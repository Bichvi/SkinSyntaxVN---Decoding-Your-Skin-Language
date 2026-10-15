"""Task 6b: finished product-page videos publish themselves into ai_product_videos (spec D10, D12)."""
import datetime as dt
import os
import sys
import tempfile
import unittest
from unittest.mock import MagicMock, patch

from bson import ObjectId

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import core.agent_runs as agent_runs
import core.publications as publications
from engines import media_publisher

NOW = dt.datetime(2026, 10, 15, 9, 0, tzinfo=dt.timezone.utc)
R2_URL = "https://pub-test.r2.dev/product-videos/abc.mp4"
NO_VALKEY = {"VALKEY_URL": "", "CACHE_PREFIX": "skinsyntax:"}


class FakeValkey:
    """Records SCAN/DEL like redis-py; `keys` is what the server would hold."""

    def __init__(self, keys=(), fail=None):
        self.keys, self.fail = list(keys), fail
        self.scans, self.deleted, self.closed = [], [], False

    def scan_iter(self, match=None, count=None):
        if self.fail:
            raise self.fail
        self.scans.append((match, count))
        return iter([key for key in self.keys if key.startswith(match.rstrip("*"))])

    def delete(self, *keys):
        self.deleted.extend(keys)
        return len(keys)

    def close(self):
        self.closed = True


class PublicationTestCase(unittest.TestCase):
    def setUp(self):
        self.db = FakeDb()
        for target in (publications, agent_runs):
            patcher = patch.object(target, "get_db", return_value=self.db)
            patcher.start()
            self.addCleanup(patcher.stop)
        self.purges = patch.object(publications, "purge_product_video_cache", return_value=0)
        self.purge = self.purges.start()
        self.addCleanup(self.purges.stop)
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)

    def file(self, name="clip.mp4"):
        path = os.path.join(self.folder.name, name)
        with open(path, "wb") as handle:
            handle.write(b"x" * 2048)
        return path

    def campaign(self, *, name="Routine da dầu tháng 10", run_id=None, avatar_mode=None):
        configuration = {}
        if run_id:
            configuration["agent_run_id"] = run_id
        if avatar_mode:
            configuration["avatar_mode"] = avatar_mode
        campaign_id = ObjectId()
        self.db.ai_idol_campaigns.insert_one({"_id": campaign_id, "name": name, "configuration": configuration})
        return str(campaign_id)

    def run_doc(self):
        run_id = ObjectId()
        self.db.ai_idol_agent_runs.insert_one({"_id": run_id, "decisions": []})
        return str(run_id)

    def video(self, **fields):
        doc = {
            "_id": ObjectId(), "job_id": str(ObjectId()), "product_id": "93", "purpose": "product_video",
            "campaign_id": None, "duration": 42.5, "format": "16:9", "resolution": "1280x720",
            "file_path": "/app/storage/v.mp4", "product_page_file_path": "/app/storage/v.mp4",
        }
        doc.update(fields)
        self.db.ai_idol_videos.insert_one(doc)
        return str(doc["_id"])

    def publications_for(self, video_id):
        return self.db.ai_product_videos.find({"video_id": video_id})

    def decisions(self, run_id):
        return self.db.ai_idol_agent_runs.find_one({"_id": ObjectId(run_id)})["decisions"]


