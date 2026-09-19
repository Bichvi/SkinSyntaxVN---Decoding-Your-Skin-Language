"""Pure consultation money rules."""
from __future__ import annotations

from decimal import Decimal, InvalidOperation
import re


def budget_unspecified(text):
    unknown = list(re.finditer(r'(?:chưa\s+(?:chốt|biết|xác định)(?:\s+được)?\s+ngân sách|ngân sách\s+chưa\s+(?:chốt|biết|xác định))', text, re.I))
    bounds = list(re.finditer(r'(?:dưới|trên|không quá|tối đa|tối thiểu|từ)\s+\d', text, re.I))
    return bool(unknown and (not bounds or unknown[-1].start() > bounds[-1].start()))


def parse_budget(text: str) -> int | None:
    """Return a stated ceiling; no-limit/unknown have no numeric ceiling.

    Handles Vietnamese decimal/thousands separators and ranges. A lower bound
    ("trên") is not silently turned into a maximum.
    """
    t = (text or "").lower()
    if budget_unspecified(t):
        return None
    if "không giới hạn" in t or "khong gioi han" in t:
        return None
    if re.search(r"\b(?:trên|hơn|tối thiểu)\s+\d", t) and not re.search(r"dưới|tối đa|không quá|đến|tới", t):
        return None
    pattern = r"(?<![\w.,])(\d+(?:[.,]\d+)*)(?:\s*)(triệu|tr\b|k\b|nghìn|ngàn|vnđ|vnd|đồng|đ\b)?"
    amounts = []
    for m in re.finditer(pattern, t):
        raw, unit = m.group(1), m.group(2)
        if not unit and not re.fullmatch(r"\d{5,8}|\d{1,3}(?:[.,]\d{3})+", raw):
            continue
        try:
            if unit in ("triệu", "tr", "k", "nghìn", "ngàn"):
                number = Decimal(raw.replace(",", "."))
                factor = 1000000 if unit in ("triệu", "tr") else 1000
            else:
                number = Decimal(raw.replace(".", "").replace(",", ""))
                factor = 1
            value = int(number * factor)
            if value > 0:
                amounts.append(value)
        except InvalidOperation:
            continue
    return amounts[-1] if amounts else None


def price_bounds(text):
    """Integer VND bounds. Infer the shared unit in 'từ 300 đến 500k'."""
    if budget_unspecified(text) or re.search(r'không giới hạn|khong gioi han', text, re.I):
        return None, None
    minimum = None
    match = re.search(r'\b(trên|hơn|tối thiểu|từ)\s+(\d+(?:[.,]\d+)*)\s*(triệu|tr\b|k\b|nghìn|ngàn|vnđ|vnd|đồng|đ\b)?', text, re.I)
    if match:
        unit = match[3]
        if not unit and re.search(r'đến|tới', text[match.end():], re.I):
            shared = re.search(r'\d+(?:[.,]\d+)*\s*(triệu|tr\b|k\b|nghìn|ngàn|vnđ|vnd|đồng|đ\b)', text[match.end():], re.I)
            unit = shared[1] if shared else ''
        minimum = parse_budget(match[2] + (unit or ''))
        if minimum is not None and match[1].casefold() in ('trên', 'hơn'):
            minimum += 1
    return minimum, parse_budget(text)
