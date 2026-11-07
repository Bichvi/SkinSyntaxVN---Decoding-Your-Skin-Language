import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from engines import audio_mixer
from engines.audio_mixer import (
    build_filter_graph, build_mix_command, mix_program_audio, pick_music_track, plan_mix_timing,
)


class MusicPickTests(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        for name in ("a.mp3", "b.wav", "notes.txt"):
            (Path(self.folder.name) / name).write_bytes(b"x")

    def test_pick_is_stable_and_ignores_non_audio(self):
        first = pick_music_track("job-1", {}, self.folder.name)
        self.assertIn(os.path.basename(first), {"a.mp3", "b.wav"})
        self.assertEqual(first, pick_music_track("job-1", {}, self.folder.name))

    def test_requested_track_and_opt_out(self):
        self.assertTrue(pick_music_track("j", {"music_track": "b.wav"}, self.folder.name).endswith("b.wav"))
        self.assertEqual(pick_music_track("j", {"music_enabled": False}, self.folder.name), "")
        self.assertEqual(pick_music_track("j", {"music_enabled": "false"}, self.folder.name), "")

    def test_path_traversal_track_falls_back_to_library(self):
        picked = pick_music_track("j", {"music_track": "../b.wav"}, self.folder.name)
        self.assertEqual(Path(picked).parent, Path(self.folder.name))

    def test_empty_library_means_no_music(self):
        with tempfile.TemporaryDirectory() as empty:
            self.assertEqual(pick_music_track("j", {}, empty), "")


class CommandTests(unittest.TestCase):
    def test_music_graph_ducks_pads_and_limits(self):
        graph = build_filter_graph(20.0, 1.5, 2.0, music_offset=12.0, music_lufs=-22, target_lufs=-14)
        self.assertIn("loudnorm=I=-22", graph)
        self.assertIn("sidechaincompress", graph)
        self.assertIn("loudnorm=I=-14", graph)
        self.assertIn("adelay=delays=1500:all=1", graph)
        self.assertIn("apad=whole_dur=23.500", graph)
        self.assertIn("tpad=start_duration=1.500:stop_duration=2.000", graph)
        self.assertIn("atrim=12.000:35.500", graph)
        self.assertIn("afade=t=out:st=22.100:d=1.40", graph)

    def test_music_command_reencodes_only_when_padded(self):
        padded = build_mix_command("v.mp4", "voice.wav", "m.mp3", "o.mp4", 10.0, 1.0, 1.0)
        self.assertIn("libx264", padded)
        self.assertEqual(padded[padded.index("-t") + 1], "12.000")
        unpadded = build_mix_command("v.mp4", "voice.wav", "m.mp3", "o.mp4", 10.0, 0, 0)
        self.assertEqual(unpadded[unpadded.index("-c:v") + 1], "copy")

    def test_voice_only_command_keeps_video_stream(self):
        cmd = build_mix_command("v.mp4", "voice.wav", "", "o.mp4", 10.0)
        self.assertNotIn("-stream_loop", cmd)
        self.assertEqual(cmd[cmd.index("-c:v") + 1], "copy")
        self.assertIn("loudnorm", cmd[cmd.index("-filter_complex") + 1])


class TimingTests(unittest.TestCase):
    def test_timing_is_stable_per_job_and_within_range(self):
        plans = [plan_mix_timing(f"job-{i}", 130.0, (2.0, 3.0), (2.0, 3.0)) for i in range(40)]
        self.assertEqual(plan_mix_timing("job-1", 130.0, (2.0, 3.0), (2.0, 3.0)), plans[1])
        for plan in plans:
            self.assertTrue(2.0 <= plan["intro"] <= 3.0 and 2.0 <= plan["outro"] <= 3.0)
            self.assertTrue(0.0 <= plan["music_offset"] <= 60.0)
        self.assertGreater(len({plan["intro"] for plan in plans}), 3)
        self.assertTrue(any(plan["music_offset"] == 0 for plan in plans))
        self.assertTrue(any(plan["music_offset"] > 0 for plan in plans))

    def test_short_track_always_starts_from_the_top(self):
        self.assertEqual(plan_mix_timing("job", 40.0)["music_offset"], 0.0)


class MixFallbackTests(unittest.TestCase):
    def test_missing_inputs_keep_original_without_ffmpeg(self):
        with patch.object(audio_mixer.subprocess, "run") as run:
            self.assertEqual(mix_program_audio("j", "/missing.mp4", "/missing.wav", {}), "/missing.mp4")
            run.assert_not_called()

    def test_ffmpeg_failure_keeps_original(self):
        with tempfile.TemporaryDirectory() as folder:
            video = Path(folder) / "final.mp4"
            voice = Path(folder) / "voice.wav"
            video.write_bytes(b"v" * 2048)
            voice.write_bytes(b"a" * 2048)
            failed = type("R", (), {"returncode": 1, "stderr": "boom"})()
            with patch.object(audio_mixer, "_probe_duration", return_value=5.0), \
                 patch.object(audio_mixer, "pick_music_track", return_value=""), \
                 patch.object(audio_mixer.subprocess, "run", return_value=failed):
                self.assertEqual(mix_program_audio("j", str(video), str(voice), {}), str(video))
            self.assertFalse((Path(folder) / "final.mix.partial.mp4").exists())

    def mix_ok(self, music, padded, probe=5.0):
        """Run a successful mix and return what the mixer reports it applied."""
        with tempfile.TemporaryDirectory() as folder:
            video = Path(folder) / "final.mp4"
            voice = Path(folder) / "voice.wav"
            video.write_bytes(b"v" * 2048)
            voice.write_bytes(b"a" * 2048)

            def ffmpeg(cmd, **kwargs):
                Path(cmd[-1]).write_bytes(b"m" * 4096)
                return type("R", (), {"returncode": 0, "stderr": ""})()

            applied = {}
            with patch.object(audio_mixer, "_probe_duration", return_value=probe), \
                 patch.object(audio_mixer, "pick_music_track", return_value=music), \
                 patch.object(audio_mixer, "plan_mix_timing", return_value={"intro": 2.5, "outro": 2.0, "music_offset": 0.0}), \
                 patch.object(audio_mixer, "lead_in_seconds", return_value=(2.5, 2.0)), \
                 patch.object(audio_mixer, "prepare_cards", return_value=None), \
                 patch.object(audio_mixer.subprocess, "run", side_effect=ffmpeg):
                out = mix_program_audio("j", str(video), str(voice), {}, video_padded=padded, applied=applied)
            self.assertNotEqual(out, str(video))
            return applied

    def test_review_c2_voice_only_mix_reports_no_lead_in(self):
        self.assertEqual(self.mix_ok(music="", padded=False)["intro"], 0.0)

    def test_review_c2_music_mix_reports_its_lead_in(self):
        self.assertEqual(self.mix_ok(music="/m.mp3", padded=False)["intro"], 2.5)

    def test_review_c2_mismatched_syna_render_reports_no_lead_in(self):
        # voice 5s + 2.5 + 2 != video 5s -> the mixer drops the lead-in.
        self.assertEqual(self.mix_ok(music="/m.mp3", padded=True)["intro"], 0.0)

    def test_job_level_opt_out(self):
        with patch.object(audio_mixer.subprocess, "run") as run:
            self.assertEqual(mix_program_audio("j", "/x.mp4", "/x.wav", {"audio_mix_enabled": False}), "/x.mp4")
            run.assert_not_called()


class MusicBedLevelTests(unittest.TestCase):
    def test_default_ducking_is_gentler_so_music_stays_audible(self):
        self.assertIn("sidechaincompress=threshold=0.04:ratio=3:", build_filter_graph(10, 2, 2))

    def test_duck_ratio_is_configurable(self):
        self.assertIn("sidechaincompress=threshold=0.04:ratio=5:", build_filter_graph(10, 2, 2, duck_ratio=5))

    def test_defaults_raise_the_bed_and_bump_the_pipeline_version(self):
        # Load a private copy: reloading the shared `config` module would swap objects
        # (e.g. RTMP_TARGETS) that other modules already imported.
        import importlib.util
        import config
        env = {key: value for key, value in os.environ.items()
               if key not in ("AI_IDOL_MUSIC_BED_LUFS", "AI_IDOL_MUSIC_DUCK_RATIO")}
        spec = importlib.util.spec_from_file_location("config_defaults_probe", config.__file__)
        fresh = importlib.util.module_from_spec(spec)
        with patch.dict(os.environ, env, clear=True):
            spec.loader.exec_module(fresh)
        self.assertEqual(fresh.MUSIC_BED_LUFS, -19.0)
        self.assertEqual(fresh.MUSIC_DUCK_RATIO, 3.0)
        self.assertEqual(fresh.AUDIO_PIPELINE_VERSION, "3")


if __name__ == "__main__":
    unittest.main()
