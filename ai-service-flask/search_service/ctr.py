"""Click-through-rate boost for the recommend ranker (U12 part A).

Pipeline (nightly job ``ctr_boost``, 05:00, registered in ``workers/jobs.py``):

1. read ``search_events`` of the last 30 days (Mongo, read-only), test traffic removed with the SAME filters as the
   metrics report (``metrics.filter_events``: ``u03verify*`` probes and ``is_test_account`` customers);
2. per ``(section, product_id)`` count impressions and clicks. An impression event carries the ordered
   ``product_ids`` shown in that section, so every product gets one impression at position ``index + 1``.
   Events without ``product_ids`` (before U12 the beacon only said "section shown") cannot be attributed to a
   product and are ignored;
3. position normalisation: a click at rank k is less likely than at rank 1 purely because of where the card sits.
   Each impression adds ``position_exposure(k)`` of *effective exposure* (1 at rank 1, 0.5 at rank 2, ...), and the
   click-through rate is clicks per effective exposure (the maximum-likelihood rate when ``P(click) = exposure * quality``);
4. Bayesian smoothing with a Beta prior fitted from the section's own data:
   ``ctr = (clicks + alpha) / (exposure + alpha + beta)``, ``alpha = mean_ctr * s``, ``beta = (1 - mean_ctr) * s``,
   ``s`` by the method of moments over products with enough impressions (clamped to 5..200, 20 when too few);
5. ``ctr_norm`` = mid-rank percentile of the smoothed CTR among the section's eligible products (>= 50 impressions),
   so 0.5 is the median product and the boost ``w_ctr * (ctr_norm - 0.5)`` is symmetric;
6. Redis Hash ``search:ctr:v1:<section>`` (field product id, value ``"<ctr>|<ctr_norm>|<impressions>"``, TTL 2 days).

At request time ``CtrLookup.norms`` does one pipelined ``HMGET`` per section for the candidates; any Redis problem
returns nothing, i.e. no boost. The whole feature stays behind ``SEARCH_CTR_BOOST_ENABLED`` (default false).
"""

from __future__ import annotations

import bisect
import json
import logging
import math
from collections import Counter, defaultdict
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from typing import Any, Callable, Iterable, Mapping, Sequence

from shared.redis_kit import keys

from . import config, metrics

logger = logging.getLogger("search_service.ctr")

CTR_SECTIONS = metrics.SEARCH_SECTIONS
DEFAULT_PRIOR_STRENGTH = 20.0
MIN_PRIOR_STRENGTH, MAX_PRIOR_STRENGTH = 5.0, 200.0
MIN_PRIOR_PRODUCTS = 5          # fewer eligible products than this -> default prior strength
MEAN_CTR_FLOOR = 0.001          # a section without any click still gets a proper (weak) prior


def position_exposure(position: Any) -> float:
    """Examination probability of rank ``position`` relative to rank 1: ``1 / (1 + log2(position))``."""

    try:
        pos = float(position)
    except (TypeError, ValueError):
        return 1.0
    if not math.isfinite(pos) or pos < 1:
        return 1.0
    return 1.0 / (1.0 + math.log2(pos))


@dataclass
class Counts:
    impressions: int = 0         # raw impressions (what the "at least 50" rule and the gate talk about)
    exposure: float = 0.0        # position-weighted impressions
    clicks: int = 0


@dataclass
class Totals:
    """Gate figures: section-level events (one per rendered section), after test-traffic removal."""

    impression_events: int = 0
    product_impressions: int = 0
    clicks: int = 0
    add_to_cart: int = 0
    skipped_without_products: int = 0

    def gate(self, impressions: int = config.CTR_GATE_IMPRESSIONS, clicks: int = config.CTR_GATE_CLICKS) -> dict[str, Any]:
        return {"impressions": self.impression_events, "clicks": self.clicks, "required_impressions": impressions,
                "required_clicks": clicks, "met": self.impression_events >= impressions and self.clicks >= clicks}


@dataclass
class SectionScores:
    alpha: float
    beta: float
    mean_ctr: float
    products: dict[str, tuple[float, float, int]] = field(default_factory=dict)   # pid -> (ctr, ctr_norm, impressions)
    eligible: int = 0


def _product_ids(raw: Any) -> list[str]:
    if isinstance(raw, str):
        raw = raw.split(",")
    if not isinstance(raw, (list, tuple)):
        return []
    return [str(p).strip() for p in raw if str(p).strip()]


def aggregate(events: Iterable[Mapping[str, Any]], traffic: metrics.TestTraffic | None = None,
              dropped: Any = None) -> tuple[dict[tuple[str, str], Counts], Totals]:
    """Count impressions/clicks per ``(section, product_id)``; test traffic is filtered with the metrics filters."""

    rows = metrics.filter_events(events, traffic, dropped) if traffic is not None else list(events)
    counts: dict[tuple[str, str], Counts] = defaultdict(Counts)
    totals = Totals()
    for ev in rows:
        section, action = str(ev.get("section") or ""), ev.get("action")
        if section not in CTR_SECTIONS:
            continue
        if action == "impression":
            totals.impression_events += 1
            ids = _product_ids(ev.get("product_ids"))
            if not ids:
                totals.skipped_without_products += 1
            for index, pid in enumerate(ids):
                cell = counts[(section, pid)]
                cell.impressions += 1
                cell.exposure += position_exposure(index + 1)
                totals.product_impressions += 1
        elif action == "click" and ev.get("product_id"):
            counts[(section, str(ev["product_id"]))].clicks += 1
            totals.clicks += 1
        elif action == "add_to_cart":
            totals.add_to_cart += 1
    return dict(counts), totals


