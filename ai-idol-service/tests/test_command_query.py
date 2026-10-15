"""Deterministic tests: no database, network, GPU or paid LLM calls."""
import datetime as dt
import os
import sys
import unittest
from zoneinfo import ZoneInfo

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from agents.command_query import add_months, build_query_plan, execute_query
from agents.intent_classifier import CANCEL_CAMPAIGN, CREATE_CAMPAIGN, PRODUCT_SEARCH, classify_intent
from agents.intent_parser import fallback_parse
from agents.product_resolver import normalize_catalog_product
from agents.query_sources import bounded_rows

TODAY = dt.date(2026, 9, 28)


def product(pid, **fields):
    return {"ma_san_pham": pid, "ten_san_pham": f"Kem chống nắng {pid}", **fields}


def query(text, catalog=(), **sources):
    return execute_query(build_query_plan(text, today=TODAY), list(catalog), brief=text, **sources)


def snapshot(pid, **fields):
    return {"product_id": pid, "as_of": TODAY.isoformat(), "window_days": 30, "coverage_complete": True, **fields}


class QueryPlanTests(unittest.TestCase):
    def test_inventory_not_cancel(self):
        self.assertEqual(classify_intent("Lọc 10 kem chống nắng còn 18 tháng hạn sử dụng").intent, PRODUCT_SEARCH)
        self.assertEqual(classify_intent("Hủy livestream tối nay").intent, CANCEL_CAMPAIGN)
        self.assertEqual(classify_intent("Dừng livestream tối nay").intent, CANCEL_CAMPAIGN)

    def test_compound_command(self):
        self.assertEqual(classify_intent("Lọc 10 kem chống nắng tồn kho trên 100 để tạo livestream").intent, CREATE_CAMPAIGN)

    def test_months_are_calendar_months(self):
        self.assertEqual(add_months(dt.date(2027, 8, 31), 6), dt.date(2028, 2, 29))

    def test_ambiguous_expiry_asks(self):
        self.assertEqual(query("Lọc 10 kem chống nắng còn khoảng 18 tháng hạn sử dụng")["status"], "NEEDS_INPUT")

    def test_expiry_does_not_become_stock_threshold(self):
        p = build_query_plan("Lọc 10 kem chống nắng còn tối đa 18 tháng hạn sử dụng", today=TODAY)
        self.assertIsNone(p["stock_max"])
        self.assertEqual(p["expiry_to"], "2028-03-28")

    def test_foundation_category_is_resolved_for_inventory_search(self):
        r = query(
            "Tìm kem nền còn hạn sử dụng từ 17 đến 19 tháng",
            [{"ma_san_pham": "foundation-1", "ten_san_pham": "Kem nền Maybelline", "stock": 12}],
            batches=[{
                "product_id": "foundation-1",
                "batch_code": "FOUNDATION-01",
                "status": "available",
                "available_quantity": 12,
                "expires_on": "2028-03-01",
            }],
        )
        self.assertEqual(r["query"]["category"], "foundation")
        self.assertEqual([item["product_id"] for item in r["selected"]], ["foundation-1"])

    def test_count_not_product_id(self):
        p = build_query_plan("Lọc 10 kem chống nắng bán chạy nhất toàn kỳ", today=TODAY)
        self.assertEqual(p["limit"], 10)
        self.assertIsNone(p["window_days"])

    def test_invalid_or_unsupported_queries_clarify(self):
        for text in ["Lọc 100 kem chống nắng", "Lọc 10 kem chống nắng tồn kho thấp",
                     "Lọc 10 kem chống nắng bán chạy trong 14 ngày qua", "Lọc 10 kem chống nắng tăng mạnh tuần này",
                     "Lọc 10 kem chống nắng hạn dùng từ 19 đến 17 tháng",
                     "Lọc 10 kem chống nắng cho da dầu", "Lọc 10 kem chống nắng không chứa retinol"]:
            with self.subTest(text=text):
                self.assertTrue(build_query_plan(text, today=TODAY)["questions"])

    def test_schedule_hour_not_duration(self):
        now = dt.datetime(2026, 9, 28, 21, tzinfo=ZoneInfo("Asia/Ho_Chi_Minh"))
        b = fallback_parse("Tạo livestream lúc 19 giờ ngày 28/9/2026", now=now)
        self.assertEqual(b.duration_minutes, 60)
        self.assertTrue(b.scheduled_at.startswith("2026-09-28T19:00"))

    def test_tonight_not_silently_tomorrow(self):
        now = dt.datetime(2026, 9, 28, 21, tzinfo=ZoneInfo("Asia/Ho_Chi_Minh"))
        b = fallback_parse("Tạo live lúc 19h tối nay, thời lượng 2 giờ", now=now)
        self.assertEqual(b.duration_minutes, 120)
        self.assertTrue(b.scheduled_at.startswith("2026-09-28T19:00"))


