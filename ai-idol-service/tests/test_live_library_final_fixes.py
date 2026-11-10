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


# ---------------------------------------------------------------- I3: a failed phase-A writer can be retried

import datetime as dt  # noqa: E402
import functools  # noqa: E402

import core.campaigns as campaigns  # noqa: E402
from tests.test_live_library_campaign import (  # noqa: E402
    Harness, fake_frame_clips, fake_plan, fake_product_clips, snapshot_for)


class WriterFailureRetryTests(Harness):
    def prepare(self, cid, frame):
        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for), \
                patch.object(campaigns.show_plan, "build_show_plan", side_effect=fake_plan), \
                patch.object(campaigns.show_writer, "write_product_clips", side_effect=fake_product_clips), \
                patch.object(campaigns.show_writer, "load_answered_faqs", return_value=[]), \
                patch.object(campaigns.show_writer, "write_frame_clips", side_effect=frame):
            return campaigns.prepare_campaign(cid)

    def test_writer_429_then_retry_queues_prepare(self):
        cid, _ = self.create()

        def boom(*args, **kwargs):
            raise RuntimeError("Groq is unavailable: 429")

        self.assertEqual(self.prepare(cid, boom), [])
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")
        queued = {"prepare": [], "job": [], "assemble": [], "recap": []}
        result = campaigns.retry_campaign(
            cid, enqueue_job=queued["job"].append, enqueue_prepare=queued["prepare"].append,
            enqueue_assemble=queued["assemble"].append, enqueue_recap=queued["recap"].append)
        self.assertTrue(result["prepare_queued"])
        self.assertEqual(queued, {"prepare": [cid], "job": [], "assemble": [], "recap": []})
        self.assertEqual(self.campaign()["status"], "GENERATING")
        self.assertIsNone(self.campaign()["error_message"])
        # The queued prepare now writes the library for real.
        self.assertTrue(self.prepare(cid, fake_frame_clips))
        self.assertTrue(self.db.ai_idol_segments.docs)

    def test_written_library_without_failures_still_conflicts(self):
        cid, _ = self.create()
        self.prepare(cid, fake_frame_clips)
        self.db.ai_idol_campaigns.update_one({}, {"$set": {"status": "NEEDS_ATTENTION"}})
        with self.assertRaises(campaigns.CampaignConflict):
            campaigns.retry_campaign(cid, enqueue_job=print, enqueue_prepare=print,
                                     enqueue_assemble=print, enqueue_recap=print)


# ---------------------------------------------------------------- I4: agent lead time for live libraries

from agents.service import _earliest_start  # noqa: E402
from tests.test_autopilot_service import OTHER, VICHY, AutopilotHarness, row  # noqa: E402
from tests.test_agent import process_agent_run  # noqa: E402,F401

LATE_MORNING = dt.datetime(2026, 10, 15, 10, 30, tzinfo=dt.timezone(dt.timedelta(hours=7)))


class LateMorningHarness(AutopilotHarness):
    def execute(self, catalog, **kwargs):
        import agents.service as service
        pinned = functools.partial(service.process_agent_run, now=LATE_MORNING, snapshots=None)
        with patch("tests.test_agent.process_agent_run", new=pinned):
            return super(AutopilotHarness, self).execute(catalog, **kwargs)


class LiveLeadTimeTests(unittest.TestCase):
    def test_earliest_start_for_live_uses_library_estimate(self):
        for n in (1, 3, 6):
            expected = show_plan.estimate_preparation_minutes(n) + 10
            got = _earliest_start(n, LATE_MORNING, purpose="live_and_product") - LATE_MORNING
            self.assertEqual(got, dt.timedelta(minutes=expected), n)
        self.assertEqual(_earliest_start(3, LATE_MORNING, purpose="live_only") - LATE_MORNING,
                         dt.timedelta(minutes=108))

    def test_legacy_earliest_start_unchanged(self):
        self.assertEqual(_earliest_start(1, LATE_MORNING) - LATE_MORNING, dt.timedelta(minutes=60))

    def test_autopilot_three_products_schedule_passes_real_lead_check(self):
        catalog = [VICHY, OTHER, row(935, "Kem Dưỡng Cerave", stock=4)]
        harness = LateMorningHarness("Livestream giới thiệu sản phẩm mã 995, mã 934 và mã 935")
        run = harness.execute(catalog)
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        payload = harness.payload
        self.assertEqual(len(payload["product_ids"]), 3)
        scheduled = dt.datetime.fromisoformat(payload["scheduled_at"])
        self.assertGreaterEqual(scheduled - LATE_MORNING, dt.timedelta(minutes=108))
        # The real create_campaign lead-time check, at the same moment, accepts it.
        db = FakeDb()
        with patch.object(campaigns, "get_db", return_value=db), \
                patch.object(campaigns, "utcnow", return_value=LATE_MORNING.astimezone(dt.timezone.utc)):
            cid, created = campaigns.create_campaign(payload)
        self.assertTrue(created)


# ---------------------------------------------------------------- I6: greet/wave padding only on opening/closing

import json  # noqa: E402
import wave  # noqa: E402

