import os
import sys
import unittest


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from core.script_text import SALE_LABELS, find_price_mentions, split_labeled_sections, split_speech_sections


class LabeledSectionTests(unittest.TestCase):
    def test_bracket_headers_label_each_section(self):
        self.assertEqual(
            split_labeled_sections("[Hook]\nA b.\n\n[Price]\nGiá tốt."),
            [("hook", "A b."), ("price", "Giá tốt.")],
        )

    def test_markdown_header_is_canonicalised(self):
        self.assertEqual(split_labeled_sections("**Key Benefits**\nX")[0], ("key_benefits", "X"))

    def test_new_product_video_headers(self):
        labels = [label for label, _ in split_labeled_sections(
            "[Problem]\nA\n[Specs]\nB\n[Ingredients]\nC\n[Wrap-up]\nD"
        )]
        self.assertEqual(labels, ["problem", "specs", "ingredients", "wrap_up"])

    def test_inline_header_labels_its_own_line(self):
        self.assertEqual(split_labeled_sections("Price: Chỉ hôm nay.")[0], ("price", "Chỉ hôm nay."))

    def test_unlabelled_prose_matches_speech_sections(self):
        script = "Đoạn một nói về da.\n\nĐoạn hai nói về cách dùng."
        labeled = split_labeled_sections(script)
        self.assertEqual([label for label, _ in labeled], ["", ""])
        self.assertEqual([text for _, text in labeled], split_speech_sections(script))

    def test_long_section_chunks_keep_their_label(self):
        body = " ".join(["từ"] * 230)
        labeled = split_labeled_sections(f"[Ingredients]\n{body}")
        self.assertEqual([label for label, _ in labeled], ["ingredients"] * 3)

    def test_speech_sections_unchanged_for_headed_scripts(self):
        script = "Opening\nChào cả nhà.\n\nPrice\nGiá chỉ một trăm nghìn đồng."
        self.assertEqual(split_speech_sections(script), ["Chào cả nhà.", "Giá chỉ một trăm nghìn đồng."])

    def test_sale_labels(self):
        self.assertEqual(SALE_LABELS, frozenset({"price", "cta", "closing"}))


class PriceMentionTests(unittest.TestCase):
    def test_numeric_prices_are_found(self):
        self.assertTrue(find_price_mentions("chỉ 199k thôi"))
        self.assertTrue(find_price_mentions("199.000 đồng"))
        self.assertTrue(find_price_mentions("giá 250.000đ"))

    def test_spelled_out_prices_are_found(self):
        self.assertTrue(find_price_mentions("chỉ một trăm chín mươi chín nghìn đồng"))

    def test_promotion_words_are_found_with_or_without_accents(self):
        self.assertTrue(find_price_mentions("chot don ngay"))
        self.assertTrue(find_price_mentions("Flash sale hôm nay"))
        self.assertTrue(find_price_mentions("đang có khuyến mãi"))

    def test_review_i5_price_forms_are_found(self):
        self.assertTrue(find_price_mentions("chỉ 350.000 ₫ thôi"))
        self.assertTrue(find_price_mentions("chỉ ba trăm năm mươi nghìn"))
        self.assertTrue(find_price_mentions("giá VND 350.000"))
        self.assertTrue(find_price_mentions("đang giảm 20% cho bạn"))

    def test_skincare_language_is_not_a_price(self):
        self.assertEqual(find_price_mentions("Niacinamide 10 phần trăm"), [])
        self.assertEqual(find_price_mentions("Kẽm hoạt động đồng thời với dung tích 30 mililít"), [])


if __name__ == "__main__":
    unittest.main()
