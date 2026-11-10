import os
import sys
import unittest
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import engines.script_validator as sv
from core.script_text import find_price_issues
from core.vi_money import price_phrases

PRICING = {"regular_price": 278000, "market_price": None, "flash": None, "captured_ts": 1}
FLASH_PRICING = {"regular_price": 278000, "market_price": None, "captured_ts": 1,
                 "flash": {"flash_price": 249000, "discount_percent": 10, "end_ts": 1792256340}}
SNAP = {"name": "Kem Chống Nắng Beplain", "brand": "Beplain", "pricing": PRICING}
COMBINED = {
    "name": "Routine chống nắng", "brand": "SkinSyntax", "pricing": None, "bundle_total": 371000,
    "products": [
        {"name": "Kem Chống Nắng Beplain", "brand": "Beplain", "pricing": PRICING},
        {"name": "Sữa Rửa Mặt Cerave", "brand": "Cerave",
         "pricing": {"regular_price": 93000, "market_price": None, "flash": None, "captured_ts": 1}},
    ],
}
REGULAR = price_phrases(PRICING)["regular"]


def issues(script, snapshot=SNAP, purpose="live_and_product", clip_kind=None):
    with patch("engines.script_validator._llm_audit_issues", return_value=[]):
        return sv.validate_script(script, snapshot, purpose, clip_kind)


def codes(script, snapshot=SNAP, purpose="live_and_product", clip_kind=None):
    return [i.code for i in issues(script, snapshot, purpose, clip_kind)]


class CanhAliasTests(unittest.TestCase):
    def test_canh_alias_valid(self):
        text = f"Giá {REGULAR}, tức là hai trăm bảy mươi tám cành thôi."
        self.assertFalse([c for c in codes(text) if c.startswith("PRICE_")])

    def test_canh_alias_wrong_number(self):
        self.assertIn("PRICE_MISMATCH", codes(f"Giá {REGULAR}, tức là hai trăm cành thôi."))

    def test_extra_phrases_make_canh_valid(self):
        extra = {"bundle": "ba trăm bảy mươi mốt nghìn đồng"}
        out = find_price_issues("Tổng ba trăm bảy mươi mốt nghìn đồng, ba trăm bảy mươi mốt cành.", None, extra)
        self.assertEqual(out, [])


class D7PatternTests(unittest.TestCase):
    def test_fake_quota_rejected(self):
        for text in ("30 bà đầu tiên chốt đơn nha.", "Chỉ có 40 suất duy nhất."):
            self.assertIn("BLACKLIST_CLAIM", codes(text, clip_kind="interlude"))

    def test_fake_gift_and_unlock_rejected(self):
        for text in ("Mua là tặng kèm bông.", "5.000 tim là mở khóa nha."):
            self.assertIn("BLACKLIST_CLAIM", codes(text, clip_kind="interlude"))

    def test_end_of_live_price_rejected(self):
        self.assertIn("BLACKLIST_CLAIM", codes("Tắt live là giá nhảy về cũ.", clip_kind="interlude"))

    def test_efficacy_days_rejected(self):
        for text in ("Bôi 3 hôm là dịu.", "Công thức chuẩn y khoa."):
            self.assertIn("BLACKLIST_CLAIM", codes(text, clip_kind="tip"))

    def test_promo_words_allowed_with_flash_sale(self):
        flash_snap = {**COMBINED, "products": [{**COMBINED["products"][0], "pricing": FLASH_PRICING}]}
        text = "Có voucher và trợ giá nữa."
        self.assertNotIn("BLACKLIST_CLAIM", codes(text, flash_snap, clip_kind="faq"))
        self.assertIn("BLACKLIST_CLAIM", codes(text, COMBINED, clip_kind="faq"))
        # product clip: the clip's own pricing decides
        self.assertNotIn("BLACKLIST_CLAIM", codes(text, {**SNAP, "pricing": FLASH_PRICING}, clip_kind="product"))

    def test_d7_patterns_not_applied_without_clip_kind(self):
        self.assertNotIn("BLACKLIST_CLAIM", codes("Mua là tặng kèm bông."))


class RoboticTests(unittest.TestCase):
    def test_robotic_word_is_soft_error(self):
        found = [i for i in issues("Theo dữ liệu của Syna thì ổn.", clip_kind="faq") if i.code == "ROBOTIC_WORDING"]
        self.assertTrue(found)
        self.assertEqual(found[0].category, "SOFT_QUALITY")
        self.assertEqual(found[0].severity, "error")

    def test_ai_phan_tich_flagged(self):
        self.assertIn("ROBOTIC_WORDING", codes("AI phân tích da giúp bà.", clip_kind="faq"))

    def test_robotic_not_checked_for_legacy(self):
        self.assertNotIn("ROBOTIC_WORDING", codes("Theo dữ liệu của Syna thì ổn."))


