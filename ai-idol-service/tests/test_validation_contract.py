import os
import sys
import unittest
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import engines.script_validator as sv

PRICING = {
    "regular_price": 299000, "market_price": 350000,
    "flash": {"campaign_id": 12, "flash_price": 249000, "discount_percent": 17, "end_ts": 1792256340},
}
NO_FLASH = {"regular_price": 299000, "market_price": 350000, "flash": None}
PHRASE = "hai trăm chín mươi chín nghìn đồng"


class FakeLLM:
    reply = "[]"
    fail = False

    def invoke(self, system_prompt, user_prompt):
        if FakeLLM.fail:
            raise RuntimeError("down")
        return FakeLLM.reply


def run(script, pricing=NO_FLASH, purpose="live_and_product", reply="[]", fail=False):
    FakeLLM.reply, FakeLLM.fail = reply, fail
    with patch.object(sv, "LLMProvider", FakeLLM):
        return sv.validate_script(script, {"pricing": pricing, "ingredients": ""}, purpose)


def codes(issues):
    return [i.code for i in issues]


class ValidationContractTests(unittest.TestCase):
    def test_correct_price_in_hook_passes(self):
        issues = run(f"[Hook]\nChỉ {PHRASE}.\n[Price]\nGiá tốt.\n[CTA]\nĐặt ngay.")
        self.assertEqual(issues, [])

    def test_wrong_price_is_price_mismatch(self):
        issues = run("[Hook]\nChỉ một trăm nghìn đồng.\n[CTA]\nĐặt ngay.")
        self.assertIn("PRICE_MISMATCH", codes(issues))
        issue = next(i for i in issues if i.code == "PRICE_MISMATCH")
        self.assertEqual((issue.category, issue.severity, issue.section), ("HARD_FACT_ERROR", "error", "hook"))
        self.assertIn("category", issue.to_dict())

    def test_scarcity_without_flash(self):
        self.assertIn("SCARCITY_UNVERIFIED", codes(run("[Hook]\nSắp hết rồi.\n[CTA]\nĐặt.")))

    def test_scarcity_allowed_with_verified_flash(self):
        self.assertEqual(run("[Hook]\nSắp hết rồi.\n[CTA]\nĐặt.", PRICING), [])

    def test_promo_without_flash(self):
        self.assertIn("PROMO_UNVERIFIED", codes(run("[Hook]\nĐang khuyến mãi.\n[CTA]\nĐặt.")))

    def test_personal_experience(self):
        issues = run("[Hook]\nMình dùng rồi, da mình đẹp hẳn.\n[CTA]\nĐặt.")
        self.assertIn("PERSONAL_EXPERIENCE", codes(issues))
        self.assertTrue(next(i for i in issues if i.code == "PERSONAL_EXPERIENCE").auto_fixable)

    def test_external_retailer_and_blacklist(self):
        self.assertIn("EXTERNAL_RETAILER", codes(run("[Hook]\nMua ở Shopee nhé.")))
        self.assertIn("BLACKLIST_CLAIM", codes(run("[Hook]\nTrị dứt điểm mụn.")))

    def test_llm_failure_is_technical(self):
        issues = run("[Hook]\nDa dầu?\n[CTA]\nĐặt.", fail=True)
        self.assertEqual(codes(issues), ["VALIDATOR_UNAVAILABLE"])
        self.assertEqual(issues[0].category, "TECHNICAL")
        self.assertFalse(issues[0].auto_fixable)
        self.assertEqual(issues[0].severity, "error")

    def test_llm_json_array_parsed(self):
        reply = '[{"section": "hook", "original_text": "x", "reason": "bịa", "auto_fixable": true}]'
        issues = run("[Hook]\nDa dầu?\n[CTA]\nĐặt.", reply=reply)
        self.assertEqual(codes(issues), ["LLM_AUDIT"])
        self.assertEqual(issues[0].section, "hook")

    def test_llm_unparseable_falls_back_to_single_issue(self):
        issues = run("[Hook]\nDa dầu?\n[CTA]\nĐặt.", reply="FAIL: nói quá")
        self.assertEqual(codes(issues), ["LLM_AUDIT"])
        self.assertIsNone(issues[0].section)

    def test_missing_ingredients_section(self):
        FakeLLM.reply, FakeLLM.fail = "[]", False
        with patch.object(sv, "LLMProvider", FakeLLM):
            issues = sv.validate_script("[Hook]\nDa dầu?\n[Wrap-up]\nXem thêm.",
                                        {"pricing": None, "ingredients": "Niacinamide"}, "product_video")
        self.assertIn("MISSING_SECTION", codes(issues))


if __name__ == "__main__":
    unittest.main()
