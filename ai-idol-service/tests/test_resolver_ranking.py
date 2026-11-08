"""Ranking of tied matches: exact name beats alphabetical order, ties are asked, "N <noun>" sets the count."""
from __future__ import annotations

import unittest

from agents.product_resolver import _requested_limit, resolve_products

HATO_DESC = "kem dưỡng cấp ẩm làm sáng da"
HATOMUGI = [
    {"ma_san_pham": "5778", "ten_san_pham": "Kem Chống Nắng Hatomugi Dưỡng Ẩm & Làm Sáng Da 80g",
     "thuong_hieu": "Hatomugi", "mo_ta": HATO_DESC},
    {"ma_san_pham": "1325", "ten_san_pham": "Kem Chống Nắng Hatomugi Nâng Tông & Dưỡng Ẩm Da 70g",
     "thuong_hieu": "Hatomugi", "mo_ta": HATO_DESC},
    {"ma_san_pham": "173", "ten_san_pham": "Kem Dưỡng Hatomugi Cấp Ẩm & Làm Sáng Da 300g",
     "thuong_hieu": "Hatomugi", "mo_ta": HATO_DESC},
]
HATO_QUERY = "Kem Dưỡng Hatomugi Cấp Ẩm & Làm Sáng Da"

SUNSCREEN_DESC = "kem chống nắng"
SUNSCREENS = [
    {"ma_san_pham": "BB1", "ten_san_pham": "BB Chống Nắng Trang Điểm Tông Sáng 30ml",
     "thuong_hieu": "Zeta", "mo_ta": SUNSCREEN_DESC, "so_luong_da_ban": 9000},
    {"ma_san_pham": "BU1", "ten_san_pham": "Bút Cushion Che Khuyết Điểm Maybelline 110 Fair 6ml",
     "thuong_hieu": "Maybelline", "mo_ta": SUNSCREEN_DESC, "so_luong_da_ban": 8000},
    {"ma_san_pham": "K1", "ten_san_pham": "Kem Chống Nắng Anessa Perfect Milk 60ml",
     "thuong_hieu": "Anessa", "mo_ta": SUNSCREEN_DESC, "so_luong_da_ban": 100},
    {"ma_san_pham": "K2", "ten_san_pham": "Kem Chống Nắng Skin1004 Daily Fluid 50ml",
     "thuong_hieu": "Skin1004", "mo_ta": SUNSCREEN_DESC, "so_luong_da_ban": 500},
    {"ma_san_pham": "K3", "ten_san_pham": "Kem Chống Nắng Sunplay Aqua Gel 50g",
     "thuong_hieu": "Sunplay", "mo_ta": SUNSCREEN_DESC, "so_luong_da_ban": 300},
]


def _ids(items):
    return [item["product_id"] for item in items]


class TieBreakTests(unittest.TestCase):
    def test_exact_product_beats_alphabetical_tie(self):
        result = resolve_products(f"Làm video giới thiệu {HATO_QUERY}", [], [HATO_QUERY], HATOMUGI)
        self.assertEqual(_ids(result["selected"]), ["173"])

    def test_exact_product_is_first_candidate_without_llm(self):
        result = resolve_products(f"Làm video giới thiệu {HATO_QUERY}", [], [], HATOMUGI)
        first = (result["selected"] or result["ambiguous"])[0]
        self.assertEqual(first["product_id"], "173")

    def test_count_picks_real_sunscreens_by_sales(self):
        result = resolve_products("Làm video 3 kem chống nắng", [], ["kem chống nắng"], SUNSCREENS)
        self.assertEqual(len(result["selected"]), 3)
        self.assertTrue(all(i["name"].startswith("Kem Chống Nắng") for i in result["selected"]))
        self.assertEqual(_ids(result["selected"]), ["K2", "K3", "K1"])

    def test_equal_name_cover_goes_to_best_seller_not_shortest_name(self):
        catalog = [
            {"ma_san_pham": "5595", "ten_san_pham": "Kem Chống Nắng Embryolisse 100ml",
             "thuong_hieu": "Embryolisse", "mo_ta": "sữa dưỡng ẩm", "so_luong_da_ban": 0},
            {"ma_san_pham": "93", "ten_san_pham": "Sữa Dưỡng Ẩm Embryolisse Siêu Phục Hồi Da 30ml",
             "thuong_hieu": "Embryolisse", "mo_ta": "sữa dưỡng ẩm", "so_luong_da_ban": 40},
        ]
        result = resolve_products("Làm video cho Embryolisse", [], ["Embryolisse"], catalog)
        self.assertEqual(result["ambiguous"][0]["product_id"], "93")

    def test_whole_phrase_beats_scattered_words_even_when_less_sold(self):
        catalog = [
            {"ma_san_pham": "415", "ten_san_pham": "Kem Nền Maybelline Mịn Nhẹ Kiềm Dầu Chống Nắng #110 30ml",
             "thuong_hieu": "Maybelline", "mo_ta": "kem chống nắng", "so_luong_da_ban": 205},
            {"ma_san_pham": "27", "ten_san_pham": "Kem Chống Nắng MartiDerm Phổ Rộng 40ml",
             "thuong_hieu": "Martiderm", "mo_ta": "kem chống nắng", "so_luong_da_ban": 110},
        ]
        result = resolve_products("Làm video 2 kem chống nắng", [], ["kem chống nắng"], catalog)
        self.assertEqual(_ids(result["selected"]), ["27", "415"])

    def test_generic_query_with_exact_tie_is_asked(self):
        result = resolve_products("Làm video kem chống nắng", [], ["kem chống nắng"], SUNSCREENS)
        self.assertEqual(result["selected"], [])
        self.assertTrue(result["ambiguous"])
        self.assertTrue(result["ambiguous"][0]["name"].startswith("Kem Chống Nắng"))


