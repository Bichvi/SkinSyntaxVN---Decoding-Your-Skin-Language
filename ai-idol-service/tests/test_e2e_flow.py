"""End-to-end regression of the livestream flow with an in-memory Mongo.

Real code: agent -> campaign -> prepare -> pipeline -> assemble gate -> dispatch ->
broadcast -> finalize, plus the approve endpoint and the renderer-wait loop.
Live campaigns are clip libraries: phase A writes the clips (prewritten scripts, one job each),
phase B writes the routine recap, then the show is assembled.
Patched: the LLM, the clip writers, RAG, script council/validator, TTS, FFmpeg assembly, Syna renderer.
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
from core.show_types import ClipDraft
from core.state_machine import object_id
from core.watchdog import resume_waiting_renders
from engines import broadcast_engine, show_writer, syna_engine

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


def fake_product_clips(snapshot, item, plan, **kwargs):
    return [ClipDraft("product", SCRIPT, product_id=str(item["product_id"]), angle=angle, variant=index)
            for index, angle in enumerate("AB")]


def fake_frame_clips(plan, snapshots, faqs, **kwargs):
    return "Routine nhẹ nhàng", [ClipDraft("opening", SCRIPT), ClipDraft("closing", SCRIPT)]


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
        self.render_calls = []
        self.llm_scripts = []  # clips the pipeline wrote itself (library clips are prewritten: none)
        self.recap_calls = []
        self.stack.enter_context(patch.object(show_writer, "write_product_clips", side_effect=fake_product_clips))
        self.stack.enter_context(patch.object(show_writer, "write_frame_clips", side_effect=fake_frame_clips))
        self.stack.enter_context(patch.object(show_writer, "write_routine_recap",
                                              side_effect=lambda plan, snapshots, **k: ClipDraft("routine_recap", SCRIPT)))
        self.stack.enter_context(patch.object(show_writer, "load_answered_faqs", return_value=[]))
        self.stack.enter_context(patch("core.show_plan.fetch_routine_steps",
                                       side_effect=lambda ids, **k: ({str(i): None for i in ids}, [], "keyword")))
        self.stack.enter_context(patch.object(tasks.write_routine_recap_task, "delay", side_effect=self.recap_calls.append))
        self.stack.enter_context(patch.dict(sys.modules, {
            "engines.knowledge_retriever": types.SimpleNamespace(retrieve_knowledge=lambda snapshot: "facts"),
            "engines.content_agent": types.SimpleNamespace(generate_content_and_script=self._generate),
            "engines.script_validator": types.SimpleNamespace(
                validate_and_repair_script=lambda draft, *a, **k: types.SimpleNamespace(
                    passed=True, script=draft, feedback="", repair_attempts=0, issues=[],
                    history=[{"version": 1, "text": draft, "passed": True, "issues": []}]),
                validate_script=lambda *a, **k: []),
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
        self.publish_calls = []  # product-page publish queued when a both-mode show ends
        self.stack.enter_context(patch.object(broadcast_engine, "_send_publish_task",
                                              side_effect=self.publish_calls.append))
        self.replay_calls = []  # storefront replay queued when a show on the internal channel ends
        self.stack.enter_context(patch.object(broadcast_engine, "_send_replay_task",
                                              side_effect=self.replay_calls.append))
        service_app = self.app
        self.stack.enter_context(patch.object(service_app, "get_db", return_value=self.db))
        self.stack.enter_context(patch.object(service_app.generate_job_task, "delay", side_effect=self.generate_calls.append))

    def _generate(self, *args, **kwargs):
        self.llm_scripts.append(args)
        return SCRIPT

    def _render(self, *args, **kwargs):
        self.render_calls.append(kwargs)
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

    def write_recap(self):
        """Phase B: the recap writer runs once every product clip has settled."""
        pending, self.recap_calls[:] = list(self.recap_calls), []
        for campaign_id in pending:
            tasks.write_routine_recap_task.run(campaign_id)

    def finish_generation(self):
        self.generate_all()
        self.write_recap()
        self.generate_all()

    def assemble_all(self):
        pending, self.assemble_calls[:] = list(self.assemble_calls), []
        for campaign_id in pending:
            tasks.assemble_campaign_task.run(campaign_id)

    def campaign(self):
        return self.db.ai_idol_campaigns.docs[0]

    def jobs(self):
        return self.db.ai_idol_jobs.docs

    def job(self, kind="product", angle=None):
        """The job of one clip kind (first product angle by default)."""
        for segment in self.db.ai_idol_segments.docs:
            if segment["kind"] == kind and (angle is None or segment.get("angle") == angle):
                return next(j for j in self.jobs() if str(j["_id"]) == str(segment["job_id"]))
        raise LookupError(kind)


class EndToEndFlowTests(unittest.TestCase):
    def test_auto_preview_runs_to_completed_with_the_requested_product(self):
        h = Harness(self)
        run = h.plan("auto_preview")
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(h.campaign()["product_ids"], ["93"])  # never 745
        self.assertEqual(h.campaign()["platforms"], ["internal"])
        self.assertEqual(h.campaign()["show_format"], "clip_library_v1")

        h.generate_all()  # phase A: two product angles, opening, closing
        kinds = sorted(job["clip_kind"] for job in h.jobs())
        self.assertEqual(kinds, ["closing", "opening", "product", "product"])
        for job in h.jobs():
            self.assertEqual(job["status"], "READY")
            self.assertEqual(job["script_status"], "APPROVED")  # agent policy approved it, nobody had to
            self.assertTrue(job["prewritten_script"])
        self.assertEqual(h.llm_scripts, [])  # prewritten clips never call the content generator
        self.assertEqual(h.campaign()["status"], "GENERATING")  # waits for the routine recap
        self.assertEqual(h.assemble_calls, [])

        h.write_recap()
        h.generate_all()  # phase B
        self.assertEqual(h.job("routine_recap")["status"], "READY")
        self.assertEqual(h.campaign()["status"], "ASSEMBLING")
        self.assertEqual(h.campaign()["progress"]["ready"], 5)

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
        self.assertEqual(h.campaign()["purpose"], "live_only")  # the agent's default live mode
        self.assertEqual(h.publish_calls, [])  # live_only never publishes clips to product pages
        self.assertEqual(h.replay_calls, [str(h.campaign()["_id"])])  # the aired show becomes a replay

    def test_library_clips_render_with_their_kind(self):
        h = Harness(self)
        h.plan("auto_preview")
        h.finish_generation()
        rendered = sorted(call.get("clip_kind") for call in h.render_calls)
        self.assertEqual(rendered, ["closing", "opening", "product", "product", "routine_recap"])

    def test_assisted_waits_for_approval_then_schedules(self):
        h = Harness(self)
        h.plan("assisted")
        h.generate_all()
        for job in h.jobs():
            self.assertEqual(job["status"], "AWAITING_APPROVAL")
        self.assertEqual(h.llm_scripts, [])
        self.assertEqual(h.campaign()["status"], "AWAITING_APPROVAL")
        for segment in h.db.ai_idol_segments.docs:
            self.assertEqual(segment["status"], "AWAITING_APPROVAL")
        self.assertEqual(h.assemble_calls, [])  # nothing is assembled before a human approves

        client = h.app.app.test_client()
        for job in h.jobs():
            response = client.post(f"/api/ai-idol/jobs/{job['_id']}/script/approve", json={"script_text": SCRIPT})
            self.assertEqual(response.status_code, 202)
        self.assertEqual(h.campaign()["status"], "GENERATING")

        h.generate_all()  # phase A is READY now
        h.write_recap()
        h.generate_all()  # the recap is a clip too: it waits for a human as well
        self.assertEqual(h.job("routine_recap")["status"], "AWAITING_APPROVAL")
        recap_id = h.job("routine_recap")["_id"]
        response = client.post(f"/api/ai-idol/jobs/{recap_id}/script/approve", json={"script_text": SCRIPT})
        self.assertEqual(response.status_code, 202)
        h.generate_all()
        for job in h.jobs():
            self.assertEqual(job["status"], "READY")
        h.assemble_all()
        self.assertEqual(h.campaign()["status"], "SCHEDULED")

    def test_syna_down_waits_then_resumes_when_it_returns(self):
        h = Harness(self)
        h.syna_up = False
        h.plan("auto_preview")
        h.generate_all()
        for job in h.jobs():
            self.assertEqual(job["status"], "WAITING_RENDERER")  # not FAILED
        for segment in h.db.ai_idol_segments.docs:
            self.assertEqual(segment["status"], "GENERATING")
        self.assertEqual(h.campaign()["status"], "GENERATING")
        self.assertEqual(h.assemble_calls, [])

        now = dt.datetime.now(dt.timezone.utc)
        resumed = resume_waiting_renders(h.generate_calls.append, now, renderer_check=lambda: {"ok": False})
        self.assertEqual(resumed["resumed"], 0)
        for job in h.jobs():
            self.assertEqual(job["status"], "WAITING_RENDERER")

        h.syna_up = True
        resumed = resume_waiting_renders(h.generate_calls.append, now, renderer_check=lambda: {"ok": True})
        self.assertEqual(resumed["resumed"], 4)
        for job in h.jobs():
            self.assertEqual(job["status"], "QUEUED")
        h.finish_generation()
        for job in h.jobs():
            self.assertEqual(job["status"], "READY")
        for segment in h.db.ai_idol_segments.docs:
            self.assertEqual(segment["status"], "READY")
        self.assertEqual(h.campaign()["status"], "ASSEMBLING")
        self.assertEqual(h.assemble_calls, [str(h.campaign()["_id"])])

    def test_legacy_campaign_still_generates_its_own_script(self):
        """A campaign without show_format (made before clip libraries) keeps the one-job-per-product flow."""
        h = Harness(self)
        campaign_id = str(h.db.ai_idol_campaigns.insert_one({
            "status": "GENERATING", "purpose": "live_only", "product_ids": ["93"],
            "content_mode": "Product Intro", "progress": {"total": 1},
            "configuration": {"approval_mode": "AUTO", "avatar_mode": "syna_3d"}}).inserted_id)
        h.db.ai_idol_segments.insert_one({
            "campaign_id": campaign_id, "product_id": "93", "position": 0, "status": "QUEUED", "job_id": None})
        for job_id in prepare_campaign(campaign_id):
            h.generate_calls.append(job_id)
        h.generate_all()
        job = h.jobs()[0]
        self.assertEqual(job["status"], "READY")
        self.assertNotIn("prewritten_script", job)
        self.assertEqual(len(h.llm_scripts), 1)  # the pipeline wrote it itself
        self.assertEqual(h.render_calls, [{"purpose": "live_only"}])  # legacy render call is unchanged
        self.assertEqual(h.campaign()["status"], "ASSEMBLING")


if __name__ == "__main__":
    unittest.main()