class CreatesRecordTests(PublicationTestCase):
    def test_product_video_gets_a_published_record_with_the_publish_video_schema(self):
        campaign_id = self.campaign(name="Routine da dầu tháng 10", avatar_mode="syna_3d")
        video_id = self.video(campaign_id=campaign_id, job_id="job-1", format="9:16", resolution="720x1280")
        segment_id = ObjectId()
        self.db.ai_idol_segments.insert_one({"_id": segment_id, "video_id": video_id, "campaign_id": campaign_id})

        result = publications.auto_publish_video(video_id)

        stored = self.publications_for(video_id)
        self.assertEqual(len(stored), 1)
        record = stored[0]
        self.assertEqual(record["video_id"], video_id)
        self.assertEqual(record["job_id"], "job-1")
        self.assertEqual(record["campaign_id"], campaign_id)
        self.assertEqual(record["segment_id"], str(segment_id))
        self.assertEqual(record["title"], "Routine da dầu tháng 10")
        self.assertEqual(record["duration"], 42.5)
        self.assertEqual(record["format"], "9:16")
        self.assertEqual(record["resolution"], "720x1280")
        self.assertEqual(record["avatar_mode"], "syna_3d")
        self.assertEqual(record["associated_product_ids"], ["93"])
        self.assertEqual(record["primary_product_id"], "93")
        self.assertEqual(record["display_priority"], 10)
        self.assertIs(record["is_published"], True)
        self.assertEqual(record["source"], "auto")
        for field in ("created_at", "updated_at", "published_at"):
            self.assertIsInstance(record[field], dt.datetime)
        self.assertEqual(result["video_id"], video_id)
        self.assertEqual(result["source"], "auto")
        self.purge.assert_called_once_with()

    def test_defaults_fill_in_when_the_video_has_no_campaign_or_segment(self):
        video_id = self.video()
        publications.auto_publish_video(video_id)
        record = self.publications_for(video_id)[0]
        self.assertEqual(record["title"], "Video tư vấn sản phẩm Syna 3D")
        self.assertEqual(record["campaign_id"], "")
        self.assertEqual(record["segment_id"], "")
        self.assertEqual(record["avatar_mode"], "syna_3d")

    def test_live_and_product_publishes_its_price_free_clip(self):
        clip = self.file("full.body.mp4")
        video_id = self.video(purpose="live_and_product", file_path="/app/storage/full.mp4",
                              product_page_file_path=clip, duration=140.0)
        with patch.object(publications, "probe_duration", return_value=48.25) as probe:
            publications.auto_publish_video(video_id)
        probe.assert_called_once_with(clip)
        self.assertEqual(self.publications_for(video_id)[0]["duration"], 48.25)  # the body, not the full render

    def test_uploaded_clip_duration_wins_without_probing(self):
        video_id = self.video(purpose="live_and_product", file_path="/app/storage/full.mp4",
                              product_page_file_path="/app/storage/gone.mp4", duration=140.0,
                              media={"video_url": R2_URL, "duration": 47.5})
        with patch.object(publications, "probe_duration") as probe:
            publications.auto_publish_video(video_id)
        probe.assert_not_called()
        self.assertEqual(self.publications_for(video_id)[0]["duration"], 47.5)

    def test_unprobeable_clip_falls_back_to_the_render_duration(self):
        clip = self.file("full.body.mp4")
        video_id = self.video(purpose="live_and_product", file_path="/app/storage/full.mp4",
                              product_page_file_path=clip, duration=140.0)
        with patch.object(publications, "probe_duration", side_effect=RuntimeError("ffprobe missing")):
            publications.auto_publish_video(video_id)
        self.assertEqual(self.publications_for(video_id)[0]["duration"], 140.0)

    def test_clip_that_only_lives_on_r2_still_publishes(self):
        video_id = self.video(product_page_file_path=None, media={"video_url": R2_URL})
        self.assertIsNotNone(publications.auto_publish_video(video_id))
        self.assertEqual(len(self.publications_for(video_id)), 1)

    def test_decision_is_appended_to_the_agent_run(self):
        run_id = self.run_doc()
        campaign_id = self.campaign(run_id=run_id)
        video_id = self.video(campaign_id=campaign_id)
        publications.auto_publish_video(video_id)
        decisions = self.decisions(run_id)
        self.assertEqual(len(decisions), 1)
        self.assertEqual(decisions[0]["code"], "PRODUCT_PAGE_PUBLISHED")
        self.assertEqual(decisions[0]["level"], "done")
        self.assertTrue(decisions[0]["message"].startswith("Đã đăng video lên trang sản phẩm"))
        self.assertIn("93", decisions[0]["message"])

    def test_decision_names_the_product_when_the_job_snapshot_has_one(self):
        run_id = self.run_doc()
        campaign_id = self.campaign(run_id=run_id)
        job_id = ObjectId()
        self.db.ai_idol_jobs.insert_one({"_id": job_id, "product_snapshot": {"name": "Kem chống nắng X"}})
        video_id = self.video(campaign_id=campaign_id, job_id=str(job_id))
        publications.auto_publish_video(video_id)
        self.assertIn("Kem chống nắng X", self.decisions(run_id)[0]["message"])

    def test_no_agent_run_means_no_decision_and_no_failure(self):
        video_id = self.video(campaign_id=self.campaign())
        self.assertIsNotNone(publications.auto_publish_video(video_id))
        self.assertEqual(self.db.ai_idol_agent_runs.find(), [])


