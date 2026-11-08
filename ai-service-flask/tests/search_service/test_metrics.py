from datetime import datetime

from search_service.metrics import collect, fold, section_stats, zero_result_stats


def S(query, count=None, live=None):
    meta = {"query": query}
    if count is not None:
        meta["result_count"] = count
    if live is not None:
        meta["live"] = live
    return {"metadata": meta}


def test_fold_matches_php():
    assert fold("  ĐẶC TRỊ   Mụn ") == "dac tri mun"


def test_zero_result_rate_skips_live_and_legacy_rows():
    out = zero_result_stats([
        S("xyzabc", 0), S("XYZABC", 0), S("sữa rửa mặt", 5), S("ser", 0, live=True), S("old row"),
        S("Toner không cồn", 0), S("x", True),
    ])
    assert out["searches"] == 6 and out["searches_with_result_count"] == 4
    assert out["zero_result"] == 3 and out["zero_result_rate"] == 0.75
    assert out["live_partial_skipped"] == 1
    assert out["top_zero_queries"] == [("xyzabc", 2), ("toner khong con", 1)]


def test_zero_result_rate_none_without_counts():
    assert zero_result_stats([S("a")])["zero_result_rate"] is None


def test_section_ctr_and_atc():
    ev = (
        [{"action": "impression", "section": "semantic", "session_hash": "s1"}] * 4
        + [{"action": "click", "section": "semantic", "session_hash": "s1"}]
        + [{"action": "add_to_cart", "section": "semantic", "session_hash": "s2"}] * 2
        + [{"action": "impression", "section": "trending", "session_hash": "s2"}]
        + [{"action": "chip_click", "section": "chips", "chip": "best_sellers", "session_hash": "s2"},
           {"action": "bogus", "section": "semantic"}]
    )
    out = section_stats(ev)
    rows = {r["section"]: r for r in out["sections"]}
    assert rows["semantic"]["ctr"] == 0.25 and rows["semantic"]["atc_rate"] == 0.5
    assert rows["trending"]["clicks"] == 0 and rows["trending"]["ctr"] == 0.0
    assert rows["ALL"]["impressions"] == 5 and rows["ALL"]["ctr"] == 0.2
    assert out["chip_clicks"] == {"best_sellers": 1} and out["sessions"] == 2
    assert [r["section"] for r in out["sections"]] == ["semantic", "trending", "ALL"]


class Coll:
    def __init__(self, docs):
        self.docs, self.queries = docs, []

    def find(self, query, projection=None):
        self.queries.append(query)
        return list(self.docs)


def test_collect_queries_are_read_only_finds_with_window():
    db = {"tuong_tac_nguoi_dung": Coll([S("a", 0)]), "search_events": Coll([])}
    out = collect(db, 7, now=datetime(2026, 10, 21))
    assert out["since"] == datetime(2026, 10, 14)
    assert db["tuong_tac_nguoi_dung"].queries[0]["loai_tuong_tac"] == "search"
    assert db["search_events"].queries[0] == {"ts": {"$gte": datetime(2026, 10, 14)}}
    assert out["zero"]["zero_result"] == 1


# ---- U11: test traffic never reaches the reports ------------------------------------------------------------------

from search_service.metrics import filter_events, filter_searches, is_pseudo_query, is_test_query, load_test_traffic, user_hash  # noqa: E402


def _traffic(salt="s"):
    class KH:
        def find(self, query, projection=None):
            assert query == {"is_test_account": True}
            return [{"ma_kh": 7, "email": "Tester@skinsyntax.test"}]

    return load_test_traffic({"khach_hang": KH()}, salt)


def test_load_test_traffic_matches_the_php_user_hash_recipe():
    traffic = _traffic("s")
    assert traffic.user_ids == {"7"}
    assert traffic.user_hashes == {user_hash("tester@skinsyntax.test", "s")}
    assert len(next(iter(traffic.user_hashes))) == 64
    assert load_test_traffic({}, "s").user_ids == set()          # no collection -> no exclusions, no crash


def test_query_classifiers():
    assert is_test_query("U03verify abc") and not is_test_query("serum")
    assert is_pseudo_query("cart:analyze") and is_pseudo_query("similar:1496") and not is_pseudo_query("similar serum")


def test_filter_searches_drops_test_accounts_and_probes():
    traffic = _traffic()
    docs = [{"ma_kh": 7, "metadata": {"query": "serum", "result_count": 0}},
            {"ma_kh": 8, "metadata": {"query": "u03verify-1", "result_count": 0}},
            {"ma_kh": 8, "metadata": {"query": "toner", "result_count": 0}},
            {"metadata": {"query": "kem", "result_count": 3}}]
    from collections import Counter
    dropped = Counter()
    kept = filter_searches(docs, traffic, dropped)
    assert [d["metadata"]["query"] for d in kept] == ["toner", "kem"]
    assert dropped == {"search_test_account": 1, "search_test_query": 1}


