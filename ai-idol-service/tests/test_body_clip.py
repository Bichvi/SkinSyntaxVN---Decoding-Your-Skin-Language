import json
import os
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import core.pipeline as pipeline
from engines import body_clip
from engines.syna_engine import build_manifest


TIMINGS = [
    {"start": 0.0, "end": 5.0, "text": "Da dầu?", "label": "hook"},
    {"start": 41.2, "end": 50.0, "text": "Giá chỉ một trăm nghìn đồng.", "label": "price"},
    {"start": 55.0, "end": 60.0, "text": "Chốt đơn ngay.", "label": "cta"},
]
PRICE_ONLY = TIMINGS[:2]
CLOSING = TIMINGS[:2] + [{"start": 52.0, "end": 58.0, "text": "Tạm biệt.", "label": "closing"}]
PRICING = {"regular_price": 299000, "market_price": 350000,
           "flash": {"campaign_id": 12, "flash_price": 249000, "end_ts": 1792256340}}


class CutPointTests(unittest.TestCase):
    def test_cut_lands_on_first_sale_section_after_the_intro(self):
        self.assertAlmostEqual(body_clip.body_cut_seconds(TIMINGS, 2.5), 43.7)

    def test_cut_labels_can_be_narrowed(self):
        self.assertAlmostEqual(body_clip.body_cut_seconds(TIMINGS, 2.5, labels=frozenset({"cta"})), 57.5)

    def test_no_sale_section_means_no_cut(self):
        self.assertIsNone(body_clip.body_cut_seconds(TIMINGS[:1], 2.5))

    def test_price_from_per_purpose(self):
        self.assertEqual(body_clip.price_from_for("live_only", TIMINGS, 2.5), 0.0)
        self.assertIsNone(body_clip.price_from_for("product_video", TIMINGS, 2.5))
        self.assertAlmostEqual(body_clip.price_from_for("live_and_product", TIMINGS, 2.5), 43.7)
        self.assertEqual(body_clip.price_from_for("live_and_product", TIMINGS[:1], 2.5), 0.0)

    def test_price_from_product_video_with_claim_is_zero(self):
        self.assertEqual(body_clip.price_from_for("product_video", TIMINGS, 2.5, has_claim=True), 0.0)

    def test_price_from_product_video_without_claim_is_none(self):
        self.assertIsNone(body_clip.price_from_for("product_video", TIMINGS, 2.5, has_claim=False))

    def test_price_from_live_and_product_is_price_section_start(self):
        self.assertAlmostEqual(body_clip.price_from_for("live_and_product", TIMINGS, 2.5), 43.7)

    def test_price_from_live_and_product_falls_back_without_price_label(self):
        timings = [TIMINGS[0], TIMINGS[2]]
        self.assertAlmostEqual(body_clip.price_from_for("live_and_product", timings, 2.5), 57.5)

    def test_board_price_prefers_flash(self):
        snap = {"price": 1, "sale_price": 2, "pricing": PRICING}
        self.assertEqual(body_clip.board_price(snap, "product_video"), 249000)
        self.assertEqual(body_clip.board_price(snap, "live_and_product"), 249000)
        self.assertEqual(body_clip.board_price({"pricing": {**PRICING, "flash": None}}, "product_video"), 299000)
        self.assertEqual(body_clip.board_price({"price": 5}, "product_video"), 0)

    def test_board_price_live_only_unchanged(self):
        self.assertEqual(body_clip.board_price({"price": 100, "sale_price": 80, "pricing": PRICING}, "live_only"), 80)
        self.assertEqual(body_clip.board_price({"price": 100}, "live_only"), 100)

    def test_cut_command_trims_and_fades_out(self):
        with patch.object(body_clip.subprocess, "run", return_value=SimpleNamespace(returncode=0, stderr="")) as run, \
                patch.object(body_clip.os.path, "getsize", return_value=4096):
            body_clip.cut_body_clip("in.mp4", "out.mp4", 43.7)
        command = " ".join(run.call_args[0][0])
        self.assertIn("-to 43.700", command)
        self.assertIn("afade=t=out:st=43.100:d=0.6", command)
        self.assertIn("fade=t=out:st=43.100:d=0.6", command)


class ProductPageClipTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.audio = os.path.join(self.folder.name, "voice.wav")
        self.video = os.path.join(self.folder.name, "final.mp4")

    def write_timings(self, timings):
        with open(self.audio + ".timings.json", "w", encoding="utf-8") as handle:
            json.dump(timings, handle)

    def test_live_only_has_no_product_page_clip(self):
        self.assertEqual(body_clip.product_page_clip("j", "live_only", self.video, self.audio, intro=2.5), (None, None))

    def test_product_video_uses_the_full_render(self):
        self.assertEqual(body_clip.product_page_clip("j", "product_video", self.video, self.audio, intro=2.5),
                         (self.video, None))

    def test_live_and_product_clip_cuts_at_cta(self):
        self.write_timings(TIMINGS)
        with patch.object(body_clip, "cut_body_clip", side_effect=lambda src, dst, cut: dst) as cut:
            body_clip.product_page_clip("j", "live_and_product", self.video, self.audio, intro=2.5)
        self.assertAlmostEqual(cut.call_args[0][2], 57.5)

    def test_live_and_product_clip_falls_back_to_closing(self):
        self.write_timings(CLOSING)
        with patch.object(body_clip, "cut_body_clip", side_effect=lambda src, dst, cut: dst) as cut:
            body_clip.product_page_clip("j", "live_and_product", self.video, self.audio, intro=2.5)
        self.assertAlmostEqual(cut.call_args[0][2], 54.5)

    def test_live_and_product_without_cta_or_closing_is_blocked(self):
        self.write_timings(PRICE_ONLY)
        self.assertEqual(body_clip.product_page_clip("j", "live_and_product", self.video, self.audio, intro=2.5),
                         (None, "missing_price_marker"))

    def test_live_and_product_without_price_marker_is_blocked(self):
        self.write_timings(TIMINGS[:1])
        self.assertEqual(body_clip.product_page_clip("j", "live_and_product", self.video, self.audio, intro=2.5),
                         (None, "missing_price_marker"))

    def test_live_and_product_cuts_after_the_applied_lead_in(self):
        self.write_timings(TIMINGS)
        with patch.object(body_clip, "cut_body_clip", side_effect=lambda src, dst, cut: dst) as cut:
            path, reason = body_clip.product_page_clip("j", "live_and_product", self.video, self.audio, intro=2.5)
        self.assertIsNone(reason)
        self.assertTrue(path.endswith(".body.mp4"))
        self.assertAlmostEqual(cut.call_args[0][2], 57.5)

    def test_review_c2_no_lead_in_cuts_exactly_at_cta(self):
        self.write_timings(TIMINGS)
        with patch.object(body_clip, "cut_body_clip", side_effect=lambda src, dst, cut: dst) as cut:
            body_clip.product_page_clip("j", "live_and_product", self.video, self.audio, intro=0.0)
        self.assertAlmostEqual(cut.call_args[0][2], 55.0)

    def test_review_c2_pipeline_uses_the_lead_in_the_mixer_applied(self):
        # Mixed file: trust what the mixer reports (0 for a voice-only or mismatched mix).
        self.assertEqual(pipeline.applied_intro("j", {}, padded=False, rendered="/a.mp4",
                                                final="/a.mix.mp4", applied={"intro": 0.0}), 0.0)
        with patch.object(pipeline, "lead_in_seconds", return_value=(2.5, 2.0)):
            # Mix skipped: a Syna render still carries its own lead-in; other renders have none.
            self.assertEqual(pipeline.applied_intro("j", {}, padded=True, rendered="/a.mp4",
                                                    final="/a.mp4", applied={}), 2.5)
            self.assertEqual(pipeline.applied_intro("j", {}, padded=False, rendered="/a.mp4",
                                                    final="/a.mp4", applied={}), 0.0)

    def test_cut_failure_blocks_the_page_but_keeps_the_job(self):
        self.write_timings(TIMINGS)
        with patch.object(body_clip, "cut_body_clip", side_effect=RuntimeError("ffmpeg")):
            self.assertEqual(body_clip.product_page_clip("j", "live_and_product", self.video, self.audio, intro=0.0),
                             (None, "body_cut_failed"))


class ManifestTests(unittest.TestCase):
    def manifest(self, purpose):
        return build_manifest({"name": "A", "price": 100000}, "[Hook]\nDa dầu?\n[Price]\nGiá tốt.", 52.0,
                              [dict(item, start=item["start"] + 2.5, end=item["end"] + 2.5) for item in TIMINGS],
                              {"format": "16:9"}, purpose=purpose)

    def test_price_from_follows_purpose(self):
        self.assertIsNone(self.manifest("product_video")["product"]["price_from"])
        self.assertEqual(self.manifest("live_only")["product"]["price_from"], 0.0)
        self.assertAlmostEqual(self.manifest("live_and_product")["product"]["price_from"], 43.7)


class CompleteJobTests(unittest.TestCase):
    def test_video_document_records_product_page_fields(self):
        db = FakeDb()
        with patch("engines.video_composer.get_audio_duration", return_value=48.0), \
                patch.object(pipeline, "get_db", return_value=db), \
                patch("core.pipeline.os.path.isfile", return_value=True):
            pipeline._complete_video_job(
                "64b64b64b64b64b64b64b64b", "93", {"format": "16:9"}, "/x/final.mp4", "/x/voice.wav", db,
                purpose="live_and_product", campaign_id="c1", page_path=None, block_reason="missing_price_marker",
            )
        video = db.ai_idol_videos.find_one({})
        self.assertEqual(video["purpose"], "live_and_product")
        self.assertEqual(video["campaign_id"], "c1")
        self.assertIsNone(video["product_page_file_path"])
        self.assertEqual(video["product_page_block_reason"], "missing_price_marker")


if __name__ == "__main__":
    unittest.main()
