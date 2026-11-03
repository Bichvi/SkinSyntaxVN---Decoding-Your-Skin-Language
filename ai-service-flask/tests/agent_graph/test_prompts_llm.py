import os
import sys

import pytest

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)


TOOLS = {
    "search_products",
    "get_product",
    "compare_products",
    "check_conflicts",
    "view_cart",
    "add_to_cart",
    "remove_from_cart",
    "get_checkout_defaults",
    "prepare_order_draft",
    "reorder_from_order",
    "get_order_status",
}


def test_prompt_version_set():
    from agent_graph.prompts import PROMPT_VERSION

    assert PROMPT_VERSION == "2026-10-27.1"


def test_agent_prompt_has_no_create_order_promise():
    from agent_graph.prompts import AGENT_PROMPT

    assert "Bạn KHÔNG thể tạo đơn hàng" in AGENT_PROMPT


def test_render_rejects_missing_key():
    from agent_graph.prompts import render

    with pytest.raises(ValueError):
        render("hello {missing}")


def test_tool_docs_cover_all_tools():
    from agent_graph.prompts import TOOL_DOCS

    assert set(TOOL_DOCS) == TOOLS


def test_agent_llm_none_without_keys(monkeypatch):
    for key in ("OPENAI_API_KEY", "GOOGLE_API_KEY", "GOOGLE_API_KEYS", "GROQ_API_KEY"):
        monkeypatch.delenv(key, raising=False)
    from agent_graph import llm

    assert llm.build_router_llms() == []
    assert llm.build_agent_llms() == []
    assert llm.with_tools([], []) is None
    assert llm.with_schema([], dict) is None


def test_provider_order_and_no_openrouter(monkeypatch):
    monkeypatch.setenv("GROQ_API_KEY", "gsk-test")
    monkeypatch.setenv("OPENAI_API_KEY", "sk-test")
    monkeypatch.setenv("GOOGLE_API_KEY", "AIza-test")
    for key in ("GOOGLE_API_KEYS", "GEMINI_CHAT_MODEL", "GEMINI_AGENT_MODEL", "OPENROUTER_API_KEY"):
        monkeypatch.delenv(key, raising=False)
    from agent_graph import llm

    router = llm.build_router_llms()
    agent = llm.build_agent_llms()
    assert [getattr(model, "model_name", getattr(model, "model", "")) for model in router] == [
        "openai/gpt-oss-20b",
        "gemini-2.5-flash",
        "gpt-4o-mini",
    ]
    assert [getattr(model, "model_name", getattr(model, "model", "")) for model in agent] == [
        "openai/gpt-oss-120b",
        "gemini-2.5-flash",
        "gpt-4o-mini",
    ]
    assert all("openrouter" not in repr(model).lower() for model in router + agent)


class _FakeChatModel:
    def __init__(self, result):
        self.result = result

    def bind_tools(self, tools):
        return _FakeRunnable(self.result)

    def with_structured_output(self, schema):
        return _FakeRunnable(self.result)


class _FakeRunnable:
    def __init__(self, result):
        self.result = result

    def with_fallbacks(self, fallbacks):
        return _FakeFallbackRunnable(self, fallbacks)

    def invoke(self, value):
        if isinstance(self.result, Exception):
            raise self.result
        return self.result


class _FakeFallbackRunnable:
    def __init__(self, first, fallbacks):
        self.first = first
        self.fallbacks = fallbacks

    def invoke(self, value):
        try:
            return self.first.invoke(value)
        except Exception:
            return self.fallbacks[0].invoke(value)


def test_with_tools_and_schema_fallback_without_network():
    from agent_graph import llm

    models = [_FakeChatModel(RuntimeError("first failed")), _FakeChatModel("second")]
    assert llm.with_tools(models, []).invoke("input") == "second"
    assert llm.with_schema(models, dict).invoke("input") == "second"


def test_agent_gemini_ignores_slow_legacy_chat_model_and_disables_thinking(monkeypatch):
    """Live: GEMINI_CHAT_MODEL=gemini-3.5-flash-lite cost 8-20 s per fallback round."""
    monkeypatch.setenv("GOOGLE_API_KEY", "AIza-test")
    monkeypatch.setenv("GEMINI_CHAT_MODEL", "gemini-3.5-flash-lite")
    monkeypatch.delenv("GEMINI_AGENT_MODEL", raising=False)
    monkeypatch.delenv("GROQ_API_KEY", raising=False)
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    from agent_graph import llm

    (model,) = llm.build_agent_llms()
    assert model.model == "gemini-2.5-flash"
    assert model.thinking_budget == 0


def test_provider_failure_is_logged_by_exception_type_only(caplog):
    from agent_graph import llm

    handler = llm._FallbackLogger("openai/gpt-oss-120b")
    with caplog.at_level("WARNING", logger="agent_graph.llm"):
        handler.on_llm_error(RuntimeError("Rate limit gsk_secret-token org_123"))
    assert "cause=RuntimeError" in caplog.text
    assert "gsk_secret" not in caplog.text
