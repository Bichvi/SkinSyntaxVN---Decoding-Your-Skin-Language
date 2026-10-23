"""Product doc -> LLM input / rule-tag comparison / sampling. Pure (the script does the I/O)."""

from __future__ import annotations

import random
from typing import Any, Mapping, Sequence

from graph_sync.curation import CURATED_SOURCES, FIELD_BY_GROUP
from .labeler import GROUPS, MAX_INGREDIENTS, ProductInput


def _tag_keys(entries: Sequence[Mapping[str, Any]] | None) -> list[str]:
    return [str(e["key"]) for e in entries or [] if e.get("key")]


def rule_tags_from_row(row: Mapping[str, Any]) -> dict[str, list[str]]:
    """Tags the ETL would give a product WITHOUT curated fields (feed ``to_graph_row`` a doc stripped of them)."""

    return {group: _tag_keys(row.get(group)) for group in GROUPS}


def strip_curation(doc: Mapping[str, Any]) -> dict[str, Any]:
    """Copy of the doc without curated tag fields, so ``to_graph_row`` yields the pure rule result."""

    drop = set(FIELD_BY_GROUP.values()) | {"tag_source", "tag_updated_at"}
    return {k: v for k, v in doc.items() if k not in drop}


def current_tags(doc: Mapping[str, Any]) -> dict[str, list[str]]:
    """The curated tags already stored on the product (empty when it has none)."""

    if str(doc.get("tag_source") or "") not in CURATED_SOURCES:
        return {group: [] for group in GROUPS}
    return {group: [str(k) for k in (doc.get(field) or [])] for group, field in FIELD_BY_GROUP.items()}


def product_input(doc: Mapping[str, Any], row: Mapping[str, Any]) -> ProductInput:
    """LLM input from the doc + its ETL row: name, category chain, first 30 ingredients, description."""

    ingredients = [str(i.get("inci") or i.get("name") or "").strip() for i in row.get("ingredients") or []]
    ingredients = [i for i in ingredients if i][:MAX_INGREDIENTS]
    if not ingredients:       # parser found nothing: fall back to the raw comma list
        raw = str(doc.get("thanh_phan_full") or doc.get("thanh_phan_sach") or "")
        ingredients = [p.strip() for p in raw.replace("\n", ",").split(",") if p.strip()][:MAX_INGREDIENTS]
    category = " > ".join(str(c.get("name") or "") for c in row.get("categories") or [] if c.get("name"))
    return ProductInput(
        ma_san_pham=doc.get("ma_san_pham"),
        name=str(doc.get("ten_san_pham") or ""),
        category=category or str(doc.get("danh_muc_day_du") or ""),
        ingredients=tuple(ingredients),
        description=str(doc.get("mo_ta") or ""),
    )


def choose_ids(candidates: Sequence[Any], limit: int | None, sample: str = "random", seed: int = 7) -> list[Any]:
    """``first``: ascending order; ``random``: deterministic sample for a given seed."""

    ordered = sorted(candidates, key=lambda v: (str(type(v).__name__), v if isinstance(v, (int, float)) else str(v)))
    if limit is None or limit >= len(ordered):
        return ordered
    if sample == "first":
        return ordered[:limit]
    picked = random.Random(seed).sample(ordered, limit)
    return sorted(picked, key=lambda v: (str(type(v).__name__), v if isinstance(v, (int, float)) else str(v)))


def estimate_cost_usd(usage: Mapping[str, int], price_in_per_m: float, price_out_per_m: float) -> float:
    return (usage.get("input_tokens", 0) * price_in_per_m + usage.get("output_tokens", 0) * price_out_per_m) / 1_000_000
