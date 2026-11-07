"""Smart-search metrics (Bước 08): pure aggregation over search logs and ``search_events``.

Sources (Mongo, read-only):
- ``tuong_tac_nguoi_dung`` ``{loai_tuong_tac:'search', created_at, metadata:{query, result_count?, live?}}``
  (PHP ``InteractionLogger::logSearch``; ``result_count``/``live`` exist since Bước 08 — older rows
  have no count and are left out of the zero-result rate; ``live=true`` = half-typed live-filter query).
- ``search_events`` ``{ts, q_norm, section, product_id, action, user_hash, session_hash[, chip]}``.
"""

from __future__ import annotations

import unicodedata
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from typing import Any, Iterable

ACTIONS = ("impression", "click", "add_to_cart", "chip_click")
SECTIONS = ("category_alt", "same_brand", "personalized", "semantic", "trending", "best_sellers")


def fold(text: str) -> str:
    """lower + strip Vietnamese accents + collapse spaces (same as PHP SearchSkinProfile::fold)."""

    text = unicodedata.normalize("NFKD", str(text or "").lower().replace("đ", "d"))
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    return " ".join(text.split())


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


def collect(db: Any, days: int, now: datetime | None = None) -> dict[str, Any]:
    """Read-only queries for the last ``days`` days (naive UTC, like Mongo dates)."""

    since = (now or datetime.utcnow()) - timedelta(days=days)
    searches = db["tuong_tac_nguoi_dung"].find(
        {"loai_tuong_tac": "search", "created_at": {"$gte": since}},
        {"_id": 0, "metadata.query": 1, "metadata.result_count": 1, "metadata.live": 1},
    )
    events = db["search_events"].find(
        {"ts": {"$gte": since}}, {"_id": 0, "section": 1, "action": 1, "chip": 1, "session_hash": 1},
    )
    return {"since": since, "days": days, "zero": zero_result_stats(searches), "events": section_stats(events)}
