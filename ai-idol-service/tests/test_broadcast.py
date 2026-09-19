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


if __name__ == "__main__":
    unittest.main()
