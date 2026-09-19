import os
import sys
import unittest


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from core.script_text import (
    clean_script_for_speech,
    find_external_retailer_mentions,
    sanitize_external_retailer_mentions,
    split_speech_sections,
)
from engines.video_composer import build_subtitle_cues


class SubtitleTimingTests(unittest.TestCase):
    def test_authoring_labels_and_list_markers_are_not_spoken(self):
        script = "**Opening**\nXin chào mọi người.\n\n**CTA**\n- Nhấn vào giỏ hàng."
        cleaned = clean_script_for_speech(script)
        self.assertNotIn("Opening", cleaned)
        self.assertNotIn("CTA", cleaned)
        self.assertNotIn("**", cleaned)
        self.assertNotIn("- Nhấn", cleaned)
        self.assertEqual(len(split_speech_sections(script)), 2)

    def test_cues_never_run_past_audio_or_reverse(self):
        script = "**Opening**\nXin chào. Đây là sản phẩm mới.\n\n**CTA**\nMời bạn mua ngay."
        timings = [
            {"start": 0.0, "end": 2.4, "text": "Xin chào. Đây là sản phẩm mới."},
            {"start": 2.4, "end": 4.0, "text": "Mời bạn mua ngay."},
        ]
        cues = build_subtitle_cues(script, 4.0, timings)
        self.assertTrue(cues)
        self.assertEqual(cues[-1][1], 4.0)
        self.assertTrue(all(0 <= start < end <= 4.0 for start, end, _ in cues))
        self.assertTrue(all("**" not in text and "CTA" not in text for _, _, text in cues))


class ScriptRetailerPolicyTests(unittest.TestCase):
    def test_external_retailer_names_are_replaced_before_tts(self):
        script = "Bạn có thể mua ngay tại Hasaki hoặc Shopee với giá ưu đãi."
        cleaned = sanitize_external_retailer_mentions(script)
        self.assertNotRegex(cleaned, r"(?i)hasaki|shopee")
        self.assertEqual(find_external_retailer_mentions(script), ["Hasaki", "Shopee"])
        self.assertIn("SkinSyntax", cleaned)


if __name__ == "__main__":
    unittest.main()
