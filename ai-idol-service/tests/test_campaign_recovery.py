"""Phase 2: queues, idempotent jobs, watchdog, campaign retry, rejected scripts."""
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

import core.campaigns as campaigns
import core.watchdog as watchdog
from celery_app import TASK_ROUTES, celery_app


def oid():
    return ObjectId()


NOW = dt.datetime(2026, 10, 13, 12, 0, tzinfo=dt.timezone.utc)


def ago(minutes):
    return NOW - dt.timedelta(minutes=minutes)


class QueueRoutingTests(unittest.TestCase):
    def test_routes_split_control_media_and_broadcast(self):
        routes = celery_app.conf.task_routes
        self.assertIs(routes, TASK_ROUTES)
        queue = lambda name: routes[name]["queue"]
        for name in ("process_agent_run", "prepare_campaign", "dispatch_due_campaigns",
                     "preflight_campaigns", "reap_stale_work"):
            self.assertEqual(queue(f"ai_idol.{name}"), "control", name)
        self.assertEqual(queue("ai_idol.generate_job"), "media")
        self.assertEqual(queue("ai_idol.assemble_campaign"), "media")
        self.assertEqual(queue("ai_idol.broadcast_campaign"), "broadcast")

    def test_every_registered_task_has_a_route_and_beat_entries_expire(self):
        import tasks  # noqa: F401  (registers the tasks)
        ours = {name for name in celery_app.tasks if name.startswith("ai_idol.")}
        self.assertLessEqual(ours, set(TASK_ROUTES))
        for name, entry in celery_app.conf.beat_schedule.items():
            self.assertIn("expires", entry["options"], name)
            self.assertIn(entry["task"], TASK_ROUTES)


class GenerateJobTaskTests(unittest.TestCase):
    def setUp(self):
        import tasks
        self.tasks = tasks
        self.job_id, self.campaign_id = oid(), oid()

    def db(self, job_status="QUEUED", campaign_status="GENERATING"):
        return FakeDb(
            ai_idol_jobs=Collection([{"_id": self.job_id, "status": job_status, "campaign_id": str(self.campaign_id),
                                      "current_stage": "RENDERING"}]),
            ai_idol_campaigns=Collection([{"_id": self.campaign_id, "status": campaign_status}]),
        )

    def run_task(self, db, *, retries=0, pipeline=None):
        tasks = self.tasks
        pipeline = pipeline or MagicMock(return_value=True)
        with patch.object(tasks, "get_db", return_value=db), \
                patch.object(tasks, "run_ai_idol_pipeline", pipeline), \
                patch.object(tasks, "fail_job") as fail, \
                patch.object(tasks, "update_campaign_from_job", return_value=str(self.campaign_id)) as update, \
                patch.object(tasks, "claim_campaign_for_assembly", return_value=True) as claim, \
                patch.object(tasks.assemble_campaign_task, "delay") as assemble:
            tasks.generate_job_task.push_request(retries=retries)
            try:
                result = tasks.generate_job_task.run(str(self.job_id))
            finally:
                tasks.generate_job_task.pop_request()
        return result, pipeline, fail, update, claim, assemble

    def test_ready_job_only_resyncs_campaign(self):
        result, pipeline, fail, update, claim, assemble = self.run_task(self.db("READY"))
        pipeline.assert_not_called()
        fail.assert_not_called()
        update.assert_called_once_with(str(self.job_id))
        claim.assert_called_once_with(str(self.campaign_id))
        assemble.assert_called_once_with(str(self.campaign_id))
        self.assertTrue(result)

    def test_cancelled_campaign_fails_job_without_running_pipeline(self):
        result, pipeline, fail, update, _, assemble = self.run_task(self.db(campaign_status="CANCELLED"))
        pipeline.assert_not_called()
        self.assertEqual(fail.call_args.args[:2], (str(self.job_id), "CANCELLED"))
        update.assert_not_called()
        assemble.assert_not_called()
        self.assertFalse(result)

    def test_exhausted_retries_fail_the_job_and_update_campaign(self):
        boom = MagicMock(side_effect=RuntimeError("syna down"))
        result, _, fail, update, _, _ = self.run_task(self.db(), retries=2, pipeline=boom)
        self.assertEqual(fail.call_args.args[:2], (str(self.job_id), "RENDERING"))
        self.assertIn("syna down", fail.call_args.args[2])
        update.assert_called_once_with(str(self.job_id))
        self.assertFalse(result)

    def test_earlier_failures_are_still_retried(self):
        boom = MagicMock(side_effect=RuntimeError("temporary"))
        with patch.object(self.tasks.generate_job_task, "retry", side_effect=KeyError("retry")):
            with self.assertRaises(KeyError):
                self.run_task(self.db(), retries=0, pipeline=boom)


