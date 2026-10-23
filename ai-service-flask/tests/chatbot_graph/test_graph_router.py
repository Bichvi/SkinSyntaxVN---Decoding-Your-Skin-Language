"""U10 router: which questions go to graph tools, answer composition guards, RAG fallback."""

from __future__ import annotations

import copy
from types import SimpleNamespace

import pytest

from chatbot_service import graph_router as gr
from chatbot_service import graph_tools as gt
from graph_fakes import FakeCatalog, FakeGraph, make_tools, row, snapshot, vocab

CATALOG = FakeCatalog([
    {"id": "1496", "ten_san_pham": "Sữa Rửa Mặt Torriden Tạo Bọt Dưỡng Ẩm Da 150ml", "thuong_hieu": "Torriden",
     "category_keys": ["sua_rua_mat"]},
    {"id": "619", "ten_san_pham": "Serum Torriden Dive-In Hyaluronic Acid 50ml", "thuong_hieu": "Torriden",
     "category_keys": ["serum"]},
])


def ctx(current: str | None = None) -> gr.PlanContext:
    return gr.PlanContext(snapshot=snapshot(), vocab=vocab(), groups=gt.load_groups(), catalog=CATALOG,
                          current_product_id=current)


def plan(message: str, current: str | None = None):
    return gr.build_plan(message, ctx(current))


# ------------------------------------------------------------------ routing table


def test_serum_oily_skin_not_conflicting_with_retinol():
    p = plan("serum nào hợp da dầu, không kỵ retinol")
    assert p.tool == "find_products"
    assert p.args == {"category": "serum", "skin_type": "da_dau", "avoid_conflict_with": ["retinol"], "limit": 6}


def test_cleanser_without_alcohol_under_300k():
    p = plan("sữa rửa mặt không chứa cồn dưới 300k")
    assert p.tool == "find_products"
    assert p.args["category"] == "sua_rua_mat" and p.args["exclude_ingredients"] == ["alcohol_denat"]
    assert p.args["price_max"] == 300000 and "include_ingredients" not in p.args


def test_ingredient_pair_question_goes_to_check_conflicts():
    p = plan("retinol dùng chung với BHA được không")
    assert p.tool == "check_conflicts" and p.args == {"ingredient_keys": ["retinol", "salicylic_acid"]}
    assert plan("vitamin c kết hợp với retinol có sao không").args["ingredient_keys"] == ["ascorbic_acid", "retinol"]


def test_brand_category_existence_question():
    p = plan("Torriden có kem chống nắng không")
    assert p.tool == "find_products" and p.args["brand"] == "torriden" and p.args["category"] == "kem_chong_nang"


def test_groups_unknown_to_the_dictionary_are_picked_up_after_a_negation():
    p = plan("kem dưỡng không paraben không hương liệu cho da nhạy cảm")
    assert set(p.args["exclude_ingredients"]) == {"paraben", "fragrance"} and p.args["skin_type"] == "da_nhay_cam"
    assert plan("kem dưỡng silicone free").args["exclude_ingredients"] == ["silicone"]
    # a group that is merely mentioned (not negated) is not an exclusion
    assert plan("kem dưỡng có silicone dưới 300k").args.get("exclude_ingredients") is None


def test_positive_ingredient_and_price_range():
    p = plan("serum có niacinamide giá từ 200k đến 500k")
    assert p.args["include_ingredients"] == ["niacinamide"] and p.args["price_min"] == 200000 and p.args["price_max"] == 500000


@pytest.mark.parametrize("message", [
    "serum cho da dầu",                       # ordinary product question -> RAG as before
    "kem chống nắng cho da dầu",
    "cho mình toner da khô",
    "routine cho da dầu mụn không cồn",       # routines keep their own flow
    "retinol là gì", "cách dùng serum vitamin c dưới 400k", "đổi trả trong bao lâu",
    "so sánh serum cerave và torriden không cồn",
    "loại retinol nào tốt",
    "Loại da: Da dầu", "Ngân sách: Dưới 300k",   # survey quick-replies
    "xin chào", "", "   ",
])
def test_ordinary_or_non_product_questions_are_not_routed(message):
    assert plan(message) is None


