"""RAGAS benchmark, stage A: retrieval (3 configs) + id-based metrics + answer generation.

Run inside the ai container (read-only against Chroma):
    python -m scripts.eval_ragas_build [--limit N] [--run-dir report/ragas-benchmark/run-YYYYMMDD-HHMM]

Output: <run-dir>/stage_a.json (resumable: samples already present are skipped).
Relevance (silver labels) comes from tests/chatbot_eval/ragas_cases.json constraints, see `is_relevant`.
"""
from __future__ import annotations

import argparse
import json
import math
import os
import sys
import time
import unicodedata
from datetime import datetime
from pathlib import Path
from typing import Any

SERVICE_ROOT = Path(__file__).resolve().parent.parent
for _p in (SERVICE_ROOT, SERVICE_ROOT / "chatbot_service", SERVICE_ROOT / "shared"):
    if str(_p) not in sys.path:
        sys.path.insert(0, str(_p))

CASES_PATH = SERVICE_ROOT / "tests" / "chatbot_eval" / "ragas_cases.json"
OUT_ROOT = SERVICE_ROOT / "report" / "ragas-benchmark"
CONFIGS = ("semantic", "hybrid_rrf", "hybrid_rerank")
GROQ_MODEL = "openai/gpt-oss-120b"
GEMINI_MODEL = os.getenv("RAGAS_GEN_FALLBACK_MODEL", "gemini-2.5-flash")
CTX_CHARS = 1200
ANY_SKIN_VALUE = "da thuong/moi loai da"  # folded; matches every requested skin type

PROMPT = (
    "Bạn là chuyên viên tư vấn mỹ phẩm của cửa hàng SkinSyntaxVN. Chỉ dựa vào các đoạn NGỮ CẢNH sản phẩm "
    "bên dưới để trả lời câu hỏi của khách.\n"
    "Quy tắc:\n"
    "- Gợi ý tối đa 3 sản phẩm, mỗi sản phẩm kèm một lý do cụ thể lấy từ ngữ cảnh.\n"
    "- Nếu ngữ cảnh không có sản phẩm phù hợp, nói rõ điều đó, không bịa.\n"
    "- Không tự nghĩ ra giá hay thông số không có trong ngữ cảnh.\n"
    "- Trả lời bằng tiếng Việt, 3 đến 6 câu.\n\n"
    "NGỮ CẢNH:\n{context}\n\nCÂU HỎI CỦA KHÁCH: {query}\n\nTRẢ LỜI:"
)


def fold(text: Any) -> str:
    s = unicodedata.normalize("NFD", str(text or "").lower().replace("đ", "d"))
    return "".join(c for c in s if unicodedata.category(c) != "Mn")


def is_relevant(meta: dict, doc_text: str, c: dict) -> bool:
    if meta.get("loai_san_pham") != c["category"]:
        return False
    sk = c.get("skin_type_any")
    if sk:
        da = fold(meta.get("loai_da"))
        if not (da == ANY_SKIN_VALUE or any(fold(k) in da for k in sk)):
            return False
    ta = c.get("text_any")
    if ta:
        ft = fold(doc_text)
        if not any(fold(k) in ft for k in ta):
            return False
    mp = c.get("max_price")
    if mp is not None:
        try:
            price = float(meta.get("gia_ban"))
        except (TypeError, ValueError):
            return False
        if not (0 < price <= mp):
            return False
    return True


def compute_relevant(collection, cases: list[dict]) -> dict[str, set[str]]:
    res = collection.get(include=["documents", "metadatas"])
    out: dict[str, set[str]] = {}
    for case in cases:
        out[case["id"]] = {
            i for i, d, m in zip(res["ids"], res["documents"], res["metadatas"])
            if is_relevant(m or {}, d or "", case["constraints"])
        }
    return out


