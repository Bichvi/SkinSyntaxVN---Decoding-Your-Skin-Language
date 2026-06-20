# -*- coding: utf-8 -*-
"""AI backend API — no UI (demo / thesis engine only).

The chatbot UI lives in the frontend:
  - frontend/views/components/ai_chat_widget.php
  - frontend/public/assets/js/ai-chat-widget.js

Production flow:
  browser widget -> PHP BFF (AiChatService) -> this service (port 5001)

Use curl or `python chatbot_flask.py` only to test the API directly.
Nginx blocks public /api/; php-backend calls this over the Docker network.
"""
import json
import logging
import os
import sys
from pathlib import Path

logger = logging.getLogger(__name__)

os.environ["PYTHONUTF8"] = "1"
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

from dotenv import load_dotenv

_ENV_PATH = Path(__file__).resolve().parent.parent / ".env"
if _ENV_PATH.exists():
    load_dotenv(_ENV_PATH, override=True)
else:
    load_dotenv(override=True)

from flask import Flask, request, jsonify, Response, stream_with_context

from llm_pool import get_llms, OPENAI_MODEL
from retrieval import get_vectorstore, get_hybrid_pipeline
from pipeline import xu_ly_cau_hoi, xu_ly_cau_hoi_stream
from session_cache import cache_stats
from response_cache import cache_stats as response_cache_stats
from router import should_use_agent

app = Flask(__name__)

FLASK_PORT = int(os.getenv("CHATBOT_PORT", 5001))


def _parse_chat_payload(data: dict) -> tuple[str, dict | None]:
    message_raw = str(data.get("message", "")).strip()
    if message_raw:
        try:
            msg_data = json.loads(message_raw)
            return str(msg_data.get("customer_question", message_raw)).strip(), msg_data
        except Exception:
            return message_raw, data
    message = str(data.get("customer_question", data.get("message", ""))).strip()
    return message, data


def _run_pipeline(message: str, msg_data: dict | None, **meta):
    result = xu_ly_cau_hoi(message, msg_data)
    result.update(meta)
    result.setdefault("_mode", meta.get("_mode", "pipeline"))
    return jsonify(result)


def _run_agent_or_fallback(message: str, msg_data: dict | None, **meta):
    try:
        from agent import run_agent
        result = run_agent(message, msg_data)
        result.update(meta)
        result["_mode"] = "agent"
        return jsonify(result)
    except Exception as exc:
        logger.debug("[AUTO] Agent failed: %s — falling back to pipeline", exc)
        result = xu_ly_cau_hoi(message, msg_data)
        result.update(meta)
        result["_mode"] = "pipeline_fallback"
        result["_route_reason"] = f"agent failed: {type(exc).__name__}"
        return jsonify(result)


@app.get("/api/health")
def health():
    vs_ok, doc_count = False, 0
    try:
        vs = get_vectorstore()
        doc_count = vs._collection.count()
        vs_ok = True
    except Exception as exc:
        logger.warning("[HEALTH] Vectorstore error: %s", exc)

    llms = get_llms()
    primary = getattr(llms[0], "model", getattr(llms[0], "model_name", OPENAI_MODEL)) if llms else OPENAI_MODEL

    return jsonify({
        "ok": True,
        "service": "SkinSyntaxVN Chatbot",
        "port": FLASK_PORT,
        "model": f"{primary} (primary)",
        "openai_model": OPENAI_MODEL,
        "llm_count": len(llms),
        "chromadb": vs_ok,
        "documents": doc_count,
        "session_cache": cache_stats(),
        "response_cache": response_cache_stats(),
    })


