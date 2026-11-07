"""Autopilot campaigns skip a product whose script fails moderation instead of stalling the whole programme."""
import os
import sys
import tempfile
import unittest
from unittest.mock import patch

from bson import ObjectId

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import core.agent_runs as agent_runs
import core.campaigns as campaigns

REJECTED = "VALIDATING_SCRIPT"
ALL_REJECTED = "Tất cả kịch bản đều trượt kiểm duyệt"
FEEDBACK = "Kịch bản không vượt qua kiểm duyệt: nêu công dụng điều trị"


def job_spec(status="READY", failed_stage=None, error_message=None, name=None, script_status=None):
    return {"status": status, "failed_stage": failed_stage, "error_message": error_message, "name": name,
            "script_status": script_status}


def rejected(error_message, name=None):
    """A job the script validator really rejected: pipeline stamps script_status before failing the job."""
    return job_spec("FAILED", REJECTED, error_message, name=name, script_status="REJECTED_BY_VALIDATOR")


class SkipHarness(unittest.TestCase):
    """FakeDb with one run, one campaign and one segment+job per spec; returns the ids as strings."""

    def setUp(self):
        self.db = FakeDb()
        for module in (campaigns, agent_runs):
            patcher = patch.object(module, "get_db", return_value=self.db)
            patcher.start()
            self.addCleanup(patcher.stop)
        self.run_id = ObjectId()
        self.db.ai_idol_agent_runs.insert_one({"_id": self.run_id, "status": "COMPLETED", "decisions": []})

    def build(self, specs, *, autopilot=True, purpose="product_video", status="GENERATING",
              segment_status="GENERATING", **campaign_extra):
        configuration = {"agent_mode": "autopilot", "agent_run_id": str(self.run_id)} if autopilot else {}
        campaign_id = ObjectId()
        self.db.ai_idol_campaigns.insert_one({
            "_id": campaign_id, "status": status, "purpose": purpose, "configuration": configuration,
            "platforms": ["internal"], "progress": {"total": len(specs), "ready": 0, "failed": 0},
            **campaign_extra,
        })
        self.campaign_id = str(campaign_id)
        self.job_ids = []
        for position, spec in enumerate(specs):
            job_id = ObjectId()
            self.db.ai_idol_jobs.insert_one({
                "_id": job_id, "campaign_id": self.campaign_id, "product_id": f"P{position}",
                "status": spec["status"], "failed_stage": spec["failed_stage"],
                "error_message": spec["error_message"], "script_status": spec["script_status"],
                "product_snapshot": {"name": spec["name"]} if spec["name"] else None,
            })
            self.db.ai_idol_segments.insert_one({
                "campaign_id": self.campaign_id, "product_id": f"P{position}", "position": position,
                "status": segment_status, "job_id": str(job_id),
            })
            self.job_ids.append(str(job_id))
        return self.campaign_id

    def campaign(self):
        return self.db.ai_idol_campaigns.find_one({"_id": ObjectId(self.campaign_id)})

    def segments(self):
        return sorted(self.db.ai_idol_segments.find({"campaign_id": self.campaign_id}), key=lambda s: s["position"])

    def segment_statuses(self):
        return [segment["status"] for segment in self.segments()]

    def decisions(self):
        return self.db.ai_idol_agent_runs.find_one({"_id": self.run_id})["decisions"]


