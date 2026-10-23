import time
from concurrent.futures import ThreadPoolExecutor

from search_service import config
from search_service.ranker import Candidate, combine, cross_encoder_blend, cut, dedupe, normalize


def C(pid, score, branch, code="semantic_match", params=()):
    return Candidate(pid, score, code, branch, tuple(params))


def test_normalize_min_max_per_branch():
    out = normalize([C("a", 10, "semantic"), C("b", 20, "semantic"), C("c", 15, "semantic")])
    assert out == {"a": 0.0, "b": 1.0, "c": 0.5}


def test_normalize_equal_scores_and_empty():
    assert normalize([C("a", 3, "semantic"), C("b", 3, "semantic")]) == {"a": 1.0, "b": 1.0}
    assert normalize([]) == {}


def test_combine_weights_and_popularity():
    ranked = combine({"semantic": [C("a", 2, "semantic"), C("b", 1, "semantic")]}, sold={"a": 0, "b": 100})
    by = {r.product_id: r.score for r in ranked}
    assert by["a"] == config.WEIGHTS["semantic"] * 1.0
    assert by["b"] == config.WEIGHTS["popularity"] * 1.0


def test_combine_out_of_stock_penalty():
    ranked = combine({"semantic": [C("a", 1, "semantic"), C("b", 1, "semantic")]}, in_stock={"b": False})
    by = {r.product_id: r.score for r in ranked}
    assert round(by["a"] - by["b"], 6) == config.OUT_OF_STOCK_PENALTY


def test_combine_graph_takes_max_of_graph_branches():
    ranked = combine({
        "same_brand": [C("a", 5, "same_brand", "same_brand"), C("x", 0, "same_brand", "same_brand")],
        "category_alt": [C("a", 1, "category_alt", "same_category"), C("y", 9, "category_alt", "same_category")],
    })
    a = [r for r in ranked if r.product_id == "a"]
    assert len(a) == 2 and all(r.score == config.WEIGHTS["graph"] for r in a)


def test_dedupe_keeps_strongest_section_and_excludes():
    ranked = combine({
        "semantic": [C("a", 1, "semantic"), C("b", 2, "semantic")],
        "trending": [C("a", 10, "trending", "trending"), C("c", 5, "trending", "trending")],
    })
    kept = {r.product_id: r.branch for r in dedupe(ranked, exclude_ids=["c"])}
    assert kept == {"a": "trending", "b": "semantic"}


def test_dedupe_tie_prefers_earlier_section():
    ranked = combine({
        "trending": [C("a", 1, "trending", "trending")],
        "semantic": [C("a", 1, "semantic")],
    })
    assert [r.branch for r in dedupe(ranked)] == ["semantic"]


def test_cut_orders_sections_limits_and_drops_empty():
    ranked = dedupe(combine({
        "trending": [C(f"t{i}", i, "trending", "trending") for i in range(5)],
        "semantic": [C(f"s{i}", i, "semantic") for i in range(5)],
        "same_brand": [C(f"b{i}", i, "same_brand", "same_brand", ["Torriden"]) for i in range(3)],
        "personalized": [],
    }))
    sections = cut(ranked, 2)
    assert [s["key"] for s in sections] == ["same_brand", "semantic", "trending"]
    assert all(len(s["items"]) == 2 for s in sections)
    items = sections[1]["items"]
    assert items[0]["score"] >= items[1]["score"]
    assert sections[0]["items"][0]["reason_params"] == ["Torriden"]


def test_cross_encoder_blend_applies_and_skips():
    ranked = combine({"semantic": [C("a", 1, "semantic"), C("b", 0, "semantic")]})
    out, applied = cross_encoder_blend("q", ranked, {"a": "A", "b": "B"}, lambda pairs: [-10.0, 10.0])
    assert applied
    by = {r.product_id: r.score for r in out}
    assert by["b"] > by["a"]

    ranked2 = combine({"semantic": [C("a", 1, "semantic")]})
    before = ranked2[0].score
    _, applied = cross_encoder_blend("q", ranked2, {}, None)
    assert not applied and ranked2[0].score == before

    def boom(pairs):
        raise RuntimeError("x")

    _, applied = cross_encoder_blend("q", ranked2, {}, boom)
    assert not applied and ranked2[0].score == before


def test_cross_encoder_budget_overrun_skipped():
    ranked = combine({"semantic": [C("a", 1, "semantic")]})
    before = ranked[0].score

    def slow(pairs):
        time.sleep(0.3)
        return [5.0]

    with ThreadPoolExecutor(1) as ex:
        _, applied = cross_encoder_blend("q", ranked, {}, slow, budget_ms=50, executor=ex)
    assert not applied and ranked[0].score == before


def test_curated_tags_add_small_flat_boost_without_changing_reason():
    by = {"semantic": [C("a", 1, "semantic"), C("b", 1, "semantic")]}
    base = {r.product_id: r for r in combine(by)}
    boosted = {r.product_id: r for r in combine(by, curated={"b": True}, w_curated=0.05)}
    assert boosted["a"].score == base["a"].score
    assert abs(boosted["b"].score - base["b"].score - 0.05) < 1e-9
    assert boosted["b"].reason_code == base["b"].reason_code
    assert {r.product_id: r.score for r in combine(by, curated={"b": True}, w_curated=0.0)}["b"] == base["b"].score


def test_curated_boost_default_is_005():
    from search_service import config
    assert config.W_CURATED == 0.05
