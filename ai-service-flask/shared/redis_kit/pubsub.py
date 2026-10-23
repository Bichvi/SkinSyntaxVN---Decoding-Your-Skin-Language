"""Cache invalidation over Pub/Sub + a generation counter (no SCAN needed).

Why a *generation* instead of deleting ``search:rec:*`` keys: the recommend cache key embeds
``g<gen>`` (``keys.search_cache_key_with_gen``). ``publish_invalidate`` does ``INCR search:cachegen:v1``,
so every old entry is orphaned in O(1) and expires on its own TTL (<= 300s). No ``SCAN``/``DEL`` storm,
no race where a request re-fills an entry between SCAN and DEL, and it works even when no listener is
running. The Pub/Sub message (``cache:invalidate``) additionally tells every worker process to drop
in-process caches (catalog snapshot, entity dictionary) via handlers registered with ``register_handler``.
"""

from __future__ import annotations

import json
import logging
import os
import threading
import time
from typing import Any, Callable, Iterable

from . import client as _client
from . import keys

logger = logging.getLogger("redis_kit.pubsub")

MAX_IDS = 200
Handler = Callable[[dict], None]

_handlers: list[Handler] = []
_listener_pid: int | None = None
_listener_lock = threading.Lock()


def current_generation(client: Any | None = None) -> int:
    """Current cache generation (0 when unset or Redis is down)."""

    redis_client = client if client is not None else _client.get_redis()
    if redis_client is None:
        return 0
    try:
        raw = redis_client.get(keys.SEARCH_CACHE_GEN)
        return int(raw) if raw else 0
    except Exception as exc:
        _client.mark_down_if(client is None, exc)
        return 0


def publish_invalidate(scope: str, ids: Iterable[Any] = (), *, client: Any | None = None) -> bool:
    """Invalidate search caches. ``scope`` e.g. ``"catalog"`` (product changed), ``"search"``, ``"all"``.

    Bumps the generation, then broadcasts ``{"scope", "ids", "gen", "ts"}`` on ``cache:invalidate``.
    Returns False when Redis is unavailable (callers should rely on the cache TTL then).
    """

    redis_client = client if client is not None else _client.get_redis()
    if redis_client is None:
        return False
    id_list = [str(i) for i in list(ids)[:MAX_IDS]]
    try:
        gen = int(redis_client.incr(keys.SEARCH_CACHE_GEN))
        message = json.dumps({"scope": str(scope), "ids": id_list, "gen": gen, "ts": int(time.time() * 1000)})
        redis_client.publish(keys.CHANNEL_INVALIDATE, message)
        return True
    except Exception as exc:
        _client.mark_down_if(client is None, exc)
        logger.warning("publish_invalidate failed: %s", type(exc).__name__)
        return False


def register_handler(handler: Handler) -> None:
    """Called in the listener thread with the decoded message (``{"scope": "*"}`` after a reconnect)."""

    if handler not in _handlers:
        _handlers.append(handler)


def _dispatch(message: dict) -> None:
    for handler in list(_handlers):
        try:
            handler(message)
        except Exception as exc:
            logger.warning("invalidate handler failed: %s", type(exc).__name__)


def _listen_forever(url: str | None, stop: threading.Event) -> None:
    backoff = 1.0
    first = True
    while not stop.is_set():
        try:
            conn = _client.make_client(url, socket_timeout=5.0, connect_timeout=1.0)
            ps = conn.pubsub(ignore_subscribe_messages=True)
            ps.subscribe(keys.CHANNEL_INVALIDATE)
            if not first:
                _dispatch({"scope": "*", "ids": [], "reason": "reconnect"})  # may have missed messages
            first = False
            backoff = 1.0
            while not stop.is_set():
                msg = ps.get_message(timeout=1.0)
                if not msg or msg.get("type") != "message":
                    continue
                try:
                    payload = json.loads(msg.get("data") or "{}")
                except (TypeError, ValueError):
                    continue
                if isinstance(payload, dict):
                    _dispatch(payload)
        except Exception as exc:
            logger.warning("invalidate listener lost connection (%s); retry in %.0fs", type(exc).__name__, backoff)
            stop.wait(backoff)
            backoff = min(backoff * 2, 30.0)


def start_listener(url: str | None = None) -> threading.Thread | None:
    """Start the background subscriber once per process (safe to call on every request)."""

    global _listener_pid
    pid = os.getpid()
    with _listener_lock:
        if _listener_pid == pid:
            return None
        _listener_pid = pid
    thread = threading.Thread(target=_listen_forever, args=(url, threading.Event()),
                              daemon=True, name="cache-invalidate-listener")
    thread.start()
    return thread
