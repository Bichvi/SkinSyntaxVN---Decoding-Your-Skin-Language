from __future__ import annotations

import threading
import time
from queue import Empty, Queue as StdQueue
from types import SimpleNamespace

import pytest
from langchain_core.messages import AIMessage

from agent_graph.auth import AgentClaims
from agent_graph.graph import (
    NO_DRAFT_FALLBACK_TEXT,
    ORDER_FALLBACK_TEXT,
    PAYMENT_SUPPORT_TEXT,
    build_graph,
    claims_false_commitment,
    is_unsupported_payment_question,
    run_turn,
)
from agent_graph.router import Intent, RouterEntities, RouterOutput
from agent_graph.state import ConvState
from agent_graph.tools import ToolContext

from fakes import (
    BlockingEmitTool,
    FakeGraphTools,
    FakePhp,
    LateEmitTool,
    ScriptedChatModel,
    ScriptedRouter,
)


def claims(*scopes: str) -> AgentClaims:
    return AgentClaims("7", "customer@example.test", "sid", tuple(scopes), 2_000_000_000, "jti")


def context(*, scopes: tuple[str, ...] = (), php: FakePhp | None = None, state: ConvState | None = None) -> ToolContext:
    return ToolContext(
        claims=claims(*scopes),
        php=php or FakePhp(),
        cart_snapshot=[],
        emit=lambda *_args: None,
        state=state or ConvState(),
        graph_tools=FakeGraphTools(),
        text_search=lambda _request: {"sections": []},
    )


def output(*labels: str, entities: RouterEntities | None = None) -> RouterOutput:
    return RouterOutput(
        intents=[Intent(label=label, confidence=1) for label in labels],
        entities=entities or RouterEntities(),
    )


def scripted_tool_call(name: str, args: dict, call_id: str) -> AIMessage:
    return AIMessage(
        content="",
        tool_calls=[{"name": name, "args": args, "id": call_id, "type": "tool_call"}],
    )


