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
from core.purpose import (
    DEFAULT_PURPOSE, LEGACY_PURPOSE, PURPOSES, has_broadcast, normalize_purpose,
    publishes_to_product_page, purpose_of,
)


class PurposeTests(unittest.TestCase):
    def test_normalize_defaults_to_product_video(self):
        self.assertEqual(DEFAULT_PURPOSE, "product_video")
        self.assertEqual(normalize_purpose(None), "product_video")
        self.assertEqual(normalize_purpose("x"), "product_video")
        self.assertEqual(normalize_purpose(" LIVE_ONLY "), "live_only")
        self.assertEqual(PURPOSES, frozenset({"product_video", "live_and_product", "live_only"}))

    def test_missing_purpose_on_stored_documents_is_legacy_live_only(self):
        self.assertEqual(LEGACY_PURPOSE, "live_only")
        self.assertEqual(purpose_of({}), "live_only")
        self.assertEqual(purpose_of(None), "live_only")
        self.assertEqual(purpose_of({"purpose": "live_and_product"}), "live_and_product")
        self.assertEqual(purpose_of({"purpose": "garbage"}), "live_only")

    def test_broadcast_and_product_page_flags(self):
        self.assertFalse(has_broadcast("product_video"))
        self.assertTrue(has_broadcast("live_and_product"))
        self.assertTrue(has_broadcast("live_only"))
        self.assertTrue(publishes_to_product_page("product_video"))
        self.assertTrue(publishes_to_product_page("live_and_product"))
        self.assertFalse(publishes_to_product_page("live_only"))


class CreateCampaignPurposeTests(unittest.TestCase):
    def setUp(self):
        self.db = FakeDb()
        for target in (campaigns, state_machine):
            patcher = patch.object(target, "get_db", return_value=self.db)
            patcher.start()
            self.addCleanup(patcher.stop)

    def test_product_video_needs_no_schedule(self):
        campaign_id, created = campaigns.create_campaign({"product_ids": ["1"], "purpose": "product_video"})
        self.assertTrue(created)
        stored = self.db.ai_idol_campaigns.find_one({})
        self.assertIsNone(stored["scheduled_at"])
        self.assertEqual(stored["platforms"], [])
        self.assertEqual(stored["purpose"], "product_video")

    def test_missing_purpose_defaults_to_product_video(self):
        campaigns.create_campaign({"product_ids": ["1"]})
        self.assertEqual(self.db.ai_idol_campaigns.find_one({})["purpose"], "product_video")

    def test_scheduled_request_without_purpose_keeps_its_livestream(self):
        from core.state_machine import utcnow
        import datetime as dt
        when = (utcnow() + dt.timedelta(days=1)).isoformat()
        campaigns.create_campaign({"product_ids": ["1"], "scheduled_at": when})
        stored = self.db.ai_idol_campaigns.find_one({})
        self.assertEqual(stored["purpose"], "live_and_product")
        self.assertIsNotNone(stored["scheduled_at"])

    def test_two_unscheduled_product_video_campaigns_are_distinct(self):
        first, _ = campaigns.create_campaign({"product_ids": ["1"], "purpose": "product_video"})
        second, created = campaigns.create_campaign({"product_ids": ["1"], "purpose": "product_video"})
        self.assertTrue(created)
        self.assertNotEqual(first, second)

    def test_live_purposes_still_require_a_schedule(self):
        with self.assertRaisesRegex(ValueError, "scheduled_at is required"):
            campaigns.create_campaign({"product_ids": ["1"], "purpose": "live_only"})

    def test_prepare_campaign_creates_long_product_video_jobs(self):
        campaign_id, _ = campaigns.create_campaign({"product_ids": ["1"], "purpose": "product_video"})
        with patch.object(campaigns, "collect_product_and_snapshot", return_value={"name": "A"}):
            job_ids = campaigns.prepare_campaign(campaign_id)
        job = self.db.ai_idol_jobs.find_one({})
        self.assertEqual(len(job_ids), 1)
        self.assertEqual(job["purpose"], "product_video")
        self.assertEqual(job["configuration"]["length_mode"], "Long (90-150s)")

    def test_prepare_campaign_keeps_explicit_length_mode(self):
        campaign_id, _ = campaigns.create_campaign({
            "product_ids": ["1"], "purpose": "product_video",
            "configuration": {"length_mode": "Short (30-60s)"},
        })
        with patch.object(campaigns, "collect_product_and_snapshot", return_value={}):
            campaigns.prepare_campaign(campaign_id)
        self.assertEqual(self.db.ai_idol_jobs.find_one({})["configuration"]["length_mode"], "Short (30-60s)")

    def test_create_job_defaults_to_legacy_purpose(self):
        state_machine.create_job("1", "Product Intro", {})
        self.assertEqual(self.db.ai_idol_jobs.find_one({})["purpose"], "live_only")


if __name__ == "__main__":
    unittest.main()