class NoRecordTests(PublicationTestCase):
    def assertNothingPublished(self, video_id):
        self.assertIsNone(publications.auto_publish_video(video_id))
        self.assertEqual(self.db.ai_product_videos.find(), [])
        self.purge.assert_not_called()

    def test_live_only_video_is_never_published(self):
        self.assertNothingPublished(self.video(purpose="live_only"))

    def test_legacy_video_without_purpose_is_never_published(self):
        self.assertNothingPublished(self.video(purpose=None))

    def test_hidden_video_is_not_published(self):
        self.assertNothingPublished(self.video(hidden_from_product_page=True))

    def test_video_without_a_price_free_file_is_not_published(self):
        self.assertNothingPublished(self.video(purpose="live_and_product", product_page_file_path=None,
                                               product_page_block_reason="missing_price_marker"))

    def test_video_without_a_product_is_not_published(self):
        self.assertNothingPublished(self.video(product_id=""))

    def test_unknown_or_malformed_video_id_is_ignored(self):
        self.assertIsNone(publications.auto_publish_video("v1"))
        self.assertIsNone(publications.auto_publish_video(str(ObjectId())))
        self.assertIsNone(publications.auto_publish_video(""))
        self.assertEqual(self.db.ai_product_videos.find(), [])


class IdempotencyTests(PublicationTestCase):
    def test_second_call_leaves_one_record_and_does_no_more_work(self):
        run_id = self.run_doc()
        video_id = self.video(campaign_id=self.campaign(run_id=run_id))
        first = publications.auto_publish_video(video_id)
        second = publications.auto_publish_video(video_id)
        self.assertIsNotNone(first)
        self.assertIsNone(second)
        self.assertEqual(len(self.publications_for(video_id)), 1)
        self.assertEqual(self.purge.call_count, 1)
        self.assertEqual(len(self.decisions(run_id)), 1)

    def test_a_record_the_admin_took_down_is_left_exactly_as_it_is(self):
        video_id = self.video()
        admin_record = {
            "_id": ObjectId(), "video_id": video_id, "title": "Tiêu đề do admin sửa", "is_published": False,
            "display_priority": 99, "associated_product_ids": ["93", "94"], "source": "admin",
        }
        self.db.ai_product_videos.insert_one(admin_record)
        self.assertIsNone(publications.auto_publish_video(video_id))
        stored = self.publications_for(video_id)
        self.assertEqual(len(stored), 1)
        self.assertEqual(stored[0], admin_record)
        self.assertEqual(self.db.ai_product_videos.writes, [])
        self.purge.assert_not_called()

    def test_a_record_created_between_the_check_and_the_write_is_not_overwritten(self):
        video_id = self.video()
        collection = self.db.ai_product_videos
        original = collection.update_one

        def lose_the_race(query, update, **kwargs):
            # Someone else's record lands first; the upsert must find it and leave it alone.
            collection.insert_one({"_id": ObjectId(), "video_id": video_id, "is_published": False, "source": "admin"})
            return original(query, update, **kwargs)

        with patch.object(collection, "update_one", side_effect=lose_the_race):
            self.assertIsNone(publications.auto_publish_video(video_id))
        stored = self.publications_for(video_id)
        self.assertEqual(len(stored), 1)
        self.assertEqual(stored[0]["source"], "admin")
        self.assertIs(stored[0]["is_published"], False)


