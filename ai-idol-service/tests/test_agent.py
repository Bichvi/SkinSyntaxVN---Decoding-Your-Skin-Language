import datetime as dt
import os
import sys
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from agents.intent_parser import clean_product_id, fallback_parse
from agents.intent_classifier import (
    CANCEL_CAMPAIGN,
    CREATE_CAMPAIGN,
    PREVIEW_CAMPAIGN,
    PRODUCT_SEARCH,
    RETRY_FAILED,
    classify_intent,
)
from agents.product_resolver import normalize_catalog_product, resolve_products
from agents.schemas import AgentBrief
from agents.service import ConflictError, _safe_schedule, cancel_agent_run, confirm_agent_run, process_agent_run
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


BRIEF_93 = "Tối mai lúc 20 giờ, tạo livestream giới thiệu sản phẩm mã 93 an toàn"


class ProductIdCleaningTests(unittest.TestCase):
    def test_clean_product_id_forms(self):
        self.assertEqual(clean_product_id("mã 93"), "93")
        self.assertEqual(clean_product_id("93.0"), "93")
        self.assertEqual(clean_product_id(93.0), "93")
        self.assertEqual(clean_product_id("#93"), "93")
        self.assertEqual(clean_product_id("ID: 745"), "745")
        self.assertEqual(clean_product_id("Mã sản phẩm ABC12"), "abc12")
        self.assertIsNone(clean_product_id("mã"))
        self.assertIsNone(clean_product_id("an"))
        self.assertIsNone(clean_product_id("9.5"))
        self.assertIsNone(clean_product_id(None))

    def test_brief_from_screenshot_extracts_only_the_id(self):
        self.assertEqual(fallback_parse(BRIEF_93).explicit_product_ids, ["93"])

    def test_schema_cleans_and_dedups_llm_ids(self):
        brief = AgentBrief.from_mapping({"goal": "x", "explicit_product_ids": ["mã 93", "93.0", " ", "an"]})
        self.assertEqual(brief.explicit_product_ids, ["93"])


class ProductAliasResolverTests(unittest.TestCase):
    CATALOG = [
        {"ma_san_pham": 745, "id": 93, "ten_san_pham": "Kem Chống Nắng An Toàn Cho Da Nhạy Cảm"},
        {"ma_san_pham": 120, "ten_san_pham": "Serum Rau Má Làm Dịu Da"},
    ]

    def test_alias_id_resolves_to_primary_ma_san_pham(self):
        result = resolve_products(BRIEF_93, ["93"], [BRIEF_93], self.CATALOG)
        self.assertEqual([item["product_id"] for item in result["selected"]], ["745"])
        self.assertEqual(result["unresolved_ids"], [])

    def test_aliases_cover_ma_id_and_object_id_with_numeric_normalisation(self):
        product = normalize_catalog_product({
            "_id": "64b64b64b64b64b64b64b64b", "ma_san_pham": 745.0, "id": 93, "ten_san_pham": "A",
        })
        self.assertEqual(product["id"], "745")
        self.assertEqual(product["aliases"], ["745", "93", "64b64b64b64b64b64b64b64b"])
        again = normalize_catalog_product(product)  # re-normalising keeps aliases
        self.assertEqual(again["aliases"], product["aliases"])

    def test_float_catalog_id_matches_clean_id(self):
        result = resolve_products("sản phẩm mã 93", ["93"], [], [{"ma_san_pham": 93.0, "ten_san_pham": "Kem A"}])
        self.assertEqual([item["product_id"] for item in result["selected"]], ["93"])

    def test_primary_id_beats_another_products_alias(self):
        catalog = [
            {"ma_san_pham": 93, "ten_san_pham": "Kem Chính Chủ"},
            {"ma_san_pham": 745, "id": 93, "ten_san_pham": "Kem Trùng Alias"},
        ]
        result = resolve_products("mã 93", ["93"], [], catalog)
        self.assertEqual([item["product_id"] for item in result["selected"]], ["93"])

    def test_missing_id_never_falls_back_to_fuzzy_match(self):
        catalog = [{"ma_san_pham": 745, "ten_san_pham": "Kem Chống Nắng An Toàn Cho Da Nhạy Cảm"}]
        result = resolve_products(BRIEF_93, ["93"], [BRIEF_93], catalog)
        self.assertEqual(result["selected"], [])
        self.assertEqual(result["unresolved_ids"], ["93"])
        self.assertEqual(result["unresolved_reasons"], {"93": "NOT_FOUND"})
        self.assertTrue(result["ambiguous"])  # offered as suggestions only
        self.assertLessEqual(len(result["ambiguous"]), 5)

    def test_command_words_do_not_inflate_overlap(self):
        catalog = [{"ma_san_pham": 745, "ten_san_pham": "Rau Má An Toàn"}]
        result = resolve_products("tạo livestream mã an toàn", [], [], catalog)
        self.assertEqual(result["selected"], [])

    def test_explicit_id_does_not_add_product_named_in_brief(self):
        catalog = [
            {"ma_san_pham": 120, "ten_san_pham": "Serum Rau Má Làm Dịu Da"},
            {"ma_san_pham": 934, "ten_san_pham": "Kem Chống Nắng Dịu Nhẹ"},
        ]
        result = resolve_products("mã 120 và Kem Chống Nắng Dịu Nhẹ", ["120"], [], catalog)
        self.assertEqual([item["product_id"] for item in result["selected"]], ["120"])


