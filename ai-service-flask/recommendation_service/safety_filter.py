"""Hard ingredient, sellability, category, and routine-budget constraints."""

from __future__ import annotations

import logging
import re
import unicodedata
from dataclasses import dataclass, field
from typing import Any, Iterable

from .knowledge.ingredient_rules import ingredient_aliases

logger = logging.getLogger(__name__)


def _norm(value: object) -> str:
    text = unicodedata.normalize("NFKD", str(value or "").lower())
    return "".join(ch for ch in text if not unicodedata.combining(ch)).strip()


def _clean_avoid(values: Iterable[object]) -> list[str]:
    ignored = {"", "none", "khong co", "khong co / khong quan tam"}
    return [value for value in (_norm(item).strip() for item in values) if value not in ignored]


def _contains(text: str, value: str) -> bool:
    return value in _norm(text)


@dataclass
class SafetyDecision:
    candidates: list[dict[str, Any]] = field(default_factory=list)
    rejected: list[dict[str, str]] = field(default_factory=list)


class SafetyFilter:
    """Apply constraints before reranking or output construction."""

    NON_SKINCARE_ALIASES: tuple[str, ...] = (
        "trang diem",
        "makeup",
        "foundation",
        "kem nen",
        "phan nen",
        "cushion",
        "concealer",
        "son moi",
        "mascara",
        "eyeliner",
        "ma hong",
        "phan mat",
        "phan phu",
        "dau duong toc",
        "dau goi",
        "dau xa",
        "duong toc",
        "duong the",
        "body",
        "sua tam",
        "khu mui",
        "lan nach",
        "trang rang",
        "kem danh rang",
        "nuoc suc mieng",
        "nuoc hoa",
        "parfum",
    )

    @classmethod
    def _is_non_skincare(cls, product: dict[str, Any]) -> bool:
        text = _norm(
            " ".join(
                str(product.get(field) or "")
                for field in (
                    "category",
                    "loai_san_pham",
                    "danh_muc",
                    "danh_muc_day_du",
                    "name",
                    "ten_san_pham",
                )
            )
        )
        return any(alias in text for alias in cls.NON_SKINCARE_ALIASES)

    CATEGORY_ALIASES: dict[str, tuple[str, ...]] = {
        "cleanser": ("sua rua mat", "cleanser", "rua mat", "gel rua"),
        "toner": ("toner", "nuoc hoa hong", "nuoc can bang"),
        "treatment": ("serum", "tinh chat", "essence", "ampoule", "treatment"),
        "moisturizer": ("kem duong", "duong am", "moistur", "emulsion", "gel duong"),
        "sunscreen": ("chong nang", "sunscreen", "sunblock", "spf"),
    }

    def _avoid_hits(self, product: dict[str, Any], avoid: list[str]) -> list[str]:
        ingredients = " ".join(
            str(product.get(field) or "")
            for field in ("ingredients", "thanh_phan_chinh", "ingredients_full", "thanh_phan_day_du", "description")
        )
        aliases = ingredient_aliases(ingredients)
        hits: list[str] = []
        for requested in avoid:
            if requested in aliases or _contains(ingredients, requested):
                hits.append(requested)
        return hits

    def _category_matches(self, product: dict[str, Any], category: str) -> bool:
        aliases = self.CATEGORY_ALIASES.get(category, (category,))
        category_text = _norm(
            " ".join(
                str(product.get(field) or "")
                for field in ("category", "loai_san_pham", "danh_muc", "danh_muc_day_du")
            )
        )
        name_text = _norm(" ".join(str(product.get(field) or "") for field in ("name", "ten_san_pham")))
        if self._is_non_skincare(product):
            return False
        # A populated catalog category is authoritative. Do not let a claim in
        # the product name (e.g. "chống nắng") override "Trang điểm".
        if category_text:
            return any(alias in category_text for alias in aliases)
        return any(alias in name_text for alias in aliases)

    def filter_candidates(
        self,
        candidates: Iterable[dict[str, Any]],
        profile: Any,
        category: str = "",
        user_id: str = "",
    ) -> SafetyDecision:
        """Remove unsafe candidates and retain an auditable rejection reason."""

        if isinstance(profile, dict):
            avoid_values = profile.get("avoid_ingredients", [])
        else:
            avoid_values = getattr(profile, "avoid_ingredients", [])
        avoid = _clean_avoid(avoid_values or [])
        decision = SafetyDecision()
        for candidate in candidates:
            product = dict(candidate)
            product_id = str(product.get("id") or product.get("product_id") or "")
            if not product_id:
                continue
            if product.get("stock_status") in {"hidden", "out_of_stock"}:
                decision.rejected.append({"product_id": product_id, "reason": "not_sellable"})
                continue
            if self._is_non_skincare(product):
                decision.rejected.append({"product_id": product_id, "reason": "category_mismatch:non_skincare"})
                continue
            if category and not self._category_matches(product, category):
                decision.rejected.append({"product_id": product_id, "reason": f"category_mismatch:{category}"})
                continue
            hits = self._avoid_hits(product, avoid)
            if hits:
                decision.rejected.append({"product_id": product_id, "reason": "avoid_ingredients:" + ",".join(hits)})
                continue
            product["avoid_flags"] = []
            decision.candidates.append(product)
        if decision.rejected:
            logger.info(
                "Safety filter removed candidates",
                extra={"user_id": user_id, "rejected": len(decision.rejected)},
            )
        return decision

    @staticmethod
    def select_with_budget(
        candidates_by_step: list[list[dict[str, Any]]],
        optional_steps: list[bool],
        budget: int | None,
    ) -> list[dict[str, Any] | None]:
        """Choose one item per step without exceeding the total routine budget.

        The search space is intentionally bounded by the caller to the top few
        reranked candidates, so this exact small knapsack beats ad-hoc per-item
        price filtering while remaining well under the latency target.
        """

        if not candidates_by_step:
            return []
        choices = [items[:6] for items in candidates_by_step]
        best: tuple[float, int, list[dict[str, Any] | None]] = (float("-inf"), 0, [])

        def visit(index: int, total: int, score: float, selected: list[dict[str, Any] | None]) -> None:
            nonlocal best
            if budget and total > budget:
                return
            if index == len(choices):
                required_count = sum(item is not None for item in selected)
                best_count = sum(item is not None for item in best[2])
                if score > best[0] or (score == best[0] and required_count > best_count):
                    best = (score, total, list(selected))
                return
            options = choices[index]
            if optional_steps[index] or not options:
                visit(index + 1, total, score, selected + [None])
            for item in options:
                item_score = float(item.get("rerank_score") or item.get("match_score") or item.get("rrf_score") or 0.0)
                price = int(item.get("price") or item.get("gia_ban") or 0)
                visit(index + 1, total + price, score + item_score, selected + [item])

        visit(0, 0, 0.0, [])
        return best[2] if best[2] else [None] * len(choices)
