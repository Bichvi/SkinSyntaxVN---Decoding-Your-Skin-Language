import os

import pytest
from flask import Flask

from search_service import config, routes

TOKEN = "unit-test-token"


class FakeService:
    def recommend(self, req, correlation_id=""):
        return {"mode": "fallback", "degraded": False, "degraded_branches": [], "took_ms": 3, "intent": None,
                "sections": [{"key": "semantic", "items": [
                    {"product_id": "1496", "score": 0.8, "reason_code": "semantic_match", "reason_params": []}]}]}


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setattr(routes, "_warm_pid", os.getpid())
    monkeypatch.setattr(routes, "_service", lambda: FakeService())
    app = Flask(__name__)
    app.register_blueprint(routes.search_bp)
    return app.test_client()


def test_401_when_token_missing_or_wrong(client, monkeypatch):
    monkeypatch.setattr(config, "INTERNAL_TOKEN", TOKEN)
    assert client.post("/api/search/recommend", json={"q": "x"}).status_code == 401
    r = client.post("/api/search/recommend", json={"q": "x"}, headers={"X-Internal-Token": "nope"})
    assert r.status_code == 401
    assert client.get("/api/search/health").status_code == 401


def test_no_token_only_private_callers(client, monkeypatch):
    monkeypatch.setattr(config, "INTERNAL_TOKEN", "")
    r = client.post("/api/search/recommend", json={"q": "x"}, environ_base={"REMOTE_ADDR": "8.8.8.8"})
    assert r.status_code == 401
    r = client.post("/api/search/recommend", json={"q": "x"}, environ_base={"REMOTE_ADDR": "172.18.0.5"})
    assert r.status_code == 200


def test_400_invalid(client, monkeypatch):
    monkeypatch.setattr(config, "INTERNAL_TOKEN", TOKEN)
    r = client.post("/api/search/recommend", json={"q": ""}, headers={"X-Internal-Token": TOKEN})
    assert r.status_code == 400 and r.get_json() == {"error": "invalid_request", "field": "q"}
    r = client.post("/api/search/recommend", data="not json", headers={"X-Internal-Token": TOKEN})
    assert r.status_code == 400


def test_200_shape_and_request_id(client, monkeypatch):
    monkeypatch.setattr(config, "INTERNAL_TOKEN", TOKEN)
    r = client.post("/api/search/recommend", json={"q": "torriden"},
                    headers={"X-Internal-Token": TOKEN, "X-Request-Id": "abc-1"})
    body = r.get_json()
    assert r.status_code == 200 and r.headers["X-Request-Id"] == "abc-1"
    assert set(body) == {"mode", "degraded", "degraded_branches", "took_ms", "intent", "sections"}


def test_service_crash_returns_degraded_200(client, monkeypatch):
    monkeypatch.setattr(config, "INTERNAL_TOKEN", TOKEN)

    class Crash:
        def recommend(self, *a, **k):
            raise RuntimeError("boom")

    monkeypatch.setattr(routes, "_service", lambda: Crash())
    r = client.post("/api/search/recommend", json={"q": "x"}, headers={"X-Internal-Token": TOKEN})
    assert r.status_code == 200 and r.get_json()["degraded"] is True


def test_429_with_retry_after_when_rate_limited(client, monkeypatch):
    monkeypatch.setattr(config, "INTERNAL_TOKEN", TOKEN)
    seen = {}

    def deny(key, limit, window_s, **kw):
        seen.update(key=key, limit=limit, window=window_s)
        return False, 2.3

    monkeypatch.setattr(routes.ratelimit, "allow", deny)
    r = client.post("/api/search/recommend", json={"q": "x"}, headers={"X-Internal-Token": TOKEN},
                    environ_base={"REMOTE_ADDR": "172.18.0.5"})
    assert r.status_code == 429 and r.headers["Retry-After"] == "3" and r.get_json() == {"error": "rate_limited"}
    assert seen["key"] == "rl:search_recommend:v1:172.18.0.5" and seen["limit"] == config.RATE_LIMIT_PER_WINDOW


def test_unauthorized_requests_do_not_consume_rate_limit(client, monkeypatch):
    monkeypatch.setattr(config, "INTERNAL_TOKEN", TOKEN)
    monkeypatch.setattr(routes.ratelimit, "allow", lambda *a, **k: (_ for _ in ()).throw(AssertionError("called")))
    assert client.post("/api/search/recommend", json={"q": "x"}).status_code == 401
