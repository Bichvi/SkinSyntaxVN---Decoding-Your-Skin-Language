"""Phase 3: health, waiting renderer, cancel/reschedule, internal broadcast, redaction, agent timeline."""
import datetime as dt
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

import requests
from bson import ObjectId

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import Collection, FakeDb

import core.agent_runs as agent_runs
import core.campaigns as campaigns
import core.health as health
import core.watchdog as watchdog
from core.redact import redact_secrets
from engines import broadcast_engine, syna_engine

NOW = dt.datetime(2026, 10, 13, 12, 0, tzinfo=dt.timezone.utc)
SECRET_URL = "rtmp://a.rtmp.youtube.com/live2/SECRETKEY-1234"


def oid():
    return ObjectId()


class DependencyHealthTests(unittest.TestCase):
    def setUp(self):
        health._cache.update(at=0.0, value=None)

    def test_probes_run_once_per_cache_window_and_failures_degrade(self):
        calls = {"a": 0}

        def ok():
            calls["a"] += 1
            return {"ok": True, "status": "ok", "detail": ""}

        def broken():
            raise RuntimeError("boom with rtmp://x/SECRET-KEY-999")

        with patch.object(health, "CHECKS", {"mongo": ok, "syna": broken}):
            first = health.dependency_status()
            second = health.dependency_status()
            forced = health.dependency_status(force=True)
        self.assertIs(first, second)
        self.assertEqual(calls["a"], 2)  # cached once, then forced
        self.assertEqual(first["overall"], "degraded")
        self.assertFalse(first["services"]["syna"]["ok"])
        self.assertNotIn("SECRET", json.dumps(forced))  # a crashing probe never leaks its message

    def test_down_mongo_makes_overall_down(self):
        down = lambda: {"ok": False, "status": "down", "detail": ""}
        with patch.object(health, "CHECKS", {"mongo": down}):
            self.assertEqual(health.dependency_status(force=True)["overall"], "down")

    def test_rtmp_reports_platform_names_only(self):
        with patch.object(health, "RTMP_TARGETS", {"youtube": SECRET_URL, "facebook": "rtmps://x/FBSECRET-5678"}):
            result = health.check_rtmp()
        self.assertEqual(result["platforms"], ["facebook", "youtube"])
        self.assertNotIn("SECRET", json.dumps(result))
        self.assertNotIn("://", json.dumps(result))

    def test_syna_requires_matching_renderer_version(self):
        def answer(renderer):
            response = MagicMock()
            response.json.return_value = {"renderer": renderer}
            return response

        with patch.object(health.requests, "get", return_value=answer(syna_engine.RENDER_VERSION)):
            self.assertTrue(health.check_syna()["ok"])
        with patch.object(health.requests, "get", return_value=answer("syna-old")):
            self.assertFalse(health.check_syna()["ok"])
        with patch.object(health.requests, "get", side_effect=requests.ConnectionError("refused")):
            result = health.check_syna()
        self.assertFalse(result["ok"])
        self.assertIn("start-ai-idol-syna", result["detail"])

    def test_avatar_not_required_when_provider_is_mock(self):
        with patch.object(health, "AVATAR_PROVIDER", "mock"):
            self.assertEqual(health.check_avatar()["status"], "not_required")

    def test_endpoint_returns_cached_status(self):
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        with patch.object(service_app, "dependency_status", return_value={"overall": "ok", "services": {}}):
            body = service_app.app.test_client().get("/api/ai-idol/health/dependencies").get_json()
        self.assertTrue(body["ok"])
        self.assertEqual(body["data"]["overall"], "ok")