class PrepareCampaignTests(unittest.TestCase):
    def setUp(self):
        self.campaign_id = oid()

    def build(self, segments, jobs=()):
        return FakeDb(
            ai_idol_campaigns=Collection([{"_id": self.campaign_id, "status": "VALIDATING", "content_mode": "Product Intro"}]),
            ai_idol_segments=Collection(segments),
            ai_idol_jobs=Collection(jobs),
        )

    def segment(self, position, product_id, job_id=None, status="QUEUED"):
        return {"_id": oid(), "campaign_id": str(self.campaign_id), "position": position,
                "product_id": product_id, "job_id": job_id, "status": status}

    def test_returns_only_jobs_that_are_not_ready(self):
        ready_job, pending_job = oid(), oid()
        db = self.build(
            [self.segment(0, "1", str(ready_job), "READY"), self.segment(1, "2", str(pending_job), "GENERATING")],
            [{"_id": ready_job, "status": "READY"}, {"_id": pending_job, "status": "IN_PROGRESS"}],
        )
        with patch.object(campaigns, "get_db", return_value=db), \
                patch.object(campaigns, "update_campaign_from_job") as update:
            result = campaigns.prepare_campaign(str(self.campaign_id))
        self.assertEqual(result, [str(pending_job)])
        update.assert_called_once_with(str(ready_job))
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "GENERATING")

    def test_all_segments_failing_records_a_reason(self):
        db = self.build([self.segment(0, "1"), self.segment(1, "2")])
        with patch.object(campaigns, "get_db", return_value=db), \
                patch.object(campaigns, "collect_product_and_snapshot", side_effect=ValueError("không tồn tại")):
            result = campaigns.prepare_campaign(str(self.campaign_id))
        campaign = db.ai_idol_campaigns.docs[0]
        self.assertEqual(result, [])
        self.assertEqual(campaign["status"], "NEEDS_ATTENTION")
        self.assertIn("không tồn tại", campaign["error_message"])
        self.assertEqual(campaign["progress"]["failed"], 2)
        self.assertTrue(all(item["status"] == "FAILED" for item in db.ai_idol_segments.docs))

    def test_task_assembles_when_every_job_was_already_ready(self):
        import tasks
        with patch.object(tasks, "prepare_campaign", return_value=[]), \
                patch.object(tasks, "resume_ready_campaign", return_value=True), \
                patch.object(tasks.assemble_campaign_task, "delay") as assemble, \
                patch.object(tasks.generate_job_task, "delay") as generate:
            tasks.prepare_campaign_task.run(str(self.campaign_id))
        generate.assert_not_called()
        assemble.assert_called_once_with(str(self.campaign_id))

    def test_late_job_callback_cannot_revive_a_cancelled_campaign(self):
        job_id, segment = oid(), self.segment(0, "1", "x", "GENERATING")
        segment["job_id"] = str(job_id)
        db = self.build([segment], [{"_id": job_id, "status": "FAILED", "campaign_id": str(self.campaign_id)}])
        db.ai_idol_campaigns.docs[0]["status"] = "CANCELLED"
        with patch.object(campaigns, "get_db", return_value=db):
            campaigns.update_campaign_from_job(str(job_id))
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "CANCELLED")


