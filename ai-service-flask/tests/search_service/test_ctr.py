"""U12 part A: CTR aggregation (position-normalised), Bayesian smoothing, Redis publish/lookup, ranker boost."""

import math

import pytest

from search_service import config, ctr, metrics
from search_service.ranker import Candidate, combine
from search_service.schemas import validate_request
from search_service.service import SearchRecommendService, cache_key

fakeredis = pytest.importorskip("fakeredis")


def imp(section, ids, **extra):
    return {"action": "impression", "section": section, "product_ids": ids, "q_norm": "toner", **extra}


def click(section, pid, **extra):
    return {"action": "click", "section": section, "product_id": pid, "q_norm": "toner", **extra}


# ---------------------------------------------------------------- position normalisation

def test_position_exposure_decays_and_is_one_at_top():
    assert ctr.position_exposure(1) == 1.0
    assert ctr.position_exposure(2) == pytest.approx(0.5)
    assert ctr.position_exposure(8) == pytest.approx(0.25)
    values = [ctr.position_exposure(k) for k in range(1, 21)]
    assert values == sorted(values, reverse=True)
    assert all(ctr.position_exposure(bad) == 1.0 for bad in (None, 0, -3, "x", float("nan")))


def test_aggregate_uses_list_order_as_position():
    counts, totals = ctr.aggregate([imp("semantic", ["a", "b", "c"]), imp("semantic", ["b", "a"]), click("semantic", "a")])
    a, b = counts[("semantic", "a")], counts[("semantic", "b")]
    assert (a.impressions, a.clicks, a.exposure) == (2, 1, pytest.approx(1.0 + 0.5))
    assert (b.impressions, b.exposure) == (2, pytest.approx(0.5 + 1.0))
    assert counts[("semantic", "c")].exposure == pytest.approx(ctr.position_exposure(3))
    assert totals.impression_events == 2 and totals.product_impressions == 5 and totals.clicks == 1


def test_clicks_at_bad_position_are_not_penalised_less_than_top_ones():
    """Same click count, but 'low' sits at rank 8 -> fewer effective impressions -> higher normalised CTR."""

    events = [imp("semantic", ["top"] + ["x"] * 0), imp("semantic", ["f1", "f2", "f3", "f4", "f5", "f6", "f7", "low"])] * 10
    events += [click("semantic", "top")] + [click("semantic", "low")]
    counts, _ = ctr.aggregate(events)
    top, low = counts[("semantic", "top")], counts[("semantic", "low")]
    assert top.clicks == low.clicks == 1 and top.impressions == low.impressions == 10
    assert low.exposure < top.exposure
    assert ctr.smoothed_ctr(low.clicks, low.exposure, 1, 20) > ctr.smoothed_ctr(top.clicks, top.exposure, 1, 20)


def test_legacy_impressions_without_product_ids_are_counted_for_the_gate_only():
    counts, totals = ctr.aggregate([{"action": "impression", "section": "semantic"}, click("semantic", "5")])
    assert totals.impression_events == 1 and totals.skipped_without_products == 1 and totals.product_impressions == 0
    assert counts[("semantic", "5")].impressions == 0


def test_blocks_and_chips_are_ignored_and_test_traffic_is_removed():
    traffic = metrics.TestTraffic(user_hashes={"deadbeef"})
    events = [
        imp("semantic", ["a"]), click("semantic", "a"),
        imp("similar", ["a"]), click("routine", "a"), {"action": "chip_click", "section": "chips", "chip": "category"},
        imp("semantic", ["a"], user_hash="deadbeef"), click("semantic", "a", user_hash="deadbeef"),
        imp("semantic", ["a"], q_norm="u03verify-1"),
    ]
    dropped = {}
    from collections import Counter
    dropped = Counter()
    counts, totals = ctr.aggregate(events, traffic, dropped)
    assert list(counts) == [("semantic", "a")]
    assert counts[("semantic", "a")].impressions == 1 and counts[("semantic", "a")].clicks == 1
    assert dropped == {"event_test_account": 2, "event_test_query": 1}
    assert totals.impression_events == 1 and totals.clicks == 1


# ---------------------------------------------------------------- Bayesian smoothing

