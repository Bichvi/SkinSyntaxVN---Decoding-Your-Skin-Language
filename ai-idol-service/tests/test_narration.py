import os
import sys
import unittest
from unittest.mock import patch


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import core.campaigns as campaigns
from core.narration import (
    NARRATION_STYLES, PRICE_STYLES, normalize_styles, style_instruction, styles_from_text,
)
from engines.content_agent import _script_council_payload, build_script_prompts
from tests.fakes import FakeDb


SNAPSHOT = {"name": "Kem A", "brand": "B", "price": 100000, "ingredients": "", "description": "", "usage": ""}


class NarrationTests(unittest.TestCase):
    def test_style_table_matches_the_spec_order_and_price_flags(self):
        self.assertEqual([key for key, _, _ in NARRATION_STYLES],
                         ["cheerful", "gentle", "expert", "closing", "concise",
                          "detailed", "friendly", "ingredients", "pricing"])
        self.assertEqual({key for key, _, speaks_price in NARRATION_STYLES if speaks_price}, set(PRICE_STYLES))
        self.assertEqual(PRICE_STYLES, frozenset({"closing", "pricing"}))

    def test_normalize_drops_price_styles_for_product_video(self):
        self.assertEqual(normalize_styles(["gentle", "pricing", "closing"], "product_video"),
                         (["gentle"], ["pricing", "closing"]))

    def test_normalize_keeps_price_styles_for_live(self):
        self.assertEqual(normalize_styles(["pricing"], "live_only")[0], ["pricing"])
        self.assertEqual(normalize_styles(["closing"], "live_and_product")[0], ["closing"])

    def test_normalize_unknown_and_duplicates(self):
        self.assertEqual(normalize_styles(["x", "gentle", "gentle"], "live_only"), (["gentle"], []))

    def test_normalize_tolerates_non_list_and_non_string_values(self):
        self.assertEqual(normalize_styles(None, "live_only"), ([], []))
        self.assertEqual(normalize_styles("gentle", "live_only"), ([], []))
        self.assertEqual(normalize_styles([None, 3, " Gentle "], "live_only"), (["gentle"], []))

    def test_concise_and_detailed_are_exclusive(self):
        self.assertEqual(normalize_styles(["detailed", "concise"], "live_only"), (["detailed"], ["concise"]))
        self.assertEqual(normalize_styles(["concise", "detailed", "detailed"], "live_only"),
                         (["concise"], ["detailed"]))

    def test_styles_from_text(self):
        self.assertEqual(styles_from_text("Giọng vui tươi, chốt sale, nhấn mạnh thành phần"),
                         ["cheerful", "closing", "ingredients"])
        self.assertEqual(styles_from_text("Giọng tươi vui"), ["cheerful"])

    def test_styles_from_text_follows_style_order_not_text_order(self):
        self.assertEqual(styles_from_text("nhấn mạnh giá, giải thích chi tiết, giọng chuyên gia"),
                         ["expert", "detailed", "pricing"])

    def test_styles_from_text_without_a_style_is_empty(self):
        self.assertEqual(styles_from_text("Làm video sản phẩm mã 995"), [])
        self.assertEqual(styles_from_text(""), [])

    def test_style_instruction(self):
        self.assertEqual(style_instruction(["gentle", "ingredients"]),
                         "- Phong cách dẫn chuyện: tư vấn nhẹ nhàng, nhấn mạnh thành phần.")
        self.assertEqual(style_instruction([]), "")

    def test_script_prompt_contains_styles_for_every_purpose(self):
        for purpose in ("product_video", "live_and_product", "live_only"):
            with self.subTest(purpose=purpose):
                _, user = build_script_prompts(SNAPSHOT, "", "Product Intro", {"narration_styles": ["gentle"]}, purpose)
                self.assertIn("Phong cách dẫn chuyện: tư vấn nhẹ nhàng", user)
                _, plain = build_script_prompts(SNAPSHOT, "", "Product Intro", {}, purpose)
                self.assertNotIn("Phong cách dẫn chuyện", plain)

    def test_style_line_sits_directly_after_the_length_line(self):
        for purpose in ("product_video", "live_and_product", "live_only"):
            with self.subTest(purpose=purpose):
                _, user = build_script_prompts(SNAPSHOT, "", "Product Intro", {"narration_styles": ["gentle"]}, purpose)
                lines = user.splitlines()
                length_at = next(index for index, line in enumerate(lines) if line.startswith("- Độ dài mong muốn:"))
                self.assertEqual(lines[length_at + 1], "- Phong cách dẫn chuyện: tư vấn nhẹ nhàng.")

    def test_council_payload_carries_styles(self):
        payload = _script_council_payload(SNAPSHOT, "", "Product Intro", {"narration_styles": ["expert"]}, "product_video")
        self.assertEqual(payload["source"]["narration_styles"], "- Phong cách dẫn chuyện: chuyên gia khoa học.")

    def test_council_payload_styles_are_empty_when_not_chosen(self):
        payload = _script_council_payload(SNAPSHOT, "", "Product Intro", {}, "product_video")
        self.assertEqual(payload["source"]["narration_styles"], "")

    def test_create_campaign_normalizes_styles(self):
        db = FakeDb()
        with patch.object(campaigns, "get_db", return_value=db):
            campaign_id, created = campaigns.create_campaign({
                "product_ids": ["1"],
                "purpose": "product_video",
                "configuration": {"narration_styles": ["pricing", "gentle"]},
            })
        self.assertTrue(created)
        stored = db.ai_idol_campaigns.find_one({})
        self.assertEqual(str(stored["_id"]), campaign_id)
        self.assertEqual(stored["configuration"]["narration_styles"], ["gentle"])

    def test_create_campaign_always_stores_a_list(self):
        db = FakeDb()
        with patch.object(campaigns, "get_db", return_value=db):
            campaigns.create_campaign({"product_ids": ["1"], "purpose": "product_video"})
        self.assertEqual(db.ai_idol_campaigns.find_one({})["configuration"]["narration_styles"], [])

    def test_create_campaign_keeps_price_styles_for_a_livestream(self):
        db = FakeDb()
        with patch.object(campaigns, "get_db", return_value=db):
            campaigns.create_campaign({
                "product_ids": ["1"],
                "purpose": "live_only",
                "scheduled_at": "2999-01-01T20:00:00Z",
                "platforms": ["internal"],
                "configuration": {"narration_styles": ["closing", "detailed", "concise"]},
            })
        self.assertEqual(db.ai_idol_campaigns.find_one({})["configuration"]["narration_styles"],
                         ["closing", "detailed"])


if __name__ == "__main__":
    unittest.main()
