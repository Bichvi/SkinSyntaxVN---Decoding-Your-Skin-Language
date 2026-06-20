# -*- coding: utf-8 -*-
"""Server-side chat session state — Redis with in-memory fallback."""
from __future__ import annotations

import json
import time
from threading import Lock
from typing import Optional

from redis_store import get_redis

_SESSION_PREFIX = "skinsyntax:chat:session:"
_TTL = int(__import__("os").getenv("SESSION_CACHE_TTL", "3600"))
_MAX_TURNS = 10

_MEMORY: dict[str, dict] = {}
_LOCK = Lock()


def session_key(msg_data: dict | None) -> Optional[str]:
    if not msg_data:
        return None
    uid = (
        msg_data.get("user_id")
        or msg_data.get("session_id")
        or msg_data.get("customer_id")
    )
    return str(uid).strip() if uid else None


def _redis_key(key: str) -> str:
    return _SESSION_PREFIX + key


def _load_entry(key: str) -> dict | None:
    client = get_redis()
    if client is not None:
        try:
            raw = client.get(_redis_key(key))
            if raw:
                data = json.loads(raw)
                return data if isinstance(data, dict) else None
        except Exception:
            pass
    with _LOCK:
        entry = _MEMORY.get(key)
        if not entry:
            return None
        if time.monotonic() - entry.get("ts", 0) > _TTL:
            del _MEMORY[key]
            return None
        return dict(entry)


def _save_entry(key: str, entry: dict) -> None:
    entry["ts"] = time.monotonic()
    client = get_redis()
    if client is not None:
        try:
            client.setex(
                _redis_key(key),
                _TTL,
                json.dumps(entry, ensure_ascii=False, separators=(",", ":")),
            )
            return
        except Exception:
            pass
    with _LOCK:
        _MEMORY[key] = entry


def get_history(msg_data: dict | None) -> list[dict]:
    key = session_key(msg_data)
    if not key:
        return []
    entry = _load_entry(key)
    if not entry:
        return []
    return list(entry.get("history") or [])


def next_rotating_index(msg_data: dict | None, namespace: str, pool_size: int) -> int:
    if pool_size <= 0:
        return 0
    key = session_key(msg_data)
    if not key:
        return hash(namespace) % pool_size
    entry = _load_entry(key) or {"history": [], "counters": {}, "state": {}}
    counters = entry.setdefault("counters", {})
    last = counters.get(namespace, -1)
    idx = (last + 1) % pool_size
    counters[namespace] = idx
    _save_entry(key, entry)
    return idx


def save_turn(
    msg_data: dict | None,
    user_msg: str,
    bot_msg: str,
    last_products: list | None = None,
) -> None:
    key = session_key(msg_data)
    if not key:
        return
    entry = _load_entry(key) or {"history": [], "counters": {}, "state": {}}
    history = list(entry.get("history") or [])
    history.append({"sender": "user", "text": user_msg})
    history.append({"sender": "bot", "text": bot_msg[:500]})
    if len(history) > _MAX_TURNS * 2:
        history = history[-(_MAX_TURNS * 2):]
    entry["history"] = history
    if last_products:
        entry["last_products"] = last_products[:8]
    _save_entry(key, entry)


def get_last_products(msg_data: dict | None) -> list[dict]:
    key = session_key(msg_data)
    if not key:
        return []
    entry = _load_entry(key)
    if not entry:
        return []
    return list(entry.get("last_products") or [])


def get_session_state(msg_data: dict | None) -> dict:
    key = session_key(msg_data)
    if not key:
        return {}
    entry = _load_entry(key)
    if not entry:
        return {}
    return dict(entry.get("state") or {})


def update_session_state(msg_data: dict | None, **fields) -> None:
    key = session_key(msg_data)
    if not key:
        return
    patch = {k: v for k, v in fields.items() if v is not None}
    if not patch:
        return
    entry = _load_entry(key) or {"history": [], "counters": {}, "state": {}}
    state = entry.setdefault("state", {})
    state.update(patch)
    _save_entry(key, entry)


def expire_old() -> int:
    client = get_redis()
    if client is not None:
        return 0
    now = time.monotonic()
    with _LOCK:
        expired = [k for k, v in _MEMORY.items() if now - v.get("ts", 0) > _TTL]
        for k in expired:
            del _MEMORY[k]
    return len(expired)


def cache_stats() -> dict:
    client = get_redis()
    if client is not None:
        return {"backend": "redis", "ttl_seconds": _TTL}
    with _LOCK:
        return {"backend": "memory", "active_sessions": len(_MEMORY), "ttl_seconds": _TTL}
