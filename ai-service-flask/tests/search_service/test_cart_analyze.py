"""U09: POST /api/cart/analyze - validation, conflict grouping, routine, suggestions, fallback, route, contract."""

import json
import os
from pathlib import Path

import pytest
from flask import Flask

from graph_sync.conflicts import load_rule_set
from search_service import cart_analyze, config, routes
from search_service.cart_analyze import (CartAnalyzeService, CartRequest, CartValidationError, adjust_severity,
                                         conflicts_from_ingredients, conflicts_from_legacy_text,
                                         conflicts_from_rows, parse_request, rank_step_candidates)
from search_service.routine import analyze as analyze_routine, load_routine

jsonschema = pytest.importorskip("jsonschema")
SCHEMA = json.loads((Path(__file__).resolve().parents[2] / "search_service" / "contract"
                     / "cart_analyze_response.schema.json").read_text(encoding="utf-8"))
RULES = load_rule_set()
ROUTINE = load_routine()


def row(id1, id2, k1="retinol", k2="salicylic_acid", n1="Retinol", n2="Salicylic Acid", r1=5, r2=6,
        severity="high", rule="retinoid_bha"):
    return {"id1": id1, "id2": id2, "key1": k1, "key2": k2, "name1": n1, "name2": n2, "rank1": r1, "rank2": r2,
            "severity": severity, "timing": "same_routine", "rule_id": rule, "advice_vi": "tách buổi", "evidence": "strong"}


CATS = {"1": ["serum", "dac_tri", "cham_soc_da_mat"], "2": ["toner", "lam_sach_da", "cham_soc_da_mat"],
        "3": ["kem_duong", "duong_am", "cham_soc_da_mat"], "4": ["sua_rua_mat", "lam_sach_da", "cham_soc_da_mat"]}


# -- parse_request
def test_parse_request_ok_and_dedupes():
    req = parse_request({"product_ids": ["1", 2, "1"], "skin_profile": {"skin_type": "Da_Dau"}})
    assert req == CartRequest(("1", "2"), "da_dau")
    assert parse_request({"product_ids": []}).product_ids == ()


@pytest.mark.parametrize("payload, field", [
    (None, "body"), ([], "body"), ({}, "product_ids"), ({"product_ids": "1"}, "product_ids"),
    ({"product_ids": [str(i) for i in range(51)]}, "product_ids"), ({"product_ids": ["../x"]}, "product_ids"),
    ({"product_ids": [True]}, "product_ids"), ({"product_ids": ["1"], "skin_profile": "x"}, "skin_profile"),
    ({"product_ids": ["1"], "skin_profile": {"skin_type": "Da Dau!"}}, "skin_profile"),
])
def test_parse_request_rejects(payload, field):
    with pytest.raises(CartValidationError) as exc:
        parse_request(payload)
    assert exc.value.field == field


# -- conflicts
def test_pairs_are_grouped_and_highest_severity_wins():
    rows = [row("1", "2", severity="low", rule="low_rule"),
            row("1", "2", "retinol", "glycolic_acid", "Retinol", "Glycolic Acid", severity="high", rule="retinoid_aha"),
            row("1", "3", severity="medium", rule="m")]
    out = conflicts_from_rows(rows, CATS, RULES)
    assert [(c["a"], c["b"], c["severity"], c["rule_id"]) for c in out] == [("1", "2", "high", "retinoid_aha"),
                                                                           ("1", "3", "medium", "m")]
    assert out[0]["ingredients"][:2] == ["Retinol", "Glycolic Acid"] and out[0]["advice_code"] == "retinoid_aha"
    assert len(out[0]["ingredients"]) <= 4


