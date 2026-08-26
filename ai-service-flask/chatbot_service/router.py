# -*- coding: utf-8 -*-
"""Heuristic routing helpers for chat auto mode."""

AGENT_SIGNALS = (
    " và ", " + ", " cộng ", " kèm ",
    "dùng chung", "kết hợp", "xung đột", "có dùng được không", "dùng được không",
    "có kết hợp", "có thể dùng", "trộn",
    "routine", "chu trình", "bộ sản phẩm", "bước dưỡng",
    "tổng dưới", "tổng không quá", "ngân sách tổng",
    "retinol", "retinoid", "tretinoin", "aha", "bha", "vitamin c",
    "niacinamide", "peptide", "ceramide", "bakuchiol",
    "thành phần", "ingredient", "công thức", "chi tiết sản phẩm",
)


def should_use_agent(message: str, msg_data: dict | None = None) -> bool:
    from cart_analysis import is_cart_analysis_query

    if is_cart_analysis_query(message):
        return False

    m = message.lower()

    detail_keywords = (
        "thành phần", "ingredient", "cách dùng", "huong dan", "hướng dẫn",
        "chi tiết", "mô tả", "mo ta", "công thức", "tư vấn kỹ", "tu van ky",
    )
    multi_product_keywords = (
        "dùng chung", "kết hợp", "xung đột", "routine", "chu trình",
        "bộ sản phẩm", "bước dưỡng", "tổng dưới", "ngân sách tổng",
        "có dùng được không", "có kết hợp", "có thể dùng", "trộn",
    )

    if any(k in m for k in detail_keywords) and not any(k in m for k in multi_product_keywords):
        return False

    if ("thành phần" in m or "ingredient" in m) and (
        "cách dùng" in m or "chi tiết" in m or "tư vấn" in m
    ):
        return False

    return sum(1 for signal in AGENT_SIGNALS if signal in m) >= 2
