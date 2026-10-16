import os
import sys
import unittest


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import engines.script_validator as script_validator
from core.script_text import PAGE_CUT_LABELS, find_price_violations
from core.vi_money import price_phrases

# Case B of spec section 7 (end_ts = 2026-10-17T16:59Z, i.e. 23:59 on the 17th in Vietnam).
PRICING = {
    "regular_price": 299000,
    "market_price": 350000,
    "flash": {"campaign_id": 12, "campaign_name": "Sale 16/10", "flash_price": 249000,
              "discount_percent": 17, "end_ts": 1792256340},
    "captured_ts": 1792160000,
}
PRICING_NO_FLASH = {"regular_price": 299000, "market_price": 350000, "flash": None, "captured_ts": 1792160000}


def script(body_price="", extra=""):
    return (
        "[Hook]\nDa dầu?\n[Problem]\nBóng nhờn.\n[Product Intro]\n" + body_price +
        "\n[Specs]\nBa mươi mililít.\n[Ingredients]\nNi-a-ci-na-mi.\n[How to Use]\nSáng tối.\n[Wrap-up]\n" + extra + " Xem thêm."
    )


def check(text, pricing=PRICING, purpose="product_video"):
    snapshot = {"pricing": pricing} if pricing is not None else {}
    return script_validator.check_purpose_rules(text, snapshot, purpose)


class ProductVideoPriceTests(unittest.TestCase):
    def test_exact_phrases_pass(self):
        phrases = price_phrases(PRICING)
        self.assertEqual(set(phrases), {"regular", "market", "flash", "discount", "deadline"})
        body = f"Giá {phrases['regular']}, giá tham khảo thị trường {phrases['market']}."
        extra = f"Flash sale {phrases['flash']}, {phrases['discount']}, {phrases['deadline']}."
        self.assertEqual(check(script(body, extra)), [])

    def test_phrase_without_dong_and_ngan_variant_pass(self):
        self.assertEqual(check(script("Giá hai trăm chín mươi chín ngàn.")), [])
        self.assertEqual(check(script("Giá hai trăm chín mươi chín ngàn đồng.")), [])

    def test_wrong_amount_fails(self):
        errors = check(script("Giá hai trăm tám mươi nghìn đồng."))
        self.assertTrue(errors and errors[0].startswith("Con số giá không khớp dữ liệu"))

    def test_extended_amount_fails(self):
        self.assertTrue(check(script("Giá ba trăm hai trăm chín mươi chín nghìn đồng.")))

    def test_digits_fail(self):
        self.assertTrue(check(script("Giá 299.000đ.")))
        self.assertTrue(check(script("Giá 299k.")))

    def test_wrong_percent_fails(self):
        self.assertTrue(check(script("Đang giảm hai mươi phần trăm.")))

    def test_ingredient_percent_is_not_a_price(self):
        text = script().replace("Ba mươi mililít.", "Ba mươi mililít, Niacinamide mười phần trăm.")
        self.assertEqual(check(text), [])

    def test_promo_without_flash_fails(self):
        errors = check(script("Giá hai trăm chín mươi chín nghìn đồng, đang ưu đãi."), PRICING_NO_FLASH)
        self.assertTrue(any("Không có chương trình ưu đãi đang chạy" in e for e in errors))

    def test_promo_with_flash_passes(self):
        self.assertEqual(check(script("Đang có khuyến mãi.")), [])

    def test_scarcity_words_fail_without_verified_flash(self):
        for word in ("hôm nay", "tối nay", "chốt đơn", "sắp hết", "chỉ còn vài chai", "số lượng có hạn"):
            with self.subTest(word=word):
                self.assertTrue(check(script(f"Mua {word} nhé."), PRICING_NO_FLASH))

    def test_scarcity_words_pass_with_verified_flash(self):
        for word in ("hôm nay", "sắp hết", "số lượng có hạn"):
            with self.subTest(word=word):
                self.assertEqual(check(script(f"Mua {word} nhé.")), [])

    def test_no_pricing_any_amount_fails(self):
        self.assertTrue(check(script("Chỉ hai trăm nghìn đồng."), None))

    def test_no_pricing_without_amount_passes(self):
        self.assertEqual(check(script("Da dầu rất hợp.")), [])
        self.assertEqual(check(script("Da dầu rất hợp."), None), [])

    def test_find_price_violations_one_message_per_kind(self):
        errors = find_price_violations("hôm nay và tối nay, 299k và 199k", PRICING_NO_FLASH)
        self.assertEqual(len(errors), 2)


class LiveAndProductScopeTests(unittest.TestCase):
    def test_price_is_checked_in_every_section(self):
        live = "[Hook]\nDa dầu?\n[Price]\nGiá hai trăm chín mươi chín nghìn đồng.\n[CTA]\nĐặt ngay nhé."
        self.assertEqual(check(live, PRICING, "live_and_product"), [])
        early = "[Hook]\nChỉ hai trăm chín mươi chín nghìn đồng.\n[Price]\nGiá tốt.\n[CTA]\nĐặt ngay."
        self.assertEqual(check(early, PRICING, "live_and_product"), [])

    def test_wrong_price_in_hook_or_cta_fails(self):
        self.assertTrue(check("[Hook]\nChỉ 199k!\n[Price]\nGiá tốt.\n[CTA]\nĐặt.", PRICING, "live_and_product"))
        self.assertTrue(check("[Hook]\nDa dầu?\n[Price]\nGiá tốt.\n[CTA]\nChỉ 199k.", PRICING, "live_and_product"))

    def test_scarcity_without_flash_fails(self):
        live = "[Hook]\nDa dầu?\n[Price]\nHôm nay giá tốt.\n[CTA]\nChốt đơn."
        self.assertTrue(check(live, PRICING_NO_FLASH, "live_and_product"))

    def test_without_cta_or_closing_is_skipped(self):
        live = "[Hook]\nDa dầu?\n[Price]\nHôm nay giá 199k."
        self.assertEqual(check(live, PRICING, "live_and_product"), [])

    def test_cut_labels(self):
        self.assertEqual(PAGE_CUT_LABELS, frozenset({"cta", "closing"}))


if __name__ == "__main__":
    unittest.main()
