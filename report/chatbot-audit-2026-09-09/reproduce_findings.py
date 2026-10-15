"""Offline behavioral probes against the current source, without importing the app.

Loads selected, unmodified functions/classes with AST. Network, model inference and
database boundaries are replaced by explicit fixtures. This is NOT an end-to-end
test or a clinical evaluation. Exit 0 means the audit ran, not that the app is correct.
Run: python report/chatbot-audit-2026-09-09/reproduce_findings.py
"""
from __future__ import annotations

import ast
import contextlib
import dataclasses
import io
import json
import logging
import math
from pathlib import Path
import random
import re
import sys
import time
import types

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "ai-service-flask/chatbot_service"
OUT = Path(__file__).resolve().parent
RESULTS = []


def load(filename, names, extra=None):
    path = SRC / filename
    tree = ast.parse(path.read_text(encoding="utf-8-sig"))
    chosen = []
    for n in tree.body:
        if isinstance(n, (ast.FunctionDef, ast.ClassDef)) and n.name in names:
            chosen.append(n)
        elif isinstance(n, ast.Assign) and any(isinstance(t, ast.Name) and t.id in names for t in n.targets):
            chosen.append(n)
    module = types.ModuleType("audit_" + filename.replace(".", "_"))
    sys.modules[module.__name__] = module
    ns = module.__dict__
    ns.update(re=re, math=math, time=time, random=random, json=json,
              dataclass=dataclasses.dataclass, field=dataclasses.field,
              logger=logging.getLogger("audit"))
    ns.update(extra or {})
    tree = ast.Module(body=[ast.ImportFrom(module="__future__", names=[ast.alias(name="annotations")], level=0)] + chosen, type_ignores=[])
    exec(compile(ast.fix_missing_locations(tree), str(path), "exec"), ns)
    return ns


def record(code, title, observed, reproduced):
    RESULTS.append(dict(id=code, title=title, reproduced=bool(reproduced), observed=observed))


@dataclasses.dataclass
class Doc:
    page_content: str
    metadata: dict
    id: str = ""


h = load("hybrid_search.py", {"BM25Search", "RankedDocument", "ReciprocalRankFusion", "HybridSearchPipeline"},
         {"Document": Doc, "RERANKER_MODEL": "fixture"})
BM25 = h["BM25Search"]

factory = load("chatbot_flask.py", {"get_hybrid_pipeline"}, {
    "_hybrid_pipeline": None, "get_vectorstore": lambda: object(), "BM25Search": BM25,
    "HybridSearchPipeline": lambda **kw: types.SimpleNamespace(**kw),
})
with contextlib.redirect_stdout(io.StringIO()):
    created = factory["get_hybrid_pipeline"]()
record("F02a", "Active factory creates an empty BM25 index", len(created.bm25_index.documents), not created.bm25_index.documents)

bm = BM25([{"id": "lip", "content": "lipstick red", "metadata": {}},
           {"id": "ret", "content": "retinol serum", "metadata": {}}])
hits = bm.search("retinol")
record("F02b", "BM25 scores a document which does not contain the query token", hits,
       any(pid == "lip" and score > 0 for pid, score in hits))

hybrid = object.__new__(h["HybridSearchPipeline"])
hybrid.rrf = h["ReciprocalRankFusion"]()
hybrid.reranker = None
hybrid.bm25_index = BM25([
    {"id": "cleanser", "content": "retinol cleanser", "metadata": {"loai_san_pham": "Sữa Rửa Mặt"}},
    {"id": "serum", "content": "retinol serum", "metadata": {"loai_san_pham": "Serum"}},
])
hybrid._semantic_search_with_ids = lambda q, k, filters: [("serum", Doc("retinol serum", {"loai_san_pham": "Serum"}), 0.9)]
found, _ = hybrid.search("retinol", k_total=4, top_n=4, filters={"loai_san_pham": {"$eq": "Serum"}}, use_reranker=False)
record("F02c", "Lexical-only results bypass metadata filter", [d.doc_id for d in found], any(d.doc_id == "cleanser" for d in found))

s = load("survey_service.py", {"get_last_ai_message", "is_in_survey_flow", "Q1_TEXT", "Q2_TEXT", "Q3_TEXT", "Q4_TEXT"})
history = [{"sender": "assistant", "text": s["Q1_TEXT"]}]
record("F03", "Survey cannot read Mongo-restored dictionary history", {
    "last_ai": s["get_last_ai_message"](history),
    "in_survey": s["is_in_survey_flow"]("Loại da: Da dầu", history)},
    not s["is_in_survey_flow"]("Loại da: Da dầu", history))

