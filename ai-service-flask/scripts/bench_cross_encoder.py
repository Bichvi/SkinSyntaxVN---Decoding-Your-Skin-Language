"""Cross-encoder latency benchmark for the search reranker (U12 part D).

Run INSIDE the ai container (CPU only, uses the model already in the Hugging Face cache; never downloads):

    MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-ai python scripts/bench_cross_encoder.py [--pairs 40]
        [--iterations 40] [--threads 2,4] [--no-int8] [--no-onnx] [--out report/search-metrics/ce_bench.json]

It measures p50/p95/max of scoring N (query, "name - category") pairs for: fp32 torch, int8 dynamic-quantized
torch and (optional) int8 ONNX Runtime, at each ``torch.set_num_threads`` value, plus the rank agreement
(Spearman) of each variant with fp32. The gate of U12 is p95(40 pairs) <= 120 ms. Nothing here touches Mongo
writes; the product names come from the read-only catalog snapshot (or a built-in list when Mongo is down).
This script is OFFLINE tooling: the request path never imports it.
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import statistics
import sys
import tempfile
import time
from pathlib import Path
from typing import Any, Callable

SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

log = logging.getLogger("bench_cross_encoder")

QUERIES = [
    "sua rua mat cho da dau", "kem chong nang torriden", "serum vitamin c", "toner khong con", "kem duong am ban dem",
    "cerave sua rua mat", "retinol cho da mun", "mat na ngu", "tay trang la roche posay", "serum niacinamide",
    "kem mat", "lotion duong the", "tinh chat hyaluronic", "gel rua mat cho da nhay cam", "kem tri mun",
    "chong nang vat ly", "essence duong sang da", "tay te bao chet", "sua rua mat diu nhe", "kem duong phuc hoi",
]
FALLBACK_TEXTS = [
    "Sua rua mat Cerave diu nhe - Sua rua mat", "Kem chong nang Anessa SPF50 - Kem chong nang",
    "Serum Vitamin C 15% - Serum", "Toner Torriden Dive-In - Toner", "Kem duong am Laneige - Kem duong",
    "Tay trang La Roche-Posay - Tay trang", "Mat na ngu Laneige - Mat na", "Gel rua mat Cosrx - Sua rua mat",
]


def percentile(values: list[float], pct: float) -> float:
    ordered = sorted(values)
    if not ordered:
        return 0.0
    index = min(len(ordered) - 1, max(0, int(round(pct / 100 * (len(ordered) - 1)))))
    return ordered[index]


def summarize(samples_ms: list[float]) -> dict[str, float]:
    return {
        "n": len(samples_ms),
        "p50_ms": round(statistics.median(samples_ms), 1),
        "p95_ms": round(percentile(samples_ms, 95), 1),
        "max_ms": round(max(samples_ms), 1),
        "mean_ms": round(statistics.fmean(samples_ms), 1),
    }


def spearman(a: list[float], b: list[float]) -> float:
    """Rank correlation without scipy (average ranks are not needed: float scores rarely tie)."""

    def ranks(values: list[float]) -> list[float]:
        order = sorted(range(len(values)), key=lambda i: values[i])
        out = [0.0] * len(values)
        for rank, i in enumerate(order):
            out[i] = float(rank)
        return out

    ra, rb = ranks(a), ranks(b)
    n = len(a)
    if n < 2:
        return 1.0
    d2 = sum((x - y) ** 2 for x, y in zip(ra, rb))
    return 1 - 6 * d2 / (n * (n * n - 1))


TEXT_POOL = 160     # same candidate texts for every --pairs value, so 20 vs 40 pairs are comparable


def load_texts(count: int = TEXT_POOL) -> list[str]:
    try:
        from pymongo import MongoClient

        from search_service import config
        from search_service.catalog import load_catalog

        db = MongoClient(config.MONGO_URI, serverSelectionTimeoutMS=15000)[config.MONGO_DB_NAME]
        texts = [f"{p.get('ten_san_pham', '')} - {p.get('loai_san_pham', '')}" for p in load_catalog(db)]
    except Exception as exc:  # noqa: BLE001 - benchmark must still run offline
        log.warning("catalog unavailable (%s); using built-in product names", type(exc).__name__)
        texts = []
    texts = [t for t in texts if t.strip()] or FALLBACK_TEXTS
    step = max(1, len(texts) // max(count, 1))
    return [texts[(i * step) % len(texts)] for i in range(count)]


def make_batches(pairs: int, iterations: int, texts: list[str]) -> list[list[list[str]]]:
    batches = []
    for i in range(iterations):
        query = QUERIES[i % len(QUERIES)]
        offset = (i * 7) % max(1, len(texts) - pairs)
        batches.append([[query, texts[(offset + j) % len(texts)]] for j in range(pairs)])
    return batches


def time_scorer(score: Callable[[list[list[str]]], list[float]], batches: list[list[list[str]]], warmup: int = 3) -> list[float]:
    for batch in batches[:warmup]:
        score(batch)
    samples = []
    for batch in batches:
        started = time.perf_counter()
        score(batch)
        samples.append((time.perf_counter() - started) * 1000)
    return samples


def torch_scorer(model: Any) -> Callable[[list[list[str]]], list[float]]:
    def run(pairs: list[list[str]]) -> list[float]:
        return [float(s) for s in model.predict(pairs, batch_size=len(pairs), show_progress_bar=False)]

    return run


def build_onnx_scorer(model: Any, tmp_dir: str) -> Callable[[list[list[str]]], list[float]] | None:
    """Export the HF model to ONNX and quantize to int8 with onnxruntime (local files only)."""

    try:
        import numpy as np
        import onnxruntime as ort
        import torch
        from onnxruntime.quantization import QuantType, quantize_dynamic

        hf_model, tokenizer = model.model.eval(), model.tokenizer
        sample = tokenizer([["q", "a product"]], padding=True, truncation=True, max_length=128, return_tensors="pt")
        names = [n for n in ("input_ids", "attention_mask", "token_type_ids") if n in sample]
        fp32_path, int8_path = os.path.join(tmp_dir, "ce_fp32.onnx"), os.path.join(tmp_dir, "ce_int8.onnx")
        torch.onnx.export(
            hf_model, tuple(sample[n] for n in names), fp32_path, input_names=names, output_names=["logits"],
            dynamic_axes={**{n: {0: "batch", 1: "seq"} for n in names}, "logits": {0: "batch"}},
            opset_version=17, dynamo=False,
        )
        quantize_dynamic(fp32_path, int8_path, weight_type=QuantType.QInt8)
        options = ort.SessionOptions()
        options.intra_op_num_threads = torch.get_num_threads()
        session = ort.InferenceSession(int8_path, options, providers=["CPUExecutionProvider"])

        def run(pairs: list[list[str]]) -> list[float]:
            enc = tokenizer(pairs, padding=True, truncation=True, max_length=128, return_tensors="np")
            logits = session.run(["logits"], {n: enc[n].astype(np.int64) for n in names})[0]
            return [float(x) for x in logits.reshape(len(pairs), -1)[:, 0]]

        return run
    except Exception as exc:  # noqa: BLE001 - optional variant
        log.warning("ONNX variant skipped: %s: %s", type(exc).__name__, str(exc)[:200])
        return None


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Cross-encoder rerank latency benchmark (CPU, cached model only)")
    parser.add_argument("--pairs", type=int, default=40)
    parser.add_argument("--iterations", type=int, default=40)
    parser.add_argument("--threads", default="2,4")
    parser.add_argument("--no-int8", action="store_true", help="skip torch dynamic int8")
    parser.add_argument("--no-onnx", action="store_true", help="skip ONNX Runtime int8")
    parser.add_argument("--gate-ms", type=float, default=120.0)
    parser.add_argument("--out", default="")
    args = parser.parse_args(argv)
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")

    os.environ.setdefault("HF_HUB_OFFLINE", "1")          # the benchmark must never download a model
    os.environ.setdefault("TRANSFORMERS_OFFLINE", "1")
    import torch
    from sentence_transformers import CrossEncoder

    from shared.model_config import RERANKER_MODEL

    texts = load_texts()
    report_text_len = round(statistics.fmean(len(t) for t in texts), 1)
    log.info("pair texts: %d candidates, mean %.1f chars", len(texts), report_text_len)
    batches = make_batches(args.pairs, args.iterations, texts)
    threads_list = [int(t) for t in args.threads.split(",") if t.strip()]
    report: dict[str, Any] = {
        "model": RERANKER_MODEL, "pairs": args.pairs, "iterations": args.iterations, "gate_p95_ms": args.gate_ms,
        "torch": torch.__version__, "cpu_count": os.cpu_count(), "mean_text_chars": report_text_len, "variants": [],
    }

    model = CrossEncoder(RERANKER_MODEL, device="cpu", max_length=128)
    reference = torch_scorer(model)(batches[0])
    variants: list[tuple[str, Callable[[list[list[str]]], list[float]], int]] = []
    for n in threads_list:
        variants.append(("fp32", torch_scorer(model), n))
    if not args.no_int8:
        q_model = CrossEncoder(RERANKER_MODEL, device="cpu", max_length=128)     # separate copy: quantized in place
        torch.quantization.quantize_dynamic(q_model, {torch.nn.Linear}, dtype=torch.qint8, inplace=True)
        for n in threads_list:
            variants.append(("int8_dynamic_torch", torch_scorer(q_model), n))
    if not args.no_onnx:
        with tempfile.TemporaryDirectory() as tmp:
            torch.set_num_threads(threads_list[-1])
            onnx_scorer = build_onnx_scorer(model, tmp)
            if onnx_scorer is not None:
                for n in threads_list:
                    torch.set_num_threads(n)
                    onnx_scorer = build_onnx_scorer(model, tmp) or onnx_scorer
                    samples = time_scorer(onnx_scorer, batches)
                    row = {"variant": "int8_onnxruntime", "threads": n, **summarize(samples),
                           "spearman_vs_fp32": round(spearman(reference, onnx_scorer(batches[0])), 3)}
                    row["passes_gate"] = row["p95_ms"] <= args.gate_ms
                    report["variants"].append(row)
                    log.info("%s", row)

    for name, scorer, n in variants:
        torch.set_num_threads(n)
        samples = time_scorer(scorer, batches)
        row = {"variant": name, "threads": n, **summarize(samples),
               "spearman_vs_fp32": round(spearman(reference, scorer(batches[0])), 3)}
        row["passes_gate"] = row["p95_ms"] <= args.gate_ms
        report["variants"].append(row)
        log.info("%s", row)

    best = min(report["variants"], key=lambda r: r["p95_ms"], default=None)
    report["best"] = best
    report["gate_passed"] = bool(best and best["passes_gate"])
    print(json.dumps(report, indent=2))
    if args.out:
        Path(args.out).parent.mkdir(parents=True, exist_ok=True)
        Path(args.out).write_text(json.dumps(report, indent=2), encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
