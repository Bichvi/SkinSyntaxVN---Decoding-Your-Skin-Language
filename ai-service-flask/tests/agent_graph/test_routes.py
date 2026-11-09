from __future__ import annotations

import json
import threading

import pytest
from flask import Flask

from agent_graph import config
from agent_graph.auth import AgentClaims


@pytest.fixture
def route_app(monkeypatch):
    from agent_graph import routes

    app = Flask("agent-route-tests")
    app.register_blueprint(routes.agent_bp)
    monkeypatch.setattr(config, "INTERNAL_SECRET", "internal-secret")
    monkeypatch.setattr(config, "TOKEN_SECRET", "token-secret")
    monkeypatch.setattr(routes, "_get_graph", lambda: object())
    monkeypatch.setattr(routes, "_get_state_store", lambda: None)
    monkeypatch.setattr(routes, "identity_from_request", lambda _request: None)
    return app, routes


def _claims() -> AgentClaims:
    return AgentClaims("7", "user@example.test", "sid", ("chat",), 2_000_000_000, "jti")


def _headers(**extra):
    return {"X-Internal-Token": "internal-secret", **extra}


def _body(response):
    return [json.loads(line.split(": ", 1)[1]) for line in response.data.decode().splitlines() if line.startswith("data: ")]


def test_rejects_missing_internal_token(route_app):
    app, _routes = route_app

    response = app.test_client().post("/api/agent/chat", json={"message": "hi"})

    assert response.status_code == 401
    assert response.is_json


def test_empty_internal_secret_is_fail_closed(route_app, monkeypatch):
    app, _routes = route_app
    monkeypatch.setattr(config, "INTERNAL_SECRET", "")

    response = app.test_client().post(
        "/api/agent/chat", headers={"X-Internal-Token": "internal-secret"}, json={"message": "hi"}
    )

    assert response.status_code == 401


def test_streams_meta_first_done_last(route_app, monkeypatch):
    app, routes = route_app
    captured = {}
    saved = threading.Event()
    from chatbot_service import chatbot_flask

    monkeypatch.setattr(routes, "identity_from_request", lambda _request: _claims())
    monkeypatch.setattr(chatbot_flask, "create_new_conversation", lambda email, message: "conv-1")
    monkeypatch.setattr(
        chatbot_flask,
        "save_chat_messages",
        lambda *args: (captured.setdefault("saved", args), saved.set()),
    )

    def fake_run_turn(graph, **kwargs):
        captured.update(kwargs)
        yield "token", {"text": "Xin "}
        yield "token", {"text": "chào"}
        yield "done", {}

    monkeypatch.setattr(routes, "run_turn", fake_run_turn)

    response = app.test_client().post(
        "/api/agent/chat",
        headers=_headers(**{"X-Agent-Token": "raw-agent-token"}),
        json={"message": "hi"},
    )

    assert response.status_code == 200
    assert response.headers["Content-Type"] == "text/event-stream"
    assert response.headers["Cache-Control"] == "no-cache"
    assert response.headers["X-Accel-Buffering"] == "no"
    events = [line for line in response.data.decode().splitlines() if line.startswith("event: ")]
    assert events[0] == "event: meta"
    assert events[-1] == "event: done"
    assert _body(response)[0]["conversation_id"] == "conv-1"
    assert captured["conversation_id"] == "conv-1"
    assert captured["ctx"].php.agent_token == "raw-agent-token"
    assert saved.wait(1)
    assert captured["saved"][0:4] == ("conv-1", "user@example.test", "hi", "Xin chào")


def test_invalid_agent_token_runs_as_guest(route_app, monkeypatch):
    app, routes = route_app
    captured = {}
    monkeypatch.setattr(routes, "identity_from_request", lambda _request: None)

    def fake_run_turn(_graph, **kwargs):
        captured["ctx"] = kwargs["ctx"]
        yield "done", {}

    monkeypatch.setattr(routes, "run_turn", fake_run_turn)

    response = app.test_client().post(
        "/api/agent/chat",
        headers=_headers(**{"X-Agent-Token": "not-a-valid-token"}),
        json={"message": "hi"},
    )

    assert response.status_code == 200
    _ = response.data
    assert captured["ctx"].claims is None
    assert captured["ctx"].php is None


def test_message_too_long_400(route_app):
    app, _routes = route_app

    response = app.test_client().post(
        "/api/agent/chat", headers=_headers(), json={"message": "x" * 2001}
    )

    assert response.status_code == 400


def test_whitespace_message_is_rejected(route_app):
    app, _routes = route_app

    response = app.test_client().post(
        "/api/agent/chat", headers=_headers(), json={"message": "   \t"}
    )

    assert response.status_code == 400