class NeverFailsTests(PublicationTestCase):
    def test_database_errors_are_logged_not_raised(self):
        video_id = self.video()
        with patch.object(self.db.ai_product_videos, "update_one", side_effect=RuntimeError("mongo down")), \
                patch.object(publications, "logger") as log:
            self.assertIsNone(publications.auto_publish_video(video_id))
        log.warning.assert_called_once()

    def test_unreadable_video_documents_are_logged_not_raised(self):
        with patch.object(self.db.ai_idol_videos, "find_one", side_effect=RuntimeError("mongo down")), \
                patch.object(publications, "logger") as log:
            self.assertIsNone(publications.auto_publish_video(str(ObjectId())))
        log.warning.assert_called_once()


class PurgeCacheTests(unittest.TestCase):
    def test_deletes_product_video_keys_under_the_cache_prefix(self):
        client = FakeValkey(keys=["skinsyntax:product:videos:93", "skinsyntax:product:videos:94", "skinsyntax:menu"])
        with patch.dict(os.environ, {"VALKEY_URL": "rediss://default:s3cret@cache.example:1234", "CACHE_PREFIX": "skinsyntax:"}), \
                patch.object(publications, "_valkey_client", return_value=client) as factory:
            deleted = publications.purge_product_video_cache()
        factory.assert_called_once_with("rediss://default:s3cret@cache.example:1234")
        self.assertEqual(client.scans[0][0], "skinsyntax:product:videos:*")
        self.assertEqual(sorted(client.deleted), ["skinsyntax:product:videos:93", "skinsyntax:product:videos:94"])
        self.assertEqual(deleted, 2)
        self.assertTrue(client.closed)

    def test_prefix_defaults_to_skinsyntax_and_glob_characters_are_escaped(self):
        client = FakeValkey()
        with patch.dict(os.environ, {"VALKEY_URL": "redis://cache:6379/0"}), \
                patch.object(publications, "_valkey_client", return_value=client):
            os.environ.pop("CACHE_PREFIX", None)
            publications.purge_product_video_cache()
            os.environ["CACHE_PREFIX"] = "shop[1]*:"
            publications.purge_product_video_cache()
        self.assertEqual(client.scans[0][0], "skinsyntax:product:videos:*")
        self.assertEqual(client.scans[1][0], "shop\\[1\\]\\*:product:videos:*")

    def test_missing_valkey_url_is_skipped_with_a_warning(self):
        with patch.dict(os.environ, NO_VALKEY), patch.object(publications, "_valkey_client") as factory, \
                patch.object(publications, "logger") as log:
            self.assertEqual(publications.purge_product_video_cache(), 0)
        factory.assert_not_called()
        log.warning.assert_called_once()

    def test_connection_errors_are_swallowed_and_never_leak_the_url(self):
        secret_url = "rediss://default:s3cret-pass@cache.example:1234"
        client = FakeValkey(fail=ConnectionError(f"cannot reach {secret_url}"))
        with patch.dict(os.environ, {"VALKEY_URL": secret_url}), \
                patch.object(publications, "_valkey_client", return_value=client), \
                patch.object(publications, "logger") as log:
            self.assertEqual(publications.purge_product_video_cache(), 0)
        log.warning.assert_called_once()
        self.assertNotIn("s3cret-pass", " ".join(str(arg) for arg in log.warning.call_args.args))
        self.assertTrue(client.closed)

    def test_client_construction_errors_are_swallowed(self):
        with patch.dict(os.environ, {"VALKEY_URL": "not a url"}), \
                patch.object(publications, "_valkey_client", side_effect=ValueError("bad scheme")), \
                patch.object(publications, "logger") as log:
            self.assertEqual(publications.purge_product_video_cache(), 0)
        log.warning.assert_called_once()

    def test_keys_are_deleted_in_batches(self):
        keys = [f"skinsyntax:product:videos:{n}" for n in range(250)]
        client = FakeValkey(keys=keys)
        with patch.dict(os.environ, {"VALKEY_URL": "redis://cache:6379/0", "CACHE_PREFIX": "skinsyntax:"}), \
                patch.object(publications, "_valkey_client", return_value=client):
            self.assertEqual(publications.purge_product_video_cache(), 250)
        self.assertEqual(client.deleted, keys)

    def test_real_client_uses_short_socket_timeouts(self):
        fake_redis = MagicMock()
        with patch.dict(sys.modules, {"redis": fake_redis}):
            publications._valkey_client("rediss://default:pw@cache.example:1234")
        kwargs = fake_redis.Redis.from_url.call_args.kwargs
        self.assertLessEqual(kwargs["socket_timeout"], 2)
        self.assertLessEqual(kwargs["socket_connect_timeout"], 2)


