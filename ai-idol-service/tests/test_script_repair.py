import os
import sys
import unittest
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import engines.script_repair as script_repair
import engines.script_validator as sv
from engines.script_validator import ValidationIssue, validate_and_repair_script

SNAP = {"name": "Kem", "brand": "B", "ingredients": "Niacinamide", "pricing": None}
SCRIPT = "[Hook]\nChào cả nhà.\n[Specs]\nSản phẩm trị khỏi hoàn toàn nám.\n[Wrap-up]\nBấm vào sản phẩm nhé."


def issue(section, code="BLACKLIST_CLAIM"):
    return ValidationIssue(code, sv.HARD_FACT_ERROR, "error", section, "x", "sai", True)


class FakeLLM:
    calls = []

    def invoke(self, system, user):
        FakeLLM.calls.append(user)
        return "Kem giúp da mềm mịn."


class RepairScriptTests(unittest.TestCase):
    def setUp(self):
        FakeLLM.calls = []

    def test_only_failing_section_changes(self):
        with patch.object(script_repair, "LLMProvider", FakeLLM):
            fixed = script_repair.repair_script(SCRIPT, [issue("specs")], SNAP, "product_video")
        self.assertEqual(len(FakeLLM.calls), 1)
        self.assertEqual(fixed, "[Hook]\nChào cả nhà.\n[Specs]\nKem giúp da mềm mịn.\n[Wrap-up]\nBấm vào sản phẩm nhé.")
        self.assertNotIn("Chào cả nhà", FakeLLM.calls[0])

    def test_section_none_repairs_whole_script(self):
        with patch.object(script_repair, "LLMProvider", FakeLLM):
            fixed = script_repair.repair_script(SCRIPT, [issue(None)], SNAP, "product_video")
        self.assertEqual(fixed, "Kem giúp da mềm mịn.")
        self.assertIn("Chào cả nhà", FakeLLM.calls[0])

    def test_stops_after_three_repairs(self):
        class Stubborn:
            def invoke(self, system, user):
                return "Mình đã dùng rồi nhé."

        with patch.object(script_repair, "LLMProvider", Stubborn), \
                patch.object(sv, "_llm_audit_issues", return_value=[]):
            result = validate_and_repair_script("[Hook]\ntrị khỏi hoàn toàn", SNAP, "live_only")
        self.assertFalse(result.passed)
        self.assertEqual(result.repair_attempts, 3)
        self.assertEqual(len(result.history), 4)

    def test_repair_then_pass(self):
        with patch.object(script_repair, "LLMProvider", FakeLLM), \
                patch.object(sv, "_llm_audit_issues", return_value=[]):
            result = validate_and_repair_script("[Hook]\ntrị khỏi hoàn toàn", SNAP, "live_only")
        self.assertTrue(result.passed)
        self.assertEqual(result.repair_attempts, 1)
        self.assertEqual([h["passed"] for h in result.history], [False, True])

    def test_technical_issue_is_retried_not_repaired(self):
        tech = ValidationIssue("VALIDATOR_UNAVAILABLE", sv.TECHNICAL, "error", None, "", "down", False)
        with patch.object(script_repair, "LLMProvider", FakeLLM), \
                patch.object(sv, "_llm_audit_issues", return_value=[tech]) as audit:
            result = validate_and_repair_script("[Hook]\nXin chào.", SNAP, "live_only")
        self.assertFalse(result.passed)
        self.assertTrue(result.technical_failure)
        self.assertEqual(audit.call_count, 3)
        self.assertEqual(FakeLLM.calls, [])


class SameLineHeaderRepairTests(unittest.TestCase):
    def setUp(self):
        FakeLLM.calls = []

    def test_split_raw_splits_same_line_header(self):
        blocks = script_repair._split_raw("[Hook]\nHi.\n[Ingredients] • Niacinamide: dịu.\n[Wrap-up]\nBye.")
        self.assertEqual([b["label"] for b in blocks], ["", "hook", "ingredients", "wrap_up"])
        self.assertEqual(blocks[2]["header"], "[Ingredients]")
        self.assertEqual(blocks[2]["body"], ["• Niacinamide: dịu."])
        self.assertEqual(script_repair._join(blocks),
                         "[Hook]\nHi.\n[Ingredients]\n• Niacinamide: dịu.\n[Wrap-up]\nBye.")

    def test_section_repair_finds_same_line_ingredients(self):
        script = "[Hook]\nHi.\n[Ingredients] trị khỏi hoàn toàn nám.\n[Wrap-up]\nBye."
        with patch.object(script_repair, "LLMProvider", FakeLLM):
            fixed = script_repair.repair_script(script, [issue("ingredients")], SNAP, "product_video")
        self.assertEqual(len(FakeLLM.calls), 1)
        self.assertEqual(fixed, "[Hook]\nHi.\n[Ingredients]\nKem giúp da mềm mịn.\n[Wrap-up]\nBye.")

    def test_whole_script_repair_allows_headers(self):
        seen = []

        class Spy:
            def invoke(self, system, user):
                seen.append(system)
                return "[Hook]\nOk."

        with patch.object(script_repair, "LLMProvider", Spy):
            script_repair.repair_script(SCRIPT, [issue(None, "MISSING_SECTION")], SNAP, "product_video")
            script_repair.repair_script(SCRIPT, [issue("specs")], SNAP, "product_video")
        self.assertNotIn("không thêm dòng tiêu đề", seen[0])
        self.assertIn("tiêu đề trong ngoặc vuông", seen[0])
        self.assertIn("không thêm dòng tiêu đề trong ngoặc vuông", seen[1])


