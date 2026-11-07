"""Structured product query shared by manual mode and the Agent. Deterministic: no database, network or LLM."""
import datetime as dt
import os
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from agents import structured_query
from agents.command_query import build_query_plan, execute_query
from agents.query_sources import bounded_rows
from agents.structured_query import FILTER_ENUMS, FilterError, plan_from_filters, run_structured_query
from fixtures.prompt_builder.cases import FRAGMENTS, compose_brief

PARITY_KEYS = ("stock_min", "stock_max", "expiry_from", "expiry_to", "expiry_required", "rank_by",
               "window_days", "warehouse_id", "batch_id", "brand", "brand_mode", "per_brand_max")
TODAY = dt.date(2026, 10, 15)
PRODUCT_KEYS = {"product_id", "name", "image_raw", "stock", "price", "brand", "metric_label", "metric_value"}


def item(pid, name, **fields):
    return {"ma_san_pham": pid, "ten_san_pham": name, **fields}


class Sources:
    """Replaces the module's Mongo loaders with counting fakes."""

    def __init__(self, catalog, batches=(), snapshots=()):
        self.catalog, self.batches, self.snapshots = list(catalog), list(batches), list(snapshots)
        self.catalog_calls = 0
        self.source_calls = []

    def load_catalog(self, db):
        self.catalog_calls += 1
        return self.catalog

    def load_query_sources(self, db, plan):
        self.source_calls.append(plan)
        return self.batches, self.snapshots

    def patched(self):
        return _Patches(self)


class _Patches:
    def __init__(self, sources):
        self.patches = [patch.object(structured_query, "load_catalog", sources.load_catalog),
                        patch.object(structured_query, "load_query_sources", sources.load_query_sources)]

    def __enter__(self):
        for p in self.patches:
            p.start()

    def __exit__(self, *exc):
        for p in reversed(self.patches):
            p.stop()


class Cursor(list):
    def limit(self, count):
        return Cursor(self[:count])


class Clock:
    def __init__(self, now=0.0):
        self.now = now

    def __call__(self):
        return self.now


def run(filters, sources, *, clock=None, today=TODAY):
    with sources.patched():
        return run_structured_query(filters, db=object(), today=today, clock=clock or Clock())


class ParityTests(unittest.TestCase):
    def selections(self):
        for group in ("expiry", "stock", "sales"):
            for key in FRAGMENTS[group]:
                if key:
                    yield {group: key}
        yield {"brand": "CeraVe"}
        yield {"brand_cap": True}
        yield {"enough_stock": True}
        yield {"warehouse_mode": "warehouse", "scope_code": "KHO-HN-01"}
        yield {"warehouse_mode": "batch", "scope_code": "LO-7"}
        yield {"expiry": "expiry_17_19", "stock": "stock_high", "sales": "best_30", "brand_cap": True}

    def extra_selections(self):
        yield {"expiry": "expiry_max_18", "stock": "out_of_stock"}
        yield {"stock": "out_of_stock", "enough_stock": True}
        yield {"stock": "stock_low", "enough_stock": True}
        yield {"stock": "stock_high", "enough_stock": True}
        yield {"expiry": "expiry_max_18", "sales": "best_7", "brand": "La Roche-Posay"}
        yield {"warehouse_mode": "warehouse", "scope_code": "KHO-HN-01", "sales": "growth_7", "brand_cap": True}

    def assert_parity(self, selections):
        for sel in selections:
            with self.subTest(sel=sel):
                expected = build_query_plan(compose_brief(sel), today=TODAY)
                self.assertEqual(expected["questions"], [])
                actual = plan_from_filters(sel, today=TODAY)
                for key in PARITY_KEYS:
                    self.assertEqual(actual[key], expected[key], key)

    def test_filters_match_prompt_builder_plan(self):
        self.assert_parity(self.selections())

    def test_combined_filters_match_prompt_builder_plan(self):
        self.assert_parity(self.extra_selections())


