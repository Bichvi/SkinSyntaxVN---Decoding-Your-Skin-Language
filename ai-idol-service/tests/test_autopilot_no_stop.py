"""Autopilot (Studio "Tự động") decides fixable ambiguity itself: every default is an "adjusted" decision."""
import datetime as dt
import functools
import os
import sys
import unittest
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from agents.catalog_constraints import CatalogVocabulary
from agents.command_query import build_query_plan
from agents.service import process_agent_run
from agents.intent_parser import parse_agent_brief
from tests.test_agent import AgentRunHarness
from tests.test_autopilot_service import NOW, AutopilotHarness, codes, decision, snapshot

BRANDS = ["Eucerin", "Some By Mi", "La Roche-Posay", "Vichy", "Anessa", "Cosrx"]
VOCAB = CatalogVocabulary.from_rows(
    [{"ma_thuong_hieu": str(i), "ten_thuong_hieu": name} for i, name in enumerate(BRANDS, 1)], [], [], [])
CLEANSER = "Chăm sóc da mặt -> Làm sạch da -> Sữa rửa mặt"
SUNSCREEN = "Chăm sóc da mặt -> Chống nắng"
MOISTURIZER = "Chăm sóc da mặt -> Dưỡng ẩm -> Kem / Gel / Dầu dưỡng"


def item(code, name, brand="Vichy", stock=10, sold=0, category=SUNSCREEN, **extra):
    return {"ma_san_pham": code, "ten_san_pham": name, "ten_thuong_hieu": brand, "so_luong_ton_kho": stock,
            "so_luong_da_ban": sold, "danh_muc_day_du": category, "gia_ban": 200000, **extra}


class NoStopHarness(AutopilotHarness):
    """Autopilot run with a fixed vocabulary instead of Mongo lookups."""

    def execute(self, catalog, *, snapshots=None, batches=None, **kwargs):
        pinned = functools.partial(process_agent_run, now=NOW, snapshots=snapshots, batches=batches)
        with patch("tests.test_agent.process_agent_run", new=pinned), \
                patch("agents.service.load_vocabulary", return_value=VOCAB), \
                patch("agents.service.get_db", return_value=object()):
            return super(AutopilotHarness, self).execute(catalog, **kwargs)

    @property
    def ids(self):
        return self.payload["product_ids"]


def query_of(run):
    return run["plan"]["query_result"]["query"]


def adjusted(run):
    return [d for d in run["decisions"] if d["level"] == "adjusted"]