def test_foreign_conversation_is_rejected_before_stream(route_app, monkeypatch):
    app, routes = route_app
    from chatbot_service import chatbot_flask

    monkeypatch.setattr(routes, "identity_from_request", lambda _request: _claims())
    monkeypatch.setattr(chatbot_flask, "get_conversation_by_id", lambda *_args: None)
    monkeypatch.setattr(routes, "run_turn", lambda *_args, **_kwargs: pytest.fail("must not stream"))

    response = app.test_client().post(
        "/api/agent/chat", headers=_headers(), json={"message": "hi", "conversation_id": "foreign"}
    )

    assert response.status_code == 403
    assert response.is_json


def test_graph_build_failure_yields_terminal_error(route_app, monkeypatch):
    app, routes = route_app
    monkeypatch.setattr(routes, "_get_graph", lambda: (_ for _ in ()).throw(RuntimeError("boom")))

    response = app.test_client().post(
        "/api/agent/chat", headers=_headers(), json={"message": "hi"}
    )

    events = [line for line in response.data.decode().splitlines() if line.startswith("event: ")]
    assert events == ["event: meta", "event: error"]


def test_graph_none_yields_error_without_persistence(route_app, monkeypatch):
    app, routes = route_app
    captured = {"saved": False}
    from chatbot_service import chatbot_flask

    monkeypatch.setattr(routes, "identity_from_request", lambda _request: _claims())
    monkeypatch.setattr(chatbot_flask, "create_new_conversation", lambda email, message: "conv-1")
    monkeypatch.setattr(chatbot_flask, "save_chat_messages", lambda *args: captured.__setitem__("saved", True))
    monkeypatch.setattr(routes, "_get_graph", lambda: None)

    response = app.test_client().post(
        "/api/agent/chat", headers=_headers(), json={"message": "hi"}
    )

    assert [line for line in response.data.decode().splitlines() if line.startswith("event: ")] == [
        "event: meta", "event: error"
    ]
    assert captured["saved"] is False


def test_unknown_events_are_not_streamed(route_app, monkeypatch):
    app, routes = route_app

    def fake_run_turn(_graph, **kwargs):
        yield "unknown", {"secret": True}
        yield "done", {}

    monkeypatch.setattr(routes, "run_turn", fake_run_turn)

    response = app.test_client().post(
        "/api/agent/chat", headers=_headers(), json={"message": "hi"}
    )

    assert "event: unknown" not in response.data.decode()


def test_run_turn_is_closed_when_client_stops_reading(route_app, monkeypatch):
    app, routes = route_app
    closed = threading.Event()

    def fake_run_turn(_graph, **kwargs):
        try:
            yield "token", {"text": "partial"}
            while True:
                yield "token", {"text": "more"}
        finally:
            closed.set()

    monkeypatch.setattr(routes, "run_turn", fake_run_turn)
    response = app.test_client().post(
        "/api/agent/chat", headers=_headers(), json={"message": "hi"}, buffered=False
    )
    stream = iter(response.response)
    next(stream)
    next(stream)
    response.close()

    assert closed.wait(1)


def test_registration_flag_controls_fresh_flask_app(monkeypatch):
    from agent_graph import routes

    monkeypatch.setattr(config, "AGENT_V2", False)
    off_app = Flask("agent-route-off")
    if config.AGENT_V2:
        routes.register(off_app)
    assert off_app.test_client().post("/api/agent/chat").status_code == 404

    monkeypatch.setattr(config, "AGENT_V2", True)
    on_app = Flask("agent-route-on")
    if config.AGENT_V2:
        routes.register(on_app)
    assert on_app.test_client().post("/api/agent/chat").status_code != 404


def test_profile_accepts_canonical_keys(route_app):
    _app, routes = route_app

    result = routes._profile({
        "skin_type": "Da thường",
        "goal": "Dưỡng sáng",
        "concerns": ["Lỗ chân lông to"],
        "avoid_ingredients": "cồn, hương liệu",
        "budget_max": 500000,
        "junk": "x",
    })

    assert result == {
        "skin_type": "Da thường",
        "goal": "Dưỡng sáng",
        "concerns": ["Lỗ chân lông to"],
        "avoid_ingredients": ["cồn, hương liệu"],
        "budget_max": 500000,
    }


def test_profile_maps_legacy_keys_and_drops_junk(route_app):
    _app, routes = route_app

    result = routes._profile({"loai_da": "Da dầu", "van_de": "Mụn", "evil": "x", "budget_max": True})

    assert result == {"skin_type": "Da dầu", "concerns": ["Mụn"]}
    assert routes._profile({"budget_max": 0, "concerns": [1, "a" * 300]}) == {"concerns": ["a" * 200]}
    assert routes._profile("nope") == {}


