"""Prewritten live clips through the pipeline: no generation, clip_kind validation, page cut, manifest, retries."""
import json
import os
import sys
import tempfile
import types
import unittest
from contextlib import ExitStack
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import core.pipeline as pipeline
import core.state_machine as state_machine
from engines import body_clip, syna_engine

PRODUCT_TIMINGS = [
    {"start": 0.0, "end": 8.0, "text": "Chào", "label": "lead_in"},
    {"start": 8.0, "end": 30.0, "text": "Da bạn?", "label": "pain_point"},
    {"start": 30.0, "end": 60.0, "text": "Vì sao", "label": "why"},
    {"start": 60.0, "end": 90.0, "text": "Giá", "label": "price"},
    {"start": 90.0, "end": 100.0, "text": "Chốt", "label": "cta"},
]
DRAFT = "[Lead-in]\nChào bạn."
SNAPSHOT = {"id": "7", "name": "Serum A", "pricing": {"regular_price": 100000, "flash": None}}
COMBINED = {"name": "Routine tối", "brand": "SkinSyntax", "products": [], "bundle_total": None, "pricing": None}


class FakeRun:
    def __init__(self, test):
        self.db = FakeDb()
        self.stack = ExitStack()
        test.addCleanup(self.stack.close)
        for module in ("core.state_machine", "core.pipeline"):
            self.stack.enter_context(patch(f"{module}.get_db", return_value=self.db))
        self.validator_calls, self.generate_calls, self.knowledge_calls = [], [], []

        def generate(*args, **kwargs):
            self.generate_calls.append(args)
            return "[Lead-in]\nDo LLM viết."

        def retrieve(snapshot):
            self.knowledge_calls.append(snapshot)
            return "facts"

        def validate(script, snapshot, purpose, **kwargs):
            self.validator_calls.append((script, snapshot, purpose, kwargs))
            return types.SimpleNamespace(passed=True, script=script, feedback="", repair_attempts=0, issues=[],
                                         history=[{"version": 1, "text": script, "passed": True, "issues": []}])

        self.stack.enter_context(patch.dict(sys.modules, {
            "engines.knowledge_retriever": types.SimpleNamespace(retrieve_knowledge=retrieve),
            "engines.content_agent": types.SimpleNamespace(generate_content_and_script=generate),
            "engines.script_validator": types.SimpleNamespace(
                validate_and_repair_script=validate, validate_script=lambda *a, **k: []),
        }))

    def job(self, **extra):
        doc = {"product_id": "7", "content_mode": "Product Intro", "purpose": "live_and_product",
               "configuration": {"approval_mode": "MANUAL"}, "status": "QUEUED", "current_stage": "QUEUED",
               "product_snapshot": SNAPSHOT, "artifacts": {}, **extra}
        return str(self.db.ai_idol_jobs.insert_one(doc).inserted_id)

    def stored(self, job_id):
        return self.db.ai_idol_jobs.find_one({"_id": state_machine.object_id(job_id)})


class PrewrittenPipelineTests(unittest.TestCase):
    def test_prewritten_job_skips_generation(self):
        run = FakeRun(self)
        job_id = run.job(prewritten_script=True, clip_kind="product",
                         artifacts={"prewritten_draft": DRAFT})
        self.assertFalse(pipeline.run_ai_idol_pipeline(job_id))  # parked for manual approval
        self.assertEqual(run.generate_calls, [])
        self.assertEqual(run.knowledge_calls, [])
        self.assertEqual(len(run.validator_calls), 1)
        script, snapshot, purpose, kwargs = run.validator_calls[0]
        self.assertEqual(script, DRAFT)
        self.assertEqual(snapshot, SNAPSHOT)
        self.assertEqual(purpose, "live_and_product")  # the campaign purpose, never a synthetic one
        self.assertEqual(kwargs["clip_kind"], "product")
        stored = run.stored(job_id)
        self.assertEqual(stored["artifacts"]["knowledge"], "")
        self.assertEqual(stored["artifacts"]["script_text"], DRAFT)
        self.assertEqual(stored["status"], "AWAITING_APPROVAL")

    def test_prewritten_non_product_job_validates_combined_snapshot(self):
        run = FakeRun(self)
        job_id = run.job(product_id=None, prewritten_script=True, clip_kind="interlude", purpose="live_only",
                         product_snapshot=COMBINED, artifacts={"prewritten_draft": DRAFT})
        pipeline.run_ai_idol_pipeline(job_id)
        _, snapshot, purpose, kwargs = run.validator_calls[0]
        self.assertEqual((snapshot, purpose, kwargs["clip_kind"]), (COMBINED, "live_only", "interlude"))

    def test_legacy_job_still_generates(self):
        run = FakeRun(self)
        job_id = run.job(purpose="live_only")
        pipeline.run_ai_idol_pipeline(job_id)
        self.assertEqual(len(run.generate_calls), 1)
        self.assertEqual(len(run.knowledge_calls), 1)
        script, _, purpose, kwargs = run.validator_calls[0]
        self.assertEqual(script, "[Lead-in]\nDo LLM viết.")
        self.assertEqual(purpose, "live_only")
        self.assertNotIn("clip_kind", kwargs)
        self.assertEqual(run.stored(job_id)["artifacts"]["knowledge"], "facts")


