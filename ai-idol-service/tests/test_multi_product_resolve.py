"""Một lệnh nêu nhiều sản phẩm: mỗi tên phải được khớp riêng, không bị sản phẩm khớp mạnh nhất che mất."""
from __future__ import annotations

import unittest

from agents.intent_classifier import classify_intent
from agents.intent_parser import fallback_parse, split_product_queries
from agents.product_resolver import resolve_products

CATALOG = [
    {"ma_san_pham": "SP1", "ten_san_pham": "Serum Phục Hồi La Roche-Posay Hyalu B5 30ml", "thuong_hieu": "La Roche-Posay"},
    {"ma_san_pham": "SP2", "ten_san_pham": "Kem Chống Nắng Anessa Perfect UV Sunscreen Skincare Milk 60ml", "thuong_hieu": "Anessa"},
    {"ma_san_pham": "SP3", "ten_san_pham": "Sữa Rửa Mặt Cocoon Bí Đao 140ml", "thuong_hieu": "Cocoon"},
    {"ma_san_pham": "SP4", "ten_san_pham": "Kem Chống Nắng Sunplay Skin Aqua 50g", "thuong_hieu": "Sunplay"},
    {"ma_san_pham": "SP5", "ten_san_pham": "Serum Vichy Mineral 89 50ml", "thuong_hieu": "Vichy"},
]
BRIEF = "Làm video cho serum B5 La Roche-Posay, kem chống nắng Anessa và sữa rửa mặt bí đao Cocoon"


def _ids(items):
    return [item["product_id"] for item in items]


class MultiProductResolveTests(unittest.TestCase):
    def test_every_named_product_is_selected(self):
        result = resolve_products(BRIEF, [], ["serum B5 La Roche-Posay", "kem chống nắng Anessa",
                                              "sữa rửa mặt bí đao Cocoon"], CATALOG)
        self.assertEqual(_ids(result["selected"]), ["SP1", "SP2", "SP3"])
        self.assertEqual(result["unresolved_queries"], [])

    def test_vague_query_is_asked_not_guessed(self):
        result = resolve_products("Làm video serum B5 La Roche-Posay và kem chống nắng", [],
                                  ["serum B5 La Roche-Posay", "kem chống nắng"], CATALOG)
        self.assertEqual(_ids(result["selected"]), ["SP1"])
        self.assertEqual(result["unresolved_queries"], ["kem chống nắng"])
        self.assertTrue({"SP2", "SP4"} <= set(_ids(result["ambiguous"])))

    def test_two_queries_for_the_same_product_select_it_once(self):
        result = resolve_products(BRIEF, [], ["kem chống nắng Anessa", "Anessa Perfect UV"], CATALOG)
        self.assertEqual(_ids(result["selected"]), ["SP2"])
        self.assertEqual(result["unresolved_queries"], [])

    def test_single_query_keeps_the_old_behaviour(self):
        result = resolve_products("kem chống nắng Anessa", [], ["kem chống nắng Anessa"], CATALOG)
        self.assertEqual(_ids(result["selected"]), ["SP2"])
        self.assertEqual(result["unresolved_queries"], [])

    def test_description_words_do_not_outrank_the_named_product(self):
        catalog = CATALOG + [
            {"ma_san_pham": "SP6", "ten_san_pham": "Combo 2 Gel Rửa Mặt SVR 400ml", "thuong_hieu": "SVR",
             "mo_ta": "gel dưỡng giảm mụn cho da dầu"},
            {"ma_san_pham": "SP7", "ten_san_pham": "Gel Dưỡng SVR Giảm Mụn & Loại Bỏ Dầu 40ml", "thuong_hieu": "SVR"},
        ]
        result = resolve_products("Làm video cho gel dưỡng SVR giảm mụn và kem chống nắng Anessa", [],
                                  ["gel dưỡng SVR giảm mụn", "kem chống nắng Anessa"], catalog)
        self.assertEqual(_ids(result["selected"]), ["SP7", "SP2"])

    def test_full_name_beats_a_combo_that_bundles_it(self):
        catalog = CATALOG + [
            {"ma_san_pham": "SP8", "ten_san_pham": "Son Dưỡng LipIce Không Màu Chuyển Kem Mịn Hương Dâu 4.3g", "thuong_hieu": "LipIce"},
            {"ma_san_pham": "SP9", "ten_san_pham": "Combo 3 Son Dưỡng LipIce Không Màu Chuyển Kem Mịn (Hương Chanh + Hương Dâu + Không Hương) 4.3g", "thuong_hieu": "LipIce"},
        ]
        result = resolve_products("x", [], ["son dưỡng LipIce không màu chuyển kem mịn hương dâu", "kem chống nắng Anessa"], catalog)
        self.assertEqual(_ids(result["selected"]), ["SP8", "SP2"])

    def test_two_sizes_of_one_product_are_asked(self):
        catalog = CATALOG + [
            {"ma_san_pham": "SP10", "ten_san_pham": "Son Dưỡng LipIce Hương Dâu 4.3g", "thuong_hieu": "LipIce"},
            {"ma_san_pham": "SP11", "ten_san_pham": "Son Dưỡng LipIce Hương Dâu 10g", "thuong_hieu": "LipIce"},
        ]
        result = resolve_products("x", [], ["son dưỡng LipIce hương dâu", "kem chống nắng Anessa"], catalog)
        self.assertEqual(result["unresolved_queries"], ["son dưỡng LipIce hương dâu"])

    def test_unclear_names_are_never_filled_by_the_overall_top_match(self):
        result = resolve_products("Làm video cho kem chống nắng và serum", [], ["kem chống nắng", "serum"], CATALOG)
        self.assertEqual(result["selected"], [])
        self.assertEqual(result["unresolved_queries"], ["kem chống nắng", "serum"])

    def test_explicit_ids_are_not_complemented_by_queries(self):
        result = resolve_products("làm video SP3", ["SP3"], ["serum B5 La Roche-Posay", "kem chống nắng Anessa"], CATALOG)
        self.assertEqual(_ids(result["selected"]), ["SP3"])
        self.assertEqual(result["unresolved_queries"], [])


