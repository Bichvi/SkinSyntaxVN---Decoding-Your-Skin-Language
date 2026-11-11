import os
import sys
import unittest

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import Collection, FakeDb

from core.show_plan import bundle_total_phrase
from engines.show_writer import (
    PROMPT_BUDGET, SECTION_KEYS, SECTION_LABELS, build_frame_prompts, build_product_prompts, build_recap_prompts,
    load_answered_faqs, prompt_len, write_frame_clips, write_product_clips, write_routine_recap,
)
from engines.syna_persona import PERSONA_RULES_VI, SHOP_FACTS_VI


class FakeLLM:
    """Returns canned JSON by schema_name; records every call."""

    def __init__(self, responses):
        self.responses = responses
        self.calls = []

    def invoke_json_schema(self, system, user, *, schema, schema_name, max_completion_tokens=None):
        self.calls.append((system, user, schema_name, schema, max_completion_tokens))
        response = self.responses
        if isinstance(response, dict) and schema_name in response:
            response = response[schema_name]
        return response


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


def words(count, tag="w"):
    return " ".join([tag] * count)


def angle(tag, per_section=60):
    return {key: f"{key} {tag} " + words(per_section - 2) for key in SECTION_KEYS}


FRAME = {
    "frame_opening": {"opening": words(300), "transitions": [words(100), words(100)]},
    "frame_interludes": {"interludes": {"cta": [words(50)] * 2, "about_shop": [words(50)] * 2, "fun": [words(50)] * 2}},
    "frame_faqs": {"faqs": [{"product_id": "p1", "text": words(100)} for _ in range(4)]},
    "frame_tips_closing": {"tips": [words(300), words(300)], "closing": words(100)},
}
PRODUCT = {"product_clip_a": angle("A"), "product_clip_b": angle("B")}


def big_world(n=6):
    long = "x" * 5000
    snaps, items = {}, []
    for i in range(1, n + 1):
        pid = f"p{i}"
        snaps[pid] = {"id": pid, "name": "Tên sản phẩm rất dài " * 10, "brand": "Thương hiệu " * 10,
                      "category": "serum", "description": long, "ingredients": long, "usage": long,
                      "origin": "Hàn Quốc", "skin_type": "mọi loại da", "pricing": pricing(100000 * i)}
        items.append({"product_id": pid, "number": i, "step_key": "serum", "step_label": "Serum"})
    plan = {"products": items,
            "conflicts": [{"a": "p1", "b": "p2", "severity": "warn", "message_vi": "m" * 200}],
            "theme_vi": "Chủ đề " * 8, "routine_source": "keywords"}
    return plan, snaps


