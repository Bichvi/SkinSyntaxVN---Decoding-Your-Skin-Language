"""Shared Redis client factory (U03).

``get_redis()`` returns a process-wide client or ``None`` when Redis is unreachable. Callers must
degrade softly on ``None`` (no cache / fail-open limiter / direct DB write) - never raise to users.

Unlike ``shared.redis_store.get_redis`` (which gives up forever after one failed attempt), a failed
attempt here is retried after ``RETRY_AFTER_FAIL_S`` seconds, so a Redis restart heals by itself.
Command errors after a successful connect should call ``mark_down()`` so the next calls fail fast.
"""

from __future__ import annotations

import logging
import os
import threading
import time
from typing import Any

logger = logging.getLogger("redis_kit")

DEFAULT_URL = "redis://127.0.0.1:6379/0"
RETRY_AFTER_FAIL_S = 5.0

_client: Any | None = None
_down_until = 0.0
_lock = threading.Lock()
_last_warn = 0.0


def redis_url() -> str:
    return (os.getenv("REDIS_URL") or "").strip() or DEFAULT_URL


def _float_env(name: str, default: float) -> float:
    try:
        return float(os.getenv(name, str(default)))
    except ValueError:
        return default


def make_client(url: str | None = None, *, socket_timeout: float | None = None,
                connect_timeout: float | None = None, health_check_interval: int = 30) -> Any:
    """Build a new (lazy, unconnected) client. Strings are decoded (``decode_responses=True``).

    Use a larger ``socket_timeout`` than the BLOCK time for blocking calls (XREADGROUP, pub/sub).
    """

    import redis

    return redis.Redis.from_url(
        url or redis_url(),
        socket_connect_timeout=connect_timeout if connect_timeout is not None else _float_env("REDIS_KIT_CONNECT_TIMEOUT", 0.2),
        socket_timeout=socket_timeout if socket_timeout is not None else _float_env("REDIS_KIT_TIMEOUT", 0.2),
        health_check_interval=health_check_interval,
        decode_responses=True,
    )


def _warn_throttled(message: str, *args: Any) -> None:
    global _last_warn
    now = time.monotonic()
    if now - _last_warn >= 30.0:
        _last_warn = now
        logger.warning(message, *args)


def get_redis() -> Any | None:
    """Singleton client (``REDIS_URL``, ``socket_timeout`` 0.2s) or ``None`` while Redis is down."""

    global _client, _down_until
    client = _client
    if client is not None:
        return client
    if time.monotonic() < _down_until:
        return None
    with _lock:
        if _client is not None:
            return _client
        if time.monotonic() < _down_until:
            return None
        try:
            candidate = make_client()
            candidate.ping()
            _client = candidate
        except Exception as exc:
            _down_until = time.monotonic() + RETRY_AFTER_FAIL_S
            _warn_throttled("redis unavailable (%s); degrading softly, retry in %ss", type(exc).__name__, RETRY_AFTER_FAIL_S)
            return None
        return _client


def mark_down(exc: BaseException | None = None) -> None:
    """Drop the singleton after a command failure: calls return ``None`` for ``RETRY_AFTER_FAIL_S``."""

    global _client, _down_until
    with _lock:
        _client = None
        _down_until = time.monotonic() + RETRY_AFTER_FAIL_S
    if exc is not None:
        _warn_throttled("redis command failed (%s); marked down for %ss", type(exc).__name__, RETRY_AFTER_FAIL_S)


def mark_down_if(own_client: bool, exc: BaseException | None = None) -> None:
    """``mark_down`` only when the failing client was the shared singleton (not an injected one)."""

    if own_client:
        mark_down(exc)


def reset() -> None:
    """Forget singleton and back-off state (tests, forked workers)."""

    global _client, _down_until
    with _lock:
        _client = None
        _down_until = 0.0