class KindRuleTests(unittest.TestCase):
    def test_tip_with_product_name_rejected(self):
        self.assertIn("TIP_MENTIONS_PRODUCT",
                      codes("Nhớ thoa Kem Chống Nắng Beplain mỗi sáng.", COMBINED, clip_kind="tip"))
        self.assertIn("TIP_MENTIONS_PRODUCT", codes("Cerave rửa mặt nhẹ nhàng.", COMBINED, clip_kind="tip"))
        self.assertNotIn("TIP_MENTIONS_PRODUCT", codes("Nhớ thoa chống nắng mỗi sáng.", COMBINED, clip_kind="tip"))

    def test_interlude_with_price_rejected(self):
        self.assertIn("PRICE_NOT_ALLOWED", codes(f"Giá chỉ {REGULAR} thôi.", COMBINED, clip_kind="interlude"))

    def test_product_clip_evergreen_part(self):
        script = "[Lead-in]\nChào cả nhà.\n[Pain Point]\nComment cho Syna nha.\n[CTA]\nComment nhé."
        found = [i for i in issues(script, clip_kind="product") if i.code == "EVERGREEN_VIOLATION"]
        self.assertEqual([i.section for i in found], ["pain_point"])
        ok = "[Pain Point]\nDa dầu bóng nhẫy.\n[CTA]\nThả tim và comment nhé."
        self.assertNotIn("EVERGREEN_VIOLATION", codes(ok, clip_kind="product"))

    def test_product_clip_price_before_price_section(self):
        script = f"[Why]\nGiá {REGULAR} đó.\n[Price]\nGiá {REGULAR}."
        self.assertIn("EVERGREEN_VIOLATION", codes(script, clip_kind="product"))

    def test_recap_total_phrase_valid(self):
        text = "Tổng cả bộ: ba trăm bảy mươi mốt nghìn đồng."
        self.assertFalse([c for c in codes(text, COMBINED, clip_kind="routine_recap") if c.startswith("PRICE_")])
        self.assertIn("PRICE_MISMATCH", codes("Tổng cả bộ: ba trăm nghìn đồng.", COMBINED, clip_kind="routine_recap"))

    def test_unknown_kind_rejected(self):
        with self.assertRaises(ValueError):
            sv.validate_script("x", SNAP, "live_only", "bogus")


class LegacyTests(unittest.TestCase):
    SCRIPT = ("[Opening]\nChào cả nhà.\n[Hook]\nDa dầu?\n[Product Intro]\nKem nhẹ.\n[Key Benefits]\nDịu da.\n"
              "[How to Use]\nSáng tối.\n[Price]\nGiá " + REGULAR + ".\n[CTA]\nĐặt nhé.\n[Closing]\nTạm biệt.")

    def test_legacy_validation_unchanged(self):
        self.assertEqual(codes(self.SCRIPT), [])
        bad = self.SCRIPT.replace("Dịu da.", "Dịu da, giá 100k.")
        self.assertEqual(codes(bad), ["PRICE_MISMATCH"])


class RepairThreadingTests(unittest.TestCase):
    def test_clip_kind_threaded_to_repair(self):
        with patch("engines.script_validator._llm_audit_issues", return_value=[]), \
                patch("engines.script_repair.repair_script", return_value="Xin chào cả nhà.") as repair:
            result = sv.validate_and_repair_script("Theo dữ liệu thì ổn.", COMBINED, "live_only", clip_kind="faq")
        self.assertTrue(result.passed)
        self.assertEqual(repair.call_args.kwargs.get("clip_kind"), "faq")

    def test_clip_product_structure(self):
        text = sv._revision_structure("clip_product")
        for label in ("[Lead-in]", "[Pain Point]", "[Why]", "[Solution]", "[Feel]", "[How to Use]", "[Price]", "[CTA]"):
            self.assertIn(label, text)

    def test_audit_prompt_lists_all_products_and_shop_facts(self):
        _, user = sv._audit_prompts("x", COMBINED, "live_only", json_mode=True, clip_kind="interlude")
        self.assertIn("Sữa Rửa Mặt Cerave", user)
        self.assertIn("giao hàng toàn quốc", user)
        _, user_tip = sv._audit_prompts("x", COMBINED, "live_only", json_mode=True, clip_kind="tip")
        self.assertNotIn("giao hàng toàn quốc", user_tip)


if __name__ == "__main__":
    unittest.main()
