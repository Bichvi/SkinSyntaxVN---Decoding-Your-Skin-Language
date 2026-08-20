# -*- coding: utf-8 -*-
"""Shared Redis client for chat caches."""
from __future__ import annotations

import logging
import os

logger = logging.getLogger(__name__)

_client = None
_checked = False


def get_redis():
    global _client, _checked
    if _checked:
        return _client
    _checked = True
    url = (os.getenv("REDIS_URL") or "").strip()
    if not url:
        return None
    try:
        import redis
        _client = redis.from_url(url, decode_responses=True, socket_connect_timeout=2)
        _client.ping()
        logger.info("[REDIS] Connected")
    except Exception as exc:
        logger.warning("[REDIS] Unavailable: %s", exc)
        _client = None
    return _client
