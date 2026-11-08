"""Sliding-window rate limiter on a sorted set (``ZREMRANGEBYSCORE`` + ``ZADD`` + ``ZCARD`` in MULTI).

Fail-open: any Redis problem lets the request through (the limiter protects, it must not become an outage).
"""

from __future__ import annotations

import logging
import math
import time
import uuid
from typing import Any

from . import client as _client

logger = logging.getLogger("redis_kit.ratelimit")


def allow(key: str, limit: int, window_s: float, *, client: Any | None = None,
          now: float | None = None) -> tuple[bool, float]:
    """Record one hit on ``key``. Returns ``(allowed, retry_after_seconds)``.

    At most ``limit`` hits are allowed in any trailing ``window_s`` seconds. A rejected hit is NOT
    counted (it is removed again), so a client that backs off recovers after ``retry_after``.
    ``limit <= 0`` disables the limiter.
    """

    if limit <= 0 or window_s <= 0:
        return True, 0.0
    redis_client = client if client is not None else _client.get_redis()
    if redis_client is None:
        return True, 0.0
    ts = time.time() if now is None else float(now)
    member = f"{int(ts * 1000)}-{uuid.uuid4().hex[:10]}"
    try:
        pipe = redis_client.pipeline(transaction=True)
        pipe.zremrangebyscore(key, "-inf", ts - window_s)
        pipe.zadd(key, {member: ts})
        pipe.zcard(key)
        pipe.zrange(key, 0, 0, withscores=True)
        pipe.pexpire(key, int(window_s * 1000) + 1000)
        _, _, count, oldest, _ = pipe.execute()
        if int(count) <= limit:
            return True, 0.0
        redis_client.zrem(key, member)
        oldest_ts = float(oldest[0][1]) if oldest else ts
        return False, max(0.0, oldest_ts + window_s - ts)
    except Exception as exc:  # fail-open
        _client.mark_down_if(client is None, exc)
        logger.warning("ratelimit fail-open: %s", type(exc).__name__)
        return True, 0.0


def retry_after_header(retry_after: float) -> str:
    """Integer seconds for the ``Retry-After`` header (at least 1)."""

    return str(max(1, int(math.ceil(retry_after))))