class AgentRunHarness:
    """Stateful in-memory agent run; no Mongo, no network, no real campaign."""

    def __init__(self, brief, overrides=None, status="RECEIVED"):
        self.run = {
            "_id": "64b64b64b64b64b64b64b64b", "brief": brief, "status": status,
            "overrides": {"autonomy_policy": "assisted", **(overrides or {})},
            "campaign_id": None, "created_by": "admin",
        }

    def execute(self, catalog, *, llm_ids=None, rtmp=None, create=("campaign-1", True), db=None):
        class FakeLlm:
            def invoke_json_schema(_self, *args, **kwargs):
                return {"goal": self.run["brief"], "explicit_product_ids": llm_ids or []}

        def update(_run_id, values):
            self.run.update(values)

        create_kwargs = {"side_effect": create} if isinstance(create, Exception) else {"return_value": create}
        patches = [
            patch("agents.service.get_agent_run", side_effect=lambda _id: self.run),
            patch("agents.service.update_agent_run", side_effect=update),
            patch("agents.service.create_campaign", **create_kwargs),
            patch("agents.service.RTMP_TARGETS", rtmp or {}),
        ]
        if db is not None:
            patches += [patch("agents.service.get_db", return_value=db), patch("agents.service._catalog", return_value=catalog)]
        started = [item.start() for item in patches]
        try:
            kwargs = {"catalog": catalog} if db is None else {}
            process_agent_run(self.run["_id"], llm=FakeLlm(), **kwargs)
        finally:
            for item in patches:
                item.stop()
        self.create = started[2]
        return self.run


class FakeSanPham:
    def __init__(self, document):
        self.document = document
        self.filters = []

    def find_one(self, query, projection=None):
        self.filters.append(query)
        return self.document


class FakeProductDb:
    def __init__(self, document=None):
        self.san_pham = FakeSanPham(document)