def test_profile_keeps_sensitivity_and_valid_updated_at(route_app):
    _app, routes = route_app

    kept = routes._profile({
        "skin_type": "Da dầu",
        "sensitivity": "  Khỏe mạnh, hiếm khi ",
        "updated_at": "2026-11-01T10:00:00+00:00",
        "budget_max": 500000,
    })
    assert kept["sensitivity"] == "Khỏe mạnh, hiếm khi"
    assert kept["updated_at"] == "2026-11-01T10:00:00+00:00"

    dropped = routes._profile({"skin_type": "Da dầu", "sensitivity": 5, "updated_at": "yesterday"})
    assert "sensitivity" not in dropped
    assert "updated_at" not in dropped
    assert "updated_at" not in routes._profile({"updated_at": "2026-11-01" + "x" * 40})


def test_legacy_payload_mirrors_budget_max_without_mutating_profile(route_app, monkeypatch):
    app, routes = route_app
    captured = {}

    def fake_run_turn(_graph, **kwargs):
        captured.update(kwargs)
        yield "done", {}

    monkeypatch.setattr(routes, "run_turn", fake_run_turn)

    response = app.test_client().post(
        "/api/agent/chat",
        headers=_headers(),
        json={"message": "tư vấn cho tôi", "profile": {
            "skin_type": "Da dầu", "sensitivity": "Khỏe mạnh, hiếm khi",
            "updated_at": "2026-11-01T10:00:00+00:00", "budget_max": 500000,
        }},
    )

    assert response.status_code == 200
    _ = response.data
    legacy = captured["legacy_payload"]["customer_profile"]
    assert legacy["budget"] == 500000
    assert legacy["sensitivity"] == "Khỏe mạnh, hiếm khi"
    assert legacy["updated_at"] == "2026-11-01T10:00:00+00:00"
    assert "budget" not in captured["skin_profile"]
    assert captured["skin_profile"]["budget_max"] == 500000


def test_scanned_product_validator_drops_junk(route_app):
    _app, routes = route_app
    clean = routes._scanned_product({
        "name": "  Toner A  ", "brand": "B" * 300, "product_type": "weird", "in_shop": True, "matched_product_id": "bad id!",
        "ingredients": ["Aqua", "", 5, "x" * 200] + ["i%d" % n for n in range(80)],
        "highlights": ["Niacinamide"] * 20, "evil": "drop me",
        "skin_fit": {"verdict": "bogus", "profile": {"skin_type": "alien", "sensitive": "yes"}, "findings": [
            {"verdict": "good", "reason_vi": "r" * 500, "ingredients": ["a"] * 9, "x": 1},
            {"verdict": "nonsense", "reason_vi": "no"}, "junk",
        ] + [{"verdict": "caution", "reason_vi": "c", "ingredients": []}] * 12},
        "conflicts": [{"cart_product_name": "Retinol", "severity": "high", "advice_vi": "a" * 500, "ingredients": ["AHA"], "z": 1}] * 5,
    })
    assert clean["name"] == "Toner A" and len(clean["brand"]) == 100
    assert clean["product_type"] == "other" and clean["in_shop"] is False and clean["matched_product_id"] == ""
    assert len(clean["ingredients"]) == 60 and clean["ingredients"][0] == "Aqua" and len(clean["ingredients"][1]) == 80
    assert len(clean["highlights"]) == 8 and "evil" not in clean
    sf = clean["skin_fit"]
    assert sf["verdict"] == "unknown" and sf["profile"] == {"skin_type": None, "sensitive": False}
    assert len(sf["findings"]) == 8 and len(sf["findings"][0]["reason_vi"]) == 300 and len(sf["findings"][0]["ingredients"]) == 6
    assert len(clean["conflicts"]) == 3 and len(clean["conflicts"][0]["advice_vi"]) == 300 and "z" not in clean["conflicts"][0]


@pytest.mark.parametrize("value", [None, "str", 5, [], {}, {"name": "", "ingredients": []}, {"name": 5, "ingredients": "x"}])
def test_scanned_product_validator_empty_or_bad_types(route_app, value):
    _app, routes = route_app
    assert routes._scanned_product(value) == {}


def test_scanned_product_reaches_tool_context(route_app, monkeypatch):
    app, routes = route_app
    captured = {}

    def fake_run_turn(_graph, **kwargs):
        captured["ctx"] = kwargs["ctx"]
        yield "done", {}

    monkeypatch.setattr(routes, "run_turn", fake_run_turn)
    body = {"message": "hi", "scanned_product": {"name": "Toner A", "ingredients": ["Aqua"], "in_shop": True, "matched_product_id": "12"}}
    response = app.test_client().post("/api/agent/chat", headers=_headers(), json=body)
    _ = response.data
    assert captured["ctx"].scanned_product["name"] == "Toner A"
    assert captured["ctx"].scanned_product["in_shop"] is True
    assert captured["ctx"].scanned_product["matched_product_id"] == "12"

    captured.clear()
    response = app.test_client().post("/api/agent/chat", headers=_headers(), json={"message": "hi", "scanned_product": "junk"})
    _ = response.data
    assert captured["ctx"].scanned_product == {}