def test_collect_excludes_test_events_and_splits_blocks_from_search_sections():
    h = user_hash("tester@skinsyntax.test", "s")

    class KH:
        def find(self, query, projection=None):
            return [{"ma_kh": 7, "email": "tester@skinsyntax.test"}]

    events = [
        {"action": "impression", "section": "semantic", "session_hash": "a", "q_norm": "serum", "user_hash": None},
        {"action": "impression", "section": "semantic", "session_hash": "b", "q_norm": "u03verify-9"},
        {"action": "impression", "section": "trending", "session_hash": "c", "q_norm": "kem", "user_hash": h},
        {"action": "impression", "section": "similar", "session_hash": "d", "q_norm": "similar:1496"},
        {"action": "click", "section": "similar", "session_hash": "d", "q_norm": "similar:1496"},
        {"action": "impression", "section": "routine", "session_hash": "e", "q_norm": "cart:analyze", "user_hash": h},
    ]
    db = {"khach_hang": KH(), "tuong_tac_nguoi_dung": Coll([]), "search_events": Coll(events)}
    import os
    os.environ["SEARCH_USER_HASH_SALT"] = "s"
    try:
        out = collect(db, 7, now=datetime(2026, 10, 21))
    finally:
        os.environ.pop("SEARCH_USER_HASH_SALT", None)
    rows = {r["section"]: r for r in out["events"]["sections"]}
    assert set(rows) == {"semantic", "ALL"} and rows["semantic"]["impressions"] == 1       # probe + test-account rows gone
    assert rows["ALL"]["impressions"] == 1                                                  # similar/cart rows are not search sections
    blocks = {r["section"]: r for r in out["blocks"]["sections"]}
    assert set(blocks) == {"similar"} and blocks["similar"]["impressions"] == 1 and blocks["similar"]["clicks"] == 1
    assert out["excluded"] == {"search_test_account": 0, "search_test_query": 0, "event_test_account": 2, "event_test_query": 1}


# ---------------------------------------------------------------- U12: experiment report

import pytest  # noqa: E402

from search_service.metrics import experiment_stats, two_proportion_p_value, wilson_interval  # noqa: E402


def test_wilson_matches_known_values():
    lo, hi = wilson_interval(50, 100)
    assert lo == pytest.approx(0.4038, abs=1e-3) and hi == pytest.approx(0.5962, abs=1e-3)
    lo, hi = wilson_interval(0, 50)
    assert lo == pytest.approx(0.0, abs=1e-9) and hi == pytest.approx(0.0713, abs=1e-3)
    lo, hi = wilson_interval(10, 10)
    assert hi == pytest.approx(1.0, abs=1e-9) and lo == pytest.approx(0.7225, abs=1e-3)


def test_wilson_edge_cases():
    assert wilson_interval(0, 0) is None
    assert wilson_interval(15, 10) == wilson_interval(10, 10)         # successes capped at n
    lo, hi = wilson_interval(5, 1000)
    assert 0 < lo < 0.005 < hi < 0.02


def test_two_proportion_p_value():
    assert two_proportion_p_value(50, 1000, 50, 1000) == pytest.approx(1.0)
    assert two_proportion_p_value(80, 1000, 40, 1000) < 0.001
    assert two_proportion_p_value(0, 0, 1, 10) is None
    assert two_proportion_p_value(0, 100, 0, 100) == 1.0


def E(variant, action, section="semantic", session="s1", experiment="exp1", **extra):
    return {"experiment": experiment, "variant": variant, "action": action, "section": section, "session_hash": session, **extra}


def test_experiment_stats_per_variant_and_not_enough_sample():
    events = [E("control", "impression")] * 40 + [E("control", "click")] * 4 + [E("variant", "impression")] * 30 + [
        E("variant", "click")] * 6 + [E("variant", "add_to_cart")] * 3
    events += [E("variant", "impression", experiment="other"), E("variant", "click", section="similar"), E("variant", "chip_click")]
    out = experiment_stats(events, "exp1")
    control, variant = out["variants"]
    assert (control["variant"], control["impressions"], control["clicks"], control["ctr"]) == ("control", 40, 4, 0.1)
    assert (variant["impressions"], variant["clicks"], variant["add_to_cart"]) == (30, 6, 3)
    assert variant["atc_rate"] == pytest.approx(0.1) and variant["ctr_ci95"][0] < 0.2 < variant["ctr_ci95"][1]
    assert out["enough_sample"] is False and out["recommendation"].startswith("Chưa đủ mẫu")
    assert out["comparisons"][0]["variant"] == "variant" and out["comparisons"][0]["ctr_diff"] == pytest.approx(0.1)


def test_experiment_stats_enough_sample_gives_significance_verdict():
    events = [E("control", "impression")] * 1200 + [E("control", "click")] * 60 + [E("variant", "impression")] * 1200 + [
        E("variant", "click")] * 120
    out = experiment_stats(events, "exp1")
    assert out["enough_sample"] is True and "có ý nghĩa" in out["recommendation"] and "chưa" not in out["recommendation"].lower().split(":")[0]
    same = experiment_stats([E("control", "impression")] * 1200 + [E("control", "click")] * 60 + [E("variant", "impression")] * 1200
                            + [E("variant", "click")] * 61, "exp1")
    assert same["enough_sample"] is True and "chưa khác biệt" in same["recommendation"]


def test_experiment_stats_empty_and_single_arm():
    assert "Chưa có sự kiện" in experiment_stats([], "exp1")["recommendation"]
    one = experiment_stats([E("control", "impression")] * 2000, "exp1")
    assert one["enough_sample"] is False and one["comparisons"] == []
