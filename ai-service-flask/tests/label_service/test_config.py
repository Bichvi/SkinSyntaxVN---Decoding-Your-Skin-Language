import importlib

import pytest

from label_service import config

ENV = [
    "LABEL_SCAN_ENABLED", "LABEL_VISION_PROVIDERS", "LABEL_GROQ_VISION_MODEL", "LABEL_GEMINI_VISION_MODEL",
    "LABEL_VISION_TIMEOUT_S", "LABEL_MAX_IMAGE_MB", "LABEL_RATE_LIMIT", "GOOGLE_API_KEY", "GOOGLE_API_KEYS",
]


@pytest.fixture
def fresh(monkeypatch):
    for k in ENV:
        monkeypatch.delenv(k, raising=False)
    yield lambda: importlib.reload(config)
    for k in ENV:
        monkeypatch.delenv(k, raising=False)
    importlib.reload(config)


def test_defaults(fresh):
    c = fresh()
    assert c.LABEL_SCAN_ENABLED is False
    assert c.LABEL_VISION_PROVIDERS == ["groq", "gemini"]
    assert c.LABEL_GROQ_VISION_MODEL == "qwen/qwen3.8-27b"
    assert c.LABEL_GEMINI_VISION_MODEL == "gemini-2.5-flash"
    assert c.LABEL_VISION_TIMEOUT_S == 12.0
    assert c.LABEL_MAX_IMAGE_BYTES == 4 * 1024 * 1024
    assert (c.RATE_LIMIT_COUNT, c.RATE_LIMIT_WINDOW_S) == (60, 60.0)
    assert c.MAX_INGREDIENTS_TEXT == 6000 and c.MIN_INGREDIENTS_TEXT == 20


def test_rate_limit_parsing(fresh, monkeypatch):
    monkeypatch.setenv("LABEL_RATE_LIMIT", "10/60")
    c = fresh()
    assert (c.RATE_LIMIT_COUNT, c.RATE_LIMIT_WINDOW_S) == (10, 60.0)
    monkeypatch.setenv("LABEL_RATE_LIMIT", "garbage")
    c = fresh()
    assert (c.RATE_LIMIT_COUNT, c.RATE_LIMIT_WINDOW_S) == (60, 60.0)


def test_overrides(fresh, monkeypatch):
    monkeypatch.setenv("LABEL_SCAN_ENABLED", "true")
    monkeypatch.setenv("LABEL_MAX_IMAGE_MB", "2")
    monkeypatch.setenv("LABEL_VISION_PROVIDERS", "gemini")
    c = fresh()
    assert c.LABEL_SCAN_ENABLED is True
    assert c.LABEL_MAX_IMAGE_BYTES == 2 * 1024 * 1024
    assert c.LABEL_VISION_PROVIDERS == ["gemini"]


def test_gemini_key_fallback(monkeypatch):
    monkeypatch.delenv("GOOGLE_API_KEY", raising=False)
    monkeypatch.setenv("GOOGLE_API_KEYS", " , k2 ,k3")
    assert config.gemini_key() == "k2"
    monkeypatch.setenv("GOOGLE_API_KEY", "k1")
    assert config.gemini_key() == "k1"


def test_groq_key_read_at_call_time(monkeypatch):
    monkeypatch.delenv("GROQ_API_KEY", raising=False)
    assert config.groq_key() == ""
    monkeypatch.setenv("GROQ_API_KEY", "gk")
    assert config.groq_key() == "gk"