class FallbackSplitTests(unittest.TestCase):
    """LLM lỗi: fallback_parse tự tách câu lệnh thành từng tên sản phẩm."""

    def test_commas_and_va_split_into_product_names(self):
        self.assertEqual(split_product_queries(BRIEF),
                         ["serum B5 La Roche-Posay", "kem chống nắng Anessa", "sữa rửa mặt bí đao Cocoon"])

    def test_unaccented_brief_splits_too(self):
        self.assertEqual(len(split_product_queries("lam video cho serum B5, kem chong nang Anessa va sua rua mat Cocoon")), 3)

    def test_schedule_and_platform_are_cut_off(self):
        self.assertEqual(
            split_product_queries("Tạo livestream giới thiệu kem chống nắng Anessa và serum Vichy lúc 20h tối mai trên youtube"),
            ["kem chống nắng Anessa", "serum Vichy"])

    def test_one_product_keeps_the_whole_brief(self):
        for brief in ("Làm video cho kem chống nắng Anessa và đăng lên youtube",
                      "Lọc 5 serum, tạo livestream lúc 19h tối mai",
                      "Tạo video cho sản phẩm mã 93, chế độ duyệt tay"):
            self.assertEqual(split_product_queries(brief), [brief])

    def test_fallback_resolves_every_named_product(self):
        brief = fallback_parse(BRIEF)
        result = resolve_products(BRIEF, brief.explicit_product_ids, brief.product_queries, CATALOG)
        self.assertEqual(_ids(result["selected"]), ["SP1", "SP2", "SP3"])


class GroundedQueryTests(unittest.TestCase):
    """Tên sản phẩm LLM trả về phải bám vào câu lệnh; tên bịa thì dùng kết quả tự tách."""

    def test_queries_from_the_brief_are_kept(self):
        from agents.intent_parser import normalize_text
        from agents.service import _queries_grounded
        text = normalize_text("Làm video cho son dưỡng và dầu xả Tsubaki bóng mượt")
        self.assertTrue(_queries_grounded(["son dưỡng", "dầu xả Tsubaki bóng mượt"], text))
        self.assertFalse(_queries_grounded(["Tsubaki Shine & Shine Shampoo", "Tsubaki Shine & Shine Conditioner"], text))


class MakeVideoIntentTests(unittest.TestCase):
    """“Làm video cho …” là lệnh tạo mới, không bị chặn ở UNKNOWN trước bước khớp sản phẩm."""

    def test_lam_video_is_a_create_command(self):
        for brief in (BRIEF, "Quay video son dưỡng LipIce", "làm clip cho kem chống nắng Anessa"):
            self.assertEqual(classify_intent(brief).intent, "CREATE_CAMPAIGN", brief)

    def test_retry_and_cancel_still_win(self):
        self.assertEqual(classify_intent("Làm video lại phần bị lỗi").intent, "RETRY_FAILED")
        self.assertEqual(classify_intent("Hủy làm video kem Anessa").intent, "CANCEL_CAMPAIGN")


if __name__ == "__main__":
    unittest.main()
