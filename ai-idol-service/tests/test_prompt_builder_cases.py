"""Prompts produced by the Admin Prompt Builder must run as read-only queries (cases L1-L6).

Deterministic: in-memory catalog and batch ledger, no database, network, LLM or campaign writes.
"""
import datetime as dt
import os
import sys
import unittest
from collections import Counter
from unittest.mock import Mock, patch

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from agents.command_query import (
    NO_CONDITION_QUESTION, build_query_plan, catalog_search_requested, execute_query,
)
from agents.intent_classifier import CANCEL_CAMPAIGN, CREATE_CAMPAIGN, PRODUCT_SEARCH, classify_intent
from agents.intent_parser import fallback_parse
from agents.query_sources import load_query_sources
from agents.service import process_agent_run
from tests.fakes import Collection, FakeDb

TODAY = dt.date(2026, 10, 13)
ROOT = "Sức Khỏe - Làm Đẹp -> "


def row(pid, name, path, brand, stock=50, sales=10, **extra):
    return {
        "ma_san_pham": pid, "ten_san_pham": name,
        "danh_muc_day_du": ROOT + path, "loai_san_pham": path.split(" -> ")[-1],
        "thuong_hieu": brand, "so_luong_ton_kho": stock, "so_luong_ban": sales,
        "gia_ban": 100, "gia_thi_truong": 150, "trang_thai": "active", **extra,
    }


CLEANSER_PATH = "Chăm Sóc Da Mặt -> Làm Sạch Da -> Sữa Rửa Mặt"
SERUM_PATH = "Chăm Sóc Da Mặt -> Đặc Trị -> Serum / Tinh Chất"
CATALOG = [
    row("1", "Sữa Rửa Mặt CeraVe", CLEANSER_PATH, "CeraVe", stock=120),
    row("2", "Sữa Rửa Mặt CeraVe Foaming", CLEANSER_PATH, "CeraVe", stock=5),
    row("3", "Sữa Rửa Mặt CeraVe Hydrating", CLEANSER_PATH, "CeraVe", stock=0),
    row("4", "Nước Tẩy Trang Bioderma", "Chăm Sóc Da Mặt -> Làm Sạch Da -> Tẩy Trang Mặt", "Bioderma"),
    row("5", "Mặt Nạ Giấy Mediheal", "Chăm Sóc Da Mặt -> Mặt Nạ -> Mặt Nạ Giấy", "Mediheal"),
    row("6", "Kem Chống Nắng La Roche-Posay", "Chăm Sóc Da Mặt -> Chống Nắng Da Mặt", "La Roche-Posay", stock=8),
    row("7", "Serum Niacinamide The Ordinary", SERUM_PATH, "The Ordinary", thanh_phan_chinh="Niacinamide"),
    row("8", "Son Thỏi 3CE", "Trang Điểm -> Trang Điểm Môi -> Son Thỏi", "3CE"),
    row("9", "Kem Nền Maybelline", "Trang Điểm -> Trang Điểm Mặt -> Kem Nền", "Maybelline"),
    row("10", "Sữa Tắm Dove", "Chăm Sóc Cơ Thể -> Sữa Tắm", "Dove"),
    row("11", "Dầu Xả TRESemme", "Chăm Sóc Tóc Và Da Đầu -> Dầu Gội Và Dầu Xả -> Dầu Xả", "TRESemme"),
    row("12", "Kem Chống Nắng Body Nivea", "Chăm Sóc Cơ Thể -> Chống Nắng Cơ Thể", "Nivea"),
    row("13", "Serum Vitamin C Klairs", SERUM_PATH, "Klairs", thanh_phan_chinh="Ascorbic Acid"),
]
BATCHES = [
    {"product_id": "1", "batch_code": "AUTO-1-20260928", "warehouse_id": "AUTO-DEFAULT",
     "status": "available", "available_quantity": 120, "expires_on": "2028-01-01"},
    {"product_id": "2", "batch_code": "DEMO-2-20260928", "warehouse_id": "AUTO-SCENARIOS",
     "status": "available", "available_quantity": 5, "expires_on": "2028-01-01"},
]


