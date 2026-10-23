import pytest
from flask import Flask

from home_service import routes
from search_service import config

TOKEN = "unit-test-token"


class FakeService:
    def recommend(self, req, correlation_id=""):
        return {"degraded": False, "took_ms": 3, "sections": [{"key": "concern", "items": []}]}


class BoomService:
    def recommend(self, req, correlation_id=""):
        raise RuntimeError("boom")


@pytest.fixture
def make_client(monkeypatch):
    monkeypatch.setattr(config, "INTERNAL_TOKEN", TOKEN)

    def _make(service):
        monkeypatch.setattr(routes, "get_default_service", lambda: service)
        app = Flask(__name__)
        app.register_blueprint(routes.home_bp)
        return app.test_client()

    return _make


AUTH = {"X-Internal-Token": TOKEN}


def test_unauthorized_without_token(make_client):
    c = make_client(FakeService())
    assert c.post("/api/home/recommend", json={"sections": ["concern"]}).status_code == 401
    r = c.post("/api/home/recommend", json={"sections": ["concern"]}, headers=AUTH)
    assert r.status_code == 200
    assert r.get_json()["degraded"] is False


def test_invalid_payload_400(make_client):
    r = make_client(FakeService()).post("/api/home/recommend", json={"sections": ["x"]}, headers=AUTH)
    assert r.status_code == 400
    assert r.get_json() == {"error": "invalid_request", "field": "sections"}


def test_service_exception_degrades(make_client):
    r = make_client(BoomService()).post("/api/home/recommend", json={"sections": ["concern"]}, headers=AUTH)
    assert r.status_code == 200
    assert r.get_json() == {"degraded": True, "took_ms": 0, "sections": []}


def test_request_id_echoed(make_client):
    c = make_client(FakeService())
    r = c.post("/api/home/recommend", json={"sections": ["concern"]}, headers={**AUTH, "X-Request-Id": "abc-1"})
    assert r.headers["X-Request-Id"] == "abc-1"
    r = c.post("/api/home/recommend", json={"sections": ["concern"]}, headers=AUTH)
    assert 0 < len(r.headers["X-Request-Id"]) <= 64
    r = c.post("/api/home/recommend", json={"sections": ["concern"]}, headers={**AUTH, "X-Request-Id": "x" * 200})
    assert len(r.headers["X-Request-Id"]) == 64
