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
                return "trị khỏi hoàn toàn"

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


if __name__ == "__main__":
    unittest.main()
