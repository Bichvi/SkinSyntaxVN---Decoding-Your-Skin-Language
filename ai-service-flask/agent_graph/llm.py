"""Non-networking LLM construction for the agent prompt chains."""

from __future__ import annotations

import logging
import os
from typing import Any

from langchain_core.callbacks import BaseCallbackHandler
from langchain_core.language_models import BaseChatModel
from langchain_core.runnables import Runnable


logger = logging.getLogger(__name__)

# Per-request ceilings so a stalled provider hands over to the next one instead of burning the turn budget.
GROQ_TIMEOUT_S = 12
GEMINI_TIMEOUT_S = 20
DEFAULT_GEMINI_MODEL = "gemini-2.5-flash"


class _FallbackLogger(BaseCallbackHandler):
    """Log why a provider in the chain failed (exception type and model only, never the message)."""

    def __init__(self, model: str) -> None:
        self.model = model

    def on_llm_error(self, error: BaseException, **kwargs: Any) -> None:
        logger.warning("agent llm provider failed model=%s cause=%s", self.model, type(error).__name__)


def _api_key(name: str) -> str:
    return os.getenv(name, "").strip()


def _google_api_key() -> str:
    return _api_key("GOOGLE_API_KEY") or next(
        (key.strip() for key in os.getenv("GOOGLE_API_KEYS", "").split(",") if key.strip()),
        "",
    )


def _openai_model(api_key: str, model: str, **kwargs: Any) -> BaseChatModel | None:
    try:
        from langchain_openai import ChatOpenAI

        return ChatOpenAI(
            openai_api_key=api_key,
            model_name=model,
            temperature=0,
            max_retries=0,
            callbacks=[_FallbackLogger(model)],
            **kwargs,
        )
    except Exception:
        return None


def _build_openai(api_key: str) -> BaseChatModel | None:
    return _openai_model(
        api_key,
        os.getenv("OPENAI_CHAT_MODEL", "gpt-4o-mini").strip() or "gpt-4o-mini",
    )


def _build_groq(api_key: str, model: str, *, reasoning_effort: str | None = None) -> BaseChatModel | None:
    kwargs: dict[str, Any] = {
        "openai_api_base": "https://api.groq.com/openai/v1",
        "max_tokens": 4096,
        "request_timeout": GROQ_TIMEOUT_S,
    }
    if reasoning_effort is not None:
        kwargs["reasoning_effort"] = reasoning_effort
    return _openai_model(api_key, model, **kwargs)


def _gemini_model_name() -> str:
    # GEMINI_CHAT_MODEL belongs to the legacy chatbot (it may point at a slow "thinking" preview model whose
    # thinking cannot be switched off: 8-20 s per tool round). The agent chain has its own, fast default.
    return os.getenv("GEMINI_AGENT_MODEL", "").strip() or DEFAULT_GEMINI_MODEL


def _build_gemini(api_key: str) -> BaseChatModel | None:
    try:
        from langchain_google_genai import ChatGoogleGenerativeAI

        model = _gemini_model_name()
        kwargs: dict[str, Any] = {}
        if model.startswith("gemini-2.5"):
            kwargs["thinking_budget"] = 0  # no hidden reasoning tokens: ~2 s per round instead of ~10+ s
        elif model.startswith("gemini-3"):
            kwargs["thinking_level"] = "minimal"
        return ChatGoogleGenerativeAI(
            model=model,
            temperature=0,
            max_tokens=4096,
            max_retries=0,
            timeout=GEMINI_TIMEOUT_S,
            callbacks=[_FallbackLogger(model)],
            google_api_key=api_key,
            **kwargs,
        )
    except Exception:
        return None


def build_router_llms() -> list[BaseChatModel]:
    """Build the router provider chain in its required priority order."""
    providers: list[BaseChatModel] = []
    groq_key = _api_key("GROQ_API_KEY")
    if groq_key:
        model = os.getenv("GROQ_ROUTER_MODEL", "openai/gpt-oss-20b").strip() or "openai/gpt-oss-20b"
        built = _build_groq(groq_key, model, reasoning_effort="low")
        if built is not None:
            providers.append(built)

    google_key = _google_api_key()
    if google_key:
        built = _build_gemini(google_key)
        if built is not None:
            providers.append(built)

    openai_key = _api_key("OPENAI_API_KEY")
    if openai_key:
        built = _build_openai(openai_key)
        if built is not None:
            providers.append(built)
    return providers


def build_agent_llms() -> list[BaseChatModel]:
    """Build the agent provider chain in its required priority order."""
    providers: list[BaseChatModel] = []
    groq_key = _api_key("GROQ_API_KEY")
    if groq_key:
        built = _build_groq(groq_key, "openai/gpt-oss-120b")
        if built is not None:
            providers.append(built)

    google_key = _google_api_key()
    if google_key:
        built = _build_gemini(google_key)
        if built is not None:
            providers.append(built)

    openai_key = _api_key("OPENAI_API_KEY")
    if openai_key:
        built = _build_openai(openai_key)
        if built is not None:
            providers.append(built)
    return providers


def with_tools(llms: list[BaseChatModel], tools: list) -> Runnable | None:
    if not llms:
        return None
    bound = [llm.bind_tools(tools) for llm in llms]
    return bound[0].with_fallbacks(bound[1:])


def with_schema(llms: list[BaseChatModel], schema: type) -> Runnable | None:
    if not llms:
        return None
    structured = [llm.with_structured_output(schema) for llm in llms]
    return structured[0].with_fallbacks(structured[1:])