def test_combo_buy_flow_uses_defaults_then_draft_and_finishes():
    state = ConvState(last_shown_products=[
        {"idx": 1, "id": "p1", "name": "Sữa rửa mặt"},
        {"idx": 2, "id": "p2", "name": "Kem dưỡng"},
    ])
    php = FakePhp({
        "internal_agent_checkout_defaults": {"ok": True, "defaults": {"payment_method": "cod"}},
        "internal_agent_draft": {"ok": True, "card": {"draft_id": "D1", "expires_at": 123}},
    })
    ctx = context(scopes=("draft:create",), php=php, state=state)
    model = ScriptedChatModel(responses=[
        scripted_tool_call("get_checkout_defaults", {}, "call-1"),
        scripted_tool_call(
            "prepare_order_draft",
            {
                "scope": "mentioned_items",
                "product_ids": ["p1", "p2"],
                "address_choice": "default",
                "payment_method": "cod",
            },
            "call-2",
        ),
        AIMessage(content="Đơn nháp đã sẵn sàng."),
    ])
    router = ScriptedRouter(output("BUY", entities=RouterEntities(
        resolved_product_ids=["p1", "p2"], scope="mentioned_items"
    )))
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    events = list(run_turn(
        graph,
        message="cho tôi toàn combo này đặt giao về nhà tôi",
        ctx=ctx,
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert any(kind == "ui" and payload["kind"] == "draft_card" for kind, payload in events)
    assert events[-1] == ("done", {})
    assert [route for route, _payload in php.calls] == [
        "internal_agent_checkout_defaults",
        "internal_agent_draft",
    ]
    assert php.calls[1][1]["product_ids"] == ["p1", "p2"]
    assert {"get_checkout_defaults", "prepare_order_draft"}.issubset(model.bound_tool_names)


def test_advise_then_buy_flow_searches_adds_defaults_and_prepares_draft():
    php = FakePhp({
        "internal_agent_cart_add": {"ok": True, "effect": {"count": 1}, "line": {"product_id": "p1"}},
        "internal_agent_checkout_defaults": {"ok": True, "defaults": {"payment_method": "cod"}},
        "internal_agent_draft": {"ok": True, "card": {"draft_id": "D3"}},
    })
    ctx = ToolContext(
        claims=claims("cart:write", "draft:create"),
        php=php,
        cart_snapshot=[],
        emit=lambda *_args: None,
        state=ConvState(),
        graph_tools=SimpleNamespace(
            deps=SimpleNamespace(facts=lambda ids: {pid: {"name": "Serum"} for pid in ids}),
            vocab=None,
        ),
        text_search=lambda _request: {"sections": [{"items": [{"id": "p1", "name": "Serum"}]}]},
    )
    model = ScriptedChatModel(responses=[
        scripted_tool_call("search_products", {"query": "serum"}, "search-1"),
        scripted_tool_call("add_to_cart", {"items": [{"product_id": "p1", "qty": 1}]}, "cart-1"),
        scripted_tool_call("get_checkout_defaults", {}, "defaults-1"),
        scripted_tool_call("prepare_order_draft", {
            "scope": "mentioned_items", "product_ids": ["p1"],
            "address_choice": "default", "payment_method": "cod",
        }, "draft-1"),
        AIMessage(content="Bản nháp đã sẵn sàng."),
    ])
    router = ScriptedRouter(output("ADVISE", "BUY"))
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    events = list(run_turn(
        graph,
        message="tư vấn rồi đặt luôn",
        ctx=ctx,
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert [route for route, _payload in php.calls] == [
        "internal_agent_cart_add", "internal_agent_checkout_defaults", "internal_agent_draft",
    ]
    assert any(kind == "ui" and payload["kind"] == "product_list" for kind, payload in events)
    assert sum(kind == "cart_effect" for kind, _payload in events) == 1
    assert any(kind == "ui" and payload["kind"] == "draft_card" for kind, payload in events)
    assert events[-1] == ("done", {})


def test_store_support_goes_to_legacy():
    router = ScriptedRouter(output("STORE_SUPPORT"))
    model = ScriptedChatModel(responses=[AIMessage(content="should not run")])
    legacy_calls: list[tuple[str, dict]] = []

    def legacy(message: str, payload: dict) -> dict:
        legacy_calls.append((message, payload))
        return {"answer": "Phí giao hàng tùy khu vực.", "products": [{"id": "p1"}]}

    graph = build_graph(router, lambda tools: model.bind_tools(tools), legacy)
    events = list(run_turn(
        graph,
        message="shop có giao hàng không?",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={"customer_id": "7"},
    ))

    assert legacy_calls == [("shop có giao hàng không?", {"customer_id": "7"})]
    assert ("token", {"text": "Phí giao hàng tùy khu vực."}) in events
    assert ("ui", {"kind": "product_list", "payload": {"products": [{"id": "p1"}]}}) in events
    assert not model.calls
    assert events[-1] == ("done", {})


def test_guest_buy_gets_login_required_without_agent_call():
    router = ScriptedRouter(output("BUY"))
    model = ScriptedChatModel(responses=[AIMessage(content="must not run")])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    ctx = context()
    ctx.claims = None

    events = list(run_turn(
        graph,
        message="đặt món này",
        ctx=ctx,
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert ("ui", {"kind": "login_required", "payload": {}}) in events
    assert any(kind == "token" for kind, _payload in events)
    assert not model.calls
    assert events[-1] == ("done", {})


def test_guest_buy_with_clarification_prefers_login_required():
    router = ScriptedRouter(RouterOutput(
        intents=[{"label": "BUY", "confidence": 0.9}],
        needs_clarification="Bạn đang nói tới sản phẩm nào ạ?",
    ))
    model = ScriptedChatModel(responses=[AIMessage(content="must not run")])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    ctx = context()
    ctx.claims = None

    events = list(run_turn(
        graph,
        message="đặt cho mình 1 chai sữa rửa mặt some by mi",
        ctx=ctx,
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert ("ui", {"kind": "login_required", "payload": {}}) in events
    assert not any(kind == "ui" and payload.get("kind") == "clarify" for kind, payload in events)
    assert not model.calls


def test_max_steps_stops_cleanly(monkeypatch):
    import agent_graph.graph as graph_module

    monkeypatch.setattr(graph_module, "MAX_STEPS", 2)
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(responses=[
        scripted_tool_call("search_products", {"query": "sữa rửa mặt"}, "call-1"),
        scripted_tool_call("search_products", {"query": "kem dưỡng"}, "call-2"),
        scripted_tool_call("search_products", {"query": "chống nắng"}, "call-3"),
    ])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    events = list(run_turn(
        graph,
        message="tư vấn giúp mình",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert events[-1] == ("done", {})
    assert events[-2] == ("token", {"text": "Mình cần thêm chút thời gian, bạn nhắn lại giúp mình nhé."})
    assert len(model.calls) == 3


def test_timeout_stops_cleanly_with_injected_clock(monkeypatch):
    import agent_graph.graph as graph_module

    monkeypatch.setattr(graph_module, "TURN_TIMEOUT_S", 1)
    times = iter([0.0, 2.0])
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(responses=[AIMessage(content="must not run")])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    events = list(run_turn(
        graph,
        message="tư vấn giúp mình",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={},
        clock=lambda: next(times),
    ))

    assert events[-1] == ("done", {})
    assert events[-2] == ("token", {"text": "Mình cần thêm chút thời gian, bạn nhắn lại giúp mình nhé."})
    assert not model.calls


def test_model_order_claim_does_not_emit_order_ui_or_call_confirmation_route():
    router = ScriptedRouter(output("BUY"))
    php = FakePhp()
    model = ScriptedChatModel(responses=[
        AIMessage(content="Mình đã đặt hàng thành công."),
        AIMessage(content="Mình đã đặt hàng thành công."),
    ])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    events = list(run_turn(
        graph,
        message="đặt giúp mình",
        ctx=context(scopes=("draft:create",), php=php),
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert events == [
        ("token", {"text": NO_DRAFT_FALLBACK_TEXT}),
        ("done", {}),
    ]
    assert php.calls == []


def test_unknown_tool_and_invalid_args_are_feedback_and_the_turn_continues():
    router = ScriptedRouter(output("ADVISE"))
    invalid_json = scripted_tool_call("search_products", {}, "json-1")
    invalid_json.tool_calls[0]["args"] = "{bad"
    model = ScriptedChatModel(responses=[
        AIMessage(content="", tool_calls=[{
            "name": "create_order", "args": {}, "id": "unknown-1", "type": "tool_call",
        }]),
        invalid_json,
        AIMessage(content="Mình vẫn tiếp tục được."),
    ])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    events = list(run_turn(
        graph,
        message="tư vấn giúp mình",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert events[-1] == ("done", {})
    assert events[-2] == ("token", {"text": "Mình vẫn tiếp tục được."})
    tool_messages = [message for call in model.calls for message in call if message.type == "tool"]
    contents = [message.content for message in tool_messages]
    assert '<tool_data>{"error":"unknown_tool"}</tool_data>' in contents
    assert '<tool_data>{"error":"invalid_args"}</tool_data>' in contents


def test_pydantic_tool_validation_is_feedback_and_not_a_terminal_error():
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(responses=[
        scripted_tool_call("search_products", {"query": "serum", "limit": 0}, "bad-1"),
        AIMessage(content="Mình đã xử lý tiếp yêu cầu."),
    ])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    events = list(run_turn(
        graph,
        message="tư vấn giúp mình",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert events[-1] == ("done", {})
    assert events[-2] == ("token", {"text": "Mình đã xử lý tiếp yêu cầu."})
    tool_messages = [message for call in model.calls for message in call if message.type == "tool"]
    assert tool_messages[-1].content == '<tool_data>{"error":"invalid_args"}</tool_data>'


def test_consecutive_agent_intents_share_one_run_and_cart_step():
    php = FakePhp({
        "internal_agent_cart_add": {"ok": True, "effect": {"count": 1}, "line": {"product_id": "p1"}},
    })
    ctx = context(scopes=("cart:write",), php=php)
    model = ScriptedChatModel(responses=[
        scripted_tool_call("add_to_cart", {"items": [{"product_id": "p1", "qty": 1}]}, "cart-1"),
        AIMessage(content="Đã thêm sản phẩm vào giỏ."),
    ])
    agent_runs = []
    router = ScriptedRouter(output("ADVISE", "BUY"))

    def factory(tools):
        agent_runs.append([tool.name for tool in tools])
        return model.bind_tools(tools)

    graph = build_graph(router, factory, lambda *_args: {"answer": "legacy"})
    events = list(run_turn(
        graph,
        message="tư vấn rồi đặt luôn",
        ctx=ctx,
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert len(agent_runs) == 1
    assert [route for route, _payload in php.calls] == ["internal_agent_cart_add"]
    assert sum(kind == "cart_effect" for kind, _payload in events) == 1
    assert events[-1] == ("done", {})


def test_legacy_products_are_saved_for_follow_up_agent_run():
    state = ConvState()
    php = FakePhp({
        "internal_agent_draft": {"ok": True, "card": {"draft_id": "D2"}},
    })
    model = ScriptedChatModel(responses=[
        scripted_tool_call("prepare_order_draft", {
            "scope": "mentioned_items", "product_ids": ["p1"],
            "address_choice": "default", "payment_method": "cod",
        }, "draft-1"),
        AIMessage(content="Bản nháp đã sẵn sàng."),
    ])
    router = ScriptedRouter(output("KNOWLEDGE", "BUY"))
    graph = build_graph(
        router,
        lambda tools: model.bind_tools(tools),
        lambda *_args: {"answer": "Đây là sản phẩm phù hợp.", "products": [{"id": "p1", "name": "Serum"}]},
    )

    events = list(run_turn(
        graph,
        message="cho mình sản phẩm rồi đặt",
        ctx=context(scopes=("draft:create",), php=php, state=state),
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert state.last_shown_products == [{"idx": 1, "id": "p1", "name": "Serum"}]
    ui_kinds = [payload["kind"] for kind, payload in events if kind == "ui"]
    assert ui_kinds.index("product_list") < ui_kinds.index("draft_card")
    assert events[-1] == ("done", {})


def test_agent_system_message_excludes_product_refs():
    unique_ref = "customer-secret-product-reference-9f2a"
    router = ScriptedRouter(output(
        "ADVISE",
        entities=RouterEntities(product_refs=[unique_ref], resolved_product_ids=["p1"], concerns=["oily"]),
    ))
    model = ScriptedChatModel(responses=[AIMessage(content="Mình cần thêm thông tin để tư vấn." )])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    ctx = context(state=ConvState(last_shown_products=[{"idx": 1, "id": "p1", "name": "Serum"}]))

    list(run_turn(
        graph,
        message="tư vấn",
        ctx=ctx,
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    system = model.calls[0][0]
    assert system.type == "system"
    assert unique_ref not in system.content


def test_sleeping_model_is_stopped_by_remaining_budget(monkeypatch):
    import agent_graph.graph as graph_module

    monkeypatch.setattr(graph_module, "TURN_TIMEOUT_S", 0.02)
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(
        responses=[
            scripted_tool_call("search_products", {"query": "serum"}, "search-1"),
            AIMessage(content="should time out"),
        ],
        delays=[0, 0.2],
    )
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    started = time.monotonic()
    events = list(run_turn(
        graph,
        message="tư vấn",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert time.monotonic() - started < 0.15
    assert events[-1] == ("done", {})
    assert sum(payload.get("text") == graph_module.TIMEOUT_TEXT for kind, payload in events if kind == "token") == 1


def test_late_timed_out_tool_emit_is_dropped_and_snapshot_is_saved(monkeypatch):
    import agent_graph.graph as graph_module

    monkeypatch.setattr(graph_module, "TURN_TIMEOUT_S", 0.02)
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(responses=[
        scripted_tool_call("prepare_order_draft", {}, "late-1"),
    ])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    ctx = context()
    late_tool = LateEmitTool(ctx)
    monkeypatch.setattr(graph_module, "build_tools", lambda _ctx: [late_tool])
    store = RecordingStateStore(ConvState())

    events = list(run_turn(
        graph,
        message="tÆ° váº¥n",
        ctx=ctx,
        history=[],
        recent_turns=[],
        legacy_payload={},
        conversation_id="late-turn",
        state_store=store,
    ))
    assert late_tool.finished.wait(1.0)
    assert events[-1] == ("done", {})
    assert not any(kind == "cart_effect" for kind, _payload in events)
    saved = store.saved_keys[-1][1]
    assert saved.open_draft_id is None


def test_turn_worker_is_not_starved_by_nested_invoke_pool(monkeypatch):
    import agent_graph.graph as graph_module

    monkeypatch.setattr(graph_module, "TURN_TIMEOUT_S", 0.03)
    release = threading.Event()
    started = threading.Event()
    count_lock = threading.Lock()
    started_count = 0

    def blocked() -> None:
        nonlocal started_count
        with count_lock:
            started_count += 1
            if started_count == 16:
                started.set()
        release.wait(1.0)

    futures = [graph_module._INVOKE_EXECUTOR.submit(blocked) for _ in range(16)]
    try:
        assert started.wait(1.0)
        router = ScriptedRouter(output("ADVISE"))
        model = ScriptedChatModel(responses=[AIMessage(content="must not run")])
        graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
        started_at = time.monotonic()
        events = list(run_turn(
            graph,
            message="tÆ° váº¥n",
            ctx=context(),
            history=[],
            recent_turns=[],
            legacy_payload={},
        ))
        assert time.monotonic() - started_at < 0.2
        assert events[-1] == ("done", {})
        assert any(payload.get("text") == graph_module.TIMEOUT_TEXT for kind, payload in events if kind == "token")
    finally:
        release.set()
        for future in futures:
            future.result(timeout=1.0)


def test_base_exception_publishes_one_last_error_then_reraises():
    import agent_graph.graph as graph_module

    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(raise_base_exception=True)
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    events = []
    with pytest.raises(KeyboardInterrupt):
        stream = run_turn(
            graph,
            message="tÆ° váº¥n",
            ctx=context(),
            history=[],
            recent_turns=[],
            legacy_payload={},
        )
        while True:
            events.append(next(stream))

    assert events == [("error", {"message": graph_module.ERROR_TEXT})]


def test_runtime_exception_publishes_error_and_iteration_completes():
    import agent_graph.graph as graph_module

    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(raise_runtime_exception=True)
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    events = list(run_turn(
        graph,
        message="tÆ° váº¥n",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert events == [("error", {"message": graph_module.ERROR_TEXT})]


def test_slow_consumer_drains_worker_done_once(monkeypatch):
    import agent_graph.graph as graph_module

    monkeypatch.setattr(graph_module, "TURN_TIMEOUT_S", 0.02)
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(responses=[AIMessage(content="xong")])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    stream = run_turn(
        graph,
        message="tÆ° váº¥n",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={},
    )
    first = next(stream)
    time.sleep(0.08)
    events = [first, *list(stream)]

    assert events[-1] == ("done", {})
    assert sum(event == ("done", {}) for event in events) == 1


def test_terminal_yielded_before_deadline_gap_never_adds_second_terminal(monkeypatch):
    import agent_graph.graph as graph_module

    sentinel_release = threading.Event()

    class ControlledClock:
        now = 0.0

        def __call__(self):
            if self.now >= 2.0:
                sentinel_release.set()
            return self.now

    clock = ControlledClock()

    class TerminalGapQueue(StdQueue):
        def put(self, item, block=True, timeout=None):
            if item is None:
                sentinel_release.wait(1.0)
            return super().put(item, block=block, timeout=timeout)

    monkeypatch.setattr(graph_module, "TURN_TIMEOUT_S", 1.0)
    monkeypatch.setattr(graph_module, "Queue", TerminalGapQueue)
    monkeypatch.setattr(graph_module, "time", SimpleNamespace(monotonic=clock))
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(responses=[AIMessage(content="xong")])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    stream = run_turn(
        graph,
        message="tÆ° váº¥n",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={},
        clock=clock,
    )
    try:
        events = [next(stream), next(stream)]
        assert events[-1] == ("done", {})
        clock.now = 2.0
        events.extend(stream)
    finally:
        sentinel_release.set()

    assert sum(event[0] in {"done", "error"} for event in events) == 1
    assert events[-1] == ("done", {})


def test_failed_timeout_publish_drains_queued_worker_terminal(monkeypatch):
    import agent_graph.graph as graph_module

    terminal_queued = threading.Event()

    class ControlledClock:
        now = 0.0

        def __call__(self):
            return self.now

    clock = ControlledClock()

    class DeterministicDrainQueue(StdQueue):
        saw_worker_event = False
        forced_empty = False

        def put(self, item, block=True, timeout=None):
            result = super().put(item, block=block, timeout=timeout)
            if item is not None and item[0] in {"done", "error"}:
                # The terminal is queued only after close_and_publish closed the gate.
                terminal_queued.set()
            return result

        def get(self, block=True, timeout=None):
            item = super().get(block=block, timeout=timeout)
            if item is not None:
                self.saw_worker_event = True
            return item

        def get_nowait(self):
            if self.saw_worker_event and not self.forced_empty:
                self.forced_empty = True
                assert terminal_queued.wait(1.0)
                clock.now = 2.0
                raise Empty
            return super().get_nowait()

    monkeypatch.setattr(graph_module, "TURN_TIMEOUT_S", 1.0)
    monkeypatch.setattr(graph_module, "Queue", DeterministicDrainQueue)
    monkeypatch.setattr(graph_module, "time", SimpleNamespace(monotonic=clock))
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(responses=[AIMessage(content="xong")])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    events = list(run_turn(
        graph,
        message="tÆ° váº¥n",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={},
        clock=clock,
    ))

    assert events[-1] == ("done", {})
    assert sum(event == ("done", {}) for event in events) == 1


def test_closing_generator_cancels_between_tool_steps(monkeypatch):
    import agent_graph.graph as graph_module

    monkeypatch.setattr(graph_module, "TURN_TIMEOUT_S", 0.5)
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(responses=[
        scripted_tool_call("search_products", {"query": "serum"}, "first"),
        scripted_tool_call("search_products", {"query": "cream"}, "second"),
    ])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    ctx = context()
    blocking_tool = BlockingEmitTool(ctx)
    monkeypatch.setattr(graph_module, "build_tools", lambda _ctx: [blocking_tool])

    stream = run_turn(
        graph,
        message="tÆ° váº¥n",
        ctx=ctx,
        history=[],
        recent_turns=[],
        legacy_payload={},
    )
    first_event = next(stream)
    assert first_event == ("token", {"text": "first step"})
    stream.close()
    blocking_tool.release.set()
    time.sleep(0.05)
    assert blocking_tool.calls == 1
    assert len(model.calls) == 1


def test_agent_timeout_state_update_halts_later_legacy_intent(monkeypatch):
    import agent_graph.graph as graph_module

    monkeypatch.setattr(graph_module, "TURN_TIMEOUT_S", 0.02)
    router = ScriptedRouter(output("ADVISE", "STORE_SUPPORT"))
    model = ScriptedChatModel(responses=[AIMessage(content="late")], delays=[0.2])
    legacy_calls: list[str] = []

    def legacy(message: str, payload: dict) -> dict:
        legacy_calls.append(message)
        return {"answer": "legacy must not run"}

    graph = build_graph(router, lambda tools: model.bind_tools(tools), legacy)
    events = list(run_turn(
        graph,
        message="tÆ° váº¥n",
        ctx=context(),
        history=[],
        recent_turns=[],
        legacy_payload={},
    ))

    assert legacy_calls == []
    assert sum(payload.get("text") == graph_module.TIMEOUT_TEXT for kind, payload in events if kind == "token") == 1
    assert events[-1] == ("done", {})


class RecordingStateStore:
    def __init__(self, loaded: ConvState):
        self.loaded = loaded
        self.loaded_keys = []
        self.saved_keys = []

    def load(self, key):
        self.loaded_keys.append(key)
        return self.loaded

    def save(self, key, state):
        self.saved_keys.append((key, state))


def test_state_store_uses_conversation_id_and_empty_id_is_fresh_without_save():
    store = RecordingStateStore(ConvState(last_shown_products=[{"idx": 1, "id": "loaded", "name": "Loaded"}]))
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(responses=[AIMessage(content="ok"), AIMessage(content="ok")])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    loaded_ctx = context(state=ConvState(last_shown_products=[{"idx": 1, "id": "caller", "name": "Caller"}]))
    list(run_turn(
        graph, message="tư vấn", ctx=loaded_ctx, history=[], recent_turns=[], legacy_payload={},
        conversation_id="conversation-42", state_store=store,
    ))
    assert store.loaded_keys == ["conversation-42"]
    assert [key for key, _state in store.saved_keys] == ["conversation-42"]
    assert loaded_ctx.state.last_shown_products[0]["id"] == "loaded"

    fresh_ctx = context(state=ConvState(last_shown_products=[{"idx": 1, "id": "caller", "name": "Caller"}]))
    list(run_turn(
        graph, message="tư vấn", ctx=fresh_ctx, history=[], recent_turns=[], legacy_payload={},
        conversation_id="", state_store=store,
    ))
    assert fresh_ctx.state.last_shown_products == []
    assert store.loaded_keys == ["conversation-42"]
    assert [key for key, _state in store.saved_keys] == ["conversation-42"]


def test_survey_pending_state_is_not_replaced_with_none():
    ctx = context(state=ConvState(pending="survey:step2"))
    router = ScriptedRouter(output("SMALLTALK"))
    graph = build_graph(ScriptedRouter(output("SMALLTALK")), lambda tools: ScriptedChatModel().bind_tools(tools), lambda *_args: {"answer": "ok"})

    list(run_turn(
        graph, message="cảm ơn", ctx=ctx, history=[], recent_turns=[], legacy_payload={},
    ))

    assert ctx.state.pending == "survey:step2"


class _DictRedis:
    def __init__(self):
        self.data = {}

    def get(self, key):
        return self.data.get(key)

    def setex(self, key, _ttl, value):
        self.data[key] = value


def _search_ctx() -> ToolContext:
    return ToolContext(
        claims=claims(),
        php=FakePhp(),
        cart_snapshot=[],
        emit=lambda *_args: None,
        state=ConvState(),
        graph_tools=SimpleNamespace(
            deps=SimpleNamespace(facts=lambda ids: {pid: {"name": f"Product {pid}"} for pid in ids}),
            vocab=None,
        ),
        text_search=lambda _request: {"sections": [{"items": [
            {"id": "1000", "name": "Gel Eucerin"}, {"id": "1001", "name": "Gel B"},
        ]}]},
    )


def test_last_shown_products_set_by_search_tool_is_saved_and_reloaded_next_turn():
    """Regression: state_snapshot written inside a LangGraph node never reached run_turn's save."""
    from agent_graph.state import StateStore

    store = StateStore(_DictRedis())
    model = ScriptedChatModel(responses=[
        scripted_tool_call("search_products", {"query": "gel rửa mặt"}, "s1"),
        AIMessage(content="Đây là 2 sản phẩm."),
        AIMessage(content="ok"),
    ])
    router = ScriptedRouter(output("PRODUCT_INFO"))
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})

    list(run_turn(
        graph, message="tìm gel rửa mặt", ctx=_search_ctx(), history=[], recent_turns=[],
        legacy_payload={}, conversation_id="conv-A", state_store=store,
    ))
    assert [item["id"] for item in store.load("conv-A").last_shown_products] == ["1000", "1001"]

    next_ctx = _search_ctx()
    list(run_turn(
        graph, message="cảm ơn", ctx=next_ctx, history=[], recent_turns=[],
        legacy_payload={}, conversation_id="conv-A", state_store=store,
    ))
    assert [item["id"] for item in next_ctx.state.last_shown_products] == ["1000", "1001"]


def test_agent_system_message_includes_skin_profile_and_goal():
    router = ScriptedRouter(output("ADVISE"))
    model = ScriptedChatModel(responses=[AIMessage(content="ok")])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    ctx = context()
    ctx.skin_profile = {
        "skin_type": "Da thường-xyz",
        "goal": "Dưỡng sáng-xyz",
        "concerns": ["Lỗ chân lông to"],
        "avoid_ingredients": ["cồn"],
        "budget_max": 500000,
        "secret": "leak-me",
    }

    list(run_turn(graph, message="tư vấn", ctx=ctx, history=[], recent_turns=[], legacy_payload={}))

    system = model.calls[0][0].content
    assert "Da thường-xyz" in system
    assert "Dưỡng sáng-xyz" in system
    assert "leak-me" not in system


# --- D2: never claim a draft/order exists when it does not ---

D2_SENTENCE = (
    'Đơn nháp của bạn đã sẵn sàng. Bạn kiểm tra rồi bấm "Xác nhận đặt hàng" '
    "trong vòng 10 phút nhé."
)
G5_SENTENCE = "Đơn nháp của bạn đã được tạo sẵn, nhưng chưa được xác nhận. Bạn bấm Xác nhận đặt hàng nhé."


@pytest.mark.parametrize("text", [
    D2_SENTENCE,
    "Đơn nháp đang sẵn sàng rồi nhé",
    "Mình đã tạo xong đơn nháp cho bạn",
    "Mình đã lập đơn nháp rồi",
    "Đơn nháp đã được lập",
    "Đơn nháp của bạn mình tạo sẵn rồi",
])
def test_draft_claim_detected_only_without_open_draft(text):
    assert claims_false_commitment(text, has_open_draft=False) == "draft"
    assert claims_false_commitment(text, has_open_draft=True) is None


@pytest.mark.parametrize("text", [
    "Mình đã đặt hàng thành công.",
    "Đặt hàng thành công rồi nhé!",
    "Đơn hàng của bạn đã được tạo.",
    "Đơn hàng đã được đặt.",
    "Mình đã tạo đơn hàng cho bạn.",
    "Đã tạo đơn cho bạn rồi.",
])
def test_order_claim_detected_even_with_open_draft(text):
    assert claims_false_commitment(text, has_open_draft=False) == "order"
    assert claims_false_commitment(text, has_open_draft=True) == "order"


@pytest.mark.parametrize("text", [
    "Bạn muốn mình lập đơn nháp không?",
    'Sau khi lập đơn nháp bạn bấm "Xác nhận đặt hàng" nhé.',
    "Bạn cho mình địa chỉ để mình tạo đơn nháp nhé.",
    "Sản phẩm này hợp với da dầu.",
    "Giỏ hàng của bạn đã sẵn sàng thanh toán khi bạn muốn.",
    "",
])
def test_non_claims_are_not_flagged(text):
    assert claims_false_commitment(text, has_open_draft=False) is None


def test_g5_sentence_is_fine_with_draft_and_flagged_without():
    assert claims_false_commitment(G5_SENTENCE, has_open_draft=True) is None
    assert claims_false_commitment(G5_SENTENCE, has_open_draft=False) == "draft"


def _buy_run(responses, *, state=None, message="đặt cả giỏ, giao về địa chỉ cũ, COD"):
    router = ScriptedRouter(output("BUY"))
    model = ScriptedChatModel(responses=responses)
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    events = list(run_turn(
        graph, message=message, ctx=context(scopes=("draft:create",), state=state),
        history=[], recent_turns=[], legacy_payload={},
    ))
    return model, events


def test_false_draft_claim_is_corrected_and_only_the_question_is_emitted():
    question = "Bạn cho mình địa chỉ giao hàng nhé?"
    model, events = _buy_run([AIMessage(content=D2_SENTENCE), AIMessage(content=question)])
    tokens = [payload["text"] for kind, payload in events if kind == "token"]
    assert tokens == [question]
    assert len(model.calls) == 2
    corrective = model.calls[1][-1]
    assert corrective.type == "human" and "Kiểm tra tự động" in corrective.content
    assert events[-1] == ("done", {})
    assert [kind for kind, _ in events if kind in {"done", "error"}] == ["done"]


def test_repeated_false_draft_claim_falls_back_deterministically():
    model, events = _buy_run([AIMessage(content=D2_SENTENCE), AIMessage(content=D2_SENTENCE)])
    tokens = [payload["text"] for kind, payload in events if kind == "token"]
    assert tokens == [NO_DRAFT_FALLBACK_TEXT]
    assert "Mình chưa lập được đơn nháp" in NO_DRAFT_FALLBACK_TEXT
    assert len(model.calls) == 2
    assert events[-1] == ("done", {})


def test_draft_claim_with_open_draft_in_state_is_emitted_unchanged():
    model, events = _buy_run([AIMessage(content=G5_SENTENCE)], state=ConvState(open_draft_id="D9"))
    tokens = [payload["text"] for kind, payload in events if kind == "token"]
    assert tokens == [G5_SENTENCE]
    assert len(model.calls) == 1


def test_draft_claim_after_draft_card_emitted_this_turn_is_fine():
    php = FakePhp({"internal_agent_draft": {"ok": True, "card": {"draft_id": "D1", "expires_at": 1}}})
    router = ScriptedRouter(output("BUY"))
    model = ScriptedChatModel(responses=[
        scripted_tool_call("prepare_order_draft", {
            "scope": "whole_cart", "address_choice": "default", "payment_method": "cod",
        }, "d1"),
        AIMessage(content=G5_SENTENCE),
    ])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    events = list(run_turn(
        graph, message="đặt cả giỏ", ctx=context(scopes=("draft:create",), php=php),
        history=[], recent_turns=[], legacy_payload={},
    ))
    assert ("token", {"text": G5_SENTENCE}) in events
    assert len(model.calls) == 2


def test_order_claim_is_corrected_even_with_open_draft():
    good = 'Đơn nháp đang chờ bạn bấm "Xác nhận đặt hàng" nhé.'
    model, events = _buy_run(
        [AIMessage(content="Mình đã đặt hàng thành công!"), AIMessage(content=good)],
        state=ConvState(open_draft_id="D9"),
    )
    tokens = [payload["text"] for kind, payload in events if kind == "token"]
    assert tokens == [good]
    assert "Xác nhận đặt hàng" in model.calls[1][-1].content


def test_repeated_order_claim_with_open_draft_uses_order_fallback():
    _model, events = _buy_run(
        [AIMessage(content="Đặt hàng thành công rồi nhé"), AIMessage(content="Đặt hàng thành công rồi nhé")],
        state=ConvState(open_draft_id="D9"),
    )
    tokens = [payload["text"] for kind, payload in events if kind == "token"]
    assert tokens == [ORDER_FALLBACK_TEXT]
    assert sum(1 for kind, _ in events if kind in {"done", "error"}) == 1


def test_false_claim_in_step_cap_summary_is_replaced_by_fallback(monkeypatch):
    import agent_graph.graph as graph_module

    monkeypatch.setattr(graph_module, "MAX_STEPS", 1)
    router = ScriptedRouter(output("BUY"))
    model = ScriptedChatModel(responses=[
        scripted_tool_call("get_checkout_defaults", {}, "c1"),
        AIMessage(content=D2_SENTENCE),
    ])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_args: {"answer": "legacy"})
    events = list(run_turn(
        graph, message="đặt cả giỏ", ctx=context(scopes=("draft:create",)),
        history=[], recent_turns=[], legacy_payload={},
    ))
    texts = [payload["text"] for kind, payload in events if kind == "token"]
    assert D2_SENTENCE not in texts
    assert NO_DRAFT_FALLBACK_TEXT in texts
    assert events[-1] == ("done", {})


# --- D8: unsupported payment methods never reach the legacy bot ---

@pytest.mark.parametrize("text", [
    "mua trả góp được không", "có trả góp không shop", "thanh toán bằng MoMo được không",
    "đổi sang momo", "có ZaloPay không", "zalo pay được ko", "nhận shopeepay không",
    "Viettel Money thì sao", "dùng paypal được không", "thanh toán ví điện tử",
    "có paylater không", "pay later", "fundiin", "kredivo", "home paylater",
])
def test_unsupported_payment_questions_detected(text):
    assert is_unsupported_payment_question(text)


@pytest.mark.parametrize("text", [
    "thanh toán COD", "chuyển khoản QR nhé", "VNPay", "mình thanh toán sau khi nhận hàng",
    "serum này hợp da dầu không", "góp ý giúp mình", "momofuku", "",
    # Cards go through VNPay, so they are supported and must reach the agent.
    "quẹt thẻ tín dụng được không", "credit card ok không",
])
def test_supported_or_unrelated_messages_not_detected(text):
    assert not is_unsupported_payment_question(text)


def _route_run(labels, message, *, claims_on=True, responses=None):
    router = ScriptedRouter(output(*labels))
    model = ScriptedChatModel(responses=responses or [AIMessage(content="agent trả lời")])
    legacy_calls = []

    def legacy(msg, payload):
        legacy_calls.append(msg)
        return {"answer": "Bên mình hỗ trợ trả góp 0%!"}

    graph = build_graph(router, lambda tools: model.bind_tools(tools), legacy)
    ctx = context(scopes=("draft:create",))
    if not claims_on:
        ctx.claims = None
    events = list(run_turn(graph, message=message, ctx=ctx, history=[], recent_turns=[], legacy_payload={}))
    return model, legacy_calls, events


def test_installment_question_gets_payment_text_and_skips_legacy():
    model, legacy_calls, events = _route_run(["STORE_SUPPORT"], "mua trả góp được không")
    assert legacy_calls == []
    assert ("token", {"text": PAYMENT_SUPPORT_TEXT}) in events
    assert not model.calls
    assert events[-1] == ("done", {})


def test_guest_installment_question_with_buy_label_gets_payment_text_not_login():
    model, legacy_calls, events = _route_run(["BUY"], "có trả góp không", claims_on=False)
    assert ("token", {"text": PAYMENT_SUPPORT_TEXT}) in events
    assert not any(kind == "ui" and payload["kind"] == "login_required" for kind, payload in events)
    assert legacy_calls == [] and not model.calls


def test_logged_in_buy_momo_lets_agent_answer_without_payment_text():
    model, legacy_calls, events = _route_run(["BUY"], "đổi sang MoMo")
    assert ("token", {"text": PAYMENT_SUPPORT_TEXT}) not in events
    assert ("token", {"text": "agent trả lời"}) in events
    assert len(model.calls) == 1 and legacy_calls == []


def test_mixed_store_support_and_advise_gives_payment_text_then_agent():
    model, legacy_calls, events = _route_run(["STORE_SUPPORT", "ADVISE"], "trả góp được không, và tư vấn serum")
    tokens = [payload["text"] for kind, payload in events if kind == "token"]
    assert tokens == [PAYMENT_SUPPORT_TEXT, "agent trả lời"]
    assert legacy_calls == []


def test_payment_question_clears_clarification():
    router = ScriptedRouter(RouterOutput(
        intents=[Intent(label="STORE_SUPPORT", confidence=1)], needs_clarification="Bạn muốn hỏi gì?",
    ))
    model = ScriptedChatModel(responses=[])
    graph = build_graph(router, lambda tools: model.bind_tools(tools), lambda *_a: {"answer": "legacy"})
    events = list(run_turn(graph, message="trả góp không", ctx=context(), history=[], recent_turns=[], legacy_payload={}))
    tokens = [payload["text"] for kind, payload in events if kind == "token"]
    assert tokens == [PAYMENT_SUPPORT_TEXT]
    assert not any(kind == "ui" and payload["kind"] == "clarify" for kind, payload in events)


def _failing_turn(emits, exc=None):
    import agent_graph.graph as graph_module

    ctx = context()

    def invoke(_state):
        for kind, payload in emits:
            ctx.emit(kind, payload)
        raise exc or RuntimeError("final llm call failed")

    fake_graph = SimpleNamespace(invoke=invoke, agent_llm_factory=None)
    events = list(run_turn(
        fake_graph, message="x", ctx=ctx, history=[], recent_turns=[], legacy_payload={},
    ))
    return graph_module, events


def test_worker_failure_after_cart_effect_says_cart_updated_not_error():
    graph_module, events = _failing_turn([("cart_effect", {"effect": {"op": "add"}})])
    assert events == [
        ("cart_effect", {"effect": {"op": "add"}}),
        ("token", {"text": graph_module.MUTATION_CART_TEXT}),
        ("done", {}),
    ]


def test_worker_failure_after_draft_card_says_draft_updated_not_error():
    graph_module, events = _failing_turn([
        ("cart_effect", {"effect": {"op": "add"}}),
        ("ui", {"kind": "draft_card", "payload": {"draft_id": "D1"}}),
    ])
    assert events[-2:] == [("token", {"text": graph_module.MUTATION_DRAFT_TEXT}), ("done", {})]
    assert not any(event[0] == "error" for event in events)


def test_worker_failure_without_mutation_is_still_an_error():
    graph_module, events = _failing_turn([("ui", {"kind": "order_list", "payload": {}})])
    assert events[-1] == ("error", {"message": graph_module.ERROR_TEXT})
    assert not any(event[0] == "token" for event in events)


def test_base_exception_after_mutation_still_reraises_with_done():
    graph_module, events = [], []
    with pytest.raises(KeyboardInterrupt):
        stream = run_turn(
            SimpleNamespace(invoke=lambda _s: (_ for _ in ()).throw(KeyboardInterrupt()), agent_llm_factory=None),
            message="x", ctx=(c := context()), history=[], recent_turns=[], legacy_payload={},
        )
        c.emit("cart_effect", {"effect": {}})
        while True:
            events.append(next(stream))