def test_two_weak_conditions_alone_do_not_route_but_three_do():
    assert plan("serum trị mụn cho da dầu") is None
    p = plan("serum trị mụn kiềm dầu cho da dầu")
    assert p is not None and p.args["concerns"] == ["mun"] and p.args["benefits"] == ["kiem_dau"]


def test_negated_property_the_graph_cannot_check_is_reported():
    p = plan("kem chống nắng không cồn không gây bí da")
    assert p.tool == "find_products" and p.args["exclude_ingredients"] == ["alcohol_denat"]
    assert any("bí da" in u for u in p.unverified)
    assert plan("sữa rửa mặt không chứa cồn dưới 300k").unverified == ()


def test_product_page_questions_use_the_current_product():
    assert plan("Có chứa cồn không?", current="1496").args == {"product_id": "1496", "probe_ingredients": ["alcohol_denat"]}
    assert plan("sản phẩm này có paraben không", current="1496").args["probe_ingredients"] == ["paraben"]
    p = plan("sản phẩm tương tự sản phẩm này", current="1496")
    assert p.tool == "similar_products" and p.args["product_id"] == "1496"
    assert plan("giá bao nhiêu", current="1496") is None
    assert plan("Có chứa cồn không?") is None                      # no product context -> RAG


def test_named_product_is_resolved_only_when_unambiguous():
    p = plan("Torriden Dive-In có chứa cồn không")
    assert p.tool == "explain_product" and p.args["product_id"] == "619"
    assert plan("sản phẩm tương tự Torriden Dive-In").tool == "similar_products"
    unknown = plan("Torriden Zzz Yyy có chứa cồn không")            # unknown product: never guess a product id
    assert unknown is None or unknown.tool == "find_products"


def test_compatibility_phrase_means_avoid_conflict_not_include():
    p = plan("toner nào dùng chung được với retinol")
    assert p.tool == "find_products" and p.args["avoid_conflict_with"] == ["retinol"]
    assert "include_ingredients" not in p.args and p.args["category"] == "toner"


def test_unresolved_product_name_is_left_to_rag_instead_of_guessing():
    # brand + words that match no product name ("Dive-In" is not in CATALOG's Torriden serum names)
    assert plan("Torriden Zzz Yyy serum có chứa cồn không") is None
    assert plan("Torriden có serum không cồn không").tool == "find_products"      # only fillers left: still a catalog query


def test_two_categories_in_one_question_stay_with_rag():
    assert plan("toner và serum không cồn dưới 300k") is None


def test_conflict_fillers_after_a_negation_are_not_reported_as_unverified():
    p = plan("kem chống nắng nào không kỵ vitamin c")
    assert p.args["avoid_conflict_with"] == ["ascorbic_acid"] and p.unverified == ()


# ------------------------------------------------------------------ answer composition


def result_with_products():
    tools = make_tools(FakeGraph([row("11", "Serum A", 199000), row("12", "Serum B", 250000)]))
    return tools.call("find_products", {"category": "serum", "skin_type": "da_dau"})


def test_template_answer_lists_real_ids_prices_and_links():
    text, source = gr.compose_answer("serum da dầu", result_with_products(), vocab(), (), generate=None)
    assert source == "template"
    assert "index.php?r=chitiet&id=11" in text and "199.000đ" in text and "index.php?r=chitiet&id=12" in text


def test_llm_answer_must_not_link_products_the_tool_did_not_return():
    result = result_with_products()
    good = "Syna gợi ý **[Serum A](index.php?r=chitiet&id=11)** 199.000đ nhé!"
    bad = "Syna gợi ý **[Serum Lạ](index.php?r=chitiet&id=999)**"
    assert gr.compose_answer("q", result, vocab(), (), lambda prompt: good) == (good, "llm")
    text, source = gr.compose_answer("q", result, vocab(), (), lambda prompt: bad)
    assert source == "template" and "id=999" not in text
    text, source = gr.compose_answer("q", result, vocab(), (), lambda prompt: "Dạ có nhiều sản phẩm lắm")   # no link at all
    assert source == "template"
    assert gr.compose_answer("q", result, vocab(), (), lambda prompt: None)[1] == "template"

    def boom(prompt):
        raise RuntimeError("llm down")
    assert gr.compose_answer("q", result, vocab(), (), boom)[1] == "template"