def test_rank_limits_ignored_categories_and_unknown_severity():
    assert conflicts_from_rows([row("1", "2", "retinol", "lactic_acid", r2=16)], CATS, RULES) == []
    assert conflicts_from_rows([row("1", "2")], {**CATS, "2": ["duong_toc", "cham_soc_toc_va_da_dau"]}, RULES) == []
    out = conflicts_from_rows([row("1", "2", severity="same_session")], CATS, RULES)   # edge from an old ETL
    assert out[0]["severity"] == "low"


def test_rinse_off_products_lower_the_severity():
    assert adjust_severity("high", ["sua_rua_mat"], ["serum"], RULES) == "medium"
    assert adjust_severity("low", ["sua_rua_mat"], ["serum"], RULES) is None
    assert adjust_severity("high", ["serum"], ["kem_duong"], RULES) == "high"
    out = conflicts_from_rows([row("1", "4")], CATS, RULES)
    assert out[0]["severity"] == "medium"
    assert conflicts_from_rows([row("1", "4", severity="low")], CATS, RULES) == []


def test_python_twin_matches_the_cypher_path_semantics():
    ings = {"1": [("retinol", 8)], "2": [("salicylic_acid", 4), ("water", 1)], "3": [("niacinamide", 2)]}
    out = conflicts_from_ingredients(ings, CATS, RULES, {"retinol": "Retinol", "salicylic_acid": "Salicylic Acid"})
    assert [(c["a"], c["b"], c["severity"]) for c in out] == [("1", "2", "high")]
    assert out[0]["ingredients"] == ["Retinol", "Salicylic Acid"]


def test_legacy_text_rules_last_resort():
    texts = {"1": "Water, Retinol, Glycerin", "2": "Water, Salicylic Acid", "3": "Water, Niacinamide"}
    out = conflicts_from_legacy_text(texts, CATS, RULES)
    assert [(c["a"], c["b"], c["severity"]) for c in out] == [("1", "2", "high")]


# -- routine
def test_routine_has_and_missing_in_canonical_order():
    out = analyze_routine(ROUTINE, {"4": CATS["4"], "1": CATS["1"]})
    assert out["applicable"] and out["has"] == ["sua_rua_mat", "serum"]
    assert out["missing"] == ["toner", "kem_duong", "kem_chong_nang"]
    assert out["suggest_order"] == ["kem_duong", "kem_chong_nang", "toner"]       # core first


def test_routine_not_applicable_without_face_care_and_optional_step_never_missing():
    assert analyze_routine(ROUTINE, {"9": ["dau_goi", "dau_goi_va_dau_xa", "cham_soc_toc_va_da_dau"]}) == {
        "applicable": False, "has": [], "missing": [], "suggest_order": []}
    full = {"a": CATS["4"], "b": CATS["2"], "c": CATS["1"], "d": CATS["3"], "e": ["kem_chong_nang", "cham_soc_da_mat"]}
    out = analyze_routine(ROUTINE, full)
    assert out["missing"] == [] and "tay_trang" not in out["missing"]


# -- suggestions
def cand(pid, sold=10, skin=False, benefits=("cap_am",), ings=(), brand="b"):
    return {"id": pid, "sold": sold, "skin_fit": skin, "benefits": list(benefits), "brand": brand,
            "conflict_ings": [{"key": k, "rank": r} for k, r in ings]}


def test_suggestions_drop_conflicting_products_and_explain():
    cart = {"1": {"benefits": ["cap_am"], "conflict_ings": [{"key": "retinol", "rank": 6}]}}
    rows = [cand("c1", 100, ings=[("glycolic_acid", 3)]), cand("c2", 50, skin=True), cand("c3", 80),
            cand("c4", 90, ings=[("lactic_acid", 18)])]       # lactic acid far down = pH adjuster, allowed
    out = rank_step_candidates(rows, {"cap_am"}, cart, CATS, ["kem_chong_nang"], "Kem chống nắng", "da_dau", RULES)
    ids = [i["product_id"] for i in out]
    assert "c1" not in ids and set(ids) == {"c2", "c3", "c4"}
    by_id = {i["product_id"]: i for i in out}
    assert by_id["c2"]["reason_code"] == "fits_skin_type" and by_id["c2"]["reason_params"] == ["da_dau"]
    assert by_id["c3"]["reason_code"] == "completes_routine" and by_id["c3"]["reason_params"] == ["Kem chống nắng"]
    assert all(set(i) == {"product_id", "score", "reason_code", "reason_params"} for i in out)


