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
