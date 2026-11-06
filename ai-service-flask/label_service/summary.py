"""Short Vietnamese summary built from the response dict. Template only, no LLM. Never echoes ingredients.raw_text."""

from __future__ import annotations

from typing import Mapping

_STATUS_TEXT = {
    "not_label": "Ảnh này chưa phải bảng thành phần. Bạn hãy chụp rõ phần Ingredients ở mặt sau chai nhé.",
    "unreadable": "Mình chưa đọc được chữ trong ảnh. Bạn thử chụp gần, đủ sáng hoặc dán danh sách thành phần vào nhé.",
    "vision_unavailable": "Hiện chưa đọc được ảnh. Bạn thử lại sau hoặc dán danh sách thành phần vào nhé.",
}
_FIT_TEXT = {
    "good": "Sản phẩm {name} có vẻ hợp với hồ sơ da của bạn.",
    "caution": "Sản phẩm {name} có vài điểm cần lưu ý với hồ sơ da của bạn.",
    "avoid": "Sản phẩm {name} không phù hợp với hồ sơ da của bạn.",
    "neutral": "Mình không thấy điểm cần lưu ý nào của {name} với hồ sơ da của bạn.",
    "unknown": "Bạn hãy cho mình biết loại da để mình đánh giá {name} có hợp hay không nhé.",
}
_LOW_DATA = " Dữ liệu thành phần còn ít nên mình chưa đủ cơ sở để khẳng định."


def render(result: Mapping) -> str:
    status = str(result.get("status") or "")
    if status in _STATUS_TEXT:
        return _STATUS_TEXT[status]
    name = str((result.get("product") or {}).get("name") or "").strip()
    name = f"“{name}”" if name else "này"
    fit = (result.get("skin_fit") or {}).get("verdict") or "unknown"
    text = _FIT_TEXT.get(fit, _FIT_TEXT["unknown"]).format(name=name)
    if "low_recognition" in (result.get("warnings") or []) and fit in ("neutral", "good"):
        text += _LOW_DATA
    high = [c for c in result.get("conflicts") or [] if c.get("severity") == "high"]
    if high:
        other = str(high[0].get("cart_product_name") or "").strip()
        target = f"“{other}”" if other else "sản phẩm trong giỏ"
        text += f" Đừng dùng cùng buổi với {target} nhé."
        if any(s.get("kind") == "split_session" for s in result.get("schedule") or []):
            text += " Bạn nên tách ra sáng và tối."
    return text
