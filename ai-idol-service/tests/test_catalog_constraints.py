"""Catalog attribute constraints: pure / FakeDb, no network."""
import datetime as dt
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from agents.catalog_constraints import CatalogVocabulary, apply_constraints, extract_constraints
from agents.command_query import build_query_plan, execute_query

TODAY = dt.date(2026, 10, 16)
BRIEF = ("Làm video sản phẩm cho 5 sữa rửa mặt cho da dầu bán chạy nhất trong 30 ngày qua, giọng tư vấn nhẹ nhàng, "
         "nhấn mạnh thành phần. giải thích rõ tại sao nên sử dụng, thành phần như nào, bữa nay đang qua mùa đông, "
         "nên tui muốn đẩy mạnh các sản phẩm dịu nhẹ như l'oreal, eucerin, ....")
VOCAB = CatalogVocabulary.from_rows(
    brands=[{"ma_thuong_hieu": 1, "ten_thuong_hieu": "L'Oreal"}, {"ma_thuong_hieu": 2, "ten_thuong_hieu": "L'Oreal Professionnel"},
            {"ma_thuong_hieu": 3, "ten_thuong_hieu": "Eucerin"}, {"ma_thuong_hieu": 4, "ten_thuong_hieu": "Cetaphil"}],
    origins=[{"ma_xuat_xu": 1, "ten_xuat_xu": "Hàn Quốc"}, {"ma_xuat_xu": 2, "ten_xuat_xu": "Ðức"}, {"ma_xuat_xu": 3, "ten_xuat_xu": "Pháp"}],
    factories=[{"ma_nsx": 1, "ten_nsx": "France"}, {"ma_nsx": 2, "ten_nsx": "Korea"}])


def by_field(result, name):
    return [c for c in result["constraints"] if c["field"] == name]


def row(pid, **fields):
    return {"ma_san_pham": pid, "ten_san_pham": f"Sữa rửa mặt {pid}", "loai_san_pham": "Sữa rửa mặt", **fields}


class CatalogConstraintTests(unittest.TestCase):
    def test_user_brief_has_no_question(self):
        plan = build_query_plan(BRIEF, today=TODAY, vocab=VOCAB)
        self.assertEqual(plan["questions"], [])
        self.assertEqual((plan["category"], plan["limit"], plan["rank_by"], plan["window_days"]), ("cleanser", 5, "units", 30))
        skin, brand = by_field(plan, "skin_type")[0], by_field(plan, "brand")[0]
        self.assertEqual((skin["value"], skin["strength"]), (["oily"], "HARD"))
        self.assertEqual((brand["value"], brand["strength"]), (["L'Oreal", "Eucerin"], "SOFT"))
        kinds = {k for n in plan["content_notes"] for k in n["kinds"]}
        self.assertTrue({"tone", "emphasis", "explain", "season"} <= kinds)
        self.assertIn("Loại da: Da dầu", plan["summary"])

    def test_without_vocab_keeps_blocking_question(self):
        self.assertTrue(build_query_plan("Lọc 5 sữa rửa mặt cho da dầu", today=TODAY)["questions"])

    def test_skin_matching(self):
        c = extract_constraints("sữa rửa mặt cho da dầu", VOCAB)["constraints"]
        self.assertTrue(apply_constraints({"loai_da": "Da dầu/Hỗn hợp dầu"}, c)[0])
        ok, ev, _ = apply_constraints({"loai_da": "Da thường/Mọi loại da"}, c)
        self.assertTrue(ok)
        self.assertIn("mọi loại da", ev["skin_reason"])
        self.assertEqual(apply_constraints({"loai_da": "Da khô"}, c), (False, {}, None))
        self.assertEqual(apply_constraints({"loai_da": "Unknown"}, c)[2], "skin_type")

    def test_brand_aliases_do_not_mix_professionnel(self):
        for text in ("chỉ lấy Loreal", "chỉ lấy L'Oréal", "chỉ lấy L’Oreal"):
            brand = by_field(extract_constraints(text, VOCAB), "brand")
            self.assertEqual(brand[0]["value"], ["L'Oreal"], text)
            self.assertEqual(brand[0]["strength"], "HARD")

    def test_hard_vs_soft_brand_sort(self):
        rows = [row(1, ten_thuong_hieu="Cetaphil"), row(2, ten_thuong_hieu="Eucerin")]
        soft = execute_query(build_query_plan("Lọc 2 sữa rửa mặt ưu tiên Eucerin", today=TODAY, vocab=VOCAB), rows, brief="")
        self.assertEqual([r["product_id"] for r in soft["selected"]], ["2", "1"])
        hard = execute_query(build_query_plan("Lọc 2 sữa rửa mặt chỉ lấy Eucerin", today=TODAY, vocab=VOCAB), rows, brief="")
        self.assertEqual([r["product_id"] for r in hard["selected"]], ["2"])

    def test_origin_vs_factory(self):
        c = extract_constraints("sản phẩm xuất xứ Hàn Quốc sản xuất tại Pháp", VOCAB)["constraints"]
        self.assertEqual([(x["field"], x["value"]) for x in c],
                         [("manufacturing_country", ["phap"]), ("origin_country", ["han quoc"])])
        germany = extract_constraints("hàng Đức", VOCAB)["constraints"]
        self.assertTrue(apply_constraints({"ten_xuat_xu": "Ðức"}, germany, VOCAB)[0])
        self.assertFalse(apply_constraints({"ten_xuat_xu": "Pháp"}, germany, VOCAB)[0])

    def test_ingredient_include_and_exclude(self):
        inc = extract_constraints("serum có niacinamide", VOCAB)["constraints"]
        self.assertTrue(apply_constraints({"thanh_phan_full": "Water, Niacinamide"}, inc)[0])
        exc = extract_constraints("sữa rửa mặt không hương liệu", VOCAB)
        self.assertEqual(exc["unparsed_negations"], 0)
        self.assertFalse(apply_constraints({"thanh_phan_full": "Water, Fragrance"}, exc["constraints"])[0])
        self.assertTrue(apply_constraints({"thanh_phan_full": "Water, Glycerin"}, exc["constraints"])[0])
        self.assertEqual(apply_constraints({"thanh_phan": "Water"}, exc["constraints"])[2], "full_ingredients")

    def test_price(self):
        c = extract_constraints("sữa rửa mặt dưới 300k", VOCAB)["constraints"]
        self.assertEqual(c[0]["value"], [300000.0])
        self.assertTrue(apply_constraints({"gia_ban": 250000}, c)[0])
        self.assertFalse(apply_constraints({"gia_ban": 350000}, c)[0])

    def test_conflict_becomes_question(self):
        plan = build_query_plan("Lọc 3 sữa rửa mặt chỉ lấy Eucerin, không lấy Eucerin", today=TODAY, vocab=VOCAB)
        self.assertTrue(plan["questions"])


if __name__ == "__main__":
    unittest.main()


class ParsedNegationDoesNotBlockTest(unittest.TestCase):
    def test_parsed_negations_do_not_ask(self):
        from agents.command_query import build_query_plan
        vocab = CatalogVocabulary.from_rows([{"ma_thuong_hieu": "1", "ten_thuong_hieu": "Eucerin"}], [], [], [])
        for brief in ("Tìm 5 sữa rửa mặt da dầu không hương liệu", "Tìm 5 sữa rửa mặt không lấy Eucerin"):
            self.assertEqual(build_query_plan(brief, vocab=vocab)["questions"], [], brief)
        self.assertTrue(build_query_plan("Tìm 5 sữa rửa mặt không xyz", vocab=vocab)["questions"])
