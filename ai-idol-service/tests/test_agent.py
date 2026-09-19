import datetime as dt
import os
import sys
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from agents.intent_parser import fallback_parse
from agents.intent_classifier import (
    CANCEL_CAMPAIGN,
    CREATE_CAMPAIGN,
    PREVIEW_CAMPAIGN,
    PRODUCT_SEARCH,
    RETRY_FAILED,
    classify_intent,
)
from agents.product_resolver import resolve_products
from agents.schemas import AgentBrief
from agents.service import _safe_schedule, cancel_agent_run, process_agent_run
from core.pipeline import should_auto_approve_script
from engines.content_agent import _script_council_payload, generate_content_and_script


class AgentIntentTests(unittest.TestCase):
    def test_classifier_routes_create_request(self):
        result = classify_intent(
            "Cho tôi 1 số sản phẩm retinol để quảng bá, lịch phát lúc 20h, chiếu nội bộ xem trước"
        )
        self.assertEqual(result.intent, CREATE_CAMPAIGN)
        self.assertFalse(result.requires_confirmation)

    def test_classifier_does_not_turn_product_search_into_campaign(self):
        result = classify_intent("Cho tôi sản phẩm retinol")
        self.assertEqual(result.intent, PRODUCT_SEARCH)
        self.assertNotEqual(result.intent, CREATE_CAMPAIGN)

    def test_classifier_handles_operational_commands(self):
        self.assertEqual(classify_intent("xem trước video campaign 12").intent, PREVIEW_CAMPAIGN)
        self.assertEqual(classify_intent("tạo lại sản phẩm 1188 bị lỗi").intent, RETRY_FAILED)
        self.assertEqual(classify_intent("hủy lịch livestream tối nay").intent, CANCEL_CAMPAIGN)

    def test_fallback_extracts_product_time_mode_and_platform(self):
        now = dt.datetime(2026, 9, 17, 10, 0, tzinfo=ZoneInfo("Asia/Ho_Chi_Minh"))
        brief = fallback_parse(
            "Tối mai lúc 20 giờ tạo live 90 phút cho sản phẩm mã 995, phân tích thành phần trên YouTube",
            {"autonomy_policy": "assisted"},
            now=now,
        )
        self.assertEqual(brief.explicit_product_ids, ["995"])
        self.assertEqual(brief.content_mode, "Scientific Review")
        self.assertEqual(brief.duration_minutes, 90)
        self.assertEqual(brief.platforms, ["youtube"])
        self.assertTrue(str(brief.scheduled_at).startswith("2026-09-18T20:00"))
        self.assertEqual(brief.autonomy_policy, "assisted")

    def test_schema_sanitizes_unknown_policy_and_platforms(self):
        brief = AgentBrief.from_mapping({
            "goal": "demo",
            "autonomy_policy": "unrestricted",
            "platforms": ["youtube", "unknown"],
            "duration_minutes": 9999,
        })
        self.assertEqual(brief.autonomy_policy, "auto_preview")
        self.assertEqual(brief.platforms, ["youtube"])
        self.assertEqual(brief.duration_minutes, 720)


class ProductResolverTests(unittest.TestCase):
    CATALOG = [
        {"ma_san_pham": 995, "ten_san_pham": "Kem Dưỡng Vichy Hỗ Trợ Mờ Thâm Nám"},
        {"ma_san_pham": 934, "ten_san_pham": "Serum Rau Má Làm Dịu Da"},
        {"ma_san_pham": 120, "ten_san_pham": "Kem Chống Nắng Dịu Nhẹ"},
    ]

    def test_exact_product_id_wins(self):
        result = resolve_products("giới thiệu sản phẩm 995", ["995"], [], self.CATALOG)
        self.assertEqual([item["product_id"] for item in result["selected"]], ["995"])
        self.assertEqual(result["ambiguous"], [])

    def test_product_name_in_brief_is_selected(self):
        result = resolve_products("Hãy giới thiệu Serum Rau Má Làm Dịu Da", [], [], self.CATALOG)
        self.assertEqual([item["product_id"] for item in result["selected"]], ["934"])

    def test_unknown_id_requires_input(self):
        result = resolve_products("giới thiệu sản phẩm 9999", ["9999"], [], self.CATALOG)
        self.assertEqual(result["selected"], [])
        self.assertEqual(result["unresolved_ids"], ["9999"])

    def test_quantity_phrase_is_not_interpreted_as_product_id(self):
        brief_text = "cho tôi 1 số sản phẩm retinol bán chạy để quảng bá, chiếu nội bộ"
        brief = fallback_parse(brief_text)
        self.assertEqual(brief.explicit_product_ids, [])

        catalog = [
            {"ma_san_pham": "1", "ten_san_pham": "Sữa Rửa Mặt CeraVe Sạch Sâu"},
            {
                "ma_san_pham": "244",
                "ten_san_pham": "Serum Some By Mi Retinol 0.1%",
                "thanh_phan_clean": "retinol 0,1%, retinal",
                "so_luong_ban": 100,
            },
        ]
        result = resolve_products(
            brief_text,
            brief.explicit_product_ids,
            brief.product_queries,
            catalog,
        )
        self.assertEqual([item["product_id"] for item in result["selected"]], ["244"])

    def test_explicit_id_requires_an_id_marker(self):
        self.assertEqual(
            fallback_parse("giới thiệu sản phẩm retinol").explicit_product_ids,
            [],
        )
        self.assertEqual(
            fallback_parse("giới thiệu sản phẩm mã 1").explicit_product_ids,
            ["1"],
        )

    def test_bestseller_request_does_not_claim_without_sales_data(self):
        result = resolve_products(
            "cho tôi sản phẩm retinol bán chạy",
            [],
            ["retinol"],
            [
                {
                    "ma_san_pham": "244",
                    "ten_san_pham": "Serum Some By Mi Retinol 0.1%",
                    "thanh_phan_clean": "retinol",
                    "so_luong_ban": 0,
                }
            ],
        )
        self.assertEqual(result["selected"], [])
        self.assertFalse(result["popularity_data_available"])


