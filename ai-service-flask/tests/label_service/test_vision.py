import base64
import json
import logging

import pytest
import requests

from label_service import vision
from label_service.vision import LabelText, VisionUnavailable, looks_like_label, read_label

GROQ_URL = vision._ENDPOINTS["groq"]
GEMINI_URL = vision._ENDPOINTS["gemini"]
KEY_G, KEY_M = "fake-groq-key-123", "fake-gemini-key-456"
ING = "Aqua, Glycerin, Niacinamide, Sodium Hyaluronate, Panthenol, Allantoin"
IMG = b"\x89PNG-fake-image-bytes" * 5


@pytest.fixture(autouse=True)
def keys(monkeypatch):
    monkeypatch.setenv("GROQ_API_KEY", KEY_G)
    monkeypatch.setenv("GOOGLE_API_KEY", KEY_M)


def _resp(payload, fence=False):
    content = json.dumps(payload) if isinstance(payload, dict) else payload
    if fence:
        content = "```json\n" + content + "\n```"
    return 200, json.dumps({"choices": [{"message": {"content": content}}]})


def _good(**over):
    d = {"is_label": True, "product_name": "Serum X", "brand": "Brand", "product_type": "serum",
         "ingredients_text": ING, "language": "en"}
    d.update(over)
    return d


class Fake:
    def __init__(self, handler):
        self.handler, self.calls = handler, []

    def __call__(self, url, headers, body, timeout):
        self.calls.append((url, headers, body, timeout))
        return self.handler(url)


def test_valid_groq():
    t = Fake(lambda u: _resp(_good()))
    lt = read_label(IMG, "image/png", transport=t)
    assert isinstance(lt, LabelText) and lt.provider == "groq"
    assert lt.ingredients_text == ING and lt.product_type == "serum"
    assert looks_like_label(lt)
    assert len(t.calls) == 1


def test_request_body():
    t = Fake(lambda u: _resp(_good()))
    read_label(IMG, "image/jpeg", transport=t)
    url, headers, body, _ = t.calls[0]
    assert url == GROQ_URL and headers["Authorization"] == f"Bearer {KEY_G}"
    assert body["model"] == "qwen/qwen3.8-27b"
    assert body["response_format"] == {"type": "json_object"} and body["temperature"] == 0
    part = body["messages"][1]["content"][0]
    assert part["image_url"]["url"] == "data:image/jpeg;base64," + base64.b64encode(IMG).decode()


def test_invalid_json_falls_back():
    def h(u):
        return _resp("not json {") if u == GROQ_URL else _resp(_good())
    t = Fake(h)
    assert read_label(IMG, "image/png", transport=t).provider == "gemini"
    assert t.calls[1][0] == GEMINI_URL


def test_missing_keys_falls_back():
    def h(u):
        return _resp({"is_label": True}) if u == GROQ_URL else _resp(_good())
    assert read_label(IMG, "image/png", transport=Fake(h)).provider == "gemini"


def test_429_falls_back():
    def h(u):
        return (429, "rate") if u == GROQ_URL else _resp(_good())
    assert read_label(IMG, "image/png", transport=Fake(h)).provider == "gemini"


def test_both_timeout():
    def h(u):
        raise requests.Timeout("slow")
    with pytest.raises(VisionUnavailable) as e:
        read_label(IMG, "image/png", transport=Fake(h))
    assert KEY_G not in str(e.value)


def test_not_label_and_short():
    lt = read_label(IMG, "image/png", transport=Fake(lambda u: _resp(_good(is_label=False))))
    assert lt.is_label is False and not looks_like_label(lt)
    lt = read_label(IMG, "image/png", transport=Fake(lambda u: _resp(_good(ingredients_text="Aqua"))))
    assert not looks_like_label(lt)


def test_truncation_and_control_chars():
    lt = read_label(IMG, "image/png", transport=Fake(lambda u: _resp(_good(ingredients_text="a, " * 5000))))
    assert len(lt.ingredients_text) <= 6000
    raw = "Aqua,\x00 Glycerin,\n\x07Niacinamide"
    lt = read_label(IMG, "image/png", transport=Fake(lambda u: _resp(_good(ingredients_text=raw))))
    assert lt.ingredients_text == "Aqua, Glycerin, Niacinamide"


