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


if __name__ == "__main__":
    unittest.main()