class AgentPolicyTests(unittest.TestCase):
    def test_manual_campaign_never_auto_approves(self):
        self.assertFalse(should_auto_approve_script({"script_approval_mode": "auto"}))

    def test_agent_preview_can_auto_approve(self):
        self.assertTrue(should_auto_approve_script({
            "agent_managed": True,
            "script_approval_mode": "auto",
            "agent_autonomy_policy": "auto_preview",
        }))

    def test_missing_schedule_uses_safe_lead_time(self):
        now = dt.datetime(2026, 9, 17, 10, 1, tzinfo=ZoneInfo("Asia/Ho_Chi_Minh"))
        scheduled = _safe_schedule(None, 2, now=now)
        self.assertGreaterEqual(scheduled, now + dt.timedelta(minutes=60))
        self.assertEqual(scheduled.minute % 5, 0)

    def test_auto_preview_forces_internal_and_marks_campaign_agent_managed(self):
        run = {
            "_id": "64b64b64b64b64b64b64b64b",
            "brief": "Giới thiệu sản phẩm mã 995 lên YouTube tối mai",
            "overrides": {
                "autonomy_policy": "auto_preview",
                "explicit_product_ids": ["995"],
            },
            "status": "RECEIVED",
            "campaign_id": None,
            "created_by": "admin",
        }
        catalog = [{"ma_san_pham": 995, "ten_san_pham": "Kem Dưỡng Vichy"}]
        with patch("agents.service.get_agent_run", return_value=run), \
                patch("agents.service.update_agent_run"), \
                patch("agents.service.create_campaign", return_value=("campaign-1", True)) as create:
            process_agent_run(run["_id"], llm=object(), catalog=catalog)

        payload = create.call_args.args[0]
        self.assertEqual(payload["platforms"], ["internal"])
        self.assertEqual(payload["product_ids"], ["995"])
        self.assertTrue(payload["configuration"]["agent_managed"])
        self.assertEqual(payload["configuration"]["script_approval_mode"], "auto")

    def test_non_campaign_intent_stops_before_llm_or_campaign_creation(self):
        run = {
            "_id": "64b64b64b64b64b64b64b64b",
            "brief": "Cho tôi sản phẩm retinol",
            "overrides": {"autonomy_policy": "auto_preview"},
            "status": "RECEIVED",
            "campaign_id": None,
            "created_by": "admin",
        }
        with patch("agents.service.get_agent_run", return_value=run), \
                patch("agents.service.update_agent_run") as update, \
                patch("agents.service.create_campaign") as create, \
                patch("agents.service.parse_agent_brief") as parse:
            result = process_agent_run(run["_id"], llm=object(), catalog=[])

        self.assertEqual(update.call_args_list[1].args[1]["intent_classification"]["intent"], PRODUCT_SEARCH)
        create.assert_not_called()
        parse.assert_not_called()
        final_update = update.call_args_list[-1].args[1]
        self.assertEqual(final_update["current_stage"], "INTENT_REVIEW")

    def test_script_council_payload_is_compact_and_contains_product_facts(self):
        payload = _script_council_payload(
            {
                "name": "Serum Rau Má",
                "ingredients": "x" * 5000,
                "description": "Làm dịu da",
                "price": 199000,
            },
            "k" * 5000,
            "Scientific Review",
            {"length_mode": "Short (30-60s)"},
        )
        source = payload["source"]
        self.assertEqual(source["name"], "Serum Rau Má")
        self.assertLessEqual(len(source["ingredients"]), 1000)
        self.assertLessEqual(len(source["knowledge"]), 1000)

    @patch("engines.content_agent._generate_with_script_council", return_value="Kịch bản tốt. " * 12)
    def test_agent_managed_content_uses_script_council(self, council):
        result = generate_content_and_script(
            {"name": "Serum Rau Má"},
            "",
            "Product Intro",
            {"agent_managed": True},
        )
        self.assertGreater(len(result), 80)
        council.assert_called_once()

    @patch("engines.content_agent._generate_with_script_council", side_effect=RuntimeError("offline"))
    @patch("engines.content_agent.LLMProvider")
    def test_script_council_failure_uses_single_agent_fallback(self, provider, _council):
        provider.return_value.invoke.return_value = "Bản dự phòng an toàn. " * 8
        result = generate_content_and_script(
            {"name": "Serum Rau Má"},
            "",
            "Product Intro",
            {"agent_managed": True},
        )
        self.assertIn("Bản dự phòng", result)
        provider.return_value.invoke.assert_called_once()

    @patch("agents.service.get_agent_run", return_value={"_id": "run-1", "campaign_id": "campaign-1"})
    def test_agent_cannot_cancel_after_campaign_creation(self, _get):
        with self.assertRaisesRegex(ValueError, "Chương trình đã được tạo"):
            cancel_agent_run("run-1")


if __name__ == "__main__":
    unittest.main()
