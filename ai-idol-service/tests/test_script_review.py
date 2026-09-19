import os
import sys
import unittest
from unittest.mock import patch


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from core.pipeline import run_ai_idol_pipeline


class RecordingCollection:
    def __init__(self):
        self.updates = []

    def update_one(self, query, update, **kwargs):
        self.updates.append((query, update, kwargs))


class FakeDb:
    def __init__(self):
        self.ai_idol_jobs = RecordingCollection()
        self.ai_idol_segments = RecordingCollection()


class ScriptReviewTests(unittest.TestCase):
    def test_pipeline_stops_before_media_when_script_is_not_approved(self):
        job_id = "64b64b64b64b64b64b64b64b"
        segment_id = "65c65c65c65c65c65c65c65c"
        job = {
            "product_id": "995",
            "content_mode": "Product Intro",
            "configuration": {},
            "product_snapshot": {"name": "Vichy"},
            "segment_id": segment_id,
            "script_status": "PENDING_APPROVAL",
            "artifacts": {
                "knowledge": "verified product facts",
                "script_text": "Đây là kịch bản hợp lệ đang chờ nhân viên xem và chấp nhận.",
            },
        }
        fake_db = FakeDb()

        with patch("core.pipeline.get_job", return_value=job), \
             patch("core.pipeline.get_db", return_value=fake_db), \
             patch("core.pipeline.update_job_stage"):
            result = run_ai_idol_pipeline(job_id)

        self.assertFalse(result)
        job_values = fake_db.ai_idol_jobs.updates[-1][1]["$set"]
        segment_values = fake_db.ai_idol_segments.updates[-1][1]["$set"]
        self.assertEqual(job_values["status"], "AWAITING_APPROVAL")
        self.assertEqual(job_values["current_stage"], "AWAITING_SCRIPT_APPROVAL")
        self.assertEqual(segment_values["status"], "AWAITING_APPROVAL")


if __name__ == "__main__":
    unittest.main()