class NoStopQuestionTests(unittest.TestCase):
    def created(self, brief, catalog, **kwargs):
        harness = NoStopHarness(brief)
        run = harness.execute(catalog, **kwargs)
        self.assertEqual(run["status"], "CAMPAIGN_CREATED", [d["message"] for d in run.get("decisions", [])])
        self.assertNotIn("QUERY_UNCLEAR", codes(run))
        return harness, run

    def test_a1_this_week_uses_the_last_7_completed_days(self):
        catalog = [item(100 + i, f"Kem Chống Nắng {i}") for i in range(6)]
        snaps = [snapshot(100 + i, 10 - i, window=7) for i in range(6)]
        harness, run = self.created("Livestream 5 sản phẩm bán chạy nhất tuần này", catalog, snapshots=snaps)
        self.assertEqual(query_of(run)["window_days"], 7)
        self.assertEqual(harness.ids, ["100", "101", "102", "103", "104"])
        self.assertTrue(any("7 ngày" in d["message"] for d in adjusted(run)))

    def test_a2_about_n_months_means_n_minus_1_to_n_plus_1(self):
        catalog = [item(100, "Kem A"), item(101, "Kem B"), item(102, "Kem C")]
        batches = [
            {"product_id": "100", "batch_id": "L1", "status": "available", "available_quantity": 5, "expires_on": "2028-04-10"},
            {"product_id": "101", "batch_id": "L2", "status": "available", "available_quantity": 5, "expires_on": "2027-01-10"},
            {"product_id": "102", "batch_id": "L3", "status": "available", "available_quantity": 5, "expires_on": "2028-05-01"},
        ]
        harness, run = self.created("Làm video các sản phẩm sắp hết hạn trong khoảng 18 tháng", catalog, batches=batches)
        query = query_of(run)
        self.assertEqual((query["expiry_from"], query["expiry_to"]), ("2028-03-15", "2028-05-15"))
        self.assertEqual(sorted(harness.ids), ["100", "102"])
        self.assertTrue(any("17" in d["message"] and "19" in d["message"] for d in adjusted(run)))

    def test_still_valid_for_a_range_of_months_uses_the_batch_ledger(self):
        catalog = [item(100, "Kem A", sold=5), item(101, "Kem B", sold=5)]
        batches = [
            {"product_id": "100", "batch_id": "L1", "status": "available", "available_quantity": 5, "expires_on": "2028-04-10"},
            {"product_id": "101", "batch_id": "L2", "status": "available", "available_quantity": 5, "expires_on": "2027-01-10"},
        ]
        harness, run = self.created("Làm video sản phẩm còn hạn từ 17 đến 19 tháng", catalog, batches=batches)
        self.assertEqual(harness.ids, ["100"])

    def test_a3_low_stock_defaults_to_at_most_10(self):
        stocks = [3, 5, 8, 50, 200, 0]
        catalog = [item(100 + i, f"Kem {i}", stock=s) for i, s in enumerate(stocks)]
        harness, run = self.created("Xả hàng tồn kho thấp, làm video 5 sản phẩm", catalog)
        self.assertEqual(query_of(run)["stock_max"], 10)
        self.assertEqual(harness.ids, ["100", "101", "102"])
        self.assertTrue(any("10" in d["message"] for d in adjusted(run)))

    def test_a4_high_stock_slow_selling_maps_to_the_slow_metric(self):
        rows = [(100, 100, 0), (101, 100, 50), (102, 10, 0), (103, 0, 0), (104, 40, 1), (105, 5, 9), (106, 60, 2)]
        catalog = [item(code, f"Kem {code}", stock=stock, sold=sold) for code, stock, sold in rows]
        harness, run = self.created("Làm video 5 sản phẩm tồn kho nhiều nhưng bán chậm", catalog)
        self.assertEqual(query_of(run)["rank_by"], "units_asc")
        self.assertEqual(harness.ids[0], "100")
        self.assertNotIn("103", harness.ids)  # sold out is not "high stock"
        self.assertTrue(any("chậm" in d["message"] for d in adjusted(run)))

    def test_a5_many_views_means_views_without_orders(self):
        catalog = [item(100 + i, f"Kem {i}", sold=20 - i) for i in range(6)]
        harness, run = self.created("Làm video 5 sản phẩm xem nhiều nhưng chưa ai mua", catalog)
        self.assertTrue(any("chưa có phiên mua" in d["message"] for d in adjusted(run)))
        self.assertEqual(len(harness.ids), 5)

    def test_a6_except_sold_out_means_in_stock_only(self):
        catalog = [item(100 + i, f"Kem Trị Mụn {i}", stock=0 if i % 2 else 7, sold=9 - i, loai_da="Da mụn") for i in range(8)]
        harness, run = self.created("Làm video 5 sản phẩm trị mụn, trừ sản phẩm đã hết hàng", catalog)
        self.assertEqual(query_of(run)["stock_min"], 1)
        self.assertTrue(set(harness.ids) <= {"100", "102", "104", "106"})
        self.assertTrue(any("hết hàng" in d["message"] for d in adjusted(run)))

    def test_a7_two_categories_are_both_taken_and_split(self):
        catalog = [item(100 + i, f"Sữa Rửa Mặt Gel {i}", category=CLEANSER, sold=30 - i) for i in range(4)]
        catalog += [item(200 + i, f"Kem Chống Nắng {i}", sold=10 - i) for i in range(4)]
        harness, run = self.created("Làm video 5 sữa rửa mặt và kem chống nắng bán chạy", catalog)
        cleansers = [pid for pid in harness.ids if pid.startswith("1")]
        sunscreens = [pid for pid in harness.ids if pid.startswith("2")]
        self.assertEqual(len(harness.ids), 5)
        self.assertGreaterEqual(min(len(cleansers), len(sunscreens)), 2)
        self.assertTrue(any("chia đều" in d["message"] for d in adjusted(run)))

    def test_a8_discounted_but_slow_uses_lifetime_sales(self):
        catalog = [item(100 + i, f"Kem {i}", sold=i * 5, gia_thi_truong=300000) for i in range(6)]
        catalog += [item(300, "Kem Không Giảm", sold=0, gia_thi_truong=200000)]
        harness, run = self.created("Làm video 5 sản phẩm giảm giá mà bán chậm", catalog)
        self.assertEqual(harness.ids, ["100", "101", "102", "103", "104"])
        self.assertIn("METRICS_FALLBACK", codes(run))

    def test_a8_nothing_discounted_relaxes_to_top_sellers(self):
        catalog = [item(100 + i, f"Kem {i}", sold=i) for i in range(7)]
        harness, run = self.created("Làm video 5 sản phẩm giảm giá mà bán chậm", catalog)
        self.assertEqual(len(harness.ids), 5)
        self.assertEqual(decision(run, "QUERY_RELAXED")["level"], "adjusted")
        self.assertNotIn("NO_PRODUCTS", codes(run))

    def test_relaxation_drops_a_hard_constraint_before_giving_up(self):
        catalog = [item(100 + i, f"Kem Chống Nắng {i}", sold=i, ten_xuat_xu="Nhật Bản") for i in range(4)]
        harness, run = self.created("Làm video 5 kem chống nắng xuất xứ Nhật Bản dưới 100 nghìn", catalog)
        message = decision(run, "QUERY_RELAXED")["message"]
        self.assertIn("giá", message)
        self.assertEqual(len(harness.ids), 4)

    def test_an_empty_catalog_is_the_only_dead_end(self):
        harness = NoStopHarness("Làm video 5 sản phẩm bán chạy")
        run = harness.execute([])
        harness.create.assert_not_called()
        self.assertEqual(run["decisions"][-1]["code"], "NO_PRODUCTS")