class InventoryTests(unittest.TestCase):
    def test_missing_stock_is_not_zero(self):
        r = query("Lọc 10 sản phẩm hết hàng", [product("unknown"), product("zero", stock=0)])
        self.assertEqual([p["product_id"] for p in r["selected"]], ["zero"])
        self.assertFalse(r["complete"])

    def test_expiry_requires_actual_date_not_shelf_life(self):
        r = query("Lọc 10 kem chống nắng hạn dùng tối đa 18 tháng", [product("1", stock=20, han_su_dung="36 tháng từ NSX")])
        self.assertEqual(r["status"], "DATA_UNAVAILABLE")

    def test_expiry_never_falls_back_to_catalog_date_as_fake_batch(self):
        r = query(
            "Lọc 10 kem chống nắng hạn dùng tối đa 18 tháng",
            [product("1", stock=20, expiry_date="2028-03-01")],
        )
        self.assertEqual(r["status"], "DATA_UNAVAILABLE")
        self.assertEqual(r["selected"], [])
        self.assertIn("expiry_or_batch_quantity", r["missing_data"])

    def test_expiry_uses_batch_code_when_batch_id_is_missing(self):
        r = query("Lọc 10 kem chống nắng hạn dùng từ 17 đến 19 tháng", [product("1", stock=20)], batches=[
            {"product_id": "1", "batch_code": "LOT-2028-01", "status": "available", "available_quantity": 10, "expires_on": "2028-03-01"},
        ])
        self.assertEqual(r["selected"][0]["evidence"]["batches"][0]["batch_id"], "LOT-2028-01")

    def test_expiry_rejects_batch_without_identifier(self):
        r = query("Lọc 10 kem chống nắng hạn dùng từ 17 đến 19 tháng", [product("1", stock=20)], batches=[
            {"product_id": "1", "status": "available", "available_quantity": 10, "expires_on": "2028-03-01"},
        ])
        self.assertEqual(r["status"], "DATA_UNAVAILABLE")
        self.assertEqual(r["selected"], [])
        self.assertIn("expiry_or_batch_quantity", r["missing_data"])

    def test_multi_batch_only_eligible_quantity(self):
        batches = [{"product_id": "1", "batch_id": key, "status": status, "available_quantity": qty, "expires_on": expiry}
                   for key, status, qty, expiry in [
                       ("valid", "available", 12, "2028-03-01"),
                       ("later", "available", 100, "2029-01-01"),
                       ("expired", "available", 9, TODAY.isoformat()),
                       ("quarantine", "quarantine", 70, "2028-03-01"),
                   ]]
        r = query("Lọc 10 kem chống nắng hạn dùng từ 17 đến 19 tháng", [product("1", stock=999)], batches=batches)
        e = r["selected"][0]["evidence"]
        self.assertEqual(e["eligible_quantity"], 12)
        self.assertEqual(len(e["batches"]), 1)

    def test_batch_scope_filters_warehouse(self):
        batches = [
            {"product_id": "1", "batch_id": "HN-01", "warehouse_id": "KHO-HN-01", "status": "available", "available_quantity": 8, "expires_on": "2028-03-01"},
            {"product_id": "1", "batch_id": "HCM-01", "warehouse_id": "KHO-HCM-01", "status": "available", "available_quantity": 80, "expires_on": "2028-03-01"},
        ]
        r = query("Lọc 10 kem chống nắng tồn kho trên 5 chỉ lấy từ kho KHO-HN-01", [product("1", stock=999)], batches=batches)
        self.assertEqual([item["product_id"] for item in r["selected"]], ["1"])
        self.assertEqual(r["selected"][0]["evidence"]["stock"], 8)

    def test_batch_scope_does_not_fallback_to_legacy_stock(self):
        r = query("Lọc 10 kem chống nắng tồn kho trên 5 chỉ lấy từ kho KHO-HN-01", [product("1", stock=999)], batches=[])
        self.assertEqual(r["status"], "DATA_UNAVAILABLE")
        self.assertIn("expiry_or_batch_quantity", r["missing_data"])

    def test_batch_scope_filters_batch_code_for_expiry(self):
        batches = [
            {"product_id": "1", "batch_id": "LOT-OLD", "batch_code": "LOT-OLD", "warehouse_id": "KHO-01", "status": "available", "available_quantity": 8, "expires_on": "2028-03-01"},
            {"product_id": "1", "batch_id": "LOT-NEW", "batch_code": "LOT-NEW", "warehouse_id": "KHO-01", "status": "available", "available_quantity": 8, "expires_on": "2029-03-01"},
        ]
        r = query("Lọc 10 kem chống nắng hạn dùng từ 17 đến 19 tháng chỉ lấy từ lô hàng LOT-OLD", [product("1", stock=999)], batches=batches)
        self.assertEqual(r["selected"][0]["evidence"]["batches"][0]["batch_id"], "LOT-OLD")

    def test_no_replacement_by_unrelated_spf(self):
        r = query("Lọc 10 kem chống nắng tồn kho trên 10", [
            product("1", stock=11), product("2", stock=100, ten_san_pham="Kem nền SPF50"),
            product("3", stock=50, ten_san_pham="Sữa rửa mặt", mo_ta="Dùng trước kem chống nắng"),
        ])
        self.assertEqual([x["product_id"] for x in r["selected"]], ["1"])

    def test_stock_threshold_order_and_limit(self):
        r = query("Lọc 2 kem chống nắng tồn kho trên 10", [product("1", stock=10), product("2", stock=12), product("3", stock=25), product("4", stock=14)])
        self.assertEqual([x["product_id"] for x in r["selected"]], ["3", "4"])

    def test_expired_batches_not_counted_as_stock(self):
        r = query("Lọc 10 kem chống nắng tồn kho trên 10", [product("1", stock=100)], batches=[
            {"product_id": "1", "status": "available", "available_quantity": 100, "expires_on": "2025-01-01"},
        ])
        self.assertEqual(r["status"], "EMPTY_RESULTS")

    def test_explicit_id_cannot_bypass_filters(self):
        r = query("Lọc 1 kem chống nắng mã 2 tồn kho trên 10", [product("1", stock=40), product("2", stock=0)])
        self.assertEqual(r["selected"], [])


