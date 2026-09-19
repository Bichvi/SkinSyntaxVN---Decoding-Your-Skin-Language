"""Graceful Redis singleton used by response caches."""

from __future__ import annotations

import logging
import os
from threading import Lock
from typing import Any

logger = logging.getLogger(__name__)
_redis: Any | None = None
_redis_attempted = False
_redis_lock = Lock()


def get_redis() -> Any | None:
    """Return a Redis client or ``None`` when Redis is unavailable."""

    global _redis, _redis_attempted
    if _redis_attempted:
        return _redis
    with _redis_lock:
        if _redis_attempted:
            return _redis
        _redis_attempted = True
        try:
            import redis

            client = redis.Redis.from_url(
                os.getenv("REDIS_URL", "redis://127.0.0.1:6379/0"),
                socket_connect_timeout=float(os.getenv("REDIS_CONNECT_TIMEOUT", "0.2")),
                socket_timeout=float(os.getenv("REDIS_TIMEOUT", "0.4")),
                decode_responses=True,
            )
            client.ping()
            _redis = client
        except Exception as exc:  # pragma: no cover - environment dependent
            logger.info("Redis unavailable; using in-process cache: %s", type(exc).__name__)
            _redis = None
        return _redis


def reset_redis_singleton() -> None:
    """Reset the Redis factory for tests or worker restarts."""

    global _redis, _redis_attempted
    with _redis_lock:
        _redis = None
        _redis_attempted = False
