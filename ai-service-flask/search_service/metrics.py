"""Smart-search metrics (Bước 08): pure aggregation over search logs and ``search_events``.

Sources (Mongo, read-only):
- ``tuong_tac_nguoi_dung`` ``{loai_tuong_tac:'search', created_at, metadata:{query, result_count?, live?}}``
  (PHP ``InteractionLogger::logSearch``; ``result_count``/``live`` exist since Bước 08 — older rows
  have no count and are left out of the zero-result rate; ``live=true`` = half-typed live-filter query).
- ``search_events`` ``{ts, q_norm, section, product_id, action, user_hash, session_hash[, chip]}``.
"""

from __future__ import annotations

import hashlib
import logging
import os
import unicodedata
from collections import Counter, defaultdict
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from typing import Any, Iterable

logger = logging.getLogger("search_service.metrics")

ACTIONS = ("impression", "click", "add_to_cart", "chip_click")
SECTIONS = ("category_alt", "same_brand", "personalized", "semantic", "trending", "best_sellers", "similar", "routine", "conflict")
# Search-result fallback blocks vs the product-page / cart blocks (their events carry the pseudo query
# ``similar:<id>`` / ``cart:analyze`` instead of a search): different funnels, so they get separate tables.
SEARCH_SECTIONS = ("category_alt", "same_brand", "personalized", "semantic", "trending", "best_sellers")
BLOCK_SECTIONS = ("similar", "routine", "conflict")

# U11: test traffic that must not reach any report. ``u03verify*`` = manual verification probes (U03);
# ``cart:analyze`` / ``similar:*`` are not searches (see above) so they never count as search queries;
# test accounts = ``khach_hang.is_test_account`` (U01), matched by ``ma_kh`` (search log) or ``user_hash`` (events).
TEST_QUERY_PREFIXES = ("u03verify",)
PSEUDO_QUERY_PREFIXES = ("cart:analyze", "similar:")


def fold(text: str) -> str:
    """lower + strip Vietnamese accents + collapse spaces (same as PHP SearchSkinProfile::fold)."""

    text = unicodedata.normalize("NFKD", str(text or "").lower().replace("đ", "d"))
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    return " ".join(text.split())


def is_test_query(q: Any) -> bool:
    return str(q or "").strip().lower().startswith(TEST_QUERY_PREFIXES)


def is_pseudo_query(q: Any) -> bool:
    return str(q or "").strip().lower().startswith(PSEUDO_QUERY_PREFIXES)


@dataclass
class TestTraffic:
    """Identifiers of the test accounts (U01 ``is_test_account``)."""

    __test__ = False    # not a pytest class

    user_ids: set[str] = field(default_factory=set)       # khach_hang.ma_kh as str
    user_hashes: set[str] = field(default_factory=set)    # sha256(lower(email)+salt), as PHP SearchEventTracker::userHash


def user_hash(email: str, salt: str) -> str:
    return hashlib.sha256((email.strip().lower() + salt).encode("utf-8")).hexdigest()


def load_test_traffic(db: Any, salt: str | None = None) -> TestTraffic:
    """Best effort: a missing collection / salt only means fewer exclusions (never fails a report)."""

    traffic = TestTraffic()
    try:
        salt = os.getenv("SEARCH_USER_HASH_SALT", "") if salt is None else salt
        for doc in db["khach_hang"].find({"is_test_account": True}, {"_id": 0, "ma_kh": 1, "email": 1}):
            if doc.get("ma_kh") is not None:
                traffic.user_ids.add(str(doc["ma_kh"]))
            if doc.get("email") and salt:
                traffic.user_hashes.add(user_hash(str(doc["email"]), salt))
    except Exception as exc:  # noqa: BLE001
        logger.warning("test-account lookup failed: %s", type(exc).__name__)
    return traffic


def filter_searches(docs: Iterable[dict[str, Any]], traffic: TestTraffic, dropped: Counter | None = None) -> list[dict[str, Any]]:
    """Search-log rows without test accounts / probe queries."""

    kept = []
    for doc in docs:
        if doc.get("ma_kh") is not None and str(doc["ma_kh"]) in traffic.user_ids:
            reason = "search_test_account"
        elif is_test_query((doc.get("metadata") or {}).get("query")):
            reason = "search_test_query"
        else:
            kept.append(doc)
            continue
        if dropped is not None:
            dropped[reason] += 1
    return kept


def filter_events(events: Iterable[dict[str, Any]], traffic: TestTraffic, dropped: Counter | None = None) -> list[dict[str, Any]]:
    """``search_events`` without test accounts / probe queries."""

    kept = []
    for ev in events:
        if ev.get("user_hash") and str(ev["user_hash"]) in traffic.user_hashes:
            reason = "event_test_account"
        elif is_test_query(ev.get("q_norm")):
            reason = "event_test_query"
        else:
            kept.append(ev)
            continue
        if dropped is not None:
            dropped[reason] += 1
    return kept


def _ratio(num: int, den: int) -> float | None:
    return round(num / den, 4) if den else None


