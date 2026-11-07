"""Redis cache for the product catalog and vocabulary (Atlas reads are slow).

Entries are valid while the PHP change stream `catalog:changes:v1` has not moved. Any Redis problem
falls back to the loader; loader (Mongo) errors propagate and are never cached.
"""
from __future__ import annotations

import datetime
import logging
import zlib

import redis
from bson import json_util
from bson.json_util import JSONMode, JSONOptions, RELAXED_JSON_OPTIONS
from pymongo.database import Database

import config

logger = logging.getLogger(__name__)

STREAM = "catalog:changes:v1"
MAX_BYTES = 32 * 1024 * 1024
_LOAD_OPTIONS = JSONOptions(json_mode=JSONMode.RELAXED, tz_aware=True, tzinfo=datetime.timezone.utc)
_clients: dict[str, redis.Redis] = {}


def _reset_clients() -> None:
    _clients.clear()


def _client(url: str) -> redis.Redis:
    if url not in _clients:
        _clients[url] = redis.Redis.from_url(url, socket_connect_timeout=1, socket_timeout=2)
    return _clients[url]


def _cacheable(db) -> bool:
    return config.CATALOG_CACHE_TTL_SECONDS > 0 and isinstance(db, Database)


def _stamp() -> str | None:
    """Latest change-stream id, "0-0" when empty, None when unknown (TTL-only)."""
    url = config.CATALOG_EVENTS_REDIS_URL
    if not url:
        return None
    try:
        entries = _client(url).xrevrange(STREAM, "+", "-", count=1)
    except Exception as exc:
        logger.warning("[CATALOG-CACHE] change stream unavailable: %s", type(exc).__name__)
        return None
    if not entries:
        return "0-0"
    entry_id = entries[0][0]
    return entry_id.decode() if isinstance(entry_id, bytes) else str(entry_id)


def cached(kind: str, db, loader, *, use_events: bool):
    """Return loader()'s result, served from Redis when a still-valid copy exists."""
    if not _cacheable(db):
        return loader()
    key = f"ai_idol:{kind}:v1:{db.name}"
    stamp = _stamp() if use_events else None
    try:
        client = _client(config.REDIS_URL)
        raw = client.get(key)
        if raw is not None:
            entry = json_util.loads(zlib.decompress(raw).decode("utf-8"), json_options=_LOAD_OPTIONS)
            if entry.get("stamp") == stamp:
                return entry["data"]
    except Exception as exc:
        logger.warning("[CATALOG-CACHE] %s unavailable: %s", kind, type(exc).__name__)
        client = None
    data = loader()
    if client is not None:
        try:
            blob = zlib.compress(json_util.dumps({"stamp": stamp, "data": data},
                                                 json_options=RELAXED_JSON_OPTIONS).encode("utf-8"))
            if len(blob) <= MAX_BYTES:
                client.set(key, blob, ex=config.CATALOG_CACHE_TTL_SECONDS)
        except Exception as exc:
            logger.warning("[CATALOG-CACHE] %s unavailable: %s", kind, type(exc).__name__)
    return data
