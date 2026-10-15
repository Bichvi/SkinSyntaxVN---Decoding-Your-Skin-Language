"""Narration styles: how Syna should sound, chosen per campaign and fed into the script prompt."""
from __future__ import annotations

import re

from agents.intent_parser import normalize_text
from core.purpose import PRODUCT_VIDEO


# (key, label, speaks_price) in the fixed display order.
NARRATION_STYLES: tuple[tuple[str, str, bool], ...] = (
    ("cheerful", "Tươi vui, dễ thương", False),
    ("gentle", "Tư vấn nhẹ nhàng", False),
    ("expert", "Chuyên gia khoa học", False),
    ("closing", "Tập trung chốt sale", True),
    ("concise", "Ngắn gọn, nhanh", False),
    ("detailed", "Giải thích chi tiết", False),
    ("friendly", "Thân thiện như bạn bè", False),
    ("ingredients", "Nhấn mạnh thành phần", False),
    ("pricing", "Nhấn mạnh giá & ưu đãi", True),
)
# An evergreen product video must never speak a price, so these styles are dropped for it.
PRICE_STYLES: frozenset[str] = frozenset(key for key, _, speaks_price in NARRATION_STYLES if speaks_price)
# Choosing one of these removes the other (the one listed first wins).
_EXCLUSIVE = frozenset({"concise", "detailed"})

_LABELS = {key: label for key, label, _ in NARRATION_STYLES}
# Aliases are matched on accent-stripped text, so they carry no diacritics.
_ALIASES: dict[str, tuple[str, ...]] = {
    "cheerful": ("vui tuoi", "tuoi vui", "de thuong", "tuoi tan"),
    "gentle": ("nhe nhang", "tu van nhe"),
    "expert": ("chuyen gia", "khoa hoc"),
    "closing": ("chot sale", "chot don"),
    "concise": ("ngan gon", "nhanh gon"),
    "detailed": ("chi tiet",),
    "friendly": ("ban be", "than thien"),
    "ingredients": ("nhan manh thanh phan", "noi ky thanh phan"),
    "pricing": ("nhan manh gia", "uu dai", "khuyen mai"),
}
_ALIAS_PATTERNS = {
    key: re.compile(r"(?<![a-z0-9])(?:" + "|".join(re.escape(alias) for alias in aliases) + r")(?![a-z0-9])")
    for key, aliases in _ALIASES.items()
}


def normalize_styles(values, purpose: str) -> tuple[list[str], list[str]]:
    """Return (styles, dropped): known keys in first-seen order, minus what this purpose cannot say.

    Unknown keys and repeats vanish silently; `dropped` lists only valid styles that were removed
    on purpose (price talk in a product video, or the later of concise/detailed).
    """
    styles: list[str] = []
    dropped: list[str] = []
    if not isinstance(values, (list, tuple)):
        return styles, dropped
    seen: set[str] = set()
    for value in values:
        key = value.strip().lower() if isinstance(value, str) else ""
        if key not in _LABELS or key in seen:
            continue
        seen.add(key)
        if purpose == PRODUCT_VIDEO and key in PRICE_STYLES:
            dropped.append(key)
        elif key in _EXCLUSIVE and any(kept in _EXCLUSIVE for kept in styles):
            dropped.append(key)
        else:
            styles.append(key)
    return styles, dropped


def styles_from_text(text: str) -> list[str]:
    """Styles the admin asked for in free text, in NARRATION_STYLES order."""
    normalized = normalize_text(text)
    if not normalized:
        return []
    return [key for key, _, _ in NARRATION_STYLES if _ALIAS_PATTERNS[key].search(normalized)]


def style_instruction(styles: list[str]) -> str:
    """One Vietnamese prompt line for the chosen styles, or "" when there are none."""
    labels = [_LABELS[key].lower() for key in styles or [] if key in _LABELS]
    if not labels:
        return ""
    return "- Phong cách dẫn chuyện: " + ", ".join(labels) + "."
