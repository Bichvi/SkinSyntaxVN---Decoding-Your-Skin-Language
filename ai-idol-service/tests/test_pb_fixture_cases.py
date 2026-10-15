"""pb-fixture-v1: mọi lựa chọn của Prompt Builder chạy được trên dataset sinh ra, kiểm bằng engine thật + oracle độc lập."""
import datetime as dt
import os
import sys
import unittest
from collections import Counter

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from fixtures.prompt_builder import config as cfg
from fixtures.prompt_builder.cases import (
    FRAGMENTS, FOCUS_PATHS, NODES, _members, _pid, build_cases, compose_brief, expected_result, make_ctx, run_offline,
)
from fixtures.prompt_builder.dataset import build_dataset, snapshot_docs
from tests.pb_catalog import build_catalog

CATALOG = build_catalog()
_runs = {}


def offline(today):
    if today not in _runs:
        data = build_dataset(CATALOG.products, today, cfg.DEFAULT_SEED)
        snapshots = snapshot_docs(data, CATALOG.products, today)
        _runs[today] = (data, snapshots, run_offline(data, CATALOG.products, today, snapshots))
    return _runs[today]


class ComposeBriefTests(unittest.TestCase):
    """Các câu dưới đây là kết quả của chính hàm buildAgentPrompt() trong ai-idol-studio.js (chạy bằng Node)."""

    def test_compose_brief_matches_js_examples(self):
        cases = [
            ({"category": ("face", "sunscreen", "face_sunscreen"), "expiry": "expiry_17_19", "quantity": "10"},
             "Lọc 10 kem chống nắng còn hạn sử dụng từ 17 đến 19 tháng."),
            ({"category": ("face",), "stock": "stock_low", "brand": "Cocoon", "brand_cap": True, "enough_stock": True},
             "Tìm sản phẩm chăm sóc da mặt tồn kho tối đa 10 sản phẩm, còn hàng, ưu tiên nhiều sản phẩm của thương hiệu Cocoon, "
             "mỗi thương hiệu tối đa 2 sản phẩm, ưu tiên sản phẩm còn đủ hàng (đủ bán khi live)."),
            ({"category": ("face", "serum"), "sales": "growth_7", "warehouse_mode": "warehouse", "scope_code": "KHO-DN-01"},
             "Tìm serum có doanh số tăng mạnh trong 7 ngày qua so với 7 ngày trước đó, chỉ lấy từ kho KHO-DN-01."),
            ({"category": ("hair", "hair_cleanse", "shampoo"), "stock": "out_of_stock", "enough_stock": True, "quantity": "3"},
             "Lọc 3 dầu gội đã hết hàng."),
            ({"brand": "L'Oreal"}, "Tìm sản phẩm ưu tiên nhiều sản phẩm của thương hiệu L'Oreal."),
            ({"category": ("makeup", "base"), "expiry": "expiry_max_18", "warehouse_mode": "batch"},
             "Tìm sản phẩm trang điểm nền còn hạn sử dụng tối đa 18 tháng, chỉ lấy từ lô hàng [chưa nhập mã lô]."),
        ]
        for sel, expected in cases:
            with self.subTest(expected=expected):
                self.assertEqual(compose_brief(sel), expected)

    def test_builder_taxonomy_is_complete(self):
        self.assertEqual(sum(1 for p in NODES if len(p) == 1), 4)
        self.assertEqual(sum(1 for p in NODES if len(p) == 2), 12)
        self.assertEqual(sum(1 for p in NODES if len(p) == 3), 21)
        self.assertEqual(len(FRAGMENTS["sales"]) - 1, 8)


