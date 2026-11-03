import json
from types import SimpleNamespace

import pytest
from pydantic import ValidationError

from agent_graph.auth import AgentClaims
from agent_graph.state import ConvState
from agent_graph.tools import ToolContext, build_tools


@pytest.fixture(autouse=True)
def no_audit_io(monkeypatch):
    monkeypatch.setattr("agent_graph.tools.audit.record", lambda *_args, **_kwargs: None)


def claims(*scopes):
    return AgentClaims("7", "customer@example.test", "sid", tuple(scopes), 2_000_000_000, "jti")


class FakePhp:
    def __init__(self, responses=None, error=None):
        self.responses = responses or {}
        self.error = error
        self.calls = []

    def call(self, route, payload=None):
        self.calls.append((route, payload))
        if self.error:
            raise self.error
        return self.responses.get(route, {"ok": True})


class FakeGraph:
    def __init__(self, result=None, vocab=None, error=None):
        self.calls = []
        self.result = result or {"products": [{"id": "p1", "name": "Cleanser"}], "product": {"id": "p1"}}
        self.vocab = vocab
        self.error = error
        self._facts = {
            "p1": {"name": "Cleanser", "brand": "Brand", "price": 100, "image_url": "img-1"},
            "p2": {"name": "Graph", "brand": "Brand", "price": 200, "image_url": "img-2"},
            "c1": {"name": "Cart 1", "brand": "Brand", "price": 300},
            "c2": {"name": "Cart 2", "brand": "Brand", "price": 400},
            "c3": {"name": "Cart 3", "brand": "Brand", "price": 500},
            "c4": {"name": "Cart 4", "brand": "Brand", "price": 600},
        }

    def facts(self, ids):
        return {pid: self._facts[pid] for pid in ids if pid in self._facts}

    def call(self, name, args):
        self.calls.append((name, args))
        if self.error:
            raise self.error
        return self.result


class FakeText:
    def __init__(self, result=None, error=None):
        self.calls = []
        self.result = result or {"sections": []}
        self.error = error

    def __call__(self, request):
        self.calls.append(request)
        if self.error:
            raise self.error
        return self.result


_UNSET = object()


def context(scope=(), php=None, graph=_UNSET, text_search=None, events=None, state=None):
    return ToolContext(
        claims=claims(*scope) if scope is not None else None,
        php=php,
        cart_snapshot=[{"id": "cart-1", "qty": 1}],
        emit=(lambda name, payload: events.append((name, payload))) if events is not None else (lambda *_: None),
        state=state or ConvState(),
        graph_tools=FakeGraph() if graph is _UNSET else graph,
        text_search=text_search or FakeText(),
    )


def tool_map(ctx):
    return {tool.name: tool for tool in build_tools(ctx)}


def test_guest_gets_only_read_tools():
    names = set(tool_map(context(scope=("chat",), php=FakePhp(), graph=FakeGraph())))
    assert names == {"search_products", "get_product", "compare_products", "check_conflicts"}


def test_kill_switch_scope_hides_write_tools():
    names = set(tool_map(context(scope=("chat", "order:read"), php=FakePhp(), graph=FakeGraph())))
    assert names == {"search_products", "get_product", "compare_products", "check_conflicts", "get_order_status"}


def test_add_to_cart_emits_signed_effect_unchanged():
    events = []
    effect = {"op": "add", "product_id": "p1", "qty": 2, "sig": "signed"}
    php = FakePhp({"internal_agent_cart_add": {"ok": True, "effect": effect, "line": {"id": "p1"}}})
    tool = tool_map(context(("cart:write",), php=php, events=events))["add_to_cart"]
    result = tool.invoke({"items": [{"product_id": "p1", "qty": 2}]})
    assert effect in [event[1]["effect"] for event in events if event[0] == "cart_effect"]
    assert "<tool_data>" in result


