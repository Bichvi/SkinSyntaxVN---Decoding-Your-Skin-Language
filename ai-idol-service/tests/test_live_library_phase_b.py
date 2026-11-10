"""Phase B of a clip library: routine recap, readiness, retry (FakeDb, writer and HTTP patched)."""
import datetime as dt
import os
import sys
import unittest
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import core.campaigns as campaigns
import core.state_machine as state_machine
from core.show_types import ClipDraft

JOB_STATUS = {"READY": "READY", "FAILED": "FAILED", "GENERATING": "IN_PROGRESS", "SKIPPED": "AUTO_REPAIR_EXHAUSTED",
              "CANCELLED": "READY"}


def snapshot_for(product_id):
    return {"id": str(product_id), "name": f"Sản phẩm {product_id}", "brand": "Beplain",
            "pricing": {"regular_price": 100000 * int(product_id), "flash": None}}


class Harness(unittest.TestCase):
    def setUp(self):
        self.db = FakeDb()
        for target in (campaigns, state_machine):
            patcher = patch.object(target, "get_db", return_value=self.db)
            patcher.start()
            self.addCleanup(patcher.stop)
        ids = ["1", "2", "3"]
        plan = {"products": [{"product_id": p, "number": i, "step_key": "serum", "step_label": "Serum"}
                             for i, p in enumerate(ids, start=1)],
                "conflicts": [], "theme_vi": "Routine", "routine_source": "keyword"}
        self.campaign_id = str(self.db.ai_idol_campaigns.insert_one({
            "status": "GENERATING", "show_format": "clip_library_v1", "show_plan": plan, "product_ids": ids,
            "purpose": "live_and_product", "content_mode": "Product Intro", "configuration": {},
            "progress": {"total": 0}}).inserted_id)
        self.position = 0

    def add(self, kind, status="READY", product_id=None, angle=None, phase="A", **extra):
        segment = {"campaign_id": self.campaign_id, "kind": kind, "product_id": product_id, "angle": angle,
                   "phase": phase, "position": self.position, "status": status, "job_id": None, **extra}
        self.position += 1
        sid = self.db.ai_idol_segments.insert_one(segment).inserted_id
        job_id = str(self.db.ai_idol_jobs.insert_one({
            "campaign_id": self.campaign_id, "segment_id": str(sid), "status": JOB_STATUS[status],
            "product_id": product_id, "clip_kind": kind}).inserted_id)
        self.db.ai_idol_segments.update_one({"_id": sid}, {"$set": {"job_id": job_id}})
        return job_id

    def full_frame(self, closing="READY"):
        self.add("opening")
        self.add("interlude", interlude_type="cta")
        self.add("closing", closing)

    def segments(self, **query):
        return [s for s in self.db.ai_idol_segments.docs if all(s.get(k) == v for k, v in query.items())]

    def campaign(self):
        return self.db.ai_idol_campaigns.docs[0]

    def start_phase_b(self):
        captured = {}

        def fake_recap(plan, snapshots, **kwargs):
            captured["plan"], captured["snapshots"] = plan, snapshots
            return ClipDraft(kind="routine_recap", script="Tóm tắt routine")

        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for), \
                patch.object(campaigns.show_writer, "write_routine_recap", side_effect=fake_recap):
            job_id = campaigns.start_phase_b(self.campaign_id)
        return job_id, captured


