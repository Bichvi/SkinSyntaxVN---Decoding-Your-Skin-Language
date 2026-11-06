"""CartAnalyzeService.cart_ingredients / step_candidates (additive public methods)."""

import pytest

from search_service.cart_analyze import CartAnalyzeService


class FakeGraph:
    def __init__(self, profile=None, rows=None, error=None):
        self.profile, self.rows, self.error = profile or {}, rows or [], error
        self.calls = []

    def cart_categories(self, ids):
        return {}

    def cart_profile(self, ids, keys):
        self.calls.append(("profile", tuple(ids), tuple(keys)))
        if self.error:
            raise self.error
        return self.profile

    def routine_candidates(self, categories, cart_ids, skin_type, keys, limit):
        self.calls.append(("cand", tuple(categories), tuple(cart_ids), skin_type, tuple(keys), limit))
        if self.error:
            raise self.error
        return self.rows

    def co_purchased(self, cart_ids, ids):
        return {}


class FakeCatalog:
    def get(self, pid):
        return {"category_keys": ["serum", "cham_soc_da_mat"]} if pid in ("1", "2") else None


def row(pid, ings=(), skin_fit=False, sold=5):
    return {"id": pid, "sold": sold, "skin_fit": skin_fit, "benefits": [], "brand": "b" + pid,
            "conflict_ings": [{"key": k, "rank": r} for k, r in ings]}


def test_cart_ingredients_graph_path_uses_rule_keys():
    graph = FakeGraph({"1": {"benefits": [], "conflict_ings": [{"key": "retinol", "rank": 4}]}})
    svc = CartAnalyzeService(graph, FakeCatalog())
    found, cats, degraded = svc.cart_ingredients(["1", "2"])
    assert found == {"1": [("retinol", 4)], "2": []} and degraded is False
    assert cats["1"] == ["serum", "cham_soc_da_mat"]
    assert "retinol" in graph.calls[0][2] and graph.calls[0][2] == tuple(sorted(svc.rule_set.keys()))


def test_cart_ingredients_empty_ids():
    assert CartAnalyzeService(FakeGraph(), None).cart_ingredients([]) == ({}, {}, False)


@pytest.mark.parametrize("graph", [None, FakeGraph(error=RuntimeError("neo4j down"))])
def test_cart_ingredients_text_fallback_is_degraded(graph):
    loader = lambda ids: {"1": {"full": "Water, Glycerin, Retinol, Squalane, Tocopherol", "sach": ""}}
    svc = CartAnalyzeService(graph, FakeCatalog(), text_loader=loader)
    found, cats, degraded = svc.cart_ingredients(["1"])
    assert degraded is True and ("retinol", 3) in found["1"]


def test_cart_ingredients_text_loader_failure_still_degraded():
    def boom(ids):
        raise RuntimeError("mongo")

    found, _, degraded = CartAnalyzeService(None, None, text_loader=boom).cart_ingredients(["1"])
    assert found == {} and degraded is True


def test_step_candidates_returns_conflict_ings_and_adds_extra_keys():
    graph = FakeGraph({}, rows=[row("90", [("glycolic_acid", 3)], skin_fit=True), row("91", [("niacinamide", 2)])])
    svc = CartAnalyzeService(graph, FakeCatalog())
    out = svc.step_candidates("toner", ["1"], "da_dau", extra_keys=["fragrance"], limit=6)
    assert {o["product_id"] for o in out} == {"90", "91"}
    by_id = {o["product_id"]: o for o in out}
    assert by_id["90"]["conflict_ings"] == [{"key": "glycolic_acid", "rank": 3}]
    assert by_id["90"]["reason_code"] == "fits_skin_type"
    cand_call = [c for c in graph.calls if c[0] == "cand"][0]
    assert cand_call[1] == ("toner",) and cand_call[3] == "da_dau" and "fragrance" in cand_call[4]


def test_step_candidates_without_graph_or_step_is_empty():
    assert CartAnalyzeService(None, None).step_candidates("toner", [], None) == []
    assert CartAnalyzeService(FakeGraph(), None).step_candidates("nope", [], None) == []


def test_step_candidates_graph_errors_propagate():
    svc = CartAnalyzeService(FakeGraph(error=RuntimeError("x")), FakeCatalog())
    with pytest.raises(RuntimeError):
        svc.step_candidates("toner", ["1"], None)