from engines import audio_mixer, syna_engine  # noqa: E402

PAD_JOB = "64b64b64b64b64b64b64b64b"


class StopRender(Exception):
    pass


class LibraryPaddingTests(unittest.TestCase):
    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.folder = folder.name
        self.audio = os.path.join(self.folder, "voice.wav")
        with wave.open(self.audio, "wb") as handle:
            handle.setnchannels(1)
            handle.setsampwidth(2)
            handle.setframerate(8000)
            handle.writeframes(b"\x00\x00" * 8000)  # 1 s of speech
        with open(self.audio + ".timings.json", "w", encoding="utf-8") as handle:
            json.dump([{"start": 0.0, "end": 1.0, "text": "Chào cả nhà."}], handle)
        self.legacy_intro, self.legacy_outro = audio_mixer.lead_in_seconds(PAD_JOB, {})
        self.assertGreater(self.legacy_intro, 0)
        self.assertGreater(self.legacy_outro, 0)

    def test_lead_in_seconds_by_kind(self):
        intro, outro = self.legacy_intro, self.legacy_outro
        self.assertEqual(audio_mixer.lead_in_seconds(PAD_JOB, {}, None), (intro, outro))
        self.assertEqual(audio_mixer.lead_in_seconds(PAD_JOB, {}, "opening"), (intro, 0.0))
        self.assertEqual(audio_mixer.lead_in_seconds(PAD_JOB, {}, "closing"), (0.0, outro))
        for kind in ("interlude", "product", "faq", "tip", "transition", "routine_recap"):
            self.assertEqual(audio_mixer.lead_in_seconds(PAD_JOB, {}, kind), (0.0, 0.0), kind)

    def render_payload(self, clip_kind):
        sent = {}

        class Health:
            def raise_for_status(self):
                pass

            def json(self):
                return {"renderer": syna_engine.RENDER_VERSION}

        def post(url, json=None, **kwargs):
            sent.update(json)
            raise StopRender()

        extra = {"clip_kind": clip_kind} if clip_kind else {}
        with patch.object(syna_engine, "probe_duration", return_value=1.0), \
                patch.object(syna_engine, "OUTPUT_DIR", self.folder), \
                patch.object(syna_engine, "TEMP_DIR", self.folder), \
                patch.object(syna_engine.requests, "get", return_value=Health()), \
                patch.object(syna_engine.requests, "post", side_effect=post):
            with self.assertRaises(StopRender):
                syna_engine.render_syna_video(PAD_JOB, self.audio, "Chào cả nhà.", {"name": "Serum"},
                                              {"format": "16:9"}, purpose="live_and_product", **extra)
        return sent

    def test_interlude_renders_without_padding(self):
        manifest = self.render_payload("interlude")["manifest"]
        self.assertEqual(manifest["duration"], 1.0)
        self.assertNotIn("speech", manifest)

    def test_opening_keeps_only_the_greeting(self):
        manifest = self.render_payload("opening")["manifest"]
        self.assertAlmostEqual(manifest["duration"], round(1.0 + self.legacy_intro, 3))
        self.assertAlmostEqual(manifest["speech"]["start"], self.legacy_intro)
        self.assertAlmostEqual(manifest["speech"]["end"], manifest["duration"])  # no wave-goodbye tail

    def test_closing_keeps_only_the_goodbye(self):
        manifest = self.render_payload("closing")["manifest"]
        self.assertAlmostEqual(manifest["duration"], round(1.0 + self.legacy_outro, 3))
        self.assertEqual(manifest["speech"]["start"], 0.0)
        self.assertAlmostEqual(manifest["speech"]["end"], 1.0)

    def test_legacy_render_keeps_both(self):
        manifest = self.render_payload(None)["manifest"]
        self.assertAlmostEqual(manifest["duration"], round(1.0 + self.legacy_intro + self.legacy_outro, 3))

    def test_mix_uses_the_same_kind_padding(self):
        video = os.path.join(self.folder, "clip.mp4")
        with open(video, "wb") as handle:
            handle.write(b"0" * 2048)
        seen = []

        def build(*args, **kwargs):
            seen.append((kwargs.get("intro"), kwargs.get("outro")))
            raise StopRender()

        with patch.object(audio_mixer, "_probe_duration", return_value=1.0), \
                patch.object(audio_mixer, "pick_music_track", return_value=""), \
                patch.object(audio_mixer, "build_mix_command", side_effect=build):
            self.assertEqual(audio_mixer.mix_program_audio(PAD_JOB, video, self.audio, {}, video_padded=True,
                                                           clip_kind="interlude"), video)
        self.assertEqual(seen, [(0.0, 0.0)])

    def test_applied_intro_fallback_by_kind(self):
        args = dict(padded=True, rendered="/a.mp4", final="/a.mp4", applied={})
        self.assertEqual(pipeline.applied_intro(PAD_JOB, {}, clip_kind="product", **args), 0.0)
        self.assertEqual(pipeline.applied_intro(PAD_JOB, {}, clip_kind="opening", **args), self.legacy_intro)
        self.assertEqual(pipeline.applied_intro(PAD_JOB, {}, **args), self.legacy_intro)

    def test_pipeline_threads_kind_to_render_mix_and_page_cut(self):
        db = FakeDb()
        video = os.path.join(self.folder, "clip.mp4")
        with open(video, "wb") as handle:
            handle.write(b"0" * 2048)
        segment_id = str(db.ai_idol_segments.insert_one({"kind": "product", "angle": "A"}).inserted_id)
        job_id = str(db.ai_idol_jobs.insert_one({
            "product_id": "2", "content_mode": "Product Intro", "purpose": "live_and_product",
            "configuration": {"avatar_mode": "syna_3d", "format": "16:9", "approval_mode": "AUTO"},
            "status": "QUEUED", "current_stage": "QUEUED", "product_snapshot": SNAPS["2"],
            "segment_id": segment_id, "prewritten_script": True, "clip_kind": "product",
            "script_status": "APPROVED",
            "artifacts": {"knowledge": "", "script_text": "[Lead-in]\nChào.", "audio_path": self.audio,
                          "audio_pipeline_version": AUDIO_PIPELINE_VERSION},
        }).inserted_id)
        calls = {}

        def mix(job, path, voice, config, snapshot=None, video_padded=False, applied=None, **kwargs):
            calls["mix"] = kwargs
            return path  # mix skipped: the intro comes from the render fallback

        def page(job, purpose, final, voice, *, intro, **kwargs):
            calls["page_intro"] = intro
            return None, None

        with ExitStack() as stack:
            for module in ("core.state_machine", "core.pipeline"):
                stack.enter_context(patch(f"{module}.get_db", return_value=db))
            stack.enter_context(patch(NO_LLM, return_value=[]))
            render = stack.enter_context(patch("engines.syna_engine.render_syna_video", return_value=video))
            stack.enter_context(patch("engines.audio_mixer.mix_program_audio", side_effect=mix))
            stack.enter_context(patch("engines.body_clip.product_page_clip", side_effect=page))
            stack.enter_context(patch("engines.video_composer.get_audio_duration", return_value=110.0))
            pipeline.run_ai_idol_pipeline(job_id)
            render.assert_called_once()
        self.assertEqual(render.call_args.kwargs["clip_kind"], "product")
        self.assertEqual(calls["mix"].get("clip_kind"), "product")
        self.assertEqual(calls["page_intro"], 0.0)  # angle A cut = 0 + label start


