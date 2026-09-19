"""Pure consultation policy rules."""
from __future__ import annotations

from decimal import Decimal, InvalidOperation
from .ingredients import normalized_text, has_ingredient, usable_ingredients


def matches_metadata(metadata: dict, filters: dict | None) -> bool:
    """Same supported filter semantics for dense and lexical candidates."""
    if not filters:
        return True
    for field, condition in filters.items():
        if field == "$and":
            if not all(matches_metadata(metadata, c) for c in condition):
                return False
        elif field == "$or":
            if not any(matches_metadata(metadata, c) for c in condition):
                return False
        else:
            actual = metadata.get(field)
            for op, wanted in (condition.items() if isinstance(condition, dict) else [("$eq", condition)]):
                try:
                    valid = {"$eq": lambda: actual == wanted, "$ne": lambda: actual != wanted,
                             "$in": lambda: actual in wanted, "$nin": lambda: actual not in wanted,
                             "$lt": lambda: actual < wanted, "$lte": lambda: actual <= wanted,
                             "$gt": lambda: actual > wanted, "$gte": lambda: actual >= wanted}[op]()
                except (KeyError, TypeError):
                    valid = False
                if not valid:
                    return False
    return True


def eligible_products(docs, *, exclusions=(), budget=None, minimum_price=None, category=None, brand=None):
    selected, rejected, seen = [], [], set()
    for doc in docs:
        m = doc.metadata or {}
        pid = str(getattr(doc, "id", "") or m.get("id") or m.get("ma_san_pham") or "")
        key = pid.removeprefix("product_") or normalized_text(m.get("ten_san_pham"))
        if key in seen:
            continue
        seen.add(key)
        reason = None
        try:
            price = int(Decimal(str(m.get("gia_ban") or 0)))
        except (InvalidOperation, ValueError, OverflowError):
            price = 0
        if category and m.get("loai_san_pham") != category:
            reason = "category"
        elif brand and normalized_text(m.get("thuong_hieu")) != normalized_text(brand):
            reason = "brand"
        elif m.get("stock_status") in ("hidden", "out_of_stock", "inactive"):
            reason = "stock"
        elif budget is not None and (price <= 0 or price > budget):
            reason = "budget_or_unknown_price"
        elif minimum_price is not None and (price <= 0 or price < minimum_price):
            reason = "budget_or_unknown_price"
        full = usable_ingredients(m.get("thanh_phan_day_du") or m.get("inci"))
        ingredients = full or m.get("ingredients") or m.get("thanh_phan_chinh") or ""
        if exclusions and not reason:
            if any(has_ingredient(ingredients, name) for name in exclusions):
                reason = "excluded_ingredient"
            elif not (full or m.get("ingredients_complete") is True):
                reason = "ingredients_unverified"
        if reason:
            rejected.append({"product_id": key, "reason": reason})
        else:
            selected.append(doc)
    return selected, rejected
