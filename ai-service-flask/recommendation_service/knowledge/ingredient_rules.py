"""Conservative ingredient aliases and same-session conflict rules."""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass
from typing import Iterable


def _norm(value: object) -> str:
    text = unicodedata.normalize("NFKD", str(value or "").lower())
    return "".join(ch for ch in text if not unicodedata.combining(ch))


_ALIASES: dict[str, tuple[str, ...]] = {
    "retinol": ("retinol", "retinal", "retinoid", "vitamin a"),
    "bha": ("bha", "salicylic acid", "salicylic", "beta hydroxy acid"),
    "aha": ("aha", "alpha hydroxy acid", "glycolic acid", "lactic acid", "mandelic acid"),
    "benzoyl peroxide": ("benzoyl peroxide", "benzoyl peroxid"),
    "vitamin c": ("vitamin c", "vit c", "ascorbic acid", "l ascorbic", "ascorbyl"),
    "niacinamide": ("niacinamide", "nicotinamide", "vitamin b3"),
    "azelaic acid": ("azelaic acid", "azelaic"),
    "peptides": ("peptide", "peptides", "copper peptide"),
    "fragrance": ("fragrance", "parfum", "hương liệu", "huong lieu"),
    "denatured alcohol": ("alcohol denat", "denatured alcohol", "ethanol", "sd alcohol"),
}


@dataclass(frozen=True)
class IngredientConflict:
    first: str
    second: str
    resolution: str


CONFLICT_RULES: tuple[IngredientConflict, ...] = (
    IngredientConflict(
        "retinol",
        "aha",
        "Tách AHA và retinol sang các buổi khác nhau; bắt đầu treatment từ từ và dùng chống nắng.",
    ),
    IngredientConflict(
        "retinol",
        "bha",
        "Tách BHA và retinol sang các buổi khác nhau để giảm nguy cơ khô và kích ứng.",
    ),
    IngredientConflict(
        "retinol",
        "benzoyl peroxide",
        "Không dùng cùng buổi; ưu tiên một treatment mỗi buổi và theo dõi phản ứng da.",
    ),
)


def ingredient_aliases(value: object) -> set[str]:
    """Return canonical ingredient names detected in a product/profile string."""

    normalized = _norm(value)
    found: set[str] = set()
    for canonical, aliases in _ALIASES.items():
        if any(re.search(r"(?<!\w)" + re.escape(_norm(alias)) + r"(?!\w)", normalized) for alias in aliases):
            found.add(canonical)
    return found


def find_conflicts(items: Iterable[tuple[str, object]]) -> list[IngredientConflict]:
    """Find conflicts among ``(product_name, ingredients)`` in one routine."""

    detected: dict[str, list[str]] = {}
    for product_name, ingredients in items:
        for ingredient in ingredient_aliases(ingredients):
            detected.setdefault(ingredient, []).append(product_name)

    conflicts: list[IngredientConflict] = []
    for rule in CONFLICT_RULES:
        if detected.get(rule.first) and detected.get(rule.second):
            conflicts.append(rule)
    return conflicts
