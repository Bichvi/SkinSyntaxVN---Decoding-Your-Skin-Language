import datetime as dt
import os
import re
import sys
import tempfile
import unittest
from unittest.mock import patch

from bson import ObjectId


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

from engines import media_publisher, replay_publisher


R2 = {
    "R2_ACCOUNT_ID": "acct", "R2_ACCESS_KEY_ID": "AKIDSECRETVALUE", "R2_SECRET_ACCESS_KEY": "s3cr3t-value",
    "R2_BUCKET": "videos", "R2_PUBLIC_BASE_URL": "https://pub-test.r2.dev/",
}
NOW = dt.datetime(2026, 10, 14, 8, 0, tzinfo=dt.timezone.utc)


class FakeS3:
    def __init__(self, fail=None):
        self.uploads, self.fail = [], fail

    def upload_file(self, path, bucket, key, ExtraArgs=None):
        if self.fail:
            raise RuntimeError(self.fail)
        self.uploads.append((path, bucket, key, ExtraArgs))


class ReplayTestCase(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.db = FakeDb()
        self.posters = []
        for target in (replay_publisher, media_publisher):
            patcher = patch.object(target, "get_db", return_value=self.db)
            patcher.start()
            self.addCleanup(patcher.stop)
        poster = patch.object(replay_publisher, "make_poster", side_effect=self.fake_poster)
        poster.start()
        self.addCleanup(poster.stop)
        probe = patch.object(replay_publisher, "probe_duration", return_value=30.0)
        probe.start()
        self.addCleanup(probe.stop)
        clock = patch.object(replay_publisher, "utcnow", return_value=NOW)
        clock.start()
        self.addCleanup(clock.stop)
        replay_publisher._warned_unconfigured = False

    def fake_poster(self, video_path, out_path, duration):
        with open(out_path, "wb") as handle:
            handle.write(b"jpg")
        self.posters.append(out_path)
        return out_path

    def master(self, name="master.mp4"):
        path = os.path.join(self.folder.name, name)
        with open(path, "wb") as handle:
            handle.write(b"x" * 2048)
        return path

    def campaign(self, **fields):
        doc = {
            "_id": ObjectId(), "status": "COMPLETED", "purpose": "live_only", "platforms": ["internal"],
            "name": "Live 15/10", "completed_at": NOW, "master_video_path": self.master(f"{ObjectId()}.mp4"),
            **fields,
        }
        self.db.ai_idol_campaigns.insert_one(doc)
        return str(doc["_id"])

    def stored(self, campaign_id):
        return self.db.ai_idol_campaigns.find_one({"_id": ObjectId(campaign_id)})


class PublishCampaignReplayTests(ReplayTestCase):
    def test_publish_uploads_video_and_poster_and_releases_master(self):
        campaign_id = self.campaign()
        master = self.stored(campaign_id)["master_video_path"]
        client = FakeS3()
        with patch.dict(os.environ, R2):
            self.assertTrue(replay_publisher.publish_campaign_replay(campaign_id, client=client))
        self.assertEqual(len(client.uploads), 2)
        pattern = re.compile(rf"^live-replays/{campaign_id}-[0-9a-f]{{12}}\.(mp4|jpg)$")
        for item in client.uploads:
            self.assertRegex(item[2], pattern)
        mp4 = next(item for item in client.uploads if item[2].endswith(".mp4"))
        jpg = next(item for item in client.uploads if item[2].endswith(".jpg"))
        self.assertEqual(mp4[3]["ContentType"], "video/mp4")
        self.assertEqual(jpg[3]["ContentType"], "image/jpeg")
        replay = self.stored(campaign_id)["replay"]
        self.assertEqual(replay["video_url"], f"https://pub-test.r2.dev/{mp4[2]}")
        self.assertTrue(replay["poster_url"].endswith(".jpg"))
        self.assertEqual(replay["duration"], 30.0)
        self.assertIsNotNone(replay["uploaded_at"])
        self.assertIsNone(replay["last_error"])
        self.assertIsNone(replay.get("started_at"))  # FakeDb stores $unset as None
        self.assertFalse(os.path.exists(master))
        self.assertEqual(len(self.posters), 1)
        self.assertFalse(os.path.exists(self.posters[0]))

    def test_publish_is_idempotent(self):
        campaign_id = self.campaign()
        client = FakeS3()
        with patch.dict(os.environ, R2):
            self.assertTrue(replay_publisher.publish_campaign_replay(campaign_id, client=client))
            self.assertTrue(replay_publisher.publish_campaign_replay(campaign_id, client=client))
        self.assertEqual(len(client.uploads), 2)

    def test_unconfigured_r2_records_message_without_attempt(self):
        campaign_id = self.campaign()
        client = FakeS3()
        with patch.dict(os.environ, {key: "" for key in R2}):
            self.assertFalse(replay_publisher.publish_campaign_replay(campaign_id, client=client))
        replay = self.stored(campaign_id)["replay"]
        self.assertEqual(replay["last_error"], "Chưa cấu hình Cloudflare R2 nên chưa có video xem lại.")
        self.assertFalse(replay.get("attempts"))
        self.assertEqual(client.uploads, [])

    def test_missing_master_is_not_retried(self):
        campaign_id = self.campaign()
        os.remove(self.stored(campaign_id)["master_video_path"])
        client = FakeS3()
        with patch.dict(os.environ, R2):
            self.assertFalse(replay_publisher.publish_campaign_replay(campaign_id, client=client))
        replay = self.stored(campaign_id)["replay"]
        self.assertEqual(replay["last_error"], "Không còn file video gốc để lưu bản xem lại.")
        self.assertEqual(replay["attempts"], 5)
        self.assertEqual(client.uploads, [])

    def test_fresh_claim_blocks_second_worker(self):
        campaign_id = self.campaign(replay={"started_at": NOW - dt.timedelta(minutes=5)})
        client = FakeS3()
        with patch.dict(os.environ, R2):
            self.assertFalse(replay_publisher.publish_campaign_replay(campaign_id, client=client))
        self.assertEqual(client.uploads, [])

    def test_stale_claim_is_reclaimed(self):
        campaign_id = self.campaign(replay={"started_at": NOW - dt.timedelta(minutes=31)})
        with patch.dict(os.environ, R2):
            self.assertTrue(replay_publisher.publish_campaign_replay(campaign_id, client=FakeS3()))

    def test_upload_failure_counts_attempt_and_scrubs_secret(self):
        campaign_id = self.campaign()
        master = self.stored(campaign_id)["master_video_path"]
        with patch.dict(os.environ, R2):
            self.assertFalse(replay_publisher.publish_campaign_replay(
                campaign_id, client=FakeS3(fail="boom AKIDSECRETVALUE")))
        replay = self.stored(campaign_id)["replay"]
        self.assertEqual(replay["attempts"], 1)
        self.assertNotIn("AKIDSECRETVALUE", replay["last_error"])
        self.assertIsNone(replay.get("started_at"))  # FakeDb stores $unset as None
        self.assertTrue(os.path.isfile(master))
        self.assertFalse(os.path.exists(self.posters[0]))


class PublishableTests(ReplayTestCase):
    def test_not_publishable(self):
        client = FakeS3()
        with patch.dict(os.environ, R2):
            for fields in ({"purpose": "product_video"}, {"platforms": ["youtube"]}, {"status": "LIVE"}):
                campaign_id = self.campaign(**fields)
                self.assertFalse(replay_publisher.publish_campaign_replay(campaign_id, client=client), fields)
            partial = self.campaign(status="PARTIAL_FAILURE")
            self.assertFalse(replay_publisher.publish_campaign_replay(partial, client=client))
            self.db.ai_idol_broadcasts.insert_one(
                {"campaign_id": partial, "platform": "internal", "status": "COMPLETED"})
            self.assertTrue(replay_publisher.publish_campaign_replay(partial, client=client))


class RetryAndBackfillTests(ReplayTestCase):
    def test_retry_skips_exhausted_and_done(self):
        self.campaign(replay={"attempts": 5})
        self.campaign(replay={"uploaded_at": NOW})
        self.campaign()
        with patch.dict(os.environ, R2):
            self.assertEqual(replay_publisher.retry_pending_replays(client=FakeS3()), 1)

    def test_backfill_includes_legacy_campaign_without_purpose_or_platforms(self):
        campaign_id = self.campaign()
        self.db.ai_idol_campaigns.update_one(
            {"_id": ObjectId(campaign_id)}, {"$unset": {"purpose": "", "platforms": ""}})
        dry = FakeS3()
        with patch.dict(os.environ, R2):
            rows = replay_publisher.backfill(apply=False, client=dry)
            self.assertEqual([row["campaign_id"] for row in rows], [campaign_id])
            self.assertFalse(rows[0]["published"])
            self.assertEqual(dry.uploads, [])
            rows = replay_publisher.backfill(apply=True, client=FakeS3())
        self.assertTrue(rows[0]["published"])

    def test_main_apply_without_r2_returns_2(self):
        with patch.dict(os.environ, {key: "" for key in R2}):
            self.assertEqual(replay_publisher.main(["backfill", "--apply"]), 2)


if __name__ == "__main__":
    unittest.main()
