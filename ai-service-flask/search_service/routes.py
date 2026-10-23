"""Flask blueprint: POST /api/search/recommend, GET /api/search/health."""

from __future__ import annotations

import hmac
import ipaddress
import logging
import os
import threading
import uuid

from flask import Blueprint, jsonify, request

from shared.redis_kit import keys as redis_keys
from shared.redis_kit import ratelimit

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
    from .service import start_invalidation_listener, warm_up

    threading.Thread(target=warm_up, daemon=True, name="search-warmup").start()
    try:
        start_invalidation_listener()
    except Exception as exc:  # cache invalidation is best-effort; never break requests
        logger.warning("invalidation listener not started: %s", type(exc).__name__)


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
    allowed, retry_after = ratelimit.allow(
        redis_keys.ratelimit("search_recommend", request.remote_addr or "unknown"),
        config.RATE_LIMIT_PER_WINDOW, config.RATE_LIMIT_WINDOW_S,
    )
    if not allowed:
        limited = jsonify({"error": "rate_limited"})
        limited.status_code = 429
        limited.headers["Retry-After"] = ratelimit.retry_after_header(retry_after)
        return limited
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


@search_bp.get("/api/search/intent")
def intent():
    """Lightweight query analysis for "Có phải bạn muốn tìm...": intent + typo/abbreviation corrections (U05)."""

    if not is_authorized():
        return jsonify({"error": "unauthorized"}), 401
    allowed, retry_after = ratelimit.allow(
        redis_keys.ratelimit("search_intent", request.remote_addr or "unknown"),
        config.RATE_LIMIT_PER_WINDOW, config.RATE_LIMIT_WINDOW_S,
    )
    if not allowed:
        limited = jsonify({"error": "rate_limited"})
        limited.status_code = 429
        limited.headers["Retry-After"] = ratelimit.retry_after_header(retry_after)
        return limited
    q = request.args.get("q", "")
    if not isinstance(q, str) or not q.strip():
        return jsonify({"error": "invalid_request", "field": "q"}), 400
    try:
        from .service import describe_intent

        body = describe_intent(q)
    except Exception as exc:  # never 500 with internals; the caller treats any failure as "no correction"
        logger.error("search intent failed: %s", type(exc).__name__)
        body = {"intent": None, "corrected_q": q.strip()[: config.MAX_QUERY_LEN], "corrections": []}
    return jsonify(body)


@search_bp.get("/api/products/<product_id>/similar")
def similar_products(product_id: str):
    """U08: products similar to ``product_id`` (SIMILAR_TO graph, Chroma fallback). Never 500s with internals."""

    if not is_authorized():
        return jsonify({"error": "unauthorized"}), 401
    if not config.SEARCH_SIMILAR_ENABLED:
        return jsonify({"error": "disabled"}), 404
    allowed, retry_after = ratelimit.allow(
        redis_keys.ratelimit("product_similar", request.remote_addr or "unknown"),
        config.RATE_LIMIT_PER_WINDOW, config.RATE_LIMIT_WINDOW_S,
    )
    if not allowed:
        limited = jsonify({"error": "rate_limited"})
        limited.status_code = 429
        limited.headers["Retry-After"] = ratelimit.retry_after_header(retry_after)
        return limited
    from .similar import SimilarValidationError, parse_args

    try:
        req = parse_args(product_id, request.args.get("limit"), request.args.get("exclude"),
                         request.args.get("skin_type"))
    except SimilarValidationError as exc:
        return jsonify({"error": "invalid_request", "field": exc.field}), 400
    correlation_id = (request.headers.get("X-Request-Id") or "").strip()[:64] or str(uuid.uuid4())
    try:
        from .service import get_similar_service

        body = get_similar_service().similar(req, correlation_id=correlation_id)
    except Exception as exc:  # last-resort boundary
        logger.error("similar products failed: %s (cid=%s)", type(exc).__name__, correlation_id)
        body = {"product_id": req.product_id, "items": [], "degraded": True, "source": "semantic", "took_ms": 0}
    response = jsonify(body)
    response.headers["X-Request-Id"] = correlation_id
    return response


@search_bp.post("/api/cart/analyze")
def cart_analyze():
    """U09: ingredient conflicts between cart products + missing routine steps + suggestions. Never 500s with internals."""

    if not is_authorized():
        return jsonify({"error": "unauthorized"}), 401
    if not config.SEARCH_CART_ENABLED:
        return jsonify({"error": "disabled"}), 404
    allowed, retry_after = ratelimit.allow(
        redis_keys.ratelimit("cart_analyze", request.remote_addr or "unknown"),
        config.RATE_LIMIT_PER_WINDOW, config.RATE_LIMIT_WINDOW_S,
    )
    if not allowed:
        limited = jsonify({"error": "rate_limited"})
        limited.status_code = 429
        limited.headers["Retry-After"] = ratelimit.retry_after_header(retry_after)
        return limited
    from .cart_analyze import CartValidationError, parse_request

    try:
        req = parse_request(request.get_json(silent=True))
    except CartValidationError as exc:
        return jsonify({"error": "invalid_request", "field": exc.field}), 400
    correlation_id = (request.headers.get("X-Request-Id") or "").strip()[:64] or str(uuid.uuid4())
    try:
        from .service import get_cart_service

        body = get_cart_service().analyze(req, correlation_id=correlation_id)
    except Exception as exc:  # last-resort boundary
        logger.error("cart analyze failed: %s (cid=%s)", type(exc).__name__, correlation_id)
        body = {"conflicts": [], "routine": {"has": [], "missing": [], "labels": {}}, "suggestions": [],
                "degraded": True, "took_ms": 0}
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


_ops_cache: dict = {"at": 0.0, "body": None}
_ops_lock = threading.Lock()
OPS_HEALTH_TTL_S = 10.0


@search_bp.get("/api/ops/health")
def ops_health():
    """U11: search stack health (Neo4j / Chroma / Redis / Mongo counts, stream backlog, jobs, alerts).

    Internal token only. Cached ~10s in-process so a dashboard polling cannot hammer Atlas / Neo4j.
    """

    if not is_authorized():
        return jsonify({"error": "unauthorized"}), 401
    import time as _time

    with _ops_lock:
        if _ops_cache["body"] is not None and _time.monotonic() - _ops_cache["at"] < OPS_HEALTH_TTL_S:
            return jsonify(_ops_cache["body"])
    try:
        from .ops_health import build_health

        body = build_health()
    except Exception as exc:  # last-resort boundary: never 500 with internals
        logger.error("ops health failed: %s", type(exc).__name__)
        return jsonify({"ok": False, "error": "health_unavailable", "alerts": []}), 503
    with _ops_lock:
        _ops_cache.update(at=_time.monotonic(), body=body)
    return jsonify(body)