fmt = load("chatbot_flask.py", {"get_product_discount", "get_fallback_hdsd", "format_search_results"})
product = Doc("fixture", {"ten_san_pham": "Fixture serum", "gia_ban": 100000,
                         "loai_san_pham": "Serum", "huong_dan_su_dung": "LABEL_INSTRUCTIONS_ONLY"}, "product_123")
random.seed(0)
formatted = fmt["format_search_results"]([product])
record("F04", "Discount synthesized from product ID without sale data", {
    "product_id": "123", "discount": fmt["get_product_discount"]("123", "Fixture serum"),
    "claimed_original_price": "133.333 VNĐ" in formatted}, "Phần trăm giảm: 25%" in formatted)
record("F05", "Actual usage metadata ignored; random category tip is supplied as evidence", {
    "label_used": "LABEL_INSTRUCTIONS_ONLY" in formatted,
    "usage_line": next(line for line in formatted.splitlines() if "Hướng dẫn" in line)},
    "LABEL_INSTRUCTIONS_ONLY" not in formatted)

budget_ns = load("chatbot_flask.py", {"extract_budget_from_text", "rule_based_parse"},
                 {"PhanTichYeuCau": lambda **kw: types.SimpleNamespace(**kw)})
amount = budget_ns["extract_budget_from_text"]("ngân sách 1,5 triệu")
record("F10", "Vietnamese decimal comma in budget is misparsed", {"input": "1,5 triệu", "actual": amount, "expected": 1500000}, amount != 1500000)
parsed = budget_ns["rule_based_parse"]("kem dưỡng không chứa retinol")
record("F11", "Fast keyword parser loses the explicit excluded ingredient", vars(parsed), not getattr(parsed, "thanh_phan_can_tranh", None))

eval_ns = load("rag_evaluation.py", {"RAGEvaluator"})
eval_score = eval_ns["RAGEvaluator"]().compute_faithfulness("Sản phẩm không chứa retinol", "Sản phẩm chứa retinol")
record("F12", "Faithfulness heuristic gives 1.0 to a contradiction", eval_score, eval_score == 1.0)

# Full original orchestration function with controlled external boundaries.
fake_messages = types.ModuleType("langchain_core.messages")
fake_messages.HumanMessage = lambda content: types.SimpleNamespace(content=content)
fake_hybrid = types.ModuleType("hybrid_search")
fake_hybrid.RankedDocument = h["RankedDocument"]
fake_eval = types.ModuleType("chatbot_service.rag_evaluation")
fake_eval.RAGEvaluator = eval_ns["RAGEvaluator"]
sys.modules["langchain_core.messages"] = fake_messages
sys.modules["hybrid_search"] = fake_hybrid
sys.modules["chatbot_service.rag_evaluation"] = fake_eval


def exercise(data, routine=False, query="Tìm sản phẩm", intent="PRODUCT_INFO", amount=None,
             category_results=False, parsed_skin=None):
    calls, prompts = [], []
    def search(**kw):
        calls.append(kw)
        if not category_results:
            return [], {}
        filt = kw.get("filters") or {}
        if "$and" in filt:
            filt = filt["$and"][-1]
        category = (filt.get("loai_san_pham") or {}).get("$eq", "other")
        return [h["RankedDocument"](doc_id="product_" + category,
                    content=category, metadata={"id": category, "ten_san_pham": category,
                    "loai_san_pham": category, "gia_ban": 50000})], {}
    yc = types.SimpleNamespace(loai_da=parsed_skin, loai_san_pham=None, is_routine=routine,
        ngan_sach=amount, muc_gia=None, tu_khoa_ngu_nghia=query, so_luong_goi_y=3,
        tinh_trang_da=None, thuong_hieu=None, thanh_phan_yeu_cau=None, thanh_phan_can_tranh=None)
    def invoke(messages):
        prompts.append(messages[0].content)
        return types.SimpleNamespace(content="Fixture answer")
    llm = types.SimpleNamespace(invoke=invoke, model="offline-fixture")
    prompt_template = "{rich_context}\n{search_results}\nTOTAL={tong_chi_phi_str}\nBUDGET={ngan_sach_str}\n{user_question}"
    ns = load("chatbot_flask.py", {"xu_ly_cau_hoi"}, {
        "get_llms": lambda: [llm], "get_vectorstore": lambda: None,
        "get_hybrid_pipeline": lambda: types.SimpleNamespace(search=search, reranker=None),
        "get_groq_llama_70b": lambda: None,
        "contextualize_query": lambda message, history, llms: message,
        "classify_intent": lambda *a: (intent, None), "rule_based_parse": lambda *a: yc,
        "extract_budget_from_text": lambda *a: amount,
        "is_in_survey_flow": lambda *a: False, "determine_profile_state": lambda *a: "PROFILE_FRESH",
        "build_filter": lambda *a: {"loai_san_pham": {"$eq": "fixture"}},
        "get_loai_da_filter_values": lambda x: [x], "MockDocument": Doc,
        "format_search_results": lambda docs: json.dumps([d.metadata for d in docs], ensure_ascii=False),
        "docs_to_products": lambda docs: [dict(d.metadata) for d in docs],
        "_get_llm_cooldown": lambda llm: 0, "_get_message_text": lambda response: response.content,
        "SYSTEM_PROMPT": prompt_template, "ROUTINE_SYSTEM_PROMPT": prompt_template,
        "GENERAL_CONVERSATION_SYSTEM_PROMPT": prompt_template, "COSMETIC_KNOWLEDGE_SYSTEM_PROMPT": prompt_template,
    })
    with contextlib.redirect_stdout(io.StringIO()):
        result = ns["xu_ly_cau_hoi"](query, data)
    return result, prompts, calls