def id_metrics(ids: list[str], relevant: set[str]) -> dict[str, float]:
    top10 = ids[:10]
    top5 = ids[:5]
    rel10 = [1 if i in relevant else 0 for i in top10]
    hit5 = 1.0 if any(i in relevant for i in top5) else 0.0
    mrr = 0.0
    for rank, r in enumerate(rel10, 1):
        if r:
            mrr = 1.0 / rank
            break
    p5 = sum(1 for i in top5 if i in relevant) / 5.0
    recall10 = sum(rel10) / max(1, min(len(relevant), 10))
    dcg = sum(r / math.log2(rank + 1) for rank, r in enumerate(rel10, 1))
    ideal = sum(1 / math.log2(rank + 1) for rank in range(1, min(len(relevant), 10) + 1))
    ndcg = dcg / ideal if ideal else 0.0
    return {"hit@5": hit5, "mrr@10": mrr, "precision@5": p5, "recall@10": min(recall10, 1.0), "ndcg@10": ndcg}


def retrieve(pipeline, config: str, query: str) -> list[tuple[str, str, dict]]:
    if config == "semantic":
        hits = pipeline._semantic_search_with_ids(query, 10, None)
        return [(i, d.page_content, d.metadata) for i, d, _s in hits]
    docs, _ = pipeline.search(query, k_total=20, top_n=10, use_reranker=(config == "hybrid_rerank"))
    return [(d.doc_id, d.content, d.metadata) for d in docs]


class Generator:
    def __init__(self) -> None:
        from dotenv import dotenv_values
        env_path = SERVICE_ROOT.parent / ".env"
        env = dotenv_values(env_path) if env_path.exists() else {}
        gkey = os.getenv("GROQ_API_KEY", "").strip() or str(env.get("GROQ_API_KEY") or "").strip()
        goog = os.getenv("GOOGLE_API_KEY", "").strip() or str(env.get("GOOGLE_API_KEY") or "").strip()
        if not goog:
            raw = os.getenv("GOOGLE_API_KEYS", "") or str(env.get("GOOGLE_API_KEYS") or "")
            goog = next((k.strip() for k in raw.split(",") if k.strip()), "")
        from agent_graph.llm import _build_groq, _build_gemini
        self.groq = _build_groq(gkey, GROQ_MODEL) if gkey else None
        if self.groq is not None:
            try:
                self.groq.request_timeout = 90  # agent default (12s) is too short for a benchmark
            except Exception:
                pass
        self.gemini = _build_gemini(goog, GEMINI_MODEL) if goog else None
        if self.gemini is not None:
            try:
                self.gemini.timeout = 90
            except Exception:
                pass

    @staticmethod
    def _text(msg) -> str:
        c = msg.content
        if isinstance(c, list):
            c = "".join(p.get("text", "") if isinstance(p, dict) else str(p) for p in c)
        return (c or "").strip()

    def _call(self, llm, prompt: str, tries: int = 5) -> str:
        delay = 4.0
        last: Exception | None = None
        for _ in range(tries):
            try:
                txt = self._text(llm.invoke(prompt))
                if txt:
                    return txt
                last = RuntimeError("empty answer")
            except Exception as exc:  # noqa: BLE001
                last = exc
                s = str(exc).lower()
                retriable = any(t in s for t in ("429", "rate", "500", "502", "503", "504", "timeout", "timed out", "overloaded", "unavailable"))
                if not retriable:
                    break
            time.sleep(delay)
            delay = min(delay * 2, 40)
        raise RuntimeError(f"{type(last).__name__}: {str(last)[:400]}")

    def generate(self, prompt: str) -> tuple[str, str]:
        if self.groq is not None:
            # Groq free tier has a 200k tokens/day cap: wait out 'tokens per day' limits (keeps a single
            # generator for all samples) instead of falling back, up to ~3 h per sample.
            deadline = time.time() + (0 if os.getenv("RAGAS_GEN_NO_WAIT") else 3 * 3600)
            while True:
                try:
                    return self._call(self.groq, prompt), GROQ_MODEL
                except Exception as exc:  # noqa: BLE001
                    msg = str(exc)
                    if "tokens per day" in msg.lower() and time.time() < deadline:
                        print("  groq daily token cap; sleeping 5 min", flush=True)
                        time.sleep(300)
                        continue
                    print(f"  groq failed ({msg[:100]}); falling back to gemini", flush=True)
                    break
        if self.gemini is not None:
            return self._call(self.gemini, prompt), GEMINI_MODEL
        raise RuntimeError("no generator available")