class PhaseBTests(Harness):
    def test_phase_b_starts_after_all_product_clips_final(self):
        for pid in ("1", "2", "3"):
            self.add("product", product_id=pid, angle="A")
            if pid != "3":
                self.add("product", product_id=pid, angle="B")
        last = self.add("product", "GENERATING", product_id="3", angle="B")
        self.add("closing", "GENERATING")
        with patch.object(campaigns, "_enqueue_recap") as enqueue:
            campaigns.update_campaign_from_job(self.add("faq", "READY", product_id="1"))
            enqueue.assert_not_called()  # a product clip is still rendering
            self.db.ai_idol_jobs.update_one({"_id": state_machine.object_id(last)}, {"$set": {"status": "READY"}})
            self.assertIsNone(campaigns.update_campaign_from_job(last))
            enqueue.assert_called_once_with(self.campaign_id)
        job_id, captured = self.start_phase_b()
        self.assertTrue(job_id)
        self.assertEqual([p["number"] for p in captured["plan"]["products"]], [1, 2, 3])
        recap = self.segments(kind="routine_recap")[0]
        self.assertEqual((recap["phase"], recap["status"], recap["job_id"]), ("B", "GENERATING", job_id))
        job = self.db.ai_idol_jobs.find_one({"_id": state_machine.object_id(job_id)})
        self.assertEqual(job["clip_kind"], "routine_recap")
        self.assertEqual(job["purpose"], "live_and_product")
        self.assertTrue(job["prewritten_script"])
        self.assertEqual(job["artifacts"]["prewritten_draft"], "Tóm tắt routine")
        self.assertEqual(job["product_snapshot"]["bundle_total"], 600000)
        self.assertEqual(self.campaign()["progress"]["total"], 1)
        again, _ = self.start_phase_b()
        self.assertIsNone(again)  # same product set: nothing to redo
        self.assertEqual(len(self.segments(kind="routine_recap")), 1)

    def test_recap_after_drop_keeps_original_numbers(self):
        for angle in ("A", "B"):
            self.add("product", product_id="1", angle=angle)
            self.add("product", "SKIPPED", product_id="2", angle=angle)
            self.add("product", product_id="3", angle=angle)
        job_id, captured = self.start_phase_b()
        self.assertTrue(job_id)
        self.assertEqual([p["number"] for p in captured["plan"]["products"]], [1, 3])
        job = self.db.ai_idol_jobs.find_one({"_id": state_machine.object_id(job_id)})
        self.assertEqual(job["product_snapshot"]["bundle_total"], 400000)
        self.assertEqual(self.segments(kind="routine_recap")[0]["recap_for"], ["1", "3"])


class ReadinessTests(Harness):
    def test_ready_with_one_product_without_interlude(self):
        self.add("opening")
        self.add("product", product_id="1", angle="A")
        self.add("product", "FAILED", product_id="1", angle="B")
        self.add("routine_recap", phase="B")
        self.add("closing")
        self.assertEqual(campaigns.library_ready(self.db.ai_idol_segments.docs), (True, ""))

    def test_interlude_needed_from_two_products(self):
        self.add("opening")
        self.add("product", product_id="1", angle="A")
        self.add("product", product_id="2", angle="A")
        self.add("routine_recap", phase="B")
        self.add("closing")
        ok, reason = campaigns.library_ready(self.db.ai_idol_segments.docs)
        self.assertFalse(ok)
        self.assertIn("nghỉ giữa hiệp", reason)

    def test_not_ready_without_closing_reason(self):
        self.add("opening")
        self.add("product", product_id="1", angle="A")
        self.add("routine_recap", phase="B", recap_for=["1"])
        last = self.add("closing", "SKIPPED")
        self.campaign()["configuration"] = {"agent_mode": "autopilot"}  # autopilot drops the clip
        ok, reason = campaigns.library_ready(self.db.ai_idol_segments.docs)
        self.assertFalse(ok)
        self.assertIn("kết buổi", reason)
        with patch.object(campaigns, "_enqueue_recap"):
            self.assertIsNone(campaigns.update_campaign_from_job(last))
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")
        self.assertIn("kết buổi", self.campaign()["error_message"])

    def test_ready_library_goes_to_assembly(self):
        self.full_frame()
        self.add("product", product_id="1", angle="A")
        last = self.add("routine_recap", phase="B", recap_for=["1"])
        with patch.object(campaigns, "_enqueue_recap") as enqueue:
            self.assertEqual(campaigns.update_campaign_from_job(last), self.campaign_id)
        enqueue.assert_not_called()
        self.assertEqual(self.campaign()["status"], "GENERATING")


