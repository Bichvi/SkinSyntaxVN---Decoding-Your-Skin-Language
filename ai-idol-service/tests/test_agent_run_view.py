"""Agent run view: campaign purpose and per-segment product page state shown in AI Idol Studio."""
import datetime as dt
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

NOW = dt.datetime(2026, 10, 14, 9, 0, tzinfo=dt.timezone.utc)
R2_MESSAGE = "Chưa cấu hình Cloudflare R2 nên video chưa lên trang sản phẩm."


def oid():
    return ObjectId()


def build_db(purpose, segments, videos=(), run_id=None, campaign_id=None):
    run_id = run_id or oid()
    campaign_id = campaign_id or oid()
    campaign = {"_id": campaign_id, "status": "VIDEOS_READY", "progress": {"total": len(segments), "ready": len(segments)}}
    if purpose is not None:
        campaign["purpose"] = purpose
    db = FakeDb(
        ai_idol_agent_runs=Collection([{"_id": run_id, "status": "CAMPAIGN_CREATED", "campaign_id": str(campaign_id)}]),
        ai_idol_campaigns=Collection([campaign]),
        ai_idol_segments=Collection([dict(segment, campaign_id=str(campaign_id)) for segment in segments]),
        ai_idol_videos=Collection(list(videos)),
    )
    return db, run_id


class AgentRunViewTests(unittest.TestCase):
    def read_run(self, db, run_id):
        with patch.object(agent_runs, "get_db", return_value=db):
            return agent_runs.get_agent_run(str(run_id))

    def test_product_video_run_exposes_purpose_and_product_page_error(self):
        video_id = oid()
        db, run_id = build_db(
            "product_video",
            [{"_id": oid(), "position": 0, "product_id": "93", "status": "READY", "job_id": None, "video_id": str(video_id)}],
            videos=[{"_id": video_id, "product_id": "93", "purpose": "product_video",
                     "media": {"last_error": R2_MESSAGE}}],
        )

        data = self.read_run(db, run_id)

        self.assertEqual(data["campaign_purpose"], "product_video")
        page = data["campaign_segments"][0]["product_page"]
        self.assertEqual(page["video_id"], str(video_id))
        self.assertEqual(page["last_error"], R2_MESSAGE)
        self.assertIs(page["ready"], False)
        self.assertIs(page["eligible"], True)

    def test_ready_product_page_video_is_reported_ready(self):
        video_id = oid()
        db, run_id = build_db(
            "live_and_product",
            [{"_id": oid(), "position": 0, "product_id": "93", "status": "READY", "video_id": str(video_id)}],
            videos=[{"_id": video_id, "product_id": "93", "purpose": "live_and_product",
                     "product_page_ready_at": NOW, "media": {}}],
        )

        data = self.read_run(db, run_id)

        self.assertEqual(data["campaign_purpose"], "live_and_product")
        self.assertIs(data["campaign_segments"][0]["product_page"]["ready"], True)
        self.assertIsNone(data["campaign_segments"][0]["product_page"]["last_error"])

    def test_segment_without_video_has_no_product_page(self):
        db, run_id = build_db(
            "product_video",
            [
                {"_id": oid(), "position": 0, "product_id": "93", "status": "GENERATING", "job_id": None},
                {"_id": oid(), "position": 1, "product_id": "94", "status": "READY", "job_id": None, "video_id": None},
            ],
        )

        data = self.read_run(db, run_id)

        self.assertEqual([item["product_id"] for item in data["campaign_segments"]], ["93", "94"])
        self.assertIsNone(data["campaign_segments"][0]["product_page"])
        self.assertIsNone(data["campaign_segments"][1]["product_page"])

    def test_invalid_or_missing_video_ids_are_skipped_without_crashing(self):
        db, run_id = build_db(
            "product_video",
            [
                {"_id": oid(), "position": 0, "product_id": "93", "status": "READY", "video_id": "not-an-object-id"},
                {"_id": oid(), "position": 1, "product_id": "94", "status": "READY", "video_id": str(oid())},
            ],
        )

        data = self.read_run(db, run_id)

        self.assertEqual([item["product_page"] for item in data["campaign_segments"]], [None, None])

    def test_legacy_campaign_without_purpose_is_live_only(self):
        db, run_id = build_db(None, [{"_id": oid(), "position": 0, "product_id": "93", "status": "READY"}])

        self.assertEqual(self.read_run(db, run_id)["campaign_purpose"], "live_only")

    def test_live_only_campaign_reports_live_only(self):
        db, run_id = build_db("live_only", [{"_id": oid(), "position": 0, "product_id": "93", "status": "READY"}])

        data = self.read_run(db, run_id)

        self.assertEqual(data["campaign_purpose"], "live_only")
        self.assertIsNone(data["campaign_segments"][0]["product_page"])

    def test_videos_are_fetched_with_a_single_query(self):
        first, second = oid(), oid()
        db, run_id = build_db(
            "product_video",
            [
                {"_id": oid(), "position": 0, "product_id": "93", "status": "READY", "video_id": str(first)},
                {"_id": oid(), "position": 1, "product_id": "94", "status": "READY", "video_id": str(second)},
            ],
            videos=[
                {"_id": first, "product_id": "93", "purpose": "product_video", "media": {}},
                {"_id": second, "product_id": "94", "purpose": "product_video", "product_page_ready_at": NOW, "media": {}},
            ],
        )
        queries = []
        original_find = db.ai_idol_videos.find
        db.ai_idol_videos.find = lambda query=None, projection=None: (queries.append(query), original_find(query, projection))[1]

        data = self.read_run(db, run_id)

        self.assertEqual(len(queries), 1)
        self.assertEqual([item["product_page"]["ready"] for item in data["campaign_segments"]], [False, True])


class PendingExternalPlatformsTests(unittest.TestCase):
    def test_campaign_pending_external_platforms_is_exposed(self):
        run_id, cid = oid(), oid()
        db = FakeDb(ai_idol_campaigns=Collection([
            {"_id": cid, "status": "SCHEDULED", "platforms": ["internal"], "pending_external_platforms": ["youtube"]}]))
        data = agent_runs._serialize_with_campaign({"_id": run_id, "status": "CAMPAIGN_CREATED", "campaign_id": str(cid)}, db)
        self.assertEqual(data["campaign_pending_external_platforms"], ["youtube"])

    def test_campaign_without_pending_platforms_defaults_to_empty_list(self):
        run_id, cid = oid(), oid()
        db = FakeDb(ai_idol_campaigns=Collection([{"_id": cid, "status": "SCHEDULED"}]))
        data = agent_runs._serialize_with_campaign({"_id": run_id, "status": "CAMPAIGN_CREATED", "campaign_id": str(cid)}, db)
        self.assertEqual(data["campaign_pending_external_platforms"], [])


if __name__ == "__main__":
    unittest.main()