def aggregate(samples: list[dict]) -> dict:
    agg: dict[str, dict] = {}
    for cfg in CONFIGS:
        rows = [s for s in samples if s["config"] == cfg]
        if not rows:
            continue
        keys = rows[0]["id_metrics"].keys()
        agg[cfg] = {"n": len(rows), **{k: sum(r["id_metrics"][k] for r in rows) / len(rows) for k in keys},
                    "latency_s_mean": sum(r["latency_s"] for r in rows) / len(rows)}
    return agg


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--run-dir", default="")
    args = ap.parse_args()

    run_dir = Path(args.run_dir) if args.run_dir else OUT_ROOT / f"run-{datetime.now():%Y%m%d-%H%M}"
    if not run_dir.is_absolute():
        run_dir = SERVICE_ROOT / run_dir
    run_dir.mkdir(parents=True, exist_ok=True)
    out_path = run_dir / "stage_a.json"

    cases = json.loads(CASES_PATH.read_text(encoding="utf-8"))["cases"]
    if args.limit:
        cases = cases[: args.limit]

    samples: list[dict] = []
    if out_path.exists():
        samples = [s for s in json.loads(out_path.read_text(encoding="utf-8")).get("samples", [])
                   if not s.get("generation_error")]
    done = {(s["case_id"], s["config"]) for s in samples}

    from retrieval import get_hybrid_pipeline  # lazy: loads embeddings + BM25 + reranker
    pipeline = get_hybrid_pipeline()
    relevant = compute_relevant(pipeline.vectorstore._collection, cases)
    gen = Generator()

    t0 = time.time()
    for case in cases:
        rel = relevant[case["id"]]
        for cfg in CONFIGS:
            if (case["id"], cfg) in done:
                continue
            ts = time.perf_counter()
            hits = retrieve(pipeline, cfg, case["query"])
            latency = time.perf_counter() - ts
            ids = [h[0] for h in hits][:10]
            contexts = [(h[1] or "")[:CTX_CHARS] for h in hits[:5]]
            ctx = "\n\n".join(f"[{i}] {t}" for i, t in enumerate(contexts, 1))
            try:
                answer, model = gen.generate(PROMPT.format(context=ctx, query=case["query"]))
                err = None
            except Exception as exc:  # noqa: BLE001
                answer, model, err = "", "", str(exc)
            sample = {
                "case_id": case["id"], "query": case["query"], "config": cfg,
                "retrieved_ids": ids, "relevant_count": len(rel), "contexts": contexts,
                "answer": answer, "generator_model": model, "generation_error": err,
                "id_metrics": id_metrics(ids, rel), "latency_s": round(latency, 4),
            }
            samples.append(sample)
            out_path.write_text(json.dumps({"samples": samples, "aggregate": aggregate(samples)},
                                           ensure_ascii=False, indent=1), encoding="utf-8")
            print(f"{case['id']} {cfg:13s} rel={len(rel):4d} lat={latency:.2f}s model={model or 'NONE'} err={err}", flush=True)

    result = {"created": datetime.now().isoformat(timespec="seconds"), "n_cases": len(cases),
              "samples": samples, "aggregate": aggregate(samples)}
    out_path.write_text(json.dumps(result, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"done in {time.time() - t0:.0f}s -> {out_path}", flush=True)


if __name__ == "__main__":
    main()