def test_suggestions_cap_four_and_two_per_brand():
    rows = [cand(f"p{i}", 100 - i, brand="same") for i in range(3)] + [cand(f"q{i}", 10 - i, brand=f"b{i}") for i in range(4)]
    out = rank_step_candidates(rows, set(), {}, CATS, ["toner"], "Toner", None, RULES)
    assert len(out) == 4
    assert sum(1 for i in out if i["product_id"].startswith("p")) == 2


# -- service
class FakeGraph:
    def __init__(self, conflict_rows=None, error=None, candidates=None, profile=None):
        self.conflict_rows, self.error, self.candidates = conflict_rows or [], error, candidates or {}
        self.profile = profile or {}
        self.calls = []

    def cart_conflicts(self, ids):
        self.calls.append("conflicts")
        if self.error:
            raise self.error
        return self.conflict_rows

    def cart_categories(self, ids):
        return {}

    def cart_profile(self, ids, keys):
        self.calls.append("profile")
        if self.error:
            raise self.error
        return self.profile

    def routine_candidates(self, categories, cart_ids, skin_type, keys, limit):
        self.calls.append(("cand", tuple(categories)))
        return self.candidates.get(categories[0], [])


class FakeCatalog:
    def __init__(self, cats):
        self.cats = cats

    def get(self, pid):
        return {"category_keys": self.cats[pid]} if pid in self.cats else None


class DictCache:
    def __init__(self):
        self.data, self.ttls = {}, []

    def get(self, digest):
        return self.data.get(digest)

    def set(self, digest, value, ttl):
        self.data[digest] = value
        self.ttls.append(ttl)


def test_service_full_answer_matches_contract_and_is_cached():
    graph = FakeGraph([row("1", "2")], candidates={"kem_chong_nang": [cand("90", 5)], "kem_duong": [cand("91", 9)]},
                      profile={"1": {"benefits": ["cap_am"], "conflict_ings": []}})
    cache = DictCache()
    svc = CartAnalyzeService(graph, FakeCatalog({"1": CATS["1"], "2": CATS["4"]}), cache)
    body = svc.analyze(CartRequest(("1", "2"), "da_dau"))
    jsonschema.validate(body, SCHEMA)
    assert body["degraded"] is False
    assert [c["severity"] for c in body["conflicts"]] == ["medium"]      # "2" is a cleanser: wash-off, high -> medium
    assert body["routine"]["has"] == ["sua_rua_mat", "serum"]
    assert body["routine"]["order"] == ["tay_trang", "sua_rua_mat", "toner", "serum", "kem_duong", "kem_chong_nang"]
    assert {s["step"] for s in body["suggestions"]} == {"kem_duong", "kem_chong_nang"}
    assert cache.ttls == [config.CART_CACHE_TTL]
    graph.calls.clear()
    again = svc.analyze(CartRequest(("2", "1"), "da_dau"))      # order does not matter for the cache key
    assert graph.calls == [] and again["conflicts"] == body["conflicts"]


def test_service_neo4j_down_falls_back_to_python_rules_without_suggestions():
    texts = {"1": {"full": "Water, Retinol, Glycerin", "sach": ""},
             "2": {"full": "Water, Glycerin, Salicylic Acid", "sach": ""}}
    svc = CartAnalyzeService(FakeGraph(error=RuntimeError("neo4j_down")), FakeCatalog({"1": CATS["1"], "2": CATS["2"]}),
                             DictCache(), text_loader=lambda ids: texts)
    body = svc.analyze(CartRequest(("1", "2")))
    jsonschema.validate(body, SCHEMA)
    assert body["degraded"] is True and body["suggestions"] == []
    assert [(c["a"], c["b"], c["severity"]) for c in body["conflicts"]] == [("1", "2", "high")]
    assert body["routine"]["missing"] == ["sua_rua_mat", "kem_duong", "kem_chong_nang"]
    assert svc.cache.data == {}                                  # degraded answers are not cached


