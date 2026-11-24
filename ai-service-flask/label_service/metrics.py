"""Business metrics of the label scan ("chụp bảng thành phần"): does it earn more than it costs? Mongo read-only.

Sources: ``label_scan_events`` (PHP LabelScanMetrics: ``scan`` and ``add_to_cart`` rows, anonymous hashes only),
``hoa_don`` + ``chi_tiet_hoa_don`` (orders joined by ``analytics_sid`` = the same hashed session id).
Pure functions take plain dicts/lists so they are testable without Mongo; :func:`collect` is the only IO.

Privacy: the events carry no image, ingredient text, product name/brand, e-mail, IP or user agent. This module never
reads or prints any of those.
"""

from __future__ import annotations

import os
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from typing import Any, Iterable, Mapping

from search_service.metrics import TestTraffic, load_test_traffic

EVENTS_COLLECTION = "label_scan_events"
OUTCOMES = ("ok", "not_label", "unreadable", "vision_unavailable", "unavailable")
PROVIDERS = ("groq", "gemini")
CANCELLED = {"cancelled", "canceled", "huy", "da huy", "đã hủy"}

# ASSUMPTIONS (USD per 1M tokens), NOT verified against the providers' current price lists. Override per provider
# with LABEL_PRICE_<PROVIDER>_IN_USD_PER_M / LABEL_PRICE_<PROVIDER>_OUT_USD_PER_M.
DEFAULT_PRICES: dict[str, dict[str, float]] = {
    "groq": {"in": 0.29, "out": 0.59},
    "gemini": {"in": 0.30, "out": 2.50},
}
DEFAULT_USD_VND = 26000.0


def prices_from_env(env: Mapping[str, str] | None = None) -> dict[str, dict[str, float]]:
    env = os.environ if env is None else env
    prices = {p: dict(v) for p, v in DEFAULT_PRICES.items()}
    for provider in prices:
        for side in ("in", "out"):
            raw = str(env.get(f"LABEL_PRICE_{provider.upper()}_{side.upper()}_USD_PER_M", "")).strip()
            try:
                if raw:
                    prices[provider][side] = max(0.0, float(raw))
            except ValueError:
                pass
    return prices


def usd_vnd_from_env(env: Mapping[str, str] | None = None) -> float:
    env = os.environ if env is None else env
    try:
        value = float(str(env.get("LABEL_USD_VND", "")).strip() or DEFAULT_USD_VND)
    except ValueError:
        value = DEFAULT_USD_VND
    return value if value > 0 else DEFAULT_USD_VND


def _ratio(num: float, den: float) -> float | None:
    return (num / den) if den else None


def _s(value: Any) -> str:
    return "" if value is None else str(value)


# ---------------------------------------------------------------- filtering

def filter_events(events: Iterable[Mapping[str, Any]], traffic: TestTraffic | None = None,
                  dropped: Counter | None = None) -> list[Mapping[str, Any]]:
    """Drop rows of test accounts (``khach_hang.is_test_account``, matched by ``user_hash``)."""
    kept = []
    for ev in events:
        if traffic is not None and ev.get("user_hash") and _s(ev["user_hash"]) in traffic.user_hashes:
            if dropped is not None:
                dropped["event_test_account"] += 1
            continue
        kept.append(ev)
    return kept


def is_cancelled(order: Mapping[str, Any]) -> bool:
    """Real values (HoaDon::normalizeOrderStatus): ``trang_thai_normalized == 'cancelled'``; legacy rows only have ``trang_thai``."""
    norm = _s(order.get("trang_thai_normalized")).strip().lower()
    if norm:
        return norm in CANCELLED
    return _s(order.get("trang_thai")).strip().lower() in CANCELLED


# ---------------------------------------------------------------- scans