def test_prompt_contains_only_the_tool_json_and_rules():
    seen = {}
    gr.compose_answer("serum nào hợp da dầu", result_with_products(), vocab(), ("không gây bí da",),
                      lambda prompt: seen.setdefault("p", prompt) and None)
    prompt = seen["p"]
    assert "KẾT QUẢ TRA CỨU" in prompt and '"id":"11"' in prompt and "không gây bí da" in prompt
    assert "_cards" not in prompt and "image_url" not in prompt


def test_high_severity_conflict_always_carries_the_expert_recommendation():
    result = make_tools().call("check_conflicts", {"ingredient_keys": ["retinol", "salicylic_acid"]})
    text, _ = gr.compose_answer("q", result, vocab(), (), lambda prompt: "Hai chất này nên dùng xen kẽ nhé.")
    assert "bác sĩ da liễu" in text and "xen kẽ" in text
    template, _ = gr.compose_answer("q", result, vocab(), (), None)
    assert "bác sĩ da liễu" in template and "xen kẽ" in template and "mức cao" in template


def test_zero_results_template_does_not_invent_products():
    result = make_tools(FakeGraph([])).call("find_products", {"category": "serum", "price_max": 10_000})
    text, _ = gr.compose_answer("q", result, vocab(), (), None)
    assert "chưa thấy sản phẩm nào" in text and "index.php?r=chitiet" not in text


def test_unverified_conditions_are_always_disclosed():
    result = result_with_products()
    text, _ = gr.compose_answer("q", result, vocab(), ("không gây bí da",), None)
    assert "chưa kiểm chứng" in text and "bí da" in text
    llm_text, _ = gr.compose_answer("q", result, vocab(), ("không gây bí da",),
                                    lambda prompt: "**[Serum A](index.php?r=chitiet&id=11)** hợp nè")
    assert "chưa kiểm chứng" in llm_text


# ------------------------------------------------------------------ orchestration & fallbacks


def runtime(tools):
    return gr.Runtime(tools=tools, context=ctx)


def test_try_answer_builds_the_chat_payload():
    tools = make_tools(FakeGraph([row("11", "Serum A", 199000)]),
                       lambda ids: {"11": {"price": 199000, "in_stock": True, "image_url": "http://i/11.jpg"}})
    res = gr.try_answer("serum nào hợp da dầu, không kỵ retinol", {"cart_conflicts": ["x"]}, runtime(tools))
    assert res["ok"] and res["pipeline_mode"] == "Graph tools" and res["intent_mode"] == "GRAPH_TOOL"
    assert res["products"][0]["id"] == "11" and res["products"][0]["image_url"] == "http://i/11.jpg"
    assert res["products"][0]["detail_url"] == "index.php?r=chitiet&id=11"
    assert res["conflicts"] == ["x"] and res["graph_tool"]["name"] == "find_products" and res["fallback"] is False


def test_try_answer_returns_none_for_unrouted_and_on_every_failure():
    tools = make_tools(FakeGraph([row("11")]))
    assert gr.try_answer("serum cho da dầu", {}, runtime(tools)) is None
    assert gr.try_answer("serum không cồn", {}, None) is None
    down = make_tools(FakeGraph(fail=RuntimeError("neo4j_timeout")))
    assert gr.try_answer("serum không cồn dưới 300k", {}, runtime(down)) is None

    def broken_context(current):
        raise ValueError("boom")
    assert gr.try_answer("serum không cồn dưới 300k", {}, gr.Runtime(tools=tools, context=broken_context)) is None


def test_safety_gate_hook_can_veto_a_plan():
    tools = make_tools(FakeGraph([row("11")]))
    assert gr.try_answer("serum retinol dưới 300k", {}, runtime(tools), blocked=lambda p: True) is None
    assert gr.try_answer("serum retinol dưới 300k", {}, runtime(tools), blocked=lambda p: False) is not None


