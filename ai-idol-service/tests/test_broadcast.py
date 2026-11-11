import os
import sys
import unittest
from types import SimpleNamespace
from unittest.mock import patch


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from engines import broadcast_engine


class BroadcastCommandTests(unittest.TestCase):
    def test_rtmp_stream_loops_for_requested_campaign_duration(self):
        target = "rtmp://example.invalid/live/secret-key"
        completed = SimpleNamespace(returncode=0, stderr="")
        with patch.dict(broadcast_engine.RTMP_TARGETS, {"youtube": target}, clear=True), \
             patch.object(broadcast_engine, "_upsert_broadcast"), \
             patch.object(broadcast_engine.subprocess, "run", return_value=completed) as run:
            platform, ok, error = broadcast_engine._stream_target(
                "campaign-id", "youtube", "/storage/master.mp4", 3600
            )

        self.assertEqual((platform, ok, error), ("youtube", True, ""))
        command = run.call_args.args[0]
        self.assertIn("-stream_loop", command)
        self.assertEqual(command[command.index("-t") + 1], "3600")
        self.assertEqual(command[-1], target)

    def test_internal_preview_does_not_launch_ffmpeg(self):
        with patch.object(broadcast_engine, "_upsert_broadcast"), \
             patch.object(broadcast_engine.subprocess, "run") as run:
            result = broadcast_engine._stream_target(
                "campaign-id", "internal", "/storage/master.mp4", 3600
            )
        self.assertEqual(result, ("internal", True, ""))
        run.assert_not_called()



class BroadcastStartRetryTests(unittest.TestCase):
    """A DNS/Atlas blip at airtime must not kill the show: the first DB read is retried."""

    def test_transient_db_error_at_start_is_retried(self):
        from pymongo.errors import ConfigurationError
        campaign = {"_id": "c1", "status": "STARTING"}
        db = SimpleNamespace(ai_idol_campaigns=SimpleNamespace(find_one=lambda q: campaign))
        calls = []

        def flaky_get_db():
            calls.append(1)
            if len(calls) < 3:
                raise ConfigurationError("The DNS operation timed out.")
            return db

        with patch.object(broadcast_engine, "get_db", side_effect=flaky_get_db), \
                patch.object(broadcast_engine.time, "sleep") as sleep:
            got_db, got = broadcast_engine._load_campaign_with_retry("6af1e91b7eece27766053663")
        self.assertIs(got_db, db)
        self.assertIs(got, campaign)
        self.assertEqual(len(calls), 3)
        self.assertEqual(sleep.call_count, 2)

    def test_gives_up_after_last_attempt(self):
        from pymongo.errors import ServerSelectionTimeoutError
        with patch.object(broadcast_engine, "get_db", side_effect=ServerSelectionTimeoutError("down")), \
                patch.object(broadcast_engine.time, "sleep"):
            with self.assertRaises(ServerSelectionTimeoutError):
                broadcast_engine._load_campaign_with_retry("6af1e91b7eece27766053663")


if __name__ == "__main__":
    unittest.main()
