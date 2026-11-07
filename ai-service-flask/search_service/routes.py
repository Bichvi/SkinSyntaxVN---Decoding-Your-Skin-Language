"""Flask blueprint: POST /api/search/recommend, GET /api/search/health."""

from __future__ import annotations

import hmac
import ipaddress
import logging
import os
import threading
import uuid

from flask import Blueprint, jsonify, request

from . import config
from .schemas import ValidationError, validate_request

logger = logging.getLogger("search_service")
search_bp = Blueprint("search_bp", __name__)
_warned_no_token = False
_warm_pid: int | None = None


@search_bp.before_app_request
def _warm_once_per_worker() -> None:
    """Start background warm-up once per (forked) worker process."""

    global _warm_pid
    if _warm_pid == os.getpid():
        return
    _warm_pid = os.getpid()
    from .service import warm_up

    threading.Thread(target=warm_up, daemon=True, name="search-warmup").start()


def _is_internal_addr(addr: str | None) -> bool:
    try:
        ip = ipaddress.ip_address((addr or "").strip())
    except ValueError:
        return False
    return ip.is_loopback or ip.is_private


def is_authorized() -> bool:
    """Shared secret ``X-Internal-Token`` == SEARCH_INTERNAL_TOKEN.

    With no token configured, only loopback/private (docker network) callers pass.
    """

    global _warned_no_token
    token = config.INTERNAL_TOKEN
    if token:
        given = request.headers.get(config.INTERNAL_TOKEN_HEADER, "")
        return bool(given) and hmac.compare_digest(given.encode(), token.encode())
    if not _warned_no_token:
        _warned_no_token = True
        logger.warning("SEARCH_INTERNAL_TOKEN is empty; /api/search allows loopback/private callers only")
    return _is_internal_addr(request.remote_addr)


def _service():
    from .service import get_default_service

    return get_default_service()


@search_bp.post("/api/search/recommend")
def recommend():
    if not is_authorized():
        return jsonify({"error": "unauthorized"}), 401
    payload = request.get_json(silent=True)
    try:
        req = validate_request(payload)
    except ValidationError as exc:
        return jsonify({"error": "invalid_request", "field": exc.field}), 400
    correlation_id = (request.headers.get("X-Request-Id") or "").strip()[:64] or str(uuid.uuid4())
    try:
        body = _service().recommend(req, correlation_id=correlation_id)
    except Exception as exc:  # last-resort boundary: never 500 with internals
        logger.error("search recommend failed: %s (cid=%s)", type(exc).__name__, correlation_id)
        body = {"mode": "fallback", "degraded": True, "degraded_branches": ["all"],
                "took_ms": 0, "intent": None, "sections": []}
    response = jsonify(body)
    response.headers["X-Request-Id"] = correlation_id
    return response


@search_bp.get("/api/search/health")
def health():
    if not is_authorized():
        return jsonify({"error": "unauthorized"}), 401
    from .neo4j_client import health as neo4j_health
    from .service import get_parts

    chroma = "down"
    try:
        chroma = get_parts()["vectorstore"].health()
    except Exception:
        pass
    redis_state = "down"
    try:
        from shared.redis_store import get_redis

        client = get_redis()
        redis_state = "ok" if client is not None and client.ping() else "down"
    except Exception:
        pass
    return jsonify({"neo4j": neo4j_health(), "chroma": chroma, "redis": redis_state})