class PlanFromFiltersTests(unittest.TestCase):
    def test_plan_has_the_keys_of_build_query_plan_plus_category_ids_and_q(self):
        reference = build_query_plan("Tìm sản phẩm", today=TODAY)
        plan = plan_from_filters({}, today=TODAY)
        self.assertEqual(set(plan), set(reference) | {"category_ids", "q"})

    def test_defaults(self):
        plan = plan_from_filters({}, today=TODAY)
        self.assertEqual((plan["limit"], plan["limit_explicit"], plan["category_ids"], plan["q"]), (100, True, None, None))
        self.assertEqual((plan["rank_by"], plan["window_days"], plan["as_of"], plan["questions"]), ("catalog", 30, "2026-10-15", []))
        self.assertIsNone(plan["category"])
        self.assertIsInstance(plan["summary"], list)

    def test_enough_stock_is_ignored_for_out_of_stock(self):
        plan = plan_from_filters({"stock": "out_of_stock", "enough_stock": True}, today=TODAY)
        self.assertEqual((plan["stock_min"], plan["stock_max"], plan["rank_by"]), (None, 0, "stock_asc"))

    def test_unknown_keys_are_ignored(self):
        self.assertEqual(plan_from_filters({"quantity": "5", "live": {"at": "x"}, "styles": ["a"]}, today=TODAY),
                         plan_from_filters({}, today=TODAY))

    def test_brand_is_normalized_and_preferred(self):
        plan = plan_from_filters({"brand": "  La Roche-Posay "}, today=TODAY)
        self.assertEqual((plan["brand"], plan["brand_mode"]), ("la roche-posay", "prefer"))
        self.assertIsNone(plan_from_filters({"brand": "   "}, today=TODAY)["brand"])

    def test_scope_code_keeps_its_case(self):
        self.assertEqual(plan_from_filters({"warehouse_mode": "warehouse", "scope_code": " Kho-hn-01 "}, today=TODAY)["warehouse_id"], "Kho-hn-01")

    def test_scope_code_without_mode_is_ignored(self):
        plan = plan_from_filters({"scope_code": "KHO-HN-01"}, today=TODAY)
        self.assertEqual((plan["warehouse_id"], plan["batch_id"]), (None, None))

    def test_scope_mode_requires_a_code(self):
        for mode in ("warehouse", "batch"):
            with self.subTest(mode=mode), self.assertRaises(ValueError) as caught:
                plan_from_filters({"warehouse_mode": mode, "scope_code": "  "}, today=TODAY)
            self.assertEqual(str(caught.exception), "Bộ lọc không hợp lệ: scope_code")

    def test_flags_accept_form_encoded_values(self):
        for raw, expected in ((True, 2), ("1", 2), ("true", 2), (1, 2), (False, None), ("0", None), ("", None), (None, None), (0, None)):
            with self.subTest(brand_cap=raw):
                self.assertEqual(plan_from_filters({"brand_cap": raw}, today=TODAY)["per_brand_max"], expected)
        for bad in ("maybe", 2, [], {}):
            with self.subTest(enough_stock=bad), self.assertRaises(ValueError) as caught:
                plan_from_filters({"enough_stock": bad}, today=TODAY)
            self.assertEqual(str(caught.exception), "Bộ lọc không hợp lệ: enough_stock")

    def test_unknown_enum_rejected(self):
        with self.assertRaises(ValueError):
            plan_from_filters({"sales": "best_99"}, today=TODAY)

    def test_every_enum_key_is_validated_with_its_name(self):
        for key, allowed in FILTER_ENUMS.items():
            with self.subTest(key=key), self.assertRaises(ValueError) as caught:
                plan_from_filters({key: "khong-co"}, today=TODAY)
            self.assertEqual(str(caught.exception), f"Bộ lọc không hợp lệ: {key}")
            for value in allowed:
                plan_from_filters({key: value, "scope_code": "KHO-1"}, today=TODAY)

    def test_limit_bounds(self):
        self.assertEqual(plan_from_filters({"limit": 1}, today=TODAY)["limit"], 1)
        self.assertEqual(plan_from_filters({"limit": "25"}, today=TODAY)["limit"], 25)
        self.assertEqual(plan_from_filters({"limit": 100}, today=TODAY)["limit"], 100)
        for blank in (None, ""):
            self.assertEqual(plan_from_filters({"limit": blank}, today=TODAY)["limit"], 100)
        for bad in (0, 101, -3, "abc", 2.5, True, []):
            with self.subTest(limit=bad), self.assertRaises(ValueError) as caught:
                plan_from_filters({"limit": bad}, today=TODAY)
            self.assertEqual(str(caught.exception), "Bộ lọc không hợp lệ: limit")

    def test_category_ids(self):
        self.assertEqual(plan_from_filters({"category_ids": [10, "20", 10]}, today=TODAY)["category_ids"], [10, 20])
        self.assertIsNone(plan_from_filters({"category_ids": []}, today=TODAY)["category_ids"])
        self.assertIsNone(plan_from_filters({"category_ids": None}, today=TODAY)["category_ids"])
        for bad in ("10", 10, [10, "x"], [True], [1.5], [None], {"a": 1}):
            with self.subTest(category_ids=bad), self.assertRaises(ValueError) as caught:
                plan_from_filters({"category_ids": bad}, today=TODAY)
            self.assertEqual(str(caught.exception), "Bộ lọc không hợp lệ: category_ids")

    def test_q_is_trimmed_and_blank_means_none(self):
        self.assertEqual(plan_from_filters({"q": "  Serum "}, today=TODAY)["q"], "Serum")
        self.assertIsNone(plan_from_filters({"q": "   "}, today=TODAY)["q"])
        with self.assertRaises(ValueError):
            plan_from_filters({"q": ["serum"]}, today=TODAY)

    def test_non_object_filters_rejected(self):
        for bad in ([], "x", 5):
            with self.subTest(filters=bad), self.assertRaises(ValueError):
                plan_from_filters(bad, today=TODAY)

    def test_every_rejection_is_a_filter_error(self):
        """Only FilterError means "bad filter"; the route maps it (and nothing else) to 400."""
        self.assertTrue(issubclass(FilterError, ValueError))
        digits = "9" * 5000  # int() refuses strings this long with a bare ValueError
        for bad in ({"sales": "x"}, {"limit": 0}, {"limit": digits}, {"category_ids": [digits]}, {"category_ids": "1"},
                    {"q": 1}, {"brand": []}, {"brand_cap": "maybe"}, {"warehouse_mode": "batch"}):
            with self.subTest(filters=bad), self.assertRaises(FilterError):
                plan_from_filters(bad, today=TODAY)
        for bad in ([], "x", 5):
            with self.subTest(filters=bad), self.assertRaises(FilterError):
                plan_from_filters(bad, today=TODAY)


