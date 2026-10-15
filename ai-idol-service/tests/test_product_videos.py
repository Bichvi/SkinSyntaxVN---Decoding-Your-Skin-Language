import datetime as dt
import os
import sys
import unittest
from unittest.mock import patch

from bson import ObjectId


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import core.campaigns as campaigns
import core.product_videos as product_videos


NOW = dt.datetime(2026, 10, 14, 8, 0, tzinfo=dt.timezone.utc)
BASE = "https://pub-test.r2.dev/product-videos"


def video(product_id="1024", *, purpose="product_video", ready_minutes=0, hidden=None,
          aired=False, campaign_id=None, ready=True, **extra):
    doc = {
        "_id": ObjectId(),
        "product_id": product_id,
        "purpose": purpose,
        "duration": 30.4,
        "format": "16:9",
        "campaign_id": campaign_id,
        "campaign_name": "Routine da dầu tháng 10",
        "created_at": NOW - dt.timedelta(days=3),
        "media": {"video_url": f"{BASE}/a.mp4", "poster_url": f"{BASE}/a.jpg"},
    }
    if purpose is None:
        doc.pop("purpose")
    if ready:
        doc["product_page_ready_at"] = NOW - dt.timedelta(minutes=ready_minutes)
    if hidden is not None:
        doc["hidden_from_product_page"] = hidden
    if aired:
        doc["aired_at"] = NOW - dt.timedelta(days=2)
    doc.update(extra)
    return doc


class ListProductVideosTests(unittest.TestCase):
    def setUp(self):
        self.db = FakeDb()
        self.patch = patch.object(product_videos, "get_db", return_value=self.db)
        self.patch.start()
        self.addCleanup(self.patch.stop)

    def seed(self, *docs):
        for doc in docs:
            self.db.ai_idol_videos.insert_one(doc)

    def test_only_ready_visible_product_page_videos_newest_first(self):
        a = video(ready_minutes=30)
        b = video(purpose="live_and_product", ready_minutes=5, aired=True)
        hidden = video(hidden=True)
        pending = video(ready=False)
        other = video(product_id="2000")
        self.seed(a, b, hidden, pending, other)

        result = product_videos.list_product_videos("1024")

        self.assertEqual([item["video_id"] for item in result["videos"]], [str(b["_id"]), str(a["_id"])])
        self.assertEqual(result["videos"][0]["date_kind"], "aired")
        self.assertEqual(result["videos"][1]["date_kind"], "created")
        self.assertEqual(result["videos"][0]["video_url"], f"{BASE}/a.mp4")
        self.assertEqual(result["videos"][0]["poster_url"], f"{BASE}/a.jpg")
        self.assertEqual(result["videos"][0]["format"], "16:9")
        self.assertEqual(result["videos"][0]["campaign_name"], "Routine da dầu tháng 10")
        self.assertIsNone(result["live"])

    def test_legacy_and_live_only_videos_never_reach_product_page(self):
        self.seed(video(purpose=None), video(purpose="live_only"))
        self.assertEqual(product_videos.list_product_videos("1024")["videos"], [])

    def test_product_ids_lead_with_clip_product_then_three_campaign_peers(self):
        campaign_id = str(ObjectId())
        self.db.ai_idol_campaigns.insert_one({
            "_id": ObjectId(campaign_id), "product_ids": ["9", "1024", "8", "7", "6"],
        })
        self.seed(video(campaign_id=campaign_id))
        ids = product_videos.list_product_videos("1024")["videos"][0]["product_ids"]
        self.assertEqual(ids, ["1024", "9", "8", "7"])

    def test_product_ids_without_campaign_is_only_clip_product(self):
        self.seed(video())
        self.assertEqual(product_videos.list_product_videos("1024")["videos"][0]["product_ids"], ["1024"])

    def test_live_campaign_containing_product_is_reported(self):
        self.db.ai_idol_campaigns.insert_one({
            "_id": ObjectId(), "status": "LIVE", "name": "Live tối nay",
            "purpose": "live_and_product", "product_ids": ["1024"],
        })
        live = product_videos.list_product_videos("1024")["live"]
        self.assertEqual(live["name"], "Live tối nay")
        self.assertTrue(live["campaign_id"])

    def test_legacy_live_campaign_without_purpose_counts_as_live(self):
        self.db.ai_idol_campaigns.insert_one({
            "_id": ObjectId(), "status": "LIVE", "name": "Cũ", "product_ids": ["1024"],
        })
        self.assertIsNotNone(product_videos.list_product_videos("1024")["live"])

    def test_live_ignores_other_products_and_non_live_status(self):
        self.db.ai_idol_campaigns.insert_one({"_id": ObjectId(), "status": "LIVE", "product_ids": ["5"]})
        self.db.ai_idol_campaigns.insert_one({"_id": ObjectId(), "status": "COMPLETED", "product_ids": ["1024"]})
        self.assertIsNone(product_videos.list_product_videos("1024")["live"])

    def test_limit_is_clamped_to_thirty(self):
        self.seed(*[video(ready_minutes=index) for index in range(35)])
        self.assertEqual(len(product_videos.list_product_videos("1024", limit=500)["videos"]), 30)
        self.assertEqual(len(product_videos.list_product_videos("1024", limit=0)["videos"]), 1)