class ShowWriterTests(unittest.TestCase):
    def test_product_clips_two_angles_with_labels(self):
        llm = FakeLLM(PRODUCT)
        drafts = write_product_clips(SNAPS["p1"], PLAN["products"][0], PLAN, llm=llm)
        self.assertEqual([d.angle for d in drafts], ["A", "B"])
        for draft in drafts:
            self.assertEqual(draft.kind, "product")
            self.assertEqual(draft.product_id, "p1")
            positions = [draft.script.index(f"[{label}]") for label in SECTION_LABELS]
            self.assertEqual(positions, sorted(positions))
        self.assertEqual(len(llm.calls), 2)
        self.assertIn("món số 1", llm.calls[0][1])
        self.assertTrue(all(call[4] and call[4] > 700 for call in llm.calls))

    def test_persona_in_every_system_prompt(self):
        llm = FakeLLM({**PRODUCT, **FRAME, "routine_recap": {"text": words(300)}})
        write_product_clips(SNAPS["p1"], PLAN["products"][0], PLAN, llm=llm)
        write_frame_clips(PLAN, SNAPS, [], llm=llm)
        write_routine_recap(PLAN, SNAPS, llm=llm)
        self.assertEqual(len(llm.calls), 2 + 4 + 1)
        for system, *_rest in llm.calls:
            self.assertIn(PERSONA_RULES_VI, system)

    def test_frame_clip_counts(self):
        llm = FakeLLM(FRAME)
        theme, drafts = write_frame_clips(PLAN, SNAPS, [], llm=llm)
        self.assertEqual(theme, "Da sạch da khỏe")
        self.assertEqual(len(llm.calls), 4)
        kinds = [d.kind for d in drafts]
        self.assertEqual({k: kinds.count(k) for k in set(kinds)},
                         {"opening": 1, "transition": 2, "interlude": 6, "faq": 4, "tip": 2, "closing": 1})
        types = [d.interlude_type for d in drafts if d.kind == "interlude"]
        for kind in ("cta", "about_shop", "fun"):
            self.assertEqual(types.count(kind), 2)
        self.assertTrue(all(d.product_id for d in drafts if d.kind == "faq"))

    def test_recap_prompt_contains_total_and_numbers(self):
        llm = FakeLLM({"text": words(300)})
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
            write_product_clips(SNAPS["p1"], PLAN["products"][0], PLAN, llm=FakeLLM({"lead_in": "x"}))
        with self.assertRaises(ValueError):
            write_frame_clips(PLAN, SNAPS, [], llm=FakeLLM({}))
        with self.assertRaises(ValueError):
            write_routine_recap(PLAN, SNAPS, llm=FakeLLM({"text": " "}))

    def test_prompts_fit_budget_and_keep_rules_with_six_max_products(self):
        plan, snaps = big_world(6)
        faqs = [{"product_id": "p1", "question": "q" * 900, "answer": "a" * 900}] * 4
        frame = build_frame_prompts(plan, snaps, faqs)
        recap = build_recap_prompts(plan, snaps)
        product_a = build_product_prompts(snaps["p1"], plan["products"][0], plan, "A")
        product_b = build_product_prompts(snaps["p1"], plan["products"][0], plan, "B", angle("A"))
        for system, user in [*frame, recap, product_a, product_b]:
            self.assertLessEqual(prompt_len(system, user), PROMPT_BUDGET)
        self.assertIn("Rồi, món tiếp theo cũng đỉnh không kém nè!", frame[1][1])
        for fact in SHOP_FACTS_VI:
            self.assertIn(fact, frame[1][1])
        self.assertIn("món số 6", recap[1])
        self.assertIn("trợ giá", recap[1])
        self.assertIn("món số 1", product_a[1])
        self.assertIn("Góc A đã dùng", product_b[1])

    def test_frame_prompts_forbid_numbers_and_have_honesty(self):
        for _system, user in build_frame_prompts(PLAN, SNAPS, []):
            self.assertIn('KHÔNG nói "món số N"', user)
            self.assertIn("500 bà đã chốt", user)
            self.assertIn("ngay bây giờ", user)

    def test_unknown_faq_product_id_raises_and_schema_has_enum(self):
        bad = dict(FRAME)
        bad["frame_faqs"] = {"faqs": [{"product_id": "zz", "text": "x"}] * 4}
        llm = FakeLLM(bad)
        with self.assertRaises(ValueError):
            write_frame_clips(PLAN, SNAPS, [], llm=llm)
        faq_call = [c for c in llm.calls if c[2] == "frame_faqs"][0]
        enum = faq_call[3]["properties"]["faqs"]["items"]["properties"]["product_id"]["enum"]
        self.assertEqual(enum, ["p1", "p3"])

    def test_theme_from_plan_wins_and_llm_theme_only_when_missing(self):
        responses = dict(FRAME)
        responses["frame_opening"] = {"theme_vi": "Chủ đề LLM", "opening": words(300), "transitions": [words(100), words(100)]}
        theme, _ = write_frame_clips({**PLAN, "theme_vi": ""}, SNAPS, [], llm=FakeLLM(responses))
        self.assertEqual(theme, "Chủ đề LLM")
        llm = FakeLLM(FRAME)
        theme, _ = write_frame_clips(PLAN, SNAPS, [], llm=llm)
        self.assertEqual(theme, "Da sạch da khỏe")
        self.assertNotIn("theme_vi", llm.calls[0][3]["properties"])

    def test_product_without_id_raises(self):
        with self.assertRaises(ValueError):
            write_product_clips({"name": "x"}, {"number": 1}, PLAN, llm=FakeLLM(PRODUCT))


class SequenceLLM:
    """Returns the queued answers one by one; records every call."""

    def __init__(self, answers):
        self.answers = list(answers)
        self.calls = []

    def invoke_json_schema(self, system, user, *, schema, schema_name, max_completion_tokens=None):
        self.calls.append((system, user, schema_name))
        return self.answers[min(len(self.calls), len(self.answers)) - 1]


