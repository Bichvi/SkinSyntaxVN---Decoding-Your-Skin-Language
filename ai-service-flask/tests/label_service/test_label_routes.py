import base64
import json
import sys
from pathlib import Path

import pytest
from flask import Flask

from label_service import config, routes
from search_service import config as search_config

jsonschema = pytest.importorskip("jsonschema")
SCHEMA_PATH = Path(__file__).resolve().parents[2] / "label_service" / "contract" / "label_analyze_response.schema.json"
VALIDATOR = jsonschema.Draft202012Validator(json.loads(SCHEMA_PATH.read_text(encoding="utf-8")))

TOKEN = "unit-test-token"
HEADERS = {"X-Internal-Token": TOKEN}
JPEG = b"\xff\xd8\xff\xe0" + b"0" * 64
TEXT = "Aqua, Glycerin, Niacinamide, Sodium Hyaluronate, Panthenol"


def b64(data: bytes) -> str:
    return base64.b64encode(data).decode()


def good_body():
    return {"status": "ok", "product": {"name": "X", "brand": "", "product_type": "serum", "matched_product_id": None,
                                        "match_score": None},
            "ingredients": {"source": "photo", "total": 0, "recognized": 0, "items": [], "raw_text": ""},
            "conflicts": [], "schedule": [],
            "skin_fit": {"verdict": "unknown", "profile": {"skin_type": None, "sensitive": False, "concerns": []},
                         "good": [], "caution": [], "avoid": []},
            "alternatives": [], "warnings": [], "summary_vi": "ok", "sunscreen_note": False, "took_ms": 1}


class FakeService:
    def __init__(self):
        self.calls = []

    def analyze(self, req, correlation_id=""):
        self.calls.append((req, correlation_id))
        return good_body()


@pytest.fixture
def fake():
    return FakeService()


@pytest.fixture
def client(monkeypatch, fake):
    monkeypatch.setattr(search_config, "INTERNAL_TOKEN", TOKEN)
    monkeypatch.setattr(config, "LABEL_SCAN_ENABLED", True)
    monkeypatch.setattr(routes.ratelimit, "allow", lambda *a, **k: (True, 0.0))
    monkeypatch.setattr(routes, "_service", lambda: fake)
    app = Flask(__name__)
    app.register_blueprint(routes.label_bp)
    return app.test_client()


def post(client, payload, headers=HEADERS, **kw):
    return client.post("/api/label/analyze", json=payload, headers=headers, **kw)


def test_401_wrong_or_missing_token(client):
    assert post(client, {"ingredients_text": TEXT}, headers={}).status_code == 401
    r = post(client, {"ingredients_text": TEXT}, headers={"X-Internal-Token": "nope"})
    assert r.status_code == 401 and r.get_json() == {"error": "unauthorized"}


def test_404_when_flag_off(client, monkeypatch):
    monkeypatch.setattr(config, "LABEL_SCAN_ENABLED", False)
    r = post(client, {"ingredients_text": TEXT})
    assert r.status_code == 404 and r.get_json() == {"error": "disabled"}


@pytest.mark.parametrize("payload", [{}, {"image_b64": b64(JPEG), "image_mime": "image/jpeg", "ingredients_text": TEXT}])
def test_400_neither_or_both_sources(client, payload):
    r = post(client, payload)
    assert r.status_code == 400 and r.get_json() == {"error": "invalid_request", "field": "image_b64"}


def test_400_non_json_body(client):
    r = client.post("/api/label/analyze", data="not json", headers=HEADERS)
    assert r.status_code == 400 and r.get_json()["error"] == "invalid_request"


def test_400_bad_base64(client):
    r = post(client, {"image_b64": "@@@not-base64@@@", "image_mime": "image/jpeg"})
    assert r.status_code == 400 and r.get_json()["field"] == "image_b64"


def test_400_magic_bytes_not_an_image(client):
    r = post(client, {"image_b64": b64(b"hello world, not an image"), "image_mime": "image/jpeg"})
    assert r.status_code == 400 and r.get_json()["field"] == "image_mime"


def test_413_by_content_length(client, monkeypatch, fake):
    monkeypatch.setattr(config, "LABEL_MAX_IMAGE_BYTES", 100)
    r = client.post("/api/label/analyze", data=b"x" * 70000, content_type="application/json", headers=HEADERS)
    assert r.status_code == 413 and r.get_json() == {"error": "image_too_large"}
    assert fake.calls == []


def test_413_by_decoded_size(client, monkeypatch):
    monkeypatch.setattr(config, "LABEL_MAX_IMAGE_BYTES", 100)
    r = post(client, {"image_b64": b64(JPEG + b"0" * 100), "image_mime": "image/jpeg"})
    assert r.status_code == 413 and r.get_json() == {"error": "image_too_large"}


def test_429_with_retry_after(client, monkeypatch, fake):
    seen = {}

    def deny(key, limit, window_s, **kw):
        seen.update(key=key, limit=limit, window=window_s)
        return False, 2.3

    monkeypatch.setattr(routes.ratelimit, "allow", deny)
    r = post(client, {"ingredients_text": TEXT}, environ_base={"REMOTE_ADDR": "172.18.0.5"})
    assert r.status_code == 429 and r.headers["Retry-After"] == "3" and r.get_json() == {"error": "rate_limited"}
    assert seen["key"] == "rl:label_analyze:v1:172.18.0.5"
    assert seen["limit"] == config.RATE_LIMIT_COUNT and seen["window"] == config.RATE_LIMIT_WINDOW_S
    assert fake.calls == []


def test_unauthorized_does_not_consume_rate_limit(client, monkeypatch):
    monkeypatch.setattr(routes.ratelimit, "allow", lambda *a, **k: (_ for _ in ()).throw(AssertionError("called")))
    assert post(client, {"ingredients_text": TEXT}, headers={}).status_code == 401


def test_service_crash_returns_vision_unavailable_200(client, monkeypatch):
    class Crash:
        def analyze(self, *a, **k):
            raise RuntimeError("boom")

    monkeypatch.setattr(routes, "_service", lambda: Crash())
    r = post(client, {"ingredients_text": TEXT})
    body = r.get_json()
    assert r.status_code == 200 and body["status"] == "vision_unavailable"
    assert body["warnings"] == ["internal_error"] and body["summary_vi"]
    assert not list(VALIDATOR.iter_errors(body))


def test_happy_path_validates_and_echoes_request_id(client, fake):
    r = post(client, {"ingredients_text": TEXT, "product_type": "serum"}, headers={**HEADERS, "X-Request-Id": "abc-1"})
    assert r.status_code == 200 and r.headers["X-Request-Id"] == "abc-1"
    assert not list(VALIDATOR.iter_errors(r.get_json()))
    req, cid = fake.calls[0]
    assert cid == "abc-1" and req.product_type == "serum" and req.ingredients_text == TEXT


def test_request_id_generated_when_absent(client):
    r = post(client, {"image_b64": b64(JPEG), "image_mime": "image/jpeg"})
    assert r.status_code == 200 and len(r.headers["X-Request-Id"]) >= 8


def test_label_service_does_not_import_chatbot_flask():
    import label_service  # noqa: F401
    import label_service.routes  # noqa: F401

    assert "chatbot_service.chatbot_flask" not in sys.modules
