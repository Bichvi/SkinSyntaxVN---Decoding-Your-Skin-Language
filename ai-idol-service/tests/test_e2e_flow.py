"""End-to-end regression of the livestream flow with an in-memory Mongo.

Real code: agent -> campaign -> prepare -> pipeline -> assemble gate -> dispatch ->
broadcast -> finalize, plus the approve endpoint and the renderer-wait loop.
Patched: the LLM, RAG, script council/validator, TTS, FFmpeg assembly, Syna renderer.
"""
import datetime as dt
import os
import sys
import tempfile
import types
import unittest
from contextlib import ExitStack
from unittest.mock import MagicMock, patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import tasks
from agents.service import process_agent_run
from core.agent_runs import create_agent_run, get_agent_run
from core.campaigns import prepare_campaign
from core.state_machine import object_id
from core.watchdog import resume_waiting_renders
from engines import broadcast_engine, syna_engine

BRIEF = "Tối mai lúc 20 giờ, tạo livestream giới thiệu sản phẩm mã 93 an toàn"
SCRIPT = "Xin chào các bạn, hôm nay SkinSyntax giới thiệu một sản phẩm dịu nhẹ cho làn da hằng ngày."
CATALOG = [
    {"ma_san_pham": 745, "ten_san_pham": "Kem Chống Nắng An Toàn"},
    {"ma_san_pham": 93, "ten_san_pham": "Serum Phục Hồi Da"},
]
DB_MODULES = [
    "core.state_machine", "core.campaigns", "core.agent_runs", "core.pipeline", "core.watchdog",
    "engines.broadcast_engine", "tasks", "agents.service", "engines.product_collector",
]


class FakeLlm:
    def invoke_json_schema(self, *args, **kwargs):
        return {"goal": BRIEF, "explicit_product_ids": ["93"]}


class Harness:
    """Wires one FakeDb into every module and stubs only the heavy engines."""

    def __init__(self, test):
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app  # imported before the engine stubs are installed
        self.app = service_app
        self.db = FakeDb()
        self.stack = ExitStack()
        test.addCleanup(self.stack.close)
        self.master = tempfile.NamedTemporaryFile(suffix=".mp4", delete=False)
        self.master.write(b"0" * 2048)
        self.master.close()
        test.addCleanup(os.unlink, self.master.name)
        for module in DB_MODULES:
            self.stack.enter_context(patch(f"{module}.get_db", return_value=self.db))
        self.generate_calls, self.assemble_calls, self.broadcast_calls = [], [], []
        self.syna_up = True
        self.stack.enter_context(patch.dict(sys.modules, {
            "engines.knowledge_retriever": types.SimpleNamespace(retrieve_knowledge=lambda snapshot: "facts"),
            "engines.content_agent": types.SimpleNamespace(generate_content_and_script=lambda *a, **k: SCRIPT),
            "engines.script_validator": types.SimpleNamespace(
                validate_and_repair_script=lambda draft, *a, **k: types.SimpleNamespace(
                    passed=True, script=draft, feedback="", repair_attempts=0,
                    history=[{"version": 1, "text": draft, "passed": True, "issues": []}])),
            "engines.voice_engine": types.SimpleNamespace(generate_voice_audio=lambda *a, **k: self.master.name),
        }))
        self.stack.enter_context(patch("engines.video_composer.get_audio_duration", return_value=12.0))
        self.stack.enter_context(patch.object(syna_engine, "render_syna_video", side_effect=self._render))
        self.stack.enter_context(patch("core.campaigns.collect_product_and_snapshot",
                                       side_effect=lambda pid: {"name": f"Sản phẩm {pid}", "price": 100000}))
        self.stack.enter_context(patch.object(tasks.generate_job_task, "delay", side_effect=self.generate_calls.append))
        self.stack.enter_context(patch.object(tasks.assemble_campaign_task, "delay", side_effect=self.assemble_calls.append))
        self.stack.enter_context(patch.object(tasks, "assemble_campaign_program", side_effect=self._assemble))
        self.stack.enter_context(patch.object(tasks.broadcast_campaign_task, "apply_async",
                                              side_effect=lambda args, queue: self.broadcast_calls.append(args[0])))
        self.stack.enter_context(patch.object(broadcast_engine, "probe_duration", return_value=12))
        service_app = self.app
        self.stack.enter_context(patch.object(service_app, "get_db", return_value=self.db))
        self.stack.enter_context(patch.object(service_app.generate_job_task, "delay", side_effect=self.generate_calls.append))

    def _render(self, *args, **kwargs):
        if not self.syna_up:
            raise syna_engine.RendererUnavailable("Syna chưa sẵn sàng")
        return self.master.name

    def _assemble(self, campaign_id):
        self.db.ai_idol_campaigns.find_one_and_update(
            {"_id": object_id(campaign_id)},
            {"$set": {"status": "SCHEDULED", "master_video_path": self.master.name, "duration_seconds": 12}},
        )
        return self.master.name

    # ---- steps --------------------------------------------------------
    def plan(self, policy):
        run_id, _ = create_agent_run({"brief": BRIEF, "autonomy_policy": policy, "idempotency_key": f"e2e:{policy}"}, "admin")
        queued = []
        process_agent_run(run_id, llm=FakeLlm(), catalog=CATALOG, enqueue_campaign=queued.append)
        run = get_agent_run(run_id)
        self.run_id, self.campaign_id = run_id, run["campaign_id"]
        for campaign_id in queued:
            for job_id in prepare_campaign(campaign_id):
                self.generate_calls.append(job_id)
        return run

    def generate_all(self):
        pending, self.generate_calls[:] = list(self.generate_calls), []
        for job_id in pending:
            tasks.generate_job_task.run(job_id)

    def assemble_all(self):
        pending, self.assemble_calls[:] = list(self.assemble_calls), []
        for campaign_id in pending:
            tasks.assemble_campaign_task.run(campaign_id)

    def campaign(self):
        return self.db.ai_idol_campaigns.docs[0]

    def job(self):
        return self.db.ai_idol_jobs.docs[0]


