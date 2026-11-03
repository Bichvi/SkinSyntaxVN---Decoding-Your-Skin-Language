from __future__ import annotations

import threading
import time
from queue import Empty, Queue as StdQueue
from types import SimpleNamespace

import pytest
from langchain_core.messages import AIMessage

from agent_graph.auth import AgentClaims
from agent_graph.graph import build_graph, run_turn
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
    model = ScriptedChatModel(responses=[AIMessage(content="Mình đã đặt hàng thành công.")])
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
        ("token", {"text": "Mình đã đặt hàng thành công."}),
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
