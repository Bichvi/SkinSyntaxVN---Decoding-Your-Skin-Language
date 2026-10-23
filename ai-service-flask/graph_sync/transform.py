"""Mongo ``san_pham`` document -> graph row. Pure (no I/O)."""

from __future__ import annotations

import re
from collections import Counter, defaultdict
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any, Iterable, Mapping

from .curation import resolve_tags
from .ingredients import AliasEntry, IngredientRef, parse_ingredients
from .normalize import display_names, group_synonyms, normalize_key, slugify
from .rules import TagRule, infer_tags

# Copied from PHP SanPham::visibleProductFilter() / availableProductFilter()
# (backend/app/models/SanPham.php): Mongo filter {trang_thai: {$nin: [...]}} (exact match;
# a missing trang_thai counts as visible).
HIDDEN_STATUSES = ("inactive", "hidden", "tam_an", "taman", "disabled", "off", "0")
# PHP SanPham::getProductStock() field order.
STOCK_FIELDS = ("so_luong_ton_kho", "so_luong_ton", "ton_kho", "stock", "quantity")
UNKNOWN_SKIN_CODES = {"4"}
PATH_ROOT_SLUGS = {"suc_khoe_lam_dep"}
MAX_CATEGORY_LEVEL = 3


@dataclass(frozen=True)
class CategoryInfo:
    name: str
    level: int
    parent: str | None      # parent ma_danh_muc as string


@dataclass(frozen=True)
class Lookups:
    brands: Mapping[str, str] = field(default_factory=dict)            # ma_thuong_hieu -> ten
    categories: Mapping[str, CategoryInfo] = field(default_factory=dict)
    skin_types: Mapping[str, str] = field(default_factory=dict)        # ma_loai_da -> ten
    origins: Mapping[str, str] = field(default_factory=dict)           # ma_xuat_xu -> ten


def code(value: Any) -> str:
    """Mongo int/Int64/float/str code -> canonical string ('12.0' -> '12')."""

    if value is None:
        return ""
    if isinstance(value, bool):
        return ""
    if isinstance(value, float):
        return str(int(value)) if value.is_integer() else str(value)
    if isinstance(value, int):
        return str(int(value))
    return str(value).strip()


def is_visible(doc: Mapping[str, Any]) -> bool:
    return doc.get("trang_thai") not in HIDDEN_STATUSES


def in_stock(doc: Mapping[str, Any]) -> bool:
    """Mirror of PHP SanPham::isProductAvailable() stock part."""

    if str(doc.get("trang_thai_kho") or "").strip().lower() == "het_hang":
        return False
    for name in STOCK_FIELDS:
        value = doc.get(name)
        if value is not None and value != "":
            try:
                return int(float(value)) > 0
            except (TypeError, ValueError):
                return True
    return True


def _int_or_none(value: Any) -> int | None:
    try:
        return None if value in (None, "") else int(float(value))
    except (TypeError, ValueError):
        return None


def _float_or_none(value: Any) -> float | None:
    try:
        return None if value in (None, "") else float(value)
    except (TypeError, ValueError):
        return None


def _iso(value: Any) -> str | None:
    if isinstance(value, datetime):
        if value.tzinfo is not None:
            value = value.astimezone(timezone.utc).replace(tzinfo=None)
        return value.isoformat(timespec="seconds") + "Z"
    return str(value) if value not in (None, "") else None


def _category_chain(doc: Mapping[str, Any], lookups: Lookups, syn: Mapping[str, str]) -> tuple[list[dict[str, Any]], str | None]:
    """Root(level 1)->leaf list of {key,name,level} and the source used."""

    chain: list[dict[str, Any]] = []
    current = code(doc.get("ma_danh_muc"))
    seen: set[str] = set()
    while current and current in lookups.categories and current not in seen:
        seen.add(current)
        info = lookups.categories[current]
        chain.append({"key": normalize_key(info.name, syn), "name": info.name, "level": int(info.level)})
        current = info.parent or ""
    if chain:
        return list(reversed(chain)), "danh_muc"
    path = str(doc.get("danh_muc_day_du") or "")
    parts = [p.strip() for p in re.split(r"\s*-?>\s*", path) if p.strip()]
    parts = [p for p in parts if slugify(p) not in PATH_ROOT_SLUGS][:MAX_CATEGORY_LEVEL]
    if not parts:
        return [], None
    return [
        {"key": normalize_key(name, syn), "name": name, "level": level}
        for level, name in enumerate(parts, start=1)
    ], "path"


