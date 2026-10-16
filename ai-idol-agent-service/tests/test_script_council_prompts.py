import hashlib
import os
import sys
import unittest
from unittest.mock import patch


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import app as council_app
from script_council import editor_description, max_tokens_for, writer_description


PURPOSES = ("product_video", "live_and_product", "live_only")
STYLE_LINE = "- Phong cách dẫn chuyện: tư vấn nhẹ nhàng, nhấn mạnh thành phần."
# sha256 of writer_description(purpose) before narration styles existed.
WRITER_FINGERPRINTS = {
    "live_only": "61c7306263f144cd2c5d9e2c41962e93eb7eb81d2efe9978f8340fa28792dfb4",
}


class WriterPromptTests(unittest.TestCase):
    def test_product_video_writer_is_evergreen_and_headed(self):
        text = writer_description("product_video")
        for header in ("[Hook]", "[Specs]", "[Ingredients]", "[Wrap-up]"):
            self.assertIn(header, text)
        self.assertNotIn("Giá: {price}", text)

    def test_live_only_writer_keeps_eight_unlabelled_beats(self):
        text = writer_description("live_only")
        self.assertIn("tám nhịp", text)
        self.assertIn("Giá: {price}", text)

    def test_live_and_product_writer_marks_price_section(self):
        text = writer_description("live_and_product")
        self.assertIn("[Price]", text)
        self.assertIn("{price_lines}", text)
        self.assertNotIn("Giá: {price}", text)

    def test_writer_product_video_uses_price_lines(self):
        text = writer_description("product_video")
        self.assertIn("{price_lines}", text)
        self.assertNotIn("Giá thị trường: {market_price}", text)
        self.assertIn("đúng nguyên văn", text)
        self.assertIn("bấm vào sản phẩm để xem giá mới nhất và đặt hàng", text)
        self.assertNotIn("Tuyệt đối không nói giá", text)

    def test_editor_live_and_product_scope_before_cta(self):
        text = editor_description("live_and_product")
        self.assertIn("trước [CTA]", text)
        self.assertIn("đúng nguyên văn", text)
        self.assertIn("giá tham khảo thị trường", editor_description("product_video"))

    def test_writer_without_styles_is_unchanged(self):
        for purpose in WRITER_FINGERPRINTS:
            with self.subTest(purpose=purpose):
                for styles in (None, ""):
                    text = writer_description(purpose) if styles is None else writer_description(purpose, styles)
                    self.assertEqual(hashlib.sha256(text.encode()).hexdigest(), WRITER_FINGERPRINTS[purpose])
                    self.assertNotIn("narration_styles", text)

    def test_writer_renders_the_style_line_for_every_purpose(self):
        for purpose in PURPOSES:
            with self.subTest(purpose=purpose):
                source = council_app._normalize_payload(
                    {"source": {"name": "Kem A", "purpose": purpose, "narration_styles": STYLE_LINE}})["source"]
                rendered = writer_description(purpose, source["narration_styles"]).format(**source)
                self.assertIn("\n" + STYLE_LINE + "\n", rendered)
                self.assertNotIn("{", rendered)

    def test_style_placeholder_replaces_one_space_after_the_length_sentence(self):
        for purpose in PURPOSES:
            with self.subTest(purpose=purpose):
                plain = writer_description(purpose)
                styled = writer_description(purpose, STYLE_LINE)
                self.assertEqual(styled.replace("\n{narration_styles}\n", " ", 1), plain)
                self.assertEqual(styled.count("{narration_styles}"), 1)
                self.assertLess(styled.index("{length_mode}"), styled.index("{narration_styles}"))

    def test_editor_keeps_headers_for_headed_purposes(self):
        self.assertIn("[Ingredients]", editor_description("product_video"))
        self.assertIn("tám nhịp", editor_description("live_only"))


class TokenBudgetTests(unittest.TestCase):
    def test_product_video_gets_a_longer_budget(self):
        with patch.dict(os.environ, {}, clear=True):
            self.assertEqual(max_tokens_for("product_video"), 1400)
            self.assertEqual(max_tokens_for("live_only"), 650)

    def test_budgets_are_clamped(self):
        with patch.dict(os.environ, {"AI_IDOL_CREW_MAX_TOKENS_LONG": "99999", "AI_IDOL_CREW_MAX_TOKENS": "99999"}):
            self.assertEqual(max_tokens_for("product_video"), 1800)
            self.assertEqual(max_tokens_for("live_and_product"), 900)


class NormalizePayloadTests(unittest.TestCase):
    def test_unknown_purpose_is_live_only(self):
        self.assertEqual(council_app._normalize_payload({"source": {"purpose": "zzz"}})["source"]["purpose"], "live_only")

    def test_payload_clips_price_lines_to_600(self):
        for purpose in ("product_video", "live_and_product"):
            source = council_app._normalize_payload({"source": {
                "purpose": purpose, "price": "1", "market_price": "2", "price_lines": "p" * 900}})["source"]
            self.assertEqual(len(source["price_lines"]), 600)
            self.assertEqual(source["price"], "")
            self.assertEqual(source["market_price"], "")

    def test_live_only_has_no_price_lines(self):
        source = council_app._normalize_payload({"source": {"purpose": "live_only", "price_lines": "x"}})["source"]
        self.assertEqual(source["price_lines"], "")

    def test_product_video_keeps_more_source_and_drops_price(self):
        source = council_app._normalize_payload({"source": {
            "purpose": "product_video", "price": "289000", "market_price": "350000",
            "ingredients": "i" * 2500, "knowledge": "k" * 2500,
        }})["source"]
        self.assertEqual(source["price"], "")
        self.assertEqual(source["market_price"], "")
        self.assertEqual(len(source["ingredients"]), 2000)
        self.assertEqual(len(source["knowledge"]), 2200)

    def test_narration_styles_pass_through_and_are_clipped(self):
        for purpose in PURPOSES:
            with self.subTest(purpose=purpose):
                source = council_app._normalize_payload({"source": {
                    "purpose": purpose, "narration_styles": "  " + STYLE_LINE + "  ",
                }})["source"]
                self.assertEqual(source["narration_styles"], STYLE_LINE)
        long_source = council_app._normalize_payload({"source": {"narration_styles": "s" * 500}})["source"]
        self.assertEqual(len(long_source["narration_styles"]), 300)

    def test_missing_narration_styles_become_empty(self):
        self.assertEqual(council_app._normalize_payload({"source": {"purpose": "live_only"}})["source"]["narration_styles"], "")
        self.assertEqual(council_app._normalize_payload({})["source"]["narration_styles"], "")

    def test_livestream_limits_are_unchanged(self):
        source = council_app._normalize_payload({"source": {
            "purpose": "live_only", "price": "289000", "ingredients": "i" * 2500, "knowledge": "k" * 2500,
        }})["source"]
        self.assertEqual(source["price"], "289000")
        self.assertEqual(len(source["ingredients"]), 1000)
        self.assertEqual(len(source["knowledge"]), 1000)


if __name__ == "__main__":
    unittest.main()