def test_add_to_cart_rejects_qty_21():
    tool = tool_map(context(("cart:write",), php=FakePhp()))["add_to_cart"]
    with pytest.raises(ValidationError):
        tool.invoke({"items": [{"product_id": "p1", "qty": 21}]})


def test_out_of_stock_returns_error_not_raise():
    from agent_graph.php_client import PhpError

    php = FakePhp(error=PhpError(409, "not enough stock", code="out_of_stock"))
    tool = tool_map(context(("cart:write",), php=php))["add_to_cart"]
    result = tool.invoke({"items": [{"product_id": "p1", "qty": 1}]})
    assert '"error": "out_of_stock"' in result


def test_prepare_draft_emits_card_and_sets_state():
    events = []
    state = ConvState()
    card = {"draft_id": "D1", "expires_at": 123}
    php = FakePhp({"internal_agent_draft": {"ok": True, "card": card}})
    tool = tool_map(context(("draft:create",), php=php, events=events, state=state))["prepare_order_draft"]
    tool.invoke({"scope": "whole_cart", "product_ids": [], "address_choice": "default", "payment_method": "cod"})
    assert state.open_draft_id == "D1"
    assert ("ui", {"kind": "draft_card", "payload": card}) in events
    assert php.calls[0][1]["cart_snapshot"]


def test_search_sets_last_shown_products():
    state = ConvState()
    graph = FakeGraph(vocab=SimpleNamespace(brands={}, skin_types={"oily": "Oily"}, concerns={}))
    result = tool_map(context(graph=graph, state=state))["search_products"].invoke({"query": "cleanser", "skin_type": "oily"})
    assert state.last_shown_products == [{"idx": 1, "id": "p1", "name": "Cleanser"}]
    assert "<tool_data>" in result


def test_results_wrapped_in_tool_data():
    result = tool_map(context(graph=FakeGraph()))["get_product"].invoke({"product_id": "p1"})
    assert result.startswith("<tool_data>") and result.endswith("</tool_data>")


def test_misspelled_query_reaches_text_search(monkeypatch):
    text = FakeText({"sections": [{"key": "text", "items": [{"product_id": "p1", "score": 0.9, "reason_code": "text"}]}]})
    ctx = context(graph=FakeGraph(vocab=SimpleNamespace(brands={}, skin_types={}, concerns={})), text_search=text)
    tool_map(ctx)["search_products"].invoke({"query": "some by me"})
    assert text.calls[0].q == "some by me"


def test_query_only_search_never_calls_graph_and_hydrates_text_hit():
    graph = FakeGraph(vocab=SimpleNamespace(brands={}, skin_types={}, concerns={}))
    text = FakeText({"sections": [{"key": "text", "items": [{"product_id": "p1", "score": 0.9, "reason_code": "text"}]}]})
    ctx = context(graph=graph, text_search=text)
    result = tool_map(ctx)["search_products"].invoke({"query": "some by me"})
    assert graph.calls == []
    assert '"name": "Cleanser"' in result
    assert '"price": 100' in result
    assert '"card"' in result
    assert ctx.state.last_shown_products[0]["name"] == "Cleanser"


def test_text_failure_without_mapped_filter_is_unavailable_without_event():
    events = []
    graph = FakeGraph(vocab=SimpleNamespace(brands={}, skin_types={}, concerns={}))
    ctx = context(graph=graph, text_search=FakeText(error=RuntimeError("down")), events=events)
    result = tool_map(ctx)["search_products"].invoke({"query": "some by me"})
    assert '"error": "search_unavailable"' in result
    assert graph.calls == []
    assert events == []


