import hashlib
import os
import sys
import unittest
from unittest.mock import patch


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import engines.content_agent as content_agent
import engines.script_validator as script_validator
from core.pipeline import run_ai_idol_pipeline


SNAP = {
    "name": "Serum A", "brand": "B", "category": "Serum", "origin": "VN", "price": 289000,
    "market_price": 350000, "ingredients": "Niacinamide 10%, Zinc 1%", "description": "Giảm dầu",
    "usage": "Sáng tối", "skin_type": "Da dầu",
}
# sha256(system + "\0" + user) of the livestream prompt before purposes existed.
LIVE_ONLY_FINGERPRINT = "4565298572e16efe4b7021a6d3cf95f92e1c67fcddd8eedef5c8c12d1b539993"
PRICING = {"regular_price": 299000, "market_price": 350000,
           "flash": {"flash_price": 249000, "discount_percent": 17, "end_ts": 1792256340}}
PRODUCT_VIDEO_HEADERS = ["[Hook]", "[Problem]", "[Product Intro]", "[Specs]", "[Ingredients]", "[How to Use]", "[Wrap-up]"]
LIVE_HEADERS = ["[Opening]", "[Hook]", "[Product Intro]", "[Key Benefits]", "[How to Use]", "[Price]", "[CTA]", "[Closing]"]


class PromptTests(unittest.TestCase):
    def prompts(self, purpose, config=None):
        return content_agent.build_script_prompts(SNAP, "Kiến thức", "Product Intro", config or {}, purpose)

    def test_live_only_prompt_is_unchanged(self):
        system, user = self.prompts("live_only")
        self.assertEqual(hashlib.sha256((system + "\x00" + user).encode()).hexdigest(), LIVE_ONLY_FINGERPRINT)

    def test_default_generation_uses_the_live_only_prompt(self):
        seen = {}

        class FakeLLM:
            def invoke(self, system, user):
                seen["pair"] = (system, user)
                return "kịch bản"

        with patch.object(content_agent, "LLMProvider", FakeLLM):
            content_agent.generate_content_and_script(SNAP, "Kiến thức", "Product Intro", {})
        self.assertEqual(seen["pair"], self.prompts("live_only"))

    def test_product_video_prompt_is_evergreen_and_long(self):
        system, user = self.prompts("product_video")
        for header in PRODUCT_VIDEO_HEADERS:
            self.assertIn(header, system)
        self.assertNotIn("289000", user)
        self.assertIn("Long (90-150s)", user)

    def test_live_and_product_prompt_marks_sections_and_keeps_intro_neutral(self):
        system, user = self.prompts("live_and_product")
        for header in LIVE_HEADERS:
            self.assertIn(header, system)
        self.assertIn("chốt đơn", system)
        self.assertIn("Giá: không có dữ liệu", user)

    def test_council_payload_hides_price_for_product_video(self):
        payload = content_agent._script_council_payload(SNAP, "k" * 3000, "Product Intro", {}, "product_video")
        self.assertEqual(payload["source"]["purpose"], "product_video")
        self.assertEqual(payload["source"]["price"], "")
        self.assertEqual(payload["source"]["market_price"], "")
        self.assertEqual(payload["source"]["length_mode"], "Long (90-150s)")
        self.assertGreater(len(payload["source"]["knowledge"]), 2000)

    def test_council_payload_keeps_price_for_live_only(self):
        payload = content_agent._script_council_payload(SNAP, "k", "Product Intro", {}, "live_only")
        self.assertEqual(payload["source"]["price"], 289000)
        self.assertNotIn("price_lines", payload["source"])

    def test_council_payload_carries_price_lines_for_page_purposes(self):
        snap = dict(SNAP, pricing=PRICING)
        for purpose in ("product_video", "live_and_product"):
            source = content_agent._script_council_payload(snap, "k", "Product Intro", {}, purpose)["source"]
            self.assertEqual(source["price"], "")
            self.assertEqual(source["market_price"], "")
            self.assertIn("Giá bán tại SkinSyntax: hai trăm chín mươi chín nghìn đồng", source["price_lines"])

    def test_product_video_prompt_carries_price_lines(self):
        system, user = content_agent.build_script_prompts(
            dict(SNAP, pricing=PRICING), "k", "Product Intro", {}, "product_video")
        self.assertIn("Giá bán tại SkinSyntax: hai trăm chín mươi chín nghìn đồng", user)
        self.assertIn("Flash sale: ", user)
        self.assertNotIn("289000", user)
        self.assertNotIn("Tuyệt đối không nói giá", system)
        self.assertIn("bấm vào sản phẩm để xem giá mới nhất và đặt hàng", system)
        self.assertIn("đúng nguyên văn", system)

    def test_product_video_without_pricing_says_no_price_data(self):
        _, user = self.prompts("product_video")
        self.assertIn("Giá: không có dữ liệu, không nhắc giá.", user)

    def test_live_and_product_prompt_scopes_live_offers_to_cta(self):
        system, user = content_agent.build_script_prompts(
            dict(SNAP, pricing=PRICING), "k", "Product Intro", {}, "live_and_product")
        self.assertIn("chỉ được nói ở [CTA]", system)
        self.assertIn("Flash sale: ", user)
        self.assertNotIn("Mọi nội dung về giá và kêu gọi mua chỉ nằm từ [Price] trở đi", system)


