from __future__ import annotations

import hmac
import os
from typing import Any

from flask import Flask, jsonify, request
from script_council import run_script_council


app = Flask(__name__)
MAX_REQUEST_BYTES = int(os.getenv("AI_IDOL_CREW_MAX_REQUEST_BYTES", "24000"))
INTERNAL_TOKEN = os.getenv("AI_IDOL_INTERNAL_TOKEN", "").strip()


def _clip(value: Any, limit: int) -> str:
    return str(value or "").strip()[:limit]


def _authorized() -> bool:
    if not INTERNAL_TOKEN:
        return True
    supplied = request.headers.get("Authorization", "")
    expected = f"Bearer {INTERNAL_TOKEN}"
    return hmac.compare_digest(supplied, expected)


def _normalize_payload(payload: dict[str, Any]) -> dict[str, Any]:
    raw = payload.get("source") if isinstance(payload.get("source"), dict) else {}
    return {
        "source": {
            "name": _clip(raw.get("name"), 240),
            "brand": _clip(raw.get("brand"), 120),
            "category": _clip(raw.get("category"), 160),
            "origin": _clip(raw.get("origin"), 120),
            "price": _clip(raw.get("price"), 60),
            "market_price": _clip(raw.get("market_price"), 60),
            "ingredients": _clip(raw.get("ingredients"), 1000),
            "description": _clip(raw.get("description"), 1300),
            "usage": _clip(raw.get("usage"), 650),
            "skin_type": _clip(raw.get("skin_type"), 180),
            "knowledge": _clip(raw.get("knowledge"), 1000),
            "content_mode": _clip(raw.get("content_mode"), 80),
            "length_mode": _clip(raw.get("length_mode"), 80),
        }
    }


@app.get("/api/health")
def health():
    return jsonify({"ok": True, "service": "ai-idol-script-council", "provider": "crewai"})


@app.post("/api/script-council")
def script_council():
    if not _authorized():
        return jsonify({"ok": False, "error": "Unauthorized"}), 401
    if request.content_length and request.content_length > MAX_REQUEST_BYTES:
        return jsonify({"ok": False, "error": "Request is too large"}), 413
    payload = request.get_json(silent=True)
    if not isinstance(payload, dict):
        return jsonify({"ok": False, "error": "JSON object is required"}), 400
    normalized = _normalize_payload(payload)
    if not normalized["source"]["name"]:
        return jsonify({"ok": False, "error": "Product name is required"}), 400
    try:
        script = run_script_council(normalized)
        return jsonify({"ok": True, "script": script, "provider": "crewai", "agents": 2})
    except Exception as exc:
        app.logger.exception("Script Council failed")
        return jsonify({"ok": False, "error": str(exc)[:600]}), 503


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.getenv("AI_IDOL_CREW_PORT", "5013")))