def plan(text):
    return build_query_plan(text, today=TODAY)


def run(text, catalog=None, **sources):
    return execute_query(plan(text), list(CATALOG if catalog is None else catalog), brief=text, **sources)


def ids(result):
    return [item["product_id"] for item in result["selected"]]


class CategorySearchTests(unittest.TestCase):
    """L1: a plain "Tim <danh muc>" is a read-only catalog query."""

    def test_builder_category_prompts_run_as_queries(self):
        cases = [
            ("Tìm sản phẩm chăm sóc da mặt.", "face", {"1", "2", "3", "4", "5", "6", "7", "13"}),
            ("Tìm sản phẩm trang điểm.", "makeup", {"8", "9"}),
            ("Tìm sản phẩm làm sạch da.", "face_cleansing", {"1", "2", "3", "4"}),
            ("Tìm sữa rửa mặt.", "cleanser", {"1", "2", "3"}),
            ("Tìm nước tẩy trang.", "makeup_remover", {"4"}),
            ("Tìm mặt nạ chăm sóc da.", "mask", {"5"}),
            ("Tìm kem chống nắng body.", "body_sunscreen", {"12"}),
            ("Tìm sản phẩm chăm sóc cơ thể.", "body", {"10", "12"}),
            ("Tìm sản phẩm dầu xả.", "conditioner", {"11"}),
            ("Tìm serum niacinamide.", "serum", {"7"}),
            ("Tìm serum vitamin C.", "serum", {"13"}),
        ]
        for prompt, category, expected in cases:
            with self.subTest(prompt=prompt):
                self.assertEqual(classify_intent(prompt).intent, PRODUCT_SEARCH)
                self.assertTrue(catalog_search_requested(prompt))
                query_plan = plan(prompt)
                self.assertEqual(query_plan["questions"], [])
                self.assertEqual(query_plan["limit"], 10)
                self.assertEqual(query_plan["category"], category)
                result = run(prompt)
                self.assertEqual(result["status"], "RESULTS_READY")
                self.assertEqual(set(ids(result)), expected)

    def test_plain_sunscreen_keeps_legacy_category(self):
        self.assertEqual(plan("Tìm kem chống nắng.")["category"], "sunscreen")
        self.assertIn("6", ids(run("Tìm kem chống nắng.")))

    def test_style_only_prompt_asks_for_a_condition(self):
        prompt = "Tìm sản phẩm, theo phong cách tươi vui, dễ thương."
        self.assertEqual(classify_intent(prompt).intent, PRODUCT_SEARCH)
        self.assertEqual(plan(prompt)["questions"], [NO_CONDITION_QUESTION])

    def test_unsupported_categories_ask_instead_of_guessing(self):
        self.assertTrue(any("chưa có ánh xạ" in q for q in plan("Tìm sản phẩm phục hồi hàng rào da.")["questions"]))
        self.assertTrue(plan("Lọc 10 toner bán chạy toàn kỳ")["questions"])


