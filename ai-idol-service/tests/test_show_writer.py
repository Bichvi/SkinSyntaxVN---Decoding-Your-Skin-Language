import os
import sys
import unittest

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import Collection, FakeDb

from core.show_plan import bundle_total_phrase
from engines.show_writer import (
    SECTION_KEYS, SECTION_LABELS, load_answered_faqs, write_frame_clips, write_product_clips, write_routine_recap,
)
from engines.syna_persona import PERSONA_RULES_VI


class FakeLLM:
    def __init__(self, response):
        self.response = response
        self.calls = []

    def invoke_json_schema(self, system, user, *, schema, schema_name):
        self.calls.append((system, user, schema_name))
        return self.response


def pricing(price):
    return {"regular_price": price, "flash": None}


SNAPS = {
    "p1": {"id": "p1", "name": "Sữa rửa mặt A", "brand": "BrandA", "category": "sữa rửa mặt",
           "description": "Làm sạch dịu nhẹ", "ingredients": "Glycerin", "usage": "Rửa sáng tối",
           "skin_type": "mọi loại da", "pricing": pricing(150000)},
    "p3": {"id": "p3", "name": "Kem dưỡng C", "brand": "BrandC", "category": "kem dưỡng",
           "description": "Cấp ẩm", "ingredients": "Ceramide", "usage": "Thoa tối",
           "skin_type": "da khô", "pricing": pricing(278000)},
}
PLAN = {
    "products": [
        {"product_id": "p1", "number": 1, "step_key": "sua_rua_mat", "step_label": "Sữa rửa mặt"},
        {"product_id": "p3", "number": 3, "step_key": "kem_duong", "step_label": "Kem dưỡng"},
    ],
    "conflicts": [], "theme_vi": "Da sạch da khỏe", "routine_source": "keywords",
}


def angle(tag):
    return {key: f"{key} {tag}" for key in SECTION_KEYS}


FRAME = {
    "theme_vi": "Da sạch da khỏe",
    "opening": "chào cả nhà",
    "transitions": ["t1", "t2"],
    "interludes": {"cta": ["c1", "c2"], "about_shop": ["a1", "a2"], "fun": ["f1", "f2"]},
    "faqs": [{"product_id": "p1", "text": f"faq{i}"} for i in range(4)],
    "tips": ["tip1", "tip2"],
    "closing": "tạm biệt",
}


class ShowWriterTests(unittest.TestCase):
    def test_product_clips_two_angles_with_labels(self):
        llm = FakeLLM({"angle_a": angle("A"), "angle_b": angle("B")})
        drafts = write_product_clips(SNAPS["p1"], PLAN["products"][0], PLAN, llm=llm)
        self.assertEqual([d.angle for d in drafts], ["A", "B"])
        for draft in drafts:
            self.assertEqual(draft.kind, "product")
            self.assertEqual(draft.product_id, "p1")
            positions = [draft.script.index(f"[{label}]") for label in SECTION_LABELS]
            self.assertEqual(positions, sorted(positions))
        self.assertIn("món số 1", llm.calls[0][1])

    def test_persona_in_every_system_prompt(self):
        llm = FakeLLM({"angle_a": angle("A"), "angle_b": angle("B")})
        write_product_clips(SNAPS["p1"], PLAN["products"][0], PLAN, llm=llm)
        llm.response = FRAME
        write_frame_clips(PLAN, SNAPS, [], llm=llm)
        llm.response = {"text": "tóm tắt"}
        write_routine_recap(PLAN, SNAPS, llm=llm)
        self.assertEqual(len(llm.calls), 3)
        for system, _user, _name in llm.calls:
            self.assertIn(PERSONA_RULES_VI, system)

    def test_frame_clip_counts(self):
        theme, drafts = write_frame_clips(PLAN, SNAPS, [], llm=FakeLLM(FRAME))
        self.assertTrue(theme)
        kinds = [d.kind for d in drafts]
        self.assertEqual({k: kinds.count(k) for k in set(kinds)},
                         {"opening": 1, "transition": 2, "interlude": 6, "faq": 4, "tip": 2, "closing": 1})
        types = [d.interlude_type for d in drafts if d.kind == "interlude"]
        for kind in ("cta", "about_shop", "fun"):
            self.assertEqual(types.count(kind), 2)
        self.assertTrue(all(d.product_id for d in drafts if d.kind == "faq"))

    def test_recap_prompt_contains_total_and_numbers(self):
        llm = FakeLLM({"text": "tóm tắt"})
        draft = write_routine_recap(PLAN, SNAPS, llm=llm)
        self.assertEqual(draft.kind, "routine_recap")
        user = llm.calls[0][1]
        self.assertIn("món số 1", user)
        self.assertIn("món số 3", user)
        self.assertNotIn("món số 2", user)
        self.assertIn(bundle_total_phrase(428000), user)

    def test_faq_prefers_answered_questions(self):
        db = FakeDb(hoi_dap_san_pham=Collection([
            {"ma_san_pham": "p1", "cau_hoi": "Dùng sáng được không?", "ngay_hoi": 2,
             "tra_loi": {"noi_dung": "Được ạ"}},
            {"ma_san_pham": "p1", "cau_hoi": "Chưa ai trả lời", "ngay_hoi": 3, "tra_loi": None},
            {"ma_san_pham": 7, "cau_hoi": "Id số", "ngay_hoi": 1, "tra_loi": {"noi_dung": "Ok"}},
            {"ma_san_pham": "zz", "cau_hoi": "SP khác", "ngay_hoi": 5, "tra_loi": {"noi_dung": "x"}},
        ]))
        faqs = load_answered_faqs(db, ["p1", "7"])
        self.assertEqual([f["question"] for f in faqs], ["Dùng sáng được không?", "Id số"])
        self.assertEqual(faqs[0], {"product_id": "p1", "question": "Dùng sáng được không?", "answer": "Được ạ"})

    def test_malformed_llm_json_raises_value_error(self):
        with self.assertRaises(ValueError):
            write_product_clips(SNAPS["p1"], PLAN["products"][0], PLAN, llm=FakeLLM({"angle_a": angle("A")}))
        with self.assertRaises(ValueError):
            write_frame_clips(PLAN, SNAPS, [], llm=FakeLLM({}))
        with self.assertRaises(ValueError):
            write_routine_recap(PLAN, SNAPS, llm=FakeLLM({"text": " "}))


if __name__ == "__main__":
    unittest.main()
