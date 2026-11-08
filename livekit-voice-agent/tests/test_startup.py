import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import agent  # noqa: E402
from config import _health_url  # noqa: E402


class FakeClock:
    def __init__(self):
        self.now = 0.0
        self.sleeps = []

    def monotonic(self):
        return self.now

    def sleep(self, secs):
        self.sleeps.append(secs)
        self.now += secs


def test_health_url_derived_from_endpoint():
    assert _health_url("http://ai-service:5001/api/chat/auto") == "http://ai-service:5001/api/health"
    assert _health_url("http://voice-service:5002/api/tts/stream") == "http://voice-service:5002/api/health"


def test_waits_with_exponential_backoff_then_succeeds():
    clock = FakeClock()
    calls = {"ai": 0}

    def probe(url):
        if "ai" in url:
            calls["ai"] += 1
            return calls["ai"] >= 4
        return True

    ok = agent.wait_for_dependencies(
        [("ai", "http://ai/api/health"), ("tts", "http://tts/api/health")],
        max_wait=120, initial_backoff=2, max_backoff=5,
        probe=probe, sleep=clock.sleep, monotonic=clock.monotonic,
    )
    assert ok is True
    assert clock.sleeps == [2, 4, 5]  # nhân đôi, chặn ở max_backoff


def test_gives_up_after_deadline_without_chat_requests(monkeypatch):
    clock = FakeClock()
    posted = []
    monkeypatch.setattr(agent.requests, "post", lambda *a, **k: posted.append(a))

    ok = agent.wait_for_dependencies(
        [("tts", "http://tts/api/health")],
        max_wait=20, initial_backoff=2, max_backoff=8,
        probe=lambda url: False, sleep=clock.sleep, monotonic=clock.monotonic,
    )
    assert ok is False
    assert clock.now <= 20
    assert posted == []


def test_probe_health_false_on_connection_error(monkeypatch):
    def boom(*a, **k):
        raise agent.requests.ConnectionError("dns")

    monkeypatch.setattr(agent.requests, "get", boom)
    assert agent._probe_health("http://voice-service:5002/api/health") is False
