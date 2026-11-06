"""POST /api/label/analyze - photo-of-ingredient-label analysis. Never answers 500."""

from __future__ import annotations

import logging
import uuid

from flask import Blueprint, jsonify, request

from search_service.routes import is_authorized
from shared.redis_kit import keys as redis_keys
from shared.redis_kit import ratelimit

from . import config, summary
from .service import LabelValidationError, get_label_service, parse_request

logger = logging.getLogger(__name__)

label_bp = Blueprint("label_bp", __name__)
_service = get_label_service  # module-level indirection so tests can monkeypatch

_BODY_SLACK = 64 * 1024


def _minimal_unavailable() -> dict:
    body = {
        "status": "vision_unavailable",
        "product": {"name": "", "brand": "", "product_type": "other", "matched_product_id": None, "match_score": None},
        "ingredients": {"source": "photo", "total": 0, "recognized": 0, "items": [], "raw_text": ""},
        "conflicts": [], "schedule": [],
        "skin_fit": {"verdict": "unknown", "profile": {"skin_type": None, "sensitive": False, "concerns": []},
                     "good": [], "caution": [], "avoid": []},
        "alternatives": [], "warnings": ["internal_error"], "summary_vi": "", "sunscreen_note": False, "took_ms": 0,
    }
    body["summary_vi"] = summary.render({"status": "vision_unavailable"})
    return body


def _too_large():
    return jsonify({"error": "image_too_large"}), 413


@label_bp.post("/api/label/analyze")
def label_analyze():
    if not is_authorized():
        return jsonify({"error": "unauthorized"}), 401
    if not config.LABEL_SCAN_ENABLED:
        return jsonify({"error": "disabled"}), 404
    limit = (config.LABEL_MAX_IMAGE_BYTES * 4 + 2) // 3 + _BODY_SLACK
    if request.content_length is not None and request.content_length > limit:
        return _too_large()
    # Service-wide cap (PHP is the only caller, so remote_addr is shared); per-user limits are enforced in PHP LabelScanController.
    allowed, retry_after = ratelimit.allow(
        redis_keys.ratelimit("label_analyze", request.remote_addr or "unknown"),
        config.RATE_LIMIT_COUNT, config.RATE_LIMIT_WINDOW_S,
    )
    if not allowed:
        limited = jsonify({"error": "rate_limited"})
        limited.status_code = 429
        limited.headers["Retry-After"] = ratelimit.retry_after_header(retry_after)
        return limited
    payload = request.get_json(silent=True)
    try:
        req = parse_request(payload, max_image_bytes=config.LABEL_MAX_IMAGE_BYTES)
    except LabelValidationError as exc:
        if exc.field == "image_too_large":
            return _too_large()
        return jsonify({"error": "invalid_request", "field": exc.field}), 400
    correlation_id = (request.headers.get("X-Request-Id") or "").strip()[:64] or str(uuid.uuid4())
    try:
        body = _service().analyze(req, correlation_id=correlation_id)
    except Exception as exc:  # last-resort boundary
        logger.error("label analyze failed: %s (cid=%s)", type(exc).__name__, correlation_id)
        body = _minimal_unavailable()
    logger.info("label_analyze_route status=%s took_ms=%s cid=%s", body.get("status"), body.get("took_ms"), correlation_id)
    response = jsonify(body)
    response.headers["X-Request-Id"] = correlation_id
    return response
