"""Task 9: compose the clip-library timeline and broadcast its real length."""
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from bson import ObjectId

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import Collection, FakeDb

from core.show_types import ProgramItem
from engines import broadcast_engine, program_composer


def item(seg, kind="product", start=0.0, end=10.0, product_id=None, angle=None):
    return ProgramItem(seg, kind, product_id, angle, start, end)


class ConcatLinesTests(unittest.TestCase):
    def test_concat_lines_repeat_clips_in_order(self):
        program = [item("a"), item("b"), item("a")]
        text = program_composer.concat_lines(program, {"a": "/x/a.mp4", "b": "/x/b.mp4"})
        lines = text.strip().splitlines()
        self.assertEqual(len(lines), 3)
        self.assertTrue(lines[0].endswith("a.mp4'"))
        self.assertTrue(lines[1].endswith("b.mp4'"))
        self.assertTrue(lines[2].endswith("a.mp4'"))


class AssembleTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.dir = Path(self.tmp.name)
        self.cid = ObjectId()
        self.segs = []
        specs = [
            ("opening", None, None, 70), ("product", "p1", "A", 120), ("product", "p1", "B", 120),
            ("interlude", None, None, 15), ("routine_recap", None, None, 60), ("closing", None, None, 25),
        ]
        for pos, (kind, pid, angle, dur) in enumerate(specs):
            path = self.dir / f"{kind}{pos}.mp4"
            path.write_bytes(b"x" * 100)
            self.segs.append({
                "_id": ObjectId(), "campaign_id": str(self.cid), "kind": kind, "product_id": pid,
                "angle": angle, "status": "READY", "position": pos, "video_path": str(path),
                "interlude_type": "cta" if kind == "interlude" else None, "variant": 0,
            })
        self.durations = {s["video_path"]: specs[i][3] for i, s in enumerate(self.segs)}
        # a cancelled segment must be ignored
        self.segs.append({"_id": ObjectId(), "campaign_id": str(self.cid), "kind": "faq",
                          "status": "CANCELLED", "position": 9, "video_path": ""})

    def make_db(self, **campaign):
        base = {"_id": self.cid, "status": "ASSEMBLING", "duration_minutes": 7,
                "show_format": "clip_library_v1",
                "show_plan": {"products": [{"product_id": "p1", "number": 1}]}}
        base.update(campaign)
        return FakeDb(ai_idol_campaigns=Collection([base]), ai_idol_segments=Collection(self.segs))

    def probe(self, path):
        return self.durations.get(path, 400.0)

    def run_assemble(self, db, free):
        ok = SimpleNamespace(returncode=0, stderr="")

        def fake_run(cmd, **kw):
            Path(cmd[-1]).write_bytes(b"m")
            return ok
        with patch.object(program_composer, "get_db", return_value=db), \
             patch.object(program_composer, "PROGRAM_DIR", self.dir), \
             patch.object(program_composer, "TEMP_DIR", self.dir), \
             patch.object(program_composer, "probe_duration", side_effect=self.probe), \
             patch.object(program_composer.shutil, "disk_usage", return_value=SimpleNamespace(free=free)), \
             patch.object(program_composer.subprocess, "run", side_effect=fake_run) as run:
            result = program_composer.assemble_campaign_program(str(self.cid))
        return result, run

    def test_assemble_library_saves_program(self):
        db = self.make_db()
        result, run = self.run_assemble(db, free=10**9)
        camp = db.ai_idol_campaigns.find_one({"_id": self.cid})
        self.assertEqual(camp["status"], "SCHEDULED")
        self.assertEqual(camp["master_video_path"], result)
        self.assertTrue(camp["program"])
        self.assertIsInstance(camp["program"][0], dict)
        self.assertEqual(set(camp["program"][0]), {"segment_id", "kind", "product_id", "angle", "start", "end"})
        self.assertEqual(camp["program"][0]["kind"], "opening")
        self.assertEqual(camp["program"][-1]["kind"], "closing")
        self.assertEqual(camp["duration_seconds"], 400.0)  # probed master length
        seg = db.ai_idol_segments.find_one({"_id": self.segs[1]["_id"]})
        self.assertEqual(seg["duration_seconds"], 120)
        self.assertTrue(run.called)

    def test_assemble_library_low_disk_needs_attention(self):
        db = self.make_db()
        result, run = self.run_assemble(db, free=10)
        camp = db.ai_idol_campaigns.find_one({"_id": self.cid})
        self.assertEqual(camp["status"], "NEEDS_ATTENTION")
        self.assertIn("Không đủ dung lượng ổ đĩa để ghép buổi live", camp["error_message"])
        self.assertIsNone(result)
        run.assert_not_called()

    def test_build_program_error_needs_attention(self):
        db = self.make_db(show_plan={"products": []})
        result, run = self.run_assemble(db, free=10**9)
        camp = db.ai_idol_campaigns.find_one({"_id": self.cid})
        self.assertEqual(camp["status"], "NEEDS_ATTENTION")
        self.assertTrue(camp["error_message"])
        run.assert_not_called()

    def test_assemble_legacy_unchanged(self):
        legacy = []
        for pos in range(2):
            p = self.dir / f"leg{pos}.mp4"
            p.write_bytes(b"x")
            legacy.append({"_id": ObjectId(), "campaign_id": str(self.cid), "status": "READY",
                           "position": pos, "video_path": str(p)})
        db = FakeDb(ai_idol_campaigns=Collection([{"_id": self.cid, "status": "ASSEMBLING"}]), ai_idol_segments=Collection(legacy))
        ok = SimpleNamespace(returncode=0, stderr="")

        def fake_run(cmd, **kw):
            Path(cmd[-1]).write_bytes(b"m")
            return ok
        with patch.object(program_composer, "get_db", return_value=db), \
             patch.object(program_composer, "PROGRAM_DIR", self.dir), \
             patch.object(program_composer, "TEMP_DIR", self.dir), \
             patch.object(program_composer, "probe_duration", return_value=33.0), \
             patch.object(program_composer.shutil, "disk_usage", side_effect=AssertionError("no disk check")), \
             patch.object(program_composer.subprocess, "run", side_effect=fake_run):
            program_composer.assemble_campaign_program(str(self.cid))
        camp = db.ai_idol_campaigns.find_one({"_id": self.cid})
        self.assertEqual(camp["status"], "SCHEDULED")
        self.assertEqual(camp["duration_seconds"], 33.0)
        self.assertNotIn("program", camp)


