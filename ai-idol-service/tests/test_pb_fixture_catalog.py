"""pb-fixture-v1: phân loại sản phẩm và chọn sản phẩm khôi phục từ backup."""
import os
import random
import sys
import unittest
from collections import Counter

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from agents.command_query import CATEGORY_ALIASES, CATEGORY_PATHS
from fixtures.prompt_builder import config as cfg
from fixtures.prompt_builder.catalog_pick import (
    classify_product, pick_restore_candidates, popularity_prior, shelf_life_months,
)

ROOT = "Sức Khỏe - Làm Đẹp -> "


def row(pid, path, name="Sản phẩm", **extra):
    return {"ma_san_pham": pid, "ten_san_pham": name, "danh_muc_day_du": ROOT + path, "gia_ban": 100000, **extra}


class ClassifyTests(unittest.TestCase):
    def test_real_catalog_paths(self):
        cases = {
            "Chăm Sóc Da Mặt -> Chống Nắng Da Mặt": "sunscreen",
            "Chăm Sóc Da Mặt -> Mặt Nạ -> Mặt Nạ Giấy": "sheet_mask",
            "Chăm Sóc Cơ Thể -> Sữa Tắm": "body_wash",
            "Chăm Sóc Cơ Thể -> Chống Nắng Cơ Thể": "body_sunscreen",
            "Chăm Sóc Da Mặt -> Làm Sạch Da -> Sữa Rửa Mặt": "cleanser",
            "Chăm Sóc Da Mặt -> Đặc Trị -> Serum / Tinh Chất": "serum",
            "Chăm Sóc Da Mặt -> Làm Sạch Da -> Tẩy Trang Mặt": "makeup_remover",
            "Trang Điểm -> Trang Điểm Mặt -> Kem Nền": "makeup_base",
            "Trang Điểm -> Trang Điểm Môi -> Son Thỏi": "lipstick",
            "Chăm Sóc Tóc Và Da Đầu -> Dầu Gội Và Dầu Xả -> Dầu Gội": "shampoo",
            "Chăm Sóc Tóc Và Da Đầu -> Dầu Gội Và Dầu Xả -> Dầu Xả": "conditioner",
            "Chăm Sóc Da Mặt -> Làm Sạch Da -> Toner / Nước Cân Bằng Da": "face_cleansing",
        }
        for path, expected in cases.items():
            with self.subTest(path=path):
                self.assertEqual(classify_product(row(1, path)), expected)

    def test_unrelated_product_is_none(self):
        self.assertIsNone(classify_product(row(1, "Chăm Sóc Cá Nhân -> Cạo Râu -> Dao Cạo Râu")))

    def test_shelf_life_covers_every_agent_category(self):
        for key in [*CATEGORY_PATHS, *CATEGORY_ALIASES]:
            self.assertIn(key, cfg.SHELF_LIFE_MONTHS)

    def test_shelf_life_special_cases(self):
        serum = "Chăm Sóc Da Mặt -> Đặc Trị -> Serum / Tinh Chất"
        self.assertEqual(shelf_life_months(row(1, serum, "Serum Vitamin C 15%")), 24)
        self.assertEqual(shelf_life_months(row(2, serum, "Serum Niacinamide")), 30)
        self.assertEqual(shelf_life_months(row(3, "Chăm Sóc Da Mặt -> Chống Nắng Da Mặt")), 36)

    def test_tag_marks_every_required_field(self):
        doc = cfg.tag({"a": 1})
        self.assertEqual((doc["test_dataset"], doc["data_source"], doc["needs_verification"]),
                         ("pb-fixture-v1", "pb_fixture", True))


class RestoreTests(unittest.TestCase):
    def backup(self):
        rows = []
        pid = 1000
        for path in ("Trang Điểm -> Trang Điểm Môi -> Son Thỏi", "Trang Điểm -> Trang Điểm Mặt -> Kem Nền",
                     "Chăm Sóc Cơ Thể -> Sữa Tắm", "Chăm Sóc Tóc Và Da Đầu -> Dầu Gội Và Dầu Xả -> Dầu Gội"):
            for i in range(40):
                pid += 1
                rows.append(row(pid, path, f"SP {pid}", so_luong_da_ban=i * 3, diem_danh_gia=4.0))
        rows.append(row(2001, "Chăm Sóc Cá Nhân -> Cạo Râu -> Dao Cạo Râu", "Dao"))  # ngoài nhóm Builder
        rows.append(row(2002, "Trang Điểm -> Trang Điểm Môi -> Son Thỏi", "", gia_ban=1))  # thiếu tên
        rows.append(row(2003, "Trang Điểm -> Trang Điểm Môi -> Son Thỏi", "Son lỗi giá", gia_ban=0))
        rows.append(row(1001, "Chăm Sóc Da Mặt -> Chống Nắng Da Mặt", "Sản phẩm da mặt có sẵn"))
        return rows

    def test_skips_live_ids_and_caps_per_leaf(self):
        backup = self.backup()
        live = {"1001", "1002"}
        picked = pick_restore_candidates(backup, live, per_leaf=22, rng=random.Random(1))
        ids = [str(p["ma_san_pham"]) for p in picked]
        self.assertFalse(live & set(ids))
        self.assertEqual(len(ids), len(set(ids)))
        per_leaf = Counter(p["danh_muc_day_du"] for p in picked)
        self.assertEqual(len(per_leaf), 4)
        self.assertTrue(all(count <= 22 for count in per_leaf.values()))
        self.assertTrue(all(p["gia_ban"] > 0 and p["ten_san_pham"] for p in picked))
        self.assertNotIn("2001", ids)
        self.assertNotIn("2002", ids)
        self.assertNotIn("2003", ids)

    def test_same_seed_same_result(self):
        a = pick_restore_candidates(self.backup(), set(), rng=random.Random(7))
        b = pick_restore_candidates(self.backup(), set(), rng=random.Random(7))
        c = pick_restore_candidates(self.backup(), set(), rng=random.Random(8))
        self.assertEqual([p["ma_san_pham"] for p in a], [p["ma_san_pham"] for p in b])
        self.assertNotEqual([p["ma_san_pham"] for p in a], [p["ma_san_pham"] for p in c])

    def test_input_rows_are_not_mutated(self):
        backup = self.backup()
        picked = pick_restore_candidates(backup, set(), rng=random.Random(1))
        picked[0]["trang_thai"] = "active"
        self.assertTrue(all("trang_thai" not in r for r in backup))


class PopularityTests(unittest.TestCase):
    def test_positive_and_monotonic_in_sales(self):
        values = [popularity_prior({"so_luong_da_ban": n, "diem_danh_gia": 4.5}) for n in (0, 1, 10, 100, 1000)]
        self.assertTrue(all(v > 0 for v in values))
        self.assertEqual(values, sorted(values))
        self.assertGreater(popularity_prior({}), 0)

    def test_better_rating_scores_higher(self):
        self.assertGreater(popularity_prior({"so_luong_da_ban": 50, "diem_danh_gia": 5}),
                           popularity_prior({"so_luong_da_ban": 50, "diem_danh_gia": 3}))


if __name__ == "__main__":
    unittest.main()
