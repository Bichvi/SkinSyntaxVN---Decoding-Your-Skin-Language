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
]


class CutPointTests(unittest.TestCase):
    def test_cut_lands_on_first_sale_section_after_the_intro(self):
        self.assertAlmostEqual(body_clip.body_cut_seconds(TIMINGS, 2.5), 43.7)

    def test_no_sale_section_means_no_cut(self):
        self.assertIsNone(body_clip.body_cut_seconds(TIMINGS[:1], 2.5))

    def test_price_from_per_purpose(self):
        self.assertEqual(body_clip.price_from_for("live_only", TIMINGS, 2.5), 0.0)
        self.assertIsNone(body_clip.price_from_for("product_video", TIMINGS, 2.5))
        self.assertAlmostEqual(body_clip.price_from_for("live_and_product", TIMINGS, 2.5), 43.7)
        self.assertEqual(body_clip.price_from_for("live_and_product", TIMINGS[:1], 2.5), 0.0)

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
        self.assertAlmostEqual(cut.call_args[0][2], 43.7)

    def test_review_c2_no_lead_in_cuts_exactly_at_price(self):
        self.write_timings(TIMINGS)
        with patch.object(body_clip, "cut_body_clip", side_effect=lambda src, dst, cut: dst) as cut:
            body_clip.product_page_clip("j", "live_and_product", self.video, self.audio, intro=0.0)
        self.assertAlmostEqual(cut.call_args[0][2], 41.2)

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
                patch.object(pipeline, "get_db", return_value=db):
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
