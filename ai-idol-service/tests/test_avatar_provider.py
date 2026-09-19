import unittest
from unittest.mock import Mock, patch

from providers.avatar_provider import SadTalkerProvider, Wav2LipProvider


class SadTalkerProviderTests(unittest.TestCase):
    def test_sadtalker_requires_portrait(self):
        import tempfile
        from pathlib import Path

        with tempfile.TemporaryDirectory() as folder:
            audio = Path(folder) / "voice.wav"
            audio.write_bytes(b"RIFF")
            with self.assertRaisesRegex(ValueError, "ảnh nhân vật"):
                SadTalkerProvider().generate(str(audio), "", str(Path(folder) / "out.mp4"))

    @patch("providers.avatar_provider.requests.post")
    def test_sadtalker_sends_shared_paths(self, mock_post):
        import tempfile
        from pathlib import Path

        with tempfile.TemporaryDirectory() as folder:
            audio = Path(folder) / "voice.wav"
            avatar = Path(folder) / "avatar.png"
            output = Path(folder) / "out.mp4"
            audio.write_bytes(b"RIFF")
            avatar.write_bytes(b"PNG")
            output.write_bytes(b"0" * 2048)
            mock_post.return_value = Mock(
                status_code=200,
                iter_content=lambda chunk_size: [b"0" * 2048],
                close=lambda: None,
            )

            self.assertTrue(
                SadTalkerProvider().generate(str(audio), str(avatar), str(output), "16:9")
            )
            self.assertEqual("16:9", mock_post.call_args.kwargs["data"]["video_format"])
            self.assertIn("avatar", mock_post.call_args.kwargs["files"])

    def test_wav2lip_requires_motion_video(self):
        import tempfile
        from pathlib import Path

        with tempfile.TemporaryDirectory() as folder:
            audio = Path(folder) / "voice.wav"
            avatar = Path(folder) / "avatar.png"
            audio.write_bytes(b"RIFF")
            avatar.write_bytes(b"PNG")
            with self.assertRaisesRegex(ValueError, "chỉ nhận video"):
                Wav2LipProvider().generate(
                    str(audio), str(avatar), str(Path(folder) / "out.mp4")
                )

    @patch("providers.avatar_provider.requests.post")
    def test_wav2lip_sends_motion_template_to_local_service(self, mock_post):
        import tempfile
        from pathlib import Path

        with tempfile.TemporaryDirectory() as folder:
            audio = Path(folder) / "voice.wav"
            avatar = Path(folder) / "avatar.mp4"
            output = Path(folder) / "out.mp4"
            audio.write_bytes(b"RIFF")
            avatar.write_bytes(b"video")
            mock_post.return_value = Mock(
                status_code=200,
                iter_content=lambda chunk_size: [b"0" * 2048],
                close=lambda: None,
            )

            self.assertTrue(
                Wav2LipProvider().generate(str(audio), str(avatar), str(output), "9:16")
            )
            sent_avatar = mock_post.call_args.kwargs["files"]["avatar"]
            self.assertEqual("avatar.mp4", sent_avatar[0])


if __name__ == "__main__":
    unittest.main()