class AgentProductConfirmationTests(unittest.TestCase):
    CATALOG = [
        {"ma_san_pham": 745, "id": 98, "ten_san_pham": "Kem Chống Nắng An Toàn"},
        {"ma_san_pham": 93, "ten_san_pham": "Serum Phục Hồi Da"},
    ]

    def test_llm_invented_id_is_dropped_and_real_id_is_used(self):
        harness = AgentRunHarness(BRIEF_93)
        run = harness.execute(self.CATALOG, llm_ids=["555", "93.0"])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.create.call_args.args[0]["product_ids"], ["93"])
        self.assertEqual(run["normalized_brief"]["explicit_product_ids"], ["93"])

    def test_llm_id_absent_from_brief_cannot_pick_a_product(self):
        harness = AgentRunHarness("Tạo livestream giới thiệu serum phục hồi vào tối mai lúc 20 giờ cho khách mới")
        run = harness.execute(self.CATALOG, llm_ids=["745"])
        self.assertEqual(run["normalized_brief"]["explicit_product_ids"], [])

    def test_unknown_code_needs_input_with_structured_plan(self):
        harness = AgentRunHarness(BRIEF_93.replace("mã 93", "mã 9393"))
        run = harness.execute(self.CATALOG)
        harness.create.assert_not_called()
        plan = run["plan"]
        self.assertEqual(run["status"], "NEEDS_INPUT")
        self.assertEqual(plan["resolved_products"], [])
        self.assertEqual(plan["unresolved_ids"], ["9393"])
        self.assertEqual(plan["unresolved_reasons"], {"9393": "NOT_FOUND"})
        self.assertEqual(plan["needs"], ["product"])
        self.assertEqual(plan["confirmable_products"], plan["candidate_products"])
        self.assertIn(
            "Không tìm thấy mã 9393 trong catalog đang bán (đã đối chiếu mã sản phẩm, id và _id).",
            plan["warnings"],
        )

    def test_hidden_product_is_reported_as_inactive(self):
        db = FakeProductDb({"trang_thai": "inactive"})
        harness = AgentRunHarness(BRIEF_93)
        run = harness.execute([], db=db)
        harness.create.assert_not_called()
        self.assertEqual(run["plan"]["unresolved_reasons"], {"93": "INACTIVE"})
        self.assertIn("Mã 93 đang ẩn/ngừng bán.", run["plan"]["warnings"])
        self.assertIn({"ma_san_pham": 93}, db.san_pham.filters[0]["$or"])
        self.assertIn({"id": "93"}, db.san_pham.filters[0]["$or"])

    def test_confirmed_ids_skip_business_query_branch(self):
        harness = AgentRunHarness(
            "Lọc 10 kem chống nắng bán chạy toàn kỳ rồi tạo livestream",
            {"explicit_product_ids": ["745"]},
            status="NEEDS_INPUT",
        )
        run = harness.execute([{"ma_san_pham": 745, "ten_san_pham": "Kem chống nắng A", "so_luong_ban": 5}])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.create.call_args.args[0]["product_ids"], ["745"])

    def test_unconfigured_rtmp_blocks_planning(self):
        harness = AgentRunHarness(
            "Giới thiệu sản phẩm mã 995 lên YouTube tối mai lúc 20 giờ",
            {"explicit_product_ids": ["995"], "platforms": ["youtube"]},
        )
        run = harness.execute([{"ma_san_pham": 995, "ten_san_pham": "Kem Dưỡng Vichy"}])
        harness.create.assert_not_called()
        self.assertEqual((run["status"], run["current_stage"]), ("NEEDS_INPUT", "POLICY_GATE"))
        self.assertEqual(run["plan"]["needs"], ["platform"])
        self.assertIn("Chưa cấu hình RTMP cho youtube. Chọn phát nội bộ hoặc cấu hình trước.", run["plan"]["warnings"])
        self.assertNotIn("rtmp://", str(run["plan"]))

    def test_configured_rtmp_allows_campaign(self):
        harness = AgentRunHarness(
            "Giới thiệu sản phẩm mã 995 lên YouTube tối mai lúc 20 giờ",
            {"explicit_product_ids": ["995"], "platforms": ["youtube"]},
        )
        run = harness.execute(
            [{"ma_san_pham": 995, "ten_san_pham": "Kem Dưỡng Vichy"}],
            rtmp={"youtube": "rtmp://example.invalid/live/secret"},
        )
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.create.call_args.args[0]["platforms"], ["youtube"])

    def test_campaign_validation_error_asks_for_schedule_without_failing(self):
        harness = AgentRunHarness(BRIEF_93, {"explicit_product_ids": ["93"]})
        run = harness.execute(self.CATALOG, create=ValueError("Select at least one supported platform"))
        self.assertEqual((run["status"], run["current_stage"]), ("NEEDS_INPUT", "SCHEDULE_REVIEW"))
        self.assertEqual(run["plan"]["needs"], ["schedule"])
        self.assertIn("Select at least one supported platform", run["plan"]["warnings"][0])
        self.assertIsNone(run["campaign_id"])


