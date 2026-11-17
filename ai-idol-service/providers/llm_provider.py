import json
import re

try:
    from groq import Groq
except ImportError:  # Keep local unit tests and deterministic fallbacks importable.
    Groq = None

from config import (
    GROQ_API_KEY,
    GROQ_FALLBACK_MODELS,
    GROQ_MAX_COMPLETION_TOKENS,
    GROQ_MAX_PROMPT_CHARS,
    GROQ_MODEL,
    GROQ_RETRY_PROMPT_CHARS,
    logger,
)
from core.text_budget import fit_message_pair


DEFAULT_RATE_LIMIT_WAIT_SECONDS = 300.0

_WAIT_RE = re.compile(r"try again in\s+((?:\d+(?:\.\d+)?\s*(?:ms|h|m|s)\s*)+)", re.IGNORECASE)
_WAIT_PART_RE = re.compile(r"(\d+(?:\.\d+)?)\s*(ms|h|m|s)", re.IGNORECASE)
_UNIT_SECONDS = {"h": 3600.0, "m": 60.0, "s": 1.0, "ms": 0.001}


class LLMRateLimited(RuntimeError):
    """Every Groq model answered 429: transient quota exhaustion, not a failure."""

    def __init__(self, message: str, retry_after_seconds: float = DEFAULT_RATE_LIMIT_WAIT_SECONDS):
        super().__init__(message)
        self.retry_after_seconds = float(retry_after_seconds)


def parse_retry_after(text) -> float | None:
    """Seconds from 'try again in 7m5.08s' / '1h2m3s' / '45.5s' / '900ms'; None when absent."""
    match = _WAIT_RE.search(str(text or ""))
    if not match:
        return None
    parts = _WAIT_PART_RE.findall(match.group(1))
    if not parts:
        return None
    return sum(float(value) * _UNIT_SECONDS[unit.lower()] for value, unit in parts)