class UpdateCampaignFromJobSkipTests(SkipHarness):
    def test_one_rejected_script_is_skipped(self):
        self.build([
            job_spec("READY"),
            rejected(FEEDBACK, name="Serum Retinol"),
            job_spec("READY"),
        ])
        first = campaigns.update_campaign_from_job(self.job_ids[0])
        second = campaigns.update_campaign_from_job(self.job_ids[1])
        self.assertIsNone(first)   # job 3 is still generating
        self.assertIsNone(second)  # ...and the skip alone does not finish the campaign
        last = campaigns.update_campaign_from_job(self.job_ids[2])

        self.assertEqual(self.segment_statuses(), ["READY", "SKIPPED", "READY"])
        self.assertEqual(self.segments()[1]["error_message"], FEEDBACK)
        self.assertEqual(self.campaign()["progress"], {"total": 3, "ready": 2, "failed": 0, "skipped": 1})
        self.assertEqual(self.campaign()["status"], "GENERATING")
        self.assertEqual(last, self.campaign_id)  # two ready clips: assemble / publish as usual

        campaigns.update_campaign_from_job(self.job_ids[1])  # a repeated callback must not repeat the decision
        decisions = self.decisions()
        self.assertEqual([item["code"] for item in decisions], ["SCRIPT_SKIPPED"])
        self.assertEqual(decisions[0]["level"], "adjusted")
        self.assertIn("Bỏ Serum Retinol: kịch bản trượt kiểm duyệt sau 3 lần tự sửa — ", decisions[0]["message"])
        self.assertIn("nêu công dụng điều trị", decisions[0]["message"])
        self.assertTrue(decisions[0]["at"])

    def test_skip_decision_names_the_product_by_code_when_name_is_unknown(self):
        self.build([rejected(FEEDBACK), job_spec("READY")])
        campaigns.update_campaign_from_job(self.job_ids[0])
        self.assertTrue(self.decisions()[0]["message"].startswith("Bỏ P0: kịch bản trượt kiểm duyệt"))

    def test_skip_decision_shortens_a_long_error_to_200_characters(self):
        self.build([rejected("x" * 900, name="A"), job_spec("READY")])
        campaigns.update_campaign_from_job(self.job_ids[0])
        message = self.decisions()[0]["message"]
        reason = message.split(" — ", 1)[1]
        self.assertEqual(len(reason), 200)
        self.assertTrue(reason.endswith("…"))

    def test_all_rejected_needs_attention(self):
        self.build([rejected(FEEDBACK) for _ in range(3)])
        results = [campaigns.update_campaign_from_job(job_id) for job_id in self.job_ids]
        self.assertEqual(results, [None, None, None])
        self.assertEqual(self.segment_statuses(), ["SKIPPED"] * 3)
        campaign = self.campaign()
        self.assertEqual(campaign["status"], "NEEDS_ATTENTION")
        self.assertEqual(campaign["error_message"], ALL_REJECTED)
        self.assertEqual(campaign["progress"]["skipped"], 3)
        self.assertEqual(campaign["progress"]["failed"], 0)
        self.assertEqual(len(self.decisions()), 3)

    def test_manual_campaign_keeps_failed(self):
        self.build([job_spec("READY"), rejected(FEEDBACK)], autopilot=False)
        campaigns.update_campaign_from_job(self.job_ids[0])
        result = campaigns.update_campaign_from_job(self.job_ids[1])
        self.assertIsNone(result)
        self.assertEqual(self.segment_statuses(), ["READY", "FAILED"])
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")
        self.assertEqual(self.campaign()["progress"]["failed"], 1)
        self.assertEqual(self.decisions(), [])

    def test_technical_failure_not_skipped(self):
        self.build([job_spec("READY"), job_spec("FAILED", "RENDERING_SYNA", "Syna render lỗi")])
        campaigns.update_campaign_from_job(self.job_ids[0])
        result = campaigns.update_campaign_from_job(self.job_ids[1])
        self.assertIsNone(result)
        self.assertEqual(self.segment_statuses(), ["READY", "FAILED"])
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")
        self.assertEqual(self.decisions(), [])

    def test_validation_timeout_is_not_skipped(self):
        # The watchdog fails a job that died during validation with failed_stage = VALIDATING_SCRIPT, but no
        # script was ever rejected (the pipeline never stamped script_status): that is a technical failure.
        timeout = job_spec("FAILED", REJECTED, "Quá 12 phút không có phản hồi ở bước VALIDATING_SCRIPT.")
        self.build([job_spec("READY"), timeout])
        campaigns.update_campaign_from_job(self.job_ids[0])
        result = campaigns.update_campaign_from_job(self.job_ids[1])
        self.assertIsNone(result)
        self.assertEqual(self.segment_statuses(), ["READY", "FAILED"])
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")
        self.assertEqual(self.campaign()["progress"]["skipped"], 0)
        self.assertEqual(self.decisions(), [])

    def test_in_flight_counts_are_read_before_the_settled_ones(self):
        # A concurrent callback moves segments in-flight -> settled; reading in-flight first means a stale
        # "0 ready" can never be combined with a fresh "0 generating" and end the campaign too early.
        self.build([job_spec("READY"), job_spec("READY")])
        seen = []
        original = self.db.ai_idol_segments.count_documents

        def spy(query):
            seen.append(query["status"])
            return original(query)

        with patch.object(self.db.ai_idol_segments, "count_documents", side_effect=spy):
            campaigns.update_campaign_from_job(self.job_ids[0])
        in_flight = [{"$in": ["QUEUED", "GENERATING"]}, "AWAITING_APPROVAL"]
        self.assertEqual(seen[:2], in_flight)
        self.assertEqual(sorted(seen[2:]), ["FAILED", "READY", "SKIPPED"])

    def test_a_failed_segment_still_blocks_even_when_another_is_skipped(self):
        self.build([
            job_spec("READY"),
            rejected(FEEDBACK),
            job_spec("FAILED", "RENDERING_SYNA", "Syna render lỗi"),
        ])
        results = [campaigns.update_campaign_from_job(job_id) for job_id in self.job_ids]
        self.assertEqual(results[-1], None)
        self.assertEqual(self.segment_statuses(), ["READY", "SKIPPED", "FAILED"])
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")
        self.assertNotEqual(self.campaign().get("error_message"), ALL_REJECTED)

    def test_cancelled_segment_is_never_skipped(self):
        self.build([rejected(FEEDBACK), job_spec("READY")], segment_status="CANCELLED")
        campaigns.update_campaign_from_job(self.job_ids[0])
        self.assertEqual(self.segment_statuses(), ["CANCELLED", "CANCELLED"])
        self.assertEqual(self.decisions(), [])

    def test_skip_without_a_run_id_still_skips_and_does_not_fail(self):
        self.build([rejected(FEEDBACK), job_spec("READY")])
        self.db.ai_idol_campaigns.update_one({"_id": ObjectId(self.campaign_id)},
                                             {"$set": {"configuration.agent_run_id": ""}})
        campaigns.update_campaign_from_job(self.job_ids[0])
        self.assertEqual(self.segment_statuses(), ["SKIPPED", "GENERATING"])
        self.assertEqual(self.decisions(), [])

    def test_skipped_segment_comes_back_when_its_job_finishes_after_a_manual_rescue(self):
        self.build([rejected(FEEDBACK), job_spec("READY")])
        campaigns.update_campaign_from_job(self.job_ids[0])
        self.assertEqual(self.segment_statuses()[0], "SKIPPED")
        self.db.ai_idol_jobs.update_one({"_id": ObjectId(self.job_ids[0])},
                                        {"$set": {"status": "READY", "failed_stage": None, "error_message": None}})
        campaigns.update_campaign_from_job(self.job_ids[0])
        self.assertEqual(self.segment_statuses()[0], "READY")
        self.assertEqual(self.campaign()["progress"]["skipped"], 0)


