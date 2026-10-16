import os
import sys
import unittest


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from core.script_text import SALE_LABELS, find_price_violations, split_labeled_sections, split_speech_sections


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


class PriceViolationTests(unittest.TestCase):
    def test_numeric_prices_are_found(self):
        self.assertTrue(find_price_violations("chỉ 199k thôi", None))
        self.assertTrue(find_price_violations("199.000 đồng", None))
        self.assertTrue(find_price_violations("giá 250.000đ", None))

    def test_spelled_out_prices_are_found(self):
        self.assertTrue(find_price_violations("chỉ một trăm chín mươi chín nghìn đồng", None))

    def test_promotion_words_are_found_with_or_without_accents(self):
        self.assertTrue(find_price_violations("chot don ngay", None))
        self.assertTrue(find_price_violations("Flash sale hôm nay", None))
        self.assertTrue(find_price_violations("đang có khuyến mãi", None))

    def test_review_i5_price_forms_are_found(self):
        self.assertTrue(find_price_violations("chỉ 350.000 ₫ thôi", None))
        self.assertTrue(find_price_violations("chỉ ba trăm năm mươi nghìn", None))
        self.assertTrue(find_price_violations("giá VND 350.000", None))
        self.assertTrue(find_price_violations("đang giảm 20% cho bạn", None))

    def test_skincare_language_is_not_a_price(self):
        # Spec 4.3 step 2: any "<number> phần trăm" outside the backend phrases is a price claim.
        self.assertTrue(find_price_violations("Niacinamide 10 phần trăm", None))
        self.assertEqual(find_price_violations("Kẽm hoạt động đồng thời với dung tích 30 mililít", None), [])


if __name__ == "__main__":
    unittest.main()