class RendererUnavailableTests(unittest.TestCase):
    def render(self, requests_get):
        with tempfile.TemporaryDirectory() as folder:
            audio = Path(folder) / "a.wav"
            audio.write_bytes(b"0" * 2048)
            Path(str(audio) + ".timings.json").write_text("[]", encoding="utf-8")
            with patch.object(syna_engine, "probe_duration", return_value=5.0), \
                    patch.object(syna_engine, "build_manifest", return_value={"v": 1}), \
                    patch.object(syna_engine, "OUTPUT_DIR", folder), \
                    patch.object(syna_engine.requests, "get", requests_get):
                return syna_engine.render_syna_video("job1", str(audio), "x", {}, {})

    def test_connection_failure_is_renderer_unavailable(self):
        with self.assertRaises(syna_engine.RendererUnavailable):
            self.render(MagicMock(side_effect=requests.ConnectionError("refused")))

    def test_version_mismatch_is_renderer_unavailable(self):
        response = MagicMock()
        response.json.return_value = {"renderer": "old"}
        with self.assertRaises(syna_engine.RendererUnavailable):
            self.render(MagicMock(return_value=response))

    def test_renderer_unavailable_is_still_a_runtime_error(self):
        self.assertTrue(issubclass(syna_engine.RendererUnavailable, RuntimeError))


class ResumeWaitingRendersTests(unittest.TestCase):
    def build(self, minutes_to_air):
        self.campaign_id, self.job_id = oid(), oid()
        return FakeDb(
            ai_idol_campaigns=Collection([{"_id": self.campaign_id, "status": "GENERATING",
                                           "scheduled_at": NOW + dt.timedelta(minutes=minutes_to_air)}]),
            ai_idol_jobs=Collection([{"_id": self.job_id, "status": "WAITING_RENDERER",
                                      "campaign_id": str(self.campaign_id)}]),
            ai_idol_segments=Collection([{"_id": oid(), "campaign_id": str(self.campaign_id), "job_id": str(self.job_id),
                                          "status": "GENERATING", "position": 0}]),
        )

    def sweep(self, db, healthy):
        enqueue = MagicMock()
        with patch.object(watchdog, "get_db", return_value=db), \
                patch.object(watchdog, "update_campaign_from_job") as update:
            result = watchdog.resume_waiting_renders(enqueue, NOW, renderer_check=lambda: {"ok": healthy})
        return result, enqueue, update

    def test_healthy_renderer_requeues_the_job_once(self):
        db = self.build(120)
        result, enqueue, _ = self.sweep(db, True)
        self.assertEqual(result["resumed"], 1)
        enqueue.assert_called_once_with(str(self.job_id))
        self.assertEqual(db.ai_idol_jobs.docs[0]["status"], "QUEUED")
        result, enqueue, _ = self.sweep(db, True)  # second sweep: nothing is waiting any more
        self.assertEqual(result["resumed"], 0)
        enqueue.assert_not_called()

    def test_unhealthy_renderer_keeps_the_job_waiting(self):
        db = self.build(120)
        result, enqueue, _ = self.sweep(db, False)
        self.assertEqual(result["waiting"], 1)
        enqueue.assert_not_called()
        self.assertEqual(db.ai_idol_jobs.docs[0]["status"], "WAITING_RENDERER")

    def test_job_is_failed_when_less_than_ten_minutes_to_airtime(self):
        db = self.build(9)
        result, enqueue, update = self.sweep(db, True)
        self.assertEqual(result["failed"], 1)
        enqueue.assert_not_called()
        update.assert_called_once_with(str(self.job_id))
        self.assertEqual(db.ai_idol_jobs.docs[0]["status"], "FAILED")
        for _, query, _update in db.ai_idol_jobs.writes:
            self.assertEqual(query["status"], "WAITING_RENDERER")  # conditional transitions only


