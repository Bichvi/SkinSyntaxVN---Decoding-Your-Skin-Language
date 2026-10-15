import datetime as dt
import os
import sys
import unittest


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from config import CAMPAIGN_MAX_PRODUCTS
from core.campaigns import normalize_product_ids, parse_schedule


class CampaignValidationTests(unittest.TestCase):
    def test_product_ids_are_trimmed_and_deduplicated_in_order(self):
        self.assertEqual(normalize_product_ids([" 12 ", 12, "abc", "abc", ""]), ["12", "abc"])

    def test_campaign_requires_at_least_one_product(self):
        with self.assertRaisesRegex(ValueError, "at least one product"):
            normalize_product_ids([])

    def test_campaign_product_limit_is_enforced(self):
        with self.assertRaisesRegex(ValueError, "at most"):
            normalize_product_ids([str(index) for index in range(CAMPAIGN_MAX_PRODUCTS + 1)])

    def test_naive_schedule_is_interpreted_in_configured_timezone(self):
        parsed = parse_schedule("2026-09-03T20:00:00")
        self.assertEqual(parsed.tzinfo, dt.timezone.utc)
        self.assertEqual(parsed.isoformat(), "2026-09-03T13:00:00+00:00")

    def test_utc_schedule_remains_the_same_instant(self):
        parsed = parse_schedule("2026-09-03T13:00:00Z")
        self.assertEqual(parsed.isoformat(), "2026-09-03T13:00:00+00:00")




class ProductVideoLifecycleTests(unittest.TestCase):
    """Product-video campaigns end at VIDEOS_READY: no master assembly, no broadcast."""

    def setUp(self):
        from bson import ObjectId
        from unittest.mock import patch
        from tests.fakes import FakeDb
        import core.campaigns as campaigns_module
        self.ObjectId, self.patch, self.campaigns = ObjectId, patch, campaigns_module
        self.db = FakeDb()
        patcher = patch.object(campaigns_module, "get_db", return_value=self.db)
        patcher.start()
        self.addCleanup(patcher.stop)

    def campaign(self, status="GENERATING", **extra):
        doc = {"_id": self.ObjectId(), "status": status, **extra}
        self.db.ai_idol_campaigns.insert_one(doc)
        return str(doc["_id"])

    def status(self, campaign_id):
        return self.db.ai_idol_campaigns.find_one({"_id": self.ObjectId(campaign_id)})["status"]

    def test_product_video_campaign_is_never_claimed_for_assembly(self):
        campaign_id = self.campaign(purpose="product_video")
        self.assertFalse(self.campaigns.claim_campaign_for_assembly(campaign_id))
        self.assertEqual(self.status(campaign_id), "VIDEOS_READY")

    def test_livestream_and_legacy_campaigns_still_assemble(self):
        both = self.campaign(purpose="live_and_product")
        legacy = self.campaign()
        self.assertTrue(self.campaigns.claim_campaign_for_assembly(both))
        self.assertTrue(self.campaigns.claim_campaign_for_assembly(legacy))
        self.assertEqual(self.status(both), "ASSEMBLING")
        self.assertEqual(self.status(legacy), "ASSEMBLING")

    def test_generate_job_task_finishes_product_video_without_assembly(self):
        import tasks
        campaign_id = self.campaign(purpose="product_video")
        self.db.ai_idol_jobs.insert_one({"_id": self.ObjectId("64b64b64b64b64b64b64b64b"), "status": "READY",
                                         "campaign_id": campaign_id})
        with self.patch.object(tasks, "get_db", return_value=self.db), \
                self.patch.object(tasks, "update_campaign_from_job", return_value=campaign_id), \
                self.patch.object(tasks.assemble_campaign_task, "delay") as assemble:
            tasks.generate_job_task("64b64b64b64b64b64b64b64b")
        assemble.assert_not_called()
        self.assertEqual(self.status(campaign_id), "VIDEOS_READY")

    def test_retry_of_ready_product_video_campaign_does_not_assemble(self):
        campaign_id = self.campaign(status="NEEDS_ATTENTION", purpose="product_video")
        self.db.ai_idol_segments.insert_one({"campaign_id": campaign_id, "position": 0, "status": "READY"})
        assembled = []
        result = self.campaigns.retry_campaign(campaign_id, enqueue_job=lambda *_: None,
                                               enqueue_prepare=lambda *_: None, enqueue_assemble=assembled.append)
        self.assertEqual(assembled, [])
        self.assertEqual(self.status(campaign_id), "VIDEOS_READY")
        self.assertTrue(result.get("videos_ready"))


if __name__ == "__main__":
    unittest.main()
