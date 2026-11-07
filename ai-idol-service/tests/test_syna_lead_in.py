import io
import os
import sys
import tempfile
import unittest
import wave
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from engines import audio_mixer
from engines.audio_mixer import build_mix_command, lead_in_seconds, plan_mix_timing
from engines.syna_engine import build_manifest, padded_wav_bytes, shift_timings


class SynaPaddingTests(unittest.TestCase):
    def test_wav_gets_exact_silent_lead_in_and_tail(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder) / "voice.wav"
            with wave.open(str(path), "wb") as handle:
                handle.setnchannels(1); handle.setsampwidth(2); handle.setframerate(44100)
                handle.writeframes(b"\x01\x00" * 44100)
            padded = padded_wav_bytes(str(path), 2.5, 2.0)
        with wave.open(io.BytesIO(padded), "rb") as handle:
            frames = handle.readframes(handle.getnframes())
            self.assertEqual(handle.getnframes(), int(44100 * 5.5))
        lead = int(44100 * 2.5) * 2
        self.assertEqual(frames[:lead], bytes(lead))
        self.assertEqual(frames[lead:lead + 2], b"\x01\x00")

    def test_cues_shift_with_the_lead_in_and_manifest_carries_speech_window(self):
        timings = shift_timings([{"start": 0.0, "end": 4.0, "text": "Xin chào cả nhà"}], 2.5)
        self.assertEqual((timings[0]["start"], timings[0]["end"]), (2.5, 6.5))
        manifest = build_manifest({"name": "Gel"}, "Xin chào cả nhà", 8.5, timings, {"format": "16:9"},
                                  speech=(2.5, 6.5))
        self.assertEqual(manifest["speech"], {"start": 2.5, "end": 6.5})
        self.assertEqual(manifest["cues"][0]["start"], 2.5)
        self.assertNotIn("speech", build_manifest({}, "x", 5, [{"start": 0, "end": 5, "text": "x"}],
                                                  {"format": "16:9"}))

    def test_syna_and_mixer_agree_on_lead_in(self):
        intro, outro = lead_in_seconds("job-42", {})
        plan = plan_mix_timing("job-42", 130.0)
        self.assertEqual((intro, outro), (plan["intro"], plan["outro"]))
        self.assertEqual(lead_in_seconds("job-42", {"audio_mix_enabled": False}), (0.0, 0.0))


class PaddedVideoMixTests(unittest.TestCase):
    def test_music_mix_keeps_syna_frames_and_only_shifts_voice(self):
        cmd = build_mix_command("syna.mp4", "voice.wav", "m.mp3", "o.mp4", 20.0, 2.5, 2.0,
                                music_offset=10.0, video_padded=True)
        graph = cmd[cmd.index("-filter_complex") + 1]
        self.assertNotIn("tpad", graph)
        self.assertNotIn("[vout]", graph)
        self.assertIn("adelay=delays=2500:all=1", graph)
        self.assertEqual(cmd[cmd.index("-c:v") + 1], "copy")
        self.assertEqual(cmd[cmd.index("-t") + 1], "24.500")

    def test_voice_only_mix_shifts_voice_for_padded_video(self):
        cmd = build_mix_command("syna.mp4", "voice.wav", "", "o.mp4", 20.0, 2.5, 2.0, video_padded=True)
        self.assertIn("adelay=delays=2500", cmd[cmd.index("-filter_complex") + 1])
        self.assertEqual(cmd[cmd.index("-t") + 1], "24.500")
        self.assertNotIn("-shortest", cmd)

    def test_old_unpadded_render_is_mixed_without_shift(self):
        with tempfile.TemporaryDirectory() as folder:
            video, voice = Path(folder) / "syna.mp4", Path(folder) / "voice.wav"
            video.write_bytes(b"v" * 2048); voice.write_bytes(b"a" * 2048)
            calls = []
            ok = type("R", (), {"returncode": 0, "stderr": ""})()

            def run(cmd, **kwargs):
                calls.append(cmd); Path(cmd[-1]).write_bytes(b"x" * 2048); return ok

            # Video is as long as the voice: rendered before lead-ins existed.
            with patch.object(audio_mixer, "_probe_duration", return_value=20.0), \
                 patch.object(audio_mixer, "pick_music_track", return_value=""), \
                 patch.object(audio_mixer.subprocess, "run", side_effect=run):
                out = audio_mixer.mix_program_audio("job", str(video), str(voice), {}, video_padded=True)
        self.assertTrue(out.endswith(".mix.mp4"))
        self.assertNotIn("adelay", calls[0][calls[0].index("-filter_complex") + 1])


if __name__ == "__main__":
    unittest.main()
