# -*- coding: utf-8 -*-
"""Stream agent results as SSE events."""
from __future__ import annotations

import json
import logging
from typing import Generator

logger = logging.getLogger(__name__)


def stream_agent_sse(message: str, msg_data: dict | None) -> Generator[str, None, None]:
    def _sse(payload: dict | str) -> str:
        if isinstance(payload, str):
            return f"data: {payload}\n\n"
        return f"data: {json.dumps(payload, ensure_ascii=False)}\n\n"

    yield _sse({"type": "status", "message": "Đang xử lý câu hỏi phức tạp..."})
    try:
        from agent import run_agent
        result = run_agent(message, msg_data)
        answer = str(result.get("answer") or "").strip()
        if not answer:
            raise RuntimeError("Agent returned empty answer")
        yield _sse({"type": "token", "delta": answer})
        yield _sse({
            "type": "done",
            "products": result.get("products") or [],
            "conflicts": result.get("conflicts") or [],
            "analysis": result.get("analysis") or {},
            "intent": result.get("intent") or "",
            "suggestions": result.get("suggestions") or [],
            "mode": "agent",
        })
    except Exception as exc:
        logger.debug("[AGENT_STREAM] Failed: %s — falling back to pipeline", exc)
        from pipeline import xu_ly_cau_hoi_stream
        yield from xu_ly_cau_hoi_stream(message, msg_data)
        return
    yield "data: [DONE]\n\n"
