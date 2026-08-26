# -*- coding: utf-8 -*-
"""Fast cart analysis without LLM agent."""
from __future__ import annotations

import json
import re
from typing import Generator


_CART_ANALYSIS_RE = re.compile(
    r"phân tích.*giỏ hàng|phan tich.*gio hang|giỏ hàng.*xung|gio hang.*xung|"
    r"cảnh báo.*xung|canh bao.*xung|xung đột.*giỏ|xung dot.*gio|"
    r"routine hiện tại|routine hien tai|phân tích nhanh giỏ hàng|phan tich nhanh gio hang",
    re.IGNORECASE,
)


def is_cart_analysis_query(message: str) -> bool:
    m = (message or "").strip()
    if not m:
        return False
    if _CART_ANALYSIS_RE.search(m):
        return True
    lower = m.lower()
    has_cart = "giỏ hàng" in lower or "gio hang" in lower
    has_signal = any(k in lower for k in ("xung đột", "xung dot", "phân tích", "phan tich", "cảnh báo", "canh bao"))
    return has_cart and has_signal


def _format_vnd(value: int) -> str:
    try:
        return f"{int(value):,}".replace(",", ".")
    except (TypeError, ValueError):
        return "0"


def build_cart_analysis_answer(
    cart_items: list,
    cart_conflicts: list,
    profile: dict | None = None,
) -> str:
    profile = profile or {}
    if not cart_items:
        return (
            "Giỏ hàng của bạn đang trống.\n\n"
            "Thêm sản phẩm vào giỏ rồi mình sẽ phân tích xung đột hoạt chất giúp bạn nhé."
        )

    lines = [f"**Phân tích nhanh giỏ hàng** ({len(cart_items)} sản phẩm)", ""]
    total = 0
    for item in cart_items:
        if not isinstance(item, dict):
            continue
        name = str(item.get("name") or item.get("ten_san_pham") or "Sản phẩm").strip()
        qty = max(1, int(item.get("qty") or item.get("so_luong") or 1))
        price = int(item.get("price") or item.get("gia_ban") or 0)
        total += price * qty
        suffix = f" ×{qty}" if qty > 1 else ""
        lines.append(f"• **{name}**{suffix} — {_format_vnd(price)} đ")

    lines.append("")
    lines.append(f"Tạm tính: **{_format_vnd(total)} đ**")

    skin_type = str(profile.get("skin_type") or profile.get("loai_da") or "").strip()
    if skin_type:
        lines.append(f"Loại da hồ sơ: **{skin_type}**")

    lines.append("")
    if not cart_conflicts:
        lines.extend([
            "✅ **Không phát hiện xung đột hoạt chất** giữa các sản phẩm trong giỏ "
            "(retinol/Vit C, retinol/AHA-BHA, BPO/retinol, AHA-BHA/Vit C).",
            "Bạn vẫn nên patch test và tăng tần suất dần nếu da nhạy cảm.",
        ])
        return "\n".join(lines)

    lines.append(f"⚠️ **Phát hiện {len(cart_conflicts)} cặp có thể xung đột:**")
    for conflict in cart_conflicts:
        if not isinstance(conflict, dict):
            continue
        product_a = str(conflict.get("product_a") or "").strip()
        product_b = str(conflict.get("product_b") or "").strip()
        warning = str(conflict.get("warning") or "").strip()
        recommendation = str(conflict.get("recommendation") or "").strip()
        lines.append("")
        if product_a and product_b:
            lines.append(f"**{product_a}** ↔ **{product_b}**")
        if warning:
            lines.append(f"- {warning}")
        if recommendation:
            lines.append(f"- Gợi ý: {recommendation}")

    return "\n".join(lines)


def stream_cart_analysis_sse(message: str, msg_data: dict | None) -> Generator[str, None, None]:
    msg_data = msg_data or {}
    cart_items = msg_data.get("cart_items") or []
    cart_conflicts = msg_data.get("cart_conflicts") or []
    profile = msg_data.get("customer_profile") or {}

    def _sse(payload: dict | str) -> str:
        if isinstance(payload, str):
            return f"data: {payload}\n\n"
        return f"data: {json.dumps(payload, ensure_ascii=False)}\n\n"

    yield _sse({"type": "status", "message": "Đang phân tích giỏ hàng..."})
    answer = build_cart_analysis_answer(cart_items, cart_conflicts, profile)
    yield _sse({"type": "token", "delta": answer})
    yield _sse({
        "type": "done",
        "products": [],
        "conflicts": cart_conflicts,
        "analysis": {"mode": "cart_fast", "intent": "CART_ANALYSIS"},
        "intent": "CART_ANALYSIS",
        "suggestions": [],
        "mode": "cart_fast",
    })
    yield "data: [DONE]\n\n"