class PageClipTests(unittest.TestCase):
    def setUp(self):
        self.db = FakeDb()
        patcher = patch("core.state_machine.get_db", return_value=self.db)
        patcher.start()
        self.addCleanup(patcher.stop)
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.audio = os.path.join(self.folder.name, "voice.wav")
        with open(self.audio + ".timings.json", "w", encoding="utf-8") as handle:
            json.dump(PRODUCT_TIMINGS, handle)
        self.video = os.path.join(self.folder.name, "clip.mp4")

    def job_with_angle(self, angle):
        segment_id = str(self.db.ai_idol_segments.insert_one({"kind": "product", "angle": angle}).inserted_id)
        return str(self.db.ai_idol_jobs.insert_one({"segment_id": segment_id, "clip_kind": "product"}).inserted_id)

    def test_page_clip_window_for_product_angle_a(self):
        job_id = self.job_with_angle("A")
        with patch.object(body_clip, "cut_body_clip", side_effect=lambda src, dst, cut, **kw: dst) as cut:
            path, reason = body_clip.product_page_clip(job_id, "live_and_product", self.video, self.audio,
                                                       intro=2.5, clip_kind="product")
        self.assertIsNone(reason)
        self.assertTrue(path.endswith(".body.mp4"))
        args, kwargs = cut.call_args
        self.assertAlmostEqual(args[2], 62.5)  # price start + intro
        self.assertAlmostEqual(kwargs["start_seconds"], 10.5)  # pain_point start + intro

    def test_angle_b_has_no_page_clip(self):
        job_id = self.job_with_angle("B")
        with patch.object(body_clip, "cut_body_clip") as cut:
            self.assertEqual(body_clip.product_page_clip(job_id, "live_and_product", self.video, self.audio,
                                                         intro=2.5, clip_kind="product"), (None, None))
        cut.assert_not_called()

    def test_live_only_product_has_no_page_clip(self):
        job_id = self.job_with_angle("A")
        self.assertEqual(body_clip.product_page_clip(job_id, "live_only", self.video, self.audio,
                                                     intro=2.5, clip_kind="product"), (None, None))

    def test_no_page_clip_for_interlude(self):
        for kind in ("interlude", "opening", "faq", "tip", "routine_recap", "transition", "closing"):
            with patch.object(body_clip, "cut_body_clip") as cut:
                self.assertEqual(body_clip.product_page_clip("x" * 24, "live_and_product", self.video, self.audio,
                                                             intro=2.5, clip_kind=kind), (None, None), kind)
            cut.assert_not_called()

    def test_missing_markers_withholds_the_clip(self):
        job_id = self.job_with_angle("A")
        with open(self.audio + ".timings.json", "w", encoding="utf-8") as handle:
            json.dump(PRODUCT_TIMINGS[:2], handle)
        self.assertEqual(body_clip.product_page_clip(job_id, "live_and_product", self.video, self.audio,
                                                     intro=2.5, clip_kind="product"), (None, "missing_price_marker"))

    def test_legacy_call_without_clip_kind_unchanged(self):
        with patch.object(body_clip, "cut_body_clip", side_effect=lambda src, dst, cut, **kw: dst) as cut:
            path, _ = body_clip.product_page_clip("legacy", "live_and_product", self.video, self.audio, intro=2.5)
        args, kwargs = cut.call_args
        self.assertAlmostEqual(args[2], 92.5)  # first cta
        self.assertEqual(kwargs, {})

    def test_cut_body_clip_command(self):
        class Result:
            returncode, stderr = 0, ""
        written = os.path.join(self.folder.name, "out.mp4")
        with open(written, "wb") as handle:
            handle.write(b"0" * 2048)
        with patch("subprocess.run", return_value=Result()) as run:
            body_clip.cut_body_clip("in.mp4", written, 62.5)
            legacy = run.call_args[0][0]
            body_clip.cut_body_clip("in.mp4", written, 62.5, start_seconds=10.5)
            windowed = run.call_args[0][0]
        self.assertEqual(legacy[:6], ["ffmpeg", "-y", "-i", "in.mp4", "-to", "62.500"])
        self.assertNotIn("-ss", legacy)
        self.assertEqual(windowed[:8], ["ffmpeg", "-y", "-ss", "10.500", "-i", "in.mp4", "-t", "52.000"])
        self.assertIn("fade=t=out:st=51.400:d=0.6", windowed)


