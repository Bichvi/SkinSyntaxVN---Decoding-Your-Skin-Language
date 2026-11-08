"""Autopilot query helpers: when a brief describes a SET of products, and how to relax a query that found nothing.

Pure functions; the catalog and vocabulary are passed in. ``process_agent_run`` records each relaxation as an
"adjusted" decision so autopilot never stops on a fixable query.
"""
from __future__ import annotations

import re
from typing import Any, Callable, Iterator

from agents.command_query import (
    COUNT_EXCLUDE_PATTERN, HOT_PATTERN, METRIC_LABELS, _auto_count, _extra_scope_terms, detect_categories,
)
from agents.intent_parser import _extract_explicit_ids, normalize_text

PLURAL_PATTERN = re.compile(r"\b(?:cac|may|nhung)\b")
SHELF_PATTERN = re.compile(r"\bcon han\b|\bhan (?:su )?dung\b|\bhan con\b|\bhet han\b")
CART_PATTERN = re.compile(r"\bbo gio\b|\bthem gio\b")
WINDOWED_RANKS = frozenset({"units", "revenue_low_conversion", "views_no_orders", "cart", "discount_slow", "growth"})
STRONG_FIELDS = {"origin_country", "manufacturing_country", "skin_type", "ingredient", "price"}
HARD_DROP_ORDER = ("price", "ingredient", "skin_type", "manufacturing_country", "origin_country", "brand")
NAME_REASONS = {"Tên xuất hiện trong lệnh", "Khớp mã sản phẩm"}


def set_query_signals(brief_text: str, vocab=None) -> str | None:
    """"strong" when the brief clearly describes a set of products; a bare category or brand stays a product name."""
    text = normalize_text(brief_text)
    if any(re.search(r"\d", value) for value in _extract_explicit_ids(text)):
        return None  # a digit-less "id" is just the word "mà"/"mã" followed by a word
    if SHELF_PATTERN.search(text):
        return "shelf"  # a shelf-life range never names one product
    categories = detect_categories(text)
    count = _auto_count(COUNT_EXCLUDE_PATTERN.sub(" ", text))
    if (
        (count and int(count.group(1)) >= 2) or re.search(r"\btop\s+\d", text) or PLURAL_PATTERN.search(text)
        or HOT_PATTERN.search(text) or CART_PATTERN.search(text) or SHELF_PATTERN.search(text) or len(categories) > 1
    ):
        return "strong"
    if vocab is not None:
        from agents.catalog_constraints import extract_constraints
        extracted = extract_constraints(brief_text, vocab, bare_country=True)
        if extracted["content_notes"] or any(
                c["field"] in STRONG_FIELDS or c["operator"] == "not_in" for c in extracted["constraints"]):
            return "strong"
    return None


def names_specific_products(resolution: dict[str, Any], signal: str | None = None) -> bool:
    """True when the brief literally names what the resolver found, so it stays on the product-name path."""
    if signal == "shelf":
        return False
    selected = resolution.get("selected") or []
    if not selected or resolution.get("ambiguous") or resolution.get("unresolved_queries"):
        return False
    return all(item.get("reason") in NAME_REASONS for item in selected)


def relaxation_steps(plan: dict[str, Any]) -> Iterator[tuple[str, dict[str, Any]]]:
    """Cumulative, ever looser versions of an empty query as (what was relaxed, plan). Exclusions are never dropped."""
    from agents.catalog_constraints import summarize_constraints
    current = {**plan, "keep_unsold": True}  # a relaxed query wants something, even a product that never sold
    rank, window = current["rank_by"], current.get("window_days")
    if rank in WINDOWED_RANKS and window:
        lifetime = "discount_slow" if rank == "discount_slow" else "units"
        current = {**current, "rank_by": lifetime, "window_days": None}
        yield (f"Không có số liệu {window} ngày khớp tiêu chí “{METRIC_LABELS[rank]}”; xếp theo doanh số toàn kỳ", current)
    constraints = list(current.get("constraints") or [])
    for field_name in HARD_DROP_ORDER:
        # Missing-data exclusions (no INCI list) are relaxable too; a brand exclusion never is.
        softened = [c for c in constraints if c["field"] == field_name and c["strength"] == "HARD"
                    and (c["operator"] != "not_in" or field_name == "ingredient")]
        if not softened:
            continue
        # HARD -> SOFT keeps the wish as a ranking boost instead of throwing it away.
        constraints = [{**c, "strength": "SOFT"} if c in softened else c for c in constraints]
        current = {**current, "constraints": constraints}
        yield ("Hạ điều kiện " + "; ".join(summarize_constraints(softened)).lower() + " thành ưu tiên", current)
    if current.get("brand") and current.get("brand_mode") == "only":
        yield (f"Bỏ điều kiện thương hiệu {current['brand']}", {**current, "brand": None, "brand_mode": None})
        current = {**current, "brand": None, "brand_mode": None}
    if current.get("name_terms"):
        terms = ", ".join(current["name_terms"])
        current = {**current, "name_terms": []}
        yield (f"Bỏ lọc theo tên sản phẩm chứa {terms}", current)
    if current.get("stock_min") is not None or current.get("stock_max") is not None or current.get("expiry_required"):
        current = {**current, "stock_min": None, "stock_max": None, "expiry_required": False,
                   "expiry_from": None, "expiry_to": None}
        yield ("Bỏ điều kiện tồn kho/hạn dùng", current)
    if current["rank_by"] not in {"units", "catalog"} or (current["rank_by"] == "units" and current.get("window_days")):
        current = {**current, "rank_by": "units", "window_days": None}
        yield ("Xếp theo sản phẩm bán chạy nhất toàn kỳ", current)
    if current.get("category") or current.get("categories") or current.get("name_terms"):
        current = {**current, "category": None, "categories": [], "name_terms": []}
        yield ("Bỏ lọc danh mục; lấy sản phẩm bán chạy nhất trong toàn bộ catalog", current)
    if current["rank_by"] != "catalog":
        current = {**current, "rank_by": "catalog", "window_days": None}
        yield ("Catalog chưa có doanh số; lấy theo mã sản phẩm", current)


def relax_empty_query(
    plan: dict[str, Any],
    run_query: Callable[[dict[str, Any]], dict[str, Any]],
) -> tuple[dict[str, Any], dict[str, Any], list[str]] | None:
    """First looser plan that finds products: (plan, query result, what was relaxed so far), else None."""
    done: list[str] = []
    for message, relaxed in relaxation_steps(plan):
        done.append(message)
        result = run_query(relaxed)
        if result["selected"]:
            return relaxed, result, done
    return None