class WatchdogTests(unittest.TestCase):
    def test_fails_only_stale_in_progress_jobs_with_conditional_updates(self):
        campaign_id = oid()
        stale, avatar, fresh, waiting = oid(), oid(), oid(), oid()
        jobs = Collection([
            {"_id": stale, "status": "IN_PROGRESS", "current_stage": "RENDERING", "updated_at": ago(50), "campaign_id": str(campaign_id)},
            {"_id": avatar, "status": "IN_PROGRESS", "current_stage": "GENERATING_AVATAR", "updated_at": ago(50)},
            {"_id": fresh, "status": "IN_PROGRESS", "current_stage": "RENDERING", "updated_at": ago(5)},
            {"_id": waiting, "status": "AWAITING_APPROVAL", "current_stage": "AWAITING_SCRIPT_APPROVAL", "updated_at": ago(900)},
        ])
        db = FakeDb(ai_idol_jobs=jobs)
        with patch.object(watchdog, "get_db", return_value=db), \
                patch.object(watchdog, "update_campaign_from_job") as update:
            reaped = watchdog.reap_stale_work(NOW)
        status = {doc["_id"]: doc["status"] for doc in jobs.docs}
        self.assertEqual(status[stale], "FAILED")
        self.assertEqual(status[avatar], "IN_PROGRESS")  # the avatar service may run for hours
        self.assertEqual(status[fresh], "IN_PROGRESS")
        self.assertEqual(status[waiting], "AWAITING_APPROVAL")
        self.assertEqual(reaped["jobs"], 1)
        update.assert_called_once_with(str(stale))
        for kind, query, _ in jobs.writes:
            self.assertEqual(query["status"], "IN_PROGRESS")  # never an unconditional write

    def test_job_that_finishes_during_the_sweep_is_left_alone(self):
        job = oid()
        jobs = Collection([{"_id": job, "status": "IN_PROGRESS", "current_stage": "RENDERING", "updated_at": ago(60)}])
        original_find = jobs.find

        def racing_find(query=None, projection=None):
            found = original_find(query)
            jobs.docs[0]["status"] = "READY"  # worker completes right after the scan
            return found

        jobs.find = racing_find
        with patch.object(watchdog, "get_db", return_value=FakeDb(ai_idol_jobs=jobs)), \
                patch.object(watchdog, "update_campaign_from_job"):
            reaped = watchdog.reap_stale_work(NOW)
        self.assertEqual(reaped["jobs"], 0)
        self.assertEqual(jobs.docs[0]["status"], "READY")

    def test_stuck_starting_and_assembling_campaigns_need_attention(self):
        a, b, c, d = oid(), oid(), oid(), oid()
        campaigns_col = Collection([
            {"_id": a, "status": "STARTING", "updated_at": ago(10)},
            {"_id": b, "status": "STARTING", "updated_at": ago(1)},
            {"_id": c, "status": "ASSEMBLING", "updated_at": ago(61)},
            {"_id": d, "status": "LIVE", "updated_at": ago(5000)},
        ])
        with patch.object(watchdog, "get_db", return_value=FakeDb(ai_idol_campaigns=campaigns_col)):
            reaped = watchdog.reap_stale_work(NOW)
        status = {doc["_id"]: doc["status"] for doc in campaigns_col.docs}
        self.assertEqual((status[a], status[b], status[c], status[d]),
                         ("NEEDS_ATTENTION", "STARTING", "NEEDS_ATTENTION", "LIVE"))
        self.assertEqual((reaped["starting"], reaped["assembling"]), (1, 1))
        for _, query, _update in campaigns_col.writes:
            self.assertIn(query["status"], {"STARTING", "ASSEMBLING"})