def test_service_survives_loader_errors_and_empty_cart():
    def boom(ids):
        raise RuntimeError("mongo")

    svc = CartAnalyzeService(FakeGraph(error=RuntimeError("down")), FakeCatalog({"1": CATS["1"], "2": CATS["2"]}),
                             None, text_loader=boom)
    assert svc.analyze(CartRequest(("1", "2")))["conflicts"] == []
    empty = CartAnalyzeService(FakeGraph(), FakeCatalog({}), None).analyze(CartRequest(()))
    jsonschema.validate(empty, SCHEMA)
    assert empty["conflicts"] == [] and empty["routine"]["missing"] == []


def test_service_without_graph_is_degraded_but_answers_routine():
    svc = CartAnalyzeService(None, FakeCatalog({"4": CATS["4"]}), None, text_loader=lambda ids: {})
    body = svc.analyze(CartRequest(("4",)))
    assert body["degraded"] is True and body["routine"]["has"] == ["sua_rua_mat"]


# -- route
TOKEN = "unit-test-token"


class FakeService:
    def analyze(self, req, correlation_id=""):
        return {"conflicts": [], "routine": {"has": [], "missing": []}, "suggestions": [], "degraded": False,
                "took_ms": 1, "echo": list(req.product_ids)}


@pytest.fixture
def client(monkeypatch):
    monkeypatch.setattr(routes, "_warm_pid", os.getpid())
    monkeypatch.setattr("search_service.service.get_cart_service", lambda: FakeService())
    monkeypatch.setattr(config, "INTERNAL_TOKEN", TOKEN)
    app = Flask(__name__)
    app.register_blueprint(routes.search_bp)
    return app.test_client()


def test_route_auth_validation_and_disabled(client, monkeypatch):
    h = {"X-Internal-Token": TOKEN}
    assert client.post("/api/cart/analyze", json={"product_ids": ["1"]}).status_code == 401
    r = client.post("/api/cart/analyze", json={"product_ids": ["1", "2"]}, headers=h)
    assert r.status_code == 200 and r.get_json()["echo"] == ["1", "2"] and r.headers["X-Request-Id"]
    r = client.post("/api/cart/analyze", json={"product_ids": "x"}, headers=h)
    assert r.status_code == 400 and r.get_json() == {"error": "invalid_request", "field": "product_ids"}
    assert client.post("/api/cart/analyze", data="nope", headers=h).status_code == 400
    monkeypatch.setattr(config, "SEARCH_CART_ENABLED", False)
    assert client.post("/api/cart/analyze", json={"product_ids": ["1"]}, headers=h).status_code == 404


def test_route_never_500s(client, monkeypatch):
    class Boom:
        def analyze(self, *a, **k):
            raise RuntimeError("secret internals")

    monkeypatch.setattr("search_service.service.get_cart_service", lambda: Boom())
    r = client.post("/api/cart/analyze", json={"product_ids": ["1"]}, headers={"X-Internal-Token": TOKEN})
    assert r.status_code == 200 and r.get_json()["degraded"] is True and "secret" not in r.get_data(as_text=True)


def test_cache_digest_ignores_order_but_not_skin():
    a = cart_analyze.cache_digest(CartRequest(("1", "2"), None))
    assert a == cart_analyze.cache_digest(CartRequest(("2", "1"), None))
    assert a != cart_analyze.cache_digest(CartRequest(("1", "2"), "da_dau"))