class BrandTests(unittest.TestCase):
    def test_preferred_brand_orders_without_filtering(self):
        prompt = "Tìm sản phẩm ưu tiên nhiều sản phẩm của thương hiệu CeraVe."
        query_plan = plan(prompt)
        self.assertEqual((query_plan["brand"], query_plan["brand_mode"], query_plan["limit"]), ("cerave", "prefer", 10))
        result = run(prompt)
        self.assertEqual(ids(result)[:3], ["1", "2", "3"])
        self.assertEqual(len(ids(result)), 10)

    def test_per_brand_cap_is_not_a_brand_filter_or_a_limit(self):
        prompt = "Tìm sản phẩm mỗi thương hiệu tối đa 2 sản phẩm."
        query_plan = plan(prompt)
        self.assertEqual((query_plan["per_brand_max"], query_plan["brand"], query_plan["limit"]), (2, None, 10))
        result = run(prompt)
        self.assertNotIn("3", ids(result))
        counts = Counter(item["evidence"]["brand"] for item in result["selected"])
        self.assertTrue(counts and max(counts.values()) <= 2)

    def test_preferred_brand_with_cap(self):
        result = run("Tìm sản phẩm ưu tiên nhiều sản phẩm của thương hiệu CeraVe, mỗi thương hiệu tối đa 2 sản phẩm.")
        self.assertEqual(ids(result)[:2], ["1", "2"])
        self.assertNotIn("3", ids(result))

    def test_plain_brand_stays_a_hard_filter(self):
        prompt = "Lọc 5 sữa rửa mặt thương hiệu CeraVe"
        self.assertEqual(plan(prompt)["brand_mode"], "only")
        self.assertEqual(set(ids(run(prompt))), {"1", "2", "3"})

    def test_missing_brand_fails_closed_under_cap(self):
        result = run("Tìm sản phẩm mỗi thương hiệu tối đa 2 sản phẩm.", [row("x", "Sữa Rửa Mặt X", CLEANSER_PATH, "")])
        self.assertEqual(result["missing_data"]["brand"], 1)
        self.assertEqual(result["selected"], [])


class StockThresholdTests(unittest.TestCase):
    """L2: a stock threshold is not a result count."""

    def test_threshold_is_not_limit(self):
        prompt = "Tìm sản phẩm tồn kho trên 100 sản phẩm."
        query_plan = plan(prompt)
        self.assertEqual((query_plan["limit"], query_plan["stock_min"], query_plan["stock_max"]), (10, 101, None))
        self.assertEqual(query_plan["rank_by"], "stock_desc")
        self.assertEqual(query_plan["questions"], [])
        self.assertEqual(ids(run(prompt)), ["1"])

    def test_low_stock_with_in_stock(self):
        prompt = "Tìm sản phẩm tồn kho tối đa 10 sản phẩm, còn hàng."
        query_plan = plan(prompt)
        self.assertEqual((query_plan["limit"], query_plan["stock_min"], query_plan["stock_max"]), (10, 1, 10))
        self.assertEqual(query_plan["rank_by"], "stock_asc")
        self.assertEqual(ids(run(prompt)), ["2", "6"])

    def test_explicit_count_still_wins(self):
        self.assertEqual(plan("Lọc 3 sản phẩm tồn kho trên 100 sản phẩm.")["limit"], 3)

    def test_out_of_stock(self):
        prompt = "Tìm sản phẩm đã hết hàng."
        self.assertEqual(plan(prompt)["stock_max"], 0)
        self.assertEqual(ids(run(prompt)), ["3"])

    def test_contradictions_ask_instead_of_picking(self):
        for prompt in (
            "Tìm sản phẩm tồn kho trên 100 sản phẩm, tồn kho tối đa 10 sản phẩm, còn hàng.",
            "Tìm sản phẩm đã hết hàng, ưu tiên sản phẩm còn đủ hàng (đủ bán khi live).",
            "Tìm sản phẩm còn hạn sử dụng từ 17 đến 19 tháng, còn hạn sử dụng tối đa 18 tháng.",
        ):
            with self.subTest(prompt=prompt):
                self.assertTrue(plan(prompt)["questions"])