class EndToEndFlowTests(unittest.TestCase):
    def test_auto_preview_runs_to_completed_with_the_requested_product(self):
        h = Harness(self)
        run = h.plan("auto_preview")
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(h.campaign()["product_ids"], ["93"])  # never 745
        self.assertEqual(h.campaign()["platforms"], ["internal"])

        h.generate_all()
        self.assertEqual(h.job()["status"], "READY")
        self.assertEqual(h.job()["script_status"], "APPROVED")  # agent policy approved it, nobody had to
        self.assertEqual(h.campaign()["status"], "ASSEMBLING")
        self.assertEqual(h.campaign()["progress"]["ready"], 1)

        h.assemble_all()
        self.assertEqual(h.campaign()["status"], "SCHEDULED")

        h.campaign()["scheduled_at"] = dt.datetime.now(dt.timezone.utc) - dt.timedelta(minutes=1)  # airtime reached
        self.assertEqual(tasks.dispatch_due_campaigns_task.run(), 1)
        self.assertEqual(h.campaign()["status"], "STARTING")
        broadcast_engine.broadcast_campaign(h.broadcast_calls[0])
        live = h.campaign()
        self.assertEqual(live["status"], "LIVE")  # internal-only: stays live for the whole programme
        self.assertEqual(h.db.ai_idol_broadcasts.docs[0]["status"], "LIVE")

        shown = get_agent_run(h.run_id)
        self.assertEqual(shown["status_effective"], "LIVE")
        self.assertEqual(shown["campaign_segments"][0]["product_id"], "93")
        self.assertTrue(len(shown["stage_history"]) >= 3)

        closed = broadcast_engine.finalize_live_campaigns(live["ends_at"] + dt.timedelta(seconds=1))
        self.assertEqual(closed["completed"], 1)
        self.assertEqual(get_agent_run(h.run_id)["status_effective"], "COMPLETED")
        self.assertEqual(h.db.ai_idol_broadcasts.docs[0]["status"], "COMPLETED")

    def test_assisted_waits_for_approval_then_schedules(self):
        h = Harness(self)
        h.plan("assisted")
        h.generate_all()
        self.assertEqual(h.job()["status"], "AWAITING_APPROVAL")
        self.assertEqual(h.campaign()["status"], "AWAITING_APPROVAL")
        self.assertEqual(h.db.ai_idol_segments.docs[0]["status"], "AWAITING_APPROVAL")
        self.assertEqual(h.assemble_calls, [])  # nothing is assembled before a human approves

        client = h.app.app.test_client()
        response = client.post(f"/api/ai-idol/jobs/{h.job()['_id']}/script/approve", json={"script_text": SCRIPT})
        self.assertEqual(response.status_code, 202)
        self.assertEqual(h.campaign()["status"], "GENERATING")

        h.generate_all()
        self.assertEqual(h.job()["status"], "READY")
        h.assemble_all()
        self.assertEqual(h.campaign()["status"], "SCHEDULED")

    def test_syna_down_waits_then_resumes_when_it_returns(self):
        h = Harness(self)
        h.syna_up = False
        h.plan("auto_preview")
        h.generate_all()
        self.assertEqual(h.job()["status"], "WAITING_RENDERER")  # not FAILED
        self.assertEqual(h.db.ai_idol_segments.docs[0]["status"], "GENERATING")
        self.assertEqual(h.campaign()["status"], "GENERATING")
        self.assertEqual(h.assemble_calls, [])

        now = dt.datetime.now(dt.timezone.utc)
        resumed = resume_waiting_renders(h.generate_calls.append, now, renderer_check=lambda: {"ok": False})
        self.assertEqual(resumed["resumed"], 0)
        self.assertEqual(h.job()["status"], "WAITING_RENDERER")

        h.syna_up = True
        resumed = resume_waiting_renders(h.generate_calls.append, now, renderer_check=lambda: {"ok": True})
        self.assertEqual(resumed["resumed"], 1)
        self.assertEqual(h.job()["status"], "QUEUED")
        h.generate_all()
        self.assertEqual(h.job()["status"], "READY")
        self.assertEqual(h.db.ai_idol_segments.docs[0]["status"], "READY")
        self.assertEqual(h.campaign()["status"], "ASSEMBLING")
        self.assertEqual(h.assemble_calls, [str(h.campaign()["_id"])])


if __name__ == "__main__":
    unittest.main()