class HookTests(PublicationTestCase):
    def test_ready_product_video_job_publishes_to_the_product_page(self):
        import tasks
        job_id = ObjectId()
        self.db.ai_idol_jobs.insert_one({"_id": job_id, "status": "READY", "purpose": "product_video", "video_id": "v1"})
        with patch.object(tasks, "get_db", return_value=self.db), \
                patch.object(tasks, "update_campaign_from_job", return_value=None), \
                patch.object(tasks.publish_product_video_task, "delay"), \
                patch.object(tasks, "auto_publish_video") as auto:
            self.assertTrue(tasks.generate_job_task(str(job_id)))
        auto.assert_called_once_with("v1")

    def test_livestream_jobs_wait_for_the_broadcast(self):
        import tasks
        for purpose in ("live_and_product", "live_only"):
            job_id = ObjectId()
            self.db.ai_idol_jobs.insert_one({"_id": job_id, "status": "READY", "purpose": purpose, "video_id": "v1"})
            with patch.object(tasks, "get_db", return_value=self.db), \
                    patch.object(tasks, "update_campaign_from_job", return_value=None), \
                    patch.object(tasks.publish_product_video_task, "delay"), \
                    patch.object(tasks, "auto_publish_video") as auto:
                tasks.generate_job_task(str(job_id))
            auto.assert_not_called()

    def test_failed_job_publishes_nothing(self):
        import tasks
        job_id = ObjectId()
        self.db.ai_idol_jobs.insert_one({"_id": job_id, "status": "FAILED", "purpose": "product_video"})
        with patch.object(tasks, "get_db", return_value=self.db), \
                patch.object(tasks, "run_ai_idol_pipeline", return_value=False), \
                patch.object(tasks, "update_campaign_from_job", return_value=None), \
                patch.object(tasks, "auto_publish_video") as auto:
            self.assertFalse(tasks.generate_job_task(str(job_id)))
        auto.assert_not_called()

    def finished_show(self, purpose="live_and_product", status="COMPLETED"):
        campaign = ObjectId()
        self.db.ai_idol_campaigns.insert_one({"_id": campaign, "status": status, "purpose": purpose, "name": "Live 15/10"})
        first = self.video(purpose=purpose, campaign_id=str(campaign), product_page_file_path="/app/storage/a.body.mp4")
        second = self.video(purpose=purpose, campaign_id=str(campaign), product_page_file_path="/app/storage/b.body.mp4")
        return str(campaign), [first, second]

    def test_finished_show_publishes_each_clip_even_when_the_r2_upload_fails(self):
        campaign, videos = self.finished_show()
        with patch.object(media_publisher, "get_db", return_value=self.db), \
                patch.object(media_publisher, "publish_video", return_value=False) as upload, \
                patch.object(media_publisher, "auto_publish_video") as auto:
            media_publisher.publish_campaign_videos(campaign)
        self.assertEqual(sorted(call.args[0] for call in auto.call_args_list), sorted(videos))
        self.assertEqual(upload.call_count, 2)

    def test_clip_is_published_after_its_upload_attempt(self):
        campaign, _ = self.finished_show()
        order = []
        with patch.object(media_publisher, "get_db", return_value=self.db), \
                patch.object(media_publisher, "publish_video", side_effect=lambda *a, **k: order.append("upload") or True), \
                patch.object(media_publisher, "auto_publish_video", side_effect=lambda *a, **k: order.append("page")):
            media_publisher.publish_campaign_videos(campaign)
        self.assertEqual(order, ["upload", "page", "upload", "page"])

    def test_show_still_on_air_or_live_only_publishes_nothing(self):
        for purpose, status in (("live_and_product", "LIVE"), ("live_only", "COMPLETED")):
            campaign, _ = self.finished_show(purpose, status)
            with patch.object(media_publisher, "get_db", return_value=self.db), \
                    patch.object(media_publisher, "publish_video", return_value=True), \
                    patch.object(media_publisher, "auto_publish_video") as auto:
                media_publisher.publish_campaign_videos(campaign)
            auto.assert_not_called()


