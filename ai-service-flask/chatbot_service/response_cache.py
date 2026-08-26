# -*- coding: utf-8 -*-
"""Redis-backed cache for repeat chat questions (shared across workers)."""
from __future__ import annotations

import hashlib
import json
import logging
import os
import re
from typing import Any

from redis_store import get_redis

logger = logging.getLogger(__name__)

_KEY_PREFIX = "skinsyntax:chat:resp:"
_DEFAULT_TTL = int(os.getenv("REDIS_CACHE_TTL", "604800"))


def normalize_message(message: str) -> str:
    text = (message or "").strip().lower()
    text = re.sub(r"\s+", " ", text)
    return text


def build_cache_key(message: str, msg_data: dict | None) -> str:
    profile = (msg_data or {}).get("customer_profile") or {}
    avoid = profile.get("avoid_ingredients") or []
    if isinstance(avoid, list):
        avoid = sorted(str(x).strip().lower() for x in avoid if str(x).strip())
    else:
        avoid = []
    product_id = str((msg_data or {}).get("current_product_id") or "")
    skin_type = str(profile.get("skin_type") or profile.get("loai_da") or "").strip().lower()
    budget = str(profile.get("budget") or profile.get("ngan_sach") or 0)
    raw = "|".join([
        normalize_message(message),
        product_id,
        skin_type,
        ",".join(avoid),
        budget,
    ])
    return _KEY_PREFIX + hashlib.sha256(raw.encode("utf-8")).hexdigest()


def should_cache_request(message: str, msg_data: dict | None, *, use_agent: bool = False) -> bool:
    if use_agent:
        return False
    if not message.strip():
        return False
    data = msg_data or {}
    if data.get("cart_items"):
        return False
    if data.get("cart_conflicts"):
        return False
    history = data.get("conversation_history") or []
    if isinstance(history, list) and len(history) > 0:
        return False
    from router import should_use_agent
    if should_use_agent(message):
        return False
    return True


def get_cached_response(message: str, msg_data: dict | None) -> dict | None:
    client = get_redis()
    if client is None:
        return None
    try:
        raw = client.get(build_cache_key(message, msg_data))
        if not raw:
            return None
        data = json.loads(raw)
        return data if isinstance(data, dict) else None
    except Exception as exc:
        logger.debug("[RESP_CACHE] get failed: %s", exc)
        return None


def store_response(message: str, msg_data: dict | None, payload: dict) -> None:
    if not should_cache_request(message, msg_data):
        return
    answer = str(payload.get("answer") or "").strip()
    if not answer or payload.get("ok") is False:
        return
    client = get_redis()
    if client is None:
        return
    try:
        client.setex(
            build_cache_key(message, msg_data),
            _DEFAULT_TTL,
            json.dumps(payload, ensure_ascii=False, separators=(",", ":")),
        )
    except Exception as exc:
        logger.debug("[RESP_CACHE] set failed: %s", exc)


def cache_stats() -> dict[str, Any]:
    client = get_redis()
    if client is None:
        return {"enabled": False, "ttl_seconds": _DEFAULT_TTL}
    try:
        client.ping()
        return {"enabled": True, "ttl_seconds": _DEFAULT_TTL, "ping": True}
    except Exception:
        return {"enabled": True, "ttl_seconds": _DEFAULT_TTL, "ping": False}
