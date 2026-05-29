# -*- coding: utf-8 -*-
"""Standalone Recommendation Flask service.
Luồng chính: PHP /goiy -> POST /api/recommend/llamaindex -> LlamaIndex service.
"""
from __future__ import annotations

import os
import sys
from pathlib import Path

os.environ["PYTHONUTF8"] = "1"
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

from dotenv import load_dotenv
from flask import Flask, jsonify, request
from flask_cors import CORS

_BASE_DIR = Path(__file__).resolve().parent
_ENV_PATH = _BASE_DIR.parent / ".env"
if _ENV_PATH.exists():
    load_dotenv(_ENV_PATH, override=True)
else:
    load_dotenv(override=True)

from services.llamaindex_recommend_service import llamaindex_recommend_service


HOST = os.getenv("RECOMMENDATION_HOST", "127.0.0.1")
PORT = int(os.getenv("RECOMMENDATION_PORT", "5002"))
UNAVAILABLE_MESSAGE = "Hiện chưa thể tạo gợi ý cá nhân hóa. Vui lòng thử lại sau."

app = Flask(__name__)
CORS(app)


@app.get("/health")
def health():
    """Health check nhẹ, không load index và không gọi MongoDB để phản hồi nhanh."""
    return jsonify({
        "ok": True,
        "service": "recommendation-flask",
        "framework": "LlamaIndex",
    })


@app.get("/")
def root():
    """Mở root trên trình duyệt thì trả health thay vì 404 để dễ kiểm tra service."""
    return health()


@app.post("/api/recommend/llamaindex")
def llamaindex_recommendation():
    """Endpoint cá nhân hóa cho route PHP /goiy.

    Payload tối thiểu: {"user_id": "...", "email": "..."}.
    Service phía dưới tự lấy MongoDB profile/history/cart/chat, load index đã build,
    chạy VectorIndexRetriever + BM25Retriever, rerank top 5 và dùng Gemini viết answer_text.
    """
    payload = request.get_json(force=True, silent=True) or {}
    try:
        result = llamaindex_recommend_service.recommend(
            user_id=payload.get("user_id") or payload.get("session_user_id"),
            email=str(payload.get("email") or "").strip(),
        )
        return jsonify(result)
    except Exception as exc:
        app.logger.warning("LlamaIndex recommendation failed: %s", exc)
        return jsonify({
            "ok": False,
            "message": UNAVAILABLE_MESSAGE,
        }), 503


@app.errorhandler(404)
def not_found(_):
    return jsonify({"ok": False, "message": "Route not found"}), 404


@app.errorhandler(500)
def server_error(_):
    return jsonify({"ok": False, "message": UNAVAILABLE_MESSAGE}), 500


if __name__ == "__main__":
    print(f"[START] SkinSyntaxVN Recommendation Flask - http://{HOST}:{PORT}")
    print("[INFO]  Health: /health")
    print("[INFO]  LlamaIndex API: /api/recommend/llamaindex")
    app.run(host=HOST, port=PORT, debug=False, use_reloader=False)
