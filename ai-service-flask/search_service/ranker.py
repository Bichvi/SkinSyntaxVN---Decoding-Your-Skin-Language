"""Pure ranking helpers: normalize, combine, cross-encoder blend, dedupe, cut."""

from __future__ import annotations

import math
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FutureTimeout
from dataclasses import dataclass, field
from typing import Callable, Iterable, Mapping

from . import config

GRAPH_BRANCHES = ("same_brand", "category_alt")
COMPONENT_OF = {
    "same_brand": "graph",
    "category_alt": "graph",
    "semantic": "semantic",
    "personalized": "personal",
    "trending": "popularity",
}


@dataclass(frozen=True)
class Candidate:
    product_id: str
    raw_score: float
    reason_code: str
    branch: str
    reason_params: tuple[str, ...] = field(default_factory=tuple)


@dataclass
class Ranked:
    product_id: str
    branch: str
    score: float
    reason_code: str
    reason_params: list[str]
    branch_norm: float


def normalize(candidates: list[Candidate]) -> dict[str, float]:
    """Min-max normalize raw scores of ONE branch to [0,1] (max per product)."""

    best: dict[str, float] = {}
    for cand in candidates:
        if cand.product_id not in best or cand.raw_score > best[cand.product_id]:
            best[cand.product_id] = float(cand.raw_score)
    if not best:
        return {}
    lo, hi = min(best.values()), max(best.values())
    if hi - lo <= 1e-12:
        return {pid: 1.0 for pid in best}
    return {pid: (value - lo) / (hi - lo) for pid, value in best.items()}


def popularity_scores(sold: Mapping[str, float], product_ids: Iterable[str]) -> dict[str, float]:
    """log1p(sold) min-max normalized over the given ids."""

    ids = list(dict.fromkeys(product_ids))
    if not ids:
        return {}
    logs = {pid: math.log1p(max(0.0, float(sold.get(pid, 0) or 0))) for pid in ids}
    hi = max(logs.values())
    if hi <= 0:
        return {pid: 0.0 for pid in ids}
    return {pid: value / hi for pid, value in logs.items()}


def bought_together_scores(counts: Mapping[str, float]) -> dict[str, float]:
    """CO_PURCHASED counts (cart -> candidate) -> log1p(count) / max, in [0,1]."""

    logs = {str(pid): math.log1p(max(0.0, float(n or 0))) for pid, n in counts.items()}
    hi = max(logs.values(), default=0.0)
    if hi <= 0:
        return {}
    return {pid: value / hi for pid, value in logs.items() if value > 0}


def combine(
    by_branch: Mapping[str, list[Candidate]],
    sold: Mapping[str, float] | None = None,
    in_stock: Mapping[str, bool] | None = None,
    weights: Mapping[str, float] | None = None,
    bought_together: Mapping[str, float] | None = None,
    w_cop: float = config.W_COP,
    curated: Mapping[str, bool] | None = None,
    w_curated: float = config.W_CURATED,
    ctr: Mapping[str, Mapping[str, float]] | None = None,
    w_ctr: float = config.W_CTR,
) -> list[Ranked]:
    """Return one ``Ranked`` per (product, branch) with the product's combined score.

    ``bought_together`` (normalized, see ``bought_together_scores``) adds ``w_cop * value``; when
    that is the product's largest score component the reason becomes ``bought_together``.
    ``curated`` (product id -> True when its tags are ``manual``/``llm_approved``) adds the flat
    ``w_curated`` (U07); it never changes the reason.
    ``ctr`` (U12, ``{section: {product_id: ctr_norm in [0,1]}}``, only products with enough impressions, see
    ``search_service.ctr``) adds ``w_ctr * (ctr_norm - 0.5)`` to that product's score IN THAT SECTION; the median product
    gets 0, so the boost is symmetric. Never changes the reason.
    """

    weights = weights or config.WEIGHTS
    sold = sold or {}
    in_stock = in_stock or {}
    bought_together = bought_together or {}
    curated = curated or {}
    ctr = ctr or {}
    norms = {branch: normalize(cands) for branch, cands in by_branch.items()}
    all_ids = [pid for branch_norm in norms.values() for pid in branch_norm]
    popularity = popularity_scores(sold, all_ids)

    product_score: dict[str, float] = {}
    cop_led: set[str] = set()
    for pid in dict.fromkeys(all_ids):
        graph = max((norms.get(b, {}).get(pid, 0.0) for b in GRAPH_BRANCHES), default=0.0)
        parts = (
            weights.get("graph", 0) * graph,
            weights.get("semantic", 0) * norms.get("semantic", {}).get(pid, 0.0),
            weights.get("personal", 0) * norms.get("personalized", {}).get(pid, 0.0),
            weights.get("popularity", 0) * popularity.get(pid, 0.0),
        )
        cop = w_cop * float(bought_together.get(pid, 0.0))
        score = sum(parts) + cop + (w_curated if curated.get(pid) else 0.0)
        if cop > 0 and cop > max(parts):
            cop_led.add(pid)
        if in_stock.get(pid, True) is False:
            score -= config.OUT_OF_STOCK_PENALTY
        product_score[pid] = score

    ranked: list[Ranked] = []
    for branch, cands in by_branch.items():
        seen: set[str] = set()
        for cand in cands:
            if cand.product_id in seen:
                continue
            seen.add(cand.product_id)
            led = cand.product_id in cop_led
            ctr_norm = ctr.get(branch, {}).get(cand.product_id)
            boost = w_ctr * (float(ctr_norm) - 0.5) if ctr_norm is not None and w_ctr else 0.0
            ranked.append(
                Ranked(
                    product_id=cand.product_id,
                    branch=branch,
                    score=product_score[cand.product_id] + boost,
                    reason_code="bought_together" if led else cand.reason_code,
                    reason_params=[] if led else [str(p) for p in cand.reason_params][:3],
                    branch_norm=norms[branch].get(cand.product_id, 0.0),
                )
            )
    return ranked