class CancelRescheduleTests(unittest.TestCase):
    def build(self, status="SCHEDULED", platforms=("internal",), **extra):
        self.campaign_id, self.ready_job, self.open_job = oid(), oid(), oid()
        cid = str(self.campaign_id)
        return FakeDb(
            ai_idol_campaigns=Collection([{"_id": self.campaign_id, "status": status, "platforms": list(platforms),
                                           "scheduled_at": NOW + dt.timedelta(hours=3), **extra}]),
            ai_idol_segments=Collection([
                {"_id": oid(), "campaign_id": cid, "position": 0, "status": "READY", "job_id": str(self.ready_job)},
                {"_id": oid(), "campaign_id": cid, "position": 1, "status": "AWAITING_APPROVAL", "job_id": str(self.open_job)},
            ]),
            ai_idol_jobs=Collection([{"_id": self.ready_job, "status": "READY"},
                                     {"_id": self.open_job, "status": "AWAITING_APPROVAL"}]),
            ai_idol_agent_runs=Collection([{"_id": oid(), "campaign_id": cid, "status": "CAMPAIGN_CREATED"}]),
        )

    def test_cancel_stops_open_work_keeps_ready_and_completes_agent_run(self):
        db = self.build("AWAITING_APPROVAL")
        with patch.object(campaigns, "get_db", return_value=db):
            result = campaigns.cancel_campaign(str(self.campaign_id))
        self.assertEqual(result["status"], "CANCELLED")
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "CANCELLED")
        self.assertEqual([item["status"] for item in db.ai_idol_segments.docs], ["READY", "CANCELLED"])
        self.assertEqual({str(doc["_id"]): doc["status"] for doc in db.ai_idol_jobs.docs},
                         {str(self.ready_job): "READY", str(self.open_job): "FAILED"})
        self.assertEqual(db.ai_idol_agent_runs.docs[0]["status"], "COMPLETED")

    def test_cancel_while_live_or_starting_is_a_conflict(self):
        for status in ("LIVE", "STARTING", "COMPLETED", "CANCELLED"):
            db = self.build(status)
            with patch.object(campaigns, "get_db", return_value=db), self.assertRaises(campaigns.CampaignConflict):
                campaigns.cancel_campaign(str(self.campaign_id))
            self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], status)
            self.assertEqual(db.ai_idol_segments.docs[1]["status"], "AWAITING_APPROVAL")

    def test_cancel_unknown_campaign(self):
        with patch.object(campaigns, "get_db", return_value=FakeDb()), self.assertRaises(campaigns.CampaignNotFound):
            campaigns.cancel_campaign(str(oid()))

    def test_late_job_callback_cannot_revive_cancelled_segment(self):
        db = self.build("AWAITING_APPROVAL")
        db.ai_idol_jobs.docs[1].update(status="READY", campaign_id=str(self.campaign_id))
        with patch.object(campaigns, "get_db", return_value=db):
            campaigns.cancel_campaign(str(self.campaign_id))
            db.ai_idol_jobs.docs[1]["status"] = "READY"
            campaigns.update_campaign_from_job(str(self.open_job))
        self.assertEqual(db.ai_idol_segments.docs[1]["status"], "CANCELLED")
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "CANCELLED")

    def reschedule(self, db, value):
        with patch.object(campaigns, "get_db", return_value=db), patch.object(campaigns, "utcnow", return_value=NOW):
            return campaigns.reschedule_campaign(str(self.campaign_id), value)

    def test_reschedule_enforces_two_minute_lead(self):
        db = self.build()
        with self.assertRaisesRegex(ValueError, "2 phút"):
            self.reschedule(db, (NOW + dt.timedelta(minutes=1)).isoformat())
        result = self.reschedule(db, (NOW + dt.timedelta(minutes=5)).isoformat())
        self.assertEqual(db.ai_idol_campaigns.docs[0]["scheduled_at"], NOW + dt.timedelta(minutes=5))
        self.assertEqual(result["status"], "SCHEDULED")
        with self.assertRaisesRegex(ValueError, "không hợp lệ"):
            self.reschedule(db, "không phải ngày")

    def test_now_is_only_for_internal_only_campaigns(self):
        db = self.build(platforms=("internal", "youtube"))
        with self.assertRaisesRegex(ValueError, "nội bộ"):
            self.reschedule(db, "now")
        self.assertEqual(db.ai_idol_campaigns.docs[0]["scheduled_at"], NOW + dt.timedelta(hours=3))
        internal = self.build(platforms=("internal",))
        self.reschedule(internal, "now")
        self.assertEqual(internal.ai_idol_campaigns.docs[0]["scheduled_at"], NOW)

    def test_missed_slot_with_finished_programme_returns_to_schedule(self):
        db = self.build("NEEDS_ATTENTION", master_video_path="/tmp/master.mp4", error_message="quá giờ")
        db.ai_idol_segments.docs[1]["status"] = "READY"
        with patch.object(campaigns.os.path, "isfile", return_value=True):
            result = self.reschedule(db, "now")
        self.assertEqual(result["status"], "SCHEDULED")
        self.assertIsNone(db.ai_idol_campaigns.docs[0]["error_message"])

    def test_reschedule_live_campaign_is_a_conflict(self):
        db = self.build("LIVE")
        with self.assertRaises(campaigns.CampaignConflict):
            self.reschedule(db, (NOW + dt.timedelta(hours=1)).isoformat())

    def test_endpoints_map_errors(self):
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        client = service_app.app.test_client()
        with patch.object(service_app, "cancel_campaign", side_effect=campaigns.CampaignConflict("đang phát")):
            self.assertEqual(client.post("/api/ai-idol/campaigns/x/cancel").status_code, 409)
        with patch.object(service_app, "reschedule_campaign", side_effect=ValueError("sai")) as reschedule:
            response = client.post("/api/ai-idol/campaigns/x/reschedule", json={"scheduled_at": "now"})
        self.assertEqual(response.status_code, 400)
        reschedule.assert_called_once_with("x", "now")


