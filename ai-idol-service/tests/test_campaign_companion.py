"""A livestream can bring along its own, independent product-video campaign (also_product_video)."""
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


def live_payload(**extra):
    when = (state_machine.utcnow() + dt.timedelta(days=1)).isoformat()
    payload = {
        "name": "Live tối", "product_ids": ["1", "2"], "purpose": "live_only", "scheduled_at": when,
        "platforms": ["internal"], "content_mode": "Product Intro",
        "configuration": {"approval_mode": "MANUAL", "narration_styles": ["closing", "ingredients"]},
        "idempotency_key": "live-key-1",
    }
    payload.update(extra)
    return payload


class CompanionCampaignTests(unittest.TestCase):
    def setUp(self):
        self.db = FakeDb()
        for target in (campaigns, state_machine):
            patcher = patch.object(target, "get_db", return_value=self.db)
            patcher.start()
            self.addCleanup(patcher.stop)

    def docs(self):
        return list(self.db.ai_idol_campaigns.find({}))

    def test_flag_creates_a_linked_product_video_companion(self):
        live_id, created = campaigns.create_campaign(live_payload(also_product_video=True), created_by="admin")
        self.assertTrue(created)
        docs = {str(doc["_id"]): doc for doc in self.docs()}
        self.assertEqual(len(docs), 2)
        live = docs[live_id]
        companion_id = live["companion_campaign_id"]
        companion = docs[companion_id]
        self.assertEqual(companion["companion_of"], live_id)
        self.assertEqual(companion["purpose"], "product_video")
        self.assertEqual(companion["product_ids"], ["1", "2"])
        self.assertEqual(companion["name"], "Live tối · Video sản phẩm")
        self.assertEqual(companion["content_mode"], "Product Intro")
        self.assertEqual(companion["configuration"]["length_mode"], "Long (90-150s)")
        self.assertEqual(companion["configuration"]["approval_mode"], "MANUAL")
        self.assertNotIn("closing", companion["configuration"]["narration_styles"])
        self.assertIn("ingredients", companion["configuration"]["narration_styles"])
        self.assertIsNone(companion["scheduled_at"])
        self.assertEqual(companion["platforms"], [])
        self.assertEqual(companion["created_by"], "admin")
        self.assertEqual(len(list(self.db.ai_idol_segments.find({"campaign_id": companion_id}))), 2)
        self.assertEqual(campaigns.companion_campaign_id(live_id), companion_id)
        self.assertNotIn("length_mode", live["configuration"])

    def test_companion_name_is_truncated(self):
        live_id, _ = campaigns.create_campaign(live_payload(also_product_video=True, name="x" * 160))
        companion = next(d for d in self.docs() if d.get("companion_of") == live_id)
        self.assertEqual(len(companion["name"]), 160)

    def test_no_companion_unless_flag_is_exactly_true(self):
        for index, flag in enumerate((False, None, "true", 1)):
            payload = live_payload(idempotency_key=f"k{index}")
            if flag is not None:
                payload["also_product_video"] = flag
            live_id, _ = campaigns.create_campaign(payload)
            self.assertIsNone(campaigns.companion_campaign_id(live_id))
        self.assertEqual(len(self.docs()), 4)

    def test_flag_is_ignored_for_other_purposes(self):
        video_id, _ = campaigns.create_campaign({"product_ids": ["1"], "purpose": "product_video", "also_product_video": True})
        legacy_id, _ = campaigns.create_campaign(live_payload(purpose="live_and_product", also_product_video=True))
        self.assertIsNone(campaigns.companion_campaign_id(video_id))
        self.assertIsNone(campaigns.companion_campaign_id(legacy_id))
        self.assertEqual(len(self.docs()), 2)

    def test_duplicate_call_creates_no_second_companion(self):
        first, created = campaigns.create_campaign(live_payload(also_product_video=True))
        again, created_again = campaigns.create_campaign(live_payload(also_product_video=True))
        self.assertTrue(created)
        self.assertFalse(created_again)
        self.assertEqual(first, again)
        self.assertEqual(len(self.docs()), 2)

    def test_companion_failure_still_returns_the_livestream(self):
        real = campaigns.create_campaign

        def fail_for_video(payload, created_by=None):
            if payload.get("purpose") == "product_video":
                raise RuntimeError("boom")
            return real(payload, created_by)

        with patch.object(campaigns, "create_campaign", side_effect=fail_for_video):
            live_id, created = campaigns.create_campaign(live_payload(also_product_video=True))
        self.assertTrue(created)
        self.assertEqual(len(self.docs()), 1)
        self.assertIsNone(campaigns.companion_campaign_id(live_id))

    def test_scheduled_request_without_purpose_is_a_plain_livestream(self):
        when = (state_machine.utcnow() + dt.timedelta(days=1)).isoformat()
        campaigns.create_campaign({"product_ids": ["1"], "scheduled_at": when})
        self.assertEqual(self.docs()[0]["purpose"], "live_only")


class CreateCampaignRouteTests(unittest.TestCase):
    def post(self, create_result, companion):
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        with patch.object(service_app, "create_campaign", return_value=create_result), \
                patch.object(service_app, "companion_campaign_id", return_value=companion), \
                patch.object(service_app, "prepare_campaign_task") as task:
            response = service_app.app.test_client().post("/api/ai-idol/campaigns", json={"product_ids": ["1"]})
        return response, task

    def test_created_live_prepares_both_campaigns(self):
        response, task = self.post(("live1", True), "vid1")
        self.assertEqual(response.status_code, 202)
        self.assertEqual(response.get_json()["companion_campaign_id"], "vid1")
        self.assertEqual([call.args[0] for call in task.delay.call_args_list], ["live1", "vid1"])

    def test_created_without_companion_prepares_once(self):
        response, task = self.post(("c1", True), None)
        self.assertEqual(response.status_code, 202)
        self.assertIsNone(response.get_json()["companion_campaign_id"])
        task.delay.assert_called_once_with("c1")

    def test_duplicate_returns_companion_id_without_preparing(self):
        response, task = self.post(("live1", False), "vid1")
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.get_json()["companion_campaign_id"], "vid1")
        task.delay.assert_not_called()


if __name__ == "__main__":
    unittest.main()