def smoothed_ctr(clicks: float, exposure: float, alpha: float, beta: float) -> float:
    """Posterior mean of a Beta(alpha, beta) prior after ``clicks`` out of ``exposure`` effective impressions."""

    return (clicks + alpha) / (max(0.0, exposure) + alpha + beta)


def fit_prior(rows: Sequence[Counts], min_impressions: int = config.CTR_MIN_IMPRESSIONS) -> tuple[float, float, float]:
    """Beta prior ``(alpha, beta, mean_ctr)`` of one section from its own products (method of moments)."""

    total_exposure = sum(r.exposure for r in rows)
    total_clicks = sum(r.clicks for r in rows)
    mean = total_clicks / total_exposure if total_exposure > 0 else 0.0
    mean = min(0.5, max(MEAN_CTR_FLOOR, mean))
    eligible = [r for r in rows if r.impressions >= min_impressions and r.exposure > 0]
    strength = DEFAULT_PRIOR_STRENGTH
    if len(eligible) >= MIN_PRIOR_PRODUCTS:
        rates = [r.clicks / r.exposure for r in eligible]
        weights = [r.exposure for r in eligible]
        weight_sum = sum(weights)
        centre = sum(w * x for w, x in zip(weights, rates)) / weight_sum
        observed_var = sum(w * (x - centre) ** 2 for w, x in zip(weights, rates)) / weight_sum
        sampling_var = sum(w * mean * (1 - mean) / r.exposure for w, r in zip(weights, eligible)) / weight_sum
        true_var = observed_var - sampling_var
        if true_var > 1e-12:
            strength = mean * (1 - mean) / true_var - 1.0
        else:
            strength = MAX_PRIOR_STRENGTH          # no real spread between products: trust the pooled mean
        strength = min(MAX_PRIOR_STRENGTH, max(MIN_PRIOR_STRENGTH, strength))
    return mean * strength, (1 - mean) * strength, mean


def build_section_scores(rows: Mapping[str, Counts], min_impressions: int = config.CTR_MIN_IMPRESSIONS) -> SectionScores:
    alpha, beta, mean = fit_prior(list(rows.values()), min_impressions)
    scores = SectionScores(alpha=alpha, beta=beta, mean_ctr=mean)
    ctr_of = {pid: smoothed_ctr(c.clicks, c.exposure, alpha, beta) for pid, c in rows.items()}
    eligible = sorted(ctr_of[pid] for pid, c in rows.items() if c.impressions >= min_impressions)
    scores.eligible = len(eligible)
    for pid, cell in rows.items():
        norm = 0.5
        if cell.impressions >= min_impressions and eligible:
            below = bisect.bisect_left(eligible, ctr_of[pid])
            equal = bisect.bisect_right(eligible, ctr_of[pid]) - below
            norm = (below + 0.5 * equal) / len(eligible)         # mid-rank percentile: ties share the middle
        scores.products[pid] = (ctr_of[pid], norm, cell.impressions)
    return scores


def compute(events: Iterable[Mapping[str, Any]], traffic: metrics.TestTraffic | None = None,
            min_impressions: int = config.CTR_MIN_IMPRESSIONS) -> tuple[dict[str, SectionScores], Totals, dict[str, int]]:
    """events -> per-section scores + gate totals + counts of removed test events."""

    dropped: Counter = Counter()
    counts, totals = aggregate(events, traffic, dropped)
    by_section: dict[str, dict[str, Counts]] = defaultdict(dict)
    for (section, pid), cell in counts.items():
        by_section[section][pid] = cell
    scores = {section: build_section_scores(rows, min_impressions) for section, rows in by_section.items()}
    return scores, totals, {k: int(v) for k, v in dropped.items()}


def encode_value(ctr: float, norm: float, impressions: int) -> str:
    return f"{ctr:.6f}|{norm:.4f}|{int(impressions)}"


def decode_value(raw: Any, min_impressions: int = config.CTR_MIN_IMPRESSIONS) -> float | None:
    """``"<ctr>|<norm>|<impressions>"`` -> ``ctr_norm``; ``None`` for malformed values or too few impressions."""

    try:
        _ctr, norm, impressions = str(raw).split("|")
        norm_f, imps = float(norm), int(impressions)
    except (TypeError, ValueError):
        return None
    if imps < min_impressions or not 0.0 <= norm_f <= 1.0:
        return None
    return norm_f


