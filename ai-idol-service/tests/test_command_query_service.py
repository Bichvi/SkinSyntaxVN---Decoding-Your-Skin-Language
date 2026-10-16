"""Agent orchestration contracts; no network or real campaign writes."""
import os
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from agents.service import process_agent_run


class QueryServiceTests(unittest.TestCase):
    PRODUCTS = [{"ma_san_pham": "10", "ten_san_pham": "Kem chống nắng A", "so_luong_ban": 100}]

    def run_command(self, text, catalog=None):
        run = {"_id": "run-test", "brief": text, "status": "RECEIVED", "overrides": {"autonomy_policy": "assisted"}}
        def update(_, values):
            run.update(values)
        enqueue = Mock()
        with patch("agents.service.get_agent_run", side_effect=lambda _: run), \
             patch("agents.service.update_agent_run", side_effect=update), \
             patch("agents.service.create_campaign", return_value=("campaign-test", True)) as create, \
             patch("agents.service.LLMProvider") as provider, \
             patch("agents.service.parse_agent_brief") as parser:
            result = process_agent_run("run-test", catalog=self.PRODUCTS if catalog is None else catalog, enqueue_campaign=enqueue)
        provider.assert_not_called()
        parser.assert_not_called()
        return result, create, enqueue

    def test_read_only_never_generates(self):
        result, create, enqueue = self.run_command("Lọc 1 kem chống nắng bán chạy toàn kỳ")
        self.assertEqual(result["status"], "RESULTS_READY")
        self.assertEqual(result["plan"]["query_result"]["selected"][0]["product_id"], "10")
        create.assert_not_called()
        enqueue.assert_not_called()

    def test_missing_expiry_returns_actionable_data_gap(self):
        result, create, _ = self.run_command("Lọc 10 kem chống nắng hạn dùng từ 17 đến 19 tháng")
        self.assertEqual(result["status"], "DATA_UNAVAILABLE")
        self.assertIn("expiry_or_batch_quantity", result["plan"]["query_result"]["missing_data"])
        create.assert_not_called()

    def test_ambiguous_expiry_returns_question(self):
        result, create, _ = self.run_command("Lọc 10 kem chống nắng còn khoảng 18 tháng hạn sử dụng", [])
        self.assertEqual(result["status"], "NEEDS_INPUT")
        create.assert_not_called()

    def test_compound_shortfall_never_creates_campaign(self):
        result, create, _ = self.run_command("Lọc 10 kem chống nắng bán chạy toàn kỳ rồi tạo livestream")
        self.assertEqual(result["status"], "NEEDS_INPUT")
        self.assertEqual(len(result["plan"]["resolved_products"]), 1)
        create.assert_not_called()

    def test_compound_partial_coverage_never_creates_campaign(self):
        result, create, _ = self.run_command("Lọc 1 kem chống nắng bán chạy toàn kỳ rồi tạo livestream", [
            *self.PRODUCTS, {"ma_san_pham": "11", "ten_san_pham": "Kem chống nắng B"},
        ])
        self.assertEqual(result["status"], "NEEDS_INPUT")
        create.assert_not_called()

    def test_valid_compound_keeps_evidence_and_assisted_policy(self):
        result, create, enqueue = self.run_command("Lọc 1 kem chống nắng bán chạy toàn kỳ, tạo livestream tối mai lúc 19h")
        self.assertEqual(result["status"], "CAMPAIGN_CREATED")
        self.assertTrue(result["plan"]["query_result"]["complete"])
        self.assertEqual(create.call_args.args[0]["product_ids"], ["10"])
        self.assertEqual(create.call_args.args[0]["configuration"]["script_approval_mode"], "human")
        enqueue.assert_called_once_with("campaign-test")

    def test_explicit_past_and_invalid_date_dont_auto_reschedule(self):
        for date in ["19h ngày 27/9/2020", "19h ngày 31/2/2027"]:
            with self.subTest(date=date):
                result, create, _ = self.run_command("Lọc 1 kem chống nắng bán chạy toàn kỳ, tạo livestream lúc " + date)
                self.assertEqual(result["current_stage"], "SCHEDULE_REVIEW")
                create.assert_not_called()


if __name__ == "__main__":
    unittest.main()


class ApprovalModeSchemaTests(unittest.TestCase):
    def test_approval_mode_is_validated(self):
        from agents.schemas import AgentBrief
        self.assertEqual(AgentBrief.from_mapping({"goal": "x", "approval_mode": " manual "}).approval_mode, "MANUAL")
        self.assertEqual(AgentBrief.from_mapping({"goal": "x", "approval_mode": "AUTO"}).approval_mode, "AUTO")
        self.assertIsNone(AgentBrief.from_mapping({"goal": "x", "approval_mode": "bogus"}).approval_mode)
        self.assertIsNone(AgentBrief.from_mapping({"goal": "x"}).approval_mode)
