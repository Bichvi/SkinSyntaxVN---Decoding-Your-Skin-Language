"""generate_job_task takes a run lease so a duplicate delivery never runs the pipeline twice."""
import datetime as dt
import os
import sys
import unittest
from unittest.mock import MagicMock, patch

from bson import ObjectId

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import Collection, FakeDb

import tasks
from config import RUN_LEASE_MINUTES


def utc_now():
    return dt.datetime.now(dt.timezone.utc)


class LeaseTests(unittest.TestCase):
    def setUp(self):
        self.job_id, self.campaign_id = ObjectId(), ObjectId()

    def run_task(self, job_fields=None, *, pipeline=None, retries=0):
        job = {"_id": self.job_id, "status": "QUEUED", "campaign_id": str(self.campaign_id),
               "current_stage": "QUEUED", **(job_fields or {})}
        self.db = FakeDb(ai_idol_jobs=Collection([job]),
                         ai_idol_campaigns=Collection([{"_id": self.campaign_id, "status": "GENERATING"}]))
        self.pipeline = pipeline or MagicMock(return_value=True)
        with patch.object(tasks, "get_db", return_value=self.db), \
                patch.object(tasks, "run_ai_idol_pipeline", self.pipeline), \
                patch.object(tasks, "fail_job"), \
                patch.object(tasks, "update_campaign_from_job", return_value=None), \
                patch.object(tasks, "claim_campaign_for_assembly", return_value=False):
            tasks.generate_job_task.push_request(retries=retries)
            try:
                return tasks.generate_job_task.run(str(self.job_id))
            finally:
                tasks.generate_job_task.pop_request()

    def stored(self):
        return self.db.ai_idol_jobs.docs[0]

    def test_default_lease_is_90_minutes(self):
        self.assertEqual(RUN_LEASE_MINUTES, 90)

    def test_duplicate_delivery_with_fresh_lease_does_not_run(self):
        result = self.run_task({"run_lease_until": utc_now() + dt.timedelta(minutes=30)})
        self.pipeline.assert_not_called()
        self.assertFalse(result)

    def test_expired_lease_runs(self):
        result = self.run_task({"run_lease_until": utc_now() - dt.timedelta(minutes=1)})
        self.pipeline.assert_called_once_with(str(self.job_id))
        self.assertTrue(result)

    def test_ready_job_is_skipped(self):
        self.run_task({"status": "READY"})
        self.pipeline.assert_not_called()

    def test_lease_is_taken_during_run_and_cleared_after(self):
        seen = {}

        def pipeline(job_id):
            seen["lease"] = self.db.ai_idol_jobs.docs[0].get("run_lease_until")
            return True

        self.run_task(pipeline=pipeline)
        self.assertGreater(seen["lease"], utc_now() + dt.timedelta(minutes=RUN_LEASE_MINUTES - 2))
        self.assertIsNone(self.stored().get("run_lease_until"))

    def test_lease_is_cleared_after_an_exception(self):
        boom = MagicMock(side_effect=RuntimeError("syna down"))
        self.run_task(pipeline=boom, retries=2)  # out of retries: records the failure
        self.assertIsNone(self.stored().get("run_lease_until"))

    def test_lease_is_released_before_self_retry(self):
        boom = MagicMock(side_effect=RuntimeError("temporary"))
        leases = []

        def fake_retry(*args, **kwargs):
            leases.append(self.db.ai_idol_jobs.docs[0].get("run_lease_until"))
            raise KeyError("retry")

        with patch.object(tasks.generate_job_task, "retry", side_effect=fake_retry):
            with self.assertRaises(KeyError):
                self.run_task(pipeline=boom, retries=0)
        self.assertEqual(leases, [None])

    def test_second_delivery_after_release_can_claim(self):
        self.run_task()
        self.pipeline.reset_mock()
        with patch.object(tasks, "get_db", return_value=self.db), \
                patch.object(tasks, "run_ai_idol_pipeline", self.pipeline), \
                patch.object(tasks, "update_campaign_from_job", return_value=None), \
                patch.object(tasks, "claim_campaign_for_assembly", return_value=False):
            self.db.ai_idol_jobs.docs[0]["status"] = "WAITING_RENDERER"
            tasks.generate_job_task.push_request(retries=0)
            try:
                tasks.generate_job_task.run(str(self.job_id))
            finally:
                tasks.generate_job_task.pop_request()
        self.pipeline.assert_called_once()


if __name__ == "__main__":
    unittest.main()