class AppendDecisionsTests(SkipHarness):
    def test_appends_normalised_decisions_in_order(self):
        agent_runs.append_decisions(str(self.run_id), [
            {"code": "A", "level": "done", "message": "Một"},
            {"code": "B", "level": "adjusted", "message": "Hai", "at": "2026-10-15T01:02:03+00:00"},
        ])
        agent_runs.append_decisions(str(self.run_id), [{"code": "C", "level": "waiting", "message": "Ba"}])
        decisions = self.decisions()
        self.assertEqual([item["code"] for item in decisions], ["A", "B", "C"])
        self.assertEqual(decisions[1]["at"], "2026-10-15T01:02:03+00:00")
        self.assertTrue(decisions[0]["at"].endswith("+00:00"))

    def test_messages_are_redacted(self):
        agent_runs.append_decisions(str(self.run_id), [
            {"code": "A", "level": "done", "message": "đẩy tới rtmp://a.rtmp.youtube.com/live2/SECRETKEY-1234 xong"},
        ])
        self.assertNotIn("SECRETKEY", str(self.decisions()))

    def test_blank_malformed_or_unknown_run_is_ignored(self):
        item = [{"code": "A", "level": "done", "message": "x"}]
        for run_id in ("", None, "not-an-object-id", str(ObjectId())):
            agent_runs.append_decisions(run_id, item)
        self.assertEqual(self.decisions(), [])
        self.assertEqual(len(self.db.ai_idol_agent_runs.docs), 1)

    def test_empty_list_is_a_no_op(self):
        agent_runs.append_decisions(str(self.run_id), [])
        self.assertEqual(self.db.ai_idol_agent_runs.writes, [])


