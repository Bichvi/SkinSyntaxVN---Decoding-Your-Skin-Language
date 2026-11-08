"""Bước 08c: ngân sách thời gian cho _store_support_generate (LLM giả, không gọi mạng)."""
import sys
import time
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from chatbot_service import chatbot_flask as cf  # noqa: E402


class FakeLLM:
    def __init__(self, name, sleep=0.0, raises=None, text="xin chào"):
        self.model = name
        self.sleep, self.raises, self.text = sleep, raises, text

    def invoke(self, messages):
        if self.sleep:
            time.sleep(self.sleep)
        if self.raises:
            raise self.raises
        return SimpleNamespace(content=self.text)


def _no_cooldown(monkeypatch):
    monkeypatch.setattr(cf, "_get_llm_cooldown", lambda llm: 0)
    monkeypatch.setattr(cf, "_set_llm_cooldown", lambda llm, s: None)


def test_hanging_llm_returns_none_within_budget(monkeypatch):
    _no_cooldown(monkeypatch)
    monkeypatch.setenv("STORE_SUPPORT_LLM_BUDGET_S", "0.5")
    t = time.time()
    assert cf._store_support_generate("hỏi", [FakeLLM("a", sleep=5), FakeLLM("b", sleep=5)]) is None
    assert time.time() - t < 1.5


def test_fast_connect_error_falls_to_next(monkeypatch):
    _no_cooldown(monkeypatch)
    monkeypatch.setenv("STORE_SUPPORT_LLM_BUDGET_S", "5")
    t = time.time()
    out = cf._store_support_generate("hỏi", [FakeLLM("a", raises=ConnectionError("x")), FakeLLM("b", text="ok b")])
    assert out == "ok b"
    assert time.time() - t < 1.0


def test_normal_llm_unchanged(monkeypatch):
    _no_cooldown(monkeypatch)
    assert cf._store_support_generate("hỏi", [FakeLLM("a", text="<think>x</think>trả lời")]) == "trả lời"
