import os
import sys
import unittest
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from bson import ObjectId


from engines.script_validator import ScriptValidationResult, ValidationIssue
from tests.fakes import Collection, FakeDb

GOOD = "[Hook]\nChào cả nhà, hôm nay mình giới thiệu một sản phẩm dưỡng da rất nhẹ nhàng cho mọi người."
ISSUE = ValidationIssue("BLACKLIST_CLAIM", "HARD_FACT_ERROR", "error", None, "x", "bad claim", True)


def passing(script, repaired_to=None):
    text = repaired_to or script
    history = [{"version": 1, "text": script, "passed": repaired_to is None, "issues": []}]
    attempts = 0
    if repaired_to:
        history[0].update(issues=[ISSUE.to_dict()], repair={"to_version": 2, "issue_codes": ["BLACKLIST_CLAIM"]})
        history.append({"version": 2, "text": repaired_to, "passed": True, "issues": []})
        attempts = 1
    return ScriptValidationResult(True, text, [], history, attempts)


def failing(script):
    history = [{"version": v, "text": script, "passed": False, "issues": [ISSUE.to_dict()]} for v in (1, 2)]
    return ScriptValidationResult(False, script, [ISSUE], history, 1)


class RecoveryApiTests(unittest.TestCase):
    def setUp(self):
        try:  # celery is optional in the dev venv; the API only needs the task objects' .delay
            import celery  # noqa: F401
        except ImportError:
            import types
            from unittest.mock import MagicMock
            stub = types.ModuleType("tasks")
            stub.prepare_campaign_task = stub.generate_job_task = stub.process_agent_run_task = MagicMock()
            patcher = patch.dict(sys.modules, {"tasks": stub})
            patcher.start()
            self.addCleanup(patcher.stop)
            sys.modules.pop("app", None)
            self.addCleanup(sys.modules.pop, "app", None)
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        self.app = service_app
        self.client = service_app.app.test_client()
        self.job_id = ObjectId()
        self.seg_id = ObjectId()
        self.camp_id = ObjectId()

    def db(self, status="AUTO_REPAIR_EXHAUSTED", config=None, **job):
        base = {"_id": self.job_id, "status": status, "product_id": "7", "purpose": "live_only",
                "configuration": config or {"approval_mode": "MANUAL"}, "segment_id": str(self.seg_id),
                "campaign_id": str(self.camp_id), "product_snapshot": {},
                "artifacts": {"rejected_script_text": GOOD, "script_text": "" if status != "AWAITING_APPROVAL" else GOOD},
                **job}
        return FakeDb(
            ai_idol_jobs=Collection([base]),
            ai_idol_segments=Collection([{"_id": self.seg_id, "campaign_id": str(self.camp_id), "status": "FAILED"}]),
            ai_idol_campaigns=Collection([{"_id": self.camp_id, "status": "NEEDS_ATTENTION"}]))

    def post(self, db, path, body=None, validator=None):
        validator = validator or (lambda script, *a, **k: passing(script))
        with patch.object(self.app, "get_db", return_value=db), \
                patch("engines.script_validator.validate_and_repair_script", side_effect=validator), \
                patch.object(self.app.generate_job_task, "delay") as delay:
            response = self.client.post(f"/api/ai-idol/jobs/{self.job_id}/{path}", json=body or {})
        return response, delay

    def job(self, db):
        return db.ai_idol_jobs.docs[0]

    def test_approve_edit_passes_resumes_once_and_double_click_conflicts(self):
        db = self.db()
        first, delay = self.post(db, "script/approve", {"script_text": GOOD, "validation": {"passed": True}})
        self.assertEqual(first.status_code, 202)
        body = first.get_json()
        self.assertEqual((body["ok"], body["status"]), (True, "QUEUED"))
        delay.assert_called_once()
        self.assertEqual(self.job(db)["script_status"], "APPROVED")
        self.assertEqual(db.ai_idol_segments.docs[0]["status"], "GENERATING")
        self.assertEqual(db.ai_idol_campaigns.docs[0]["status"], "GENERATING")
        sources = [v["source"] for v in self.job(db)["artifacts"]["script_versions"]]
        self.assertEqual(sources, ["admin_edit"])
        second, delay2 = self.post(db, "script/approve", {"script_text": GOOD})
        self.assertEqual(second.status_code, 409)
        delay2.assert_not_called()

    def test_approve_edit_repaired_goes_to_awaiting(self):
        db = self.db("AWAITING_APPROVAL")
        fixed = GOOD + " Đã sửa."
        response, delay = self.post(db, "script/approve", {"script_text": GOOD},
                                    validator=lambda s, *a, **k: passing(s, repaired_to=fixed))
        body = response.get_json()
        self.assertEqual((response.status_code, body["status"], body["repaired"]), (200, "AWAITING_APPROVAL", True))
        self.assertEqual(self.job(db)["artifacts"]["script_text"], fixed)
        self.assertEqual(self.job(db)["current_stage"], "AWAITING_SCRIPT_APPROVAL")
        self.assertEqual(body["script_version"], 2)
        delay.assert_not_called()

    def test_approve_failing_edit_parks_exhausted_not_422(self):
        db = self.db("AWAITING_APPROVAL")
        response, delay = self.post(db, "script/approve", {"script_text": GOOD},
                                    validator=lambda s, *a, **k: failing(s))
        body = response.get_json()
        self.assertEqual((response.status_code, body["status"]), (200, "AUTO_REPAIR_EXHAUSTED"))
        self.assertEqual(body["issues"][0]["code"], "BLACKLIST_CLAIM")
        self.assertEqual(self.job(db)["artifacts"]["script_text"], "")
        self.assertEqual(db.ai_idol_segments.docs[0]["status"], "FAILED")
        delay.assert_not_called()

    def test_retry_repair_manual_awaits_and_auto_resumes(self):
        db = self.db()
        response, delay = self.post(db, "script/retry-repair")
        self.assertEqual(response.get_json()["status"], "AWAITING_APPROVAL")
        delay.assert_not_called()
        auto = self.db(config={"approval_mode": "AUTO"})
        response, delay = self.post(auto, "script/retry-repair")
        self.assertEqual((response.status_code, response.get_json()["status"]), (202, "QUEUED"))
        delay.assert_called_once()

    def test_retry_repair_wrong_state_conflicts(self):
        response, _ = self.post(self.db("READY"), "script/retry-repair")
        self.assertEqual(response.status_code, 409)

    def test_rewrite_validates_enum_and_runs(self):
        db = self.db("AWAITING_APPROVAL")
        bad, _ = self.post(db, "script/rewrite", {"instruction": "SPICY"})
        self.assertEqual(bad.status_code, 400)
        custom, _ = self.post(db, "script/rewrite", {"instruction": "CUSTOM", "custom_text": ""})
        self.assertEqual(custom.status_code, 400)
        self.assertEqual(self.job(db)["status"], "AWAITING_APPROVAL")
        with patch.object(self.app.script_recovery, "_rewrite", return_value=GOOD + " Mới.") as rewrite:
            ok, delay = self.post(db, "script/rewrite", {"instruction": "shorter"})
        self.assertEqual(ok.get_json()["status"], "AWAITING_APPROVAL")
        self.assertEqual(rewrite.call_args.args[2], "SHORTER")
        delay.assert_not_called()

    def test_rewrite_failure_parks_exhausted(self):
        db = self.db("AWAITING_APPROVAL")
        with patch.object(self.app.script_recovery, "_rewrite", return_value=GOOD):
            response, _ = self.post(db, "script/rewrite", {"instruction": "NATURAL"},
                                    validator=lambda s, *a, **k: failing(s))
        self.assertEqual(response.get_json()["status"], "AUTO_REPAIR_EXHAUSTED")

    def test_cancel(self):
        db = self.db()
        response, _ = self.post(db, "cancel")
        self.assertEqual(response.get_json()["status"], "CANCELLED")
        self.assertEqual(self.job(db)["status"], "CANCELLED")
        self.assertEqual(db.ai_idol_segments.docs[0]["status"], "CANCELLED")
        again, _ = self.post(db, "cancel")
        self.assertEqual(again.status_code, 409)


if __name__ == "__main__":
    unittest.main()
