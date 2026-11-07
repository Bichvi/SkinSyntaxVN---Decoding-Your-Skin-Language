"""Coverage statistics and the human-readable run report (counts only, no personal data)."""

from __future__ import annotations

from collections import Counter
from typing import Any, Iterable, Mapping, Sequence


def _pct(part: int, whole: int) -> float:
    return round(100.0 * part / whole, 1) if whole else 0.0


def coverage(rows: Sequence[Mapping[str, Any]]) -> dict[str, Any]:
    """Coverage over *active* rows plus the top unmapped ingredient names."""

    active = [r for r in rows if r.get("active")]
    n = len(active)
    skin_src = Counter((r["skin_types"][0]["source"] if r["skin_types"] else "none") for r in active)
    unmapped: Counter[str] = Counter()
    mapped_refs = total_refs = 0
    for r in active:
        for ing in r["ingredients"]:
            total_refs += 1
            if ing["inci"] is None:
                unmapped[ing["name"]] += 1
            else:
                mapped_refs += 1
    return {
        "products_read": len(rows),
        "products_active": n,
        "products_hidden": len(rows) - n,
        "pct_ingredients_ge1": _pct(sum(1 for r in active if r["ingredients"]), n),
        "pct_ingredients_ge3": _pct(sum(1 for r in active if len(r["ingredients"]) >= 3), n),
        "ingredient_source": dict(Counter(r["ingredient_source"] or "none" for r in active)),
        "pct_ingredient_refs_mapped": _pct(mapped_refs, total_refs),
        "pct_skin_type_data": _pct(skin_src.get("data", 0), n),
        "pct_skin_type_inferred": _pct(skin_src.get("inferred", 0), n),
        "pct_skin_type_none": _pct(skin_src.get("none", 0), n),
        "pct_benefits": _pct(sum(1 for r in active if r["benefits"]), n),
        "pct_concerns": _pct(sum(1 for r in active if r["concerns"]), n),
        "pct_brand": _pct(sum(1 for r in active if r["brand"]), n),
        "category_source": dict(Counter(r["category_source"] or "none" for r in active)),
        "pct_origin": _pct(sum(1 for r in active if r["origin"]), n),
        "top_unmapped": unmapped.most_common(30),
    }


def planned_counts(rows: Sequence[Mapping[str, Any]], helps: Iterable[Mapping[str, str]],
                   conflicts: int, similar_edges: int | None) -> dict[str, dict[str, int]]:
    """What a load of ``rows`` would create (dry-run)."""

    def distinct(field: str) -> set[str]:
        return {item["key"] for r in rows for item in r.get(field) or []}

    ingredients = distinct("ingredients")
    helps = [h for h in helps if h["ingredient"] in ingredients]
    nodes = {
        "Product": len(rows), "Brand": len(distinct("brand")), "Category": len(distinct("categories")),
        "Ingredient": len(ingredients), "SkinType": len(distinct("skin_types")),
        "Concern": len(distinct("concerns") | {h["concern"] for h in helps}),
        "Benefit": len(distinct("benefits")), "Origin": len(distinct("origin")),
    }
    rels = {
        "OF_BRAND": sum(len(r["brand"]) for r in rows),
        "IN_CATEGORY": sum(1 for r in rows if r["leaf_category"]),
        "CHILD_OF": len({(e["child"], e["parent"]) for r in rows for e in r["category_edges"]}),
        "CONTAINS": sum(len(r["ingredients"]) for r in rows),
        "SUITABLE_FOR": sum(len(r["skin_types"]) for r in rows),
        "TARGETS": sum(len(r["concerns"]) for r in rows),
        "HAS_BENEFIT": sum(len(r["benefits"]) for r in rows),
        "MADE_IN": sum(len(r["origin"]) for r in rows),
        "HELPS": len(helps),
        "CONFLICTS_WITH": conflicts,
    }
    if similar_edges is not None:
        rels["SIMILAR_TO"] = similar_edges
    return {"nodes": nodes, "relationships": rels}


def print_report(title: str, cov: Mapping[str, Any] | None, counts: Mapping[str, Mapping[str, int]] | None,
                 timings: Mapping[str, float], extra: Mapping[str, Any] | None = None) -> None:
    print(f"===== graph_sync report: {title} =====")
    if cov:
        print(f"products read: {cov['products_read']} (active {cov['products_active']}, hidden {cov['products_hidden']})")
        print(f"ingredients >=1: {cov['pct_ingredients_ge1']}%  >=3: {cov['pct_ingredients_ge3']}%  "
              f"source: {cov['ingredient_source']}  refs mapped to INCI: {cov['pct_ingredient_refs_mapped']}%")
        print(f"skin_type: data {cov['pct_skin_type_data']}%  inferred {cov['pct_skin_type_inferred']}%  "
              f"none {cov['pct_skin_type_none']}%")
        print(f"benefits: {cov['pct_benefits']}%  concerns: {cov['pct_concerns']}%  brand: {cov['pct_brand']}%  "
              f"origin: {cov['pct_origin']}%  category source: {cov['category_source']}")
    if counts:
        for section in ("nodes", "relationships", "active_products"):
            if section in counts:
                print(f"{section}: " + ", ".join(f"{k}={v}" for k, v in sorted(counts[section].items())))
    for key, value in (extra or {}).items():
        print(f"{key}: {value}")
    if cov:
        print("top 30 unmapped ingredients (name: products):")
        for name, count in cov["top_unmapped"]:
            print(f"  {count:5d}  {name}")
    print("timings (s): " + ", ".join(f"{k}={v:.2f}" for k, v in timings.items()))