def test_search_maps_filters_and_merges_text_before_graph(monkeypatch):
    vocab = SimpleNamespace(
        brands={"some_by_mi": "Some By Mi"},
        skin_types={"oily": "Da dầu"},
        concerns={"acne": "Mụn"},
    )
    graph = FakeGraph({"products": [{"id": "p2", "name": "Graph"}, {"id": "p1", "name": "Duplicate"}]}, vocab=vocab)
    text = FakeText({"sections": [{"key": "text", "items": [{"product_id": "p1", "name": "Text"}]}]})
    result = tool_map(context(graph=graph, text_search=text))["search_products"].invoke({
        "query": "cleanser", "brand": "some by mi", "skin_type": "da dau", "concerns": ["mun"], "limit": 2,
    })
    assert graph.calls[0][1] == {"skin_type": "oily", "concerns": ["acne"], "brand": "some_by_mi", "limit": 2}
    assert '"name": "Cleanser"' in result
    assert '"name": "Graph"' in result
    assert 'Duplicate' not in result


def test_search_error_emits_nothing_and_preserves_state(monkeypatch):
    events = []
    state = ConvState(last_shown_products=[{"idx": 1, "id": "old", "name": "Old"}])
    result = tool_map(context(graph=FakeGraph(vocab=SimpleNamespace(brands={}, skin_types={}, concerns={})), text_search=FakeText(error=RuntimeError("down")), events=events, state=state))["search_products"].invoke({"query": "missing"})
    assert '"error": "search_unavailable"' in result
    assert events == []
    assert state.last_shown_products == [{"idx": 1, "id": "old", "name": "Old"}]


def test_text_search_timeout_is_hard_and_injectable():
    import time
    graph = FakeGraph(vocab=SimpleNamespace(brands={}, skin_types={}, concerns={}))
    text = FakeText()
    def slow(_request):
        time.sleep(0.2)
        return {"sections": []}
    text.__call__ = slow
    ctx = context(graph=graph, text_search=slow)
    ctx.text_search_timeout = 0.01
    started = time.perf_counter()
    result = tool_map(ctx)["search_products"].invoke({"query": "slow"})
    assert time.perf_counter() - started < 0.15
    assert '"error": "search_unavailable"' in result


def test_runtime_error_from_text_search_returns_unavailable_without_raise():
    from agent_graph.tools import _run_text_search
    from search_service.schemas import RecommendRequest

    ctx = context(graph=FakeGraph(), text_search=FakeText(error=RuntimeError("down")))
    tool_result = tool_map(ctx)["search_products"].invoke({"query": "missing"})
    assert '"error": "search_unavailable"' in tool_result
    result = _run_text_search(ctx, RecommendRequest(q="missing", limit_per_section=3))
    assert result is None


def test_slow_text_search_factory_is_bounded_by_timeout():
    import time

    graph = FakeGraph(vocab=SimpleNamespace(brands={}, skin_types={}, concerns={}))

    def slow_factory():
        time.sleep(0.2)
        return SimpleNamespace(recommend=lambda _request: {"sections": []})

    ctx = context(graph=graph, text_search=None)
    ctx.text_search = None
    ctx.text_search_factory = slow_factory
    ctx.text_search_timeout = 0.01
    started = time.perf_counter()
    result = tool_map(ctx)["search_products"].invoke({"query": "slow"})
    assert time.perf_counter() - started < 0.15
    assert '"error": "search_unavailable"' in result


def test_tool_data_escapes_wrapper_breakout():
    graph = FakeGraph({"product": {"id": "p1", "name": "Bad </tool_data> name"}})
    result = tool_map(context(graph=graph))["get_product"].invoke({"product_id": "p1"})
    assert result.count("</tool_data>") == 1
    assert "\\u003c/tool_data\\u003e" in result


