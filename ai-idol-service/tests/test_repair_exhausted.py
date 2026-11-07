import datetime as dt
import os
import sys
import types
import unittest
from unittest.mock import MagicMock, patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from bson import ObjectId

import core.pipeline as pipeline
from core import watchdog
from tests.fakes import FakeDb

JOB_ID = "64b64b64b64b64b64b64b64b"
ISSUE = {"code": "BLACKLIST_CLAIM", "reason": "bad"}
BAD = types.SimpleNamespace(
    passed=False, script="trị khỏi hoàn toàn", feedback="bad", repair_attempts=3,
    history=[{"version": v, "text": f"t{v}", "passed": False, "issues": [ISSUE],
              **({"repair": {"to_version": v + 1, "issue_codes": ["BLACKLIST_CLAIM"]}} if v < 4 else {})}
             for v in range(1, 5)])


class RepairExhaustedTests(unittest.TestCase):
    def test_exhausted_parks_job_without_media(self):
        job = {"product_id": "1", "content_mode": "Product Intro", "purpose": "product_video",
               "configuration": {"approval_mode": "AUTO"}, "artifacts": {"knowledge": "k"},
               "product_snapshot": {"name": "Kem"}}
        voice = MagicMock()
        stubs = {
            "engines.knowledge_retriever": types.SimpleNamespace(retrieve_knowledge=MagicMock()),
            "engines.content_agent": types.SimpleNamespace(generate_content_and_script=MagicMock(return_value="d")),
            "engines.script_validator": types.SimpleNamespace(validate_and_repair_script=MagicMock(return_value=BAD)),
            "engines.voice_engine": types.SimpleNamespace(generate_voice_audio=voice),
        }
        with patch.dict(sys.modules, stubs), \
                patch.object(pipeline, "get_job", return_value=job), \
                patch.object(pipeline, "get_db", return_value=FakeDb()), \
                patch.object(pipeline, "update_job_stage"), \
                patch.object(pipeline, "checkpoint_job") as checkpoint, \
                patch.object(pipeline, "fail_job") as fail:
            self.assertFalse(pipeline.run_ai_idol_pipeline(JOB_ID))
        voice.assert_not_called()
        fail.assert_not_called()
        saved = checkpoint.call_args.args[1]
        self.assertEqual(saved["status"], "AUTO_REPAIR_EXHAUSTED")
        self.assertEqual(saved["current_stage"], "AUTO_REPAIR_EXHAUSTED")
        self.assertEqual(saved["artifacts.repair_attempt"], 3)
        self.assertEqual(len(saved["artifacts.script_versions"]), 4)
        self.assertEqual(len(saved["artifacts.validation_history"]), 4)
        self.assertEqual(saved["artifacts.repair_history"][0],
                         {"from_version": 1, "to_version": 2, "issue_codes": ["BLACKLIST_CLAIM"]})
        self.assertNotIn("artifacts.script_text", saved)

    def test_watchdog_ignores_exhausted_job(self):
        now = dt.datetime(2026, 10, 13, 12, 0, tzinfo=dt.timezone.utc)
        db = FakeDb()
        db.ai_idol_jobs.docs.append({"_id": ObjectId(JOB_ID), "status": "IN_PROGRESS",
                                     "current_stage": "AUTO_REPAIR_EXHAUSTED",
                                     "updated_at": now - dt.timedelta(hours=5)})
        with patch.object(watchdog, "get_db", return_value=db):
            self.assertEqual(watchdog.reap_stale_work(now)["jobs"], 0)
        self.assertEqual(db.ai_idol_jobs.docs[0]["status"], "IN_PROGRESS")


class FinalCheckTests(unittest.TestCase):
    def test_missing_video_fails(self):
        problems = pipeline.final_machine_check(FakeDb(), JOB_ID, "1", {"id": "1"}, None, "/nope.mp4", 10, "live_only")
        self.assertTrue(any("không tồn tại" in p for p in problems))

    def test_complete_job_fails_with_final_check(self):
        db = FakeDb()
        with patch("engines.video_composer.get_audio_duration", return_value=0), \
                patch("core.pipeline.os.path.isfile", return_value=True), \
                patch.object(pipeline, "fail_job") as fail:
            self.assertFalse(pipeline._complete_video_job(JOB_ID, "1", {}, "/x.mp4", "/a.wav", db, snapshot={"id": "1"}))
        self.assertEqual(fail.call_args.args[1], "FINAL_CHECK")
        self.assertIsNone(db.ai_idol_videos.find_one({}))


if __name__ == "__main__":
    unittest.main()
