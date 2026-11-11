"""Jobs left in CREATED (worker could not start them) inside a GENERATING campaign are re-queued, then failed."""
import datetime as dt
import os
import sys
import unittest
from unittest.mock import patch

from bson import ObjectId

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import Collection, FakeDb

import core.watchdog as watchdog
from config import STUCK_CREATED_MINUTES

NOW = dt.datetime(2026, 11, 11, 12, 0, tzinfo=dt.timezone.utc)


def ago(minutes):
    return NOW - dt.timedelta(minutes=minutes)


class StuckCreatedTests(unittest.TestCase):
    def setUp(self):
        self.campaign_id, self.other_id = ObjectId(), ObjectId()
        self.queued = []

    def run_sweep(self, jobs):
        campaigns = Collection([{"_id": self.campaign_id, "status": "GENERATING"},
                                {"_id": self.other_id, "status": "NEEDS_ATTENTION"}])
        self.jobs = Collection(jobs)
        db = FakeDb(ai_idol_jobs=self.jobs, ai_idol_campaigns=campaigns)
        with patch.object(watchdog, "get_db", return_value=db), \
                patch.object(watchdog, "update_campaign_from_job") as update:
            self.update = update
            return watchdog.requeue_stuck_created_jobs(self.queued.append, NOW)

    def job(self, minutes, campaign=None, **extra):
        return {"_id": ObjectId(), "status": "CREATED", "updated_at": ago(minutes),
                "campaign_id": str(campaign or self.campaign_id), **extra}

    def test_default_is_ten_minutes(self):
        self.assertEqual(STUCK_CREATED_MINUTES, 10)

    def test_stuck_job_is_requeued_and_touched(self):
        job = self.job(15)
        result = self.run_sweep([job])
        self.assertEqual(self.queued, [str(job["_id"])])
        self.assertEqual(result["requeued"], 1)
        stored = self.jobs.docs[0]
        self.assertEqual(stored["updated_at"], NOW)
        self.assertEqual(stored["requeue_count"], 1)
        self.assertEqual(stored["status"], "CREATED")

    def test_just_requeued_job_is_not_requeued_again_at_once(self):
        job = self.job(15)
        self.run_sweep([job])
        self.queued.clear()
        db = FakeDb(ai_idol_jobs=self.jobs,
                    ai_idol_campaigns=Collection([{"_id": self.campaign_id, "status": "GENERATING"}]))
        with patch.object(watchdog, "get_db", return_value=db):
            watchdog.requeue_stuck_created_jobs(self.queued.append, NOW + dt.timedelta(minutes=2))
        self.assertEqual(self.queued, [])

    def test_fresh_created_job_is_left_alone(self):
        result = self.run_sweep([self.job(3)])
        self.assertEqual(self.queued, [])
        self.assertEqual(result["requeued"], 0)

    def test_job_of_non_generating_campaign_is_left_alone(self):
        result = self.run_sweep([self.job(60, campaign=self.other_id)])
        self.assertEqual(self.queued, [])
        self.assertEqual(result["failed"], 0)
        self.assertEqual(self.jobs.docs[0]["status"], "CREATED")

    def test_job_without_campaign_is_left_alone(self):
        job = self.job(60)
        job.pop("campaign_id")
        self.run_sweep([job])
        self.assertEqual(self.queued, [])

    def test_job_that_moved_on_is_left_alone(self):
        job = self.job(60)
        job["status"] = "QUEUED"
        self.run_sweep([job])
        self.assertEqual(self.queued, [])

    def test_after_three_requeues_job_is_failed(self):
        job = self.job(15, requeue_count=3)
        result = self.run_sweep([job])
        self.assertEqual(self.queued, [])
        self.assertEqual(result["failed"], 1)
        stored = self.jobs.docs[0]
        self.assertEqual(stored["status"], "FAILED")
        self.assertEqual(stored["error_message"], "Không khởi động được job sau 3 lần thử lại")
        self.update.assert_called_once_with(str(job["_id"]))

    def test_enqueue_failure_does_not_stop_the_sweep(self):
        jobs = [self.job(15), self.job(16)]
        calls = []

        def flaky(job_id):
            calls.append(job_id)
            if len(calls) == 1:
                raise RuntimeError("broker down")

        campaigns = Collection([{"_id": self.campaign_id, "status": "GENERATING"}])
        db = FakeDb(ai_idol_jobs=Collection(jobs), ai_idol_campaigns=campaigns)
        with patch.object(watchdog, "get_db", return_value=db):
            watchdog.requeue_stuck_created_jobs(flaky, NOW)
        self.assertEqual(len(calls), 2)


class BeatWiringTests(unittest.TestCase):
    def test_reap_task_requeues_through_generate_job_task(self):
        import tasks
        with patch.object(tasks, "reap_stale_work", return_value={"jobs": 0}),                 patch.object(tasks, "requeue_stuck_created_jobs", return_value={"requeued": 1, "failed": 0}) as sweep,                 patch.object(tasks.generate_job_task, "delay") as delay:
            result = tasks.reap_stale_work_task()
            sweep.call_args.args[0]("job-1")
        delay.assert_called_once_with("job-1")
        self.assertEqual(result["created"], {"requeued": 1, "failed": 0})


if __name__ == "__main__":
    unittest.main()
