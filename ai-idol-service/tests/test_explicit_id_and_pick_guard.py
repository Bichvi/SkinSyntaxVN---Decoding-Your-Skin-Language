"""Product ids written as "sản phẩm 98" and the autopilot guard against picking an unrelated product."""
import os
import sys
import unittest

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from agents.autopilot_query import set_query_signals
from agents.intent_parser import fallback_parse
from tests.test_autopilot_service import AutopilotHarness, codes, decision, row


def ids(text):
    return fallback_parse(text).explicit_product_ids


class ExplicitIdAfterProductWordTests(unittest.TestCase):
    def test_ids_are_read(self):
        cases = {
            "Tạo video giới thiệu sản phẩm 98": ["98"],
            "Tạo video cho sản phẩm 98": ["98"],
            "Cho tôi xem sp 101": ["101"],
            "So sánh sản phẩm 68 và 101": ["68", "101"],
            "Tạo 2 video cho sản phẩm 98 và 68": ["98", "68"],
            "Liệt kê theo lô hàng sản phẩm 101": ["101"],
            "sản phẩm mã 98": ["98"],
        }
        for text, expected in cases.items():
            with self.subTest(text=text):
                self.assertEqual(ids(text), expected)

    def test_quantities_and_units_are_not_ids(self):
        for text in (
            "Top 3 sản phẩm 30 ngày", "Top 5 sản phẩm 7 ngày qua", "sản phẩm 50ml", "sản phẩm 1.0%",
            "sản phẩm 20/11", "livestream sản phẩm 9h tối", "5 sản phẩm chống nắng",
            "Mỗi thương hiệu tối đa 2 sản phẩm, lấy 10 sản phẩm dưỡng ẩm", "sản phẩm 200k",
            "sản phẩm 2 món", "Cho tôi 200 sản phẩm",
        ):
            with self.subTest(text=text):
                self.assertEqual(ids(text), [])

    def test_explicit_ids_keep_the_name_path(self):
        self.assertIsNone(set_query_signals("Tạo 2 video cho sản phẩm 98 và 68"))


CATALOG = [
    row(98, "Kem Dưỡng Obagi Retinol 1.0% Trẻ Hóa Da 28g"),
    row(11, "Sữa Tắm Dưỡng Thể Dove Tươi Mát 900g"),
    row(12, "Mặt Nạ Dermal Tinh Chất Dưỡng Sáng Da 23g"),
]


class AutopilotPickGuardTests(unittest.TestCase):
    def test_single_explicit_id(self):
        harness = AutopilotHarness("Tạo video giới thiệu sản phẩm 98")
        run = harness.execute(CATALOG)
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.payload["product_ids"], ["98"])

    def test_two_explicit_ids(self):
        harness = AutopilotHarness("Tạo 2 video cho sản phẩm 98 và 68")
        run = harness.execute([*CATALOG, row(68, "Serum Timeless Vitamin B5 30ml")])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(set(harness.payload["product_ids"]), {"98", "68"})

    def test_unclear_name_stops(self):
        catalog = [
            row(1, "Sữa Rửa Mặt Cerave Dịu Nhẹ"),
            row(2, "Toner Klairs Cân Bằng Da"),
            row(3, "Kem Chống Nắng Torriden"),
        ]
        harness = AutopilotHarness("Cho tôi xem toner")
        run = harness.execute(catalog)
        harness.create.assert_not_called()
        last = run["decisions"][-1]
        self.assertEqual((last["code"], last["level"]), ("NAME_UNCLEAR", "stopped"))
        self.assertTrue(run["plan"]["candidate_products"])

    def test_supported_pick_still_works(self):
        catalog = [
            row(21, "Kem Chống Nắng Beplain Nâng Tông Hồng Đào 50ml"),
            row(22, "Kem Chống Nắng Beplain Kiềm Dầu 50ml"),
            row(23, "Sữa Tắm Dove Tươi Mát 900g"),
        ]
        harness = AutopilotHarness("Livestream kem chống nắng Beplain lúc 20h tối")
        run = harness.execute(catalog)
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertTrue(set(harness.payload["product_ids"]) <= {"21", "22"})
        self.assertTrue(harness.payload["product_ids"])
        self.assertNotIn("NAME_UNCLEAR", codes(run))


if __name__ == "__main__":
    unittest.main()