class VisibilityTests(unittest.TestCase):
    def setUp(self):
        self.db = FakeDb()
        self.patch = patch.object(product_videos, "get_db", return_value=self.db)
        self.patch.start()
        self.addCleanup(self.patch.stop)

    def test_hide_then_unhide_round_trip(self):
        doc = video()
        self.db.ai_idol_videos.insert_one(doc)
        video_id = str(doc["_id"])

        state = product_videos.set_video_visibility(video_id, True, "admin@skinsyntax")
        self.assertTrue(state["hidden"])
        self.assertEqual(product_videos.list_product_videos("1024")["videos"], [])
        stored = self.db.ai_idol_videos.find_one({"_id": doc["_id"]})
        self.assertEqual(stored["hidden_by"], "admin@skinsyntax")

        state = product_videos.set_video_visibility(video_id, False, "admin@skinsyntax")
        self.assertFalse(state["hidden"])
        self.assertEqual(len(product_videos.list_product_videos("1024")["videos"]), 1)

    def test_unknown_video_raises_lookup_error(self):
        with self.assertRaises(LookupError):
            product_videos.set_video_visibility(str(ObjectId()), True, "admin")

    def test_product_page_state_reports_block_reason_and_upload_error(self):
        doc = video(ready=False, product_page_block_reason="missing_price_marker")
        doc["media"]["last_error"] = "timeout"
        state = product_videos.product_page_state(doc)
        self.assertEqual(state, {
            "video_id": str(doc["_id"]), "ready": False, "hidden": False, "eligible": True,
            "block_reason": "missing_price_marker", "last_error": "timeout",
        })

    def test_review_i3_livestream_only_and_legacy_videos_are_not_eligible(self):
        self.assertFalse(product_videos.product_page_state(video(purpose="live_only"))["eligible"])
        self.assertFalse(product_videos.product_page_state(video(purpose=None))["eligible"])
        self.assertTrue(product_videos.product_page_state(video(purpose=None, product_page_backfill=True))["eligible"])


class CampaignDetailTests(unittest.TestCase):
    def test_segments_expose_product_page_state(self):
        db = FakeDb()
        campaign_id = ObjectId()
        doc = video(hidden=True)
        db.ai_idol_videos.insert_one(doc)
        db.ai_idol_campaigns.insert_one({"_id": campaign_id, "name": "x"})
        db.ai_idol_segments.insert_one({
            "campaign_id": str(campaign_id), "position": 0, "video_id": str(doc["_id"]),
        })
        with patch.object(campaigns, "get_db", return_value=db), \
                patch.object(product_videos, "get_db", return_value=db):
            result = campaigns.get_campaign(str(campaign_id))
        page = result["segments"][0]["product_page"]
        self.assertTrue(page["ready"])
        self.assertTrue(page["hidden"])


class CampaignPublicationTests(unittest.TestCase):
    def test_segments_carry_their_ai_product_videos_record_or_none(self):
        db = FakeDb()
        campaign_id = ObjectId()
        published, unpublished, missing = video(), video(), video()
        for doc in (published, unpublished, missing):
            db.ai_idol_videos.insert_one(doc)
        db.ai_idol_campaigns.insert_one({"_id": campaign_id, "name": "x"})
        for position, doc in enumerate((published, unpublished, missing)):
            db.ai_idol_segments.insert_one({"campaign_id": str(campaign_id), "position": position, "video_id": str(doc["_id"])})
        db.ai_idol_segments.insert_one({"campaign_id": str(campaign_id), "position": 3, "status": "SKIPPED"})
        first = db.ai_product_videos.insert_one(
            {"video_id": str(published["_id"]), "is_published": True, "source": "auto"}).inserted_id
        db.ai_product_videos.insert_one({"video_id": str(unpublished["_id"]), "is_published": False})
        db.ai_product_videos.insert_one({"video_id": "someone-else", "is_published": True})
        with patch.object(campaigns, "get_db", return_value=db),                 patch.object(product_videos, "get_db", return_value=db):
            result = campaigns.get_campaign(str(campaign_id))
        found = [segment["publication"] for segment in result["segments"]]
        self.assertEqual(found[0], {"publication_id": str(first), "is_published": True, "source": "auto"})
        self.assertEqual(found[1]["is_published"], False)
        self.assertIsNone(found[1]["source"])
        self.assertIsNone(found[2])
        self.assertIsNone(found[3])  # no video at all


class RouteTests(unittest.TestCase):
    def setUp(self):
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        self.app = service_app
        self.client = service_app.app.test_client()

    def test_list_route_wraps_data(self):
        payload = {"live": None, "videos": []}
        with patch.object(self.app, "list_product_videos", return_value=payload) as listed:
            body = self.client.get("/api/ai-idol/products/1024/videos?limit=12").get_json()
        listed.assert_called_once_with("1024", limit=12)
        self.assertEqual(body, {"ok": True, "data": payload})

    def test_visibility_route_maps_unknown_video_to_404(self):
        with patch.object(self.app, "set_video_visibility", side_effect=LookupError("x")):
            response = self.client.post("/api/ai-idol/videos/abc/visibility", json={"hidden": True})
        self.assertEqual(response.status_code, 404)

    def test_visibility_route_passes_flag_and_actor(self):
        with patch.object(self.app, "set_video_visibility", return_value={"hidden": True}) as setter:
            body = self.client.post(
                "/api/ai-idol/videos/abc/visibility", json={"hidden": True, "by": "admin"}
            ).get_json()
        setter.assert_called_once_with("abc", True, "admin")
        self.assertTrue(body["ok"])


if __name__ == "__main__":
    unittest.main()