class BroadcastDurationTests(unittest.TestCase):
    def run_broadcast(self, campaign):
        cid = ObjectId()
        campaign = {"_id": cid, "master_video_path": "/m.mp4", "platforms": ["internal"], **campaign}
        db = FakeDb(ai_idol_campaigns=Collection([campaign]))
        with patch.object(broadcast_engine, "get_db", return_value=db), \
             patch.object(broadcast_engine, "preflight_campaign", return_value={"ok": True, "errors": []}), \
             patch.object(broadcast_engine, "_stream_target", return_value=("internal", True, "")) as stream:
            broadcast_engine.broadcast_campaign(str(cid))
        return stream.call_args.args[3], db.ai_idol_campaigns.find_one({"_id": cid})

    def test_broadcast_uses_master_duration_for_library(self):
        secs, camp = self.run_broadcast({"show_format": "clip_library_v1", "duration_minutes": 30,
                                         "duration_seconds": 1834.4})
        self.assertEqual(secs, 1834)
        self.assertEqual((camp["ends_at"] - camp["started_at"]).total_seconds(), 1834)

    def test_broadcast_legacy_uses_minutes(self):
        secs, _ = self.run_broadcast({"duration_minutes": 30, "duration_seconds": 1834.4})
        self.assertEqual(secs, 1800)


if __name__ == "__main__":
    unittest.main()