class WarehouseScopeTests(unittest.TestCase):
    """L3: warehouse/lot codes lose trailing punctuation and the ledger is loaded."""

    WAREHOUSE = "Tìm sản phẩm chỉ lấy từ kho AUTO-DEFAULT."
    LOT = "Tìm sản phẩm chỉ lấy từ lô hàng AUTO-1-20260928."

    def test_codes_keep_case_and_drop_final_dot(self):
        self.assertEqual(plan(self.WAREHOUSE)["warehouse_id"], "AUTO-DEFAULT")
        self.assertEqual(plan(self.LOT)["batch_id"], "AUTO-1-20260928")
        self.assertEqual(plan("Lọc 1 kem chống nắng chỉ lấy từ kho KHO-HN-01.")["warehouse_id"], "KHO-HN-01")

    def test_scope_loads_batch_ledger(self):
        db = FakeDb(inventory_batches=Collection(BATCHES))
        self.assertEqual(len(load_query_sources(db, plan(self.WAREHOUSE))[0]), 2)

    def test_warehouse_scope_excludes_other_warehouses(self):
        result = run(self.WAREHOUSE, batches=BATCHES)
        self.assertEqual(result["status"], "RESULTS_READY")
        self.assertEqual(ids(result), ["1"])
        self.assertEqual(result["selected"][0]["evidence"]["warehouse_id"], "AUTO-DEFAULT")

    def test_lot_scope(self):
        result = run(self.LOT, batches=BATCHES)
        self.assertEqual(ids(result), ["1"])
        self.assertEqual(result["selected"][0]["evidence"]["batch_id"], "AUTO-1-20260928")


class SalesCriteriaTests(unittest.TestCase):
    """L4: exactly one sales criterion and one statistics window."""

    def test_single_criterion_prompts(self):
        cases = [
            ("Tìm sản phẩm bán chạy nhất trong 30 ngày qua.", "units", 30),
            ("Tìm sản phẩm bán tốt trong 7 ngày qua.", "units", 7),
            ("Tìm sản phẩm bán chạy nhất toàn kỳ.", "units", None),
            ("Tìm sản phẩm doanh thu cao nhưng tỷ lệ chuyển đổi thấp trong 30 ngày qua.", "revenue_low_conversion", 30),
            ("Tìm sản phẩm được xem nhiều nhưng chưa chốt đơn trong 30 ngày qua.", "views_no_orders", 30),
            ("Tìm sản phẩm có nhiều lượt thêm giỏ trong 30 ngày qua.", "cart", 30),
            ("Tìm sản phẩm đang giảm giá nhưng bán chậm trong 30 ngày qua.", "discount_slow", 30),
            ("Tìm sản phẩm có doanh số tăng mạnh trong 7 ngày qua so với 7 ngày trước đó.", "growth", 7),
        ]
        for prompt, rank_by, window in cases:
            with self.subTest(prompt=prompt):
                query_plan = plan(prompt)
                self.assertEqual(query_plan["questions"], [])
                self.assertEqual((query_plan["rank_by"], query_plan["window_days"]), (rank_by, window))

    def test_multiple_criteria_or_windows_never_pick_silently(self):
        for prompt in (
            "Tìm sản phẩm bán chạy nhất trong 30 ngày qua, bán tốt trong 7 ngày qua.",
            "Tìm sản phẩm bán chạy nhất trong 30 ngày qua, bán chạy nhất toàn kỳ.",
            "Tìm sản phẩm bán tốt trong 7 ngày qua, bán chạy nhất toàn kỳ.",
            "Tìm sản phẩm bán chạy nhất trong 30 ngày qua, đang giảm giá nhưng bán chậm trong 30 ngày qua.",
        ):
            with self.subTest(prompt=prompt):
                result = run(prompt)
                self.assertEqual(result["status"], "NEEDS_INPUT")
                self.assertEqual(result["selected"], [])

    def test_missing_session_metrics_stay_unavailable(self):
        snapshots = [{"product_id": "1", "as_of": "2026-10-13", "window_days": 30, "coverage_complete": True,
                      "units_sold": 3, "viewed_sessions": None, "converted_sessions": None}]
        result = run("Tìm sản phẩm được xem nhiều nhưng chưa chốt đơn trong 30 ngày qua.", snapshots=snapshots)
        self.assertEqual(result["status"], "DATA_UNAVAILABLE")
        self.assertIn("sales_snapshot", result["missing_data"])


