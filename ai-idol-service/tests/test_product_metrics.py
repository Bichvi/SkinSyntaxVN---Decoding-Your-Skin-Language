"""Deterministic tests for core.product_metrics: no database, network or Docker services."""
import datetime as dt
import os
import sys
import unittest
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from bson import Int64

from agents.command_query import build_query_plan, execute_query
from core import product_metrics as pm
from tests.fakes import Collection, FakeDb

UTC = dt.timezone.utc
AS_OF = dt.date(2026, 10, 13)
TODAY = AS_OF
BUILT = dt.datetime(2026, 10, 13, 3, 0, tzinfo=UTC)
DAY0 = dt.datetime(2026, 10, 12, 17, 0, tzinfo=UTC)  # 00:00 local on 2026-10-13 (exclusive upper bound)


def utc(*args):
    return dt.datetime(*args, tzinfo=UTC)


def catalog(*ids):
    return [{"ma_san_pham": i, "ten_san_pham": f"Sữa rửa mặt {i}"} for i in ids]


def order(oid, tong, ship=0, completed=utc(2026, 10, 10, 5), status="Hoàn thành", **extra):
    doc = {"ma_hoa_don": oid, "trang_thai": status, "hinh_thuc_thanh_toan": "cod", "tong_tien": tong,
           "phi_van_chuyen": ship, "ngay_dat": completed, "ngay_hoan_thanh": completed}
    doc.update(extra)
    return doc


def line(oid, pid, qty, price):
    return {"ma_hoa_don": oid, "ma_san_pham": pid, "so_luong": qty, "don_gia": price}


def compute(cat, orders=(), lines=(), events=(), returns=(), tracking=None, retention=120, as_of=AS_OF):
    docs = pm.compute_snapshot_docs(
        as_of, catalog=cat, orders=list(orders), lines=list(lines), events=list(events), returns=list(returns),
        tracking_started_on=tracking, retention_days=retention, today=TODAY, run_id="r1", built_at=BUILT)
    return {(d["product_id"], d["window_days"]): d for d in docs}


def view(sid, pid, when, kind="product_view"):
    return {"type": kind, "product_id": str(pid), "sid": sid, "event_time": when}


def filler_views(first_day, last_day):
    days = (last_day - first_day).days + 1
    return [view("fill", 99, dt.datetime.combine(first_day + dt.timedelta(days=i), dt.time(5), tzinfo=UTC)) for i in range(days)]


class HelperTests(unittest.TestCase):
    def test_canonical_product_id(self):
        self.assertEqual(pm.canonical_product_id(93), "93")
        self.assertEqual(pm.canonical_product_id(Int64(93)), "93")
        self.assertEqual(pm.canonical_product_id("93.0"), "93")
        self.assertEqual(pm.canonical_product_id(" 93 "), "93")
        self.assertIsNone(pm.canonical_product_id(True))
        self.assertIsNone(pm.canonical_product_id(None))

    def test_revenue_order_rules(self):
        self.assertTrue(pm.is_revenue_order({"trang_thai": "Hoan thanh", "hinh_thuc_thanh_toan": "cod"}))
        self.assertFalse(pm.is_revenue_order({"trang_thai": "Hoàn thành", "hinh_thuc_thanh_toan": "bank_transfer_qr", "status_thanh_toan": "Chưa thanh toán"}))
        self.assertTrue(pm.is_revenue_order({"trang_thai": "Hoàn thành", "hinh_thuc_thanh_toan": "bank_transfer_qr", "status_thanh_toan": "Đã thanh toán"}))
        self.assertFalse(pm.is_revenue_order({"trang_thai": "Đã hủy"}))