def test_smoothed_ctr_formula():
    assert ctr.smoothed_ctr(10, 100, 2, 18) == pytest.approx((10 + 2) / (100 + 2 + 18))
    assert ctr.smoothed_ctr(0, 0, 2, 18) == pytest.approx(0.1)          # no data -> the prior mean
    assert ctr.smoothed_ctr(0, 1000, 2, 18) < 0.01


def test_prior_mean_is_the_pooled_section_ctr_and_strength_is_clamped():
    rows = [ctr.Counts(impressions=200, exposure=100.0, clicks=10) for _ in range(8)]
    alpha, beta, mean = ctr.fit_prior(rows)
    assert mean == pytest.approx(0.1)
    assert alpha / (alpha + beta) == pytest.approx(0.1)
    assert ctr.MIN_PRIOR_STRENGTH <= alpha + beta <= ctr.MAX_PRIOR_STRENGTH
    assert alpha + beta == ctr.MAX_PRIOR_STRENGTH                       # identical products -> no real spread -> max trust


def test_prior_falls_back_to_default_strength_with_few_products_or_no_clicks():
    a, b, m = ctr.fit_prior([ctr.Counts(impressions=100, exposure=60.0, clicks=6)])
    assert a + b == pytest.approx(ctr.DEFAULT_PRIOR_STRENGTH)
    a, b, m = ctr.fit_prior([ctr.Counts(impressions=100, exposure=60.0, clicks=0)])
    assert m == ctr.MEAN_CTR_FLOOR and a > 0 and b > 0
    assert ctr.fit_prior([])[2] == ctr.MEAN_CTR_FLOOR


def test_prior_strength_shrinks_when_products_really_differ():
    spread = [ctr.Counts(impressions=500, exposure=400.0, clicks=c) for c in (4, 8, 40, 80, 120, 4, 8, 120)]
    uniform = [ctr.Counts(impressions=500, exposure=400.0, clicks=c) for c in (40, 41, 39, 40, 42, 38, 40, 41)]
    s_spread = sum(ctr.fit_prior(spread)[:2])
    s_uniform = sum(ctr.fit_prior(uniform)[:2])
    assert s_spread < s_uniform and s_spread >= ctr.MIN_PRIOR_STRENGTH


def test_section_scores_only_eligible_products_get_a_rank_and_norms_are_symmetric():
    rows = {f"p{i}": ctr.Counts(impressions=100, exposure=80.0, clicks=i) for i in range(1, 6)}
    rows["rare"] = ctr.Counts(impressions=10, exposure=8.0, clicks=8)         # fantastic CTR but only 10 impressions
    scores = ctr.build_section_scores(rows, min_impressions=50)
    assert scores.eligible == 5
    norms = {pid: v[1] for pid, v in scores.products.items()}
    assert norms["rare"] == 0.5 and scores.products["rare"][2] == 10
    assert norms["p3"] == pytest.approx(0.5)                                  # the median product gets 0 boost
    assert norms["p1"] < norms["p2"] < norms["p3"] < norms["p4"] < norms["p5"]
    assert norms["p1"] == pytest.approx(0.1) and norms["p5"] == pytest.approx(0.9)
    assert all(0.0 <= n <= 1.0 for n in norms.values())


def test_identical_products_share_the_middle_rank():
    rows = {p: ctr.Counts(impressions=100, exposure=80.0, clicks=5) for p in "abc"}
    assert {v[1] for v in ctr.build_section_scores(rows).products.values()} == {0.5}


def test_compute_groups_by_section_and_reports_gate():
    events = [imp("semantic", ["a", "b"]), click("semantic", "a"), imp("trending", ["z"])]
    scores, totals, dropped = ctr.compute(events, metrics.TestTraffic())
    assert set(scores) == {"semantic", "trending"} and dropped == {}
    gate = totals.gate()
    assert gate["impressions"] == 2 and gate["clicks"] == 1 and gate["met"] is False
    assert totals.gate(impressions=2, clicks=1)["met"] is True


# ---------------------------------------------------------------- Redis publish + lookup

