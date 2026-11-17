"""Groq token-quota (429) handling: LLMRateLimited, script stage parking, WAITING_LLM resume."""
import datetime as dt
import os
import sys
import unittest
from types import SimpleNamespace
from unittest.mock import MagicMock, patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from bson import ObjectId

import core.watchdog as watchdog
import engines.script_selector as selector
import engines.script_validator as sv
from providers.llm_provider import LLMProvider, LLMRateLimited, parse_retry_after
from tests.fakes import Collection, FakeDb
from tests.test_e2e_flow import Harness  # wires one FakeDb into every pipeline module

NOW = dt.datetime(2026, 10, 13, 12, 0, tzinfo=dt.timezone.utc)
RL_A = ("Error code: 429 - {'error': {'message': 'Rate limit reached for model x on tokens per day (TPD): "
        "Limit 200000. Please try again in 7m5.087999999s.', 'code': 'rate_limit_exceeded'}}")
RL_B = ("Error code: 429 - {'error': {'message': 'Rate limit reached for model y. "
        "Please try again in 14m14.9s.', 'code': 'rate_limit_exceeded'}}")


def provider(*errors):
    """LLMProvider whose client raises the given exceptions in order (one per model)."""
    queue = list(errors)

    def create(**options):
        raise queue.pop(0)

    llm = LLMProvider.__new__(LLMProvider)
    llm.pool = [SimpleNamespace(chat=SimpleNamespace(completions=SimpleNamespace(create=create)))]
    llm.models = ["m1", "m2"][:len(errors)]
    return llm


class ParseRetryAfterTests(unittest.TestCase):
    def test_formats(self):
        self.assertAlmostEqual(parse_retry_after("Please try again in 7m5.08s."), 425.08)
        self.assertEqual(parse_retry_after("try again in 1h2m3s"), 3723)
        self.assertEqual(parse_retry_after("try again in 45.5s"), 45.5)
        self.assertAlmostEqual(parse_retry_after("try again in 900ms"), 0.9)
        self.assertIsNone(parse_retry_after("no hint here"))


class ProviderRateLimitTests(unittest.TestCase):
    def test_all_models_429_raise_rate_limited_with_min_wait(self):
        with self.assertRaises(LLMRateLimited) as ctx:
            provider(RuntimeError(RL_A), RuntimeError(RL_B)).invoke("s", "u")
        self.assertAlmostEqual(ctx.exception.retry_after_seconds, 425.088, places=2)
        self.assertTrue(str(ctx.exception).startswith("Groq is unavailable: "))
        self.assertIsInstance(ctx.exception, RuntimeError)

    def test_structured_all_429_and_prefix(self):
        with self.assertRaises(LLMRateLimited) as ctx:
            provider(RuntimeError(RL_B), RuntimeError(RL_B)).invoke_json_schema(
                "s", "u", schema={"type": "object"}, schema_name="x")
        self.assertTrue(str(ctx.exception).startswith("Groq structured output is unavailable: "))
        self.assertAlmostEqual(ctx.exception.retry_after_seconds, 854.9, places=1)

    def test_default_wait_when_unparseable(self):
        class RateLimitError(Exception):
            status_code = 429

        with self.assertRaises(LLMRateLimited) as ctx:
            provider(RateLimitError("slow down")).invoke("s", "u")
        self.assertEqual(ctx.exception.retry_after_seconds, 300)

    def test_mixed_errors_stay_plain_runtime_error(self):
        with self.assertRaises(RuntimeError) as ctx:
            provider(RuntimeError(RL_A), RuntimeError("500 server error")).invoke("s", "u")
        self.assertNotIsInstance(ctx.exception, LLMRateLimited)
        self.assertTrue(str(ctx.exception).startswith("Groq is unavailable: "))

    def test_missing_key_is_plain_runtime_error(self):
        llm = LLMProvider.__new__(LLMProvider)
        llm.pool, llm.models = [], ["m"]
        with self.assertRaises(RuntimeError) as ctx:
            llm.invoke("s", "u")
        self.assertNotIsInstance(ctx.exception, LLMRateLimited)
        self.assertIn("GROQ_API_KEY is missing", str(ctx.exception))


