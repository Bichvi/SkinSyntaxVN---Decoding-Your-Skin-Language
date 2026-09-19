"""Bounded process-local response cache with optional Redis backing."""

from __future__ import annotations

import hashlib
import json
import logging
import time
from collections import OrderedDict
from threading import Lock
from typing import Any

from . import config

logger = logging.getLogger(__name__)


class ResponseCache:
    """Cache only deterministic response data; user identifiers are hashed."""

    def __init__(self, ttl_seconds: int = config.RESPONSE_CACHE_TTL, max_items: int = 256) -> None:
        self.ttl_seconds = max(0, ttl_seconds)
        self.max_items = max(1, max_items)
        self._items: OrderedDict[str, tuple[float, dict[str, Any]]] = OrderedDict()
        self._lock = Lock()

    @staticmethod
    def key(payload: dict[str, Any]) -> str:
        canonical = json.dumps(payload, ensure_ascii=False, sort_keys=True, default=str)
        return "recommendation:v1:" + hashlib.sha256(canonical.encode("utf-8")).hexdigest()

    def get(self, key: str) -> dict[str, Any] | None:
        """Read local/Redis cache, returning ``None`` on all cache failures."""

        if self.ttl_seconds <= 0:
            return None
        try:
            with self._lock:
                entry = self._items.get(key)
                if entry and entry[0] > time.monotonic():
                    self._items.move_to_end(key)
                    return dict(entry[1])
                self._items.pop(key, None)
            from shared.redis_store import get_redis

            client = get_redis()
            raw = client.get(key) if client else None
            return json.loads(raw) if raw else None
        except Exception as exc:
            logger.debug("Recommendation cache read skipped: %s", type(exc).__name__)
            return None

    def set(self, key: str, value: dict[str, Any]) -> None:
        """Write local cache and best-effort Redis cache."""

        if self.ttl_seconds <= 0:
            return
        try:
            with self._lock:
                self._items[key] = (time.monotonic() + self.ttl_seconds, dict(value))
                self._items.move_to_end(key)
                while len(self._items) > self.max_items:
                    self._items.popitem(last=False)
            from shared.redis_store import get_redis

            client = get_redis()
            if client:
                client.setex(key, self.ttl_seconds, json.dumps(value, ensure_ascii=False))
        except Exception as exc:
            logger.debug("Recommendation cache write skipped: %s", type(exc).__name__)
