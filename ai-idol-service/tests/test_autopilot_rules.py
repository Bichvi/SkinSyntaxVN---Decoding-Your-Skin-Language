import datetime as dt
import json
import os
import sys
import unittest
from unittest.mock import patch
from zoneinfo import ZoneInfo


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import config
from agents.autopilot import (
    autopilot_limit, count_shortfall, drop_out_of_stock, make_decision, next_prime_slot,
    parse_slots, query_questions_decision, resolve_schedule, round_up, split_platforms,
)

TZ = ZoneInfo("Asia/Ho_Chi_Minh")
SLOTS = [dt.time(12), dt.time(20)]


def at(day, hour, minute=0):
    return dt.datetime(2026, 10, day, hour, minute, tzinfo=TZ)


class AutopilotRuleTests(unittest.TestCase):
    def test_limit_default_and_cap(self):
        self.assertEqual(autopilot_limit(10, False, default=5, maximum=10)[0], 5)
        self.assertEqual(autopilot_limit(20, True, default=5, maximum=10)[1]["code"], "COUNT_CAP")
        self.assertEqual(autopilot_limit(3, True, default=5, maximum=10), (3, None))

    def test_shortfall(self):
        self.assertEqual(count_shortfall(10, 7)["level"], "adjusted")
        self.assertEqual(count_shortfall(10, 0)["code"], "NO_PRODUCTS")
        self.assertIsNone(count_shortfall(5, 5))

    def test_slot_same_day(self):
        self.assertEqual(next_prime_slot(at(15, 10), SLOTS, TZ), at(15, 12))

    def test_slot_after_midnight(self):
        self.assertEqual(next_prime_slot(at(15, 23, 50), SLOTS, TZ), at(16, 12))

    def test_schedule_moved_rounds_up(self):
        when, decision = resolve_schedule(at(15, 9), at(15, 10, 7), SLOTS, TZ)
        self.assertEqual((when, decision["code"]), (at(15, 10, 15), "SLOT_MOVED"))

    def test_schedule_kept(self):
        self.assertEqual(resolve_schedule(at(16, 20), at(15, 10), SLOTS, TZ)[0], at(16, 20))

    def test_platform_split(self):
        platforms, pending, decisions = split_platforms(["internal", "youtube", "facebook"], {"youtube"})
        self.assertEqual((platforms, pending), (["internal"], ["youtube"]))
        self.assertEqual([d["code"] for d in decisions], ["PLATFORM_INTERNAL", "PLATFORM_PENDING", "PLATFORM_UNCONFIGURED"])

    def test_out_of_stock_only_for_live(self):
        products = [{"product_id": "1"}, {"product_id": "2"}, {"product_id": "3"}]
        stock = {"1": 0, "2": 5, "3": None}
        kept, decision = drop_out_of_stock(products, stock, "live_only")
        self.assertEqual([p["product_id"] for p in kept], ["2", "3"])
        self.assertEqual(drop_out_of_stock(products, stock, "product_video"), (products, None))

    def test_query_questions(self):
        self.assertIsNone(query_questions_decision(["Số lượng cần nằm trong khoảng 1–50 sản phẩm."]))
        self.assertEqual(query_questions_decision(["Hãy chọn một tiêu chí."])["level"], "stopped")

    def test_parse_slots_fallback(self):
        self.assertEqual(parse_slots("abc"), SLOTS)


class DecisionShapeTests(unittest.TestCase):
    def test_decision_has_the_documented_shape(self):
        now = dt.datetime(2026, 10, 15, 3, 0, tzinfo=TZ)
        decision = make_decision("COUNT_DEFAULT", "adjusted", "Làm với 5 sản phẩm.", now=now)
        self.assertEqual(set(decision), {"code", "level", "message", "at"})
        self.assertEqual(decision["at"], "2026-10-14T20:00:00+00:00")
        self.assertEqual(json.loads(json.dumps(decision)), decision)

    def test_default_clock_is_utc_iso(self):
        stamp = make_decision("X", "done", "ok")["at"]
        self.assertEqual(dt.datetime.fromisoformat(stamp).utcoffset(), dt.timedelta(0))

    def test_naive_now_is_read_as_utc(self):
        decision = make_decision("X", "done", "ok", now=dt.datetime(2026, 10, 15, 3, 0))
        self.assertEqual(decision["at"], "2026-10-15T03:00:00+00:00")

    def test_unknown_level_is_rejected(self):
        with self.assertRaises(ValueError):
            make_decision("X", "fatal", "ok")

    def test_message_is_redacted(self):
        with patch.dict("core.redact.RTMP_TARGETS", {"youtube": "rtmp://a.example/live/SECRETKEY123"}):
            decision = make_decision("X", "done", "Đẩy tới rtmp://a.example/live/SECRETKEY123 xong")
        self.assertNotIn("SECRETKEY123", decision["message"])
        self.assertNotIn("rtmp://", decision["message"])


