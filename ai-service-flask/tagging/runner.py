"""Calls the project's existing LLM pool for one product with rate limiting + retry/backoff.

The LLM object is injected (``llm.invoke(messages)``), so tests never reach the network. In the real
script it comes from ``chatbot_service.llm_pool.get_llms()`` (the provider already configured for the
chatbot); no new provider is introduced.
"""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass, field
from typing import Any, Callable, Sequence

from .labeler import ParsedTags, ProductInput, Vocabulary, build_system_prompt, build_user_message, parse_llm_output

log = logging.getLogger("tagging.runner")


class RateLimiter:
    """Blocking minimum-interval limiter: at most ``rpm`` calls per minute (0 = unlimited)."""

    def __init__(self, rpm: float, clock: Callable[[], float] = time.monotonic,
                 sleep: Callable[[float], None] = time.sleep) -> None:
        self.interval = 60.0 / rpm if rpm and rpm > 0 else 0.0
        self._clock, self._sleep = clock, sleep
        self._next = 0.0

    def wait(self) -> None:
        if self.interval <= 0:
            return
        now = self._clock()
        if now < self._next:
            self._sleep(self._next - now)
            now = self._next
        self._next = now + self.interval


@dataclass
class LabelResult:
    parsed: ParsedTags
    model: str = ""
    usage: dict[str, int] = field(default_factory=dict)
    attempts: int = 0
    raw: str = ""


def extract_usage(response: Any) -> dict[str, int]:
    """Token counts when the provider reports them (LangChain ``usage_metadata``)."""

    meta = getattr(response, "usage_metadata", None)
    if not isinstance(meta, dict):
        meta = (getattr(response, "response_metadata", None) or {}).get("token_usage") or {}
    mapping = {
        "input_tokens": meta.get("input_tokens", meta.get("prompt_tokens")),
        "output_tokens": meta.get("output_tokens", meta.get("completion_tokens")),
        "total_tokens": meta.get("total_tokens"),
    }
    return {k: int(v) for k, v in mapping.items() if isinstance(v, (int, float))}


def model_name(llm: Any, response: Any = None) -> str:
    meta = getattr(response, "response_metadata", None) or {}
    return str(meta.get("model_name") or meta.get("model") or getattr(llm, "model_name", None)
               or getattr(llm, "model", None) or "unknown")


class Labeler:
    def __init__(
        self,
        llm: Any,
        vocab: Vocabulary,
        *,
        hints: Any = None,
        rpm: float = 30,
        max_attempts: int = 3,
        base_backoff: float = 2.0,
        sleep: Callable[[float], None] = time.sleep,
        limiter: RateLimiter | None = None,
    ) -> None:
        self.llm = llm
        self.vocab = vocab
        self.system = build_system_prompt(vocab, hints)
        self.max_attempts = max(1, int(max_attempts))
        self.base_backoff = base_backoff
        self._sleep = sleep
        self.limiter = limiter or RateLimiter(rpm, sleep=sleep)

    def _messages(self, product: ProductInput) -> Sequence[tuple[str, str]]:
        return [("system", self.system), ("human", build_user_message(product))]

    def label(self, product: ProductInput) -> LabelResult:
        """One product -> validated tags. LLM errors and unparseable replies are retried with backoff."""

        last = ParsedTags(error="chưa gọi LLM")
        result = LabelResult(parsed=last)
        usage_total: dict[str, int] = {}
        for attempt in range(1, self.max_attempts + 1):
            self.limiter.wait()
            result.attempts = attempt
            try:
                response = self.llm.invoke(self._messages(product))
            except Exception as exc:     # network/quota/timeouts: type only, never the message (may echo keys)
                last = ParsedTags(error=f"LLM lỗi: {type(exc).__name__}")
                log.warning("LLM call failed for %s (attempt %d/%d): %s", product.ma_san_pham, attempt,
                            self.max_attempts, type(exc).__name__)
                result.parsed = last
                if attempt < self.max_attempts:
                    self._sleep(self.base_backoff * (2 ** (attempt - 1)))
                continue
            text = getattr(response, "content", response)
            result.raw = text if isinstance(text, str) else str(text)
            result.model = model_name(self.llm, response)
            for key, value in extract_usage(response).items():
                usage_total[key] = usage_total.get(key, 0) + value
            result.usage = dict(usage_total)
            parsed = parse_llm_output(result.raw, self.vocab)
            result.parsed = parsed
            if parsed.ok:
                return result
            log.warning("unparseable LLM output for %s (attempt %d/%d): %s", product.ma_san_pham, attempt,
                        self.max_attempts, parsed.error)
            if attempt < self.max_attempts:
                self._sleep(self.base_backoff * (2 ** (attempt - 1)))
        return result