class ConfirmAgentRunTests(unittest.TestCase):
    RUN_ID = "64b64b64b64b64b64b64b64b"

    def confirm(self, run, payload, claimed=True):
        def claim(collection, item_id, expected, new_status, extra=None):
            return {"_id": item_id, "status": new_status, **(extra or {})} if run["status"] in expected and claimed else None

        with patch("agents.service.get_agent_run", return_value=run), \
                patch("agents.service.claim_transition", side_effect=claim) as claim_mock:
            try:
                return confirm_agent_run(self.RUN_ID, payload), claim_mock
            except ValueError:
                self.last_claim = claim_mock
                raise

    def test_confirm_while_interpreting_is_a_conflict(self):
        run = {"_id": self.RUN_ID, "status": "INTERPRETING", "overrides": {}}
        with self.assertRaises(ConflictError):
            self.confirm(run, {"product_ids": ["745"]})
        self.assertTrue(issubclass(ConflictError, ValueError))

    def test_confirm_claims_atomically_and_cleans_payload(self):
        run = {"_id": self.RUN_ID, "status": "NEEDS_INPUT", "overrides": {"autonomy_policy": "assisted"}}
        _, claim = self.confirm(run, {"product_ids": ["mã 93", "93.0"], "scheduled_at": "2026-12-01T20:00"})
        args = claim.call_args.args
        self.assertEqual(args[0], "ai_idol_agent_runs")
        self.assertEqual(set(args[2]), {"NEEDS_INPUT", "NEEDS_ATTENTION"})
        self.assertEqual(args[3], "RECEIVED")
        overrides = args[4]["overrides"]
        self.assertEqual(overrides["explicit_product_ids"], ["93"])
        self.assertEqual(overrides["scheduled_at"], "2026-12-01T20:00:00+07:00")
        self.assertEqual(overrides["autonomy_policy"], "assisted")

    def test_invalid_input_is_rejected_before_claiming(self):
        run = {"_id": self.RUN_ID, "status": "NEEDS_INPUT", "overrides": {}}
        for payload in ({"product_ids": ["mã"]}, {"scheduled_at": "không phải ngày"}):
            with self.assertRaises(ValueError) as caught:
                self.confirm(run, payload)
            self.assertNotIsInstance(caught.exception, ConflictError)
            self.last_claim.assert_not_called()

    def test_confirm_after_campaign_creation_is_refused(self):
        run = {"_id": self.RUN_ID, "status": "NEEDS_INPUT", "overrides": {}, "campaign_id": "campaign-1"}
        with self.assertRaisesRegex(ValueError, "already created"):
            self.confirm(run, {"product_ids": ["1"]})


class AgentTaskRetryTests(unittest.TestCase):
    def test_value_error_is_not_retried(self):
        try:
            import tasks
        except ImportError as exc:  # heavy optional modules are absent in the minimal test env
            self.skipTest(f"tasks module unavailable: {exc}")
        with patch.object(tasks, "process_agent_run", side_effect=ValueError("bad input")), \
                patch.object(tasks, "mark_agent_failed") as failed, \
                patch.object(tasks.process_agent_run_task, "retry") as retry:
            result = tasks.process_agent_run_task.run("64b64b64b64b64b64b64b64b")
        retry.assert_not_called()
        failed.assert_called_once()
        self.assertFalse(result["ok"])