def test_encode_decode_roundtrip_and_min_impressions():
    raw = ctr.encode_value(0.123456789, 0.8, 120)
    assert raw == "0.123457|0.8000|120" and ctr.decode_value(raw) == pytest.approx(0.8)
    assert ctr.decode_value(ctr.encode_value(0.5, 0.9, 49)) is None             # below 50 impressions: ignored
    assert ctr.decode_value("garbage") is None and ctr.decode_value("0.1|1.5|100") is None and ctr.decode_value(None) is None


def test_publish_then_lookup_with_ttl():
    r = fakeredis.FakeRedis(decode_responses=True)
    rows = {f"p{i}": ctr.Counts(impressions=100, exposure=80.0, clicks=i) for i in range(1, 6)}
    rows["few"] = ctr.Counts(impressions=5, exposure=5.0, clicks=1)
    scores = {"semantic": ctr.build_section_scores(rows)}
    written = ctr.publish(r, scores, ttl_s=172800)
    assert written == {"semantic": 6}
    assert 0 < r.ttl("search:ctr:v1:semantic") <= 172800 and r.ttl("search:ctr:v1:_meta") > 0
    assert r.hget("search:ctr:v1:semantic", "p5").endswith("|100")
    lookup = ctr.CtrLookup(lambda: r)
    got = lookup.norms({"semantic": ["p1", "p5", "few", "missing"], "trending": ["p1"], "similar": ["p1"]})
    assert set(got) == {"semantic"} and set(got["semantic"]) == {"p1", "p5"}      # 'few' < 50 impressions, others unknown
    assert got["semantic"]["p5"] > got["semantic"]["p1"]


def test_publish_replaces_previous_hash_and_survives_empty_input():
    r = fakeredis.FakeRedis(decode_responses=True)
    r.hset("search:ctr:v1:semantic", mapping={"old": "0.1|0.5|99"})
    ctr.publish(r, {"semantic": ctr.build_section_scores({"new": ctr.Counts(impressions=60, exposure=60.0, clicks=3)})})
    assert set(r.hgetall("search:ctr:v1:semantic")) == {"new"}
    assert ctr.publish(r, {}) == {} and r.exists("search:ctr:v1:semantic") == 0


def test_lookup_degrades_silently_when_redis_is_down():
    class Down:
        def pipeline(self, **_):
            raise ConnectionError("redis down")

    assert ctr.CtrLookup(lambda: Down()).norms({"semantic": ["1"]}) == {}
    assert ctr.CtrLookup(lambda: None).norms({"semantic": ["1"]}) == {}
    assert ctr.CtrLookup(lambda: None).norms({}) == {}


class FakeEvents:
    def __init__(self, docs):
        self.docs, self.query = docs, None

    def find(self, query, projection=None):
        self.query = query
        return iter(self.docs)


class FakeDb(dict):
    def __getitem__(self, name):
        return dict.get(self, name) or FakeEvents([])


def test_run_job_end_to_end_with_fakes():
    r = fakeredis.FakeRedis(decode_responses=True)
    docs = [imp("semantic", ["a", "b"])] * 60 + [click("semantic", "a")] * 6 + [click("semantic", "b")]
    docs += [imp("semantic", ["a"], q_norm="u03verify-x")] * 5                       # test probe: must not count
    db = FakeDb(search_events=FakeEvents(docs), khach_hang=FakeEvents([]))
    detail = ctr.run_job(db, r, salt="")
    assert detail["impression_events"] == 60 and detail["clicks"] == 7
    assert detail["excluded_test_events"] == {"event_test_query": 5}
    assert detail["gate"]["met"] is False and detail["flag_enabled"] is config.SEARCH_CTR_BOOST_ENABLED
    assert detail["redis_fields"] == {"semantic": 2} and detail["redis"] == "ok"
    assert ctr.CtrLookup(lambda: r).norms({"semantic": ["a", "b"]})["semantic"]["a"] > 0.5 > ctr.CtrLookup(lambda: r).norms({"semantic": ["b"]})["semantic"]["b"]
    # Redis unavailable: the job still returns the numbers
    assert ctr.run_job(db, None, salt="")["redis"] == "unavailable"


# ---------------------------------------------------------------- ranker + service

def C(pid, score, branch="semantic"):
    return Candidate(pid, score, "semantic_match", branch)