class PurposeRuleTests(unittest.TestCase):
    def test_product_video_rejects_any_price(self):
        script = "[Ingredients]\nNiacinamide làm đều màu da.\n[Wrap-up]\nChỉ 199k thôi."
        self.assertTrue(script_validator.check_purpose_rules(script, SNAP, "product_video"))

    def test_product_video_requires_ingredients_when_known(self):
        errors = script_validator.check_purpose_rules("[Hook]\nDa dầu?\n[Wrap-up]\nXem thêm.", SNAP, "product_video")
        self.assertTrue(any("Ingredients" in error for error in errors))

    def test_product_video_without_known_ingredients_needs_no_section(self):
        self.assertEqual(script_validator.check_purpose_rules("[Hook]\nDa dầu?", {"ingredients": ""}, "product_video"), [])

    def test_live_and_product_allows_price_only_after_price_header(self):
        snap = {**SNAP, "pricing": {"regular_price": 289000, "market_price": None, "flash": None}}
        ok = "[Hook]\nDa dầu?\n[Price]\nChỉ hai trăm tám mươi chín nghìn đồng.\n[CTA]\nĐặt ngay hôm nay."
        bad = "[Hook]\nChỉ một trăm nghìn đồng!\n[Price]\nGiá tốt.\n[CTA]\nĐặt ngay."
        self.assertEqual(script_validator.check_purpose_rules(ok, snap, "live_and_product"), [])
        self.assertTrue(script_validator.check_purpose_rules(bad, snap, "live_and_product"))

    def test_review_i1_unlabelled_livestream_script_is_not_failed(self):
        # No recognised [Price] section: the livestream must still render; the product-page
        # clip is withheld later with missing_price_marker instead of failing the show.
        script = "[Hook]\nDa dầu?\n[Giá]\nGiá chỉ ba trăm nghìn đồng hôm nay."
        self.assertEqual(script_validator.check_purpose_rules(script, SNAP, "live_and_product"), [])

    def test_live_only_has_no_extra_rules(self):
        self.assertEqual(script_validator.check_purpose_rules("Chỉ 199k hôm nay!", SNAP, "live_only"), [])

    def test_validation_fails_when_revisions_keep_the_price(self):
        priced = "[Ingredients]\nNiacinamide.\n[Wrap-up]\nChỉ 199k."

        class FakeLLM:
            def invoke(self, system, user):
                return priced

        with patch.object(script_validator, "LLMProvider", FakeLLM), \
                patch.object(script_validator, "validate_script_via_llm", return_value=(True, "")):
            ok, _, feedback = script_validator.validate_and_revise_script(
                priced, SNAP, "", "Product Intro", {}, purpose="product_video"
            )
        self.assertFalse(ok)
        self.assertIn("Con số giá không khớp dữ liệu", feedback)


class ManualApprovalRuleTests(unittest.TestCase):
    """Review C3: a human approval must not bypass the product-page price rules."""

    def approve(self, purpose, script):
        from bson import ObjectId
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        db = FakeDb()
        job_id = ObjectId()
        db.ai_idol_jobs.insert_one({"_id": job_id, "status": "FAILED", "purpose": purpose,
                                    "product_snapshot": SNAP, "artifacts": {}})
        with patch.object(service_app, "get_db", return_value=db), \
                patch.object(service_app, "generate_job_task") as task:
            response = service_app.app.test_client().post(
                f"/api/ai-idol/jobs/{job_id}/script/approve", json={"script_text": script})
        return response, task

    def test_priced_product_video_script_is_refused(self):
        script = "[Ingredients]\nNiacinamide làm đều màu da và giảm dầu thừa rất hiệu quả.\n[Wrap-up]\nChỉ 199k hôm nay."
        response, task = self.approve("product_video", script)
        self.assertEqual(response.status_code, 422)
        task.delay.assert_not_called()

    def test_live_only_script_with_price_is_still_approvable(self):
        response, _ = self.approve("live_only", "Chào cả nhà, serum này giá chỉ 199k trong livestream hôm nay thôi nhé mọi người.")
        self.assertNotEqual(response.status_code, 422)


class AutoApproveTests(unittest.TestCase):
    def test_product_video_script_is_auto_approved_even_in_human_mode(self):
        job_id = "64b64b64b64b64b64b64b64b"
        job = {
            "product_id": "995", "content_mode": "Product Intro", "purpose": "product_video",
            "configuration": {"script_approval_mode": "human"},
            "product_snapshot": {"name": "Vichy"}, "script_status": "PENDING_APPROVAL",
            "artifacts": {"knowledge": "facts", "script_text": "[Hook]\nKịch bản hợp lệ đang chờ duyệt."},
        }
        db = FakeDb()
        with patch("core.pipeline.get_job", return_value=job), \
                patch("core.pipeline.get_db", return_value=db), \
                patch("core.pipeline.update_job_stage"), \
                patch("core.pipeline.checkpoint_job"), \
                patch("core.pipeline.fail_job") as fail_job, \
                patch("engines.voice_engine.generate_voice_audio", side_effect=RuntimeError("stop here")):
            self.assertFalse(run_ai_idol_pipeline(job_id))
        self.assertEqual(fail_job.call_args[0][1], "GENERATING_VOICE")
        statuses = [update.get("$set", {}).get("status") for _, _, update in db.ai_idol_jobs.writes]
        self.assertNotIn("AWAITING_APPROVAL", statuses)
        approved = [update["$set"] for _, _, update in db.ai_idol_scripts.writes if "$set" in update]
        self.assertEqual(approved[-1]["approved_by"], "PRODUCT_VIDEO_AUTO")


if __name__ == "__main__":
    unittest.main()