def _item(pid, name, brand="", category="", desc=""):
    return {"ma_san_pham": pid, "ten_san_pham": name, "ten_thuong_hieu": brand,
            "loai_san_pham": category, "mo_ta": desc, "so_luong_ban": 0}


class ContentWordScoringTests(unittest.TestCase):
    def test_filler_words_do_not_dilute_category_query(self):
        catalog = [
            _item("T1", "Kem Chống Nắng Torriden Nâng Tông Da 60ml", "Torriden", "Kem chống nắng"),
            _item("A1", "Phấn Tươi AGE 20's Tông Da Trung Bình 12.5g", "AGE 20's", "Phấn"),
            _item("K1", "Toner Klairs Cân Bằng Da 180ml", "Klairs", "Toner"),
            _item("S1", "Nước Hoa Hồng Some By Mi Toner 150ml", "Some By Mi", "Toner"),
        ]
        result = resolve_products("Cho tôi xem toner", [], [], catalog)
        order = [c["product_id"] for c in result["ambiguous"]] or _ids(result["selected"])
        self.assertTrue(order)
        self.assertEqual(set(order[:2]), {"K1", "S1"})
        for other in ("T1", "A1"):
            if other in order:
                self.assertGreater(order.index(other), 1)

    def test_brand_query_puts_brand_product_first(self):
        catalog = [
            _item("H1", "Bộ Sản Phẩm Hada Labo Dưỡng Sáng Da 3 Món", "Hada Labo", "Bộ sản phẩm"),
            _item("O1", "Kem Dưỡng Obagi Retinol 1.0% Trẻ Hóa Da 28g", "Obagi", "Kem dưỡng"),
        ]
        result = resolve_products("Sản phẩm của Obagi", [], [], catalog)
        first = (result["selected"] or result["ambiguous"])[0]
        self.assertEqual(first["product_id"], "O1")

    def test_availability_question_selects_nothing(self):
        catalog = [
            _item("A", "Serum Vitamin C Klairs 30ml", "Klairs", "Serum", "sản phẩm còn hàng tại kho"),
            _item("B", "Sữa Rửa Mặt Cerave 236ml", "Cerave", "Sữa rửa mặt", "còn hàng"),
        ]
        result = resolve_products("Sản phẩm nào còn hàng?", [], [], catalog)
        self.assertEqual(result["selected"], [])

    def test_description_only_token_scores_low(self):
        from agents.product_resolver import _score
        product = {"id": "1", "name": "Sữa Rửa Mặt Cerave 236ml", "brand": "Cerave",
                   "category": "Sữa rửa mặt", "ingredients": "",
                   "description": "phù hợp da dầu mụn có chứa retinol"}
        self.assertLess(_score("retinol", product), 0.62)


class UnknownTermTests(unittest.TestCase):
    LIPSTICKS = [
        {"ma_san_pham": "11", "ten_san_pham": "Son Bóng Black Rouge GZ02 Fig Fuzz",
         "thuong_hieu": "Black Rouge", "mo_ta": "son bóng"},
        {"ma_san_pham": "12", "ten_san_pham": "Son Kem Lì Romand", "thuong_hieu": "Romand", "mo_ta": "son kem"},
    ]

    def test_unknown_brand_is_reported(self):
        result = resolve_products("Làm video cho son Chanel Rouge Allure", [], [], self.LIPSTICKS)
        terms = result["unknown_terms"]
        self.assertIn("chanel", terms)
        self.assertIn("allure", terms)
        for known in ("rouge", "son", "lam", "video", "cho"):
            self.assertNotIn(known, terms)

    def test_known_product_has_no_unknown_terms(self):
        result = resolve_products(f"Làm video giới thiệu {HATO_QUERY}", [], [HATO_QUERY], HATOMUGI)
        self.assertEqual(result["unknown_terms"], [])

    def test_small_typo_is_not_unknown(self):
        result = resolve_products("Kem Dưỡng Hatomugy Cấp Ẩm", [], [], HATOMUGI)
        self.assertEqual(result["unknown_terms"], [])

    def test_explicit_ids_skip_the_check(self):
        result = resolve_products("Làm video Chanel mã 173", ["173"], [], HATOMUGI)
        self.assertEqual(result["unknown_terms"], [])

    def test_frame_words_are_not_unknown(self):
        result = resolve_products("Làm video 3 kem chống nắng cho tiktok", [], ["kem chống nắng"], SUNSCREENS)
        self.assertEqual(result["unknown_terms"], [])
        result = resolve_products("Làm video 3 kem chống nắng cho tiktok", [], [], SUNSCREENS)
        self.assertEqual(result["unknown_terms"], [])


class RequestedLimitTests(unittest.TestCase):
    def test_counts(self):
        cases = {
            "Làm video 3 kem chống nắng": 3,
            "3 serum chứa niacinamide": 3,
            "Làm video 5 sản phẩm Cocoon": 5,
            "Làm video giới thiệu sản phẩm mã 93": 1,
            "kem chống nắng SPF 50": 1,
            "livestream lúc 20h": 1,
            "sữa rửa mặt dưới 200k": 1,
            "thời lượng 60 phút": 1,
        }
        for text, expected in cases.items():
            with self.subTest(text=text):
                self.assertEqual(_requested_limit(text), expected)


if __name__ == "__main__":
    unittest.main()