class DispatchTests(unittest.TestCase):
    def run_dispatch(self, scheduled_at):
        import tasks
        campaign = oid()
        db = FakeDb(ai_idol_campaigns=Collection([{"_id": campaign, "status": "SCHEDULED", "scheduled_at": scheduled_at}]))
        with patch.object(tasks, "get_db", return_value=db), patch.object(tasks, "utcnow", return_value=NOW), \
                patch.object(tasks.broadcast_campaign_task, "apply_async") as apply:
            dispatched = tasks.dispatch_due_campaigns_task.run()
        return db.ai_idol_campaigns.docs[0], dispatched, apply

    def test_due_campaign_starts(self):
        doc, dispatched, apply = self.run_dispatch(NOW - dt.timedelta(minutes=2))
        self.assertEqual((doc["status"], dispatched), ("STARTING", 1))
        apply.assert_called_once()

    def test_campaign_past_the_grace_window_needs_attention(self):
        doc, dispatched, apply = self.run_dispatch(NOW - dt.timedelta(minutes=31))
        self.assertEqual((doc["status"], dispatched), ("NEEDS_ATTENTION", 0))
        self.assertIn("quá giờ phát", doc["error_message"])
        apply.assert_not_called()


class InternalBroadcastTests(unittest.TestCase):
    def test_internal_target_goes_live_with_end_time_without_ffmpeg(self):
        db = FakeDb()
        with patch.object(broadcast_engine, "get_db", return_value=db), \
                patch.object(broadcast_engine.subprocess, "run") as run:
            result = broadcast_engine._stream_target("c1", "internal", "/m.mp4", 3600)
        run.assert_not_called()
        self.assertEqual(result, ("internal", True, ""))
        row = db.ai_idol_broadcasts.docs[0]
        self.assertEqual(row["status"], "LIVE")
        self.assertEqual((row["ends_at"] - row["started_at"]).total_seconds(), 3600)

    def broadcast(self, platforms):
        cid = oid()
        db = FakeDb(ai_idol_campaigns=Collection([{"_id": cid, "status": "STARTING", "platforms": platforms,
                                                   "duration_minutes": 60, "master_video_path": "/m.mp4"}]))
        with patch.object(broadcast_engine, "get_db", return_value=db), \
                patch.object(broadcast_engine, "preflight_campaign", return_value={"ok": True, "errors": []}), \
                patch.object(broadcast_engine, "_stream_target",
                             side_effect=lambda c, p, m, d: broadcast_engine.LOCAL_PLATFORMS and (p, True, "")):
            broadcast_engine.broadcast_campaign(str(cid))
        return db, cid

    def test_internal_only_campaign_stays_live_until_ends_at(self):
        db, _ = self.broadcast(["internal"])
        campaign = db.ai_idol_campaigns.docs[0]
        self.assertEqual(campaign["status"], "LIVE")
        self.assertEqual((campaign["ends_at"] - campaign["started_at"]).total_seconds(), 3600)

    def test_finalize_closes_only_finished_internal_campaigns(self):
        db = FakeDb(
            ai_idol_campaigns=Collection([
                {"_id": oid(), "status": "LIVE", "platforms": ["internal"], "ends_at": NOW - dt.timedelta(seconds=1)},
                {"_id": oid(), "status": "LIVE", "platforms": ["internal"], "ends_at": NOW + dt.timedelta(minutes=5)},
                {"_id": oid(), "status": "LIVE", "platforms": ["internal", "youtube"], "ends_at": NOW - dt.timedelta(minutes=1)},
            ]))
        with patch.object(broadcast_engine, "get_db", return_value=db):
            closed = broadcast_engine.finalize_live_campaigns(NOW)
        self.assertEqual([doc["status"] for doc in db.ai_idol_campaigns.docs], ["COMPLETED", "LIVE", "LIVE"])
        self.assertEqual(closed, {"completed": 1, "partial_failure": 0})

    def test_finalize_uses_partial_failure_when_a_broadcast_failed_and_is_conditional(self):
        cid = oid()
        db = FakeDb(
            ai_idol_campaigns=Collection([{"_id": cid, "status": "LIVE", "platforms": ["internal"], "ends_at": NOW - dt.timedelta(seconds=5)}]),
            ai_idol_broadcasts=Collection([{"campaign_id": str(cid), "platform": "internal", "status": "FAILED"}]),
        )
        with patch.object(broadcast_engine, "get_db", return_value=db):
            broadcast_engine.finalize_live_campaigns(NOW)
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "PARTIAL_FAILURE")
        self.assertEqual(db.ai_idol_campaigns.writes[0][1]["status"], "LIVE")

    def test_orphaned_mixed_campaign_is_closed_after_grace(self):
        db = FakeDb(ai_idol_campaigns=Collection([
            {"_id": oid(), "status": "LIVE", "platforms": ["youtube"], "ends_at": NOW - dt.timedelta(minutes=20)}]))
        with patch.object(broadcast_engine, "get_db", return_value=db):
            broadcast_engine.finalize_live_campaigns(NOW)
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "PARTIAL_FAILURE")