def _pick_ingredients(doc: Mapping[str, Any], aliases: Mapping[str, AliasEntry] | None) -> tuple[list[IngredientRef], str | None]:
    full = parse_ingredients(doc.get("thanh_phan_full"), aliases)
    if full:
        return full, "full"
    sach = parse_ingredients(doc.get("thanh_phan_sach"), aliases)
    return (sach, "sach") if sach else ([], None)


def _own_skin_types(doc: Mapping[str, Any], lookups: Lookups, syn: Mapping[str, str], known: set[str]) -> list[str]:
    skin_code = code(doc.get("ma_loai_da"))
    if skin_code in UNKNOWN_SKIN_CODES:
        return []
    name = lookups.skin_types.get(skin_code) or str(doc.get("loai_da") or "")
    if not name or slugify(name) == "unknown":
        return []
    key = normalize_key(name, syn)
    return [key] if key in known else []


def to_graph_row(
    doc: Mapping[str, Any],
    lookups: Lookups,
    *,
    aliases: Mapping[str, AliasEntry] | None = None,
    rules: Mapping[str, TagRule] | None = None,
) -> dict[str, Any] | None:
    """Return the load row for one product, or None when it has no ``ma_san_pham``."""

    pid = code(doc.get("ma_san_pham"))
    if not pid:
        return None
    category_syn = group_synonyms("categories")
    skin_syn = group_synonyms("skin_types")
    skin_names = display_names("skin_types")
    concern_names = display_names("concerns")
    benefit_names = display_names("benefits")

    brand_name = (lookups.brands.get(code(doc.get("ma_thuong_hieu"))) or "").strip()
    brand_key = slugify(brand_name)
    origin_name = (lookups.origins.get(code(doc.get("ma_xuat_xu"))) or "").strip()
    categories, category_source = _category_chain(doc, lookups, category_syn)
    refs, ingredient_source = _pick_ingredients(doc, aliases)
    own_skin = _own_skin_types(doc, lookups, skin_syn, set(skin_names))
    tags = infer_tags([r.key for r in refs], {"skin_types": own_skin}, rules)
    resolved = resolve_tags(
        tags, doc, {"benefits": benefit_names, "concerns": concern_names, "skin_types": skin_names},
    )

    return {
        "id": pid,
        "active": is_visible(doc),
        "props": {
            "name": str(doc.get("ten_san_pham") or "").strip(),
            "price": _int_or_none(doc.get("gia_ban")),
            "original_price": _int_or_none(doc.get("gia_thi_truong")),
            "sold": _int_or_none(doc.get("so_luong_da_ban")) or 0,
            "rating": _float_or_none(doc.get("diem_danh_gia")),
            "in_stock": in_stock(doc),
            "updated_at": _iso(doc.get("updated_at")),
        },
        "brand": [{"key": brand_key, "name": brand_name}] if brand_key else [],
        "categories": categories,
        "category_edges": [
            {"child": child["key"], "parent": parent["key"]}
            for parent, child in zip(categories, categories[1:])
            if child["key"] != parent["key"]
        ],
        "leaf_category": categories[-1]["key"] if categories else None,
        "category_source": category_source,
        "ingredients": [
            {"key": r.key, "inci": r.inci, "name": r.name, "name_vi": r.name_vi, "role": r.role, "rank": r.rank}
            for r in refs
        ],
        "ingredient_source": ingredient_source,
        "skin_types": [
            {"key": t["key"], "name": skin_names.get(t["key"], t["key"]), "source": t["source"]}
            for t in resolved["skin_types"]
        ],
        "concerns": [
            {"key": t["key"], "name": concern_names.get(t["key"], t["key"]), "source": t["source"]}
            for t in resolved["concerns"]
        ],
        "benefits": [
            {"key": t["key"], "name": benefit_names.get(t["key"], t["key"]), "source": t["source"]}
            for t in resolved["benefits"]
        ],
        "tag_source": resolved["tag_source"],
        "origin": [{"key": slugify(origin_name), "name": origin_name}] if slugify(origin_name) else [],
    }


def canonical_brand_names(rows: Iterable[Mapping[str, Any]]) -> dict[str, str]:
    """Brand key -> most common display spelling (ties: alphabetical) across rows."""

    counts: dict[str, Counter[str]] = defaultdict(Counter)
    for row in rows:
        for brand in row.get("brand") or []:
            counts[brand["key"]][brand["name"].strip()] += 1
    return {key: sorted(c.items(), key=lambda kv: (-kv[1], kv[0]))[0][0] for key, c in counts.items()}


def apply_brand_names(rows: Iterable[dict[str, Any]], names: Mapping[str, str]) -> None:
    for row in rows:
        for brand in row.get("brand") or []:
            brand["name"] = names.get(brand["key"], brand["name"])