class ScrubForbiddenClaimsTests(unittest.TestCase):
    def test_scrubs_claim_phrases_and_tidies(self):
        out = sv.scrub_forbidden_claims("Tiêu diệt nhân mụn tận gốc , giảm ngay mụn. Sau 3 ngày da đẹp.")
        for phrase in ("tận gốc", "giảm ngay", "sau 3 ngày"):
            self.assertNotIn(phrase, out.lower())
        self.assertNotIn("  ", out)
        self.assertNotIn(" ,", out)

    def test_keeps_percent_by_default_and_newlines(self):
        self.assertEqual(sv.scrub_forbidden_claims("Niacinamide 100% tinh khiết\nDòng hai"),
                         "Niacinamide 100% tinh khiết\nDòng hai")
        self.assertNotIn("100%", sv.scrub_forbidden_claims("An toàn 100% nhé", strict=True))

    def test_product_facts_scrubs_data(self):
        snap = dict(SNAP, description="Tiêu diệt nhân mụn tận gốc.", usage="Dùng sau 1 đêm", ingredients="X")
        facts = script_repair._product_facts(snap)
        self.assertNotIn("tận gốc", facts)
        self.assertIn("Tiêu diệt nhân mụn", facts)

    def test_audit_prompt_scrubs_data_and_json_mode_final_line(self):
        snap = dict(SNAP, description="mụn tận gốc", usage="u")
        _, json_user = sv._audit_prompts("kịch bản", snap, "live_only", json_mode=True)
        _, legacy_user = sv._audit_prompts("kịch bản", snap, "live_only")
        self.assertNotIn("tận gốc", json_user)
        self.assertIn("mảng JSON", json_user)
        self.assertNotIn("PASS hoặc FAIL", json_user)
        self.assertIn("PASS hoặc FAIL", legacy_user)


class AuditPassResponseTests(unittest.TestCase):
    def _issues(self, reply):
        class LLM:
            def invoke(self, system, user):
                return reply
        with patch.object(sv, "LLMProvider", LLM):
            return sv._llm_audit_issues("[Hook]\nXin chào.", SNAP, "live_only")

    def test_pass_variants_are_no_issues(self):
        for reply in ("PASS", "pass.", "```\nPASS\n```", "  **PASS**\n"):
            self.assertEqual(self._issues(reply), [], reply)

    def test_fail_without_json_is_one_error(self):
        issues = self._issues("FAIL: nói quá")
        self.assertEqual([i.code for i in issues], ["LLM_AUDIT"])

    def test_empty_json_array_passes(self):
        self.assertEqual(self._issues("[]"), [])

    def test_pass_verdict_inside_json_is_no_issue(self):
        # Seen live: the auditor wraps its PASS verdict in an issue item.
        replies = (
            '[{"section": null, "original_text": "", "reason": "PASS", "auto_fixable": true}]',
            '[{"section": null, "original_text": "", "reason": "Không có vi phạm"}]',
            '{"result": "PASS"}',
            "Kết quả: PASS",
        )
        for reply in replies:
            self.assertEqual(self._issues(reply), [], reply)

    def test_real_issue_next_to_pass_item_is_kept(self):
        reply = ('[{"reason": "PASS"}, {"section": "hook", "original_text": "trắng sau 1 tuần", '
                 '"reason": "Hứa hẹn thời gian", "auto_fixable": true}]')
        self.assertEqual([i.reason for i in self._issues(reply)], ["Hứa hẹn thời gian"])


class RelaxedAuditTests(unittest.TestCase):
    """AI_IDOL_RELAXED_AUDIT: demo mode keeps only shop-fact checks (price, retailer)."""

    def test_relaxed_mode_skips_claim_rules_and_llm(self):
        script = "[Hook]\nDa mình đã dùng thấy mịn.\n[Specs]\nTrị dứt điểm mụn."
        with patch.object(sv, "RELAXED_AUDIT", True), \
                patch.object(sv, "_llm_audit_issues", side_effect=AssertionError("LLM called")):
            issues = sv.validate_script(script, SNAP, "live_only")
        self.assertFalse([i for i in issues if i.severity == "error"])

    def test_relaxed_mode_keeps_retailer_check(self):
        with patch.object(sv, "RELAXED_AUDIT", True):
            issues = sv.validate_script("[Hook]\nMua ở Shopee nhé.", SNAP, "live_only")
        self.assertIn("EXTERNAL_RETAILER", [i.code for i in issues if i.severity == "error"])

    def test_strict_mode_unchanged(self):
        with patch.object(sv, "RELAXED_AUDIT", False), patch.object(sv, "_llm_audit_issues", return_value=[]):
            issues = sv.validate_script("[Specs]\nTrị dứt điểm mụn.", SNAP, "live_only")
        self.assertIn("BLACKLIST_CLAIM", [i.code for i in issues if i.severity == "error"])


class ResidualClaimScrubTests(unittest.TestCase):
    def test_repair_loop_scrubs_claim_the_llm_keeps_copying(self):
        class Stubborn:
            def invoke(self, system, user):
                return "Diệt mụn tận gốc nhé."

        snap = dict(SNAP, description="mụn tận gốc")
        with patch.object(script_repair, "LLMProvider", Stubborn), \
                patch.object(sv, "_llm_audit_issues", return_value=[]):
            result = validate_and_repair_script("tận gốc", snap, "live_only")
        self.assertTrue(result.passed)
        self.assertNotIn("tận gốc", result.script)
        self.assertEqual(result.repair_attempts, 1)


if __name__ == "__main__":
    unittest.main()
