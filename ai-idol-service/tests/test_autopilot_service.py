"""Autopilot runs through process_agent_run: every rule of spec 5.1 produces a recorded decision."""
import datetime as dt
import functools
import os
import sys
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import core.agent_runs as agent_runs
import core.campaigns as campaigns
from agents.command_query import METRIC_LABELS
from agents.service import _autopilot_schedule, _earliest_start, _safe_schedule, _stock_levels, process_agent_run
from tests.fakes import FakeDb
from tests.test_agent import AgentRunHarness, FakeProductDb

TZ = ZoneInfo("Asia/Ho_Chi_Minh")
NOW = dt.datetime(2026, 10, 15, 9, 0, tzinfo=TZ)


def row(code, name, stock=10, sold=0, category="Chăm sóc da mặt -> Chống nắng"):
    return {"ma_san_pham": code, "ten_san_pham": name, "so_luong_ton_kho": stock,
            "so_luong_da_ban": sold, "danh_muc_day_du": category}


VICHY = row(995, "Kem Dưỡng Vichy", stock=20)
OTHER = row(934, "Kem Dưỡng Eucerin", stock=5)
SOLD_OUT = row(995, "Kem Dưỡng Vichy", stock=0)
RETINOL = row(777, "Serum Retinol 0.5%", stock=12, category="Chăm sóc da mặt -> Serum")
SUNSCREENS = [row(100 + index, f"Kem Chống Nắng Alpha {index}", sold=50 - index) for index in range(8)]


def snapshot(code, units, window=30):
    """A complete sales snapshot as of NOW's day, the only kind execute_query ranks on."""
    return {"product_id": str(code), "window_days": window, "as_of": "2026-10-15",
            "coverage_complete": True, "units_sold": units}


class AutopilotHarness(AgentRunHarness):
    """The shared run harness, switched to autopilot and pinned to NOW."""

    def __init__(self, brief, overrides=None, mode="autopilot"):
        super().__init__(brief, {"autonomy_policy": "auto_preview", **(overrides or {})})
        if mode is not None:
            self.run["mode"] = mode

    def execute(self, catalog, *, snapshots=None, **kwargs):
        pinned = functools.partial(process_agent_run, now=NOW, snapshots=snapshots)
        with patch("tests.test_agent.process_agent_run", new=pinned):
            return super().execute(catalog, **kwargs)

    @property
    def payload(self):
        return self.create.call_args.args[0]


def codes(run):
    return [item["code"] for item in run.get("decisions") or []]


def decision(run, code):
    return next(item for item in run["decisions"] if item["code"] == code)


class AutopilotCreatesTests(unittest.TestCase):
    def test_search_prompt_creates_product_video(self):
        harness = AutopilotHarness("Cho tôi sản phẩm retinol")
        run = harness.execute([RETINOL])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        harness.create.assert_called_once()
        self.assertEqual(harness.payload["purpose"], "product_video")
        self.assertEqual(harness.payload["product_ids"], ["777"])
        self.assertEqual(harness.payload["configuration"]["agent_mode"], "autopilot")
        self.assertIn("INTENT_CREATE", codes(run))

    def test_classic_run_unchanged(self):
        harness = AutopilotHarness("Cho tôi sản phẩm retinol", mode=None)
        run = harness.execute([RETINOL])
        harness.create.assert_not_called()
        self.assertEqual(run["status"], "NEEDS_INPUT")
        self.assertNotIn("decisions", run)

    def test_classic_payload_carries_no_autopilot_fields(self):
        harness = AutopilotHarness("Làm video giới thiệu sản phẩm mã 995", {"autonomy_policy": "assisted"}, mode="classic")
        run = harness.execute([VICHY])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertNotIn("pending_external_platforms", harness.payload)
        self.assertNotIn("agent_mode", harness.payload["configuration"])
        self.assertNotIn("narration_styles", harness.payload["configuration"])
        self.assertNotIn("decisions", run)

    def test_campaign_created_decision_is_recorded_last(self):
        harness = AutopilotHarness("Làm video giới thiệu sản phẩm mã 995")
        run = harness.execute([VICHY])
        last = run["decisions"][-1]
        self.assertEqual((last["code"], last["level"]), ("CAMPAIGN_CREATED", "done"))
        self.assertTrue(last["message"].startswith("Đã tạo chương trình"))
        for item in run["decisions"]:
            self.assertEqual(set(item), {"code", "level", "message", "at"})

    def test_autopilot_forces_auto_preview_whatever_the_overrides_say(self):
        harness = AutopilotHarness("Làm video giới thiệu sản phẩm mã 995", {"autonomy_policy": "assisted"})
        harness.execute([VICHY])
        self.assertEqual(harness.payload["configuration"]["agent_autonomy_policy"], "auto_preview")
        self.assertEqual(harness.payload["configuration"]["script_approval_mode"], "auto")

    def test_campaign_rejection_stops_with_a_decision(self):
        harness = AutopilotHarness("Làm video giới thiệu sản phẩm mã 995")
        run = harness.execute([VICHY], create=ValueError("Select at least one product"))
        self.assertEqual((run["status"], run["current_stage"]), ("NEEDS_INPUT", "AUTOPILOT_STOPPED"))
        self.assertEqual(run["decisions"][-1]["code"], "CAMPAIGN_REJECTED")
        self.assertEqual(run["decisions"][-1]["level"], "stopped")
        self.assertIn("Select at least one product", run["plan"]["warnings"][0])