def test_combine_adds_symmetric_ctr_boost_only_in_that_section():
    by = {"semantic": [C("a", 1), C("b", 1), C("c", 1)], "trending": [C("a", 1, "trending")]}
    base = {(r.branch, r.product_id): r.score for r in combine(by)}
    boosted = {(r.branch, r.product_id): r.score for r in combine(by, ctr={"semantic": {"a": 1.0, "b": 0.5, "c": 0.0}}, w_ctr=0.08)}
    assert boosted[("semantic", "a")] == pytest.approx(base[("semantic", "a")] + 0.04)
    assert boosted[("semantic", "b")] == pytest.approx(base[("semantic", "b")])             # median: no change
    assert boosted[("semantic", "c")] == pytest.approx(base[("semantic", "c")] - 0.04)
    assert boosted[("trending", "a")] == pytest.approx(base[("trending", "a")])             # other section untouched


def test_combine_without_ctr_or_zero_weight_is_unchanged():
    by = {"semantic": [C("a", 2), C("b", 1)]}
    base = [(r.product_id, r.score) for r in combine(by)]
    assert [(r.product_id, r.score) for r in combine(by, ctr={})] == base
    assert [(r.product_id, r.score) for r in combine(by, ctr={"semantic": {"a": 0.0}}, w_ctr=0.0)] == base


def test_default_ctr_weight_is_the_specified_008():
    assert config.W_CTR == 0.08 and config.CTR_MIN_IMPRESSIONS == 50


def test_service_applies_ctr_only_when_the_flag_is_on(monkeypatch):
    def sem(req, intent):
        return [C("1", 0.5), C("2", 0.5)]

    lookup_calls = []

    def lookup(wanted):
        lookup_calls.append(wanted)
        return {"semantic": {"2": 1.0, "1": 0.0}}

    req = validate_request({"q": "toner"})
    monkeypatch.setattr(config, "SEARCH_CTR_BOOST_ENABLED", False)
    off = SearchRecommendService({"semantic": sem}, ctr_lookup=lookup).recommend(req)
    assert lookup_calls == [] and [i["product_id"] for i in off["sections"][0]["items"]] == ["1", "2"]
    monkeypatch.setattr(config, "SEARCH_CTR_BOOST_ENABLED", True)
    on = SearchRecommendService({"semantic": sem}, ctr_lookup=lookup).recommend(req)
    assert lookup_calls == [{"semantic": ["1", "2"]}]
    assert [i["product_id"] for i in on["sections"][0]["items"]] == ["2", "1"]


def test_service_survives_a_failing_ctr_lookup(monkeypatch):
    monkeypatch.setattr(config, "SEARCH_CTR_BOOST_ENABLED", True)

    def broken(_):
        raise RuntimeError("boom")

    out = SearchRecommendService({"semantic": lambda r, i: [C("1", 1)]}, ctr_lookup=broken).recommend(validate_request({"q": "t"}))
    assert out["sections"][0]["items"][0]["product_id"] == "1"


def test_cache_key_changes_with_the_ctr_flag(monkeypatch):
    req = validate_request({"q": "toner"})
    monkeypatch.setattr(config, "SEARCH_CTR_BOOST_ENABLED", False)
    off = cache_key(req)
    monkeypatch.setattr(config, "SEARCH_CTR_BOOST_ENABLED", True)
    assert cache_key(req) != off


def test_ctr_job_is_registered_at_05_00_in_the_u11_scheduler():
    from datetime import datetime
    from zoneinfo import ZoneInfo

    from workers import jobs

    table = {j.name: j for j in jobs.build_jobs()}
    now = datetime(2026, 10, 23, 0, 0, tzinfo=ZoneInfo("Asia/Ho_Chi_Minh"))
    assert table["ctr_boost"].schedule.next_after(now) == datetime(2026, 10, 23, 5, 0, tzinfo=ZoneInfo("Asia/Ho_Chi_Minh"))
    assert table["ctr_boost"].run is jobs.ctr_boost
    from search_service import ops_health
    assert "ctr_boost" in ops_health.EXPECTED_JOBS
    assert math.isfinite(ops_health.EXPECTED_JOBS["ctr_boost"][0])