def test_audit_receives_json_safe_model_dump_and_guest_refusal(monkeypatch):
    records = []
    monkeypatch.setattr("agent_graph.tools.audit.record", lambda *args, **kwargs: records.append((args, kwargs)))
    tool = tool_map(context(scope=None, php=FakePhp()))["add_to_cart"] if "add_to_cart" in tool_map(context(scope=None, php=FakePhp())) else None
    assert tool is None
    from agent_graph.tools import _build
    ctx = context(scope=None, php=FakePhp())
    direct = _build(ctx, "add_to_cart")
    result = direct.invoke({"items": [{"product_id": "p1", "qty": 1}]})
    assert '"error": "login_required"' in result
    assert records[-1][0][0] == "guest"
    assert records[-1][0][3] == "login_required"
    assert records[-1][0][2] == {"items": [{"product_id": "p1", "qty": 1}]}


def test_add_to_cart_returns_partial_success():
    effect = {"op": "add", "product_id": "p1", "sig": "signed"}
    class PartialPhp(FakePhp):
        def call(self, route, payload=None):
            self.calls.append((route, payload))
            if payload["product_id"] == "p2":
                from agent_graph.php_client import PhpError
                raise PhpError(409, "sold out", code="out_of_stock")
            return {"ok": True, "effect": effect, "line": {"id": "p1", "qty": 1}}
    events = []
    result = tool_map(context(("cart:write",), php=PartialPhp(), events=events))["add_to_cart"].invoke({
        "items": [{"product_id": "p1", "qty": 1}, {"product_id": "p2", "qty": 1}]
    })
    assert '"added": [{"id": "p1", "qty": 1}]' in result
    assert '"product_id": "p2"' in result and '"error": "out_of_stock"' in result
    assert events == [("cart_effect", {"effect": effect})]


def test_add_to_cart_audit_marks_partial_and_failed(monkeypatch):
    records = []
    monkeypatch.setattr("agent_graph.tools.audit.record", lambda *args, **kwargs: records.append(args))
    from agent_graph.php_client import PhpError
    class FailingPhp:
        def call(self, _route, payload=None):
            raise PhpError(409, "sold out", code="out_of_stock")
    tool_map(context(("cart:write",), php=FailingPhp()))["add_to_cart"].invoke({"items": [{"product_id": "p1", "qty": 1}]})
    assert records[-1][3] == "failed"

    records.clear()
    effect = {"op": "add", "product_id": "p1", "sig": "signed"}
    class OneFailPhp:
        def call(self, _route, payload=None):
            if payload["product_id"] == "p2":
                raise PhpError(409, "sold out", code="out_of_stock")
            return {"line": {"id": "p1"}, "effect": effect}
    tool_map(context(("cart:write",), php=OneFailPhp()))["add_to_cart"].invoke({
        "items": [{"product_id": "p1", "qty": 1}, {"product_id": "p2", "qty": 1}]
    })
    assert records[-1][3] == "partial"


def test_graph_runtime_wrapper_is_accepted():
    from agent_graph.tools import _graph
    wrapper = SimpleNamespace(tools=FakeGraph())
    ctx = context(graph=wrapper)
    assert _graph(ctx, "find_products", {"limit": 1})["products"]


def test_conflicts_skips_missing_cart_ids_trims_cart_and_short_circuits():
    graph = FakeGraph()
    ctx = context(graph=graph, state=ConvState())
    ctx.cart_snapshot = [{"qty": 1}, {"id": "c1"}, {"product_id": "c2"}, {"id": "c3"}, {"id": "c4"}, {"id": "c5"}]
    tool_map(ctx)["check_conflicts"].invoke({"product_ids": ["p1", "p2"], "include_cart": True})
    assert graph.calls[0][1]["product_ids"] == ["p1", "p2", "c1", "c2", "c3", "c4"]

    graph.calls.clear()
    result = tool_map(context(graph=graph))["check_conflicts"].invoke({"product_ids": ["p1"], "include_cart": False})
    assert '"note": "need_two_products"' in result
    assert graph.calls == []