class SelectorRateLimitTests(unittest.TestCase):
    def fake(self, outcomes):
        queue = list(outcomes)

        def factory():
            inst = MagicMock()
            inst.models = ["m0", "m1"]
            outcome = queue.pop(0)

            def invoke(system, user):
                if isinstance(outcome, Exception):
                    raise outcome
                return outcome

            inst.invoke.side_effect = invoke
            return inst

        return factory

    def test_all_candidates_rate_limited(self):
        outcomes = [LLMRateLimited("a", 900), LLMRateLimited("b", 120)]
        with patch.object(selector, "LLMProvider", self.fake(outcomes)):
            with self.assertRaises(LLMRateLimited) as ctx:
                selector._generate("s", "u", 2)
        self.assertEqual(ctx.exception.retry_after_seconds, 120)
        self.assertTrue(str(ctx.exception).startswith("All script candidates failed: "))

    def test_mixed_failures_stay_runtime_error(self):
        with patch.object(selector, "LLMProvider", self.fake([LLMRateLimited("a", 1), RuntimeError("boom")])):
            with self.assertRaises(RuntimeError) as ctx:
                selector._generate("s", "u", 2)
        self.assertNotIsInstance(ctx.exception, LLMRateLimited)

    def test_one_ok_returns_drafts(self):
        with patch.object(selector, "LLMProvider", self.fake([LLMRateLimited("a", 1), "Kịch bản ok"])):
            drafts, indexes = selector._generate("s", "u", 2)
        self.assertEqual((drafts, indexes), (["Kịch bản ok"], [1]))


class ValidatorRateLimitTests(unittest.TestCase):
    def test_audit_rate_limit_propagates_and_is_not_approval(self):
        class LLM:
            def invoke(self, system, user):
                raise LLMRateLimited("quota", 60)

        script = "[Hook]\nXin chào các bạn.\n[Wrap-up]\nBấm vào sản phẩm nhé."
        with patch.object(sv, "LLMProvider", LLM), patch.object(sv, "RELAXED_AUDIT", False):
            with self.assertRaises(LLMRateLimited):
                sv.validate_and_repair_script(script, {"name": "Kem", "brand": "B"}, "product_video")

    def test_plain_audit_failure_still_fails_closed(self):
        class LLM:
            def invoke(self, system, user):
                raise RuntimeError("down")

        with patch.object(sv, "LLMProvider", LLM):
            issues = sv._llm_audit_issues("[Hook]\nXin chào.", {"name": "Kem"}, "live_only")
        self.assertEqual([i.code for i in issues], ["VALIDATOR_UNAVAILABLE"])


class PipelineParkingTests(unittest.TestCase):
    def legacy_job(self, h):
        from core.campaigns import prepare_campaign
        campaign_id = str(h.db.ai_idol_campaigns.insert_one({
            "status": "GENERATING", "purpose": "live_only", "product_ids": ["93"],
            "content_mode": "Product Intro", "progress": {"total": 1},
            "configuration": {"approval_mode": "AUTO", "avatar_mode": "syna_3d"}}).inserted_id)
        h.db.ai_idol_segments.insert_one({
            "campaign_id": campaign_id, "product_id": "93", "position": 0, "status": "QUEUED", "job_id": None})
        return prepare_campaign(campaign_id)

    def stub_validator(self, h, side_effect=None):
        stub = sys.modules["engines.script_validator"]
        real = stub.validate_and_repair_script
        self.addCleanup(setattr, stub, "validate_and_repair_script", real)
        if side_effect is not None:
            stub.validate_and_repair_script = MagicMock(side_effect=side_effect)
        return stub, real

    def test_rate_limit_parks_job_then_resume_reuses_pending_draft(self):
        h = Harness(self)
        stub, real = self.stub_validator(h, LLMRateLimited("quota", 20))
        h.generate_calls.extend(self.legacy_job(h))
        h.generate_all()
        job = h.jobs()[0]
        self.assertEqual(job["status"], "WAITING_LLM")
        self.assertIsNone(job["failed_stage"])
        self.assertTrue(job["artifacts"]["pending_draft"])
        self.assertEqual(len(h.llm_scripts), 1)
        self.assertIn("Groq", job["error_message"])
        wait = (job["llm_retry_at"] - job["waiting_llm_since"]).total_seconds()
        self.assertGreaterEqual(wait, 59)  # clamped up to the 60 s floor
        self.assertLessEqual(wait, 70)
        since = job["waiting_llm_since"]

        # Quota is back: resume must not call the LLM writer again.
        stub.validate_and_repair_script = real
        h.generate_calls.append(str(job["_id"]))
        h.generate_all()
        job = h.jobs()[0]
        self.assertEqual(job["status"], "READY")
        self.assertEqual(len(h.llm_scripts), 1)
        self.assertIsNone(job["artifacts"].get("pending_draft"))
        self.assertEqual(job["waiting_llm_since"], since)  # the 48 h cap spans re-parks

    def test_other_errors_still_fail_the_job(self):
        h = Harness(self)
        self.stub_validator(h, RuntimeError("boom"))
        h.generate_calls.extend(self.legacy_job(h))
        h.generate_all()
        self.assertEqual(h.jobs()[0]["status"], "FAILED")


