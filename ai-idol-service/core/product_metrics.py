"""Daily product metrics snapshots for the AI Idol command query.

Writes ``product_metrics_snapshots`` in exactly the shape ``agents.command_query``
reads: ``as_of`` is an ISO date *string*, ``window_days`` an int, and a field is
``None`` whenever it could not be measured. A missing number is never turned
into 0: zero is only emitted when the source is proven complete (the order
ledger, or continuous session tracking over the whole window).

Indexes are created only by the explicit ``ensure-indexes`` CLI command.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import uuid
from collections import Counter, defaultdict
from zoneinfo import ZoneInfo

from pymongo import ASCENDING, UpdateOne

from agents.command_query import _product_id, number
from agents.intent_parser import normalize_text
from agents.query_sources import bounded_rows
from config import PRODUCT_EVENTS_RETENTION_DAYS, TIMEZONE, TRACKING_STARTED_ON, logger
from core.state_machine import get_db, utcnow

METRIC_VERSION = 1
WINDOWS = (7, 30)
MAX_BACKFILL_DAYS = 60
CATCHUP_DAYS = 7
EVENT_TYPES = ("product_view", "add_to_cart")
MAX_CATALOG = 20000
MAX_ORDERS = 200000
MAX_EVENTS = 500000

_NUMERIC_ID = re.compile(r"^\d+(?:\.0+)?$")
_PLAIN_ID = re.compile(r"^[A-Za-z0-9_-]{1,40}$")


def canonical_product_id(value) -> str | None:
    if value is None or isinstance(value, bool):
        return None
    if isinstance(value, int):
        return str(int(value))
    if isinstance(value, float):
        return str(int(value)) if value == int(value) else None
    if isinstance(value, str):
        text = value.strip()
        if _NUMERIC_ID.match(text):
            return str(int(text.split(".")[0]))
        if _PLAIN_ID.match(text):
            return text
    return None


def to_utc(value) -> dt.datetime | None:
    if isinstance(value, dt.datetime):
        return value.replace(tzinfo=dt.timezone.utc) if value.tzinfo is None else value.astimezone(dt.timezone.utc)
    if isinstance(value, str):
        try:
            parsed = dt.datetime.fromisoformat(value.strip())
        except ValueError:
            return None
        if parsed.tzinfo is None:  # PHP stores local wall-clock strings
            parsed = parsed.replace(tzinfo=ZoneInfo(TIMEZONE))
        return parsed.astimezone(dt.timezone.utc)
    return None


def local_midnight_utc(day: dt.date) -> dt.datetime:
    return dt.datetime(day.year, day.month, day.day, tzinfo=ZoneInfo(TIMEZONE)).astimezone(dt.timezone.utc)


def _local_day(moment: dt.datetime) -> dt.date:
    return moment.astimezone(ZoneInfo(TIMEZONE)).date()


def order_status(order: dict) -> str:
    s = normalize_text(order.get("trang_thai") or order.get("trang_thai_normalized") or "").replace("_", " ").replace("-", " ")
    if s in {"hoan thanh", "completed"}:
        return "completed"
    if s in {"da huy", "huy", "cancelled", "canceled"}:
        return "cancelled"
    return s


def is_paid(order: dict) -> bool:
    return normalize_text(order.get("status_thanh_toan") or order.get("payment_status") or "") in {
        "da thanh toan", "paid", "thanh cong", "completed"}


def is_revenue_order(order: dict) -> bool:
    """Mirror of QuanTri::isRevenueOrder."""
    if order_status(order) != "completed":
        return False
    method = str(order.get("hinh_thuc_thanh_toan") or "cod").lower()
    if "qr" in method or "transfer" in method:
        return is_paid(order)
    return True


def revenue_time(order: dict) -> dt.datetime | None:
    for field in ("ngay_hoan_thanh", "thoi_gian_hoan_thanh", "ngay_dat", "created_at"):
        moment = to_utc(order.get(field))
        if moment is not None:
            return moment
    return None


def _order_key(order: dict) -> int | None:
    value = order.get("ma_hoa_don")
    if isinstance(value, bool):
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def aggregate_sales(orders, lines_by_order, alias, start, end, returned: set[str]) -> dict:
    units: Counter = Counter()
    revenue: defaultdict = defaultdict(float)
    bad: set[str] = set()
    global_bad = False
    for order in orders:
        if not is_revenue_order(order):
            continue
        moment = revenue_time(order)
        if moment is None:
            global_bad = True
            continue
        if not start <= moment < end:
            continue
        key = _order_key(order)
        lines = lines_by_order.get(key, []) if key is not None else []
        if key is None:
            global_bad = True
            continue
        total = number(order.get("tong_tien"))
        shipping = number(order.get("phi_van_chuyen") or 0)
        if total is None or shipping is None:
            for line in lines:
                pid = alias.get(canonical_product_id(line.get("ma_san_pham")))
                if pid is not None:
                    bad.add(pid)
            continue
        base = total - shipping
        valid = []
        for line in lines:
            pid = alias.get(canonical_product_id(line.get("ma_san_pham")))
            if pid is None:
                continue  # product no longer in the catalog
            qty, price = number(line.get("so_luong")), number(line.get("don_gia"))
            if qty is None or price is None or qty <= 0 or qty != int(qty):
                bad.add(pid)
                continue
            valid.append((pid, int(qty), price))
        gross = sum(qty * price for _, qty, price in valid)
        factor = 0 if gross == 0 else min(1, max(0, base / gross))
        for pid, qty, price in valid:
            units[pid] += qty
            revenue[pid] += qty * price * factor
    return {"units": units, "revenue": revenue, "bad": bad | set(returned), "global_bad": global_bad}


def aggregate_sessions(events, orders, lines_by_order, alias, start, end) -> dict:
    first_view: dict[tuple[str, str], dt.datetime] = {}
    carted: set[tuple[str, str]] = set()
    view_days: set[dt.date] = set()
    for event in events:
        moment = to_utc(event.get("event_time"))
        sid = event.get("sid")
        if moment is None or not sid or not start <= moment < end:
            continue
        kind = event.get("type")
        pid = alias.get(canonical_product_id(event.get("product_id")))
        if kind == "product_view":
            view_days.add(_local_day(moment))
            if pid is not None:
                current = first_view.get((pid, sid))
                first_view[(pid, sid)] = moment if current is None else min(current, moment)
        elif kind == "add_to_cart" and pid is not None:
            carted.add((pid, sid))
    converted: set[tuple[str, str]] = set()
    global_bad = False
    for order in orders:
        sid = order.get("analytics_sid")
        if not sid or order_status(order) == "cancelled":
            continue
        moment = to_utc(order.get("ngay_dat"))
        if moment is None:
            global_bad = True
            continue
        if not start <= moment < end:
            continue
        key = _order_key(order)
        for line in lines_by_order.get(key, []) if key is not None else []:
            pid = alias.get(canonical_product_id(line.get("ma_san_pham")))
            if pid is not None and (pid, sid) in first_view and moment >= first_view[(pid, sid)]:
                converted.add((pid, sid))

    def count(pairs) -> Counter:
        result: Counter = Counter()
        for pid, _ in pairs:
            result[pid] += 1
        return result

    return {"viewed": count(first_view), "converted": count(converted), "carted": count(carted),
            "view_days": view_days, "global_bad": global_bad}


def sessions_reasons(as_of, window_days, *, tracking_started_on, retention_days, today, view_days, global_bad) -> list[str]:
    start_day = as_of - dt.timedelta(days=window_days)
    reasons = []
    if tracking_started_on is None:
        reasons.append("tracking_unconfigured")
    elif start_day < tracking_started_on:
        reasons.append("tracking_not_started")
    if (today - start_day).days >= retention_days:
        reasons.append("outside_retention")
    if any(start_day + dt.timedelta(days=i) not in view_days for i in range((as_of - start_day).days)):
        reasons.append("tracking_gap")
    if global_bad:
        reasons.append("order_time_invalid")
    return reasons


def compute_snapshot_docs(as_of, *, catalog, orders, lines, events, returns, tracking_started_on,
                          retention_days, today, run_id, built_at) -> list[dict]:
    """Pure computation: no I/O."""
    alias: dict[str | None, str] = {}
    keys: list[str] = []
    for row in catalog:
        key = _product_id(row)
        if not key:
            continue
        keys.append(key)
        for field in ("ma_san_pham", "id"):
            canonical = canonical_product_id(row.get(field))
            if canonical is not None:
                alias.setdefault(canonical, key)
    lines_by_order: dict[int, list] = defaultdict(list)
    for line in lines:
        try:
            lines_by_order[int(line.get("ma_hoa_don"))].append(line)
        except (TypeError, ValueError):
            continue
    mapped_returns = []
    for pid, occurred_at in returns:
        key = alias.get(canonical_product_id(pid))
        moment = to_utc(occurred_at)
        if key is not None and moment is not None:
            mapped_returns.append((key, moment))
    docs = []
    for window in WINDOWS:
        cur_start, cur_end = local_midnight_utc(as_of - dt.timedelta(days=window)), local_midnight_utc(as_of)
        prev_start = local_midnight_utc(as_of - dt.timedelta(days=2 * window))
        returned_cur = {k for k, m in mapped_returns if cur_start <= m < cur_end}
        returned_prev = {k for k, m in mapped_returns if prev_start <= m < cur_start}
        cur = aggregate_sales(orders, lines_by_order, alias, cur_start, cur_end, returned_cur)
        prev = aggregate_sales(orders, lines_by_order, alias, prev_start, cur_start, returned_prev)
        sess = aggregate_sessions(events, orders, lines_by_order, alias, cur_start, cur_end)
        session_reasons = sessions_reasons(
            as_of, window, tracking_started_on=tracking_started_on, retention_days=retention_days,
            today=today, view_days=sess["view_days"], global_bad=sess["global_bad"])
        for key in keys:
            sales_ok = not cur["global_bad"] and key not in cur["bad"]
            prev_ok = not prev["global_bad"] and key not in prev["bad"]
            reasons = set(session_reasons)
            if not sales_ok:
                reasons.add("order_ledger_invalid" if cur["global_bad"]
                            else "return_unreconciled" if key in returned_cur else "line_invalid")
            if not prev_ok:
                reasons.add("previous_order_ledger_invalid" if prev["global_bad"]
                            else "previous_return_unreconciled" if key in returned_prev else "previous_line_invalid")
            covered = not session_reasons
            docs.append({
                "product_id": key, "window_days": window, "as_of": as_of.isoformat(),
                "window_start": (as_of - dt.timedelta(days=window)).isoformat(),
                "coverage_complete": sales_ok,
                "units_sold": int(cur["units"][key]) if sales_ok else None,
                "net_revenue": int(round(cur["revenue"][key])) if sales_ok else None,
                "previous_units_sold": int(prev["units"][key]) if prev_ok else None,
                "viewed_sessions": int(sess["viewed"][key]) if covered else None,
                "converted_sessions": int(sess["converted"][key]) if covered else None,
                "cart_sessions": int(sess["carted"][key]) if covered else None,
                "coverage": {"sales": sales_ok, "previous_sales": prev_ok, "sessions": covered,
                             "reasons": sorted(reasons),
                             "tracking_started_on": tracking_started_on.isoformat() if tracking_started_on else None},
                "metric_version": METRIC_VERSION, "run_id": run_id, "built_at": built_at,
            })
    return docs


def _tracking_day() -> dt.date | None:
    if not TRACKING_STARTED_ON:
        return None
    try:
        return dt.date.fromisoformat(str(TRACKING_STARTED_ON))
    except ValueError:
        return None


def load_sources(db, as_of, today) -> dict:
    catalog = bounded_rows(db.san_pham.find({}, {"ma_san_pham": 1, "id": 1}), MAX_CATALOG, "Catalog")
    projection = {k: 1 for k in (
        "ma_hoa_don", "trang_thai", "trang_thai_normalized", "status_thanh_toan", "payment_status",
        "hinh_thuc_thanh_toan", "tong_tien", "phi_van_chuyen", "ngay_dat", "ngay_hoan_thanh",
        "thoi_gian_hoan_thanh", "created_at", "analytics_sid")}
    all_orders = bounded_rows(db.hoa_don.find({}, projection), MAX_ORDERS, "Đơn hàng")
    floor_60, floor_30 = local_midnight_utc(as_of - dt.timedelta(days=60)), local_midnight_utc(as_of - dt.timedelta(days=30))
    orders = []
    for order in all_orders:
        moment = revenue_time(order)
        placed = to_utc(order.get("ngay_dat"))
        if moment is None or moment >= floor_60 or (placed is not None and placed >= floor_30):
            orders.append(order)
    ids = sorted({k for k in (_order_key(o) for o in orders) if k is not None})
    lines: list[dict] = []
    for i in range(0, len(ids), 1000):
        lines.extend(db.chi_tiet_hoa_don.find(
            {"ma_hoa_don": {"$in": ids[i:i + 1000]}},
            {"ma_hoa_don": 1, "ma_san_pham": 1, "so_luong": 1, "don_gia": 1}))
    returns = [(canonical_product_id(m.get("product_id")), m.get("occurred_at")) for m in db.inventory_movements.find(
        {"type": "return", "occurred_at": {"$gte": floor_60, "$lt": local_midnight_utc(as_of)}},
        {"product_id": 1, "occurred_at": 1})]
    events: list[dict] = []
    started = _tracking_day()
    if started is not None and started <= as_of - dt.timedelta(days=7):
        rows = bounded_rows(db.product_events.find(
            {"type": {"$in": list(EVENT_TYPES)},
             "event_time": {"$gte": floor_30, "$lt": local_midnight_utc(as_of)}},
            {"type": 1, "product_id": 1, "session_hash": 1, "event_time": 1}), MAX_EVENTS, "Sự kiện sản phẩm")
        events = [{"type": r.get("type"), "product_id": r.get("product_id"), "sid": r.get("session_hash"),
                   "event_time": r.get("event_time")} for r in rows]
    return {"catalog": catalog, "orders": orders, "lines": lines, "events": events, "returns": returns}


def write_snapshots(db, docs) -> int:
    for i in range(0, len(docs), 500):
        db.product_metrics_snapshots.bulk_write([
            UpdateOne({"product_id": d["product_id"], "window_days": d["window_days"], "as_of": d["as_of"]},
                      {"$set": d}, upsert=True)
            for d in docs[i:i + 500]], ordered=False)
    return len(docs)


def _today(now) -> dt.date:
    return _local_day(to_utc(now or utcnow()))


def build_snapshots(as_of: dt.date, *, db=None, now=None) -> dict:
    now = now or utcnow()
    today = _today(now)
    if as_of > today or as_of < today - dt.timedelta(days=MAX_BACKFILL_DAYS):
        raise ValueError("as_of nằm ngoài khoảng cho phép (không quá hôm nay, tối đa 60 ngày trước).")
    db = db if db is not None else get_db()
    run_id, iso = uuid.uuid4().hex, as_of.isoformat()
    db.product_metrics_runs.update_one({"as_of": iso}, {"$set": {
        "status": "RUNNING", "run_id": run_id, "started_at": utcnow()}}, upsert=True)
    try:
        sources = load_sources(db, as_of, today)
        docs = compute_snapshot_docs(
            as_of, catalog=sources["catalog"], orders=sources["orders"], lines=sources["lines"],
            events=sources["events"], returns=sources["returns"], tracking_started_on=_tracking_day(),
            retention_days=PRODUCT_EVENTS_RETENTION_DAYS, today=today, run_id=run_id, built_at=utcnow())
        written = write_snapshots(db, docs)
    except Exception as exc:
        db.product_metrics_runs.update_one({"as_of": iso}, {"$set": {
            "status": "FAILED", "finished_at": utcnow(), "error_type": type(exc).__name__}})
        logger.error("[METRICS] build %s failed: %s", iso, type(exc).__name__)
        raise
    counts = {
        "products": len(sources["catalog"]), "docs": written,
        "sales_unreconciled": sum(1 for d in docs if not d["coverage_complete"]),
        "sessions_covered_7": any(d["window_days"] == 7 and d["coverage"]["sessions"] for d in docs),
        "sessions_covered_30": any(d["window_days"] == 30 and d["coverage"]["sessions"] for d in docs),
    }
    db.product_metrics_runs.update_one({"as_of": iso}, {"$set": {
        "status": "SUCCEEDED", "finished_at": utcnow(), "counts": counts}})
    logger.info("[METRICS] build %s ok: %s docs", iso, written)
    return {"as_of": iso, "status": "SUCCEEDED", "counts": counts}


def run_due_snapshots(*, db=None, now=None) -> dict:
    now = now or utcnow()
    db = db if db is not None else get_db()
    today = _today(now)
    found = list(db.product_metrics_runs.find({"status": "SUCCEEDED"}).sort("as_of", -1).limit(1))
    last = dt.date.fromisoformat(found[0]["as_of"]) if found else None
    if last is not None and last >= today:
        return {"built": [], "skipped": "up_to_date"}
    first = max(today - dt.timedelta(days=CATCHUP_DAYS - 1), last + dt.timedelta(days=1)) if last else today
    built = []
    day = first
    while day <= today:
        build_snapshots(day, db=db, now=now)
        built.append(day.isoformat())
        day += dt.timedelta(days=1)
    return {"built": built}


def ensure_metrics_indexes(db=None) -> list[str]:
    db = db if db is not None else get_db()
    names = [
        db.product_metrics_snapshots.create_index(
            [("product_id", ASCENDING), ("window_days", ASCENDING), ("as_of", ASCENDING)], unique=True, name="pms_identity"),
        db.product_metrics_snapshots.create_index([("as_of", ASCENDING), ("window_days", ASCENDING)], name="pms_lookup"),
        db.product_metrics_runs.create_index([("as_of", ASCENDING)], unique=True, name="pmr_as_of"),
        db.product_events.create_index([("event_id", ASCENDING)], unique=True, name="pe_event_id"),
        db.product_events.create_index([("type", ASCENDING), ("event_time", ASCENDING)], name="pe_type_time"),
        db.product_events.create_index([("event_time", ASCENDING)], name="pe_ttl",
                                       expireAfterSeconds=PRODUCT_EVENTS_RETENTION_DAYS * 86400),
    ]
    return names


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(prog="python -m core.product_metrics")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("ensure-indexes")
    build = sub.add_parser("build")
    build.add_argument("--as-of", help="YYYY-MM-DD (mặc định: hôm nay theo giờ local)")
    backfill = sub.add_parser("backfill")
    backfill.add_argument("--days", type=int, required=True, help="1..60")
    sub.add_parser("run-due")
    args = parser.parse_args(argv)
    if args.command == "ensure-indexes":
        print(json.dumps({"indexes": ensure_metrics_indexes()}))
    elif args.command == "build":
        as_of = dt.date.fromisoformat(args.as_of) if args.as_of else _today(None)
        print(json.dumps(build_snapshots(as_of)))
    elif args.command == "backfill":
        if not 1 <= args.days <= MAX_BACKFILL_DAYS:
            parser.error("--days phải trong khoảng 1..60")
        today = _today(None)
        results = [build_snapshots(today - dt.timedelta(days=i)) for i in range(args.days - 1, -1, -1)]
        print(json.dumps([{"as_of": r["as_of"], "status": r["status"], "counts": r["counts"]} for r in results]))
    else:
        print(json.dumps(run_due_snapshots()))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
