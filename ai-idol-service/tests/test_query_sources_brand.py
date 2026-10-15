"""load_catalog resolves the brand name from san_pham.ma_thuong_hieu via the thuong_hieu collection."""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from agents.query_sources import load_catalog
from tests.fakes import Collection, FakeDb

BRANDS = [{"ma_thuong_hieu": 7, "ten_thuong_hieu": "La Roche-Posay"}, {"ma_thuong_hieu": 8, "ten_thuong_hieu": "Cocoon"}]


def db_with(rows):
    return FakeDb(san_pham=Collection(rows), thuong_hieu=Collection(BRANDS))


class CatalogBrandTests(unittest.TestCase):
    def test_brand_name_resolved_from_ma_thuong_hieu(self):
        rows = load_catalog(db_with([{"ma_san_pham": 1, "ma_thuong_hieu": 7, "ten_san_pham": "Kem Chống Nắng"}]))
        self.assertEqual(rows[0]["ten_thuong_hieu"], "La Roche-Posay")

    def test_numeric_forms_of_brand_id_are_equivalent(self):
        rows = load_catalog(db_with([{"ma_san_pham": 1, "ma_thuong_hieu": "7"}, {"ma_san_pham": 2, "ma_thuong_hieu": 8.0}]))
        self.assertEqual([r["ten_thuong_hieu"] for r in rows], ["La Roche-Posay", "Cocoon"])

    def test_existing_brand_name_kept(self):
        rows = load_catalog(db_with([
            {"ma_san_pham": 1, "ma_thuong_hieu": 7, "ten_thuong_hieu": "Tên Có Sẵn"},
            {"ma_san_pham": 2, "ma_thuong_hieu": 7, "thuong_hieu": "CeraVe"},
        ]))
        self.assertEqual(rows[0]["ten_thuong_hieu"], "Tên Có Sẵn")
        self.assertEqual(rows[1]["thuong_hieu"], "CeraVe")
        self.assertNotIn("ten_thuong_hieu", rows[1])

    def test_unknown_brand_id_leaves_brand_empty(self):
        rows = load_catalog(db_with([{"ma_san_pham": 1, "ma_thuong_hieu": 999}, {"ma_san_pham": 2}]))
        self.assertNotIn("ten_thuong_hieu", rows[0])
        self.assertNotIn("ten_thuong_hieu", rows[1])


if __name__ == "__main__":
    unittest.main()