def test_graph_error_codes_are_preserved():
    from chatbot_service.graph_tools import ToolError
    invalid = tool_map(context(graph=FakeGraph(error=ToolError("invalid_params"))))["get_product"].invoke({"product_id": "p1"})
    unavailable = tool_map(context(graph=FakeGraph(error=ToolError("graph_unavailable"))))["get_product"].invoke({"product_id": "p1"})
    assert '"error": "invalid_params"' in invalid
    assert '"error": "search_unavailable"' in unavailable


# ---------------------------------------------------------------------------
# Cross-layer contracts: the fake PHP client returns EXACTLY what the PHP
# internal controller responds with (see ChatAgentInternalController).
# ---------------------------------------------------------------------------

PHP_CARD = {
    "draft_id": "a" * 32,
    "expires_at": 1_900_000_000,
    "items": [{"id": "p1", "name": "Product p1", "image": "", "qty": 1, "unit_price": 100, "line_total": 100}],
    "subtotal": 100,
    "voucher": None,
    "shipping": 30000,
    "total": 30100,
    "receiver": {"name": "Owner", "phone_masked": "09xx xxx 123", "address_short": "TP.HCM"},
    "payment_method": "cod",
    "warnings": [],
}

DRAFT_ARGS = {"scope": "mentioned_items", "product_ids": ["p1"], "address_choice": "default", "payment_method": "cod"}


def test_contract_php_draft_wrapper_yields_draft_card_event_and_state():
    events = []
    state = ConvState()
    php = FakePhp({"internal_agent_draft": {"ok": True, "card": PHP_CARD}})
    tool = tool_map(context(("draft:create",), php=php, events=events, state=state))["prepare_order_draft"]
    tool.invoke(DRAFT_ARGS)
    assert state.open_draft_id == PHP_CARD["draft_id"]
    assert [e for e in events if e[0] == "ui" and e[1]["kind"] == "draft_card"] == [
        ("ui", {"kind": "draft_card", "payload": PHP_CARD})
    ]


def test_contract_php_reorder_wrapper_yields_draft_card_event_and_state():
    events = []
    state = ConvState()
    php = FakePhp({"internal_agent_reorder": {"ok": True, "card": PHP_CARD}})
    tool = tool_map(context(("draft:create",), php=php, events=events, state=state))["reorder_from_order"]
    tool.invoke({"order_code": "#DH123"})
    assert state.open_draft_id == PHP_CARD["draft_id"]
    assert ("ui", {"kind": "draft_card", "payload": PHP_CARD}) in events


def test_contract_php_cart_remove_shape():
    events = []
    effect = {"op": "remove", "product_id": "p1", "qty": 1, "sub": "7", "exp": 2_000_000_000, "nonce": "n", "sig": "s"}
    php = FakePhp({"internal_agent_cart_remove": {"ok": True, "effect": effect}})
    tool_map(context(("cart:write",), php=php, events=events))["remove_from_cart"].invoke({"product_id": "p1"})
    assert events == [("cart_effect", {"effect": effect})]


def test_contract_php_cart_add_shape_uses_line():
    effect = {"op": "add", "product_id": "p1", "qty": 2, "sub": "7", "exp": 2_000_000_000, "nonce": "n", "sig": "s"}
    line = {"id": "p1", "name": "Product p1", "unit_price": 100, "qty": 2}
    php = FakePhp({"internal_agent_cart_add": {"ok": True, "effect": effect, "line": line}})
    result = tool_map(context(("cart:write",), php=php))["add_to_cart"].invoke({"items": [{"product_id": "p1", "qty": 2}]})
    assert '"added": [{"id": "p1"' in result and '"failed": []' in result


def test_contract_php_checkout_defaults_shape_is_passed_through():
    from agent_graph.tools import _php

    body = {"receiver": {"name": "Owner", "phone_masked": "09xx xxx 123", "address_short": "TP.HCM"},
            "last_payment_method": "cod", "methods": ["cod", "vnpay"]}
    php = FakePhp({"internal_agent_checkout_defaults": body})
    assert _php(context(("draft:create",), php=php), "internal_agent_checkout_defaults", {}) == body