try:
    exercise({"customer_profile": {}, "cart_conflicts": [{"product_a": "A", "product_b": "B", "warning": "fixture"}]})
    crash = "no exception"
except TypeError as exc:
    crash = str(exc)
record("F06", "Nonempty PHP conflict objects crash string join", crash, "expected str instance" in crash)

result, prompts, _ = exercise({"customer_profile": {"concerns": ["PROFILE_CONCERN_SENTINEL"]},
    "retrieved_products": [{"id": "X", "name": "Fixture", "price": 100000, "ingredients": "INGREDIENT_SENTINEL"}]})
record("F07", "PHP concerns and ingredients fields disappear in orchestration", {
    "concern_in_prompt": "PROFILE_CONCERN_SENTINEL" in prompts[0],
    "ingredient_in_prompt": "INGREDIENT_SENTINEL" in prompts[0]},
    "PROFILE_CONCERN_SENTINEL" not in prompts[0] and "INGREDIENT_SENTINEL" not in prompts[0])

result, prompts, _ = exercise({"customer_profile": {}, "retrieved_products": [
    {"id": "EXPENSIVE", "name": "Extra PHP serum", "price": 800000}]},
    routine=True, query="routine", amount=350000, category_results=True)
total = sum(p["gia_ban"] for p in result["products"])
record("F08", "PHP merge invalidates an already budget-fitted routine", {
    "budget": 350000, "fitted_combo_before_merge": 300000, "returned_total": total,
    "returned_count": len(result["products"])}, total > 350000)

result, _, _ = exercise({"customer_profile": {"avoid_ingredients": ["retinol"]},
    "retrieved_products": [{"id": "R", "name": "Retinol serum", "price": 100000,
                           "thanh_phan_chinh": "retinol"}]})
record("F09", "Excluded ingredient is context only, not a final eligibility filter", result["products"],
       any("retinol" in p["thanh_phan_chinh"] for p in result["products"]))

result, _, _ = exercise({"customer_profile": {"skin_type": "Da dầu"}}, parsed_skin="Da khô", query="Tìm kem cho da khô của mẹ")
record("F13", "Account skin type overwrites the explicit target of the current question", result["analysis"]["loai_da"],
       result["analysis"]["loai_da"] == "Da dầu")

_, _, calls = exercise({"customer_profile": {}}, intent="GENERAL_SKINCARE_KNOWLEDGE", query="Có cần dùng toner không")
record("F14", "General skincare knowledge intent falls through to product retrieval", {"search_calls": len(calls)}, len(calls) > 0)

report = {"method": __doc__, "total": len(RESULTS), "reproduced": sum(r["reproduced"] for r in RESULTS), "results": RESULTS}
(OUT / "reproduction-results.json").write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
sys.stdout.reconfigure(encoding="utf-8")
for r in RESULTS:
    print(f"{r['id']}: {'REPRODUCED' if r['reproduced'] else 'NOT REPRODUCED'} — {r['title']}")
print(f"{report['reproduced']}/{report['total']} audit scenarios reproduced. No external API or database used.")