class LLMProvider:
    """Minimal Groq provider with no paid fallback or LangChain runtime overhead."""

    def __init__(self):
        self.pool = []
        self.models = [GROQ_MODEL, *GROQ_FALLBACK_MODELS]
        if GROQ_API_KEY and Groq is not None:
            self.pool.append(Groq(api_key=GROQ_API_KEY))
        elif GROQ_API_KEY:
            logger.warning("[LLM-PROVIDER] groq package is not installed")
        else:
            logger.warning("[LLM-PROVIDER] GROQ_API_KEY is not configured")

    def invoke(self, system_prompt: str, user_prompt: str) -> str:
        errors = []
        limited = []  # one bool per recorded error: was it a rate limit?
        waits = []
        for client in self.pool:
            for model in self.models:
                limits = [GROQ_MAX_PROMPT_CHARS]
                compact_system, compact_user = fit_message_pair(
                    system_prompt, user_prompt, GROQ_MAX_PROMPT_CHARS
                )
                try:
                    return self._create(client, model, compact_system, compact_user)
                except Exception as exc:
                    errors.append(f"{model}: {exc}")
                    self._note_rate_limit(exc, limited, waits)
                    logger.warning("[LLM-PROVIDER] Groq model %s failed: %s", model, exc)

                    # Groq returns HTTP 413 when a free-tier TPM request is too large.
                    # Retry once with a much smaller prompt before moving to the fallback model.
                    if self._is_prompt_too_large(exc) and GROQ_RETRY_PROMPT_CHARS < limits[0]:
                        retry_system, retry_user = fit_message_pair(
                            system_prompt, user_prompt, GROQ_RETRY_PROMPT_CHARS
                        )
                        try:
                            logger.info(
                                "[LLM-PROVIDER] Retrying %s with compact prompt (%s chars)",
                                model,
                                len(retry_system) + len(retry_user),
                            )
                            return self._create(client, model, retry_system, retry_user)
                        except Exception as retry_exc:
                            errors.append(f"{model} compact: {retry_exc}")
                            self._note_rate_limit(retry_exc, limited, waits)
                            logger.warning(
                                "[LLM-PROVIDER] Compact retry for %s failed: %s",
                                model,
                                retry_exc,
                            )
        detail = "; ".join(errors[-2:]) if errors else "GROQ_API_KEY is missing"
        self._raise_unavailable("Groq is unavailable: " + detail, errors, limited, waits)

    def invoke_json_schema(
        self,
        system_prompt: str,
        user_prompt: str,
        *,
        schema: dict,
        schema_name: str,
        max_completion_tokens: int | None = None,
    ) -> dict:
        """Request strict JSON while retaining the same free-tier fallback behavior."""

        errors = []
        limited = []
        waits = []
        for client in self.pool:
            for model in self.models:
                compact_system, compact_user = fit_message_pair(
                    system_prompt, user_prompt, GROQ_MAX_PROMPT_CHARS
                )
                for prompt_limit in (GROQ_MAX_PROMPT_CHARS, GROQ_RETRY_PROMPT_CHARS):
                    if prompt_limit != GROQ_MAX_PROMPT_CHARS:
                        compact_system, compact_user = fit_message_pair(
                            system_prompt, user_prompt, prompt_limit
                        )
                    try:
                        raw = self._create_json_schema(
                            client,
                            model,
                            compact_system,
                            compact_user,
                            schema,
                            schema_name,
                            max_completion_tokens,
                        )
                        parsed = json.loads(raw)
                        if not isinstance(parsed, dict):
                            raise ValueError("Structured response must be an object")
                        return parsed
                    except Exception as exc:
                        errors.append(f"{model}: {exc}")
                        self._note_rate_limit(exc, limited, waits)
                        logger.warning("[LLM-PROVIDER] Structured call on %s failed: %s", model, exc)
                        if prompt_limit == GROQ_MAX_PROMPT_CHARS and self._is_prompt_too_large(exc):
                            continue
                        break
        detail = "; ".join(errors[-2:]) if errors else "GROQ_API_KEY is missing"
        self._raise_unavailable("Groq structured output is unavailable: " + detail, errors, limited, waits)

    @staticmethod
    def _is_rate_limited(exc: Exception) -> bool:
        if type(exc).__name__ == "RateLimitError" or getattr(exc, "status_code", None) == 429:
            return True
        detail = str(exc).lower()
        return "429" in detail and ("rate limit" in detail or "rate_limit_exceeded" in detail)

    @staticmethod
    def _note_rate_limit(exc: Exception, limited: list, waits: list) -> None:
        is_limited = LLMProvider._is_rate_limited(exc)
        limited.append(is_limited)
        if is_limited:
            wait = parse_retry_after(exc)
            if wait is not None:
                waits.append(wait)

    @staticmethod
    def _raise_unavailable(message: str, errors: list, limited: list, waits: list):
        if errors and limited and all(limited):
            raise LLMRateLimited(message, min(waits) if waits else DEFAULT_RATE_LIMIT_WAIT_SECONDS)
        raise RuntimeError(message)

    @staticmethod
    def _is_prompt_too_large(exc: Exception) -> bool:
        detail = str(exc).lower()
        return (
            "request too large" in detail
            or "tokens per minute" in detail
            or ("413" in detail and "token" in detail)
        )

    @staticmethod
    def _create(client, model: str, system_prompt: str, user_prompt: str) -> str:
        request_options = {
            "model": model,
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
            "temperature": 0.6,
            "max_completion_tokens": GROQ_MAX_COMPLETION_TOKENS,
        }
        if model.startswith("openai/gpt-oss"):
            # GPT-OSS defaults to medium reasoning. On a short marketing task that can
            # consume the whole completion budget before producing the final answer.
            request_options.update({"reasoning_effort": "low", "include_reasoning": False})
        elif model.startswith("qwen/qwen3"):
            # The fallback is for continuity, so disable unneeded reasoning tokens.
            request_options["reasoning_effort"] = "none"

        response = client.chat.completions.create(**request_options)
        content = (response.choices[0].message.content or "").strip()
        if not content:
            raise RuntimeError("Groq returned an empty response")
        return content

    @staticmethod
    def _create_json_schema(
        client,
        model: str,
        system_prompt: str,
        user_prompt: str,
        schema: dict,
        schema_name: str,
        max_completion_tokens: int | None = None,
    ) -> str:
        request_options = {
            "model": model,
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
            "temperature": 0.1,
            "max_completion_tokens": (
                max_completion_tokens if max_completion_tokens else min(GROQ_MAX_COMPLETION_TOKENS, 700)
            ),
            "response_format": {
                "type": "json_schema",
                "json_schema": {
                    "name": schema_name,
                    "strict": True,
                    "schema": schema,
                },
            },
        }
        if model.startswith("openai/gpt-oss"):
            request_options.update({"reasoning_effort": "low", "include_reasoning": False})
        elif model.startswith("qwen/qwen3"):
            request_options["reasoning_effort"] = "none"
        response = client.chat.completions.create(**request_options)
        content = (response.choices[0].message.content or "").strip()
        if not content:
            raise RuntimeError("Groq returned an empty structured response")
        return content
