"""Provider order: free providers (Groq, Gemini) first, paid OpenAI only as the last fallback."""
import os
import sys

import pytest

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
for _p in (_ROOT, os.path.join(_ROOT, "chatbot_service")):
    if _p not in sys.path:
        sys.path.insert(0, _p)


def _name(llm):
    return str(getattr(llm, "model_name", getattr(llm, "model", "")))


def test_llm_pool_order_groq_gemini_openai_last(monkeypatch):
    pytest.importorskip("langchain_openai")
    pytest.importorskip("langchain_google_genai")
    import llm_pool

    monkeypatch.setenv("GROQ_API_KEY", "gsk-test")
    monkeypatch.setenv("GOOGLE_API_KEY", "AIza-test")
    monkeypatch.setenv("OPENAI_API_KEY", "sk-test")
    built = llm_pool._build_llms()
    assert len(built) == 3
    assert _name(built[0]).startswith("openai/gpt-oss")
    assert "gemini" in _name(built[1]).lower()
    assert _name(built[-1]).startswith("gpt-")


def test_llm_pool_only_openai_key(monkeypatch):
    pytest.importorskip("langchain_openai")
    import llm_pool

    for k in ("GROQ_API_KEY", "GOOGLE_API_KEY", "GOOGLE_API_KEYS"):
        monkeypatch.delenv(k, raising=False)
    monkeypatch.setenv("OPENAI_API_KEY", "sk-test")
    built = llm_pool._build_llms()
    assert len(built) == 1
    assert _name(built[0]).startswith("gpt-")


def _service(monkeypatch, calls, gemini_fails=False):
    from services.llamaindex_recommend_service import LlamaIndexRecommendService

    svc = object.__new__(LlamaIndexRecommendService)
    monkeypatch.setattr(svc, "_build_answer_prompt", lambda *a, **k: "prompt", raising=False)

    def gemini(prompt):
        calls.append("gemini")
        if gemini_fails:
            raise RuntimeError("gemini down")
        return "from-gemini"

    def openai(prompt):
        calls.append("openai")
        return "from-openai"

    monkeypatch.setattr(svc, "_gemini_answer", gemini, raising=False)
    monkeypatch.setattr(svc, "_openai_answer", openai, raising=False)
    return svc


def test_recommend_tries_gemini_first_by_default(monkeypatch):
    pytest.importorskip("recommendation.config")
    monkeypatch.delenv("RECOMMENDATION_LLM_PROVIDER", raising=False)
    calls = []
    svc = _service(monkeypatch, calls)
    assert svc._llm_answer({}, "q", []) == "from-gemini"
    assert calls == ["gemini"]


def test_recommend_falls_back_to_openai_when_gemini_fails(monkeypatch):
    pytest.importorskip("recommendation.config")
    monkeypatch.delenv("RECOMMENDATION_LLM_PROVIDER", raising=False)
    calls = []
    svc = _service(monkeypatch, calls, gemini_fails=True)
    assert svc._llm_answer({}, "q", []) == "from-openai"
    assert calls == ["gemini", "openai"]


def test_recommend_openai_first_opt_in(monkeypatch):
    pytest.importorskip("recommendation.config")
    monkeypatch.setenv("RECOMMENDATION_LLM_PROVIDER", "openai_first")
    calls = []
    svc = _service(monkeypatch, calls)
    assert svc._llm_answer({}, "q", []) == "from-openai"
    assert calls == ["openai"]