def cross_encoder_blend(
    query: str,
    ranked: list[Ranked],
    texts: Mapping[str, str],
    score_pairs: Callable[[list[list[str]]], list[float]] | None,
    top_n: int = config.RERANK_TOP_N,
    budget_ms: int = config.RERANK_BUDGET_MS,
    executor: ThreadPoolExecutor | None = None,
) -> tuple[list[Ranked], bool]:
    """Blend ``final = 0.7*score + 0.3*sigmoid(ce)`` for the top-N products.

    Returns ``(ranked, applied)``. Missing scorer, error or budget overrun -> unchanged.
    """

    if score_pairs is None or not ranked:
        return ranked, False
    unique: list[str] = []
    for item in sorted(ranked, key=lambda r: -r.score):
        if item.product_id not in unique:
            unique.append(item.product_id)
        if len(unique) >= top_n:
            break
    pairs = [[query, texts.get(pid, pid)] for pid in unique]
    try:
        if executor is not None:
            raw = executor.submit(score_pairs, pairs).result(timeout=budget_ms / 1000)
        else:
            raw = score_pairs(pairs)
        ce = {pid: 1 / (1 + math.exp(-float(s))) for pid, s in zip(unique, raw)}
    except (FutureTimeout, Exception):
        return ranked, False
    for item in ranked:
        if item.product_id in ce:
            item.score = 0.7 * item.score + 0.3 * ce[item.product_id]
    return ranked, True


def dedupe(ranked: list[Ranked], exclude_ids: Iterable[str] = ()) -> list[Ranked]:
    """Keep each product only in the section where it is strongest; drop excluded ids.

    Strength = normalized branch score; ties go to the earlier section in SECTION_ORDER.
    """

    excluded = {str(x) for x in exclude_ids}
    order = {key: i for i, key in enumerate(config.SECTION_ORDER)}
    best: dict[str, Ranked] = {}
    for item in ranked:
        if item.product_id in excluded:
            continue
        current = best.get(item.product_id)
        if current is None or (item.branch_norm, -order.get(item.branch, 99)) > (
            current.branch_norm,
            -order.get(current.branch, 99),
        ):
            best[item.product_id] = item
    return list(best.values())


def cut(ranked: list[Ranked], limit_per_section: int) -> list[dict]:
    """Group by section, sort by score, cut, and order sections; empty sections dropped."""

    sections: list[dict] = []
    for key in config.SECTION_ORDER:
        items = sorted((r for r in ranked if r.branch == key), key=lambda r: (-r.score, r.product_id))
        items = items[: max(1, limit_per_section)]
        if not items:
            continue
        sections.append(
            {
                "key": key,
                "items": [
                    {
                        "product_id": r.product_id,
                        "score": round(r.score, 4),
                        "reason_code": r.reason_code,
                        "reason_params": r.reason_params[:3],
                    }
                    for r in items
                ],
            }
        )
    return sections