class LiveWordingTests(unittest.TestCase):
    """L6: "du hang de ban trong livestream" describes stock, not a create command."""

    ENOUGH = "Tìm sản phẩm ưu tiên sản phẩm còn đủ hàng để bán trong livestream."
    LONG = ("Tìm kem chống nắng tồn kho tối đa 10 sản phẩm, còn hàng, đang giảm giá nhưng bán chậm trong 30 ngày qua, "
            "mỗi thương hiệu tối đa 2 sản phẩm, ưu tiên sản phẩm còn đủ hàng để bán trong livestream.")

    def test_stock_wording_is_product_search(self):
        for prompt in (self.ENOUGH, "Tìm sản phẩm ưu tiên sản phẩm còn đủ hàng (đủ bán khi live).", self.LONG):
            with self.subTest(prompt=prompt):
                self.assertEqual(classify_intent(prompt).intent, PRODUCT_SEARCH)

    def test_enough_stock_plan(self):
        query_plan = plan(self.ENOUGH)
        self.assertEqual((query_plan["stock_min"], query_plan["rank_by"], query_plan["questions"]), (1, "stock_desc", []))

    def test_long_prompt_plan(self):
        query_plan = plan(self.LONG)
        self.assertEqual(query_plan["category"], "sunscreen")
        self.assertEqual((query_plan["limit"], query_plan["stock_min"], query_plan["stock_max"]), (10, 1, 10))
        self.assertEqual((query_plan["rank_by"], query_plan["window_days"]), ("discount_slow", 30))
        self.assertEqual((query_plan["per_brand_max"], query_plan["brand"]), (2, None))
        self.assertEqual(query_plan["questions"], [])

    def test_scheduled_live_is_still_a_create_command(self):
        for prompt in (
            "Lọc 5 kem chống nắng còn đủ hàng để bán trong livestream lúc 20h tối mai.",
            "Lọc 3 sữa rửa mặt bán chạy nhất toàn kỳ, ưu tiên sản phẩm còn đủ hàng (đủ bán khi live), "
            "tạo livestream lúc 20h00 ngày 14/10/2026, thời lượng 60 phút, chỉ chiếu nội bộ xem trước.",
        ):
            with self.subTest(prompt=prompt):
                self.assertEqual(classify_intent(prompt).intent, CREATE_CAMPAIGN)