class OfflineMatrixTests(unittest.TestCase):
    def assert_all_pass(self, today):
        _, _, results = offline(today)
        failed = [r for r in results if r["failures"] and not r["known_gap"]]
        self.assertEqual([(r["case"], r["brief"], r["failures"]) for r in failed], [])
        return results

    def test_all_cases_pass_offline(self):
        results = self.assert_all_pass(dt.date(2026, 10, 14))
        self.assertGreater(len(results), 150)
        groups = Counter(r["group"] for r in results)
        for group in ("category", "inventory", "scope", "sales", "brand", "quantity", "combo"):
            self.assertGreater(groups[group], 0, group)
        by_name = {r["case"]: r for r in results}
        self.assertEqual(by_name["inventory:sunscreen:expiry_17_19:out_of_stock"]["status"], "EMPTY_RESULTS")
        for r in results:
            if r["group"] in {"inventory", "scope"} and not r["known_gap"]:
                self.assertTrue(r["complete"], r)
            if r["group"] == "category":
                self.assertGreaterEqual(r["selected"], 1, r["case"])

    def test_cases_pass_on_other_day(self):
        self.assert_all_pass(dt.date(2027, 2, 3))

    def test_every_sales_criterion_returns_results(self):
        _, _, results = offline(dt.date(2026, 10, 14))
        for r in results:
            if r["group"] == "sales":
                self.assertEqual(r["status"], "RESULTS_READY", r)

    def test_oracle_agrees_with_designed_boundaries(self):
        data, snapshots, _ = offline(dt.date(2026, 10, 14))
        ctx = make_ctx(data, CATALOG.products, dt.date(2026, 10, 14), snapshots)
        sunscreen = FOCUS_PATHS["sunscreen"]

        def matched(**sel):
            return [r["product_id"] for r in expected_result({"category": sunscreen, **sel}, ctx)["rows"]]

        members = {_pid(r) for r in _members(ctx, sunscreen)}
        edge = {name: [pid for pid in pids if pid in members] for name, pids in data["manifest"]["profiles"].items()}
        window = matched(expiry="expiry_17_19")
        for pid in edge["w1719_edge_low"] + edge["w1719_edge_high"]:
            self.assertIn(pid, window)
        for pid in edge["w1719_out_low"] + edge["w1719_out_high"] + edge["wrong_status"]:
            self.assertNotIn(pid, window)
        for letter in "AC":
            self.assertNotIn(data["manifest"]["anchors"][letter], window)
        ceiling = matched(expiry="expiry_max_18")
        self.assertIn(edge["le18_edge_in"][0], ceiling)
        self.assertNotIn(edge["le18_edge_out"][0], ceiling)
        high = matched(stock="stock_high")
        self.assertIn(edge["stock_101"][0], high)
        self.assertNotIn(edge["stock_100"][0], high)
        for letter in "AB":
            self.assertIn(data["manifest"]["anchors"][letter], high)
        out = matched(stock="out_of_stock")
        for pid in edge["wrong_status"] + edge["expired_status"] + edge["expired_dirty"] + edge["stock_zero"]:
            self.assertIn(pid, out)
        self.assertGreaterEqual(len(matched(expiry="expiry_17_19", stock="stock_high")), 3)
        self.assertGreaterEqual(len(matched(expiry="expiry_17_19", stock="stock_low")), 3)
        self.assertEqual(matched(expiry="expiry_17_19", stock="out_of_stock"), [])

    def test_no_known_engine_gaps_and_quantity_cases_pass(self):
        """Mọi ca 'Lọc N <danh mục>' (kể cả mặt nạ giấy, dầu gội) phải trả đúng N; không còn ca nào được miễn."""
        _, _, results = offline(dt.date(2026, 10, 14))
        self.assertEqual([r["case"] for r in results if r["known_gap"]], [])
        quantity = [r for r in results if r["case"].startswith("quantity:")]
        self.assertTrue(any(r["case"].startswith(("quantity:sheet_mask:", "quantity:shampoo:")) for r in quantity))
        for r in quantity:
            self.assertEqual(r["failures"], [], r["case"])

    def test_scope_batch_code_is_globally_unique(self):
        data, _, _ = offline(dt.date(2026, 10, 14))
        code = data["manifest"]["scope_batch_code"]
        self.assertEqual(sum(1 for b in data["inventory_batches"] if b["batch_code"] == code), 1)

    def test_case_names_are_unique(self):
        data, snapshots, _ = offline(dt.date(2026, 10, 14))
        names = [c.name for c in build_cases(make_ctx(data, CATALOG.products, dt.date(2026, 10, 14), snapshots))]
        self.assertEqual(len(names), len(set(names)))


if __name__ == "__main__":
    unittest.main()