class AutopilotProductTests(unittest.TestCase):
    def test_unknown_id_dropped(self):
        harness = AutopilotHarness("Làm video giới thiệu sản phẩm mã 995 và mã 9999")
        run = harness.execute([VICHY])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.payload["product_ids"], ["995"])
        self.assertIn("IDS_DROPPED", codes(run))
        self.assertIn("9999", decision(run, "IDS_DROPPED")["message"])
        self.assertEqual(decision(run, "IDS_DROPPED")["level"], "adjusted")

    def test_no_products_stops(self):
        harness = AutopilotHarness("Làm video giới thiệu sản phẩm mã 9999")
        run = harness.execute([VICHY])
        harness.create.assert_not_called()
        self.assertEqual(run["status"], "NEEDS_INPUT")
        self.assertEqual(run["current_stage"], "AUTOPILOT_STOPPED")
        last = run["decisions"][-1]
        self.assertEqual((last["code"], last["level"]), ("NO_PRODUCTS", "stopped"))
        self.assertEqual(run["plan"]["warnings"][0], last["message"])
        self.assertIsNone(run["campaign_id"])

    def test_no_products_from_a_query_carries_the_query_warnings(self):
        harness = AutopilotHarness("Lọc 5 kem chống nắng bán chạy nhất toàn kỳ")
        run = harness.execute([row(1, "Serum Không Liên Quan", sold=9, category="Chăm sóc da mặt -> Serum")])
        harness.create.assert_not_called()
        last = run["decisions"][-1]
        self.assertEqual(last["code"], "NO_PRODUCTS")
        warnings = run["plan"]["query_result"]["warnings"]
        self.assertTrue(warnings)
        for warning in warnings:
            self.assertIn(warning, last["message"])
            self.assertIn(warning, run["plan"]["warnings"])

    def test_ambiguous_name_picks_the_first_candidate(self):
        catalog = [row(11, "Kem Dưỡng Ẩm Alpha"), row(12, "Kem Dưỡng Ẩm Beta")]
        harness = AutopilotHarness("Làm video giới thiệu kem dưỡng ẩm")
        run = harness.execute(catalog)
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(len(harness.payload["product_ids"]), 1)
        picked = decision(run, "AMBIGUOUS_PICKED")
        self.assertEqual(picked["level"], "adjusted")
        self.assertTrue(any(name in picked["message"] for name in ("Alpha", "Beta")))

    def test_constraint_mismatch_dropped_while_other_ids_stay(self):
        harness = AutopilotHarness("Làm video giới thiệu serum retinol mã 777 và mã 934")
        run = harness.execute([RETINOL, OTHER])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.payload["product_ids"], ["777"])
        dropped = decision(run, "CONSTRAINT_DROPPED")
        self.assertEqual(dropped["level"], "adjusted")
        self.assertIn("934", dropped["message"])

    def test_every_constraint_mismatch_stops_without_a_fuzzy_replacement(self):
        harness = AutopilotHarness("Làm video giới thiệu serum retinol mã 934")
        run = harness.execute([RETINOL, OTHER])
        harness.create.assert_not_called()
        self.assertEqual(codes(run)[-2:], ["CONSTRAINT_DROPPED", "NO_PRODUCTS"])

    def test_operational_intent_stops(self):
        harness = AutopilotHarness("hủy lịch livestream tối nay")
        run = harness.execute([VICHY])
        harness.create.assert_not_called()
        self.assertEqual((run["status"], run["current_stage"]), ("NEEDS_INPUT", "AUTOPILOT_STOPPED"))
        last = run["decisions"][-1]
        self.assertEqual((last["code"], last["level"]), ("INTENT_UNSUPPORTED", "stopped"))
        self.assertTrue(last["message"].startswith("Prompt chưa phải yêu cầu tạo video/livestream"))
        self.assertEqual(run["plan"]["warnings"], [last["message"]])

    def test_unclear_query_stops(self):
        harness = AutopilotHarness("Lọc 5 kem chống nắng bán chạy trong 7 ngày và 30 ngày")
        run = harness.execute(SUNSCREENS)
        harness.create.assert_not_called()
        last = run["decisions"][-1]
        self.assertEqual((last["code"], last["level"]), ("QUERY_UNCLEAR", "stopped"))
        self.assertIn("kỳ thống kê", last["message"])
        self.assertEqual(run["status"], "NEEDS_INPUT")

    def test_default_count_and_cap(self):
        harness = AutopilotHarness("Lọc kem chống nắng bán chạy nhất toàn kỳ")
        run = harness.execute(SUNSCREENS)
        self.assertEqual(len(harness.payload["product_ids"]), 5)
        self.assertIn("COUNT_DEFAULT", codes(run))
        self.assertNotIn("COUNT_SHORT", codes(run))

        harness = AutopilotHarness("Lọc 20 kem chống nắng bán chạy nhất toàn kỳ")
        run = harness.execute(SUNSCREENS)
        self.assertEqual(len(harness.payload["product_ids"]), 8)
        self.assertIn("COUNT_CAP", codes(run))
        self.assertIn("COUNT_SHORT", codes(run))
        self.assertIn("Xin 10", decision(run, "COUNT_SHORT")["message"])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")

    def test_missing_figures_are_noted_but_the_run_goes_on(self):
        catalog = [*SUNSCREENS[:3], {k: v for k, v in SUNSCREENS[3].items() if k != "so_luong_da_ban"}]
        harness = AutopilotHarness("Lọc 5 kem chống nắng bán chạy nhất toàn kỳ")
        run = harness.execute(catalog)
        self.assertEqual(len(harness.payload["product_ids"]), 3)
        gaps = decision(run, "DATA_GAPS")
        self.assertEqual(gaps["level"], "adjusted")
        self.assertIn("doanh số toàn kỳ", gaps["message"])
        self.assertIn("COUNT_SHORT", codes(run))
        # No ranking was replaced, so no fallback may be claimed.
        self.assertNotIn("METRICS_FALLBACK", codes(run))


