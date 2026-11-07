"""Autopilot: one-click approval that lets a campaign stream to YouTube/Facebook."""
import copy
import os
import sys
import unittest
from unittest.mock import patch

from bson import ObjectId

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import Collection, FakeDb

import core.agent_runs as agent_runs
import core.campaigns as campaigns

RTMP = {"youtube": "rtmp://a.rtmp.youtube.com/live2/SECRETKEY-1234"}


class PlatformApprovalTests(unittest.TestCase):
    def setUp(self):
        self.campaign_id = ObjectId()
        self.run_id = ObjectId()

    def build(self, status="SCHEDULED", platforms=("internal",), pending=("youtube",), run_id="run"):
        configuration = {"agent_run_id": str(self.run_id) if run_id == "run" else run_id}
        return FakeDb(
            ai_idol_campaigns=Collection([{
                "_id": self.campaign_id, "status": status, "platforms": list(platforms),
                "pending_external_platforms": list(pending), "configuration": configuration,
            }]),
            ai_idol_agent_runs=Collection([{"_id": self.run_id, "decisions": []}]),
        )

    def approve(self, db, platform="youtube", by="admin", rtmp=RTMP):
        with patch.object(campaigns, "get_db", return_value=db), patch.object(agent_runs, "get_db", return_value=db), \
                patch.object(campaigns, "RTMP_TARGETS", rtmp):
            return campaigns.approve_external_platform(str(self.campaign_id), platform, by)

    def test_approve_moves_platform(self):
        db = self.build()
        result = self.approve(db, by="admin@example.com")
        self.assertEqual(result, {"platforms": ["internal", "youtube"], "pending_external_platforms": []})
        stored = db.ai_idol_campaigns.docs[0]
        self.assertEqual(stored["platforms"], ["internal", "youtube"])
        self.assertEqual(stored["pending_external_platforms"], [])
        self.assertEqual(len(stored["platform_approvals"]), 1)
        approval = stored["platform_approvals"][0]
        self.assertEqual((approval["platform"], approval["by"]), ("youtube", "admin@example.com"))
        self.assertIn("at", approval)
        decisions = db.ai_idol_agent_runs.docs[0]["decisions"]
        self.assertEqual([(item["code"], item["level"], item["message"]) for item in decisions],
                         [("PLATFORM_APPROVED", "done", "Đã cho phép phát YouTube.")])

    def test_approve_keeps_the_other_pending_platform(self):
        db = self.build(pending=("youtube", "facebook"))
        result = self.approve(db, "facebook", rtmp={**RTMP, "facebook": "rtmps://x/FBSECRET-5678"})
        self.assertEqual(result, {"platforms": ["internal", "facebook"], "pending_external_platforms": ["youtube"]})
        self.assertEqual(db.ai_idol_agent_runs.docs[0]["decisions"][0]["message"], "Đã cho phép phát Facebook.")

    def test_approve_twice_is_noop(self):
        db = self.build()
        first = self.approve(db)
        snapshot = copy.deepcopy(db.ai_idol_campaigns.docs[0])
        writes = len(db.ai_idol_campaigns.writes)
        second = self.approve(db)
        self.assertEqual(second, first)
        self.assertEqual(db.ai_idol_campaigns.docs[0], snapshot)
        self.assertEqual(len(db.ai_idol_campaigns.writes), writes)
        self.assertEqual(len(db.ai_idol_agent_runs.docs[0]["decisions"]), 1)

    def test_already_streaming_platform_is_noop_even_after_airtime(self):
        db = self.build(status="LIVE", platforms=("internal", "youtube"), pending=())
        result = self.approve(db)
        self.assertEqual(result, {"platforms": ["internal", "youtube"], "pending_external_platforms": []})
        self.assertEqual(db.ai_idol_campaigns.writes, [])

    def test_not_pending_rejected(self):
        for platform in ("facebook", "tiktok", ""):
            db = self.build(pending=("youtube",))
            with self.assertRaisesRegex(ValueError, "Kênh này không chờ cho phép.") as caught:
                self.approve(db, platform, rtmp={**RTMP, "facebook": "rtmps://x/FBSECRET-5678"})
            self.assertNotIsInstance(caught.exception, campaigns.CampaignConflict)
            self.assertEqual(db.ai_idol_campaigns.docs[0]["platforms"], ["internal"])
            self.assertEqual(db.ai_idol_agent_runs.docs[0]["decisions"], [])

    def test_unconfigured_rejected(self):
        db = self.build()
        with self.assertRaisesRegex(ValueError, "Chưa cấu hình RTMP cho YouTube.") as caught:
            self.approve(db, rtmp={})
        self.assertNotIsInstance(caught.exception, campaigns.CampaignConflict)
        self.assertNotIn("SECRETKEY", str(caught.exception))
        stored = db.ai_idol_campaigns.docs[0]
        self.assertEqual((stored["platforms"], stored["pending_external_platforms"]), (["internal"], ["youtube"]))
        self.assertEqual(db.ai_idol_agent_runs.docs[0]["decisions"], [])

    def test_live_campaign_conflict(self):
        for status in ("STARTING", "LIVE", "COMPLETED", "CANCELLED", "FAILED"):
            db = self.build(status=status)
            with self.assertRaisesRegex(
                campaigns.CampaignConflict, "Chương trình đã lên sóng hoặc kết thúc; không thể thêm kênh.",
            ):
                self.approve(db)
            stored = db.ai_idol_campaigns.docs[0]
            self.assertEqual((stored["platforms"], stored["pending_external_platforms"]), (["internal"], ["youtube"]))
            self.assertEqual(db.ai_idol_agent_runs.docs[0]["decisions"], [])

    def test_every_approvable_status_accepts_the_platform(self):
        self.assertEqual(
            campaigns.APPROVABLE_STATUSES,
            ("VALIDATING", "GENERATING", "AWAITING_APPROVAL", "NEEDS_ATTENTION", "SCHEDULED"),
        )
        for status in campaigns.APPROVABLE_STATUSES:
            db = self.build(status=status)
            self.assertEqual(self.approve(db)["platforms"], ["internal", "youtube"], status)

    def test_status_changes_between_read_and_write_is_a_conflict(self):
        db = self.build()
        original = db.ai_idol_campaigns.find_one_and_update

        def go_live_first(query, update, **kwargs):
            db.ai_idol_campaigns.docs[0]["status"] = "LIVE"
            return original(query, update, **kwargs)

        with patch.object(db.ai_idol_campaigns, "find_one_and_update", side_effect=go_live_first), \
                self.assertRaises(campaigns.CampaignConflict):
            self.approve(db)
        self.assertEqual(db.ai_idol_campaigns.docs[0]["platforms"], ["internal"])
        self.assertEqual(db.ai_idol_agent_runs.docs[0]["decisions"], [])

    def test_platform_approved_by_another_request_in_between_is_a_noop(self):
        db = self.build()
        original = db.ai_idol_campaigns.find_one_and_update

        def approved_elsewhere_first(query, update, **kwargs):
            stored = db.ai_idol_campaigns.docs[0]
            stored["platforms"].append("youtube")
            stored["pending_external_platforms"].remove("youtube")
            return original(query, update, **kwargs)

        with patch.object(db.ai_idol_campaigns, "find_one_and_update", side_effect=approved_elsewhere_first):
            result = self.approve(db)
        self.assertEqual(result, {"platforms": ["internal", "youtube"], "pending_external_platforms": []})
        self.assertNotIn("platform_approvals", db.ai_idol_campaigns.docs[0])
        self.assertEqual(db.ai_idol_agent_runs.docs[0]["decisions"], [])

    def test_unknown_campaign_not_found(self):
        with patch.object(campaigns, "get_db", return_value=FakeDb()), \
                self.assertRaises(campaigns.CampaignNotFound):
            campaigns.approve_external_platform(str(ObjectId()), "youtube", "admin")

    def test_campaign_without_agent_run_still_approves(self):
        for run_id in ("", None, "not-an-object-id"):
            db = self.build(run_id=run_id)
            self.assertEqual(self.approve(db)["platforms"], ["internal", "youtube"])
            self.assertEqual(db.ai_idol_agent_runs.docs[0]["decisions"], [])

    def test_blank_approver_is_recorded_as_admin(self):
        db = self.build()
        self.approve(db, by="  ")
        self.assertEqual(db.ai_idol_campaigns.docs[0]["platform_approvals"][0]["by"], "admin")

    def test_route_status_codes(self):
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        client = service_app.app.test_client()
        url = "/api/ai-idol/campaigns/abc/platforms/approve"
        state = {"platforms": ["internal", "youtube"], "pending_external_platforms": []}
        with patch.object(service_app, "approve_external_platform", return_value=state) as approve:
            response = client.post(url, json={"platform": "youtube", "by": "admin@example.com"})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.get_json(), {"ok": True, "data": state})
        approve.assert_called_once_with("abc", "youtube", "admin@example.com")

        cases = (
            (campaigns.CampaignNotFound("Campaign not found"), 404),
            (campaigns.CampaignConflict("Chương trình đã lên sóng hoặc kết thúc; không thể thêm kênh."), 409),
            (ValueError("Kênh này không chờ cho phép."), 400),
        )
        for error, status in cases:
            with patch.object(service_app, "approve_external_platform", side_effect=error):
                response = client.post(url, json={"platform": "youtube", "by": "admin"})
            self.assertEqual(response.status_code, status, error)
            body = response.get_json()
            self.assertIs(body["ok"], False)
            self.assertEqual(body["message"], str(error))

    def test_route_tolerates_missing_body(self):
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        client = service_app.app.test_client()
        with patch.object(service_app, "approve_external_platform", side_effect=ValueError("Kênh này không chờ cho phép.")) as approve:
            response = client.post("/api/ai-idol/campaigns/abc/platforms/approve")
        self.assertEqual(response.status_code, 400)
        approve.assert_called_once_with("abc", "", "")


if __name__ == "__main__":
    unittest.main()