class NoStopRoutingTests(unittest.TestCase):
    def run_brief(self, brief, catalog):
        harness = NoStopHarness(brief)
        run = harness.execute(catalog)
        self.assertEqual(run["status"], "CAMPAIGN_CREATED", [d["message"] for d in run.get("decisions", [])])
        return harness, run

    def test_five_products_of_a_brand_are_all_that_brand(self):
        catalog = [item(100 + i, f"Eucerin Dưỡng Da {i}", brand="Eucerin", sold=i) for i in range(6)]
        catalog += [item(200 + i, f"Kem B.O.M {i}", brand="Cosrx", sold=50) for i in range(3)]
        harness, run = self.run_brief("Làm video 5 sản phẩm của Eucerin", catalog)
        self.assertEqual(len(harness.ids), 5)
        self.assertTrue(all(pid.startswith("1") for pid in harness.ids))

    def test_brand_exclusion_is_honoured(self):
        catalog = [item(100 + i, f"Toner Some By Mi {i}", brand="Some By Mi", sold=90, loai_da="Da khô") for i in range(3)]
        catalog += [item(200 + i, f"Toner Cosrx {i}", brand="Cosrx", sold=i, loai_da="Da khô") for i in range(4)]
        harness, run = self.run_brief("Làm video 3 toner cho da khô, không lấy thương hiệu Some By Mi", catalog)
        self.assertEqual(len(harness.ids), 3)
        self.assertTrue(all(pid.startswith("2") for pid in harness.ids))

    def test_origin_and_price_filter_a_set(self):
        catalog = [
            item(100, "Kem Chống Nắng Anessa", brand="Anessa", ten_xuat_xu="Nhật Bản", gia_ban=450000),
            item(101, "Kem Chống Nắng Nhật Đắt", brand="Anessa", ten_xuat_xu="Nhật Bản", gia_ban=700000),
            item(102, "Kem Chống Nắng La Roche-Posay", brand="La Roche-Posay", ten_xuat_xu="Pháp", gia_ban=300000),
        ]
        harness, run = self.run_brief("Làm video kem chống nắng xuất xứ Nhật Bản dưới 500 nghìn", catalog)
        self.assertEqual(harness.ids, ["100"])

    def test_origin_without_a_prefix_and_price_in_k(self):
        catalog = [item(100 + i, f"Kem Dưỡng Ẩm {i}", category=MOISTURIZER, ten_xuat_xu="Hàn Quốc",
                        gia_ban=250000, sold=i) for i in range(6)]
        catalog += [item(200, "Kem Dưỡng Ẩm Pháp", category=MOISTURIZER, ten_xuat_xu="Pháp", gia_ban=250000, sold=99)]
        harness, run = self.run_brief("Làm video 5 kem dưỡng ẩm Hàn Quốc giá dưới 300k", catalog)
        self.assertEqual(len(harness.ids), 5)
        self.assertNotIn("200", harness.ids)

    def test_hot_means_best_sellers(self):
        catalog = [item(100 + i, f"Kem {i}", sold=i) for i in range(8)]
        harness, run = self.run_brief("Livestream 5 sản phẩm hot trên YouTube tối nay 8h", catalog)
        self.assertEqual(harness.ids, ["107", "106", "105", "104", "103"])

    def test_plural_wording_without_a_number_is_a_set(self):
        catalog = [item(100 + i, f"Kem Dưỡng {i}", category="Chăm sóc da mặt -> Dưỡng ẩm", sold=i) for i in range(8)]
        harness, run = self.run_brief("Làm video cho mấy sản phẩm chăm sóc da mặt hot hit", catalog)
        self.assertGreater(len(harness.ids), 1)

    def test_a_single_product_id_stays_on_the_id_path(self):
        harness, run = self.run_brief("Làm video giới thiệu sản phẩm mã 995", [item(995, "Kem Dưỡng Vichy")])
        self.assertEqual(harness.ids, ["995"])
        self.assertIsNone(run["plan"]["query_result"])
        self.assertNotIn("QUERY_DEFAULTED", codes(run))

    def test_pure_category_products_come_before_combos(self):
        catalog = [item(100, "Vichy Kem Dưỡng + Gel Rửa Mặt", category=CLEANSER, sold=99),
                   item(101, "SVR Retinol + Gel Rửa Mặt", category=CLEANSER, sold=98)]
        catalog += [item(200 + i, f"Sữa Rửa Mặt Gel {i}", category=CLEANSER, sold=i) for i in range(3)]
        harness, run = self.run_brief("Làm video 3 sữa rửa mặt cho da dầu", [
            {**row, "loai_da": "Da dầu"} for row in catalog])
        self.assertEqual(sorted(harness.ids), ["200", "201", "202"])