class CommandQueryTests(unittest.TestCase):
    def test_limit_explicit_flag(self):
        self.assertTrue(build_query_plan("Lọc 5 kem chống nắng", today=TODAY)["limit_explicit"])
        self.assertFalse(build_query_plan("Tìm kem chống nắng", today=TODAY)["limit_explicit"])

    def test_category_ids_filter(self):
        catalog = [item("1", "Kem A", ma_danh_muc=10), item("2", "Kem B", ma_danh_muc=10), item("3", "Kem C", ma_danh_muc=20)]
        plan = {**build_query_plan("Lọc 5 sản phẩm", today=TODAY), "category_ids": [10]}
        self.assertEqual([r["product_id"] for r in execute_query(plan, catalog)["selected"]], ["1", "2"])

    def test_category_ids_none_or_missing_means_no_filter(self):
        catalog = [item("1", "Kem A", ma_danh_muc=10), item("3", "Kem C", ma_danh_muc=20), item("4", "Kem D")]
        base = build_query_plan("Lọc 5 sản phẩm", today=TODAY)
        for plan in (base, {**base, "category_ids": None}):
            self.assertEqual(len(execute_query(plan, catalog)["selected"]), 3)

    def test_category_ids_accepts_string_ids_and_drops_rows_without_one(self):
        catalog = [item("1", "Kem A", ma_danh_muc="10"), item("2", "Kem B", ma_danh_muc=10.0), item("3", "Kem C"),
                   item("4", "Kem D", ma_danh_muc="x"), item("5", "Kem E", ma_danh_muc=10.5), item("6", "Kem F", ma_danh_muc=None)]
        plan = {**build_query_plan("Lọc 9 sản phẩm", today=TODAY), "category_ids": [10]}
        self.assertEqual([r["product_id"] for r in execute_query(plan, catalog)["selected"]], ["1", "2"])