class RedactionTests(unittest.TestCase):
    def test_redact_removes_target_key_and_any_rtmp_url(self):
        with patch.dict("config.RTMP_TARGETS", {"youtube": SECRET_URL}, clear=True):
            text = redact_secrets(f"Failed to open {SECRET_URL}: refused; key SECRETKEY-1234; other rtmps://x.y/z?k=abc")
        self.assertNotIn("SECRETKEY", text)
        self.assertNotIn("rtmp", text.lower())

    def test_stream_failure_never_persists_the_secret(self):
        db = FakeDb()
        failed = MagicMock(returncode=1, stderr=f"Error opening output {SECRET_URL}\nconnection to SECRETKEY-1234 refused")
        with patch.dict("config.RTMP_TARGETS", {"youtube": SECRET_URL}, clear=True), \
                patch.object(broadcast_engine, "get_db", return_value=db), \
                patch.object(broadcast_engine, "BROADCAST_MAX_RETRIES", 2), \
                patch.object(broadcast_engine.time, "sleep"), \
                patch.object(broadcast_engine.subprocess, "run", return_value=failed):
            platform, ok, error = broadcast_engine._stream_target("c1", "youtube", "/m.mp4", 60)
        self.assertFalse(ok)
        self.assertNotIn("SECRETKEY", error)
        stored = json.dumps([doc for doc in db.ai_idol_broadcasts.docs], default=str)
        self.assertNotIn("SECRETKEY", stored)
        self.assertNotIn("a.rtmp.youtube.com", stored)

    def test_failed_preflight_message_is_redacted_before_it_is_stored(self):
        cid = oid()
        db = FakeDb(ai_idol_campaigns=Collection([{"_id": cid, "status": "STARTING", "platforms": ["internal"],
                                                   "master_video_path": "/missing.mp4"}]))
        with patch.dict("config.RTMP_TARGETS", {"youtube": SECRET_URL}, clear=True), \
                patch.object(broadcast_engine, "get_db", return_value=db), \
                patch.object(broadcast_engine, "preflight_campaign",
                             return_value={"ok": False, "errors": [f"probe failed for {SECRET_URL}"]}):
            broadcast_engine.broadcast_campaign(str(cid))
        stored = json.dumps(db.ai_idol_campaigns.docs, default=str)
        self.assertNotIn("SECRETKEY", stored)
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "NEEDS_ATTENTION")