class CountRuleTests(unittest.TestCase):
    def test_default_when_not_explicit(self):
        count, decision = autopilot_limit(10, False, default=5, maximum=10)
        self.assertEqual((count, decision["code"], decision["level"]), (5, "COUNT_DEFAULT", "adjusted"))
        self.assertIn("5", decision["message"])

    def test_cap_message_names_both_numbers(self):
        count, decision = autopilot_limit(20, True, default=5, maximum=10)
        self.assertEqual((count, decision["level"]), (10, "adjusted"))
        self.assertIn("20", decision["message"])
        self.assertIn("10", decision["message"])

    def test_default_never_exceeds_maximum(self):
        self.assertEqual(autopilot_limit(1, False, default=20, maximum=10)[0], 10)

    def test_exact_maximum_is_not_capped(self):
        self.assertEqual(autopilot_limit(10, True, default=5, maximum=10), (10, None))

    def test_shortfall_message(self):
        decision = count_shortfall(10, 7)
        self.assertEqual(decision["code"], "COUNT_SHORT")
        self.assertEqual(decision["message"], "Xin 10 sản phẩm, tìm được 7; làm với 7 sản phẩm.")

    def test_no_products_stops(self):
        decision = count_shortfall(10, 0)
        self.assertEqual((decision["code"], decision["level"]), ("NO_PRODUCTS", "stopped"))

    def test_more_found_than_requested_is_fine(self):
        self.assertIsNone(count_shortfall(5, 8))


class SlotTests(unittest.TestCase):
    def test_parse_slots_reads_sorted_unique_times(self):
        self.assertEqual(parse_slots(" 20:00, 9:30,12:00,20:00 "), [dt.time(9, 30), dt.time(12), dt.time(20)])

    def test_parse_slots_rejects_any_bad_part(self):
        for value in ("", None, "25:00", "12:60", "12", "12:00,abc", ",", "12:00;20:00"):
            with self.subTest(value=value):
                self.assertEqual(parse_slots(value), SLOTS)

    def test_slot_equal_to_earliest_is_allowed(self):
        self.assertEqual(next_prime_slot(at(15, 12), SLOTS, TZ), at(15, 12))

    def test_slot_one_minute_late_goes_to_next(self):
        self.assertEqual(next_prime_slot(at(15, 12, 1), SLOTS, TZ), at(15, 20))

    def test_slot_converts_other_timezones_to_local_day(self):
        earliest = dt.datetime(2026, 10, 15, 17, 30, tzinfo=dt.timezone.utc)  # 00:30 on the 16th in Vietnam
        self.assertEqual(next_prime_slot(earliest, SLOTS, TZ), at(16, 12))

    def test_single_slot_rolls_to_tomorrow(self):
        self.assertEqual(next_prime_slot(at(15, 21), [dt.time(20)], TZ), at(16, 20))

    def test_round_up_to_quarter_hour(self):
        self.assertEqual(round_up(at(15, 10, 7)), at(15, 10, 15))
        self.assertEqual(round_up(at(15, 10, 46)), at(15, 11))
        self.assertEqual(round_up(at(15, 23, 55)), at(16, 0))

    def test_round_up_keeps_exact_boundary_and_drops_seconds(self):
        self.assertEqual(round_up(at(15, 10, 15)), at(15, 10, 15))
        self.assertEqual(round_up(at(15, 10, 15).replace(second=1)), at(15, 10, 30))
        self.assertEqual(round_up(at(15, 10, 0).replace(microsecond=5)), at(15, 10, 15))

    def test_round_up_custom_step(self):
        self.assertEqual(round_up(at(15, 10, 7), 30), at(15, 10, 30))

    def test_round_up_rejects_bad_step(self):
        with self.assertRaises(ValueError):
            round_up(at(15, 10), 0)