class RegressionTests(unittest.TestCase):
    def test_expiry_windows_unchanged(self):
        query_plan = plan("Tìm sản phẩm còn hạn sử dụng từ 17 đến 19 tháng.")
        self.assertEqual((query_plan["expiry_from"], query_plan["expiry_to"], query_plan["as_of"]),
                         ("2028-03-13", "2028-05-13", "2026-10-13"))
        self.assertEqual(query_plan["questions"], [])
        query_plan = plan("Tìm sản phẩm còn hạn sử dụng tối đa 18 tháng.")
        self.assertEqual((query_plan["expiry_from"], query_plan["expiry_to"]), ("2026-10-13", "2028-04-13"))

    def test_bare_filter(self):
        query_plan = plan("Lọc 5 sản phẩm.")
        self.assertEqual((query_plan["limit"], query_plan["rank_by"], query_plan["questions"]), (5, "catalog", []))
        self.assertEqual(classify_intent("Lọc 5 sản phẩm.").intent, PRODUCT_SEARCH)

    def test_legacy_categories(self):
        self.assertEqual(plan("Tìm sữa rửa mặt còn hạn sử dụng từ 17 đến 19 tháng.")["category"], "cleanser")
        prompt = "Tìm kem nền còn hạn sử dụng từ 17 đến 19 tháng"
        self.assertEqual(classify_intent(prompt).intent, PRODUCT_SEARCH)
        self.assertEqual(plan(prompt)["category"], "foundation")

    def test_compound_create_command_is_unchanged(self):
        prompt = ("Lọc 3 sữa rửa mặt bán chạy nhất toàn kỳ, tạo livestream lúc 20h00 ngày 14/10/2026, "
                  "thời lượng 60 phút, chỉ chiếu nội bộ xem trước.")
        self.assertEqual(classify_intent(prompt).intent, CREATE_CAMPAIGN)
        query_plan = plan(prompt)
        self.assertEqual((query_plan["limit"], query_plan["category"], query_plan["rank_by"]), (3, "cleanser", "units"))
        self.assertEqual((query_plan["window_days"], query_plan["questions"]), (None, []))
        result = run(prompt)
        self.assertEqual(ids(result), ["1", "2", "3"])
        self.assertTrue(result["complete"])

    def test_explicit_product_id_and_cancel(self):
        prompt = "Tối mai lúc 20 giờ, tạo livestream giới thiệu sản phẩm mã 93 an toàn."
        self.assertEqual(classify_intent(prompt).intent, CREATE_CAMPAIGN)
        self.assertEqual(fallback_parse(prompt, {}).explicit_product_ids, ["93"])
        self.assertEqual(classify_intent("Hủy campaign").intent, CANCEL_CAMPAIGN)

    def test_vague_product_request_is_not_a_catalog_search(self):
        self.assertFalse(catalog_search_requested("Cho tôi sản phẩm retinol"))


class ServiceRoutingTests(unittest.TestCase):
    def run_command(self, text, batches=None):
        run_doc = {"_id": "run-test", "brief": text, "status": "RECEIVED", "overrides": {"autonomy_policy": "assisted"}}

        def update(_, values):
            run_doc.update(values)

        enqueue = Mock()
        with patch("agents.service.get_agent_run", side_effect=lambda _: run_doc), \
             patch("agents.service.update_agent_run", side_effect=update), \
             patch("agents.service.create_campaign", return_value=("campaign-test", True)) as create, \
             patch("agents.service.LLMProvider") as provider, \
             patch("agents.service.parse_agent_brief") as parser:
            result = process_agent_run("run-test", catalog=CATALOG, batches=batches, enqueue_campaign=enqueue)
        provider.assert_not_called()
        parser.assert_not_called()
        create.assert_not_called()
        return result

    def test_category_search_returns_results_without_llm_or_campaign(self):
        result = self.run_command("Tìm sữa rửa mặt.")
        self.assertEqual((result["status"], result["current_stage"]), ("RESULTS_READY", "QUERY_RESULTS"))
        self.assertEqual(result["plan"]["intent_classification"]["intent"], PRODUCT_SEARCH)
        self.assertEqual(ids(result["plan"]["query_result"]), ["1", "2", "3"])

    def test_style_only_prompt_stops_at_query_results_with_question(self):
        result = self.run_command("Tìm sản phẩm, theo phong cách tươi vui, dễ thương.")
        self.assertEqual((result["status"], result["current_stage"]), ("NEEDS_INPUT", "QUERY_RESULTS"))
        self.assertEqual(result["plan"]["needs"], ["product"])
        self.assertIn(NO_CONDITION_QUESTION, result["plan"]["warnings"])

    def test_warehouse_scope_with_ledger(self):
        result = self.run_command("Tìm sản phẩm chỉ lấy từ kho AUTO-DEFAULT.", batches=BATCHES)
        self.assertEqual(result["status"], "RESULTS_READY")
        self.assertEqual(ids(result["plan"]["query_result"]), ["1"])

    def test_create_command_is_not_turned_into_a_catalog_query(self):
        text = ("Tìm kem chống nắng, tạo livestream lúc 20h00 ngày 14/10/2026, thời lượng 60 phút, "
                "chỉ chiếu nội bộ xem trước.")
        run_doc = {"_id": "run-test", "brief": text, "status": "RECEIVED", "overrides": {"autonomy_policy": "assisted"}}

        def update(_, values):
            run_doc.update(values)

        with patch("agents.service.get_agent_run", side_effect=lambda _: run_doc), \
             patch("agents.service.update_agent_run", side_effect=update), \
             patch("agents.service.create_campaign", return_value=("campaign-test", True)), \
             patch("agents.service.LLMProvider"), \
             patch("agents.service.parse_agent_brief", side_effect=lambda brief, overrides, llm=None: fallback_parse(brief, overrides)), \
             patch("agents.service.execute_query") as query:
            process_agent_run("run-test", catalog=CATALOG, enqueue_campaign=Mock())
        query.assert_not_called()
        self.assertEqual(run_doc["intent_classification"]["intent"], CREATE_CAMPAIGN)