class ManifestTests(unittest.TestCase):
    TIMINGS = [{"start": 0.0, "end": 5.0, "text": "Xin chào các bạn.", "label": "opening"}]

    def build(self, **extra):
        snapshot = {**SNAPSHOT, "ingredients": "Niacinamide"}
        return syna_engine.build_manifest(snapshot, "Xin chào các bạn.", 6.0, self.TIMINGS, {"format": "16:9"},
                                          purpose="live_and_product", **extra)

    def test_manifest_for_interlude_hides_price(self):
        manifest = self.build(clip_kind="interlude", theme="Routine tối")
        self.assertEqual(manifest["product"], {"name": "Routine tối", "price": 0, "price_from": None})
        self.assertEqual(manifest["ingredients"], [])

    def test_manifest_theme_falls_back(self):
        self.assertEqual(self.build(clip_kind="opening")["product"]["name"], "SkinSyntax Live")

    def test_manifest_for_product_clip_keeps_price(self):
        manifest = self.build(clip_kind="product")
        self.assertEqual(manifest["product"]["name"], "Serum A")
        self.assertEqual(manifest["product"]["price"], 100000)
        self.assertEqual(manifest["product"]["price_from"], 0.0)  # no [Price] label in these timings
        self.assertEqual(manifest["ingredients"], ["Niacinamide"])

    def test_product_clip_price_starts_at_price_label(self):
        timings = [{"start": 0.0, "end": 3.0, "text": "a", "label": "lead_in"},
                   {"start": 3.0, "end": 5.0, "text": "b", "label": "price"}]
        snapshot = {**SNAPSHOT}
        manifest = syna_engine.build_manifest(snapshot, "a b", 6.0, timings, {"format": "16:9"},
                                              purpose="live_and_product", clip_kind="product")
        self.assertEqual(manifest["product"]["price_from"], 3.0)

    def test_manifest_without_clip_kind_is_legacy(self):
        manifest = syna_engine.build_manifest({**SNAPSHOT}, "Xin chào các bạn.", 6.0, self.TIMINGS,
                                              {"format": "16:9"}, purpose="live_only")
        self.assertEqual(manifest["product"]["price_from"], 0.0)
        self.assertEqual(manifest["product"]["name"], "Serum A")


class TransientRetryTests(unittest.TestCase):
    def test_classifier(self):
        for message in ("Syna render HTTP 422: Cannot read audio duration", "page.goto: Timeout 30000ms exceeded",
                        "Syna render HTTP 500: boom", "ffprobe timed out", "Connection reset"):
            self.assertTrue(syna_engine.is_transient_render_error(message), message)
        for message in ("Syna render HTTP 400: Invalid manifest", "Âm thanh quá lớn", ""):
            self.assertFalse(syna_engine.is_transient_render_error(message), message)

    def test_transient_render_retried_twice_then_fails(self):
        calls, sleeps = [], []

        def render():
            calls.append(1)
            raise RuntimeError("Syna render HTTP 422: Cannot read audio duration")

        with self.assertRaises(RuntimeError):
            syna_engine.render_with_retries(render, sleep=sleeps.append)
        self.assertEqual(len(calls), 3)
        self.assertEqual(sleeps, [30, 90])

    def test_transient_then_success(self):
        outcomes = [RuntimeError("ffprobe timed out"), "video.mp4"]
        sleeps = []

        def render():
            outcome = outcomes.pop(0)
            if isinstance(outcome, Exception):
                raise outcome
            return outcome

        self.assertEqual(syna_engine.render_with_retries(render, sleep=sleeps.append), "video.mp4")
        self.assertEqual(sleeps, [30])

    def test_non_transient_not_retried(self):
        calls, sleeps = [], []

        def render():
            calls.append(1)
            raise RuntimeError("Syna render HTTP 400: Invalid manifest")

        with self.assertRaises(RuntimeError):
            syna_engine.render_with_retries(render, sleep=sleeps.append)
        self.assertEqual((len(calls), sleeps), (1, []))

    def test_renderer_unavailable_not_retried(self):
        calls, sleeps = [], []

        def render():
            calls.append(1)
            raise syna_engine.RendererUnavailable("Connection refused")

        with self.assertRaises(syna_engine.RendererUnavailable):
            syna_engine.render_with_retries(render, sleep=sleeps.append)
        self.assertEqual((len(calls), sleeps), (1, []))


if __name__ == "__main__":
    unittest.main()