def test_contract_php_orders_shape_emits_order_list():
    events = []
    orders = [{"order_id": 3, "created_at": "2026-01-01T00:00:00+00:00", "total": 100, "status": "pending",
               "payment_state": "unpaid", "url": "/index.php?r=hoso"}]
    php = FakePhp({"internal_agent_orders": {"orders": orders}})
    tool_map(context(("order:read",), php=php, events=events))["get_order_status"].invoke({})
    assert events == [("ui", {"kind": "order_list", "payload": {"orders": orders}})]


def test_stale_replaces_404_clears_state_and_retries_once_without_replaces():
    from agent_graph.php_client import PhpError

    class RetryPhp(FakePhp):
        def call(self, route, payload=None):
            self.calls.append((route, dict(payload or {})))
            if payload and payload.get("replaces"):
                raise PhpError(404, "missing", code="not_found")
            return {"ok": True, "card": PHP_CARD}

    events = []
    state = ConvState(open_draft_id="stale-draft")
    php = RetryPhp()
    tool = tool_map(context(("draft:create",), php=php, events=events, state=state))["prepare_order_draft"]
    tool.invoke(DRAFT_ARGS)
    assert len(php.calls) == 2
    assert php.calls[0][1]["replaces"] == "stale-draft"
    assert not php.calls[1][1].get("replaces")
    assert state.open_draft_id == PHP_CARD["draft_id"]
    assert ("ui", {"kind": "draft_card", "payload": PHP_CARD}) in events


def test_stale_replaces_retry_happens_only_once():
    from agent_graph.php_client import PhpError

    class AlwaysGone(FakePhp):
        def call(self, route, payload=None):
            self.calls.append((route, dict(payload or {})))
            raise PhpError(410, "gone", code="expired")

    state = ConvState(open_draft_id="stale-draft")
    php = AlwaysGone()
    tool = tool_map(context(("draft:create",), php=php, state=state))["prepare_order_draft"]
    result = tool.invoke(DRAFT_ARGS)
    assert len(php.calls) == 2
    assert state.open_draft_id is None
    assert '"error"' in result


def test_compare_ui_payload_has_no_underscore_cards():
    events = []
    graph = FakeGraph(result={"product": {"id": "p1"}, "_cards": [{"id": "p1", "name": "A", "_internal": "x"}]})
    tool_map(context(graph=graph, events=events))["compare_products"].invoke({"product_ids": ["p1", "p2"]})
    ui = [payload for name, payload in events if name == "ui"]
    assert len(ui) == 1 and ui[0]["kind"] == "product_list"
    assert "_cards" not in ui[0]["payload"]
    assert ui[0]["payload"]["products"] == [{"id": "p1", "name": "A"}, {"id": "p1", "name": "A"}]


def _advice_graph():
    return FakeGraph(result={"pairs": [
        {"a": "Retinol", "b": "AHA", "severity": "high", "advice": "Không dùng cùng buổi tối " + "x" * 400},
        {"a": "Vitamin C", "b": "Niacinamide", "severity": "low", "advice": "Dùng cách nhau vài giờ."},
        {"a": "A", "b": "B", "severity": "low", "advice": "c"},
        {"a": "D", "b": "E", "severity": "low", "advice": "d"},
        {"a": "F", "b": "G", "severity": "low", "advice": ""},
    ]})


def test_prepare_draft_passes_bounded_conflict_warnings_for_mentioned_items():
    graph = _advice_graph()
    php = FakePhp({"internal_agent_draft": {"ok": True, "card": PHP_CARD}})
    tool = tool_map(context(("draft:create",), php=php, graph=graph))["prepare_order_draft"]
    tool.invoke({**DRAFT_ARGS, "product_ids": ["p1", "p2"]})
    assert graph.calls[0] == ("check_conflicts", {"product_ids": ["p1", "p2"]})
    warnings = php.calls[0][1]["conflict_warnings"]
    assert 1 <= len(warnings) <= 3
    assert all(isinstance(item, str) and 0 < len(item) <= 200 for item in warnings)
    assert "Retinol" in warnings[0]