class NoStopCountAndPurposeTests(unittest.TestCase):
    def test_c_count_for_any_product_noun(self):
        for brief in ("Làm video 5 son môi bán chạy", "Làm video 5 dầu gội bán chạy",
                      "Làm video 5 mặt nạ bán chạy 2 tuần qua"):
            plan = build_query_plan(brief, autopilot=True)
            self.assertEqual(plan["limit"], 5, brief)
            self.assertTrue(plan["limit_explicit"], brief)
        self.assertEqual(build_query_plan("Làm video 5 mặt nạ bán chạy 2 tuần qua", autopilot=True)["window_days"], 7)

    def test_c_bounds_and_periods_are_not_counts(self):
        for brief in ("Làm video sản phẩm còn hạn từ 17 đến 19 tháng", "Làm video kem chống nắng dưới 500 nghìn",
                      "Làm video sản phẩm bán chạy trong 30 ngày qua", "Làm video sản phẩm hạn dùng trên 12 tháng"):
            self.assertFalse(build_query_plan(brief, autopilot=True)["limit_explicit"], brief)

    def test_manual_planning_is_unchanged(self):
        self.assertEqual(build_query_plan("Làm video 5 son môi bán chạy")["limit"], 10)
        manual = build_query_plan("Lọc 5 sữa rửa mặt và kem chống nắng bán chạy")
        self.assertTrue(manual["questions"])
        self.assertNotIn("autopilot_notes", manual)
        self.assertTrue(build_query_plan("Xả hàng tồn kho thấp")["questions"])
        self.assertTrue(build_query_plan("Livestream 5 sản phẩm bán chạy nhất tuần này")["questions"])

    def test_d_llm_purpose_never_turns_a_plain_video_into_a_livestream(self):
        def livestream_happy(*args, **kwargs):
            brief = parse_agent_brief(*args, **kwargs)
            brief.purpose = "live_and_product"
            return brief

        with patch("agents.service.parse_agent_brief", side_effect=livestream_happy):
            harness = NoStopHarness("Làm video giới thiệu sản phẩm mã 995")
            run = harness.execute([item(995, "Kem Dưỡng Vichy")])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.payload["purpose"], "product_video")
        self.assertEqual(harness.payload["platforms"], [])

    def test_d_livestream_wording_keeps_the_broadcast(self):
        harness = NoStopHarness("Livestream giới thiệu sản phẩm mã 995")
        run = harness.execute([item(995, "Kem Dưỡng Vichy")])
        self.assertEqual(harness.payload["purpose"], "live_and_product")

    def test_d_a_made_up_time_slot_is_not_an_appointment(self):
        def invents_a_slot(*args, **kwargs):
            brief = parse_agent_brief(*args, **kwargs)
            brief.scheduled_at = "2026-10-16T20:00:00+07:00"
            return brief

        with patch("agents.service.parse_agent_brief", side_effect=invents_a_slot):
            harness = NoStopHarness("Làm video giới thiệu sản phẩm mã 995")
            harness.execute([item(995, "Kem Dưỡng Vichy")])
        self.assertEqual(harness.payload["purpose"], "product_video")
        self.assertIsNone(harness.payload["scheduled_at"])