class RunStructuredQueryTests(unittest.TestCase):
    def setUp(self):
        structured_query.clear_cache()
        self.addCleanup(structured_query.clear_cache)

    def test_product_shape_and_q_filter(self):
        sources = Sources([
            item("11", "Serum Vitamin C", ma_thuong_hieu=1, thuong_hieu="Klairs", gia_ban=250000, so_luong_ton_kho=30,
                 link_hinh_anh="https://cdn/a.jpg| https://cdn/b.jpg"),
            item("12", "Kem chống nắng", thuong_hieu="Anessa", gia_ban=300000, so_luong_ton_kho=5, hinh_anh="https://cdn/c.jpg"),
            item("13", "SERUM Niacinamide", ten_thuong_hieu="Cocoon", gia_ban=180000, so_luong_ton_kho=0),
        ])
        result = run({"q": "serum"}, sources)
        self.assertTrue(result["ok"])
        self.assertEqual([p["name"] for p in result["products"]], ["Serum Vitamin C", "SERUM Niacinamide"])
        first = result["products"][0]
        self.assertEqual(set(first), PRODUCT_KEYS)
        self.assertEqual(first, {"product_id": "11", "name": "Serum Vitamin C", "image_raw": "https://cdn/a.jpg", "stock": 30.0,
                                 "price": 250000.0, "brand": "Klairs", "metric_label": "Mã sản phẩm tăng dần", "metric_value": None})
        self.assertEqual(result["products"][1]["brand"], "Cocoon")
        self.assertEqual(result["products"][1]["image_raw"], "")
        self.assertEqual(result["matched_count"], 2)
        self.assertEqual((result["warnings"], result["missing_data"]), ([], {}))
        self.assertTrue(result["checked_at"])

    def test_image_falls_back_to_hinh_anh(self):
        sources = Sources([item("12", "Kem chống nắng", hinh_anh="https://cdn/c.jpg|https://cdn/d.jpg")])
        self.assertEqual(run({}, sources)["products"][0]["image_raw"], "https://cdn/c.jpg")

    def test_q_matches_code_and_ignores_accents_and_case(self):
        sources = Sources([item("SP-007", "Sữa rửa mặt"), item("SP-008", "Kem dưỡng")])
        self.assertEqual([p["product_id"] for p in run({"q": "sua RUA"}, sources)["products"]], ["SP-007"])
        self.assertEqual([p["product_id"] for p in run({"q": "sp-008"}, sources)["products"]], ["SP-008"])
        self.assertEqual(run({"q": "khong co"}, sources)["products"], [])

    def test_q_is_applied_before_the_limit_cut(self):
        catalog = [item(f"{i:02d}", f"Kem {i}") for i in range(1, 8)] + [item(f"9{i}", f"Serum {i}") for i in range(1, 5)]
        result = run({"q": "serum", "limit": 2}, Sources(catalog))
        self.assertEqual([p["name"] for p in result["products"]], ["Serum 1", "Serum 2"])
        self.assertEqual(result["matched_count"], 4)

    def test_limit_cuts_but_matched_count_keeps_the_total(self):
        result = run({"limit": 3}, Sources([item(str(i), f"Kem {i}") for i in range(10, 20)]))
        self.assertEqual(len(result["products"]), 3)
        self.assertEqual(result["matched_count"], 10)
        self.assertEqual(result["warnings"], [])

    def test_category_ids_filter_end_to_end(self):
        sources = Sources([item("1", "Kem A", ma_danh_muc=10), item("2", "Kem B", ma_danh_muc=20), item("3", "Kem C", ma_danh_muc="10")])
        self.assertEqual([p["product_id"] for p in run({"category_ids": [10]}, sources)["products"]], ["1", "3"])

    def test_metric_value_for_stock_ranking(self):
        high = Sources([item("1", "Kem A", so_luong_ton_kho=101), item("2", "Kem B", so_luong_ton_kho=140), item("3", "Kem C", so_luong_ton_kho=40)])
        result = run({"stock": "stock_high"}, high)
        self.assertEqual([(p["product_id"], p["metric_value"]) for p in result["products"]], [("2", 140), ("1", 101)])
        self.assertEqual(result["products"][0]["metric_label"], "Tồn kho nhiều nhất trước")
        structured_query.clear_cache()  # the catalog is cached for 30 s
        low = Sources([item("1", "Kem A", so_luong_ton_kho=7), item("2", "Kem B", so_luong_ton_kho=40), item("3", "Kem C", so_luong_ton_kho=0)])
        result = run({"stock": "stock_low"}, low)
        self.assertEqual([(p["product_id"], p["metric_value"]) for p in result["products"]], [("1", 7)])
        self.assertEqual(result["products"][0]["metric_label"], "Tồn kho ít nhất trước")

    def test_metric_value_for_sales_ranking(self):
        snapshot = lambda pid, units: {"product_id": pid, "as_of": TODAY.isoformat(), "window_days": 30,
                                       "coverage_complete": True, "units_sold": units}
        sources = Sources([item("1", "Kem A"), item("2", "Kem B")], snapshots=[snapshot("1", 4), snapshot("2", 9)])
        result = run({"sales": "best_30"}, sources)
        self.assertEqual([(p["product_id"], p["metric_value"]) for p in result["products"]], [("2", 9.0), ("1", 4.0)])
        self.assertEqual(result["products"][0]["metric_label"], "Số lượng bán giảm dần")

    def test_expiry_query_reports_batch_stock_and_expiry_date(self):
        batch = lambda pid, qty, expires: {"product_id": pid, "batch_code": f"L-{pid}", "warehouse_id": "KHO-1", "status": "available",
                                           "available_quantity": qty, "expires_on": expires}
        sources = Sources([item("1", "Kem A", so_luong_ton_kho=999), item("2", "Kem B", so_luong_ton_kho=999)],
                          batches=[batch("1", 12, "2028-04-01"), batch("2", 30, "2028-03-20")])
        result = run({"expiry": "expiry_17_19"}, sources)
        self.assertEqual([(p["product_id"], p["stock"], p["metric_value"]) for p in result["products"]],
                         [("2", 30.0, "2028-03-20"), ("1", 12.0, "2028-04-01")])

    def test_missing_data_is_reported_without_the_count_warning(self):
        result = run({"sales": "best_30"}, Sources([item("1", "Kem A"), item("2", "Kem B")]))
        self.assertEqual(result["products"], [])
        self.assertEqual(result["missing_data"], {"sales_snapshot": 2})
        self.assertTrue(result["warnings"])
        self.assertFalse([w for w in result["warnings"] if w.startswith("Tìm được")])

    def test_sources_cached_for_30_seconds(self):
        sources, clock = Sources([item("1", "Kem A")]), Clock(0)
        run({"stock": "stock_high"}, sources, clock=clock)
        clock.now = 29
        run({"stock": "stock_high"}, sources, clock=clock)
        self.assertEqual((sources.catalog_calls, len(sources.source_calls)), (1, 1))
        clock.now = 31
        run({"stock": "stock_high"}, sources, clock=clock)
        self.assertEqual((sources.catalog_calls, len(sources.source_calls)), (2, 2))

    def test_source_cache_key_follows_the_plan_not_the_presentation(self):
        sources = Sources([item("1", "Kem A")])
        run({"sales": "best_30"}, sources)
        run({"sales": "best_30", "q": "kem", "limit": 5, "category_ids": [1], "brand": "X"}, sources)
        self.assertEqual(len(sources.source_calls), 1)
        run({"sales": "best_7"}, sources)                       # other window_days
        run({"sales": "cart"}, sources)                         # other rank_by
        run({"expiry": "expiry_max_18"}, sources)               # expiry_required
        run({"stock": "stock_high"}, sources)                   # stock bound
        run({"warehouse_mode": "warehouse", "scope_code": "KHO-1"}, sources)
        run({"warehouse_mode": "batch", "scope_code": "LO-1"}, sources)
        self.assertEqual(len(sources.source_calls), 7)
        self.assertEqual(sources.catalog_calls, 1)

    def test_source_cache_key_follows_the_day(self):
        sources = Sources([item("1", "Kem A")])
        run({"sales": "best_30"}, sources, today=TODAY)
        run({"sales": "best_30"}, sources, today=TODAY + dt.timedelta(days=1))
        self.assertEqual(len(sources.source_calls), 2)

    def test_results_are_not_cached(self):
        sources = Sources([item("1", "Serum A"), item("2", "Kem B")])
        with patch.object(structured_query, "execute_query", wraps=execute_query) as spy:
            first = run({"q": "serum"}, sources)
            second = run({"q": "kem"}, sources)
        self.assertEqual(spy.call_count, 2)
        self.assertEqual([p["product_id"] for p in first["products"]], ["1"])
        self.assertEqual([p["product_id"] for p in second["products"]], ["2"])

    def test_source_cache_is_bounded(self):
        sources = Sources([item("1", "Kem A")])
        for index in range(structured_query.CACHE_MAX_ENTRIES + 5):
            run({"warehouse_mode": "warehouse", "scope_code": f"KHO-{index}"}, sources)
        self.assertLessEqual(len(structured_query._sources_cache._items), structured_query.CACHE_MAX_ENTRIES)

    def test_failed_load_is_not_cached(self):
        sources = Sources([item("1", "Kem A")])
        original = sources.load_catalog
        calls = []

        def flaky(db):
            calls.append(1)
            if len(calls) == 1:
                raise RuntimeError("mongo down")
            return original(db)

        sources.load_catalog = flaky
        with self.assertRaises(RuntimeError):
            run({}, sources)
        self.assertEqual(len(run({}, sources)["products"]), 1)

    def test_invalid_filters_raise_before_any_load(self):
        sources = Sources([item("1", "Kem A")])
        with self.assertRaises(ValueError):
            run({"sales": "x"}, sources)
        self.assertEqual((sources.catalog_calls, sources.source_calls), (0, []))