def test_prepare_draft_conflict_warnings_use_cart_ids_for_whole_cart_and_skip_single_item():
    graph = _advice_graph()
    php = FakePhp({"internal_agent_draft": {"ok": True, "card": PHP_CARD}})
    ctx = context(("draft:create",), php=php, graph=graph)
    ctx.cart_snapshot = [{"id": "c1", "qty": 1}, {"product_id": "c2", "qty": 1}]
    tool_map(ctx)["prepare_order_draft"].invoke({**DRAFT_ARGS, "scope": "whole_cart", "product_ids": []})
    assert graph.calls[0][1]["product_ids"] == ["c1", "c2"]

    graph2 = _advice_graph()
    php2 = FakePhp({"internal_agent_draft": {"ok": True, "card": PHP_CARD}})
    ctx2 = context(("draft:create",), php=php2, graph=graph2)
    ctx2.cart_snapshot = [{"id": "only", "qty": 1}]
    tool_map(ctx2)["prepare_order_draft"].invoke({**DRAFT_ARGS, "scope": "whole_cart", "product_ids": []})
    assert graph2.calls == []
    assert php2.calls[0][1].get("conflict_warnings", []) == []


def test_prepare_draft_survives_conflict_check_failure():
    from chatbot_service.graph_tools import ToolError

    php = FakePhp({"internal_agent_draft": {"ok": True, "card": PHP_CARD}})
    graph = FakeGraph(error=ToolError("graph_unavailable"))
    state = ConvState()
    tool = tool_map(context(("draft:create",), php=php, graph=graph, state=state))["prepare_order_draft"]
    tool.invoke({**DRAFT_ARGS, "product_ids": ["p1", "p2"]})
    assert php.calls[0][1].get("conflict_warnings", []) == []
    assert state.open_draft_id == PHP_CARD["draft_id"]


def _eucerin_graph():
    graph = FakeGraph(vocab=SimpleNamespace(brands={}, skin_types={}, concerns={}))
    graph._facts.update({
        "450": {"name": "Alt A"}, "1315": {"name": "Alt B"}, "5819": {"name": "Alt C"},
        "1000": {"name": "Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 75ml", "brand": "Eucerin", "price": 300},
        "728": {"name": "Eucerin Other"},
    })
    return graph


def test_best_scored_hit_in_a_later_section_is_not_cut_by_section_order():
    """Root cause of 'cannot find the product': recommend() lists category_alt first, so concatenating
    sections pushed the exact product (highest score, in same_brand) beyond the limit of 3."""
    text = FakeText({"sections": [
        {"key": "category_alt", "items": [
            {"product_id": "450", "score": 0.479}, {"product_id": "1315", "score": 0.432}, {"product_id": "5819", "score": 0.419},
        ]},
        {"key": "same_brand", "items": [{"product_id": "1000", "score": 1.022}, {"product_id": "728", "score": 0.997}]},
    ]})
    ctx = context(graph=_eucerin_graph(), text_search=text)
    tool_map(ctx)["search_products"].invoke({"query": "Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 75ml", "limit": 3})
    assert [item["id"] for item in ctx.state.last_shown_products] == ["1000", "728", "450"]


def test_exact_name_match_is_promoted_over_a_higher_scored_hit():
    text = FakeText({"sections": [{"key": "semantic", "items": [
        {"product_id": "728", "score": 0.99}, {"product_id": "1000", "score": 0.5},
    ]}]})
    ctx = context(graph=_eucerin_graph(), text_search=text)
    tool_map(ctx)["search_products"].invoke({"query": "gel rua mat eucerin cho da nhon mun 75ml", "limit": 2})
    assert ctx.state.last_shown_products[0]["id"] == "1000"


