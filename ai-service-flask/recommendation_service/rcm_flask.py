"""Recommendation service Flask app factory and HTTP routes."""

from __future__ import annotations

import logging
import os
import sys
from pathlib import Path
from typing import Any

from flask import Flask, jsonify, request

try:
    from flask_cors import CORS
except Exception:  # pragma: no cover - optional runtime
    CORS = None

if __package__ in {None, ""}:  # support `python recommendation_service/rcm_flask.py`
    _SERVICE_ROOT = Path(__file__).resolve().parents[1]
    if str(_SERVICE_ROOT) not in sys.path:
        sys.path.insert(0, str(_SERVICE_ROOT))
    from recommendation_service import config
    from recommendation_service.pipeline import RecommendationPipeline
    from recommendation_service.response_cache import ResponseCache
    from recommendation_service.schemas import RecommendRequest
else:
    from . import config
    from .pipeline import RecommendationPipeline
    from .response_cache import ResponseCache
    from .schemas import RecommendRequest

logger = logging.getLogger(__name__)


def create_app(pipeline: RecommendationPipeline | None = None) -> Flask:
    """Create the recommendation app without loading AI models at import time."""

    app = Flask(__name__)
    if CORS is not None:
        CORS(app)
    app.config["RECOMMENDATION_PIPELINE"] = pipeline
    app.extensions["recommendation_cache"] = ResponseCache()

    def get_pipeline() -> RecommendationPipeline:
        current = app.config.get("RECOMMENDATION_PIPELINE")
        if current is None:
            current = RecommendationPipeline.default()
            app.config["RECOMMENDATION_PIPELINE"] = current
        return current

    def recommend_payload(payload: dict[str, Any]):
        parsed = RecommendRequest.from_payload(payload)
        cache = app.extensions["recommendation_cache"]
        key = cache.key(parsed.model_dump(mode="json"))
        cached = cache.get(key)
        if cached is not None:
            cached["cached"] = True
            return jsonify(cached)
        response = get_pipeline().recommend(parsed).to_dict()
        cache.set(key, response)
        return jsonify(response)

    @app.get("/health")
    @app.get("/api/health")
    def health():
        return jsonify({"ok": True, "service": "recommendation-flask", "schema_version": "1.0"})

    @app.get("/ready")
    def ready():
        return jsonify({"ok": True, "service": "recommendation-flask", "models": "lazy"})

    @app.get("/")
    def root():
        return health()

    @app.post("/api/recommend")
    @app.post("/api/recommend/routine")
    @app.post("/api/recommend/profile")
    @app.post("/api/recommend/llamaindex")
    @app.post("/api/recommend/langchain-rag")
    @app.post("/api/recommend/explain")
    def recommend():
        payload = request.get_json(silent=True)
        if not isinstance(payload, dict):
            return jsonify({"ok": False, "message": "JSON payload không hợp lệ."}), 400
        try:
            return recommend_payload(payload)
        except ValueError as exc:
            return jsonify({"ok": False, "message": str(exc)}), 400
        except Exception:  # final HTTP boundary: never leak stack/secret
            logger.exception("Recommendation request failed", extra={"user_id": str(payload.get("user_id") or "")})
            return jsonify({"ok": False, "message": config.UNAVAILABLE_MESSAGE}), 503

    @app.get("/api/recommend/profile/<user_id>")
    def profile_recommend(user_id: str):
        payload = dict(request.args)
        payload["user_id"] = user_id
        try:
            return recommend_payload(payload)
        except Exception:
            logger.exception("Profile recommendation failed", extra={"user_id": user_id})
            return jsonify({"ok": False, "message": config.UNAVAILABLE_MESSAGE}), 503

    @app.post("/api/recommend/index")
    def refresh_index():
        try:
            current = get_pipeline()
            products = current.search.repository.list_visible()
            count = current.search.retrieval.build_or_refresh(products)
            current.search.invalidate("manual_refresh")
            return jsonify({"ok": True, "indexed": count, "products": len(products)})
        except Exception as exc:
            logger.warning("Recommendation index refresh degraded: %s", type(exc).__name__)
            return jsonify({"ok": False, "indexed": 0, "message": "Không thể làm mới index lúc này."}), 503

    @app.errorhandler(404)
    def not_found(_error):
        return jsonify({"ok": False, "message": "Route not found"}), 404

    @app.errorhandler(500)
    def server_error(_error):
        return jsonify({"ok": False, "message": config.UNAVAILABLE_MESSAGE}), 503

    return app


app = create_app()


if __name__ == "__main__":
    logging.basicConfig(level=os.getenv("LOG_LEVEL", "INFO"))
    app.run(host=config.HOST, port=config.PORT, debug=False, use_reloader=False)