class AutopilotMetricsFallbackTests(unittest.TestCase):
    """A windowed ranking with no snapshots to rank on falls back to lifetime sales (spec 5.1)."""

    LIFETIME_TOP_5 = ["100", "101", "102", "103", "104"]

    def check_fallback(self, brief, label=METRIC_LABELS["units"]):
        harness = AutopilotHarness(brief)
        run = harness.execute(SUNSCREENS)
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.payload["product_ids"], self.LIFETIME_TOP_5)
        fallback = decision(run, "METRICS_FALLBACK")
        self.assertEqual(fallback["level"], "adjusted")
        self.assertIn("30 ngày", fallback["message"])
        self.assertIn(label, fallback["message"])
        self.assertIn("toàn kỳ", fallback["message"])
        self.assertNotIn("NO_PRODUCTS", codes(run))
        self.assertNotIn("COUNT_SHORT", codes(run))
        self.assertIsNone(run["plan"]["query_result"]["query"]["window_days"])
        self.assertEqual(run["plan"]["query_result"]["query"]["rank_by"], "units")
        return harness, run

    def test_livestream_prompt_without_snapshots_ranks_by_lifetime_sales(self):
        harness, _ = self.check_fallback("Livestream 5 kem chống nắng bán chạy")
        self.assertEqual(harness.payload["purpose"], "live_and_product")

    def test_filter_prompt_without_snapshots_ranks_by_lifetime_sales(self):
        self.check_fallback("Lọc 5 kem chống nắng bán chạy trong 30 ngày qua")

    def test_the_message_names_the_ranking_that_was_replaced(self):
        self.check_fallback("Lọc 5 kem chống nắng thêm giỏ nhiều trong 30 ngày qua", METRIC_LABELS["cart"])

    def test_complete_snapshots_need_no_fallback(self):
        snapshots = [snapshot(100 + index, index + 1) for index in range(8)]  # the reverse of lifetime order
        harness = AutopilotHarness("Lọc 5 kem chống nắng bán chạy trong 30 ngày qua")
        run = harness.execute(SUNSCREENS, snapshots=snapshots)
        self.assertEqual(harness.payload["product_ids"], ["107", "106", "105", "104", "103"])
        self.assertFalse({"METRICS_FALLBACK", "DATA_GAPS", "COUNT_SHORT"} & set(codes(run)))
        self.assertEqual(run["plan"]["query_result"]["query"]["window_days"], 30)

    def test_partial_snapshots_that_leave_the_list_short_fall_back(self):
        harness = AutopilotHarness("Lọc 5 kem chống nắng bán chạy trong 30 ngày qua")
        run = harness.execute(SUNSCREENS, snapshots=[snapshot(105, 9), snapshot(106, 8), snapshot(107, 7)])
        self.assertEqual(harness.payload["product_ids"], self.LIFETIME_TOP_5)
        self.assertIn("METRICS_FALLBACK", codes(run))

    def test_enough_ranked_products_keep_the_period_ranking(self):
        snapshots = [snapshot(100 + index, index + 1) for index in range(6)]  # 2 of 8 have no snapshot
        harness = AutopilotHarness("Lọc 5 kem chống nắng bán chạy trong 30 ngày qua")
        run = harness.execute(SUNSCREENS, snapshots=snapshots)
        self.assertEqual(harness.payload["product_ids"], ["105", "104", "103", "102", "101"])
        self.assertNotIn("METRICS_FALLBACK", codes(run))
        self.assertIn("số liệu bán hàng 30 ngày", decision(run, "DATA_GAPS")["message"])

    def test_a_fallback_that_finds_no_more_keeps_the_period_result(self):
        no_lifetime = [{k: v for k, v in item.items() if k != "so_luong_da_ban"} for item in SUNSCREENS]
        harness = AutopilotHarness("Lọc 5 kem chống nắng bán chạy trong 30 ngày qua")
        run = harness.execute(no_lifetime, snapshots=[snapshot(100, 9), snapshot(101, 8), snapshot(102, 7)])
        self.assertEqual(harness.payload["product_ids"], ["100", "101", "102"])
        self.assertEqual(codes(run).count("COUNT_SHORT"), 1)
        self.assertNotIn("METRICS_FALLBACK", codes(run))
        self.assertIn("DATA_GAPS", codes(run))

    def test_nothing_to_rank_on_still_stops(self):
        no_lifetime = [{k: v for k, v in item.items() if k != "so_luong_da_ban"} for item in SUNSCREENS]
        harness = AutopilotHarness("Lọc 5 kem chống nắng bán chạy trong 30 ngày qua")
        run = harness.execute(no_lifetime)
        harness.create.assert_not_called()
        self.assertEqual(run["decisions"][-1]["code"], "NO_PRODUCTS")
        self.assertNotIn("METRICS_FALLBACK", codes(run))