def scan_stats(scans: Iterable[Mapping[str, Any]], atcs: Iterable[Mapping[str, Any]]) -> dict[str, Any]:
    scans, atcs = list(scans), list(atcs)
    outcomes = Counter(_s(s.get("outcome")) or "unavailable" for s in scans)
    inputs = Counter("text" if s.get("input") == "text" else "photo" for s in scans)
    verdicts = Counter(_s(s.get("skin_verdict")) or "unknown" for s in scans)
    sessions = {_s(s["session_hash"]) for s in scans if s.get("session_hash")}
    alt_sessions = {_s(s["session_hash"]) for s in scans if s.get("session_hash") and s.get("alternative_ids")}
    atc_by_kind: dict[str, dict[str, Any]] = {}
    for kind in ("alternative", "matched"):
        rows = [a for a in atcs if a.get("kind") == kind]
        atc_by_kind[kind] = {"count": len(rows),
                             "sessions": len({_s(a["session_hash"]) for a in rows if a.get("session_hash")})}
    atc_alt_sessions = {_s(a["session_hash"]) for a in atcs
                        if a.get("kind") == "alternative" and a.get("session_hash")} & alt_sessions
    return {
        "scans": len(scans),
        "outcomes": {o: outcomes.get(o, 0) for o in OUTCOMES},
        "inputs": {"photo": inputs.get("photo", 0), "text": inputs.get("text", 0)},
        "sessions": len(sessions),
        "with_conflict": sum(1 for s in scans if (s.get("conflicts_total") or 0) >= 1),
        "with_high_conflict": sum(1 for s in scans if (s.get("conflicts_high") or 0) >= 1),
        "skin_verdicts": dict(sorted(verdicts.items())),
        "with_alternatives": sum(1 for s in scans if s.get("alternative_ids")),
        "alt_sessions": len(alt_sessions),
        "atc": atc_by_kind,
        "atc_alt_sessions": len(atc_alt_sessions),
    }


# ---------------------------------------------------------------- orders

def attribute_orders(scans: Iterable[Mapping[str, Any]], atcs: Iterable[Mapping[str, Any]],
                     orders: Iterable[Mapping[str, Any]], lines: Iterable[Mapping[str, Any]],
                     *, now: datetime | None = None, test_user_ids: Iterable[str] = ()) -> dict[str, Any]:
    """Revenue of orders placed by scanning sessions after their first scan (cancelled orders excluded).

    * ``attributed``: lines whose product was added to cart through a label-scan ``add_to_cart`` event of that same
      session (and the order is not older than that add-to-cart): ``sum(don_gia * so_luong)``.
    * ``assisted``: ``tam_tinh`` of every such order, whatever it contains (upper bound, not causal).
    """
    first_scan: dict[str, datetime] = {}
    for s in scans:
        sid, ts = _s(s.get("session_hash")), s.get("ts")
        if sid and isinstance(ts, datetime) and (sid not in first_scan or ts < first_scan[sid]):
            first_scan[sid] = ts
    first_atc: dict[tuple[str, str], datetime] = {}
    for a in atcs:
        sid, pid, ts = _s(a.get("session_hash")), _s(a.get("product_id")), a.get("ts")
        if sid and pid and isinstance(ts, datetime) and ((sid, pid) not in first_atc or ts < first_atc[(sid, pid)]):
            first_atc[(sid, pid)] = ts
    by_order: dict[str, list[Mapping[str, Any]]] = defaultdict(list)
    for ln in lines:
        by_order[_s(ln.get("ma_hoa_don"))].append(ln)
    test_ids = {_s(x) for x in test_user_ids}

    assisted_orders = assisted_revenue = 0
    attributed_orders = attributed_lines = attributed_revenue = 0
    excluded = Counter()
    for o in orders:
        sid, ts = _s(o.get("analytics_sid")), o.get("ngay_dat")
        if sid not in first_scan or not isinstance(ts, datetime):
            continue
        if ts < first_scan[sid] or (now is not None and ts > now):
            excluded["before_first_scan"] += 1
            continue
        if o.get("ma_kh") is not None and _s(o["ma_kh"]) in test_ids:
            excluded["test_account"] += 1
            continue
        if is_cancelled(o):
            excluded["cancelled"] += 1
            continue
        assisted_orders += 1
        assisted_revenue += int(o.get("tam_tinh") or 0)
        hit = 0
        for ln in by_order.get(_s(o.get("ma_hoa_don")), []):
            atc_ts = first_atc.get((sid, _s(ln.get("ma_san_pham"))))
            if atc_ts is None or ts < atc_ts:
                continue
            hit += 1
            attributed_revenue += int(ln.get("don_gia") or 0) * int(ln.get("so_luong") or 0)
        attributed_lines += hit
        attributed_orders += 1 if hit else 0
    return {
        "assisted": {"orders": assisted_orders, "revenue": assisted_revenue},
        "attributed": {"orders": attributed_orders, "lines": attributed_lines, "revenue": attributed_revenue},
        "excluded": {k: excluded.get(k, 0) for k in ("before_first_scan", "test_account", "cancelled")},
    }


# ---------------------------------------------------------------- cost

