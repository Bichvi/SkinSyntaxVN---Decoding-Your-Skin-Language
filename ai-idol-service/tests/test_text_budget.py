import os
import sys
import unittest


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from core.text_budget import clean_text, clip_text, fit_message_pair
from engines.video_composer import detect_avatar_extension, detect_image_extension


class TextBudgetTests(unittest.TestCase):
    def test_clean_text_collapses_database_whitespace(self):
        self.assertEqual(clean_text("  alpha\n\n beta\t gamma  "), "alpha beta gamma")

    def test_clip_text_never_exceeds_limit_and_keeps_both_ends(self):
        source = "BEGIN " + ("x" * 300) + " END"
        result = clip_text(source, 100)
        self.assertLessEqual(len(result), 100)
        self.assertTrue(result.startswith("BEGIN"))
        self.assertTrue(result.endswith("END"))

    def test_message_pair_stays_inside_provider_budget(self):
        system, user = fit_message_pair("s" * 6000, "u" * 9000, 5000)
        self.assertLessEqual(len(system) + len(user), 5000)
        self.assertGreaterEqual(len(system), 500)
        self.assertGreaterEqual(len(user), 500)

    def test_downloaded_image_format_is_verified_by_signature(self):
        self.assertEqual(detect_image_extension(b"\x89PNG\r\n\x1a\nrest"), ".png")
        self.assertEqual(detect_image_extension(b"\xff\xd8\xffrest"), ".jpg")
        self.assertEqual(detect_image_extension(b"image not found"), "")

    def test_avatar_motion_video_format_is_verified_by_signature(self):
        self.assertEqual(detect_avatar_extension(b"\x00\x00\x00\x18ftypisomrest"), ".mp4")
        self.assertEqual(detect_avatar_extension(b"\x1a\x45\xdf\xa3webm-rest"), ".webm")
        self.assertEqual(detect_avatar_extension(b"not a media file"), "")


if __name__ == "__main__":
    unittest.main()
