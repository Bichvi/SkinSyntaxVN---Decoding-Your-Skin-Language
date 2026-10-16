import os
import sys
import unittest
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import core.pipeline as pipeline
from core.pricing import claim_from_pricing

PRICING_B = {"regular_price": 299000, "market_price": 350000,
             "flash": {"campaign_id": 12, "flash_price": 249000, "end_ts": 1792256340}}


class CompleteJobClaimTests(unittest.TestCase):
    def complete(self, purpose, snapshot):
        db = FakeDb()
        with patch("engines.video_composer.get_audio_duration", return_value=48.0),                 patch.object(pipeline, "get_db", return_value=db):
            pipeline._complete_video_job(
                "64b64b64b64b64b64b64b64b", "1024", {"format": "16:9"}, "/x/final.mp4", "/x/voice.wav", db,
                purpose=purpose, snapshot=snapshot)
        return db.ai_idol_videos.find_one({})

    def test_complete_video_job_writes_claim(self):
        video = self.complete("product_video", {"id": "1024", "pricing": PRICING_B})
        self.assertEqual(video["price_claim"], claim_from_pricing("1024", PRICING_B))
        self.assertEqual(video["price_claim"]["valid_until_ts"], PRICING_B["flash"]["end_ts"])
        self.assertIsNotNone(self.complete("live_and_product", {"id": "1024", "pricing": PRICING_B})["price_claim"])

    def test_live_only_has_no_claim(self):
        self.assertIsNone(self.complete("live_only", {"id": "1024", "pricing": PRICING_B})["price_claim"])

    def test_snapshot_without_pricing_has_no_claim(self):
        self.assertIsNone(self.complete("product_video", {"id": "1024"})["price_claim"])
        self.assertIsNone(self.complete("product_video", None)["price_claim"])

    def test_claim_uses_snapshot_id_over_argument(self):
        video = self.complete("product_video", {"id": "77", "pricing": PRICING_B})
        self.assertEqual(video["price_claim"]["product_id"], "77")


if __name__ == "__main__":
    unittest.main()