def cost_stats(scans: Iterable[Mapping[str, Any]], prices: Mapping[str, Mapping[str, float]],
               usd_vnd: float = DEFAULT_USD_VND) -> dict[str, Any]:
    tokens: dict[str, dict[str, int]] = {p: {"prompt": 0, "completion": 0, "calls": 0, "failed": 0} for p in PROVIDERS}
    for s in scans:
        for u in s.get("usage") or []:
            if not isinstance(u, Mapping) or u.get("provider") not in tokens:
                continue
            t = tokens[u["provider"]]
            t["prompt"] += int(u.get("prompt_tokens") or 0)
            t["completion"] += int(u.get("completion_tokens") or 0)
            t["calls"] += 1
            t["failed"] += 0 if u.get("ok") else 1
    usd = 0.0
    by_provider = {}
    for provider, t in tokens.items():
        price = prices.get(provider) or {}
        cost = t["prompt"] / 1e6 * float(price.get("in", 0)) + t["completion"] / 1e6 * float(price.get("out", 0))
        by_provider[provider] = {**t, "cost_usd": cost}
        usd += cost
    return {"by_provider": by_provider, "cost_usd": usd, "cost_vnd": usd * usd_vnd, "usd_vnd": usd_vnd}


# ---------------------------------------------------------------- derived

def derive(scan: Mapping[str, Any], cost: Mapping[str, Any], orders: Mapping[str, Any], margin: float) -> dict[str, Any]:
    cost_vnd = float(cost["cost_vnd"])
    scans = int(scan["scans"])
    photo = int(scan["inputs"]["photo"])
    revenue = int(orders["attributed"]["revenue"])
    gross_profit = revenue * margin
    gp_per_order = _ratio(gross_profit, orders["attributed"]["orders"])
    cost_per_scan = _ratio(cost_vnd, scans)
    return {
        "cost_per_scan_vnd": cost_per_scan,
        "cost_per_photo_scan_vnd": _ratio(cost_vnd, photo),
        "atc_rate": _ratio(scan["atc_alt_sessions"], scan["alt_sessions"]),
        "attributed_gross_profit_vnd": gross_profit,
        "roi": _ratio(gross_profit, cost_vnd),           # None when there is no cost to divide by
        "breakeven_scans_per_order": (_ratio(gp_per_order, cost_per_scan)
                                      if gp_per_order is not None and cost_per_scan else None),
        "margin": margin,
    }


# ---------------------------------------------------------------- IO

_CHUNK = 500


def _chunks(items: list, size: int = _CHUNK):
    for i in range(0, len(items), size):
        yield items[i:i + size]


def collect(db: Any, days: int, prices: Mapping[str, Mapping[str, float]] | None = None, *,
            usd_vnd: float = DEFAULT_USD_VND, margin: float = 0.3, now: datetime | None = None,
            salt: str | None = None) -> dict[str, Any]:
    """Read-only queries for the last ``days`` days (naive UTC, like Mongo dates)."""
    now = now or datetime.utcnow()
    since = now - timedelta(days=days)
    prices = prices or prices_from_env()
    traffic = load_test_traffic(db, salt)
    dropped: Counter = Counter()
    events = filter_events(db[EVENTS_COLLECTION].find({"ts": {"$gte": since}}, {"_id": 0}), traffic, dropped)
    scans = [e for e in events if e.get("type") == "scan"]
    atcs = [e for e in events if e.get("type") == "add_to_cart"]
    sessions = sorted({_s(s["session_hash"]) for s in scans if s.get("session_hash")})
    orders: list[dict] = []
    for chunk in _chunks(sessions):
        orders.extend(db["hoa_don"].find(
            {"analytics_sid": {"$in": chunk}, "ngay_dat": {"$gte": since}},
            {"_id": 0, "ma_hoa_don": 1, "ma_kh": 1, "analytics_sid": 1, "ngay_dat": 1, "tam_tinh": 1,
             "trang_thai": 1, "trang_thai_normalized": 1}))
    ids = [o["ma_hoa_don"] for o in orders if o.get("ma_hoa_don") is not None]
    lines: list[dict] = []
    for chunk in _chunks(ids):
        lines.extend(db["chi_tiet_hoa_don"].find(
            {"ma_hoa_don": {"$in": chunk}}, {"_id": 0, "ma_hoa_don": 1, "ma_san_pham": 1, "don_gia": 1, "so_luong": 1}))
    scan = scan_stats(scans, atcs)
    cost = cost_stats(scans, prices, usd_vnd)
    order_stats = attribute_orders(scans, atcs, orders, lines, now=now, test_user_ids=traffic.user_ids)
    return {"since": since, "days": days, "scan": scan, "cost": cost, "orders": order_stats,
            "derived": derive(scan, cost, order_stats, margin), "prices": {p: dict(v) for p, v in prices.items()},
            "excluded": {"event_test_account": dropped.get("event_test_account", 0)}}
