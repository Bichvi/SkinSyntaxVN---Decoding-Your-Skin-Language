from datetime import datetime

import pytest

from graph_sync import extract
from graph_sync.__main__ import EXIT_USAGE, build_parser, main
from graph_sync.report import coverage


class FakeCollection:
    def __init__(self, doc):
        self.doc = doc

    def find_one(self, *_args, **_kwargs):
        return self.doc


def test_parse_since_to_naive_utc():
    assert extract.parse_since("2026-10-01T07:00:00+07:00") == datetime(2026, 10, 1, 0, 0)
    assert extract.parse_since("2026-10-01T00:00:00Z") == datetime(2026, 10, 1)
    with pytest.raises(ValueError):
        extract.parse_since("yesterday")


def test_since_unsupported_without_updated_at():
    with pytest.raises(extract.SinceUnsupported):
        extract.ensure_since_supported({"san_pham": FakeCollection(None)})
    extract.ensure_since_supported({"san_pham": FakeCollection({"_id": 1})})


def test_with_retry_retries_then_succeeds(monkeypatch):
    monkeypatch.setattr(extract.time, "sleep", lambda _s: None)
    calls = {"n": 0}

    def flaky():
        calls["n"] += 1
        if calls["n"] < 3:
            raise OSError("dns")
        return "ok"

    assert extract.with_retry(flaky, "test") == "ok" and calls["n"] == 3


def test_cli_modes_are_exclusive_and_required():
    with pytest.raises(SystemExit):
        build_parser().parse_args(["--full", "--similarity-only"])
    assert main([]) == EXIT_USAGE


def test_coverage_counts_only_active_rows():
    rows = [
        {"active": True, "ingredients": [{"inci": "Water", "name": "Water"}, {"inci": None, "name": "X"},
                                         {"inci": None, "name": "X2"}],
         "ingredient_source": "full", "skin_types": [{"source": "data"}], "benefits": [], "concerns": [],
         "brand": [{}], "category_source": "danh_muc", "origin": []},
        {"active": False, "ingredients": [], "ingredient_source": None, "skin_types": [], "benefits": [],
         "concerns": [], "brand": [], "category_source": None, "origin": []},
    ]
    cov = coverage(rows)
    assert cov["products_active"] == 1 and cov["products_hidden"] == 1
    assert cov["pct_ingredients_ge3"] == 100.0 and cov["pct_skin_type_data"] == 100.0
    assert cov["pct_ingredient_refs_mapped"] == pytest.approx(33.3)
    assert cov["top_unmapped"] == [("X", 1), ("X2", 1)]
