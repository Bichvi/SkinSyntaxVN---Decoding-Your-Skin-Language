"""RAGAS benchmark, stage B: LLM-judged metrics + summary table. Runs on the HOST in an isolated venv.

    <venv>/Scripts/python.exe ai-service-flask/scripts/eval_ragas_judge.py --run-dir <run dir> [--limit-cases N]

Reads <run-dir>/stage_a.json, writes stage_b.json, stage_b_cache.json (resumable) and summary.md.
Only GOOGLE_API_KEY (or the first GOOGLE_API_KEYS entry) is read from the repo .env, in-process, never printed.
"""
from __future__ import annotations

import argparse
import json
import math
import os
import sys
import time
import warnings
from datetime import date
from importlib.metadata import version
from pathlib import Path

warnings.filterwarnings("ignore")

REPO_ROOT = Path(__file__).resolve().parents[2]
CONFIGS = ("semantic", "hybrid_rrf", "hybrid_rerank")
JUDGE_MODEL = "gemini-2.5-flash"
EMBED_CANDIDATES = ("models/gemini-embedding-001", "models/text-embedding-004")
METRICS = {  # output column -> label
    "llm_context_precision_without_reference": "context_precision",
    "answer_relevancy": "answer_relevancy",
    "faithfulness": "faithfulness",
}


def load_key() -> str:
    from dotenv import dotenv_values
    env = dotenv_values(REPO_ROOT / ".env")
    key = str(env.get("GOOGLE_API_KEY") or "").strip()
    if not key:
        key = next((k.strip() for k in str(env.get("GOOGLE_API_KEYS") or "").split(",") if k.strip()), "")
    if not key:
        raise SystemExit("no Google API key found in .env")
    return key


def nan_to_none(x):
    try:
        return None if x is None or (isinstance(x, float) and math.isnan(x)) else float(x)
    except (TypeError, ValueError):
        return None