class PrepareCancelResumeRetryWithSkippedTests(SkipHarness):
    def test_prepare_ignores_skipped(self):
        self.build([job_spec("READY"), rejected(FEEDBACK)], segment_status="GENERATING")
        self.db.ai_idol_segments.update_one({"job_id": self.job_ids[1]}, {"$set": {"status": "SKIPPED"}})
        self.db.ai_idol_segments.update_one({"job_id": self.job_ids[0]}, {"$set": {"status": "READY"}})
        self.db.ai_idol_segments.insert_one({
            "campaign_id": self.campaign_id, "product_id": "P2", "position": 2, "status": "QUEUED", "job_id": None,
        })
        new_job_id = str(ObjectId())
        with patch.object(campaigns, "collect_product_and_snapshot", return_value={"name": "Mới"}), \
                patch.object(campaigns, "create_job", return_value=new_job_id) as create:
            job_ids = campaigns.prepare_campaign(self.campaign_id)
        self.assertEqual(job_ids, [new_job_id])  # neither the READY job nor the skipped one is generated again
        create.assert_called_once()
        self.assertEqual(self.segment_statuses(), ["READY", "SKIPPED", "GENERATING"])
        self.assertEqual(self.campaign()["status"], "GENERATING")

    def test_prepare_with_only_skipped_segments_explains_why(self):
        self.build([rejected(FEEDBACK)], segment_status="SKIPPED")
        with patch.object(campaigns, "create_job") as create:
            job_ids = campaigns.prepare_campaign(self.campaign_id)
        self.assertEqual(job_ids, [])
        create.assert_not_called()
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")
        self.assertEqual(self.campaign()["error_message"], ALL_REJECTED)

    def test_cancel_keeps_skipped(self):
        self.build([job_spec("READY"), rejected(FEEDBACK), job_spec("QUEUED")])
        for job_id, status in zip(self.job_ids, ("READY", "SKIPPED", "GENERATING")):
            self.db.ai_idol_segments.update_one({"job_id": job_id}, {"$set": {"status": status}})
        result = campaigns.cancel_campaign(self.campaign_id)
        self.assertEqual(result, {"status": "CANCELLED", "cancelled_segments": 1})
        self.assertEqual(self.segment_statuses(), ["READY", "SKIPPED", "CANCELLED"])
        self.assertEqual(self.campaign()["status"], "CANCELLED")

    def test_resume_with_skipped(self):
        self.build([job_spec("READY"), job_spec("READY"), rejected(FEEDBACK)],
                   purpose="live_and_product")
        for job_id, status in zip(self.job_ids, ("READY", "READY", "SKIPPED")):
            self.db.ai_idol_segments.update_one({"job_id": job_id}, {"$set": {"status": status}})
        self.assertTrue(campaigns.resume_ready_campaign(self.campaign_id))
        self.assertEqual(self.campaign()["status"], "ASSEMBLING")

    def test_resume_does_not_assemble_when_nothing_is_ready_or_work_remains(self):
        self.build([rejected(FEEDBACK)], purpose="live_and_product", segment_status="SKIPPED")
        self.assertFalse(campaigns.resume_ready_campaign(self.campaign_id))
        self.assertEqual(self.campaign()["status"], "GENERATING")
        self.db.ai_idol_segments.insert_one({"campaign_id": self.campaign_id, "position": 1, "status": "READY"})
        self.db.ai_idol_segments.insert_one({"campaign_id": self.campaign_id, "position": 2, "status": "GENERATING"})
        self.assertFalse(campaigns.resume_ready_campaign(self.campaign_id))

    def test_reschedule_is_not_blocked_by_skipped(self):
        with tempfile.NamedTemporaryFile(suffix=".mp4") as master:
            self.build([job_spec("READY"), rejected(FEEDBACK)], purpose="live_and_product",
                       status="NEEDS_ATTENTION", master_video_path=master.name)
            for job_id, status in zip(self.job_ids, ("READY", "SKIPPED")):
                self.db.ai_idol_segments.update_one({"job_id": job_id}, {"$set": {"status": status}})
            result = campaigns.reschedule_campaign(self.campaign_id, "now")
        self.assertEqual(result["status"], "SCHEDULED")

    def test_retry_product_video_with_skipped_marks_ready(self):
        self.build([job_spec("READY"), rejected(FEEDBACK)], status="NEEDS_ATTENTION")
        for job_id, status in zip(self.job_ids, ("READY", "SKIPPED")):
            self.db.ai_idol_segments.update_one({"job_id": job_id}, {"$set": {"status": status}})
        queued = []
        result = campaigns.retry_campaign(self.campaign_id, enqueue_job=queued.append,
                                          enqueue_prepare=queued.append, enqueue_assemble=queued.append)
        self.assertTrue(result.get("videos_ready"))
        self.assertEqual(queued, [])
        self.assertEqual(self.campaign()["status"], "VIDEOS_READY")

    def test_retry_livestream_with_skipped_queues_assembly(self):
        self.build([job_spec("READY"), rejected(FEEDBACK)], purpose="live_and_product",
                   status="NEEDS_ATTENTION")
        for job_id, status in zip(self.job_ids, ("READY", "SKIPPED")):
            self.db.ai_idol_segments.update_one({"job_id": job_id}, {"$set": {"status": status}})
        assembled = []
        result = campaigns.retry_campaign(self.campaign_id, enqueue_job=lambda *_: None,
                                          enqueue_prepare=lambda *_: None, enqueue_assemble=assembled.append)
        self.assertTrue(result["assembly_queued"])
        self.assertEqual(assembled, [self.campaign_id])

    def test_retry_never_requeues_skipped_segments(self):
        self.build([rejected(FEEDBACK), job_spec("FAILED", "RENDERING_SYNA", "lỗi")],
                   status="NEEDS_ATTENTION")
        for job_id, status in zip(self.job_ids, ("SKIPPED", "FAILED")):
            self.db.ai_idol_segments.update_one({"job_id": job_id}, {"$set": {"status": status}})
        queued = []
        result = campaigns.retry_campaign(self.campaign_id, enqueue_job=queued.append,
                                          enqueue_prepare=lambda *_: None, enqueue_assemble=lambda *_: None)
        self.assertEqual(result["queued_segments"], 1)
        self.assertEqual(queued, [self.job_ids[1]])
        self.assertEqual(self.segment_statuses(), ["SKIPPED", "GENERATING"])

    def test_retry_with_only_skipped_segments_is_not_mistaken_for_all_ready(self):
        self.build([rejected(FEEDBACK)], status="NEEDS_ATTENTION", segment_status="SKIPPED")
        with self.assertRaises(campaigns.CampaignConflict) as raised:
            campaigns.retry_campaign(self.campaign_id, enqueue_job=lambda *_: None,
                                     enqueue_prepare=lambda *_: None, enqueue_assemble=lambda *_: None)
        self.assertEqual(
            str(raised.exception),
            "Tất cả kịch bản đều trượt kiểm duyệt nên không có phần nào để tạo lại. "
            "Hãy tạo chương trình mới với yêu cầu khác.",
        )
        self.assertEqual(self.campaign()["status"], "NEEDS_ATTENTION")


if __name__ == "__main__":
    unittest.main()