class RetryTests(Harness):
    def test_retry_failed_product_reruns_phase_b(self):
        self.full_frame()
        self.add("product", product_id="1", angle="A")
        self.add("product", product_id="1", angle="B")
        failed_job = self.add("product", "FAILED", product_id="2", angle="A")
        self.add("product", "SKIPPED", product_id="2", angle="B")
        self.add("routine_recap", phase="B", recap_for=["1"])
        self.db.ai_idol_campaigns.update_one({"_id": state_machine.object_id(self.campaign_id)},
                                             {"$set": {"status": "NEEDS_ATTENTION"}})
        queued = []
        result = campaigns.retry_campaign(self.campaign_id, enqueue_job=queued.append,
                                          enqueue_prepare=lambda c: None, enqueue_assemble=lambda c: None)
        self.assertEqual(result["queued_segments"], 1)
        self.assertEqual(queued, [failed_job])
        self.db.ai_idol_jobs.update_one({"_id": state_machine.object_id(failed_job)}, {"$set": {"status": "READY"}})
        with patch.object(campaigns, "_enqueue_recap") as enqueue:
            self.assertIsNone(campaigns.update_campaign_from_job(failed_job))
            enqueue.assert_called_once_with(self.campaign_id)
        job_id, captured = self.start_phase_b()
        self.assertTrue(job_id)
        self.assertEqual([p["number"] for p in captured["plan"]["products"]], [1, 2])
        recaps = self.segments(kind="routine_recap")
        self.assertEqual([s["status"] for s in recaps], ["CANCELLED", "GENERATING"])
        self.assertEqual(recaps[1]["recap_for"], ["1", "2"])

    def test_retry_requeues_failed_recap_writer(self):
        self.full_frame()
        self.add("product", product_id="1", angle="A")
        self.db.ai_idol_segments.insert_one({
            "campaign_id": self.campaign_id, "kind": "routine_recap", "phase": "B", "status": "FAILED",
            "job_id": None, "recap_for": ["1"], "position": 99})
        self.db.ai_idol_campaigns.update_one({"_id": state_machine.object_id(self.campaign_id)},
                                             {"$set": {"status": "NEEDS_ATTENTION"}})
        recaps = []
        result = campaigns.retry_campaign(self.campaign_id, enqueue_job=lambda j: None,
                                          enqueue_prepare=lambda c: None, enqueue_assemble=lambda c: None,
                                          enqueue_recap=recaps.append)
        self.assertEqual(recaps, [self.campaign_id])
        self.assertTrue(result["recap_queued"])
        self.assertEqual(self.segments(kind="routine_recap")[0]["status"], "CANCELLED")

    def test_writer_failure_leaves_failed_segment(self):
        self.full_frame()
        self.add("product", product_id="1", angle="A")
        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for), \
                patch.object(campaigns.show_writer, "write_routine_recap", side_effect=ValueError("boom")):
            self.assertIsNone(campaigns.start_phase_b(self.campaign_id))
        self.assertEqual(self.segments(kind="routine_recap")[0]["status"], "FAILED")
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")


class ClaimLeaseTests(Harness):
    def setup_one_product(self):
        self.full_frame()
        self.add("product", product_id="1", angle="A")

    def test_redelivery_after_crash_takes_over_stale_claim(self):
        self.setup_one_product()
        old = campaigns.utcnow() - dt.timedelta(minutes=20)
        self.campaign()["recap_claim"] = {"key": "1#0", "claimed_at": old}
        job_id, _ = self.start_phase_b()
        self.assertTrue(job_id)
        self.assertEqual(len(self.segments(kind="routine_recap")), 1)
        self.assertIsNone(self.campaign()["recap_claim"])

    def test_fresh_claim_blocks_duplicate_writer(self):
        self.setup_one_product()
        self.campaign()["recap_claim"] = {"key": "1#0", "claimed_at": campaigns.utcnow()}
        job_id, _ = self.start_phase_b()
        self.assertIsNone(job_id)
        self.assertEqual(self.segments(kind="routine_recap"), [])

    def test_create_job_failure_leaves_no_queued_segment_and_retry_recovers(self):
        self.setup_one_product()
        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for),                 patch.object(campaigns.show_writer, "write_routine_recap",
                             return_value=ClipDraft(kind="routine_recap", script="x")),                 patch.object(campaigns, "create_job", side_effect=RuntimeError("db down")):
            self.assertIsNone(campaigns.start_phase_b(self.campaign_id))
        recaps = self.segments(kind="routine_recap")
        self.assertEqual([s["status"] for s in recaps], ["FAILED"])
        self.assertIn("db down", recaps[0]["error_message"])
        self.assertIsNone(self.campaign()["recap_claim"])
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")
        queued = []
        result = campaigns.retry_campaign(self.campaign_id, enqueue_job=lambda j: None,
                                          enqueue_prepare=lambda c: None, enqueue_assemble=lambda c: None,
                                          enqueue_recap=queued.append)
        self.assertTrue(result["recap_queued"])
        job_id, _ = self.start_phase_b()
        self.assertTrue(job_id)

    def test_retry_breaks_a_blocked_claim(self):
        self.setup_one_product()
        self.campaign()["recap_claim"] = "1#0"  # a legacy string claim blocks the same key
        self.db.ai_idol_campaigns.update_one({"_id": state_machine.object_id(self.campaign_id)},
                                             {"$set": {"status": "NEEDS_ATTENTION"}})
        queued = []
        campaigns.retry_campaign(self.campaign_id, enqueue_job=lambda j: None, enqueue_prepare=lambda c: None,
                                 enqueue_assemble=lambda c: None, enqueue_recap=queued.append)
        self.assertEqual(queued, [self.campaign_id])
        self.assertTrue(self.start_phase_b()[0])

    def test_old_recap_stays_active_until_new_job_exists(self):
        self.setup_one_product()
        self.add("product", product_id="2", angle="A")
        self.add("routine_recap", phase="B", recap_for=["1"])
        seen = {}
        real = campaigns.create_job

        def spy(*args, **kwargs):
            seen["old"] = [s["status"] for s in self.segments(kind="routine_recap") if s.get("recap_for") == ["1"]]
            return real(*args, **kwargs)

        with patch.object(campaigns, "create_job", side_effect=spy):
            job_id, _ = self.start_phase_b()
        self.assertTrue(job_id)
        self.assertEqual(seen["old"], ["READY"])
        self.assertEqual([s["status"] for s in self.segments(kind="routine_recap")], ["CANCELLED", "GENERATING"])


