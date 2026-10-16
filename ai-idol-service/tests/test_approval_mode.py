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
from tests.fakes import FakeDb

JOB_ID = "64b64b64b64b64b64b64b64b"
GOOD = types.SimpleNamespace(passed=True, script="[Hook]\nXin chào.", feedback="", repair_attempts=0,
                             history=[{"version": 1, "text": "[Hook]\nXin chào.", "passed": True, "issues": []}])


class ShouldAutoApproveTests(unittest.TestCase):
    def test_modes(self):
        self.assertTrue(pipeline.should_auto_approve_script({"approval_mode": "AUTO"}))
        self.assertFalse(pipeline.should_auto_approve_script({"approval_mode": "MANUAL", "agent_managed": True,
                                                              "script_approval_mode": "auto",
                                                              "agent_autonomy_policy": "auto_publish"}))
        self.assertFalse(pipeline.should_auto_approve_script({"script_approval_mode": "auto"}))


class CreateCampaignModeTests(unittest.TestCase):
    def create(self, configuration):
        import core.campaigns as campaigns
        db = FakeDb()
        with patch.object(campaigns, "get_db", return_value=db):
            campaigns.create_campaign({"product_ids": ["1"], "purpose": "product_video",
                                       "configuration": configuration})
        return db.ai_idol_campaigns.docs[0]["configuration"]

    def test_bad_enum_raises(self):
        with self.assertRaises(ValueError):
            self.create({"approval_mode": "MAYBE"})

    def test_mapping_and_absent(self):
        self.assertEqual(self.create({"approval_mode": "AUTO"})["script_approval_mode"], "auto")
        self.assertEqual(self.create({"approval_mode": "manual"})["script_approval_mode"], "human")
        self.assertNotIn("approval_mode", self.create({}))


class PipelineModeTests(unittest.TestCase):
    def run_pipeline(self, purpose, config):
        db = FakeDb()
        db.ai_idol_jobs.docs.append({"_id": ObjectId(JOB_ID), "status": "QUEUED"})
        job = {"product_id": "1", "content_mode": "Product Intro", "purpose": purpose, "configuration": config,
               "artifacts": {"knowledge": "k"}, "product_snapshot": {"name": "Kem"}}
        voice = MagicMock(side_effect=RuntimeError("stop here"))
        stubs = {
            "engines.knowledge_retriever": types.SimpleNamespace(retrieve_knowledge=MagicMock()),
            "engines.content_agent": types.SimpleNamespace(generate_content_and_script=MagicMock(return_value="d")),
            "engines.script_validator": types.SimpleNamespace(validate_and_repair_script=MagicMock(return_value=GOOD)),
            "engines.voice_engine": types.SimpleNamespace(generate_voice_audio=voice),
        }
        with patch.dict(sys.modules, stubs), \
                patch.object(pipeline, "get_job", return_value=job), \
                patch.object(pipeline, "get_db", return_value=db), \
                patch.object(pipeline, "update_job_stage"), \
                patch.object(pipeline, "checkpoint_job"), \
                patch.object(pipeline, "fail_job"):
            pipeline.run_ai_idol_pipeline(JOB_ID)
        return db, voice

    def statuses(self, db):
        return [w[2]["$set"].get("status") for w in db.ai_idol_jobs.writes if "$set" in w[2]]

    def test_auto_never_awaits(self):
        db, voice = self.run_pipeline("live_only", {"approval_mode": "AUTO"})
        voice.assert_called_once()
        self.assertNotIn("AWAITING_APPROVAL", self.statuses(db))

    def test_manual_stops_after_pass_even_for_product_video(self):
        db, voice = self.run_pipeline("product_video", {"approval_mode": "MANUAL"})
        voice.assert_not_called()
        self.assertIn("AWAITING_APPROVAL", self.statuses(db))

    def test_agent_written_manual_config_stops_product_video(self):
        cfg = {"agent_managed": True, "agent_autonomy_policy": "auto_preview",
               "approval_mode": "MANUAL", "script_approval_mode": "human"}
        db, voice = self.run_pipeline("product_video", cfg)
        voice.assert_not_called()
        self.assertIn("AWAITING_APPROVAL", self.statuses(db))

    def test_agent_written_auto_config_continues_product_video(self):
        cfg = {"agent_managed": True, "agent_autonomy_policy": "auto_preview",
               "approval_mode": "AUTO", "script_approval_mode": "auto"}
        db, voice = self.run_pipeline("product_video", cfg)
        voice.assert_called_once()
        self.assertNotIn("AWAITING_APPROVAL", self.statuses(db))

    def test_absent_mode_keeps_old_behaviour(self):
        _, voice = self.run_pipeline("product_video", {})
        voice.assert_called_once()
        db, voice = self.run_pipeline("live_only", {})
        voice.assert_not_called()
        self.assertIn("AWAITING_APPROVAL", self.statuses(db))


if __name__ == "__main__":
    unittest.main()