class ScheduleTests(unittest.TestCase):
    def test_missing_time_picks_prime_slot(self):
        when, decision = resolve_schedule(None, at(15, 10, 7), SLOTS, TZ)
        self.assertEqual((when, decision["code"], decision["level"]), (at(15, 12), "SLOT_PICKED", "adjusted"))
        self.assertIn("12:00 15/10", decision["message"])
        self.assertNotIn("không đọc được", decision["message"])

    def test_unreadable_time_says_so(self):
        when, decision = resolve_schedule(None, at(15, 10), SLOTS, TZ, unreadable=True)
        self.assertEqual((when, decision["code"]), (at(15, 12), "SLOT_PICKED"))
        self.assertIn("không đọc được giờ trong prompt", decision["message"])

    def test_requested_time_is_kept(self):
        when, decision = resolve_schedule(at(16, 20), at(15, 10), SLOTS, TZ)
        self.assertEqual((when, decision["code"], decision["level"]), (at(16, 20), "SCHEDULE_KEPT", "done"))
        self.assertIn("20:00 16/10", decision["message"])

    def test_requested_equal_to_earliest_is_kept(self):
        when, decision = resolve_schedule(at(15, 10, 7), at(15, 10, 7), SLOTS, TZ)
        self.assertEqual((when, decision["code"]), (at(15, 10, 7), "SCHEDULE_KEPT"))

    def test_moved_message_has_old_and_new_time(self):
        when, decision = resolve_schedule(at(15, 9), at(15, 10, 7), SLOTS, TZ)
        self.assertEqual(decision["level"], "adjusted")
        self.assertIn("09:00 15/10", decision["message"])
        self.assertIn("10:15 15/10", decision["message"])
        self.assertIn("→", decision["message"])

    def test_times_are_shown_in_the_local_zone(self):
        requested = dt.datetime(2026, 10, 15, 2, 0, tzinfo=dt.timezone.utc)  # 09:00 Vietnam
        _, decision = resolve_schedule(requested, at(15, 10, 7), SLOTS, TZ)
        self.assertIn("09:00 15/10", decision["message"])


class PlatformTests(unittest.TestCase):
    def test_internal_only(self):
        platforms, pending, decisions = split_platforms([], set())
        self.assertEqual((platforms, pending), (["internal"], []))
        self.assertEqual([(d["code"], d["level"]) for d in decisions], [("PLATFORM_INTERNAL", "done")])
        self.assertIn("Nội bộ", decisions[0]["message"])

    def test_configured_external_channel_waits_for_permission(self):
        _, pending, decisions = split_platforms(["youtube"], {"youtube", "facebook"})
        self.assertEqual(pending, ["youtube"])
        self.assertEqual((decisions[1]["code"], decisions[1]["level"]), ("PLATFORM_PENDING", "waiting"))
        self.assertIn("YouTube chờ bạn cho phép", decisions[1]["message"])

    def test_unconfigured_channel_is_not_pending(self):
        _, pending, decisions = split_platforms(["facebook"], set())
        self.assertEqual(pending, [])
        self.assertEqual((decisions[1]["code"], decisions[1]["level"]), ("PLATFORM_UNCONFIGURED", "adjusted"))
        self.assertIn("Chưa cấu hình RTMP cho Facebook", decisions[1]["message"])
        self.assertIn("chỉ phát nội bộ", decisions[1]["message"])

    def test_duplicates_case_and_unknown_channels(self):
        platforms, pending, decisions = split_platforms(["YouTube", "youtube", "preview", "tiktok", " "], {"youtube"})
        self.assertEqual((platforms, pending), (["internal"], ["youtube"]))
        self.assertEqual([d["code"] for d in decisions], ["PLATFORM_INTERNAL", "PLATFORM_PENDING"])

    def test_pending_keeps_request_order(self):
        _, pending, _ = split_platforms(["facebook", "youtube"], {"youtube", "facebook"})
        self.assertEqual(pending, ["facebook", "youtube"])