class ResumeWaitingLlmTests(unittest.TestCase):
    def build(self, jobs, minutes_to_air=600):
        campaign_id = ObjectId()
        docs = []
        for extra in jobs:
            docs.append({"_id": ObjectId(), "status": "WAITING_LLM", "campaign_id": str(campaign_id),
                         "waiting_llm_since": NOW - dt.timedelta(hours=1), **extra})
        return FakeDb(
            ai_idol_campaigns=Collection([{"_id": campaign_id, "status": "GENERATING",
                                           "scheduled_at": NOW + dt.timedelta(minutes=minutes_to_air)}]),
            ai_idol_jobs=Collection(docs),
            ai_idol_segments=Collection([]),
        )

    def sweep(self, db, batch=2):
        enqueue = MagicMock()
        with patch.object(watchdog, "get_db", return_value=db), \
                patch.object(watchdog, "LLM_RESUME_BATCH", batch), \
                patch.object(watchdog, "update_campaign_from_job") as update:
            result = watchdog.resume_waiting_llm(enqueue, NOW)
        return result, enqueue, update

    def statuses(self, db):
        return [j["status"] for j in db.ai_idol_jobs.docs]

    def test_due_job_is_resumed_and_keeps_waiting_since(self):
        db = self.build([{"llm_retry_at": NOW - dt.timedelta(seconds=1)}])
        result, enqueue, _ = self.sweep(db)
        self.assertEqual(result, {"resumed": 1, "failed": 0, "waiting": 0})
        enqueue.assert_called_once_with(str(db.ai_idol_jobs.docs[0]["_id"]))
        self.assertEqual(self.statuses(db), ["QUEUED"])
        self.assertIn("waiting_llm_since", db.ai_idol_jobs.docs[0])

    def test_missing_retry_at_counts_as_due(self):
        db = self.build([{}])
        self.assertEqual(self.sweep(db)[0]["resumed"], 1)

    def test_not_due_keeps_waiting(self):
        db = self.build([{"llm_retry_at": NOW + dt.timedelta(minutes=5)}])
        result, enqueue, _ = self.sweep(db)
        self.assertEqual(result, {"resumed": 0, "failed": 0, "waiting": 1})
        enqueue.assert_not_called()
        self.assertEqual(self.statuses(db), ["WAITING_LLM"])

    def test_batch_limit_earliest_first(self):
        def past(minutes):
            return {"llm_retry_at": NOW - dt.timedelta(minutes=minutes)}

        db = self.build([past(1), past(30), past(10), past(5)])
        result, enqueue, _ = self.sweep(db, batch=2)
        self.assertEqual(result["resumed"], 2)
        self.assertEqual(result["waiting"], 2)
        resumed = {c.args[0] for c in enqueue.call_args_list}
        oldest = {str(db.ai_idol_jobs.docs[1]["_id"]), str(db.ai_idol_jobs.docs[2]["_id"])}
        self.assertEqual(resumed, oldest)

    def test_waiting_longer_than_cap_fails(self):
        db = self.build([{"waiting_llm_since": NOW - dt.timedelta(hours=49),
                          "llm_retry_at": NOW - dt.timedelta(minutes=1)}])
        result, enqueue, update = self.sweep(db)
        self.assertEqual(result["failed"], 1)
        enqueue.assert_not_called()
        update.assert_called_once_with(str(db.ai_idol_jobs.docs[0]["_id"]))
        job = db.ai_idol_jobs.docs[0]
        self.assertEqual((job["status"], job["failed_stage"]), ("FAILED", "WAITING_LLM"))
        self.assertIn("48", job["error_message"])

    def test_near_airtime_fails(self):
        db = self.build([{"llm_retry_at": NOW - dt.timedelta(minutes=1)}], minutes_to_air=5)
        result, enqueue, update = self.sweep(db)
        self.assertEqual(result["failed"], 1)
        enqueue.assert_not_called()
        update.assert_called_once()
        self.assertEqual(self.statuses(db), ["FAILED"])
        for _, query, _update in db.ai_idol_jobs.writes:
            self.assertEqual(query["status"], "WAITING_LLM")


if __name__ == "__main__":
    unittest.main()