def build_models(key: str, judge_model: str = JUDGE_MODEL):
    from langchain_google_genai import ChatGoogleGenerativeAI, GoogleGenerativeAIEmbeddings
    from ragas.embeddings import LangchainEmbeddingsWrapper
    from ragas.llms import LangchainLLMWrapper

    llm = ChatGoogleGenerativeAI(model=judge_model, temperature=0, google_api_key=key, max_retries=3, timeout=120)
    emb_name, emb = None, None
    for cand in EMBED_CANDIDATES:
        try:
            e = GoogleGenerativeAIEmbeddings(model=cand, google_api_key=key)
            e.embed_query("kiểm tra")
            emb_name, emb = cand, e
            break
        except Exception as exc:  # noqa: BLE001
            print(f"embedding {cand} unusable: {type(exc).__name__}", flush=True)
    if emb is None:
        raise SystemExit("no working Google embedding model")
    return LangchainLLMWrapper(llm), LangchainEmbeddingsWrapper(emb), emb_name


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--run-dir", required=True)
    ap.add_argument("--limit-cases", type=int, default=0)
    ap.add_argument("--batch", type=int, default=6)
    ap.add_argument("--judge-model", default=JUDGE_MODEL)
    args = ap.parse_args()

    from ragas import EvaluationDataset, RunConfig, evaluate
    from ragas.metrics import Faithfulness, LLMContextPrecisionWithoutReference, ResponseRelevancy

    run_dir = Path(args.run_dir)
    stage_a = json.loads((run_dir / "stage_a.json").read_text(encoding="utf-8"))
    samples = stage_a["samples"]
    if args.limit_cases:
        keep = sorted({s["case_id"] for s in samples})[: args.limit_cases]
        samples = [s for s in samples if s["case_id"] in keep]
    cache_path = run_dir / ("stage_b_cache.json" if not args.limit_cases else "stage_b_cache_smoke.json")
    cache: dict[str, dict] = json.loads(cache_path.read_text(encoding="utf-8")) if cache_path.exists() else {}

    llm, emb, emb_name = build_models(load_key(), args.judge_model)
    metrics = [LLMContextPrecisionWithoutReference(llm=llm), ResponseRelevancy(llm=llm, embeddings=emb, strictness=1), Faithfulness(llm=llm)]
    rc = RunConfig(max_workers=4, timeout=180, max_retries=6, max_wait=60)

    todo = [s for s in samples if f"{s['case_id']}|{s['config']}" not in cache]
    print(f"{len(samples)} samples, {len(todo)} to judge", flush=True)
    t0 = time.time()
    for i in range(0, len(todo), args.batch):
        batch = todo[i:i + args.batch]
        rows = []
        for s in batch:
            rows.append({"user_input": s["query"], "response": s["answer"] or "(không có câu trả lời)",
                         "retrieved_contexts": s["contexts"]})
        ds = EvaluationDataset.from_list(rows)
        res = evaluate(ds, metrics=metrics, llm=llm, embeddings=emb, run_config=rc,
                       raise_exceptions=False, show_progress=False)
        df = res.to_pandas()
        for s, (_, r) in zip(batch, df.iterrows()):
            vals = {k: nan_to_none(r.get(k)) for k in METRICS}
            if any(v is not None for v in vals.values()):  # all-NaN (quota/outage) is retried on the next run
                cache[f"{s['case_id']}|{s['config']}"] = vals
        cache_path.write_text(json.dumps(cache, ensure_ascii=False, indent=1), encoding="utf-8")
        print(f"judged {min(i + args.batch, len(todo))}/{len(todo)} ({time.time() - t0:.0f}s)", flush=True)

    # ---- assemble ----
    per_sample = []
    for s in samples:
        sc = cache.get(f"{s['case_id']}|{s['config']}") or {k: None for k in METRICS}
        per_sample.append({"case_id": s["case_id"], "config": s["config"], "generator_model": s["generator_model"],
                           "generation_error": s.get("generation_error"),
                           **{METRICS[k]: sc[k] for k in METRICS}, **{f"id_{k}": v for k, v in s["id_metrics"].items()}})

    cols = ["context_precision", "answer_relevancy", "faithfulness",
            "id_precision@5", "id_recall@10", "id_hit@5", "id_mrr@10", "id_ndcg@10"]
    agg: dict[str, dict] = {}
    for cfg in CONFIGS:
        rows = [r for r in per_sample if r["config"] == cfg]
        agg[cfg] = {"n": len(rows)}
        for c in cols:
            vals = [r[c] for r in rows if r[c] is not None]
            agg[cfg][c] = {"mean": (sum(vals) / len(vals)) if vals else None, "n_valid": len(vals),
                           "n_nan": len(rows) - len(vals)}
        agg[cfg]["latency_s_mean"] = sum(s["latency_s"] for s in samples if s["config"] == cfg) / max(1, len(rows))

    gen_models = sorted({s["generator_model"] for s in samples if s["generator_model"]})
    gen_counts = {m: sum(1 for s in samples if s["generator_model"] == m) for m in gen_models}
    meta = {"date": date.today().isoformat(), "judge_model": args.judge_model, "embedding_model": emb_name,
            "generator_models": gen_counts, "n_cases": len({s["case_id"] for s in samples}),
            "ragas": version("ragas"), "langchain_google_genai": version("langchain-google-genai"),
            "langchain_core": version("langchain-core")}
    smoke = bool(args.limit_cases)
    (run_dir / ("stage_b_smoke.json" if smoke else "stage_b.json")).write_text(
        json.dumps({"meta": meta, "aggregate": agg, "samples": per_sample}, ensure_ascii=False, indent=1), encoding="utf-8")

    def cell(cfg, col, pct=False):
        d = agg[cfg][col]
        if d["mean"] is None:
            return "n/a"
        txt = f"{d['mean']:.3f}"
        return txt + (f" (n={d['n_valid']}/{agg[cfg]['n']})" if d["n_nan"] else "")

    rows_def = [("Context Precision (RAGAS, LLM)", "context_precision"), ("Precision@5 (labels)", "id_precision@5"),
                ("Recall@10 (labels)", "id_recall@10"), ("Hit@5", "id_hit@5"), ("MRR@10", "id_mrr@10"),
                ("nDCG@10", "id_ndcg@10"), ("Answer Relevancy", "answer_relevancy"), ("Faithfulness", "faithfulness")]
    lines = ["# RAGAS benchmark: semantic vs hybrid_rrf vs hybrid_rerank", "",
             f"- Date: {meta['date']}", f"- n = {meta['n_cases']} queries x 3 configs ({len(samples)} samples)",
             f"- Judge LLM: {args.judge_model} (temperature 0); embeddings for Answer Relevancy: {emb_name}",
             f"- Generator: {', '.join(f'{m} ({c} answers)' for m, c in gen_counts.items())}",
             f"- ragas {meta['ragas']}, langchain-google-genai {meta['langchain_google_genai']}, langchain-core {meta['langchain_core']}", "",
             "| Metric | semantic | hybrid_rrf | hybrid_rerank |", "|---|---|---|---|"]
    for label, col in rows_def:
        lines.append(f"| {label} | " + " | ".join(cell(c, col) for c in CONFIGS) + " |")
    n_scored = sum(1 for r in per_sample if any(r[c] is not None for c in ("context_precision", "answer_relevancy", "faithfulness")))
    lines.insert(5, f"- Judge-scored samples: {n_scored}/{len(samples)} (the rest got no judge score, e.g. API quota; rerun resumes them)")
    lines.append("| Retrieval latency, mean s/query | " + " | ".join(f"{agg[c]['latency_s_mean']:.2f}" for c in CONFIGS) + " |")
    lines += ["", "NaN / failed judge scores are excluded from the mean; where any exist the cell shows n_valid/n.", "",
              "## Method and caveats", "",
              "- Corpus: Chroma collection `products` (6,377 docs). All 3 configs retrieve top-10 with NO metadata filters (ranking quality only); the answer is generated from the top-5 docs (each truncated to 1200 chars).",
              "- Label metrics (Precision@5, Recall@10, Hit@5, MRR@10, nDCG@10) use silver labels derived from product metadata: a doc is relevant iff category matches AND skin type matches (the value `Da thường/Mọi loại da` counts as suitable for any skin type; `Unknown` never matches) AND ingredient keyword appears in the document text AND price <= budget. Labels are heuristic, not human-annotated; keyword matching on text can miss synonyms. Recall@10 = relevant in top-10 / min(|relevant|, 10).",
              f"- RAGAS metrics are LLM-judged with a single judge model ({args.judge_model}) on Vietnamese text; the judge prompts are English, scores are noisy and have no human validation. Context Precision is the reference-free variant (judged against the generated answer, not a gold answer), so it rewards contexts that support the answer actually produced.",
              "- Answers were produced by a fixed Vietnamese advisory prompt (answer only from context, max 3 products, say so if no suitable product). When the context lacks a suitable product the model is told to say so, which can lower Answer Relevancy but raise Faithfulness.",
              "- Answer Relevancy uses strictness=1 (Gemini rejects multiple candidates, so one generated question per answer instead of 3). Single run, temperature 0, 30 queries: differences of a few hundredths are within noise. Reranker/BM25/embedding settings are the production defaults (RRF k=60, alpha=0.6).",
              "- Generator and judge may be rate-limited; see generator model counts above for fallbacks."]
    (run_dir / ("summary_smoke.md" if smoke else "summary.md")).write_text("\n".join(lines) + "\n", encoding="utf-8")
    print("\n".join(lines[:16]), flush=True)


if __name__ == "__main__":
    main()
