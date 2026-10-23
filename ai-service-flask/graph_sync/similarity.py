"""Product-to-product similarity (SIMILAR_TO). Pure, runs on transformed rows in RAM.

score(a,b) = 0.40·Jaccard(ingredients top-15)
           + 0.25·(same level-2 category ? 1 : same level-1 ? 0.5 : 0)
           + 0.15·Jaccard(benefits) + 0.10·Jaccard(skin_types) + 0.10·(same brand)
"""

from __future__ import annotations

import heapq
from collections import defaultdict
from dataclasses import dataclass, field
from typing import Any, Collection, Iterable, Mapping, Sequence

TOP_INGREDIENTS = 15
ACTIVE_ROLES = frozenset({"active", "uv_filter"})
W_INGREDIENTS, W_CATEGORY, W_BENEFITS, W_SKIN, W_BRAND = 0.40, 0.25, 0.15, 0.10, 0.10
# Ingredients present in more than this share of products (water, glycerin...) still count in
# the Jaccard but are not used to *generate* candidate pairs (keeps it far below O(n²)).
CANDIDATE_MAX_DF = 0.05
CANDIDATE_MIN_DF_CAP = 50


@dataclass(frozen=True)
class ProductFeatures:
    id: str
    ingredients: tuple[str, ...]                 # ranked keys
    actives: tuple[str, ...]                     # ranked keys with role active/uv_filter
    names: Mapping[str, str] = field(default_factory=dict)   # key -> display name
    cat1: str | None = None
    cat2: str | None = None
    brand: str | None = None
    benefits: frozenset[str] = frozenset()
    skin_types: frozenset[str] = frozenset()


def jaccard(a: set[str] | frozenset[str], b: set[str] | frozenset[str]) -> float:
    if not a and not b:
        return 0.0
    return len(a & b) / len(a | b)


def features_from_row(row: Mapping[str, Any]) -> ProductFeatures:
    ingredients = sorted(row.get("ingredients") or [], key=lambda i: i["rank"])
    by_level = {c["level"]: c["key"] for c in row.get("categories") or []}
    brand = (row.get("brand") or [None])[0]
    return ProductFeatures(
        id=str(row["id"]),
        ingredients=tuple(i["key"] for i in ingredients),
        actives=tuple(i["key"] for i in ingredients if i.get("role") in ACTIVE_ROLES),
        names={i["key"]: i.get("name") or i["key"] for i in ingredients},
        cat1=by_level.get(1),
        cat2=by_level.get(2),
        brand=brand["key"] if brand else None,
        benefits=frozenset(b["key"] for b in row.get("benefits") or []),
        skin_types=frozenset(s["key"] for s in row.get("skin_types") or []),
    )


def _top(p: ProductFeatures) -> frozenset[str]:
    return frozenset(p.ingredients[:TOP_INGREDIENTS])


def _category_score(a: ProductFeatures, b: ProductFeatures) -> float:
    if a.cat2 and a.cat2 == b.cat2:
        return 1.0
    if a.cat1 and a.cat1 == b.cat1:
        return 0.5
    return 0.0


def score_pair(a: ProductFeatures, b: ProductFeatures) -> float:
    return (
        W_INGREDIENTS * jaccard(_top(a), _top(b))
        + W_CATEGORY * _category_score(a, b)
        + W_BENEFITS * jaccard(a.benefits, b.benefits)
        + W_SKIN * jaccard(a.skin_types, b.skin_types)
        + W_BRAND * (1.0 if a.brand and a.brand == b.brand else 0.0)
    )


def _reason(a: ProductFeatures, b: ProductFeatures) -> tuple[str, list[str]]:
    b_actives = set(b.actives)
    shared = [k for k in a.actives if k in b_actives]
    if len(shared) >= 2:
        return "shared_ingredients", [a.names.get(k, k) for k in shared[:3]]
    if _category_score(a, b) > 0:
        return "same_category", []
    if a.brand and a.brand == b.brand:
        return "same_brand", []
    return "shared_ingredients", [a.names.get(k, k) for k in shared[:3]]


def candidate_pairs(products: Sequence[ProductFeatures],
                    restrict: "Collection[int] | None" = None) -> set[tuple[int, int]]:
    """Index pairs (i<j) sharing a not-too-common top-15 ingredient or the same category.

    ``restrict`` (U11 local similarity): keep only pairs with at least one end in this index set.
    """

    n = len(products)
    max_df = max(CANDIDATE_MIN_DF_CAP, int(CANDIDATE_MAX_DF * n))
    postings: dict[str, list[int]] = defaultdict(list)
    for idx, p in enumerate(products):
        for key in _top(p):
            postings["i:" + key].append(idx)
        group = p.cat2 or p.cat1
        if group:
            postings["c:" + group].append(idx)
    pairs: set[tuple[int, int]] = set()
    for token, members in postings.items():
        if token.startswith("i:") and len(members) > max_df:
            continue
        if restrict is not None:
            focus = [m for m in members if m in restrict]
            for a in focus:
                for b in members:
                    if a != b:
                        pairs.add((a, b) if a < b else (b, a))
            continue
        for x in range(len(members)):
            for y in range(x + 1, len(members)):
                pairs.add((members[x], members[y]))
    return pairs


def compute_similar(
    products: Iterable[ProductFeatures],
    top_k: int = 10,
    min_score: float = 0.2,
    only_sources: "Collection[str] | None" = None,
) -> list[dict[str, Any]]:
    """Directed edges ``source -> target`` (top ``top_k`` per source, score >= ``min_score``).

    Deterministic: ties broken by target id. Output sorted by source id then rank.
    ``only_sources`` (U11 local similarity): only compute/emit edges whose source id is in this set; the
    edges of those sources are identical to the full computation (top-k per source over all candidates).
    """

    items = sorted(products, key=lambda p: p.id)
    wanted: set[int] | None = None
    if only_sources is not None:
        ids = {str(s) for s in only_sources}
        wanted = {idx for idx, p in enumerate(items) if p.id in ids}
    heaps: dict[int, list[tuple[float, str, int]]] = defaultdict(list)

    def push(src: int, dst: int, score: float) -> None:
        if wanted is not None and src not in wanted:
            return
        # min-heap keyed by (score, inverted id order) so the worst candidate is popped first
        entry = (score, _neg_key(items[dst].id), dst)
        heap = heaps[src]
        if len(heap) < top_k:
            heapq.heappush(heap, entry)
        elif entry > heap[0]:
            heapq.heapreplace(heap, entry)

    for i, j in candidate_pairs(items, wanted):
        score = score_pair(items[i], items[j])
        if score + 1e-12 < min_score:
            continue
        push(i, j, score)
        push(j, i, score)

    edges: list[dict[str, Any]] = []
    for src in sorted(heaps, key=lambda s: items[s].id):
        ranked = sorted(heaps[src], key=lambda e: (-e[0], items[e[2]].id))
        for score, _, dst in ranked:
            reason, shared = _reason(items[src], items[dst])
            edges.append({
                "source": items[src].id,
                "target": items[dst].id,
                "score": round(score, 4),
                "reason": reason,
                "shared": shared,
            })
    return edges


def _neg_key(value: str) -> tuple[int, ...]:
    """Order so that, at equal score, a larger id compares *smaller* (dropped first)."""

    return tuple(-ord(ch) for ch in value) + (1,)
