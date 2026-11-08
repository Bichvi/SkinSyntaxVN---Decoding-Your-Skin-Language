"""Flask blueprint: POST /api/home/recommend (Neo4j homepage sections)."""

from __future__ import annotations

import logging
import uuid

from flask import Blueprint, jsonify, request

from search_service.routes import is_authorized
from search_service.schemas import ValidationError

from .schemas import validate_home_request
from .service import get_default_service

logger = logging.getLogger("home_service")
home_bp = Blueprint("home_service", __name__)


@home_bp.post("/api/home/recommend")
def recommend():
    if not is_authorized():
        return jsonify({"error": "unauthorized"}), 401
    payload = request.get_json(silent=True)
    try:
        req = validate_home_request(payload)
    except ValidationError as exc:
        return jsonify({"error": "invalid_request", "field": exc.field}), 400
    correlation_id = (request.headers.get("X-Request-Id") or "").strip()[:64] or str(uuid.uuid4())
    try:
        body = get_default_service().recommend(req, correlation_id=correlation_id)
    except Exception as exc:  # last-resort boundary: never 500 with internals
        logger.error("home recommend failed: %s (cid=%s)", type(exc).__name__, correlation_id)
        body = {"degraded": True, "took_ms": 0, "sections": []}
    response = jsonify(body)
    response.headers["X-Request-Id"] = correlation_id
    return response
