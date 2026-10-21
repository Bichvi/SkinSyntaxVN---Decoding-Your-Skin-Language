"""Bước 08: bought_together component (CO_PURCHASED) — formula, flag, reason_code, cache key."""

import math
import time

import pytest

from search_service import config
from search_service.graph_repository import CO_PURCHASED, GraphRepository
from search_service.ranker import Candidate, bought_together_scores, combine, cut, dedupe
from search_service.schemas import validate_request
from search_service.service import SearchRecommendService, cache_key


def C(pid, score, branch, code="semantic_match", params=()):
    return Candidate(pid, score, code, branch, tuple(params))


def test_bought_together_scores_log1p_normalized():
    out = bought_together_scores({"a": 9, "b": 2, "c": 0})
    assert out["a"] == 1.0
    assert out["b"] == pytest.approx(math.log1p(2) / math.log1p(9))
    assert "c" not in out
    assert bought_together_scores({}) == {} and bought_together_scores({"x": 0}) == {}


def test_combine_adds_w_cop_times_component():
    by = {"semantic": [C("a", 1, "semantic"), C("b", 0.5, "semantic"), C("z", 0, "semantic")]}
    base = {r.product_id: r.score for r in combine(by)}
    boosted = {r.product_id: r.score for r in combine(by, bought_together={"b": 1.0})}
    assert boosted["a"] == base["a"]
    assert boosted["b"] == pytest.approx(base["b"] + config.W_COP)
    assert config.W_COP == 0.10


def test_reason_bought_together_only_when_cop_is_largest_component():
    by = {"semantic": [C("a", 1, "semantic"), C("b", 0.9, "semantic"), C("z", 0, "semantic")]}
    ranked = {r.product_id: r for r in combine(by, bought_together={"a": 1.0, "z": 1.0})}
    # a: semantic 0.30 > cop 0.10 -> keeps its reason; z: semantic 0 -> led by co-purchase.
    assert ranked["a"].reason_code == "semantic_match"
    assert ranked["z"].reason_code == "bought_together" and ranked["z"].reason_params == []
    section = cut(dedupe(list(ranked.values())), 8)[0]
    assert {i["product_id"]: i["reason_code"] for i in section["items"]}["z"] == "bought_together"


def test_no_boost_without_component():
    by = {"trending": [C("a", 10, "trending", "trending"), C("b", 1, "trending", "trending")]}
    assert [r.reason_code for r in combine(by, bought_together={})] == ["trending", "trending"]


def sem(req, intent):
    return [C("1", 0.9, "semantic"), C("2", 0.5, "semantic"), C("3", 0.1, "semantic")]


def test_service_flag_off_ignores_co_purchase(monkeypatch):
    monkeypatch.setattr(config, "SEARCH_CO_PURCHASE_ENABLED", False)
    calls = []
    svc = SearchRecommendService({"semantic": sem}, co_purchase=lambda cart, ids: calls.append(1) or {"3": 5})
    out = svc.recommend(validate_request({"q": "toner", "cart_ids": ["1496"]}))
    assert calls == [] and all(i["reason_code"] == "semantic_match" for i in out["sections"][0]["items"])


def test_service_flag_on_boosts_with_cart(monkeypatch):
    monkeypatch.setattr(config, "SEARCH_CO_PURCHASE_ENABLED", True)
    seen = {}

    def cop(cart, ids):
        seen.update(cart=cart, ids=ids)
        return {"3": 5}

    out = SearchRecommendService({"semantic": sem}, co_purchase=cop).recommend(
        validate_request({"q": "toner", "cart_ids": ["1496"]}))
    items = {i["product_id"]: i for i in out["sections"][0]["items"]}
    assert seen == {"cart": ["1496"], "ids": ["1", "2", "3"]}
    assert items["3"]["reason_code"] == "bought_together" and not out["degraded"]
    # No cart -> never called.
    seen.clear()
    SearchRecommendService({"semantic": sem}, co_purchase=cop).recommend(validate_request({"q": "toner"}))
    assert seen == {}


def test_service_co_purchase_failure_or_timeout_degrades(monkeypatch):
    monkeypatch.setattr(config, "SEARCH_CO_PURCHASE_ENABLED", True)
    monkeypatch.setattr(config, "CO_PURCHASE_TIMEOUT_MS", 50)

    def slow(cart, ids):
        time.sleep(0.3)
        return {"3": 5}

    for fn in (slow, lambda cart, ids: (_ for _ in ()).throw(RuntimeError("neo4j_down"))):
        out = SearchRecommendService({"semantic": sem}, co_purchase=fn).recommend(
            validate_request({"q": "toner", "cart_ids": ["1496"]}))
        assert out["degraded_branches"] == ["bought_together"] and out["sections"]
        assert all(i["reason_code"] == "semantic_match" for i in out["sections"][0]["items"])


def test_cache_key_differs_with_flag_only_when_cart(monkeypatch):
    with_cart = validate_request({"q": "toner", "cart_ids": ["1"]})
    no_cart = validate_request({"q": "toner"})
    monkeypatch.setattr(config, "SEARCH_CO_PURCHASE_ENABLED", False)
    off = (cache_key(with_cart), cache_key(no_cart))
    monkeypatch.setattr(config, "SEARCH_CO_PURCHASE_ENABLED", True)
    on = (cache_key(with_cart), cache_key(no_cart))
    assert off[0] != on[0] and off[1] == on[1]


def test_cache_key_includes_skin_profile():
    a = cache_key(validate_request({"q": "toner", "skin_profile": {"skin_type": "da_dau", "concerns": ["mun"]}}))
    b = cache_key(validate_request({"q": "toner", "skin_profile": {"skin_type": "da_kho", "concerns": ["mun"]}}))
    c = cache_key(validate_request({"q": "toner"}))
    assert len({a, b, c}) == 3


class FakeRepo(GraphRepository):
    def __init__(self):
        super().__init__(driver=None)
        self.calls = []

    def _run(self, cypher, **params):
        self.calls.append((cypher, params))
        return [{"id": "3", "count": 4}]


def test_repository_co_purchased_is_parameterised_and_skips_empty():
    repo = FakeRepo()
    assert repo.co_purchased([], ["1"]) == {} and repo.co_purchased(["1"], []) == {} and repo.calls == []
    assert repo.co_purchased([1496], ["3"]) == {"3": 4}
    cypher, params = repo.calls[0]
    assert cypher == CO_PURCHASED and params == {"cart_ids": ["1496"], "ids": ["3"]}