class RetryCampaignTests(unittest.TestCase):
    def setUp(self):
        self.campaign_id = oid()
        self.enqueue = dict(enqueue_job=MagicMock(), enqueue_prepare=MagicMock(), enqueue_assemble=MagicMock())

    def build(self, segments, jobs=(), **campaign):
        doc = {"_id": self.campaign_id, "status": "NEEDS_ATTENTION", **campaign}
        return FakeDb(
            ai_idol_campaigns=Collection([doc]),
            ai_idol_segments=Collection([{"_id": oid(), "campaign_id": str(self.campaign_id), "position": i, **seg}
                                         for i, seg in enumerate(segments)]),
            ai_idol_jobs=Collection(jobs),
        )

    def retry(self, db):
        with patch.object(campaigns, "get_db", return_value=db):
            return campaigns.retry_campaign(str(self.campaign_id), **self.enqueue)

    def test_failed_segment_with_job_resets_and_queues_the_job(self):
        job = oid()
        db = self.build([{"status": "FAILED", "job_id": str(job)}, {"status": "READY"}],
                        [{"_id": job, "status": "FAILED", "retry_count": 1, "error_message": "x"}])
        result = self.retry(db)
        self.assertEqual(result, {"queued_segments": 1, "assembly_queued": False, "prepare_queued": False})
        self.enqueue["enqueue_job"].assert_called_once_with(str(job))
        self.assertEqual(db.ai_idol_jobs.docs[0]["status"], "QUEUED")
        self.assertEqual(db.ai_idol_jobs.docs[0]["retry_count"], 2)
        self.assertEqual(db.ai_idol_segments.docs[0]["status"], "GENERATING")
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "GENERATING")

    def test_failed_segment_without_job_queues_preparation(self):
        db = self.build([{"status": "FAILED", "job_id": None}])
        result = self.retry(db)
        self.assertEqual(result, {"queued_segments": 0, "assembly_queued": False, "prepare_queued": True})
        self.enqueue["enqueue_prepare"].assert_called_once_with(str(self.campaign_id))
        self.enqueue["enqueue_job"].assert_not_called()

    def test_all_ready_without_master_requeues_assembly(self):
        db = self.build([{"status": "READY"}, {"status": "READY"}], master_video_path="/nope/missing.mp4",
                        error_message="ffmpeg failed")
        result = self.retry(db)
        self.assertEqual(result, {"queued_segments": 0, "assembly_queued": True, "prepare_queued": False})
        self.enqueue["enqueue_assemble"].assert_called_once_with(str(self.campaign_id))
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "ASSEMBLING")
        self.assertIsNone(db.ai_idol_campaigns.docs[0]["error_message"])

    def test_preflight_only_problem_is_a_conflict_with_instructions(self):
        db = self.build([{"status": "READY"}], master_video_path="/tmp/master.mp4",
                        preflight={"ok": False, "errors": ["Missing RTMP configuration for youtube"]})
        with patch.object(campaigns.os.path, "isfile", return_value=True), \
                self.assertRaises(campaigns.CampaignConflict) as caught:
            self.retry(db)
        self.assertIn("Missing RTMP configuration for youtube", str(caught.exception))
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "NEEDS_ATTENTION")
        for mock in self.enqueue.values():
            mock.assert_not_called()

    def test_unknown_campaign_and_wrong_state(self):
        with self.assertRaises(campaigns.CampaignNotFound):
            self.retry(FakeDb())
        with self.assertRaises(campaigns.CampaignConflict):
            self.retry(self.build([{"status": "READY"}], status="LIVE"))

    def test_resume_ready_campaign_claims_only_when_everything_is_ready(self):
        db = self.build([{"status": "READY"}, {"status": "GENERATING"}], status="GENERATING")
        with patch.object(campaigns, "get_db", return_value=db):
            self.assertFalse(campaigns.resume_ready_campaign(str(self.campaign_id)))
            db.ai_idol_segments.docs[1]["status"] = "READY"
            self.assertTrue(campaigns.resume_ready_campaign(str(self.campaign_id)))
            self.assertFalse(campaigns.resume_ready_campaign(str(self.campaign_id)))  # already claimed


