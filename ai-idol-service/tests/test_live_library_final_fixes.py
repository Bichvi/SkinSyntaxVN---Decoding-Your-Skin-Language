"""Regression tests for the final whole-branch review of the live clip library.

Each group runs at least one real neighbour (validator, lead-time check, writer prompts) instead of a stub.
"""
import os
import sys
import tempfile
import unittest
from contextlib import ExitStack
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import core.pipeline as pipeline
import engines.script_repair as script_repair
import engines.script_validator as sv
from config import AUDIO_PIPELINE_VERSION
from core import show_plan
from core.vi_money import money_words

P1 = {"regular_price": 350000, "market_price": None, "flash": None}
P2 = {"regular_price": 199000, "market_price": None, "flash": None}
SNAPS = {
    "1": {"id": "1", "name": "Sữa rửa mặt Cerave Foaming", "brand": "CeraVe", "pricing": P1,
          "description": "Làm sạch dịu nhẹ, giữ ẩm hàng rào da", "ingredients": "Ceramide, Niacinamide",
          "usage": "Rửa mặt sáng tối"},
    "2": {"id": "2", "name": "Serum Klairs Vitamin C", "brand": "Klairs", "pricing": P2,
          "description": "Làm sáng da, mờ thâm", "ingredients": "Ascorbic Acid 5%, Centella",
          "usage": "Thoa sau toner"},
}
PLAN = {"products": [{"product_id": "1", "number": 1, "step_label": "Sữa rửa mặt"},
                     {"product_id": "2", "number": 2, "step_label": "Serum"}], "theme_vi": "Da sạch sáng"}
TOTAL = show_plan.bundle_total([P1, P2])
RECAP = ("Cả nhà ơi, điểm lại nè. Món số 1 là sữa rửa mặt, làm sạch dịu nhẹ, giá ba trăm năm mươi nghìn đồng, "
         "tức là ba trăm năm mươi cành. Món số 2 là serum, sáng da, giá một trăm chín mươi chín nghìn đồng. "
         "Tổng cả bộ: " + money_words(TOTAL) + ". Sáng rửa mặt rồi serum. Bấm giỏ hàng nha cả nhà.")
FAQ = "Có bạn hỏi serum này giá bao nhiêu? Giá một trăm chín mươi chín nghìn đồng nha, thoa sau toner."
NO_LLM = "engines.script_validator._llm_audit_issues"


def combined():
    return show_plan.combined_snapshot(PLAN, SNAPS, TOTAL)


# ---------------------------------------------------------------- C1: final check uses the clip kind

class FinalCheckClipKindTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.video = os.path.join(folder.name, "clip.mp4")
        with open(self.video, "wb") as handle:
            handle.write(b"0" * 2048)
        self.audio = os.path.join(folder.name, "voice.wav")
        with open(self.audio, "wb") as handle:
            handle.write(b"0" * 64)

    def test_recap_passes_real_validator_with_clip_kind(self):
        db = FakeDb()
        job_id = str(db.ai_idol_jobs.insert_one({"product_id": None}).inserted_id)
        with patch(NO_LLM, return_value=[]) as audit:
            problems = pipeline.final_machine_check(db, job_id, None, combined(), RECAP, self.video, 70.0,
                                                    "live_and_product", clip_kind="routine_recap")
        self.assertEqual(problems, [])
        self.assertEqual(audit.call_args.args[3], "routine_recap")  # the audit sees the recap facts

    def test_recap_without_clip_kind_still_legacy(self):
        db = FakeDb()
        job_id = str(db.ai_idol_jobs.insert_one({"product_id": None}).inserted_id)
        with patch(NO_LLM, return_value=[]):
            problems = pipeline.final_machine_check(db, job_id, None, combined(), RECAP, self.video, 70.0,
                                                    "live_and_product")
        self.assertTrue(any("giá" in p.lower() or "price" in p.lower() for p in problems), problems)

    def test_complete_video_job_threads_clip_kind(self):
        db = FakeDb()
        job_id = str(db.ai_idol_jobs.insert_one({"product_id": None}).inserted_id)
        with patch(NO_LLM, return_value=[]), \
                patch("engines.video_composer.get_audio_duration", return_value=70.0):
            ok = pipeline._complete_video_job(job_id, None, {"format": "16:9"}, self.video, self.audio, db,
                                              purpose="live_and_product", snapshot=combined(), script_text=RECAP,
                                              clip_kind="routine_recap")
        self.assertTrue(ok)
        self.assertEqual(db.ai_idol_jobs.find_one({})["status"], "READY")

    def test_pipeline_passes_job_clip_kind_to_final_check(self):
        db = FakeDb()
        job_id = str(db.ai_idol_jobs.insert_one({
            "product_id": None, "content_mode": "Product Intro", "purpose": "live_and_product",
            "configuration": {"avatar_mode": "syna_3d", "format": "16:9", "approval_mode": "AUTO"},
            "status": "QUEUED", "current_stage": "QUEUED", "product_snapshot": combined(),
            "prewritten_script": True, "clip_kind": "routine_recap", "script_status": "APPROVED",
            "artifacts": {"knowledge": "", "script_text": RECAP, "audio_path": self.audio,
                          "audio_pipeline_version": AUDIO_PIPELINE_VERSION},
        }).inserted_id)
        with ExitStack() as stack:
            for module in ("core.state_machine", "core.pipeline"):
                stack.enter_context(patch(f"{module}.get_db", return_value=db))
            stack.enter_context(patch(NO_LLM, return_value=[]))
            stack.enter_context(patch("engines.syna_engine.render_syna_video", return_value=self.video))
            stack.enter_context(patch("engines.audio_mixer.mix_program_audio",
                                      side_effect=lambda job, path, *a, **k: path))
            stack.enter_context(patch("engines.video_composer.get_audio_duration", return_value=70.0))
            self.assertTrue(pipeline.run_ai_idol_pipeline(job_id))
        stored = db.ai_idol_jobs.find_one({})
        self.assertEqual(stored["status"], "READY", stored.get("error_message"))


