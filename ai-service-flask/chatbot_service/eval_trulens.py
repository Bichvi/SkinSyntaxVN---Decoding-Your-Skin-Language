# -*- coding: utf-8 -*-
"""
eval_trulens.py — SkinSyntaxVN chatbot evaluation using TruLens (LLM-as-judge).

Why TruLens instead of the old rag_evaluation.py?
  · rag_evaluation.py uses keyword matching → inaccurate for Vietnamese.
  · TruLens uses a real LLM judge (OpenAI GPT) → accurate, with explanations.
  · Includes a web dashboard for visual result inspection.

Install packages (run outside Docker, dev environment):
  pip install trulens trulens-apps-langchain "trulens-providers-litellm>=1.0"

Run:
  python eval_trulens.py                  # default 15 queries
  python eval_trulens.py --n 5            # run only 5 queries
  python eval_trulens.py --dashboard      # open TruLens dashboard after run
  python eval_trulens.py --output report.json  # save results to file

Metrics measured:
  1. Answer Relevance   — is the answer relevant to the question?
  2. Groundedness       — does the answer hallucinate beyond the context?
  3. Context Relevance  — is the retrieved context relevant to the question?
  4. Latency            — response time (ms)
  5. Intent Accuracy    — was the intent classification correct? (rule-based check)
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import time
from dataclasses import asdict, dataclass, field
from datetime import datetime
from pathlib import Path
from typing import Any

_ENV = Path(__file__).resolve().parent.parent / ".env"
if _ENV.exists():
    from dotenv import load_dotenv
    load_dotenv(_ENV, override=True)

try:
    from trulens.core import TruSession, Feedback, Select
    from trulens.providers.litellm import LiteLLM
    _TRULENS_OK = True
except ImportError:
    _TRULENS_OK = False
    print(
        "[WARN] TruLens not installed. Run the following then retry:\n"
        "  pip install trulens trulens-apps-langchain 'trulens-providers-litellm>=1.0'\n"
        "Continuing in offline mode (no LLM judge).\n"
    )

# Each test case has:
#   question      : real customer question
#   expected_intent: expected intent for classification validation
#   tags          : category groups for per-group result analysis

TEST_CASES: list[dict] = [
    {
        "question":       "da mình dầu mụn nhiều, cần sữa rửa mặt nào?",
        "expected_intent": "PRODUCT_INQUIRY",
        "tags":           ["product", "srm", "da_dau"],
    },
    {
        "question":       "tìm kem chống nắng cho da nhạy cảm, không cồn",
        "expected_intent": "PRODUCT_INQUIRY",
        "tags":           ["product", "kcn", "da_nhay_cam"],
    },
    {
        "question":       "serum vitamin C giá dưới 300k cho da thường",
        "expected_intent": "PRODUCT_INQUIRY",
        "tags":           ["product", "serum", "budget"],
    },
    {
        "question":       "gợi ý toner nào cho da khô bong tróc vào mùa lạnh",
        "expected_intent": "PRODUCT_INQUIRY",
        "tags":           ["product", "toner", "da_kho"],
    },
    {
        "question":       "tư vấn routine dưỡng da buổi sáng cho da dầu mụn",
        "expected_intent": "PRODUCT_INQUIRY",
        "tags":           ["product", "routine", "da_dau"],
    },
    {
        "question":       "kem dưỡng ẩm Hàn Quốc cho da khô giá tầm trung",
        "expected_intent": "PRODUCT_INQUIRY",
        "tags":           ["product", "moisturizer", "korean"],
    },
    {
        "question":       "cho mình xem các loại mặt nạ ngủ dưỡng ẩm",
        "expected_intent": "PRODUCT_INQUIRY",
        "tags":           ["product", "mat_na"],
    },
    {
        "question":       "có sản phẩm nào trị thâm mụn không cần toa bác sĩ không?",
        "expected_intent": "PRODUCT_INQUIRY",
        "tags":           ["product", "tham_mun"],
    },
    {
        "question":       "retinol là gì và dùng thế nào cho đúng?",
        "expected_intent": "COSMETIC_KNOWLEDGE_OUT_OF_DB",
        "tags":           ["knowledge", "retinol"],
    },
    {
        "question":       "niacinamide và vitamin C có dùng chung được không?",
        "expected_intent": "COSMETIC_KNOWLEDGE_OUT_OF_DB",
        "tags":           ["knowledge", "ingredient_combo"],
    },
    {
        "question":       "BHA AHA khác nhau thế nào, da mình nên dùng cái nào?",
        "expected_intent": "COSMETIC_KNOWLEDGE_OUT_OF_DB",
        "tags":           ["knowledge", "bha_aha"],
    },
    {
        "question":       "hyaluronic acid giúp ích gì cho da khô?",
        "expected_intent": "COSMETIC_KNOWLEDGE_OUT_OF_DB",
        "tags":           ["knowledge", "hyaluronic"],
    },
    {
        "question":       "chào shop ơi, cho hỏi shop mở cửa mấy giờ?",
        "expected_intent": "GENERAL_CONVERSATION",
        "tags":           ["general", "greeting"],
    },
    {
        "question":       "cảm ơn shop đã tư vấn nhiệt tình nha",
        "expected_intent": "GENERAL_CONVERSATION",
        "tags":           ["general", "thankyou"],
    },
    {
        "question":       "mình có thể thanh toán bằng ví điện tử được không?",
        "expected_intent": "GENERAL_CONVERSATION",
        "tags":           ["general", "payment"],
    },
]



@dataclass
class QueryResult:
    question:         str
    expected_intent:  str
    actual_intent:    str
    answer:           str
    products_count:   int
    latency_ms:       float
    tags:             list[str]
    # TruLens scores (None if TruLens is unavailable)
    answer_relevance: float | None = None
    groundedness:     float | None = None
    context_relevance: float | None = None
    # Derived
    intent_correct:   bool = field(init=False)

    def __post_init__(self):
        self.intent_correct = self.actual_intent == self.expected_intent


@dataclass
class EvalReport:
    timestamp:            str = field(default_factory=lambda: datetime.now().isoformat())
    total_queries:        int = 0
    intent_accuracy:      float = 0.0
    avg_latency_ms:       float = 0.0
    avg_answer_relevance: float | None = None
    avg_groundedness:     float | None = None
    avg_context_relevance: float | None = None
    by_intent:            dict = field(default_factory=dict)
    by_tag:               dict = field(default_factory=dict)
    results:              list = field(default_factory=list)
    trulens_used:         bool = False


def _run_pipeline(question: str) -> tuple[dict, float]:
    """
    Call the actual pipeline and measure latency.
    Returns: (result_dict, latency_ms)
    """
    from pipeline import xu_ly_cau_hoi
    t0 = time.perf_counter()
    result = xu_ly_cau_hoi(question, msg_data=None)
    latency = (time.perf_counter() - t0) * 1000
    return result, latency


def _build_feedbacks(provider) -> tuple:
    """Build 3 standard RAG Triad feedback functions from TruLens."""
    import numpy as np
    from trulens.core import Feedback, Select

    # 1. Answer Relevance — does the answer actually address the question?
    f_answer = (
        Feedback(provider.relevance_with_cot_reasons, name="Answer Relevance")
        .on_input_output()
    )

    # 2. Groundedness — does the answer hallucinate beyond the context?
    # context here is the search_results injected into the prompt
    f_ground = (
        Feedback(provider.groundedness_measure_with_cot_reasons, name="Groundedness")
        .on(Select.RecordCalls[:].rets.collect())  # context
        .on_output()
    )

    # 3. Context Relevance — is the retrieved context relevant to the question?
    f_ctx = (
        Feedback(provider.context_relevance_with_cot_reasons, name="Context Relevance")
        .on_input()
        .on(Select.RecordCalls[:].rets[:])
        .aggregate(np.mean)
    )

    return f_answer, f_ground, f_ctx


def _score_with_llm(provider, question: str, answer: str, context: str) -> dict[str, float | None]:
    """
    Use TruLens provider directly to score (no TruChain wrapper needed).
    This is the simplest approach — call feedback functions directly.
    """
    scores: dict[str, float | None] = {
        "answer_relevance": None,
        "groundedness": None,
        "context_relevance": None,
    }
    def _extract_score(result) -> float | None:
        """TruLens _with_cot_reasons methods return (score, reasons) tuple."""
        if isinstance(result, tuple):
            val = result[0]
            return float(val) if val is not None else None
        if hasattr(result, "score"):
            val = result.score
            return float(val) if val is not None else None
        if result is None:
            return None
        return float(result)

    try:
        # Answer Relevance: (question, answer) → score
        result = provider.relevance_with_cot_reasons(question, answer)
        scores["answer_relevance"] = _extract_score(result)
    except Exception as e:
        print(f"  [SCORE] answer_relevance failed: {e}")

    if context:
        try:
            # Groundedness: (context, answer) → score
            result = provider.groundedness_measure_with_cot_reasons(context, answer)
            scores["groundedness"] = _extract_score(result)
        except Exception as e:
            print(f"  [SCORE] groundedness failed: {e}")

        try:
            # Context Relevance: (question, context) → score
            result = provider.context_relevance_with_cot_reasons(question, context)
            scores["context_relevance"] = _extract_score(result)
        except Exception as e:
            print(f"  [SCORE] context_relevance failed: {e}")
    else:
        print("  [SCORE] skipping groundedness & context_relevance (no retrieved context)")

    return scores



def run_evaluation(n_queries: int = 15, use_trulens: bool = True) -> EvalReport:
    """
    Run full pipeline evaluation.

    Args:
        n_queries  : Number of queries to run (default all 15).
        use_trulens: Use TruLens LLM judge if available.
    """
    cases  = TEST_CASES[:n_queries]
    report = EvalReport(total_queries=len(cases))

    provider = None
    session  = None

    if use_trulens and _TRULENS_OK:
        openai_key = os.getenv("OPENAI_API_KEY", "").strip()
        openai_model = os.getenv("OPENAI_CHAT_MODEL", "gpt-4o-mini").strip()

        if openai_key.startswith("sk-"):
            try:
                provider = LiteLLM(model_engine=f"openai/{openai_model}")
                print(f"[TRULENS] Judge: OpenAI {openai_model} via litellm")
                report.trulens_used = True
            except Exception as e:
                print(f"[TRULENS] OpenAI init failed: {e}")

        if provider is None:
            print("[TRULENS] No provider available, running offline (no LLM judge).")
        else:
            try:
                session = TruSession()
            except Exception:
                session = None

    query_results: list[QueryResult] = []

    print(f"\n{'─'*70}")
    print(f"  Evaluating {len(cases)} queries | TruLens judge: {'' if provider else '✗ (offline)'}")
    print(f"{'─'*70}\n")

    _QUERY_DELAY_S = 3 if provider else 0

    for idx, case in enumerate(cases, 1):
        if idx > 1 and _QUERY_DELAY_S:
            time.sleep(_QUERY_DELAY_S)
        q    = case["question"]
        tags = case["tags"]
        print(f"[{idx:02d}/{len(cases):02d}] {q[:65]}…" if len(q) > 65 else f"[{idx:02d}/{len(cases):02d}] {q}")

        try:
            result, latency = _run_pipeline(q)
        except Exception as e:
            print(f"  ✗ Pipeline error: {e}")
            continue

        answer    = result.get("answer", "")
        products  = result.get("products", [])
        analysis  = result.get("analysis", {})
        intent    = _infer_intent_from_analysis(analysis, answer)

        context = _build_context_str(products)

        scores: dict[str, float | None] = {"answer_relevance": None, "groundedness": None, "context_relevance": None}
        if provider:
            try:
                scores = _score_with_llm(provider, q, answer, context)
            except Exception as e:
                print(f"  [SCORE] Error: {e}")

        fmt = lambda v: f"{v:.2f}" if v is not None else "N/A"
        ar = scores.get("answer_relevance")
        gr = scores.get("groundedness")
        cr = scores.get("context_relevance")
        if provider:
            print(f"   AR={fmt(ar)} | GR={fmt(gr)} | CR={fmt(cr)} | {latency:.0f}ms")
        else:
            print(f"   {latency:.0f}ms | {len(products)} products | intent={intent}")

        qr = QueryResult(
            question=q,
            expected_intent=case["expected_intent"],
            actual_intent=intent,
            answer=answer,
            products_count=len(products),
            latency_ms=round(latency, 1),
            tags=tags,
            answer_relevance=scores.get("answer_relevance"),
            groundedness=scores.get("groundedness"),
            context_relevance=scores.get("context_relevance"),
        )
        query_results.append(qr)

    if not query_results:
        return report

    report.results = [asdict(r) for r in query_results]

    # Intent accuracy
    report.intent_accuracy = sum(r.intent_correct for r in query_results) / len(query_results)

    # Latency
    report.avg_latency_ms = round(sum(r.latency_ms for r in query_results) / len(query_results), 1)

    # TruLens averages
    def _avg(vals):
        clean = [v for v in vals if v is not None]
        return round(sum(clean) / len(clean), 4) if clean else None

    report.avg_answer_relevance  = _avg([r.answer_relevance  for r in query_results])
    report.avg_groundedness      = _avg([r.groundedness      for r in query_results])
    report.avg_context_relevance = _avg([r.context_relevance for r in query_results])

    # Breakdown by intent
    for intent in ("PRODUCT_INQUIRY", "COSMETIC_KNOWLEDGE_OUT_OF_DB", "GENERAL_CONVERSATION"):
        group = [r for r in query_results if r.expected_intent == intent]
        if group:
            report.by_intent[intent] = {
                "count":          len(group),
                "intent_accuracy": round(sum(r.intent_correct for r in group) / len(group), 3),
                "avg_latency_ms": round(sum(r.latency_ms for r in group) / len(group), 1),
                "avg_answer_relevance":  _avg([r.answer_relevance  for r in group]),
                "avg_groundedness":      _avg([r.groundedness      for r in group]),
            }

    # Breakdown by tag
    all_tags = {t for r in query_results for t in r.tags}
    for tag in all_tags:
        group = [r for r in query_results if tag in r.tags]
        report.by_tag[tag] = {
            "count":          len(group),
            "avg_latency_ms": round(sum(r.latency_ms for r in group) / len(group), 1),
            "avg_answer_relevance": _avg([r.answer_relevance for r in group]),
        }

    return report



def _infer_intent_from_analysis(analysis: dict, answer: str) -> str:
    """Infer intent from pipeline output (no direct intent field in output)."""
    if analysis.get("loai_san_pham") or analysis.get("loai_da"):
        return "PRODUCT_INQUIRY"
    # Heuristic: general conversation is usually short with no product references
    if len(answer) < 200 and not any(k in answer.lower() for k in ["sản phẩm", "index.php", "vnđ", "hoạt chất"]):
        return "GENERAL_CONVERSATION"
    if any(k in answer.lower() for k in ["hoạt chất", "công dụng", "cơ chế", "tác dụng của"]):
        return "COSMETIC_KNOWLEDGE_OUT_OF_DB"
    return "PRODUCT_INQUIRY"


def _build_context_str(products: list[dict]) -> str:
    """Build context string from product list for groundedness evaluation."""
    if not products:
        return ""
    parts = []
    for p in products[:5]:
        parts.append(
            f"{p.get('name','')} ({p.get('brand','')}) "
            f"— {p.get('summary','')[:150]}"
        )
    return "\n".join(parts)



def print_report(report: EvalReport):
    bar = "═" * 72
    print(f"\n{bar}")
    print(f"  SKINSYNTAXVN CHATBOT — EVALUATION RESULTS")
    print(f"  {report.timestamp}")
    print(bar)
    print(f"\n  Total queries : {report.total_queries}")
    print(f"  TruLens judge : {'LLM-based' if report.trulens_used else '✗ Offline (TruLens unavailable)'}")

    print(f"\n  {'─'*38} OVERALL {'─'*23}")
    print(f"  Intent Accuracy     : {report.intent_accuracy:.1%}")
    print(f"  Avg Latency         : {report.avg_latency_ms:.0f} ms")
    if report.trulens_used:
        ar = f"{report.avg_answer_relevance:.3f}" if report.avg_answer_relevance is not None else "N/A"
        gr = f"{report.avg_groundedness:.3f}"      if report.avg_groundedness is not None else "N/A"
        cr = f"{report.avg_context_relevance:.3f}" if report.avg_context_relevance is not None else "N/A"
        print(f"  Answer Relevance    : {ar}  (LLM judge)")
        print(f"  Groundedness        : {gr}  (LLM judge)")
        print(f"  Context Relevance   : {cr}  (LLM judge)")

    if report.by_intent:
        print(f"\n  {'─'*38} BY INTENT {'─'*22}")
        for intent, stats in report.by_intent.items():
            short = intent.replace("COSMETIC_KNOWLEDGE_OUT_OF_DB", "KNOWLEDGE").replace("_", " ")
            print(f"\n  [{short}]  n={stats['count']}")
            print(f"    Intent Accuracy: {stats['intent_accuracy']:.1%}")
            print(f"    Avg Latency   : {stats['avg_latency_ms']:.0f} ms")
            if stats.get("avg_answer_relevance") is not None:
                print(f"    Ans Relevance : {stats['avg_answer_relevance']:.3f}")
            if stats.get("avg_groundedness") is not None:
                print(f"    Groundedness  : {stats['avg_groundedness']:.3f}")

    print(f"\n{bar}\n")



def _patch_trulens_instrument() -> None:
    """
    Silence the TypeError thrown by TruLens >= 1.x when it tries to attach
    a flag attribute to a mappingproxy (read-only class __dict__).
    The dashboard still starts fine; this just suppresses the noisy traceback.
    """
    try:
        import trulens.core.otel.instrument as _instr

        _orig_call = _instr.TruWrapper.__call__

        def _safe_call(self, func):
            result = _orig_call(self, func)
            try:
                result.__dict__[_instr.TRULENS_INSTRUMENT_WRAPPER_FLAG] = True
            except (TypeError, AttributeError):
                pass
            return result

        _instr.TruWrapper.__call__ = _safe_call
    except Exception:
        pass  # If patching fails, proceed anyway — worst case: noisy traceback


def main():
    parser = argparse.ArgumentParser(description="Evaluate SkinSyntaxVN chatbot with TruLens")
    parser.add_argument("--n",          type=int, default=15,          help="Number of queries to run (default 15)")
    parser.add_argument("--no-trulens", action="store_true",           help="Run offline without TruLens LLM judge")
    parser.add_argument("--output",     type=str, default=None,        help="Save results to JSON file (e.g. report.json)")
    parser.add_argument("--dashboard",  action="store_true",           help="Open TruLens dashboard after run")
    args = parser.parse_args()

    report = run_evaluation(
        n_queries=min(args.n, len(TEST_CASES)),
        use_trulens=not args.no_trulens,
    )

    print_report(report)

    output_path = args.output or f"eval_report_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
    try:
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(asdict(report), f, ensure_ascii=False, indent=2)
        print(f"  Results saved: {output_path}")
    except Exception as e:
        print(f"  [WARN] Failed to save file: {e}")

    if args.dashboard and _TRULENS_OK:
        print("\n  Starting TruLens dashboard at http://localhost:8501 …")
        _patch_trulens_instrument()
        try:
            # New API (trulens >= 1.x)
            from trulens.dashboard.run import run_dashboard
            run_dashboard()
        except ImportError:
            # Fallback for older trulens builds
            try:
                import warnings
                with warnings.catch_warnings():
                    warnings.simplefilter("ignore", DeprecationWarning)
                    from trulens.core import TruSession
                    TruSession().run_dashboard()
            except Exception as e:
                print(f"  [WARN] Dashboard failed: {e}")
                print("  Try running manually: trulens-eval")
        except Exception as e:
            print(f"  [WARN] Dashboard failed: {e}")
            print("  Try running manually: trulens-eval")


if __name__ == "__main__":
    main()
