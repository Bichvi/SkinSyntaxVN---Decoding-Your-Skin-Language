"""Autopilot bare country: short names (Nhật, Hàn...) count only when written with the country's own accents."""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from agents.catalog_constraints import CatalogVocabulary, extract_constraints

VOCAB = CatalogVocabulary.from_rows(brands=[{"ma_thuong_hieu": 1, "ten_thuong_hieu": "Eucerin"}])


def origin(brief, bare=True):
    out = extract_constraints(brief, VOCAB, bare_country=bare)["constraints"]
    return [c for c in out if c["field"] == "origin_country"]


class BareCountryAccentTests(unittest.TestCase):
    def test_nhat_with_accent_is_japan(self):
        found = origin("Làm video 5 kem chống nắng Nhật")
        self.assertEqual([c["value"] for c in found], [["nhat ban"]])

    def test_look_alike_words_are_not_countries(self):
        for brief in ("Làm video 3 kem chống nắng bán chạy nhất", "Làm video 3 sản phẩm sắp hết hạn",
                      "Làm video 3 sản phẩm thẩm mỹ", "Làm video phương pháp trị mụn",
                      "Làm video sản phẩm đạo đức", "Làm video kem hàn gắn da"):
            self.assertEqual(origin(brief), [], brief)

    def test_list_of_short_countries(self):
        found = origin("Làm video serum Pháp hoặc Đức")
        self.assertEqual(len(found), 1)
        self.assertEqual(sorted(found[0]["value"]), ["duc", "phap"])

    def test_classic_mode_is_unchanged(self):
        self.assertEqual(origin("Làm video 5 kem chống nắng Nhật", bare=False), [])


if __name__ == "__main__":
    unittest.main()