class CatalogBrandJoinTests(unittest.TestCase):
    """Real san_pham rows only carry ma_thuong_hieu (int); the name lives in thuong_hieu (str id)."""

    def test_brand_name_joined_from_brand_collection(self):
        from agents.query_sources import load_catalog
        path = "Chăm Sóc Da Mặt -> Làm Sạch Da -> Sữa Rửa Mặt"
        rows = [dict(row("1", "Sữa Rửa Mặt CeraVe", path, ""), ma_thuong_hieu=7),
                dict(row("2", "Gel Rửa Mặt Eucerin", path, ""), ma_thuong_hieu=8),
                dict(row("3", "Sữa Rửa Mặt X", path, ""), ma_thuong_hieu=99)]
        for item in rows:
            item.pop("thuong_hieu", None)
        db = FakeDb(san_pham=Collection(rows), thuong_hieu=Collection([
            {"ma_thuong_hieu": "7", "ten_thuong_hieu": "CeraVe"},
            {"ma_thuong_hieu": "8", "ten_thuong_hieu": "Eucerin"}]))
        catalog = load_catalog(db)
        self.assertEqual([c.get("ten_thuong_hieu") for c in catalog], ["CeraVe", "Eucerin", None])
        text = "Tìm sữa rửa mặt ưu tiên nhiều sản phẩm của thương hiệu CeraVe."
        result = execute_query(build_query_plan(text, today=TODAY), catalog, brief=text)
        self.assertEqual(result["selected"][0]["product_id"], "1")
        self.assertEqual(result["selected"][0]["evidence"]["brand"], "CeraVe")


class BuilderQuantityTests(unittest.TestCase):
    """“Lọc N <danh mục>” từ Builder: N là số lượng với mọi danh mục, không chỉ sản phẩm/kem/sữa/gel/serum."""

    def limit(self, brief):
        return build_query_plan(brief, today=TODAY)["limit"]

    def test_count_before_any_category_phrase(self):
        self.assertEqual(self.limit("Lọc 3 mặt nạ giấy."), 3)
        self.assertEqual(self.limit("Lọc 5 dầu gội còn hạn sử dụng từ 17 đến 19 tháng."), 5)
        self.assertEqual(self.limit("Lọc 1 sữa tắm."), 1)
        self.assertEqual(self.limit("Lọc 10 son môi tồn kho trên 100 sản phẩm."), 10)

    def test_existing_unit_forms_still_work(self):
        self.assertEqual(self.limit("Lọc 7 kem chống nắng."), 7)
        self.assertEqual(self.limit("Tìm top 4 sản phẩm bán chạy nhất trong 30 ngày qua."), 4)

    def test_period_or_threshold_after_verb_is_not_a_count(self):
        self.assertEqual(self.limit("Lọc 7 ngày qua các sản phẩm bán tốt."), 10)
        self.assertEqual(self.limit("Lọc sản phẩm tồn kho trên 100 sản phẩm."), 10)


if __name__ == "__main__":
    unittest.main()