def test_catalog_name_lookup_finds_product_text_search_missed():
    graph = _eucerin_graph()
    ctx = context(graph=graph, text_search=FakeText({"sections": []}))
    ctx.name_lookup = lambda query: ["1000"]
    result = tool_map(ctx)["search_products"].invoke({"query": "Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 75ml"})
    assert '"error"' not in result
    assert ctx.state.last_shown_products[0]["id"] == "1000"


def test_product_cards_emitted_only_for_returned_products():
    events = []
    text = FakeText({"sections": [{"key": "semantic", "items": [
        {"product_id": pid, "score": 1 - i / 10} for i, pid in enumerate(["450", "1315", "5819", "1000", "728"])
    ]}]})
    ctx = context(graph=_eucerin_graph(), text_search=text, events=events)
    tool_map(ctx)["search_products"].invoke({"query": "x", "limit": 2})
    cards = events[0][1]["payload"]["_cards"]
    assert [card["id"] for card in cards] == ["450", "1315"]


_ADDRESS_SHAPES = [
    {"receiver_name": "Lan", "phone_number": "0912345678", "street": "12 Lê Lợi", "ward": "P.Bến Nghé",
     "district": "Q.1", "city": "TP.HCM"},
    {"full_name": "Lan", "phone": "0912345678", "address": "12 Lê Lợi, Q.1, TP.HCM", "note": "ignored"},
    {"name": "Lan", "sdt": "0912345678", "dia_chi": "12 Lê Lợi, Q.1, TP.HCM"},
    {"ho_ten": "Lan", "so_dien_thoai": "0912345678", "full_address": "12 Lê Lợi, Q.1, TP.HCM"},
    {"recipient_name": "Lan", "mobile": "0912345678", "duong": "12 Lê Lợi", "phuong": "P.Bến Nghé",
     "quan": "Q.1", "thanh_pho": "TP.HCM"},
    {"ten_nguoi_nhan": "Lan", "sdt_nguoi_nhan": "0912345678", "dia_chi_giao_hang": "12 Lê Lợi, Q.1, TP.HCM"},
]


@pytest.mark.parametrize("shape", _ADDRESS_SHAPES)
def test_prepare_draft_maps_new_address_aliases_to_php_keys(shape):
    card = {"draft_id": "D1", "expires_at": 123}
    php = FakePhp({"internal_agent_draft": {"ok": True, "card": card}})
    tool = tool_map(context(("draft:create",), php=php))["prepare_order_draft"]
    tool.invoke({"scope": "whole_cart", "product_ids": [], "address_choice": "new",
                 "new_address": dict(shape), "payment_method": "cod"})
    sent = php.calls[0][1]["new_address"]
    assert set(sent) == {"ten_nguoi_nhan", "sdt_nguoi_nhan", "dia_chi_giao_hang"}
    assert sent["ten_nguoi_nhan"] == "Lan"
    assert sent["sdt_nguoi_nhan"] == "0912345678"
    assert sent["dia_chi_giao_hang"].startswith("12 Lê Lợi")
    assert sent["dia_chi_giao_hang"].endswith("TP.HCM")


def test_new_address_joins_parts_in_order():
    from agent_graph.tools import NewAddress

    parsed = NewAddress.model_validate({"city": "HCM", "street": "1 A", "district": "Q1", "ward": "P2"})
    assert parsed.dia_chi_giao_hang == "1 A, P2, Q1, HCM"


def test_prepare_draft_schema_lists_php_address_fields():
    tool = tool_map(context(("draft:create",), php=FakePhp()))["prepare_order_draft"]
    schema = json.dumps(tool.args_schema.model_json_schema(), ensure_ascii=False)
    for key in ("ten_nguoi_nhan", "sdt_nguoi_nhan", "dia_chi_giao_hang"):
        assert key in schema