class AutopilotScheduleTests(unittest.TestCase):
    def test_live_without_time_picks_slot(self):
        harness = AutopilotHarness("Livestream giới thiệu sản phẩm mã 995")
        run = harness.execute([VICHY])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.payload["scheduled_at"], "2026-10-15T12:00:00+07:00")
        self.assertEqual(harness.payload["platforms"], ["internal"])
        self.assertIn("SLOT_PICKED", codes(run))
        self.assertIn("PLATFORM_INTERNAL", codes(run))

    def test_unreadable_clock_does_not_stop_the_run(self):
        harness = AutopilotHarness("Livestream giới thiệu sản phẩm mã 995 lúc 25h")
        run = harness.execute([VICHY])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.payload["scheduled_at"], "2026-10-15T12:00:00+07:00")
        self.assertIn("không đọc được", decision(run, "SLOT_PICKED")["message"])

    def test_past_time_moved(self):
        harness = AutopilotHarness("Livestream sản phẩm mã 995 lúc 20h ngày 14/10/2026")
        run = harness.execute([VICHY])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.payload["scheduled_at"], "2026-10-15T10:00:00+07:00")
        self.assertEqual(decision(run, "SLOT_MOVED")["level"], "adjusted")

    def test_a_date_without_an_hour_keeps_the_day_and_picks_the_slot(self):
        harness = AutopilotHarness("Livestream sản phẩm mã 995 ngày 20/10/2026")
        run = harness.execute([VICHY])
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.payload["scheduled_at"], "2026-10-20T12:00:00+07:00")
        picked = decision(run, "SLOT_PICKED")
        self.assertEqual(picked["level"], "adjusted")
        self.assertEqual(picked["message"], "Prompt có ngày 20/10 nhưng không có giờ; chọn giờ vàng 12:00 20/10.")

    def test_a_date_with_an_unreadable_hour_keeps_the_day_too(self):
        harness = AutopilotHarness("Livestream sản phẩm mã 995 ngày 20/10/2026 lúc 25h")
        run = harness.execute([VICHY])
        self.assertEqual(harness.payload["scheduled_at"], "2026-10-20T12:00:00+07:00")
        self.assertIn("ngày 20/10 nhưng không đọc được giờ", decision(run, "SLOT_PICKED")["message"])

    def test_tomorrow_without_an_hour_is_tomorrow(self):
        harness = AutopilotHarness("Livestream sản phẩm mã 995 ngày mai")
        run = harness.execute([VICHY])
        self.assertEqual(harness.payload["scheduled_at"], "2026-10-16T12:00:00+07:00")
        self.assertIn("ngày 16/10", decision(run, "SLOT_PICKED")["message"])

    def test_a_past_date_without_an_hour_uses_the_earliest_slot_and_says_so(self):
        harness = AutopilotHarness("Livestream sản phẩm mã 995 ngày 10/10/2026")
        run = harness.execute([VICHY])
        self.assertEqual(harness.payload["scheduled_at"], "2026-10-15T12:00:00+07:00")
        message = decision(run, "SLOT_PICKED")["message"]
        self.assertIn("Ngày 10/10", message)
        self.assertIn("đã qua", message)
        self.assertIn("12:00 15/10", message)

    def test_today_with_every_slot_gone_moves_to_the_next_day_and_says_so(self):
        late = dt.datetime(2026, 10, 15, 21, 0, tzinfo=TZ)
        when, picked = _autopilot_schedule("", 1, late, False, day=dt.date(2026, 10, 15))
        self.assertEqual(when, dt.datetime(2026, 10, 16, 12, 0, tzinfo=TZ))
        self.assertIn("Ngày 15/10 không còn khung giờ vàng", picked["message"])
        self.assertIn("12:00 16/10", picked["message"])

    def test_reachable_time_is_kept(self):
        harness = AutopilotHarness("Livestream sản phẩm mã 995 lúc 20h ngày 16/10/2026")
        run = harness.execute([VICHY])
        self.assertEqual(harness.payload["scheduled_at"], "2026-10-16T20:00:00+07:00")
        self.assertEqual(decision(run, "SCHEDULE_KEPT")["level"], "done")

    def test_product_video_has_no_schedule_or_platform_decisions(self):
        harness = AutopilotHarness("Làm video giới thiệu sản phẩm mã 995")
        run = harness.execute([VICHY])
        self.assertIsNone(harness.payload["scheduled_at"])
        self.assertEqual(harness.payload["platforms"], [])
        self.assertEqual(harness.payload["pending_external_platforms"], [])
        self.assertFalse({"SLOT_PICKED", "SLOT_MOVED", "PLATFORM_INTERNAL"} & set(codes(run)))

    def test_youtube_pending_when_configured(self):
        harness = AutopilotHarness("Livestream sản phẩm mã 995 lúc 20h ngày 16/10/2026 trên YouTube")
        run = harness.execute([VICHY], rtmp={"youtube": "x"})
        self.assertEqual(harness.payload["platforms"], ["internal"])
        self.assertEqual(harness.payload["pending_external_platforms"], ["youtube"])
        self.assertEqual(decision(run, "PLATFORM_PENDING")["level"], "waiting")

    def test_youtube_unconfigured_noted(self):
        harness = AutopilotHarness("Livestream sản phẩm mã 995 lúc 20h ngày 16/10/2026 trên YouTube")
        run = harness.execute([VICHY], rtmp={})
        self.assertEqual(run["status"], "CAMPAIGN_CREATED")
        self.assertEqual(harness.payload["platforms"], ["internal"])
        self.assertEqual(harness.payload["pending_external_platforms"], [])
        self.assertIn("PLATFORM_UNCONFIGURED", codes(run))

    def test_rtmp_target_never_reaches_a_decision(self):
        secret = "rtmp://example.invalid/live/topsecretkey"
        harness = AutopilotHarness("Livestream sản phẩm mã 995 lúc 20h ngày 16/10/2026 trên YouTube")
        run = harness.execute([VICHY], rtmp={"youtube": secret})
        self.assertNotIn("topsecretkey", str(run["decisions"]) + str(run["plan"]))

    def test_live_out_of_stock_dropped(self):
        harness = AutopilotHarness("Livestream giới thiệu sản phẩm mã 995 và mã 934")
        run = harness.execute([SOLD_OUT, OTHER])
        self.assertEqual(harness.payload["product_ids"], ["934"])
        self.assertEqual(decision(run, "LIVE_OOS_DROPPED")["level"], "adjusted")

    def test_product_video_keeps_sold_out_products(self):
        harness = AutopilotHarness("Làm video giới thiệu sản phẩm mã 995")
        run = harness.execute([SOLD_OUT])
        self.assertEqual(harness.payload["product_ids"], ["995"])
        self.assertNotIn("LIVE_OOS_DROPPED", codes(run))

    def test_live_with_only_sold_out_products_stops(self):
        harness = AutopilotHarness("Livestream giới thiệu sản phẩm mã 995")
        run = harness.execute([SOLD_OUT])
        harness.create.assert_not_called()
        self.assertEqual(codes(run), ["LIVE_OOS_DROPPED", "NO_PRODUCTS"])
        self.assertEqual(run["decisions"][-1]["level"], "stopped")

    def test_schedule_counts_only_the_products_left_after_the_stock_check(self):
        catalog = [SOLD_OUT, OTHER, row(935, "Kem Dưỡng Cerave", stock=4)]
        harness = AutopilotHarness("Livestream giới thiệu sản phẩm mã 995, mã 934 và mã 935")
        harness.execute(catalog)
        self.assertEqual(harness.payload["product_ids"], ["934", "935"])


