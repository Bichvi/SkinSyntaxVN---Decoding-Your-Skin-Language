import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from engines import avatar_engine


class AvatarEngineTests(unittest.TestCase):
    def test_motion_template_automatically_selects_wav2lip(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            avatars = root / "avatars"
            backgrounds = root / "backgrounds"
            output = root / "output"
            for directory in (avatars, backgrounds, output):
                directory.mkdir()
            (avatars / "presenter.mp4").write_bytes(b"video")
            audio = root / "speech.wav"
            audio.write_bytes(b"RIFF")

            def fake_generate(_provider, _audio, avatar, output_path, *_args):
                self.assertTrue(avatar.endswith("presenter.mp4"))
                Path(output_path).write_bytes(b"rendered")
                return True

            with patch.object(avatar_engine, "AVATARS_DIR", avatars), \
                    patch.object(avatar_engine, "BACKGROUNDS_DIR", backgrounds), \
                    patch.object(avatar_engine, "OUTPUT_DIR", output), \
                    patch.object(
                        avatar_engine.Wav2LipProvider,
                        "generate",
                        autospec=True,
                        side_effect=fake_generate,
                    ) as generate:
                result = avatar_engine.generate_avatar_video(
                    "job-1", str(audio), {"avatar_asset": "presenter.mp4"}
                )

            self.assertTrue(os.path.isfile(result))
            generate.assert_called_once()


if __name__ == "__main__":
    unittest.main()