class RouteTests(unittest.TestCase):
    def setUp(self):
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        self.app = service_app
        self.client = service_app.app.test_client()
        self.db = FakeDb()
        patcher = patch.object(service_app, "get_db", return_value=self.db)
        patcher.start()
        self.addCleanup(patcher.stop)
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)

    def file(self, name, content):
        path = os.path.join(self.folder.name, name)
        with open(path, "wb") as handle:
            handle.write(content)
        return path

    def video(self, **fields):
        doc = {"_id": ObjectId(), "product_id": "93", "purpose": "product_video"}
        doc.update(fields)
        self.db.ai_idol_videos.insert_one(doc)
        return str(doc["_id"])

    def get(self, video_id, query="?rendition=product_page", **kwargs):
        response = self.client.get(f"/api/ai-idol/videos/{video_id}{query}", **kwargs)
        response.get_data()  # buffer the streamed file, then release its handle
        response.close()
        return response

    def test_product_page_rendition_streams_the_price_free_file_with_range_support(self):
        full = self.file("full.mp4", b"FULL-RENDER-WITH-PRICE")
        body = self.file("full.body.mp4", b"0123456789")
        video_id = self.video(purpose="live_and_product", file_path=full, product_page_file_path=body)
        response = self.get(video_id)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.data, b"0123456789")
        self.assertEqual(response.mimetype, "video/mp4")
        ranged = self.get(video_id, headers={"Range": "bytes=2-5"})
        self.assertEqual(ranged.status_code, 206)
        self.assertEqual(ranged.data, b"2345")

    def test_missing_local_file_redirects_to_the_r2_copy(self):
        video_id = self.video(file_path="/gone/full.mp4", product_page_file_path="/gone/page.mp4",
                              media={"video_url": R2_URL})
        response = self.get(video_id)
        self.assertEqual(response.status_code, 302)
        self.assertEqual(response.headers["Location"], R2_URL)

    def test_local_file_wins_over_the_r2_copy(self):
        page = self.file("page.mp4", b"LOCAL")
        video_id = self.video(file_path=page, product_page_file_path=page, media={"video_url": R2_URL})
        response = self.get(video_id)
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.data, b"LOCAL")

    def test_live_only_video_is_never_served_in_this_rendition(self):
        page = self.file("page.mp4", b"x")
        for fields in ({"purpose": "live_only"}, {"purpose": None}):
            video_id = self.video(file_path=page, product_page_file_path=page, media={"video_url": R2_URL}, **fields)
            self.assertEqual(self.get(video_id).status_code, 404)

    def test_the_full_render_is_never_a_fallback(self):
        full = self.file("full.mp4", b"FULL-RENDER-WITH-PRICE")
        video_id = self.video(purpose="live_and_product", file_path=full, product_page_file_path=None)
        response = self.get(video_id)
        self.assertEqual(response.status_code, 404)
        self.assertNotIn(b"FULL-RENDER", response.data)

    def test_nothing_to_serve_is_404(self):
        video_id = self.video(file_path="/gone/full.mp4", product_page_file_path="/gone/page.mp4")
        self.assertEqual(self.get(video_id).status_code, 404)
        self.assertEqual(self.get(str(ObjectId())).status_code, 404)

    def test_without_the_parameter_the_full_render_is_served_as_before(self):
        full = self.file("full.mp4", b"FULL-RENDER")
        body = self.file("full.body.mp4", b"BODY")
        video_id = self.video(purpose="live_only", file_path=full, product_page_file_path=body)
        response = self.get(video_id, query="")
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.data, b"FULL-RENDER")

    def test_unknown_rendition_is_rejected_instead_of_serving_the_full_render(self):
        full = self.file("full.mp4", b"FULL-RENDER")
        video_id = self.video(file_path=full, product_page_file_path=full)
        response = self.get(video_id, query="?rendition=original")
        self.assertEqual(response.status_code, 400)
        self.assertNotIn(b"FULL-RENDER", response.data)


if __name__ == "__main__":
    unittest.main()
