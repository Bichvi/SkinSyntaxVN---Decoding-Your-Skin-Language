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


# ---------------------------------------------------------------- I1: no "chốt đơn" in the writer prompts

import time  # noqa: E402

from core.script_text import find_price_issues  # noqa: E402
from engines import show_writer  # noqa: E402
from engines.syna_persona import PERSONA_RULES_VI, ROBOTIC_WORDS  # noqa: E402

FLASH = {"regular_price": 199000, "market_price": None,
         "flash": {"campaign_id": "f1", "flash_price": 159000, "discount_percent": 20,
                   "end_ts": int(time.time()) + 7200}}
FLASH_SNAP = {"id": "2", "name": "Serum Klairs", "brand": "Klairs", "pricing": FLASH}
PRODUCT_BASE = "[Lead-in]\nTới bước serum nè.\n\n[Price]\nGiá một trăm năm mươi chín nghìn đồng.\n\n[CTA]\n"


def all_writer_prompts():
    prompts = [
        show_writer.build_product_prompts(SNAPS["2"], PLAN["products"][1], PLAN, "A"),
        show_writer.build_product_prompts(SNAPS["2"], PLAN["products"][1], PLAN, "B",
                                          {"pain_point": "p", "why": "w"}),
        *show_writer.build_frame_prompts(PLAN, SNAPS, []),
        show_writer.build_recap_prompts(PLAN, SNAPS),
    ]
    return [system + "\n" + user for system, user in prompts]


def live_codes(text, snapshot, kind):
    with patch(NO_LLM, return_value=[]):
        return [(i.code, i.original_text) for i in sv.validate_script(text, snapshot, "live_and_product", kind)]


class NoChotDonTests(unittest.TestCase):
    def test_persona_never_asks_for_chot_don(self):
        self.assertNotIn("chốt đơn", PERSONA_RULES_VI)

    def test_chot_don_only_in_the_banned_list(self):
        self.assertIn("chốt đơn", show_writer._NO_TIME_WORDS)
        for prompt in all_writer_prompts():
            self.assertNotIn("chốt đơn", prompt.replace(show_writer._NO_TIME_WORDS, ""))

    def test_typical_cta_passes_real_validator(self):
        cta = (PRODUCT_BASE.replace("một trăm năm mươi chín", "một trăm chín mươi chín")
               + "Bấm giỏ hàng, chọn món số 2 nha, comment hoặc thả tim cho Syna!")
        self.assertEqual(live_codes(cta, SNAPS["2"], "product"), [])


# ---------------------------------------------------------------- I5: invented social proof

class SocialProofTests(unittest.TestCase):
    CAUGHT = (
        "Năm trăm bà đã chốt rồi, bấm giỏ hàng chọn món số 2 nha!",
        "Ba trăm người đang xem đó, bấm giỏ hàng chọn món số 2!",
        "Mở khoá voucher cho mấy bà, bấm giỏ hàng chọn món số 2!",
        "Mở khóa quà nè, bấm giỏ hàng chọn món số 2!",
        "Mo khoa ưu đãi, bấm giỏ hàng chọn món số 2!",
        "Hai trăm khách vừa mua xong, bấm giỏ hàng chọn món số 2!",
        "Đã chốt năm trăm đơn rồi, bấm giỏ hàng chọn món số 2!",
        "300 bạn đã mua, bấm giỏ hàng chọn món số 2!",
    )

    def test_social_proof_caught_even_with_flash_sale(self):
        for cta in self.CAUGHT:
            found = live_codes(PRODUCT_BASE + cta, FLASH_SNAP, "product")
            self.assertIn("BLACKLIST_CLAIM", [code for code, _ in found], cta)

    def test_efficacy_days_with_hai(self):
        for text in ("Thoa hai hôm là hết mụn.", "Ba ngày là dịu liền."):
            self.assertIn("BLACKLIST_CLAIM", [c for c, _ in live_codes(text, combined(), "tip")], text)

    def test_near_misses_pass(self):
        for cta in ("Cả nhà nhớ bấm giỏ hàng nha, chọn món số 2!", "Mấy bà bấm giỏ hàng chọn món số 2 nha!"):
            self.assertEqual(live_codes(PRODUCT_BASE + cta, FLASH_SNAP, "product"), [], cta)
        # Left word boundary: "carbon ngày là hết" is not "bốn ngày là hết".
        self.assertNotIn("BLACKLIST_CLAIM",
                         [c for c, _ in live_codes("Than carbon ngày là hết bụi.", combined(), "tip")])

    def test_voucher_still_allowed_with_flash_sale(self):
        found = live_codes(PRODUCT_BASE + "Có voucher, bấm giỏ hàng chọn món số 2!", FLASH_SNAP, "product")
        self.assertNotIn("BLACKLIST_CLAIM", [c for c, _ in found])

    def test_honesty_rule_is_split(self):
        absolute, flash_only = show_writer._HONESTY_ABSOLUTE, show_writer._HONESTY_FLASH
        self.assertNotIn("trừ khi", absolute)
        for word in ("500 bà đã chốt", "vừa có người mua", "mở khóa", "5.000 tim", "quà tặng"):
            self.assertIn(word, absolute)
        for word in ("voucher", "trợ giá", "flash sale"):
            self.assertIn(word, flash_only)
        for prompt in all_writer_prompts():
            self.assertIn(absolute, prompt)
            self.assertIn(flash_only, prompt)