class AutopilotStyleTests(unittest.TestCase):
    def test_price_styles_dropped_for_product_video(self):
        harness = AutopilotHarness("Làm video sản phẩm mã 995, giọng vui tươi, chốt sale")
        run = harness.execute([VICHY])
        self.assertEqual(harness.payload["purpose"], "product_video")
        self.assertEqual(harness.payload["configuration"]["narration_styles"], ["cheerful"])
        self.assertEqual(decision(run, "STYLES_PRICE_DROPPED")["level"], "adjusted")
        self.assertEqual(decision(run, "STYLES_APPLIED")["level"], "done")

    def test_price_styles_stay_for_a_livestream(self):
        harness = AutopilotHarness("Livestream sản phẩm mã 995, giọng tập trung chốt sale")
        run = harness.execute([VICHY])
        self.assertEqual(harness.payload["configuration"]["narration_styles"], ["closing"])
        self.assertNotIn("STYLES_PRICE_DROPPED", codes(run))

    def test_a_prompt_without_styles_records_none(self):
        harness = AutopilotHarness("Làm video giới thiệu sản phẩm mã 995")
        run = harness.execute([VICHY])
        self.assertEqual(harness.payload["configuration"]["narration_styles"], [])
        self.assertFalse({"STYLES_APPLIED", "STYLES_PRICE_DROPPED"} & set(codes(run)))