def test_feature_flag_defaults_to_off(monkeypatch):
    monkeypatch.delenv(gr.ENV_FLAG, raising=False)
    assert gr.enabled() is False
    monkeypatch.setenv(gr.ENV_FLAG, "true")
    assert gr.enabled() is True
    monkeypatch.setenv(gr.ENV_FLAG, "0")
    assert gr.enabled() is False


# ------------------------------------------------------------------ xu_ly_cau_hoi wiring


class OldFlowReached(Exception):
    pass


@pytest.fixture
def flask_mod(monkeypatch):
    cf = pytest.importorskip("chatbot_service.chatbot_flask")

    def boom(*_a, **_k):
        raise OldFlowReached("rag")

    monkeypatch.setattr(cf, "get_vectorstore", lambda: None)
    monkeypatch.setattr(cf, "get_hybrid_pipeline", lambda: SimpleNamespace(reranker=None))
    monkeypatch.setattr(cf, "get_groq_llama_70b", lambda: None)
    monkeypatch.setattr(cf, "contextualize_query", boom)
    monkeypatch.setattr(cf, "classify_intent", boom)
    monkeypatch.setattr(cf, "get_llms", lambda: [])
    return cf


def test_xu_ly_cau_hoi_uses_graph_tools_when_enabled(flask_mod, monkeypatch):
    tools = make_tools(FakeGraph([row("11", "Serum A", 199000)]))
    monkeypatch.setenv(gr.ENV_FLAG, "true")
    monkeypatch.setattr(gr, "get_runtime", lambda: runtime(tools))
    res = flask_mod.xu_ly_cau_hoi("serum nào hợp da dầu, không kỵ retinol", {"customer_question": "x"})
    assert res["intent_mode"] == "GRAPH_TOOL" and res["products"][0]["id"] == "11"
    assert "index.php?r=chitiet&id=11" in res["answer"]


def test_xu_ly_cau_hoi_keeps_old_flow_when_flag_off_or_not_routed_or_graph_fails(flask_mod, monkeypatch):
    tools = make_tools(FakeGraph([row("11")]))
    monkeypatch.setattr(gr, "get_runtime", lambda: runtime(tools))
    monkeypatch.delenv(gr.ENV_FLAG, raising=False)
    with pytest.raises(OldFlowReached):                                        # flag off
        flask_mod.xu_ly_cau_hoi("serum nào hợp da dầu, không kỵ retinol", {})
    monkeypatch.setenv(gr.ENV_FLAG, "true")
    with pytest.raises(OldFlowReached):                                        # ordinary question
        flask_mod.xu_ly_cau_hoi("serum cho da dầu", {})
    down = make_tools(FakeGraph(fail=RuntimeError("neo4j_down")))
    monkeypatch.setattr(gr, "get_runtime", lambda: runtime(down))
    with pytest.raises(OldFlowReached):                                        # Neo4j down -> RAG
        flask_mod.xu_ly_cau_hoi("sữa rửa mặt không chứa cồn dưới 300k", {})
    monkeypatch.setattr(gr, "get_runtime", lambda: None)
    with pytest.raises(OldFlowReached):                                        # runtime unavailable
        flask_mod.xu_ly_cau_hoi("sữa rửa mặt không chứa cồn dưới 300k", {})


def test_xu_ly_cau_hoi_survey_flow_and_unstable_profile_skip_graph_tools(flask_mod, monkeypatch):
    tools = make_tools(FakeGraph([row("11")]))
    monkeypatch.setenv(gr.ENV_FLAG, "true")
    monkeypatch.setattr(gr, "get_runtime", lambda: runtime(tools))
    sensitive = {"customer_profile": {"skin_type": "Da nhạy cảm", "concerns": ["mụn viêm"], "sensitivity": "rất dễ kích ứng"}}
    with pytest.raises(OldFlowReached):                                        # retinol + unstable skin: old safety gate decides
        flask_mod.xu_ly_cau_hoi("serum retinol dưới 300k", copy.deepcopy(sensitive))
    res = flask_mod.xu_ly_cau_hoi("retinol dùng chung với BHA được không", copy.deepcopy(sensitive))
    assert res["graph_tool"]["name"] == "check_conflicts"                      # a conflict check is never blocked