class SalesTests(unittest.TestCase):
    def test_normalization_preserves_lifetime_sales(self):
        row = normalize_catalog_product(product("1", so_luong_ban=34))
        self.assertEqual(normalize_catalog_product(row)["sales_count"], 34)

    def test_30_days_not_lifetime_reviews_or_views(self):
        r = query("Lọc 10 kem chống nắng bán chạy", [product("1", so_luong_ban=999, so_luong_danh_gia=999)])
        self.assertEqual(r["status"], "DATA_UNAVAILABLE")

    def test_full_period_can_use_lifetime(self):
        r = query("Lọc 2 kem chống nắng bán chạy toàn kỳ", [product("1", so_luong_ban=9), product("2", so_luong_ban=13)])
        self.assertEqual([x["product_id"] for x in r["selected"]], ["2", "1"])
        self.assertTrue(r["complete"])

    def test_snapshot_requires_right_window_date_and_coverage(self):
        for override in [{"window_days": 7}, {"as_of": "2026-09-27"}, {"coverage_complete": False}]:
            r = query("Lọc 10 kem chống nắng bán chạy", [product("1")], snapshots=[snapshot("1", units_sold=500, **override)])
            self.assertEqual(r["status"], "DATA_UNAVAILABLE")

    def test_duplicate_snapshots_fail_closed(self):
        s = snapshot("1", units_sold=10)
        r = query("Lọc 10 kem chống nắng bán chạy", [product("1")], snapshots=[s, s])
        self.assertEqual(r["status"], "DATA_UNAVAILABLE")

    def test_zero_baseline_no_infinite_growth(self):
        r = query("Lọc 10 kem chống nắng doanh số tăng mạnh", [product("1")], snapshots=[snapshot("1", units_sold=10, previous_units_sold=0)])
        self.assertEqual(r["selected"], [])
        self.assertIn("growth_baseline", r["missing_data"])

    def test_growth_and_cart_ranking(self):
        catalog = [product("1"), product("2")]
        rows = [snapshot("1", units_sold=20, previous_units_sold=10, cart_sessions=300), snapshot("2", units_sold=25, previous_units_sold=5, cart_sessions=200)]
        self.assertEqual(query("Lọc 1 kem chống nắng tăng trưởng", catalog, snapshots=rows)["selected"][0]["product_id"], "2")
        self.assertEqual(query("Lọc 1 kem chống nắng có nhiều lượt thêm giỏ", catalog, snapshots=rows)["selected"][0]["product_id"], "1")

    def test_views_without_purchase_and_minimum_sample(self):
        catalog = [product(str(i)) for i in range(3)]
        rows = [snapshot("0", viewed_sessions=99, converted_sessions=0), snapshot("1", viewed_sessions=100, converted_sessions=0), snapshot("2", viewed_sessions=900, converted_sessions=2)]
        r = query("Lọc 10 kem chống nắng xem nhiều chưa chốt đơn", catalog, snapshots=rows)
        self.assertEqual([p["product_id"] for p in r["selected"]], ["1"])

    def test_invalid_conversion_cohort_rejected(self):
        r = query("Lọc 10 kem chống nắng xem nhiều chưa chốt đơn", [product("1")], snapshots=[snapshot("1", viewed_sessions=100, converted_sessions=200)])
        self.assertEqual(r["status"], "DATA_UNAVAILABLE")

    def test_percentile_strategies(self):
        catalog = [product(str(i), gia_ban=80, gia_thi_truong=100) for i in range(5)]
        rows = [snapshot(str(i), units_sold=i * 10, net_revenue=i * 100, viewed_sessions=200, converted_sessions=4-i) for i in range(5)]
        r = query("Lọc 10 kem chống nắng doanh thu cao chuyển đổi thấp", catalog, snapshots=rows)
        self.assertEqual([p["product_id"] for p in r["selected"]], ["4", "3"])
        r = query("Lọc 10 kem chống nắng giảm giá nhưng bán chậm", catalog, snapshots=rows)
        self.assertEqual([p["product_id"] for p in r["selected"]], ["0", "1"])

    def test_catalog_limit_fails_instead_of_partial_ranking(self):
        class Cursor:
            def limit(self, n):
                return list(range(n))
        with self.assertRaisesRegex(ValueError, "vượt giới hạn"):
            bounded_rows(Cursor(), 20000, "Catalog")


if __name__ == "__main__":
    unittest.main()
