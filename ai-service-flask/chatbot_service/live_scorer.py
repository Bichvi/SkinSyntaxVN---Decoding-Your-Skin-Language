# -*- coding: utf-8 -*-
from __future__ import annotations

import os
import logging
from pathlib import Path

_ENV = Path(__file__).resolve().parent.parent / ".env"
if _ENV.exists():
    from dotenv import load_dotenv
    load_dotenv(_ENV, override=True)

logger = logging.getLogger(__name__)

_TRULENS_OK = False
try:
    from trulens.providers.litellm import LiteLLM
    _TRULENS_OK = True
except ImportError:
    logger.warning("[SCORER] trulens not installed — live scoring disabled")


def _build_provider_list() -> list[tuple[str, object]]:
    if not _TRULENS_OK:
        return []

    providers: list[tuple[str, object]] = []
    openai_key = os.getenv("OPENAI_API_KEY", "").strip()
    openai_model = os.getenv("OPENAI_CHAT_MODEL", "gpt-4o-mini").strip()

    if openai_key.startswith("sk-"):
        try:
            os.environ["OPENAI_API_KEY"] = openai_key
            p = LiteLLM(model_engine=f"openai/{openai_model}")
            providers.append(("openai", p, openai_key, "openai"))
        except Exception as e:
            logger.debug("[SCORER] OpenAI init failed: %s", e)

    return providers


_PROVIDERS: list | None = None


def _get_providers():
    global _PROVIDERS
    if _PROVIDERS is None:
        _PROVIDERS = _build_provider_list()
        names = [p[0] for p in _PROVIDERS]
        logger.info("[SCORER] Providers ready: %s", names)
    return _PROVIDERS


def _extract(result) -> float | None:
    if isinstance(result, tuple):
        val = result[0]
        return float(val) if val is not None else None
    if hasattr(result, "score"):
        val = result.score
        return float(val) if val is not None else None
    if result is None:
        return None
    try:
        return float(result)
    except (TypeError, ValueError):
        return None


def _is_rate_limit(e: Exception) -> bool:
    msg = str(e).lower()
    return "429" in msg or "rate limit" in msg or "quota" in msg or "too many" in msg


def _is_retryable(e: Exception) -> bool:
    if _is_rate_limit(e):
        return True
    msg = str(e).lower()
    return "notfound" in msg or "404" in msg or "no endpoints" in msg


def _score_once(provider, question: str, answer: str, context: str) -> dict:
    scores: dict = {"answer_relevance": None, "groundedness": None, "context_relevance": None}

    try:
        result = provider.relevance_with_cot_reasons(question, answer)
        scores["answer_relevance"] = _extract(result)
    except Exception as e:
        if _is_retryable(e):
            raise
        logger.debug("[SCORER] answer_relevance error: %s", e)

    if context:
        try:
            result = provider.groundedness_measure_with_cot_reasons(context, answer)
            scores["groundedness"] = _extract(result)
        except Exception as e:
            if _is_retryable(e):
                raise
            logger.debug("[SCORER] groundedness error: %s", e)

        try:
            result = provider.context_relevance_with_cot_reasons(question, context)
            scores["context_relevance"] = _extract(result)
        except Exception as e:
            if _is_retryable(e):
                raise
            logger.debug("[SCORER] context_relevance error: %s", e)

    return scores


def score_response(question: str, answer: str, context: str = "") -> dict:
    out = {
        "available": False,
        "provider": "none",
        "answer_relevance": None,
        "groundedness": None,
        "context_relevance": None,
        "errors": [],
    }

    providers = _get_providers()
    if not providers:
        out["errors"].append("No TruLens provider configured")
        return out

    for name, provider, _key, _kind in providers:
        try:
            scores = _score_once(provider, question, answer, context)
            out.update(scores)
            out["available"] = True
            out["provider"]  = name
            logger.info("[SCORER] Scored with %s", name)
            return out
        except Exception as e:
            if _is_retryable(e):
                logger.warning("[SCORER] %s failed (%s), trying next...", name, type(e).__name__)
                out["errors"].append(f"{name}: {type(e).__name__}")
                continue
            out["errors"].append(f"{name}: {e}")
            continue

    out["errors"].append("All providers exhausted or failed")
    return out