class EndpointTests(unittest.TestCase):
    def setUp(self):
        with patch("core.state_machine.ensure_indexes"):
            import app as service_app
        self.app = service_app
        self.client = service_app.app.test_client()

    def test_endpoint_400_on_bad_filter(self):
        response = self.client.post("/api/ai-idol/products/query", json={"sales": "x"})
        self.assertEqual(response.status_code, 400)
        self.assertEqual(response.get_json(), {"ok": False, "message": "Bộ lọc không hợp lệ: sales"})

    def test_endpoint_400_on_absurdly_long_digit_string(self):
        for body in ({"limit": "9" * 5000}, {"category_ids": ["9" * 5000]}):
            with self.subTest(body=list(body)):
                response = self.client.post("/api/ai-idol/products/query", json=body)
                self.assertEqual(response.status_code, 400)
                self.assertTrue(response.get_json()["message"].startswith("Bộ lọc không hợp lệ: "))

    def test_endpoint_500_when_a_loader_raises_value_error(self):
        """A data-size failure is a server error, not a bad filter, and must not leak developer wording."""
        structured_query.clear_cache()
        self.addCleanup(structured_query.clear_cache)

        def oversized_catalog(db):
            return bounded_rows(Cursor(range(4)), 3, "Catalog")  # raises the loaders' own ValueError

        with patch.object(structured_query, "get_db", return_value=object()), \
                patch.object(structured_query, "load_catalog", oversized_catalog), \
                patch.object(self.app, "logger") as log:
            response = self.client.post("/api/ai-idol/products/query", json={})
        log.exception.assert_called_once()
        self.assertEqual(response.status_code, 500)
        self.assertEqual(response.get_json(), {"ok": False, "message": "Không truy vấn được sản phẩm, thử lại sau."})
        body = response.get_data(as_text=True)
        self.assertNotIn("vượt giới hạn", body)
        self.assertNotIn("aggregation", body)

    def test_endpoint_400_when_body_is_not_an_object(self):
        response = self.client.post("/api/ai-idol/products/query", json=["a"])
        self.assertEqual(response.status_code, 400)
        self.assertFalse(response.get_json()["ok"])

    def test_endpoint_passes_filters_and_returns_the_result(self):
        payload = {"ok": True, "products": [], "matched_count": 0, "warnings": [], "missing_data": {}, "checked_at": "x"}
        with patch.object(self.app, "run_structured_query", return_value=payload) as called:
            response = self.client.post("/api/ai-idol/products/query", json={"q": "serum", "limit": 5})
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.get_json(), payload)
        called.assert_called_once_with({"q": "serum", "limit": 5})

    def test_endpoint_500_hides_internal_errors(self):
        with patch.object(self.app, "run_structured_query", side_effect=RuntimeError("mongodb://user:pass@host/db")), \
                patch.object(self.app, "logger") as log:
            response = self.client.post("/api/ai-idol/products/query", json={})
        log.exception.assert_called_once()
        self.assertEqual(response.status_code, 500)
        self.assertEqual(response.get_json(), {"ok": False, "message": "Không truy vấn được sản phẩm, thử lại sau."})
        self.assertNotIn("pass", response.get_data(as_text=True))


if __name__ == "__main__":
    unittest.main()