if __name__ == "__main__":
    unittest.main()


class NoStopRoundTwoTests(unittest.TestCase):
    def created(self, brief, catalog, **kwargs):
        harness = NoStopHarness(brief)
        run = harness.execute(catalog, **kwargs)
        self.assertEqual(run["status"], "CAMPAIGN_CREATED", [d["message"] for d in run.get("decisions", [])])
        return harness, run

    def test_short_period_ranking_is_topped_up_from_30_days_then_lifetime(self):
        catalog = [item(100 + i, f"Kem Chống Nắng {i}", sold=50 - i) for i in range(8)]
        snaps = [snapshot(105, 9, window=7), snapshot(103, 5, window=30), snapshot(102, 4, window=30)]
        harness, run = self.created("Livestream 5 sản phẩm bán chạy nhất tuần này", catalog, snapshots=snaps)
        self.assertEqual(harness.ids, ["105", "103", "102", "100", "101"])
        message = decision(run, "METRICS_FALLBACK")["message"]
        self.assertEqual(decision(run, "METRICS_FALLBACK")["level"], "adjusted")
        self.assertIn("30 ngày", message)
        self.assertIn("toàn kỳ", message)
        self.assertNotIn("COUNT_SHORT", codes(run))

    def test_need_words_rank_matching_products_first_and_filter_when_enough(self):
        catalog = [item(100 + i, f"Gel Trị Mụn {i}", sold=i) for i in range(3)]
        catalog += [item(200 + i, f"Kem Che Khuyết Điểm {i}", sold=90 - i) for i in range(5)]
        harness, run = self.created("Làm video 3 sản phẩm trị mụn, trừ sản phẩm đã hết hàng", catalog)
        self.assertEqual(sorted(harness.ids), ["100", "101", "102"])

    def test_not_enough_need_matches_are_topped_up_with_a_note(self):
        catalog = [item(100 + i, f"Gel Trị Mụn {chr(65 + i)}", sold=i) for i in range(2)]
        catalog += [item(200 + i, f"Kem Khác {i}", sold=90 - i) for i in range(5)]
        harness, run = self.created("Làm video 5 sản phẩm trị mụn", catalog)
        self.assertEqual(len(harness.ids), 5)
        self.assertTrue({"100", "101"} <= set(harness.ids[:2]))
        self.assertEqual(decision(run, "NEED_RELAXED")["level"], "adjusted")

    def test_serum_category_excludes_sunscreen_essences(self):
        serums = [item(100 + i, f"Serum Vitamin C {i}", sold=i, category="Chăm sóc da mặt -> Serum") for i in range(3)]
        sun = [item(200 + i, f"Tinh Chất Chống Nắng Vitamin C {i}", sold=99) for i in range(5)]
        harness, run = self.created("Làm video top 5 serum vitamin C", serums + sun)
        self.assertTrue(all(pid.startswith("1") for pid in harness.ids))

    def test_sensitive_skin_and_fragrance_free_prefer_matching_products(self):
        catalog = [item(100 + i, f"Kem Dịu {i}", sold=i, loai_da="Da nhạy cảm") for i in range(6)]
        catalog += [item(200 + i, f"Kem Bán Chạy {i}", sold=90 - i, loai_da="Da dầu") for i in range(6)]
        harness, run = self.created("Làm video 5 sản phẩm không chứa hương liệu cho da nhạy cảm", catalog)
        self.assertTrue(all(pid.startswith("1") for pid in harness.ids), harness.ids)

    def test_brightening_matches_name_keywords(self):
        catalog = [item(100 + i, f"Serum Dưỡng Trắng Sáng Da {i}", sold=i) for i in range(3)]
        catalog += [item(200 + i, f"Kem Khác {i}", sold=80 - i) for i in range(5)]
        harness, run = self.created("Quảng bá 5 sản phẩm dưỡng trắng cho mùa hè", catalog)
        self.assertTrue({"100", "101", "102"} <= set(harness.ids))

    def test_expiry_range_goes_to_the_query_path_even_if_a_name_is_close(self):
        catalog = [item(100, "Kem Còn Hạn Từ Vichy", sold=5)]
        batches = [{"product_id": "100", "batch_id": "L1", "status": "available", "available_quantity": 5, "expires_on": "2028-04-10"}]
        harness, run = self.created("Làm video sản phẩm còn hạn từ 17 đến 19 tháng", catalog, batches=batches)
        self.assertIsNotNone(run["plan"]["query_result"])

    def test_one_variant_per_product_line(self):
        catalog = [item(100, "Eucerin Gel Rửa Mặt 200ml", brand="Eucerin", sold=9),
                   item(101, "Eucerin Gel Rửa Mặt 400ml", brand="Eucerin", sold=8),
                   item(102, "Eucerin Gel Rửa Mặt 75ml", brand="Eucerin", sold=7),
                   item(103, "Eucerin Kem Dưỡng", brand="Eucerin", sold=5),
                   item(104, "Eucerin Serum", brand="Eucerin", sold=4)]
        harness, run = self.created("Làm video 3 sản phẩm của Eucerin", catalog)
        self.assertEqual(harness.ids, ["100", "103", "104"])

    def test_variants_fill_a_shortfall(self):
        catalog = [item(100, "Eucerin Gel 200ml", brand="Eucerin", sold=9), item(101, "Eucerin Gel 400ml", brand="Eucerin", sold=8)]
        harness, run = self.created("Làm video 2 sản phẩm của Eucerin", catalog)
        self.assertEqual(harness.ids, ["100", "101"])


class ProductLineKeyTests(unittest.TestCase):
    def test_shades_of_one_line_share_a_key(self):
        from agents.command_query import line_key
        a = line_key("Bút Cushion Che Khuyết Điểm Maybelline 110 Fair Ivory 6ml", "Maybelline")
        b = line_key("Bút Cushion Che Khuyết Điểm Maybelline 120 Light 6ml", "Maybelline")
        self.assertEqual(a, b)

    def test_different_product_types_of_one_brand_differ(self):
        from agents.command_query import line_key
        self.assertNotEqual(line_key("Gel Rửa Mặt Eucerin Cho Da Nhờn 200ml", "Eucerin"),
                            line_key("Kem Chống Nắng Eucerin Kiềm Dầu 50ml", "Eucerin"))