class MinorFixTests(Harness):
    def test_library_update_waits_while_any_segment_in_flight(self):
        self.add("product", product_id="1", angle="A")
        self.add("opening", "GENERATING")
        self.add("routine_recap", phase="B", recap_for=["1"])
        plan = self.campaign()["show_plan"]
        self.assertIsNone(campaigns._library_update(self.db, self.campaign_id, plan, 0, 0, 0))
        self.assertEqual(self.campaign()["status"], "GENERATING")
        self.assertNotIn("error_message", self.campaign())

    def test_manual_mode_waits_while_a_product_is_failed(self):
        self.full_frame()
        self.add("product", product_id="1", angle="A")
        self.add("product", "FAILED", product_id="2", angle="A")
        self.db.ai_idol_campaigns.update_one({"_id": state_machine.object_id(self.campaign_id)},
                                             {"$set": {"status": "NEEDS_ATTENTION"}})
        job_id, _ = self.start_phase_b()
        self.assertIsNone(job_id)
        self.assertEqual(self.segments(kind="routine_recap"), [])
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")
        self.campaign()["configuration"] = {"agent_mode": "autopilot"}
        self.assertTrue(self.start_phase_b()[0])

    def test_discards_draft_when_campaign_cancelled_during_llm_call(self):
        self.full_frame()
        self.add("product", product_id="1", angle="A")

        def cancelling(plan, snapshots, **kwargs):
            self.campaign()["status"] = "CANCELLED"
            return ClipDraft(kind="routine_recap", script="x")

        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for),                 patch.object(campaigns.show_writer, "write_routine_recap", side_effect=cancelling):
            self.assertIsNone(campaigns.start_phase_b(self.campaign_id))
        self.assertEqual(self.segments(kind="routine_recap"), [])
        self.assertEqual(self.db.ai_idol_jobs.count_documents({"clip_kind": "routine_recap"}), 0)
        self.assertIsNone(self.campaign()["recap_claim"])

    def test_prepare_skips_cancelled_segments(self):
        self.add("opening", "CANCELLED")
        self.db.ai_idol_segments.docs[0]["job_id"] = None
        self.db.ai_idol_segments.docs[0]["script_text"] = "x"
        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for),                 patch.object(campaigns.show_plan, "build_show_plan", return_value=self.campaign()["show_plan"]):
            self.assertEqual(campaigns.prepare_campaign(self.campaign_id), [])
        self.assertEqual(self.db.ai_idol_segments.docs[0]["status"], "CANCELLED")
        self.assertIsNone(self.db.ai_idol_segments.docs[0]["job_id"])