def zero_result_stats(searches: Iterable[dict[str, Any]], top_n: int = 20) -> dict[str, Any]:
    total = counted = zero = live_skipped = 0
    zero_q: Counter = Counter()
    for doc in searches:
        meta = doc.get("metadata") or {}
        if meta.get("live") is True:
            live_skipped += 1
            continue
        total += 1
        count = meta.get("result_count")
        if isinstance(count, bool) or not isinstance(count, (int, float)):
            continue
        counted += 1
        if int(count) == 0:
            zero += 1
            q = fold(str(meta.get("query") or ""))[:100]
            if q:
                zero_q[q] += 1
    return {
        "searches": total,
        "searches_with_result_count": counted,
        "zero_result": zero,
        "zero_result_rate": _ratio(zero, counted),
        "live_partial_skipped": live_skipped,
        "top_zero_queries": sorted(zero_q.items(), key=lambda kv: (-kv[1], kv[0]))[:top_n],
    }


def section_stats(events: Iterable[dict[str, Any]]) -> dict[str, Any]:
    by_section: dict[str, Counter] = defaultdict(Counter)
    chips: Counter = Counter()
    sessions: set[str] = set()
    for ev in events:
        action = ev.get("action")
        if action not in ACTIONS:
            continue
        if ev.get("session_hash"):
            sessions.add(str(ev["session_hash"]))
        if action == "chip_click":
            chips[str(ev.get("chip") or "?")] += 1
            continue
        by_section[str(ev.get("section") or "?")][action] += 1
    rows = []
    totals: Counter = Counter()
    order = {key: i for i, key in enumerate(SECTIONS)}
    for section in sorted(by_section, key=lambda s: (order.get(s, 99), s)):
        c = by_section[section]
        totals.update(c)
        rows.append({
            "section": section, "impressions": c["impression"], "clicks": c["click"],
            "add_to_cart": c["add_to_cart"], "ctr": _ratio(c["click"], c["impression"]),
            "atc_rate": _ratio(c["add_to_cart"], c["impression"]),
        })
    rows.append({
        "section": "ALL", "impressions": totals["impression"], "clicks": totals["click"],
        "add_to_cart": totals["add_to_cart"], "ctr": _ratio(totals["click"], totals["impression"]),
        "atc_rate": _ratio(totals["add_to_cart"], totals["impression"]),
    })
    return {"sections": rows, "chip_clicks": dict(sorted(chips.items())), "sessions": len(sessions)}


def collect(db: Any, days: int, now: datetime | None = None, salt: str | None = None) -> dict[str, Any]:
    """Read-only queries for the last ``days`` days (naive UTC, like Mongo dates), test traffic filtered out."""

    since = (now or datetime.utcnow()) - timedelta(days=days)
    traffic = load_test_traffic(db, salt)
    dropped: Counter = Counter()
    searches = filter_searches(db["tuong_tac_nguoi_dung"].find(
        {"loai_tuong_tac": "search", "created_at": {"$gte": since}},
        {"_id": 0, "ma_kh": 1, "metadata.query": 1, "metadata.result_count": 1, "metadata.live": 1},
    ), traffic, dropped)
    events = filter_events(db["search_events"].find(
        {"ts": {"$gte": since}},
        {"_id": 0, "section": 1, "action": 1, "chip": 1, "session_hash": 1, "q_norm": 1, "user_hash": 1},
    ), traffic, dropped)
    search_events = [e for e in events if e.get("section") not in BLOCK_SECTIONS]
    block_events = [e for e in events if e.get("section") in BLOCK_SECTIONS]
    blocks = section_stats(block_events)
    blocks["sections"] = [r for r in blocks["sections"] if r["section"] != "ALL"]
    return {"since": since, "days": days, "zero": zero_result_stats(searches),
            "events": section_stats(search_events), "blocks": blocks,
            "excluded": {key: dropped.get(key, 0) for key in
                         ("search_test_account", "search_test_query", "event_test_account", "event_test_query")}}


# ---------------------------------------------------------------- A/B experiment report (U12)

Z95 = 1.959963984540054
MIN_IMPRESSIONS_PER_VARIANT = 1000


def wilson_interval(successes: int, n: int, z: float = Z95) -> tuple[float, float] | None:
    """Wilson score interval for a proportion (``successes`` is capped at ``n``). ``None`` when ``n`` is 0."""

    if n <= 0:
        return None
    p = min(max(successes, 0), n) / n
    denom = 1 + z * z / n
    centre = (p + z * z / (2 * n)) / denom
    half = z * ((p * (1 - p) / n + z * z / (4 * n * n)) ** 0.5) / denom
    return max(0.0, centre - half), min(1.0, centre + half)