# ---------------------------------------------------------------- I2: FAQ facts and recap descriptions

def faq_snapshot():
    return dict(SNAPS["2"])


class FaqFactsTests(unittest.TestCase):
    def test_faq_audit_prompt_has_product_facts(self):
        _, user = sv._audit_prompts(FAQ, faq_snapshot(), "live_and_product", True, "faq")
        for fact in ("Serum Klairs Vitamin C", "Làm sáng da, mờ thâm", "Ascorbic Acid", "Thoa sau toner",
                     "một trăm chín mươi chín nghìn đồng"):
            self.assertIn(fact, user)

    def test_faq_repair_prompt_has_product_facts(self):
        facts = script_repair._product_facts(faq_snapshot(), "faq")
        for fact in ("Serum Klairs Vitamin C", "Làm sáng da, mờ thâm", "một trăm chín mươi chín nghìn đồng"):
            self.assertIn(fact, facts)

    def test_faq_price_passes_real_validator(self):
        with patch(NO_LLM, return_value=[]):
            found = sv.validate_script(FAQ, faq_snapshot(), "live_and_product", "faq")
        self.assertEqual([i.code for i in found if i.code.startswith("PRICE")], [])

    def test_faq_wrong_price_still_caught(self):
        with patch(NO_LLM, return_value=[]):
            found = sv.validate_script("Giá chỉ một trăm nghìn đồng.", faq_snapshot(), "live_and_product", "faq")
        self.assertIn("PRICE_MISMATCH", [i.code for i in found])


class RecapFactsTests(unittest.TestCase):
    def test_combined_snapshot_has_clipped_descriptions(self):
        snaps = {**SNAPS, "1": {**SNAPS["1"], "description": "Làm sạch " + "x" * 1000}}
        products = show_plan.combined_snapshot(PLAN, snaps, TOTAL)["products"]
        self.assertTrue(products[0]["description"].startswith("Làm sạch"))
        self.assertLessEqual(len(products[0]["description"]), 200)
        self.assertEqual(products[1]["description"], "Làm sáng da, mờ thâm")

    def test_recap_audit_and_repair_prompts_have_descriptions(self):
        _, user = sv._audit_prompts(RECAP, combined(), "live_and_product", True, "routine_recap")
        facts = script_repair._product_facts(combined(), "routine_recap")
        for text in (user, facts):
            self.assertIn("Làm sáng da, mờ thâm", text)
            self.assertIn("Làm sạch dịu nhẹ", text)
            self.assertIn("một trăm chín mươi chín nghìn đồng", text)


if __name__ == "__main__":
    unittest.main()