class SalesTests(unittest.TestCase):
    def test_allocation_excludes_shipping(self):
        docs = compute(catalog(1, 2), [order(1, 210000, 30000)], [line(1, 1, 1, 100000), line(1, 2, 2, 50000)])
        self.assertEqual(docs[("1", 7)]["net_revenue"], 90000)
        self.assertEqual(docs[("2", 7)]["net_revenue"], 90000)
        self.assertEqual(docs[("2", 7)]["units_sold"], 2)

    def test_cancelled_excluded_and_completion_date_used(self):
        cancelled = order(1, 100000, status="Đã hủy")
        placed_before = order(2, 100000, completed=utc(2026, 10, 10, 5), ngay_dat=utc(2026, 9, 1, 5))
        docs = compute(catalog(1), [cancelled, placed_before], [line(1, 1, 5, 20000), line(2, 1, 1, 100000)])
        self.assertEqual(docs[("1", 7)]["units_sold"], 1)

    def test_window_upper_bound_is_exclusive(self):
        docs = compute(catalog(1), [order(1, 100000, completed=DAY0)], [line(1, 1, 1, 100000)])
        self.assertEqual(docs[("1", 7)]["units_sold"], 0)

    def test_no_orders_is_measured_zero(self):
        doc = compute(catalog(1))[("1", 7)]
        self.assertEqual((doc["units_sold"], doc["net_revenue"]), (0, 0))
        self.assertIs(doc["coverage_complete"], True)

    def test_invalid_line_isolated(self):
        docs = compute(catalog(1, 2), [order(1, 100000)], [line(1, 1, "abc", 50000), line(1, 2, 2, 50000)])
        bad, ok = docs[("1", 7)], docs[("2", 7)]
        self.assertIsNone(bad["units_sold"])
        self.assertIs(bad["coverage_complete"], False)
        self.assertIn("line_invalid", bad["coverage"]["reasons"])
        self.assertEqual(ok["units_sold"], 2)
        self.assertIs(ok["coverage_complete"], True)

    def test_return_makes_period_unreconciled(self):
        docs = compute(catalog(1, 2), returns=[(Int64(1), utc(2026, 10, 11, 5))])
        self.assertIsNone(docs[("1", 7)]["units_sold"])
        self.assertIn("return_unreconciled", docs[("1", 7)]["coverage"]["reasons"])
        self.assertEqual(docs[("2", 7)]["units_sold"], 0)

    def test_previous_window(self):
        in_prev = order(1, 100000, completed=utc(2026, 9, 30, 5))  # inside [Sep 29, Oct 6)
        too_old = order(2, 100000, completed=utc(2026, 9, 1, 5))  # only in the 30d previous window
        docs = compute(catalog(1), [in_prev, too_old], [line(1, 1, 3, 1000), line(2, 1, 7, 1000)])
        self.assertEqual(docs[("1", 7)]["previous_units_sold"], 3)
        self.assertEqual(docs[("1", 7)]["units_sold"], 0)
        self.assertEqual(docs[("1", 30)]["previous_units_sold"], 7)


class SessionTests(unittest.TestCase):
    def full_events(self, extra=()):
        return filler_views(AS_OF - dt.timedelta(days=30), AS_OF - dt.timedelta(days=1)) + list(extra)

    def test_unconfigured_tracking_keeps_sales_and_contract(self):
        cat = catalog(1, 2)
        docs_map = compute(cat, [order(1, 100000)], [line(1, 1, 4, 25000)])
        doc = docs_map[("1", 30)]
        for field in ("viewed_sessions", "converted_sessions", "cart_sessions"):
            self.assertIsNone(doc[field])
        self.assertIn("tracking_unconfigured", doc["coverage"]["reasons"])
        self.assertIs(doc["coverage_complete"], True)
        docs = list(docs_map.values())
        brief = "Lọc 10 sữa rửa mặt xem nhiều chưa chốt đơn"
        result = execute_query(build_query_plan(brief, today=AS_OF), cat, snapshots=docs, brief=brief)
        self.assertEqual(result["status"], "DATA_UNAVAILABLE")
        self.assertIn("sales_snapshot", result["missing_data"])
        brief = "Lọc 10 sữa rửa mặt bán chạy"
        result = execute_query(build_query_plan(brief, today=AS_OF), cat, snapshots=docs, brief=brief)
        self.assertEqual(result["status"], "RESULTS_READY")
        self.assertEqual(result["selected"][0]["product_id"], "1")

    def test_full_coverage_counts(self):
        window_start = AS_OF - dt.timedelta(days=30)
        extra = []
        for i in range(120):
            day = window_start + dt.timedelta(days=i % 30)
            extra.append(view(f"v{i}", 1, dt.datetime.combine(day, dt.time(5, 1), tzinfo=UTC)))
        extra.append(extra[5])  # exact duplicate event
        extra += [view("c1", 1, utc(2026, 10, 9, 5), "add_to_cart"), view("c1", 1, utc(2026, 10, 9, 6), "add_to_cart"),
                  view("c2", 1, utc(2026, 10, 9, 7), "add_to_cart")]
        # v0 first views on Sep 13 05:01Z and buys later; v1 first views on Sep 14 05:01Z but its
        # order (Sep 14 05:00Z) is earlier; "nv" buys without any view.
        orders = [order(1, 100000, completed=utc(2026, 9, 20, 5), analytics_sid="v0"),
                  order(2, 100000, completed=utc(2026, 9, 14, 5), analytics_sid="v1"),
                  order(3, 100000, completed=utc(2026, 9, 20, 5), analytics_sid="nv")]
        lines = [line(1, 1, 1, 100000), line(2, 1, 1, 100000), line(3, 1, 1, 100000)]
        docs = compute(catalog(1), orders, lines, self.full_events(extra), tracking=window_start - dt.timedelta(days=1))
        doc = docs[("1", 30)]
        self.assertEqual(doc["coverage"]["reasons"], [])
        self.assertEqual(doc["viewed_sessions"], 120)
        self.assertEqual(doc["converted_sessions"], 1)
        self.assertEqual(doc["cart_sessions"], 2)
        self.assertLessEqual(doc["converted_sessions"], doc["viewed_sessions"])
        self.assertIs(doc["coverage_complete"], True)

    def test_tracking_gap_nulls_sessions(self):
        events = [e for e in filler_views(AS_OF - dt.timedelta(days=30), AS_OF - dt.timedelta(days=1))
                  if e["event_time"].date() != dt.date(2026, 10, 5)]
        doc = compute(catalog(1), events=events, tracking=AS_OF - dt.timedelta(days=40))[("1", 30)]
        self.assertIn("tracking_gap", doc["coverage"]["reasons"])
        self.assertIsNone(doc["viewed_sessions"])
        self.assertIsNone(doc["cart_sessions"])

    def test_outside_retention(self):
        events = filler_views(AS_OF - dt.timedelta(days=30), AS_OF - dt.timedelta(days=1))
        docs = compute(catalog(1), events=events, tracking=AS_OF - dt.timedelta(days=40), retention=20)
        self.assertIn("outside_retention", docs[("1", 30)]["coverage"]["reasons"])
        self.assertIsNone(docs[("1", 30)]["viewed_sessions"])
        self.assertEqual(docs[("1", 7)]["viewed_sessions"], 0)