def two_proportion_p_value(s1: int, n1: int, s2: int, n2: int) -> float | None:
    """Two-sided p-value of a pooled two-proportion z-test (``None`` when it cannot be computed)."""

    import math

    if n1 <= 0 or n2 <= 0:
        return None
    s1, s2 = min(max(s1, 0), n1), min(max(s2, 0), n2)
    pooled = (s1 + s2) / (n1 + n2)
    se = math.sqrt(pooled * (1 - pooled) * (1 / n1 + 1 / n2))
    if se == 0:
        return 1.0
    z = (s1 / n1 - s2 / n2) / se
    return math.erfc(abs(z) / math.sqrt(2))


def experiment_stats(events: Iterable[dict[str, Any]], experiment_id: str,
                     min_impressions: int = MIN_IMPRESSIONS_PER_VARIANT) -> dict[str, Any]:
    """Per-variant CTR / add-to-cart rate with Wilson 95% intervals for one experiment.

    ``impressions`` are section-level impression events (the same unit as the rest of the report), ``clicks`` and
    ``add_to_cart`` are product-level events of the same sections. Events must already be test-traffic filtered.
    """

    per_variant: dict[str, Counter] = defaultdict(Counter)
    sessions: dict[str, set[str]] = defaultdict(set)
    for ev in events:
        if ev.get("experiment") != experiment_id or ev.get("section") not in SEARCH_SECTIONS:
            continue
        action, variant = ev.get("action"), str(ev.get("variant") or "?")
        if action not in ("impression", "click", "add_to_cart"):
            continue
        per_variant[variant][action] += 1
        if ev.get("session_hash"):
            sessions[variant].add(str(ev["session_hash"]))
    rows = []
    for variant in sorted(per_variant, key=lambda v: (v != "control", v)):
        c = per_variant[variant]
        imp, clicks, atc = c["impression"], c["click"], c["add_to_cart"]
        row: dict[str, Any] = {
            "variant": variant, "impressions": imp, "clicks": clicks, "add_to_cart": atc,
            "sessions": len(sessions[variant]), "ctr": _ratio(clicks, imp), "atc_rate": _ratio(atc, imp),
            "ctr_ci95": wilson_interval(clicks, imp), "atc_ci95": wilson_interval(atc, imp),
            "enough_sample": imp >= min_impressions,
        }
        rows.append(row)
    control = next((r for r in rows if r["variant"] == "control"), None)
    comparisons = []
    for row in rows:
        if control is None or row is control:
            continue
        comparisons.append({
            "variant": row["variant"],
            "ctr_diff": None if row["ctr"] is None or control["ctr"] is None else round(row["ctr"] - control["ctr"], 4),
            "ctr_p_value": two_proportion_p_value(row["clicks"], row["impressions"], control["clicks"], control["impressions"]),
            "atc_diff": None if row["atc_rate"] is None or control["atc_rate"] is None
            else round(row["atc_rate"] - control["atc_rate"], 4),
            "atc_p_value": two_proportion_p_value(row["add_to_cart"], row["impressions"], control["add_to_cart"], control["impressions"]),
        })
    if not rows:
        recommendation = "Chưa có sự kiện nào gắn với thí nghiệm này (thí nghiệm chưa chạy hoặc chưa có lượt xem khối gợi ý)."
    elif len(rows) < 2 or any(not r["enough_sample"] for r in rows):
        short = ", ".join(f"{r['variant']}={r['impressions']}" for r in rows if not r["enough_sample"])
        recommendation = f"Chưa đủ mẫu: cần >= {min_impressions} impression mỗi nhánh (đang thiếu: {short or 'thiếu một nhánh'}). Chưa kết luận."
    else:
        significant = [c for c in comparisons if c["ctr_p_value"] is not None and c["ctr_p_value"] < 0.05]
        recommendation = ("Đủ mẫu. CTR khác biệt có ý nghĩa thống kê (p < 0,05): xem hướng chênh lệch trước khi áp dụng."
                          if significant else "Đủ mẫu nhưng CTR chưa khác biệt có ý nghĩa thống kê (p >= 0,05).")
    return {"experiment": experiment_id, "variants": rows, "comparisons": comparisons,
            "min_impressions_per_variant": min_impressions, "recommendation": recommendation,
            "enough_sample": bool(rows) and len(rows) >= 2 and all(r["enough_sample"] for r in rows)}


def collect_experiment(db: Any, experiment_id: str, days: int, now: datetime | None = None,
                       salt: str | None = None) -> dict[str, Any]:
    """Read-only: events tagged with ``experiment_id`` in the last ``days`` days, test traffic removed (U11 filters)."""

    since = (now or datetime.utcnow()) - timedelta(days=days)
    traffic = load_test_traffic(db, salt)
    dropped: Counter = Counter()
    events = filter_events(db["search_events"].find(
        {"ts": {"$gte": since}, "experiment": experiment_id},
        {"_id": 0, "section": 1, "action": 1, "experiment": 1, "variant": 1, "session_hash": 1,
         "q_norm": 1, "user_hash": 1},
    ), traffic, dropped)
    result = experiment_stats(events, experiment_id)
    result.update(since=since, days=days, excluded={k: int(v) for k, v in dropped.items()})
    return result
