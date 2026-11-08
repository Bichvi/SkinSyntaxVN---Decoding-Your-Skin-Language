"""Distributed lock: ``SET key token NX PX ttl`` + Lua compare-and-delete release (never frees another owner's lock)."""

from __future__ import annotations

import logging
import uuid
from typing import Any

from . import client as _client

logger = logging.getLogger("redis_kit.lock")

_RELEASE_LUA = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end"
_EXTEND_LUA = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('pexpire', KEYS[1], ARGV[2]) else return 0 end"


class LockHeld(RuntimeError):
    """Another owner holds the lock."""


class RedisLock:
    """Context manager.

    ``with RedisLock(key, ttl_ms=30_000):`` raises ``LockHeld`` if already held. ``key`` is used
    verbatim (so existing names such as ``graph_sync:lock`` keep working).

    Redis unreachable: ``fail_open=True`` (default) runs the body unlocked (``lock.acquired`` is False,
    ``lock.degraded`` True) - matches the old graph_sync behaviour; ``fail_open=False`` raises ``LockHeld``.
    """

    def __init__(self, key: str, ttl_ms: int = 30_000, *, client: Any | None = None,
                 fail_open: bool = True, token: str | None = None) -> None:
        self.key = key
        self.ttl_ms = int(ttl_ms)
        self.fail_open = fail_open
        self.token = token or uuid.uuid4().hex
        self.acquired = False
        self.degraded = False
        self._client = client

    def _redis(self) -> Any | None:
        return self._client if self._client is not None else _client.get_redis()

    def acquire(self) -> bool:
        redis_client = self._redis()
        if redis_client is None:
            return self._unavailable(None)
        try:
            self.acquired = bool(redis_client.set(self.key, self.token, nx=True, px=self.ttl_ms))
        except Exception as exc:
            _client.mark_down_if(self._client is None, exc)
            return self._unavailable(exc)
        return self.acquired

    def _unavailable(self, exc: BaseException | None) -> bool:
        self.degraded = True
        logger.warning("redis lock %s unavailable (%s); %s", self.key,
                       type(exc).__name__ if exc else "no client",
                       "continuing unlocked" if self.fail_open else "refusing")
        if not self.fail_open:
            raise LockHeld(self.key)
        return False

    def extend(self, ttl_ms: int | None = None) -> bool:
        """Push the expiry out (long jobs). False if we no longer own the lock."""

        redis_client = self._redis()
        if redis_client is None or not self.acquired:
            return False
        try:
            return bool(redis_client.eval(_EXTEND_LUA, 1, self.key, self.token, int(ttl_ms or self.ttl_ms)))
        except Exception as exc:
            logger.warning("redis lock extend failed: %s", type(exc).__name__)
            return False

    def release(self) -> bool:
        if not self.acquired:
            return False
        self.acquired = False
        redis_client = self._redis()
        if redis_client is None:
            return False
        try:
            return bool(redis_client.eval(_RELEASE_LUA, 1, self.key, self.token))
        except Exception as exc:
            logger.warning("could not release redis lock %s: %s", self.key, type(exc).__name__)
            return False

    def __enter__(self) -> "RedisLock":
        if not self.acquire() and not self.degraded:
            raise LockHeld(self.key)
        return self

    def __exit__(self, *exc_info: Any) -> None:
        self.release()