# ---------------------------------------------------------------- Ruling 13a: conflicts capped

LONG_CONFLICTS = [{"a": "1", "b": "2", "message_vi": f"Xung đột số {i}: " + "Retinol và AHA gây kích ứng. " * 20}
                  for i in range(15)]


class ConflictCapTests(unittest.TestCase):
    def test_prompts_with_fifteen_long_conflicts_fit(self):
        plan = {**PLAN, "conflicts": LONG_CONFLICTS}
        prompts = [
            show_writer.build_product_prompts(SNAPS["2"], plan["products"][1], plan, "B",
                                              {"pain_point": "p", "why": "w"}),
            *show_writer.build_frame_prompts(plan, SNAPS, []),
            show_writer.build_recap_prompts(plan, SNAPS),
        ]
        for system, user in prompts:
            self.assertLessEqual(show_writer.prompt_len(system, user), show_writer.PROMPT_BUDGET)
            self.assertIn("Xung đột số 4", user)
            self.assertNotIn("Xung đột số 5", user)
            for line in user.splitlines():
                if line.startswith("- Xung đột"):
                    self.assertLessEqual(len(line), 162)

    def test_fit_user_warns_when_budget_unreachable(self):
        with self.assertLogs(show_writer.log, level="WARNING"):
            show_writer._fit_user("s", ((1,), (0,)), lambda n: "x" * (show_writer.PROMPT_BUDGET + 10))


# ---------------------------------------------------------------- Ruling 13c: deterministic false positives

class FalsePositiveTests(unittest.TestCase):
    def test_quet_usage_is_not_robotic(self):
        for text in ("Quét một lớp mỏng lên mặt.", "Quét đều từ trong ra ngoài."):
            self.assertIsNone(ROBOTIC_WORDS.search(text), text)
            self.assertNotIn("ROBOTIC_WORDING", [c for c, _ in live_codes(text, combined(), "tip")])

    def test_quet_robotic_senses_flagged(self):
        for text in ("Syna quét làn da của bạn.", "Quét dữ liệu xong rồi.", "Đã quét hàng triệu phản hồi."):
            self.assertIsNotNone(ROBOTIC_WORDS.search(text), text)
            self.assertIn("ROBOTIC_WORDING", [c for c, _ in live_codes(text, combined(), "tip")], text)

    def test_trieu_chung_is_not_money(self):
        for text in ("Giảm ba triệu chứng khó chịu.", "Có 3 triệu chứng thường gặp."):
            self.assertEqual(find_price_issues(text, P2), [], text)

    def test_real_millions_still_money(self):
        for text in ("Giá ba triệu đồng.", "Chỉ 3 triệu thôi.", "Ba triệu nha."):
            self.assertTrue(find_price_issues(text, P2), text)


if __name__ == "__main__":
    unittest.main()