class RejectedScriptTests(unittest.TestCase):
    def test_validator_rejection_keeps_draft_without_marking_it_usable(self):
        import types
        job_id = str(oid())
        job = {"product_id": "93", "content_mode": "Product Intro", "configuration": {}, "artifacts": {"knowledge": "facts"},
               "product_snapshot": {"name": "Kem"}}
        stubs = {
            "engines.knowledge_retriever": types.SimpleNamespace(retrieve_knowledge=MagicMock()),
            "engines.content_agent": types.SimpleNamespace(generate_content_and_script=MagicMock(return_value="bản nháp")),
            "engines.script_validator": types.SimpleNamespace(
                validate_and_revise_script=MagicMock(return_value=(False, "cam kết trị khỏi hoàn toàn", "Vi phạm từ khóa cấm"))),
        }
        import core.pipeline as pipeline
        with patch.dict(sys.modules, stubs), \
                patch.object(pipeline, "get_job", return_value=job), \
                patch.object(pipeline, "get_db", return_value=FakeDb()), \
                patch.object(pipeline, "update_job_stage"), \
                patch.object(pipeline, "checkpoint_job") as checkpoint, \
                patch.object(pipeline, "fail_job") as fail:
            self.assertFalse(pipeline.run_ai_idol_pipeline(job_id))
        saved = checkpoint.call_args.args[1]
        self.assertEqual(saved["artifacts.rejected_script_text"], "cam kết trị khỏi hoàn toàn")
        self.assertEqual(saved["artifacts.validation_feedback"], "Vi phạm từ khóa cấm")
        self.assertEqual(saved["script_status"], "REJECTED_BY_VALIDATOR")
        self.assertNotIn("artifacts.script_text", saved)
        fail.assert_called_once()

    def test_get_campaign_exposes_rejected_draft_and_feedback(self):
        campaign_id, job_id = oid(), oid()
        db = FakeDb(
            ai_idol_campaigns=Collection([{"_id": campaign_id, "status": "NEEDS_ATTENTION"}]),
            ai_idol_segments=Collection([{"_id": oid(), "campaign_id": str(campaign_id), "position": 0,
                                          "job_id": str(job_id), "status": "FAILED"}]),
            ai_idol_jobs=Collection([{"_id": job_id, "status": "FAILED", "script_status": "REJECTED_BY_VALIDATOR",
                                      "artifacts": {"rejected_script_text": "nháp", "validation_feedback": "lý do"}}]),
        )
        with patch.object(campaigns, "get_db", return_value=db):
            data = campaigns.get_campaign(str(campaign_id))
        segment = data["segments"][0]
        self.assertEqual(segment["script_status"], "REJECTED_BY_VALIDATOR")
        self.assertEqual(segment["rejected_script_text"], "nháp")
        self.assertEqual(segment["validation_feedback"], "lý do")
        self.assertEqual(segment["script_text"], "")


class ApproveBlacklistTests(unittest.TestCase):
    def client(self):
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        return service_app, service_app.app.test_client()

    def test_approve_rejects_forbidden_claims_with_422_and_writes_nothing(self):
        service_app, client = self.client()
        job_id = oid()
        db = FakeDb(ai_idol_jobs=Collection([{"_id": job_id, "status": "FAILED", "product_id": "93", "artifacts": {}}]))
        script = "Sản phẩm này cam kết trị khỏi hoàn toàn nám chỉ sau một đêm sử dụng, hãy mua ngay hôm nay."
        with patch.object(service_app, "get_db", return_value=db), \
                patch.object(service_app.generate_job_task, "delay") as delay:
            response = client.post(f"/api/ai-idol/jobs/{job_id}/script/approve", json={"script_text": script})
        self.assertEqual(response.status_code, 422)
        self.assertFalse(response.get_json()["ok"])
        self.assertEqual(db.ai_idol_jobs.docs[0]["status"], "FAILED")
        self.assertEqual(db.ai_idol_scripts.docs, [])
        delay.assert_not_called()

    def test_retry_endpoint_maps_conflict_and_not_found(self):
        service_app, client = self.client()
        with patch.object(service_app, "retry_campaign", side_effect=campaigns.CampaignConflict("đã ghép xong")):
            self.assertEqual(client.post("/api/ai-idol/campaigns/abc/retry").status_code, 409)
        with patch.object(service_app, "retry_campaign", side_effect=campaigns.CampaignNotFound("nope")):
            self.assertEqual(client.post("/api/ai-idol/campaigns/abc/retry").status_code, 404)
        with patch.object(service_app, "retry_campaign",
                          return_value={"queued_segments": 2, "assembly_queued": False, "prepare_queued": False}):
            body = client.post("/api/ai-idol/campaigns/abc/retry").get_json()
        self.assertEqual((body["ok"], body["queued"]), (True, 2))


if __name__ == "__main__":
    unittest.main()