class AgentPurposeTests(unittest.TestCase):
    CATALOG = [{"ma_san_pham": 995, "ten_san_pham": "Kem Dưỡng Vichy"}]

    def test_infer_purpose_from_text_and_schedule(self):
        from agents.intent_parser import infer_purpose
        self.assertEqual(infer_purpose("loc 5 serum ban chay", None), "product_video")
        self.assertEqual(infer_purpose("loc 5 serum ban chay", "2026-12-01T20:00:00+07:00"), "live_and_product")
        self.assertEqual(infer_purpose("tao livestream gioi thieu serum", None), "live_and_product")
        self.assertEqual(infer_purpose("gioi thieu san pham ma 995 len youtube toi mai", None), "live_and_product")

    def test_llm_path_reads_the_time_slot_from_the_brief(self):
        from agents.intent_parser import parse_agent_brief

        class NoScheduleLlm:
            def invoke_json_schema(self, *args, **kwargs):
                return {"goal": "x", "explicit_product_ids": ["995"]}

        brief = parse_agent_brief("Giới thiệu sản phẩm mã 995 tối mai lúc 20 giờ", {}, llm=NoScheduleLlm())
        self.assertEqual(brief.purpose, "live_and_product")

    def test_fallback_parse_infers_purpose(self):
        self.assertEqual(fallback_parse("Lọc 5 serum bán chạy").purpose, "product_video")
        self.assertEqual(fallback_parse("Lọc 5 serum, tạo livestream lúc 19h tối mai").purpose, "live_and_product")

    def test_brief_rejects_unknown_purpose(self):
        self.assertIsNone(AgentBrief.from_mapping({"goal": "x", "purpose": "zzz"}).purpose)
        self.assertEqual(AgentBrief.from_mapping({"goal": "x", "purpose": "live_only"}).purpose, "live_only")

    def test_explicit_product_video_wins_over_livestream_words(self):
        harness = AgentRunHarness("Giới thiệu sản phẩm mã 995, tạo livestream lúc 20 giờ tối mai",
                                  {"explicit_product_ids": ["995"], "purpose": "product_video"})
        run = harness.execute(self.CATALOG)
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        payload = harness.create.call_args.args[0]
        self.assertEqual(payload["purpose"], "product_video")
        self.assertIsNone(payload.get("scheduled_at"))
        self.assertEqual(payload["platforms"], [])
        self.assertEqual(payload["configuration"]["length_mode"], "Long (90-150s)")

    def test_product_video_skips_the_rtmp_gate(self):
        harness = AgentRunHarness("Giới thiệu sản phẩm mã 995 lên YouTube",
                                  {"explicit_product_ids": ["995"], "platforms": ["youtube"], "purpose": "product_video"})
        run = harness.execute(self.CATALOG)
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")

    def test_unchosen_purpose_with_schedule_keeps_the_livestream(self):
        harness = AgentRunHarness("Giới thiệu sản phẩm mã 995, tạo livestream lúc 20 giờ tối mai",
                                  {"explicit_product_ids": ["995"]})
        harness.execute(self.CATALOG)
        payload = harness.create.call_args.args[0]
        self.assertEqual(payload["purpose"], "live_and_product")
        self.assertTrue(payload["scheduled_at"])

    def test_create_agent_run_stores_purpose_override(self):
        import core.agent_runs as agent_runs
        from tests.fakes import FakeDb
        db = FakeDb()
        with patch.object(agent_runs, "get_db", return_value=db):
            agent_runs.create_agent_run({"brief": "Lọc 5 serum bán chạy nhất", "purpose": "live_only"})
        self.assertEqual(db.ai_idol_agent_runs.find_one({})["overrides"]["purpose"], "live_only")

    def test_confirm_accepts_purpose(self):
        run = {"_id": "64b64b64b64b64b64b64b64b", "status": "NEEDS_INPUT", "overrides": {}}
        with patch("agents.service.get_agent_run", return_value=run),                 patch("agents.service.claim_transition",
                      side_effect=lambda c, i, e, n, extra=None: {"_id": i, "status": n, **(extra or {})}) as claim:
            confirm_agent_run(run["_id"], {"purpose": "product_video"})
        self.assertEqual(claim.call_args.args[4]["overrides"]["purpose"], "product_video")


if __name__ == "__main__":
    unittest.main()