@app.post("/api/chat/stream")
def chat_stream():
    data = request.get_json(force=True) or {}
    message, msg_data = _parse_chat_payload(data)
    if not message:
        return jsonify({"ok": False, "message": "Thiếu nội dung câu hỏi."}), 400

    def generate():
        try:
            from cart_analysis import is_cart_analysis_query, stream_cart_analysis_sse

            if is_cart_analysis_query(message):
                yield from stream_cart_analysis_sse(message, msg_data)
            elif should_use_agent(message, msg_data):
                from agent_stream import stream_agent_sse
                yield from stream_agent_sse(message, msg_data)
            else:
                yield from xu_ly_cau_hoi_stream(message, msg_data)
        except Exception as exc:
            yield f"data: {json.dumps({'type': 'error', 'message': str(exc)})}\n\n"
            yield "data: [DONE]\n\n"

    return Response(
        stream_with_context(generate()),
        mimetype="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "X-Accel-Buffering": "no",
            "Connection": "keep-alive",
        },
    )


@app.post("/api/chat/auto")
def chat_auto():
    data = request.get_json(force=True) or {}
    message, msg_data = _parse_chat_payload(data)
    if not message:
        return jsonify({"ok": False, "message": "Thiếu nội dung câu hỏi."}), 400

    meta = {"_routed_by": "auto"}
    try:
        from cart_analysis import is_cart_analysis_query, build_cart_analysis_answer

        if is_cart_analysis_query(message):
            answer = build_cart_analysis_answer(
                msg_data.get("cart_items") or [],
                msg_data.get("cart_conflicts") or [],
                msg_data.get("customer_profile") or {},
            )
            return jsonify({
                "ok": True,
                "answer": answer,
                "products": [],
                "conflicts": msg_data.get("cart_conflicts") or [],
                "intent": "CART_ANALYSIS",
                "mode": "cart_fast",
                **meta,
                "_route_reason": "cart analysis fast path",
            })

        if should_use_agent(message, msg_data):
            return _run_agent_or_fallback(
                message,
                msg_data,
                **meta,
                _route_reason="multi-step / ingredient / routine detected",
            )
        return _run_pipeline(
            message,
            msg_data,
            **meta,
            _mode="pipeline",
            _route_reason="simple single-intent query",
        )
    except Exception as exc:
        return jsonify({"ok": False, "message": str(exc)}), 500


@app.post("/api/recommend/explain")
@app.post("/api/recommend/langchain-rag")
def recommend():
    data = request.get_json(force=True) or {}
    message, msg_data = _parse_chat_payload(data)
    if not message:
        return jsonify({"ok": False, "message": "Thiếu nội dung câu hỏi."}), 400
    try:
        return _run_pipeline(message, msg_data)
    except Exception as exc:
        return jsonify({"ok": False, "message": str(exc)}), 500


@app.post("/api/eval/score")
def eval_score():
    data = request.get_json(force=True) or {}
    question = str(data.get("question", "")).strip()
    answer = str(data.get("answer", "")).strip()
    context = str(data.get("context", "")).strip()
    if not question or not answer:
        return jsonify({"ok": False, "message": "question and answer are required"}), 400
    try:
        from live_scorer import score_response
        scores = score_response(question, answer, context)
        return jsonify({"ok": True, **scores})
    except Exception as exc:
        logger.warning("[EVAL_SCORE] Error: %s", exc)
        return jsonify({"ok": False, "message": str(exc)}), 500


@app.errorhandler(404)
def not_found(_):
    return jsonify({"ok": False, "message": "Route not found"}), 404


@app.errorhandler(500)
def server_error(_):
    return jsonify({"ok": False, "message": "Internal error"}), 500


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
        datefmt="%H:%M:%S",
    )
    logger.info("SkinSyntaxVN Chatbot — port %s", FLASK_PORT)
    logger.info("Primary model: %s", OPENAI_MODEL)
    logger.info("Pre-warming vectorstore & LLMs ...")
    try:
        get_vectorstore()
        get_hybrid_pipeline()
        get_llms()
    except Exception as exc:
        logger.warning("Pre-warm failed: %s", exc)
    logger.info("Health check: http://127.0.0.1:%s/api/health", FLASK_PORT)
    app.run(host="0.0.0.0", port=FLASK_PORT, debug=False, use_reloader=False)