class BuildTests(unittest.TestCase):
    def make_db(self):
        return FakeDb(
            san_pham=Collection([{"ma_san_pham": Int64(1)}, {"ma_san_pham": Int64(2)}, {"ma_san_pham": Int64(3)}]),
            hoa_don=Collection([order(Int64(1), 100000)]),
            chi_tiet_hoa_don=Collection([line(Int64(1), "1", 2, 50000)]))

    def test_build_is_idempotent_and_typed(self):
        db = self.make_db()
        with patch.object(pm, "TRACKING_STARTED_ON", None):
            result = pm.build_snapshots(AS_OF, db=db, now=BUILT)
            pm.build_snapshots(AS_OF, db=db, now=BUILT)
        docs = db.product_metrics_snapshots.docs
        self.assertEqual(len(docs), 6)
        self.assertEqual(result["status"], "SUCCEEDED")
        self.assertTrue(all(isinstance(d["as_of"], str) and isinstance(d["window_days"], int) for d in docs))
        self.assertEqual(len(db.product_metrics_runs.docs), 1)
        self.assertEqual(db.product_metrics_runs.docs[0]["status"], "SUCCEEDED")
        product_one = [d for d in docs if d["product_id"] == "1" and d["window_days"] == 7][0]
        self.assertEqual(product_one["units_sold"], 2)

    def test_future_as_of_rejected(self):
        with self.assertRaises(ValueError):
            pm.build_snapshots(AS_OF + dt.timedelta(days=1), db=self.make_db(), now=BUILT)

    def test_failed_run_is_recorded_without_message(self):
        db = self.make_db()
        with patch.object(pm, "load_sources", side_effect=RuntimeError("mongodb://secret-host")):
            with self.assertRaises(RuntimeError):
                pm.build_snapshots(AS_OF, db=db, now=BUILT)
        run = db.product_metrics_runs.docs[0]
        self.assertEqual((run["status"], run["error_type"]), ("FAILED", "RuntimeError"))
        self.assertNotIn("secret", repr(run))


class RunDueTests(unittest.TestCase):
    def run_due(self, last):
        runs = [] if last is None else [{"as_of": last.isoformat(), "status": "SUCCEEDED"}]
        db = FakeDb(product_metrics_runs=Collection(runs))
        calls = []
        with patch.object(pm, "build_snapshots", side_effect=lambda day, **kw: calls.append(day)):
            result = pm.run_due_snapshots(db=db, now=BUILT)
        return calls, result

    def test_no_previous_run_builds_today_only(self):
        self.assertEqual(self.run_due(None)[0], [TODAY])

    def test_yesterday_builds_today_only(self):
        self.assertEqual(self.run_due(TODAY - dt.timedelta(days=1))[0], [TODAY])

    def test_up_to_date_does_nothing(self):
        calls, result = self.run_due(TODAY)
        self.assertEqual(calls, [])
        self.assertEqual(result["skipped"], "up_to_date")

    def test_long_gap_catches_up_seven_days(self):
        calls, _ = self.run_due(TODAY - dt.timedelta(days=10))
        self.assertEqual(calls, [TODAY - dt.timedelta(days=i) for i in range(6, -1, -1)])


class CeleryWiringTests(unittest.TestCase):
    def test_route_and_beat(self):
        import celery_app
        self.assertEqual(celery_app.TASK_ROUTES["ai_idol.build_product_metrics"]["queue"], "control")
        self.assertIn("build-product-metrics", celery_app.celery_app.conf.beat_schedule)


if __name__ == "__main__":
    unittest.main()