class OutOfStockTests(unittest.TestCase):
    PRODUCTS = [{"product_id": "1", "name": "Serum A"}, {"product_id": "2", "name": "Kem B"},
                {"product_id": "3", "name": "Sữa rửa mặt C"}]

    def test_names_dropped_products(self):
        kept, decision = drop_out_of_stock(self.PRODUCTS, {"1": 0, "2": 5, "3": -1}, "live_and_product")
        self.assertEqual([p["product_id"] for p in kept], ["2"])
        self.assertEqual((decision["code"], decision["level"]), ("LIVE_OOS_DROPPED", "adjusted"))
        self.assertIn("Serum A", decision["message"])
        self.assertIn("Sữa rửa mặt C", decision["message"])
        self.assertNotIn("Kem B", decision["message"])

    def test_nothing_dropped_means_no_decision(self):
        kept, decision = drop_out_of_stock(self.PRODUCTS, {"1": 1, "2": 5}, "live_only")
        self.assertEqual((kept, decision), (self.PRODUCTS, None))

    def test_unknown_stock_is_kept(self):
        kept, decision = drop_out_of_stock(self.PRODUCTS, {}, "live_only")
        self.assertEqual((kept, decision), (self.PRODUCTS, None))

    def test_video_only_purpose_keeps_everything(self):
        self.assertEqual(drop_out_of_stock(self.PRODUCTS, {"1": 0}, "product_video"), (self.PRODUCTS, None))

    def test_all_dropped_returns_empty_list(self):
        kept, decision = drop_out_of_stock(self.PRODUCTS[:1], {"1": 0}, "live_only")
        self.assertEqual((kept, decision["code"]), ([], "LIVE_OOS_DROPPED"))

    def test_input_list_is_not_mutated(self):
        products = list(self.PRODUCTS)
        drop_out_of_stock(products, {"1": 0}, "live_only")
        self.assertEqual(products, self.PRODUCTS)

    def test_numeric_product_id_matches_string_stock_key(self):
        kept, _ = drop_out_of_stock([{"product_id": 7, "name": "X"}], {"7": 0}, "live_only")
        self.assertEqual(kept, [])


class QueryQuestionTests(unittest.TestCase):
    def test_count_range_question_is_resolved_by_the_cap(self):
        self.assertIsNone(query_questions_decision([]))
        self.assertIsNone(query_questions_decision(["Số lượng cần nằm trong khoảng 1–50 sản phẩm."]))

    def test_other_questions_stop_and_are_joined(self):
        decision = query_questions_decision([
            "Số lượng cần nằm trong khoảng 1–50 sản phẩm.",
            "Chọn 7 hoặc 30 ngày?",
            "Danh mục chưa được hỗ trợ.",
        ])
        self.assertEqual((decision["code"], decision["level"]), ("QUERY_UNCLEAR", "stopped"))
        self.assertEqual(decision["message"], "Chọn 7 hoặc 30 ngày? Danh mục chưa được hỗ trợ.")


class AutopilotConfigTests(unittest.TestCase):
    def test_defaults(self):
        self.assertEqual(
            (config.AUTOPILOT_DEFAULT_PRODUCTS, config.AUTOPILOT_MAX_PRODUCTS, config.AUTOPILOT_SLOTS),
            (5, 10, "12:00,20:00"),
        )

    def test_positive_int_reads_a_valid_value(self):
        with patch.dict(os.environ, {"AI_IDOL_AUTOPILOT_TEST": " 7 "}):
            self.assertEqual(config._positive_int_env("AI_IDOL_AUTOPILOT_TEST", 5), 7)

    def test_positive_int_missing_uses_default_quietly(self):
        with patch.dict(os.environ, {}, clear=False):
            os.environ.pop("AI_IDOL_AUTOPILOT_TEST", None)
            self.assertEqual(config._positive_int_env("AI_IDOL_AUTOPILOT_TEST", 5), 5)

    def test_positive_int_bad_values_warn_and_use_default(self):
        for raw in ("abc", "0", "-3", "2.5"):
            with self.subTest(raw=raw), patch.dict(os.environ, {"AI_IDOL_AUTOPILOT_TEST": raw}):
                with self.assertLogs("AI-IDOL-SERVICE", level="WARNING") as logs:
                    self.assertEqual(config._positive_int_env("AI_IDOL_AUTOPILOT_TEST", 5), 5)
                self.assertIn("AI_IDOL_AUTOPILOT_TEST", logs.output[0])

    def test_slots_string_valid_and_invalid(self):
        with patch.dict(os.environ, {"AI_IDOL_AUTOPILOT_SLOTS": "09:30, 21:00"}):
            self.assertEqual(config._autopilot_slots_env(), "09:30,21:00")
        with patch.dict(os.environ, {"AI_IDOL_AUTOPILOT_SLOTS": "noon"}):
            with self.assertLogs("AI-IDOL-SERVICE", level="WARNING"):
                self.assertEqual(config._autopilot_slots_env(), "12:00,20:00")


if __name__ == "__main__":
    unittest.main()
