import datetime as dt
import os
import sys
import tempfile
import unittest
from unittest.mock import patch

from bson import ObjectId


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import core.product_videos as product_videos
from engines import broadcast_engine, media_publisher


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


class PublisherTestCase(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.db = FakeDb()
        for target in (media_publisher, product_videos):
            patcher = patch.object(target, "get_db", return_value=self.db)
            patcher.start()
            self.addCleanup(patcher.stop)
        poster = patch.object(media_publisher, "make_poster", side_effect=self.fake_poster)
        poster.start()
        self.addCleanup(poster.stop)
        probe = patch.object(media_publisher, "probe_duration", return_value=30.0)
        probe.start()
        self.addCleanup(probe.stop)
        # Listing a clip on its product page has its own tests (test_publications); here it must not reach a real database.
        page = patch.object(media_publisher, "auto_publish_video")
        page.start()
        self.addCleanup(page.stop)
        media_publisher._warned_unconfigured = False

    def fake_poster(self, video_path, out_path, duration):
        with open(out_path, "wb") as handle:
            handle.write(b"jpg")
        return out_path

    def file(self, name):
        path = os.path.join(self.folder.name, name)
        with open(path, "wb") as handle:
            handle.write(b"x" * 2048)
        return path

    def video(self, **fields):
        doc = {"_id": ObjectId(), "product_id": "93", "duration": 30.0, "format": "16:9", **fields}
        self.db.ai_idol_videos.insert_one(doc)
        return str(doc["_id"])

    def stored(self, video_id):
        return self.db.ai_idol_videos.find_one({"_id": ObjectId(video_id)})


class PublishVideoTests(PublisherTestCase):
    def test_unconfigured_r2_neither_uploads_nor_deletes(self):
        clip = self.file("clip.mp4")
        video_id = self.video(purpose="product_video", product_page_file_path=clip, file_path=clip)
        client = FakeS3()
        with patch.dict(os.environ, {key: "" for key in R2}):
            self.assertFalse(media_publisher.publish_video(video_id, client=client))
        self.assertEqual(client.uploads, [])
        self.assertTrue(os.path.isfile(clip))
        self.assertNotIn("product_page_ready_at", self.stored(video_id))

    def test_success_publishes_urls_and_cleans_product_video_files(self):
        clip = self.file("clip.mp4")
        video_id = self.video(purpose="product_video", product_page_file_path=clip, file_path=clip)
        client = FakeS3()
        with patch.dict(os.environ, R2):
            self.assertTrue(media_publisher.publish_video(video_id, client=client))
            self.assertTrue(media_publisher.publish_video(video_id, client=client))  # idempotent
        self.assertEqual(len(client.uploads), 2)
        mp4 = next(item for item in client.uploads if item[2].endswith(".mp4"))
        self.assertEqual(mp4[1], "videos")
        self.assertTrue(mp4[2].startswith(f"product-videos/{video_id}-"))
        self.assertEqual(mp4[3], {"ContentType": "video/mp4", "CacheControl": "public, max-age=31536000, immutable"})
        stored = self.stored(video_id)
        self.assertTrue(stored["media"]["video_url"].startswith("https://pub-test.r2.dev/product-videos/"))
        self.assertTrue(stored["media"]["poster_url"].endswith(".jpg"))
        self.assertIn("product_page_ready_at", stored)
        self.assertFalse(os.path.exists(clip))

    def test_upload_failure_counts_attempt_redacts_and_keeps_files(self):
        clip = self.file("clip.mp4")
        video_id = self.video(purpose="product_video", product_page_file_path=clip, file_path=clip)
        with patch.dict(os.environ, R2):
            ok = media_publisher.publish_video(video_id, client=FakeS3(fail="denied for AKIDSECRETVALUE / s3cr3t-value"))
        self.assertFalse(ok)
        stored = self.stored(video_id)
        self.assertEqual(stored["media"]["attempts"], 1)
        self.assertNotIn("AKIDSECRETVALUE", stored["media"]["last_error"])
        self.assertNotIn("s3cr3t-value", stored["media"]["last_error"])
        self.assertTrue(os.path.isfile(clip))

    def test_review_i3_unconfigured_r2_is_visible_without_burning_attempts(self):
        clip = self.file("clip.mp4")
        video_id = self.video(purpose="product_video", product_page_file_path=clip, file_path=clip)
        with patch.dict(os.environ, {key: "" for key in R2}):
            media_publisher.publish_video(video_id, client=FakeS3())
        media = self.stored(video_id).get("media") or {}
        self.assertIn("R2", media.get("last_error") or "")
        self.assertNotIn("attempts", media)

    def test_review_i4_card_duration_is_the_published_clip(self):
        clip = self.file("clip.body.mp4")
        video_id = self.video(purpose="live_and_product", product_page_file_path=clip, file_path=clip, duration=65.0)
        with patch.dict(os.environ, R2), patch.object(media_publisher, "probe_duration", return_value=44.5):
            media_publisher.publish_video(video_id, client=FakeS3())
        self.assertEqual(self.stored(video_id)["media"]["duration"], 44.5)
        card = product_videos.list_product_videos("93")["videos"][0]
        self.assertEqual(card["duration"], 44.5)

    def test_cleanup_without_ready_stamp_deletes_nothing(self):
        clip = self.file("clip.mp4")
        video_id = self.video(purpose="product_video", product_page_file_path=clip, file_path=clip)
        media_publisher.cleanup_local_files(video_id)
        self.assertTrue(os.path.isfile(clip))


class CampaignPublishTests(PublisherTestCase):
    def test_publishable_rules(self):
        self.assertTrue(media_publisher.campaign_publishable({"status": "COMPLETED"}, []))
        self.assertTrue(media_publisher.campaign_publishable({"status": "PARTIAL_FAILURE"},
                                                            [{"status": "FAILED"}, {"status": "COMPLETED"}]))
        self.assertFalse(media_publisher.campaign_publishable({"status": "PARTIAL_FAILURE"}, [{"status": "FAILED"}]))
        self.assertFalse(media_publisher.campaign_publishable({"status": "LIVE"}, []))

    def test_completed_both_mode_campaign_publishes_bodies_then_drops_full_media(self):
        full, body, master = self.file("full.mp4"), self.file("full.body.mp4"), self.file("master.mp4")
        campaign = ObjectId()
        self.db.ai_idol_campaigns.insert_one({"_id": campaign, "status": "COMPLETED", "purpose": "live_and_product",
                                              "name": "Live 15/10", "completed_at": NOW, "master_video_path": master})
        video_id = self.video(purpose="live_and_product", campaign_id=str(campaign), file_path=full,
                              product_page_file_path=body)
        with patch.dict(os.environ, R2):
            self.assertEqual(media_publisher.publish_campaign_videos(str(campaign), client=FakeS3()), 1)
        stored = self.stored(video_id)
        self.assertEqual(stored["aired_at"], NOW)
        self.assertEqual(stored["campaign_name"], "Live 15/10")
        for path in (full, body, master):
            self.assertFalse(os.path.exists(path), path)

    def test_live_only_campaign_publishes_nothing(self):
        campaign = ObjectId()
        self.db.ai_idol_campaigns.insert_one({"_id": campaign, "status": "COMPLETED", "purpose": "live_only"})
        with patch.dict(os.environ, R2):
            self.assertEqual(media_publisher.publish_campaign_videos(str(campaign), client=FakeS3()), 0)

    def test_retry_skips_both_mode_clips_before_broadcast_ends(self):
        clip, body = self.file("a.mp4"), self.file("b.body.mp4")
        campaign = ObjectId()
        self.db.ai_idol_campaigns.insert_one({"_id": campaign, "status": "SCHEDULED", "purpose": "live_and_product"})
        ready = self.video(purpose="product_video", product_page_file_path=clip, file_path=clip)
        waiting = self.video(purpose="live_and_product", campaign_id=str(campaign), product_page_file_path=body)
        exhausted = self.video(purpose="product_video", product_page_file_path=clip, media={"attempts": 5})
        with patch.dict(os.environ, R2):
            self.assertEqual(media_publisher.retry_pending(client=FakeS3()), 1)
        self.assertIn("product_page_ready_at", self.stored(ready))
        self.assertNotIn("product_page_ready_at", self.stored(waiting))
        self.assertNotIn("product_page_ready_at", self.stored(exhausted))


class BackfillTests(PublisherTestCase):
    def test_dry_run_writes_nothing(self):
        clip = self.file("old.mp4")
        video_id = self.video(file_path=clip)
        with patch.dict(os.environ, R2):
            rows = media_publisher.backfill(apply=False, client=FakeS3())
        self.assertEqual([row["video_id"] for row in rows], [video_id])
        self.assertNotIn("product_page_backfill", self.stored(video_id))

    def test_apply_publishes_old_videos_with_files_and_keeps_their_files(self):
        clip = self.file("old.mp4")
        old = self.video(file_path=clip)
        missing = self.video(file_path=os.path.join(self.folder.name, "gone.mp4"))
        with patch.dict(os.environ, R2):
            rows = media_publisher.backfill(apply=True, client=FakeS3())
        self.assertEqual([row["video_id"] for row in rows], [old])
        stored = self.stored(old)
        self.assertTrue(stored["product_page_backfill"])
        self.assertIn("product_page_ready_at", stored)
        self.assertTrue(os.path.isfile(clip))
        self.assertNotIn("product_page_backfill", self.stored(missing))
        listed = product_videos.list_product_videos("93")["videos"]
        self.assertEqual([item["video_id"] for item in listed], [old])


class BackfillScopeTests(PublisherTestCase):
    """Review C1: the one-time backfill only touches videos made before purposes existed."""

    def test_backfill_skips_every_video_that_already_has_a_purpose(self):
        clip = self.file("c.mp4")
        legacy = self.video(file_path=clip)
        self.video(purpose="live_only", file_path=clip)
        self.video(purpose="live_and_product", file_path=clip, product_page_file_path=None,
                   product_page_block_reason="missing_price_marker")
        self.video(purpose="product_video", file_path=clip, product_page_file_path=clip)
        with patch.dict(os.environ, R2):
            rows = media_publisher.backfill(apply=False, client=FakeS3())
        self.assertEqual([row["video_id"] for row in rows], [legacy])


class BroadcastHookTests(unittest.TestCase):
    def test_finalize_queues_publish_only_for_product_page_campaigns(self):
        db = FakeDb()
        both, live = ObjectId(), ObjectId()
        for campaign_id, purpose in ((both, "live_and_product"), (live, "live_only")):
            db.ai_idol_campaigns.insert_one({"_id": campaign_id, "status": "LIVE", "purpose": purpose,
                                             "platforms": ["internal"], "ends_at": NOW - dt.timedelta(minutes=1)})
        sent = []
        with patch.object(broadcast_engine, "get_db", return_value=db), \
                patch.object(broadcast_engine, "_send_publish_task", side_effect=sent.append):
            broadcast_engine.finalize_live_campaigns(now=NOW)
        self.assertEqual(sent, [str(both)])


class TaskWiringTests(unittest.TestCase):
    def run_job(self, purpose):
        import tasks
        db = FakeDb()
        job_id = ObjectId()
        db.ai_idol_jobs.insert_one({"_id": job_id, "status": "READY", "purpose": purpose, "video_id": "v1"})
        with patch.object(tasks, "get_db", return_value=db),                 patch.object(tasks, "update_campaign_from_job", return_value=None),                 patch.object(tasks.publish_product_video_task, "delay") as publish:
            tasks.generate_job_task(str(job_id))
        return publish

    def test_ready_product_video_job_is_published_right_away(self):
        self.run_job("product_video").assert_called_once_with("v1")

    def test_livestream_jobs_wait_for_the_broadcast(self):
        self.run_job("live_and_product").assert_not_called()
        self.run_job("live_only").assert_not_called()

    def test_retry_beat_is_scheduled_every_ten_minutes(self):
        from celery_app import celery_app
        entry = next(item for item in celery_app.conf.beat_schedule.values()
                     if item["task"] == "ai_idol.retry_product_video_publish")
        self.assertEqual(entry["schedule"], 600.0)


if __name__ == "__main__":
    unittest.main()