def test_fenced_json_and_unknown_type():
    lt = read_label(IMG, "image/png", transport=Fake(lambda u: _resp(_good(product_type="weird"), fence=True)))
    assert lt.product_type == "other" and lt.ingredients_text == ING


def test_missing_groq_key_skipped(monkeypatch):
    monkeypatch.delenv("GROQ_API_KEY")
    t = Fake(lambda u: _resp(_good()))
    assert read_label(IMG, "image/png", transport=t).provider == "gemini"
    assert all(c[0] != GROQ_URL for c in t.calls)


def test_no_keys_unavailable(monkeypatch):
    monkeypatch.delenv("GROQ_API_KEY")
    monkeypatch.delenv("GOOGLE_API_KEY")
    monkeypatch.delenv("GOOGLE_API_KEYS", raising=False)
    with pytest.raises(VisionUnavailable):
        read_label(IMG, "image/png", transport=Fake(lambda u: _resp(_good())))


def test_logs_have_no_secrets(caplog):
    caplog.set_level(logging.DEBUG)

    def h(u):
        return (500, "boom " + ING) if u == GROQ_URL else _resp(_good())
    read_label(IMG, "image/png", transport=Fake(h), correlation_id="cid-1")
    text = caplog.text
    assert "cid-1" in text and "provider=groq" in text
    for secret in (KEY_G, KEY_M, ING, base64.b64encode(IMG).decode()):
        assert secret not in text


# ---- usage (token accounting, no content)
def _resp_usage(payload, usage):
    status, body = _resp(payload)
    data = json.loads(body)
    if usage is not None:
        data["usage"] = usage
    return status, json.dumps(data)


def test_usage_parsed_from_response():
    t = Fake(lambda u: _resp_usage(_good(), {"prompt_tokens": 1200, "completion_tokens": 340}))
    lt = read_label(IMG, "image/png", transport=t)
    assert lt.usage == ({"provider": "groq", "model": vision._model_for("groq"), "ok": True,
                         "prompt_tokens": 1200, "completion_tokens": 340},)


def test_usage_missing_is_zero():
    lt = read_label(IMG, "image/png", transport=Fake(lambda u: _resp(_good())))
    assert lt.usage[0]["prompt_tokens"] == 0 and lt.usage[0]["completion_tokens"] == 0 and lt.usage[0]["ok"] is True


def test_usage_failed_then_success_has_two_entries():
    def h(u):
        if u == GROQ_URL:
            return _resp_usage("not json at all", {"prompt_tokens": 900, "completion_tokens": 5})
        return _resp_usage(_good(), {"prompt_tokens": 1000, "completion_tokens": 200})
    lt = read_label(IMG, "image/png", transport=Fake(h))
    assert [(a["provider"], a["ok"]) for a in lt.usage] == [("groq", False), ("gemini", True)]
    assert lt.usage[0]["prompt_tokens"] == 900 and lt.usage[1]["completion_tokens"] == 200


def test_usage_http_error_counts_zero_tokens():
    def h(u):
        return (429, '{"usage": {"prompt_tokens": 5}}') if u == GROQ_URL else _resp(_good())
    lt = read_label(IMG, "image/png", transport=Fake(h))
    assert lt.usage[0]["ok"] is False and lt.usage[0]["prompt_tokens"] == 0


def test_visionunavailable_carries_attempts():
    def h(u):
        if u == GROQ_URL:
            raise requests.Timeout("slow")
        return _resp_usage("garbage", {"prompt_tokens": 700, "completion_tokens": 9})
    with pytest.raises(VisionUnavailable) as e:
        read_label(IMG, "image/png", transport=Fake(h))
    assert [(a["provider"], a["ok"]) for a in e.value.attempts] == [("groq", False), ("gemini", False)]
    assert e.value.attempts[1]["prompt_tokens"] == 700
    assert VisionUnavailable("x").attempts == []


def test_labeltext_positional_construction_still_works():
    assert LabelText(True, "n", "b", "serum", ING, "en", "groq").usage == ()