class AgentTimelineDataTests(unittest.TestCase):
    def test_stage_history_is_pushed_and_bounded_to_thirty(self):
        run_id = oid()
        db = FakeDb(ai_idol_agent_runs=Collection([{"_id": run_id, "status": "RECEIVED"}]))
        with patch.object(agent_runs, "get_db", return_value=db):
            for index in range(35):
                agent_runs.update_agent_run(str(run_id), {"status": "INTERPRETING", "current_stage": f"S{index}"})
            agent_runs.update_agent_run(str(run_id), {"plan": {"x": 1}})  # no stage/status: nothing pushed
        history = db.ai_idol_agent_runs.docs[0]["stage_history"]
        self.assertEqual(len(history), 30)
        self.assertEqual((history[0]["stage"], history[-1]["stage"]), ("S5", "S34"))

    def test_serialized_run_exposes_campaign_progress_and_redacts_broadcasts(self):
        run_id, cid, job = oid(), oid(), oid()
        db = FakeDb(
            ai_idol_campaigns=Collection([{"_id": cid, "status": "LIVE", "platforms": ["youtube"], "progress": {"total": 2, "ready": 2},
                                           "scheduled_at": NOW, "started_at": NOW, "ends_at": NOW + dt.timedelta(hours=1),
                                           "preflight": {"ok": True, "errors": [f"x {SECRET_URL}"]}}]),
            ai_idol_segments=Collection([
                {"_id": oid(), "campaign_id": str(cid), "position": 0, "product_id": "93", "status": "READY", "job_id": str(job)},
                {"_id": oid(), "campaign_id": str(cid), "position": 1, "product_id": "94", "status": "GENERATING", "job_id": None},
            ]),
            ai_idol_jobs=Collection([{"_id": job, "current_stage": "READY"}]),
            ai_idol_broadcasts=Collection([{"campaign_id": str(cid), "platform": "youtube", "status": "RECOVERING",
                                            "error_message": f"cannot open {SECRET_URL}"}]),
        )
        run = {"_id": run_id, "status": "CAMPAIGN_CREATED", "campaign_id": str(cid)}
        with patch.dict("config.RTMP_TARGETS", {"youtube": SECRET_URL}, clear=True):
            data = agent_runs._serialize_with_campaign(run, db)
        self.assertEqual(data["status_effective"], "LIVE")
        self.assertEqual(data["campaign_platforms"], ["youtube"])
        self.assertEqual([item["product_id"] for item in data["campaign_segments"]], ["93", "94"])
        self.assertEqual(data["campaign_segments"][0]["job_stage"], "READY")
        self.assertNotIn("SECRETKEY", json.dumps(data, default=str))
        self.assertEqual(data["campaign_broadcasts"][0]["platform"], "youtube")
        self.assertNotIn("status_effective_stored", run)  # derived for display, not persisted

    def test_run_without_campaign_keeps_its_own_status(self):
        data = agent_runs._serialize_with_campaign({"_id": oid(), "status": "NEEDS_INPUT", "campaign_id": None}, FakeDb())
        self.assertEqual(data["status_effective"], "NEEDS_INPUT")


class AgentRendererWarningTests(unittest.TestCase):
    def test_down_renderer_adds_a_non_blocking_warning(self):
        from agents.service import _renderer_warnings
        self.assertEqual(_renderer_warnings(None), [])
        self.assertEqual(_renderer_warnings(lambda: {"ok": True}), [])
        self.assertIn("Syna đang tắt", _renderer_warnings(lambda: {"ok": False})[0])
        self.assertIn("Syna đang tắt", _renderer_warnings(MagicMock(side_effect=RuntimeError("x")))[0])


class RoutesTests(unittest.TestCase):
    def test_new_beat_tasks_are_routed_to_control_and_expire(self):
        from celery_app import TASK_ROUTES, celery_app
        for name in ("ai_idol.resume_waiting_renders", "ai_idol.finalize_live_campaigns"):
            self.assertEqual(TASK_ROUTES[name]["queue"], "control")
        entries = {entry["task"]: entry for entry in celery_app.conf.beat_schedule.values()}
        self.assertEqual(entries["ai_idol.resume_waiting_renders"]["schedule"], 60.0)
        self.assertEqual(entries["ai_idol.finalize_live_campaigns"]["schedule"], 30.0)
        for entry in entries.values():
            self.assertIn("expires", entry["options"])


if __name__ == "__main__":
    unittest.main()