def publish(client: Any, scores: Mapping[str, SectionScores], ttl_s: int = config.CTR_HASH_TTL_S) -> dict[str, int]:
    """Replace the Redis hashes (one transaction per section: DEL + HSET + EXPIRE). Returns fields written per section."""

    written: dict[str, int] = {}
    meta: dict[str, str] = {}
    for section in CTR_SECTIONS:
        key = keys.search_ctr(section)
        data = scores.get(section)
        pipe = client.pipeline(transaction=True)
        pipe.delete(key)
        if data and data.products:
            mapping = {pid: encode_value(*vals) for pid, vals in data.products.items()}
            pipe.hset(key, mapping=mapping)
            pipe.expire(key, ttl_s)
            written[section] = len(mapping)
            meta[section] = json.dumps({"alpha": round(data.alpha, 4), "beta": round(data.beta, 4),
                                        "mean_ctr": round(data.mean_ctr, 6), "products": len(mapping),
                                        "eligible": data.eligible}, ensure_ascii=False)
        pipe.execute()
    pipe = client.pipeline(transaction=True)
    pipe.delete(keys.SEARCH_CTR_META)
    if meta:
        pipe.hset(keys.SEARCH_CTR_META, mapping=meta)
        pipe.expire(keys.SEARCH_CTR_META, ttl_s)
    pipe.execute()
    return written


def load_events(db: Any, days: int = config.CTR_WINDOW_DAYS, now: datetime | None = None) -> list[dict[str, Any]]:
    """Read-only: impression/click/add_to_cart events of the last ``days`` days (naive UTC like Mongo dates)."""

    since = (now or datetime.utcnow()) - timedelta(days=days)
    cursor = db["search_events"].find(
        {"ts": {"$gte": since}, "action": {"$in": ["impression", "click", "add_to_cart"]}},
        {"_id": 0, "section": 1, "action": 1, "product_id": 1, "product_ids": 1, "position": 1,
         "q_norm": 1, "user_hash": 1},
    )
    return list(cursor)


def run_job(db: Any, client: Any, *, days: int = config.CTR_WINDOW_DAYS, now: datetime | None = None,
            min_impressions: int = config.CTR_MIN_IMPRESSIONS, salt: str | None = None) -> dict[str, Any]:
    """The ``ctr_boost`` job body: events -> scores -> Redis. Returns the numbers recorded in the job status."""

    traffic = metrics.load_test_traffic(db, salt)
    events = load_events(db, days, now)
    scores, totals, dropped = compute(events, traffic, min_impressions)
    written = publish(client, scores) if client is not None else {}
    gate = totals.gate()
    detail: dict[str, Any] = {
        "window_days": days, "events_read": len(events), "excluded_test_events": dropped,
        "impression_events": totals.impression_events, "product_impressions": totals.product_impressions,
        "clicks": totals.clicks, "impressions_without_products": totals.skipped_without_products,
        "gate": gate, "flag_enabled": config.SEARCH_CTR_BOOST_ENABLED,
        "sections": {s: {"products": len(v.products), "eligible": v.eligible, "alpha": round(v.alpha, 3),
                         "beta": round(v.beta, 3), "mean_ctr": round(v.mean_ctr, 5)} for s, v in scores.items()},
        "redis_fields": written, "redis": "ok" if client is not None else "unavailable",
    }
    logger.info("ctr_boost: %d events, gate impressions=%d/%d clicks=%d/%d met=%s, flag=%s",
                len(events), gate["impressions"], gate["required_impressions"], gate["clicks"],
                gate["required_clicks"], gate["met"], config.SEARCH_CTR_BOOST_ENABLED)
    return detail


class CtrLookup:
    """Request-time reader: candidate ids per section -> ``{section: {product_id: ctr_norm}}`` (eligible products only)."""

    def __init__(self, redis_factory: Callable[[], Any] | None = None,
                 min_impressions: int = config.CTR_MIN_IMPRESSIONS) -> None:
        self.redis_factory = redis_factory
        self.min_impressions = min_impressions

    def _client(self) -> Any | None:
        if self.redis_factory is not None:
            return self.redis_factory()
        from shared.redis_kit import client as redis_client

        return redis_client.get_redis()

    def norms(self, wanted: Mapping[str, Sequence[str]]) -> dict[str, dict[str, float]]:
        sections = [(s, list(dict.fromkeys(ids))) for s, ids in wanted.items() if s in CTR_SECTIONS and ids]
        if not sections:
            return {}
        try:
            client = self._client()
            if client is None:
                return {}
            pipe = client.pipeline(transaction=False)
            for section, ids in sections:
                pipe.hmget(keys.search_ctr(section), ids)
            replies = pipe.execute()
        except Exception as exc:  # noqa: BLE001 - Redis trouble = no boost, never a failed search
            logger.warning("ctr lookup skipped: %s", type(exc).__name__)
            return {}
        out: dict[str, dict[str, float]] = {}
        for (section, ids), values in zip(sections, replies):
            found = {}
            for pid, raw in zip(ids, values or []):
                norm = decode_value(raw, self.min_impressions) if raw is not None else None
                if norm is not None:
                    found[pid] = norm
            if found:
                out[section] = found
        return out