class StockLevelTests(unittest.TestCase):
    def test_evidence_beats_the_catalog_row_beats_the_database(self):
        selected = [
            {"product_id": "1", "evidence": {"stock": 7}},
            {"product_id": "2", "evidence": {"stock": None}},
            {"product_id": "3"},
            {"product_id": "4"},
        ]
        raw = [{"ma_san_pham": 1, "so_luong_ton_kho": 99}, {"ma_san_pham": 2, "so_luong_ton_kho": 0}]
        db = FakeProductDb({"so_luong_ton_kho": 3})
        with patch("agents.service.get_db", return_value=db):
            levels = _stock_levels(selected, raw)
        self.assertEqual(levels, {"1": 7, "2": 0, "3": 3, "4": 3})
        self.assertEqual(len(db.san_pham.filters), 2)  # only the two unknown products hit the database

    def test_unknown_stock_is_none(self):
        with patch("agents.service.get_db", return_value=FakeProductDb(None)):
            self.assertEqual(_stock_levels([{"product_id": "9"}], None), {"9": None})

    def test_a_failing_lookup_is_none_not_an_error(self):
        with patch("agents.service.get_db", side_effect=RuntimeError("no mongo")):
            self.assertEqual(_stock_levels([{"product_id": "9"}], None), {"9": None})