class Round2Tests(Harness):
    def one_product(self):
        self.full_frame()
        self.add("product", product_id="1", angle="A")

    def retry(self, **kwargs):
        return campaigns.retry_campaign(
            self.campaign_id, enqueue_job=lambda j: None, enqueue_prepare=kwargs.get("prepare", lambda c: None),
            enqueue_assemble=lambda c: None, enqueue_recap=kwargs.get("recap", lambda c: None))

    def test_retry_while_recap_in_flight_does_not_raise(self):
        self.one_product()
        self.add("routine_recap", "GENERATING", phase="B", recap_for=["1"])
        result = self.retry()
        self.assertTrue(result["recap_in_flight"])

    def test_retry_after_recovery_with_recap_in_flight(self):
        self.one_product()
        self.campaign()["recap_claim"] = {"key": "1#0", "claimed_at": campaigns.utcnow() - dt.timedelta(minutes=20)}
        self.db.ai_idol_campaigns.update_one({"_id": state_machine.object_id(self.campaign_id)},
                                             {"$set": {"status": "NEEDS_ATTENTION"}})
        self.assertTrue(self.start_phase_b()[0])
        self.assertTrue(self.retry()["recap_in_flight"])

    def test_release_only_removes_own_lease(self):
        self.one_product()
        self.campaign()["recap_claim"] = {"key": "1#0", "claimed_at": campaigns.utcnow() - dt.timedelta(minutes=20)}
        newer = {"key": "1#0", "claimed_at": campaigns.utcnow()}

        def slow_writer(plan, snapshots, **kwargs):
            self.campaign()["recap_claim"] = dict(newer)  # worker B took over meanwhile
            return ClipDraft(kind="routine_recap", script="x")

        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for), \
                patch.object(campaigns.show_writer, "write_routine_recap", side_effect=slow_writer):
            campaigns.start_phase_b(self.campaign_id)
        self.assertEqual(self.campaign()["recap_claim"], newer)

    def test_prepare_creates_job_for_jobless_recap_segment(self):
        self.one_product()
        self.db.ai_idol_segments.insert_one({
            "campaign_id": self.campaign_id, "kind": "routine_recap", "phase": "B", "status": "QUEUED",
            "job_id": None, "recap_for": ["1"], "script_text": "Tóm tắt", "position": 50, "product_id": None})
        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for), \
                patch.object(campaigns.show_plan, "build_show_plan", return_value=self.campaign()["show_plan"]):
            job_ids = campaigns.prepare_campaign(self.campaign_id)
        recap = self.segments(kind="routine_recap")[0]
        self.assertEqual(recap["status"], "GENERATING")
        self.assertIn(recap["job_id"], job_ids)
        job = self.db.ai_idol_jobs.find_one({"_id": state_machine.object_id(recap["job_id"])})
        self.assertEqual(job["clip_kind"], "routine_recap")
        self.assertEqual(job["product_snapshot"]["bundle_total"], 100000)
        self.assertEqual(job["artifacts"]["prewritten_draft"], "Tóm tắt")

    def test_retry_queues_prepare_for_jobless_recap_segment(self):
        self.one_product()
        self.db.ai_idol_segments.insert_one({
            "campaign_id": self.campaign_id, "kind": "routine_recap", "phase": "B", "status": "QUEUED",
            "job_id": None, "recap_for": ["1"], "script_text": "x", "position": 50})
        queued = []
        result = self.retry(prepare=queued.append)
        self.assertEqual(queued, [self.campaign_id])
        self.assertTrue(result["prepare_queued"])

    def test_retry_leaves_fresh_lease_alone(self):
        self.one_product()
        fresh = {"key": "1#0", "claimed_at": campaigns.utcnow() - dt.timedelta(minutes=2)}
        self.campaign()["recap_claim"] = dict(fresh)
        queued = []
        result = self.retry(recap=queued.append)
        self.assertTrue(result["recap_in_flight"])
        self.assertEqual(queued, [])
        self.assertEqual(self.campaign()["recap_claim"], fresh)

    def test_retry_clears_stale_lease(self):
        self.one_product()
        self.campaign()["recap_claim"] = {"key": "1#0", "claimed_at": campaigns.utcnow() - dt.timedelta(minutes=30)}
        queued = []
        self.retry(recap=queued.append)
        self.assertEqual(queued, [self.campaign_id])
        self.assertIsNone(self.campaign()["recap_claim"])


class LegacyTests(Harness):
    def test_legacy_campaign_update_unchanged(self):
        self.db.ai_idol_campaigns.docs[0].pop("show_format")
        self.db.ai_idol_campaigns.docs[0].pop("show_plan")
        self.add("product", product_id="1")
        last = self.add("product", product_id="2")
        with patch.object(campaigns, "_enqueue_recap", create=True) as enqueue:
            self.assertEqual(campaigns.update_campaign_from_job(last), self.campaign_id)
        enqueue.assert_not_called()
        self.assertEqual(self.campaign()["progress"]["ready"], 2)
        self.assertEqual(len(self.db.ai_idol_segments.docs), 2)


if __name__ == "__main__":
    unittest.main()