# ---------------------------------------------------------------- Ruling 13b: retries keep the job alive

class RetryHeartbeatTests(unittest.TestCase):
    def test_render_retry_refreshes_job_before_each_retry(self):
        db = FakeDb()
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        audio = os.path.join(folder.name, "voice.wav")
        video = os.path.join(folder.name, "clip.mp4")
        for path in (audio, video):
            with open(path, "wb") as handle:
                handle.write(b"0" * 2048)
        job_id = str(db.ai_idol_jobs.insert_one({
            "product_id": None, "content_mode": "Product Intro", "purpose": "live_only",
            "configuration": {"avatar_mode": "syna_3d", "format": "16:9", "approval_mode": "AUTO"},
            "status": "QUEUED", "current_stage": "QUEUED", "product_snapshot": combined(),
            "prewritten_script": True, "clip_kind": "interlude", "script_status": "APPROVED",
            "artifacts": {"knowledge": "", "script_text": "Bấm giỏ hàng nha cả nhà!", "audio_path": audio,
                          "audio_pipeline_version": AUDIO_PIPELINE_VERSION},
        }).inserted_id)
        outcomes = [RuntimeError("ffprobe timed out"), RuntimeError("Syna render HTTP 503: busy"), video]
        events = []

        def render(*args, **kwargs):
            events.append("render")
            outcome = outcomes.pop(0)
            if isinstance(outcome, Exception):
                raise outcome
            return outcome

        real_stage = pipeline.update_job_stage

        def stage(jid, name, *args, **kwargs):
            events.append(("stage", name))
            return real_stage(jid, name, *args, **kwargs)

        with ExitStack() as stack:
            for module in ("core.state_machine", "core.pipeline"):
                stack.enter_context(patch(f"{module}.get_db", return_value=db))
            stack.enter_context(patch(NO_LLM, return_value=[]))
            stack.enter_context(patch("engines.syna_engine.render_syna_video", side_effect=render))
            stack.enter_context(patch("engines.syna_engine.time.sleep", side_effect=lambda s: events.append(("sleep", s))))
            stack.enter_context(patch.object(pipeline, "update_job_stage", side_effect=stage))
            stack.enter_context(patch("engines.audio_mixer.mix_program_audio",
                                      side_effect=lambda job, path, *a, **k: path))
            stack.enter_context(patch("engines.video_composer.get_audio_duration", return_value=15.0))
            self.assertTrue(pipeline.run_ai_idol_pipeline(job_id))
        tail = events[events.index(("stage", "RENDERING")):]
        self.assertEqual(tail, [("stage", "RENDERING"), "render", ("sleep", 30), ("stage", "RENDERING"), "render",
                                ("sleep", 90), ("stage", "RENDERING"), "render"])


if __name__ == "__main__":
    unittest.main()