class EarliestStartTests(unittest.TestCase):
    def test_safe_schedule_is_built_on_earliest_start(self):
        self.assertEqual(_earliest_start(1, NOW), NOW + dt.timedelta(minutes=60))
        self.assertEqual(_earliest_start(10, NOW), NOW + dt.timedelta(minutes=95))
        for count in (1, 2, 10):
            earliest = _earliest_start(count, NOW)
            rounded = earliest + dt.timedelta(minutes=(5 - earliest.minute % 5) % 5)
            self.assertEqual(_safe_schedule(None, count, now=NOW), rounded.replace(second=0, microsecond=0))


class CreateAgentRunModeTests(unittest.TestCase):
    def create(self, payload, db=None):
        db = db or FakeDb()
        with patch.object(agent_runs, "get_db", return_value=db):
            run_id, created = agent_runs.create_agent_run({"brief": "x" * 20, **payload}, created_by="admin")
        return db.ai_idol_agent_runs.find_one({}), run_id, created

    def test_create_agent_run_mode(self):
        stored, _, _ = self.create({"mode": "autopilot", "autonomy_policy": "assisted"})
        self.assertEqual(stored["mode"], "autopilot")
        self.assertEqual(stored["overrides"]["autonomy_policy"], "auto_preview")
        self.assertEqual(stored["decisions"], [])

    def test_missing_or_unknown_mode_is_classic(self):
        for payload in ({}, {"mode": "turbo"}, {"mode": None}, {"mode": 5}):
            stored, _, _ = self.create({**payload, "autonomy_policy": "assisted"})
            self.assertEqual(stored["mode"], "classic", payload)
            self.assertEqual(stored["overrides"]["autonomy_policy"], "assisted")

    def test_mode_is_part_of_the_idempotency_hash(self):
        db = FakeDb()
        with patch.object(agent_runs, "get_db", return_value=db):
            classic, classic_created = agent_runs.create_agent_run({"brief": "x" * 20}, "admin")
            auto, auto_created = agent_runs.create_agent_run({"brief": "x" * 20, "mode": "autopilot"}, "admin")
            again, again_created = agent_runs.create_agent_run({"brief": "x" * 20, "mode": "autopilot"}, "admin")
        self.assertTrue(classic_created and auto_created)
        self.assertNotEqual(classic, auto)
        self.assertFalse(again_created)
        self.assertEqual(again, auto)


class PendingExternalPlatformsTests(unittest.TestCase):
    def create(self, **payload):
        db = FakeDb()
        base = {"product_ids": ["1"], "purpose": "live_only", "scheduled_at": "2999-01-01T20:00:00Z",
                "platforms": ["internal"]}
        with patch.object(campaigns, "get_db", return_value=db):
            campaigns.create_campaign({**base, **payload})
        return db.ai_idol_campaigns.find_one({})

    def test_create_campaign_pending_platforms(self):
        stored = self.create(pending_external_platforms=["youtube", "tiktok", "youtube"])
        self.assertEqual(stored["pending_external_platforms"], ["youtube"])
        stored = self.create(pending_external_platforms=["Facebook", " youtube "])
        self.assertEqual(stored["pending_external_platforms"], ["facebook", "youtube"])

    def test_product_video_keeps_no_pending_platform(self):
        stored = self.create(purpose="product_video", pending_external_platforms=["youtube"])
        self.assertEqual(stored["pending_external_platforms"], [])

    def test_missing_or_malformed_pending_is_an_empty_list(self):
        self.assertEqual(self.create()["pending_external_platforms"], [])
        self.assertEqual(self.create(pending_external_platforms="youtube")["pending_external_platforms"], [])
        self.assertEqual(self.create(pending_external_platforms=None)["pending_external_platforms"], [])


if __name__ == "__main__":
    unittest.main()