class LengthRetryTests(unittest.TestCase):
    def write(self, llm):
        return write_product_clips(SNAPS["p1"], PLAN["products"][0], PLAN, llm=llm)

    def test_short_then_adequate_makes_two_calls_and_uses_adequate(self):
        llm = SequenceLLM([angle("short", 20), angle("good"), angle("B")])
        drafts = self.write(llm)
        self.assertEqual(len(llm.calls), 3)  # angle A twice, angle B once
        self.assertIn("lead_in good", drafts[0].script)
        self.assertNotIn("short", drafts[0].script)

    def test_feedback_names_draft_count_and_range_in_second_prompt(self):
        llm = SequenceLLM([angle("short", 20), angle("good"), angle("B")])
        self.write(llm)
        self.assertNotIn("Bản trước quá ngắn", llm.calls[0][1])
        self.assertIn("Bản trước quá ngắn: góc A có 160 chữ, cần 400-560 chữ. Viết lại đầy đủ, giữ nguyên mọi quy tắc.",
                      llm.calls[1][1])
        self.assertLessEqual(prompt_len(llm.calls[1][0], llm.calls[1][1]), PROMPT_BUDGET)

    def test_adequate_first_makes_one_call_per_angle(self):
        llm = SequenceLLM([angle("A"), angle("B")])
        self.write(llm)
        self.assertEqual(len(llm.calls), 2)

    def test_short_twice_keeps_longer_and_warns(self):
        llm = SequenceLLM([angle("first", 20), angle("second", 30), angle("B")])
        with self.assertLogs("engines.show_writer", level="WARNING") as logs:
            drafts = self.write(llm)
        self.assertEqual(len(llm.calls), 3)
        self.assertIn("lead_in second", drafts[0].script)
        self.assertTrue(any("still short" in line for line in logs.output))

    def test_short_twice_keeps_first_when_retry_is_shorter(self):
        llm = SequenceLLM([angle("first", 30), angle("second", 20), angle("B")])
        with self.assertLogs("engines.show_writer", level="WARNING"):
            drafts = self.write(llm)
        self.assertIn("lead_in first", drafts[0].script)

    def test_label_header_lines_are_not_counted(self):
        from engines.show_writer import _spoken_words
        self.assertEqual(_spoken_words("[Lead-in]\nmột hai ba\n\n[CTA]\nbốn năm"), 5)

    def test_frame_clip_short_opening_retries_once(self):
        short = dict(FRAME, frame_opening={"opening": words(100), "transitions": [words(100), words(100)]})
        responses = [short["frame_opening"], FRAME["frame_opening"], FRAME["frame_interludes"], FRAME["frame_faqs"],
                     FRAME["frame_tips_closing"]]
        llm = SequenceLLM(responses)
        write_frame_clips(PLAN, SNAPS, [], llm=llm)
        self.assertEqual(len(llm.calls), 5)
        self.assertIn("opening có 100 chữ, cần 240-480 chữ", llm.calls[1][1])

    def test_frame_short_interlude_and_recap_retry(self):
        short_interludes = {"interludes": {"cta": [words(5)] * 2, "about_shop": [words(50)] * 2, "fun": [words(50)] * 2}}
        llm = SequenceLLM([FRAME["frame_opening"], short_interludes, FRAME["frame_interludes"], FRAME["frame_faqs"],
                           FRAME["frame_tips_closing"]])
        write_frame_clips(PLAN, SNAPS, [], llm=llm)
        self.assertEqual(len(llm.calls), 5)
        self.assertIn("40-80 chữ", llm.calls[2][1])
        recap = SequenceLLM([{"text": words(50)}, {"text": words(300)}])
        draft = write_routine_recap(PLAN, SNAPS, llm=recap)
        self.assertEqual(len(recap.calls), 2)
        self.assertEqual(len(draft.script.split()), 300)

    def test_feedback_prompts_still_fit_budget_with_six_products(self):
        plan, snaps = big_world(6)
        fb = "Bản trước quá ngắn: góc A có 100 chữ, cần 400-560 chữ. Viết lại đầy đủ, giữ nguyên mọi quy tắc."
        faqs = [{"product_id": "p1", "question": "q" * 900, "answer": "a" * 900}] * 4
        theme = plan["theme_vi"]
        from engines.show_writer import (build_faq_prompts, build_interlude_prompts, build_opening_prompts,
                                         build_tips_closing_prompts)
        prompts = [build_product_prompts(snaps["p1"], plan["products"][0], plan, "B", angle("A"), fb),
                   build_recap_prompts(plan, snaps, fb), build_faq_prompts(plan, snaps, faqs, theme, fb),
                   build_opening_prompts(plan, theme, fb), build_interlude_prompts(plan, theme, fb),
                   build_tips_closing_prompts(plan, theme, fb)]
        for system, user in prompts:
            self.assertLessEqual(prompt_len(system, user), PROMPT_BUDGET)
            self.assertIn(fb, user)


if __name__ == "__main__":
    unittest.main()
