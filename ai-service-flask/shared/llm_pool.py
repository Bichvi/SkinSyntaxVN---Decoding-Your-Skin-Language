"""Small shared LLM factory; unavailable providers degrade to deterministic text."""

from __future__ import annotations

import logging
import os
from threading import Lock
from typing import Any

logger = logging.getLogger(__name__)
_llm: Any | None = None
_llm_attempted = False
_llm_lock = Lock()


def get_llm() -> Any | None:
    """Create at most one optional LLM client in this Python process."""

    global _llm, _llm_attempted
    if _llm_attempted:
        return _llm
    with _llm_lock:
        if _llm_attempted:
            return _llm
        _llm_attempted = True
        key = os.getenv("OPENAI_API_KEY", "").strip()
        if not key:
            return None
        try:
            from langchain_openai import ChatOpenAI

            _llm = ChatOpenAI(
                api_key=key,
                model=os.getenv("OPENAI_CHAT_MODEL", "gpt-4o-mini"),
                temperature=0,
                max_retries=1,
                timeout=float(os.getenv("RECOMMENDATION_LLM_TIMEOUT", "8")),
            )
        except Exception as exc:  # pragma: no cover - depends on optional runtime
            logger.warning("Shared LLM unavailable: %s", type(exc).__name__)
        return _llm


def reset_llm_singleton() -> None:
    """Reset the optional LLM factory for tests."""

    global _llm, _llm_attempted
    with _llm_lock:
        _llm = None
        _llm_attempted = False
