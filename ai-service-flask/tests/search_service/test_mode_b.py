"""Mode B (Bước 05B): graph branches, semantic memo, intent wiring, Neo4j breaker. No Neo4j needed."""

from __future__ import annotations

import threading
import time
from types import SimpleNamespace

import pytest

from search_service import branches, neo4j_client, service
from search_service.catalog import build_product, category_key_map
from search_service.dictionary import EntityDictionary
from search_service.schemas import validate_request
from search_service.service import LazyGraph, SearchRecommendService

REQ = validate_request({"q": "torriden sữa rửa mặt"})
INTENT = {"brands": ["torriden"], "categories": ["sua_rua_mat"], "ingredients": [], "skin_types": [],
          "concerns": [], "price": None, "confidence": 1.0}


class FakeGraph:
    def __init__(self, alt_rows=None, similar_rows=None, personalized_rows=None):
        self.calls = []
        self.alt_rows = alt_rows or []
        self.similar_rows = similar_rows or []
        self.personalized_rows = personalized_rows or []

    def same_brand(self, brand, categories, price=None):
        self.calls.append(("same_brand", brand, tuple(categories), price))
        return [{"id": "1496", "near": 2, "sold": 1}, {"id": "1085", "near": 1, "sold": 0},
                {"id": "462", "near": 0, "sold": 8}]

    def category_alt(self, category, brand, ingredients=None, price=None):
        self.calls.append(("category_alt", category, brand, tuple(ingredients or ()), price))
        return self.alt_rows

    def similar_to(self, seed_ids, exclude_brand=None, price=None):
        self.calls.append(("similar_to", tuple(seed_ids), exclude_brand, price))
        return self.similar_rows

    def personalized(self, skin_type, concerns, categories, cart_ids, price=None):
        self.calls.append(("personalized", skin_type, tuple(concerns), tuple(categories), tuple(cart_ids)))
        return self.personalized_rows

    def skin_type_name(self, key):
        return {"da_dau": "Da dầu"}.get(key)


def _hit(pid, score=0.1, **product):
    return SimpleNamespace(product={"id": pid, **product}, rrf_score=score)


class FakeMemo:
    def __init__(self, hits):
        self._hits = hits
        self.calls = 0

    def hits(self, q):
        self.calls += 1
        return self._hits


# ── same_brand ──
def test_same_brand_orders_by_near_then_sold_and_passes_price():
    g = FakeGraph()
    intent = dict(INTENT, price={"min_vnd": None, "max_vnd": 400000})
    out = branches.same_brand(REQ, intent, g)
    assert [c.product_id for c in out] == ["1496", "1085", "462"]
    assert out[0].raw_score > out[1].raw_score > out[2].raw_score
    assert all(c.reason_code == "same_brand" and c.branch == "same_brand" for c in out)
    assert g.calls[0] == ("same_brand", "torriden", ("sua_rua_mat",), {"min_vnd": None, "max_vnd": 400000})


def test_same_brand_without_brand_never_touches_graph():
    g = FakeGraph()
    assert branches.same_brand(REQ, dict(INTENT, brands=[]), g) == []
    assert branches.same_brand(REQ, None, g) == []
    assert g.calls == []


# ── category_alt ──
def test_category_alt_uses_leaf_category_and_shared_ingredient_reason():
    rows = [
        {"id": "587", "shared_names": ["Sodium Hyaluronate", "Panthenol"], "sold": 9, "brand": "hada_labo"},
        {"id": "121", "shared_names": [], "sold": 87, "brand": "cerave"},
    ]
    g = FakeGraph(alt_rows=rows)
    out = branches.category_alt(REQ, INTENT, g)
    assert g.calls[0][:3] == ("category_alt", "sua_rua_mat", "torriden")
    assert out[0].reason_code == "shared_ingredients" and out[0].reason_params == ("Sodium Hyaluronate", "Panthenol")
    assert out[1].reason_code == "same_category" and out[1].reason_params == ()
    assert out[0].raw_score > out[1].raw_score


def test_category_alt_diversifies_brands_max_two_first():
    rows = [{"id": str(i), "shared_names": ["X"], "sold": 0, "brand": "hada_labo"} for i in range(4)]
    rows.append({"id": "99", "shared_names": ["X"], "sold": 0, "brand": "cerave"})
    out = branches.category_alt(REQ, INTENT, FakeGraph(alt_rows=rows))
    assert [c.product_id for c in out] == ["0", "1", "99", "2", "3"]


def test_category_alt_without_category_uses_semantic_seeds_when_confident():
    memo = FakeMemo([_hit("1496"), _hit("1085"), _hit("1791"), _hit("9")])
    g = FakeGraph(similar_rows=[{"id": "587", "score": 0.6, "shared_names": ["Panthenol"], "brand": "x"}])
    intent = dict(INTENT, categories=[], confidence=1.0)
    out = branches.category_alt(REQ, intent, g, memo)
    assert g.calls == [("similar_to", ("1496", "1085", "1791"), "torriden", None)]
    assert [(c.product_id, c.reason_code) for c in out] == [("587", "shared_ingredients")]


def test_category_alt_without_category_skips_low_confidence_queries():
    memo = FakeMemo([_hit("1")])
    g = FakeGraph()
    assert branches.category_alt(REQ, dict(INTENT, categories=[], confidence=0.0), g, memo) == []
    assert memo.calls == 0 and g.calls == []


# ── personalized ──
def test_personalized_from_intent_skin_type_with_label():
    g = FakeGraph(personalized_rows=[{"id": "266", "concern_hits": 0, "concern_names": [], "sold": 229},
                                     {"id": "1000", "concern_hits": 1, "concern_names": ["Mụn"], "sold": 229}])
    intent = dict(INTENT, brands=[], skin_types=["da_dau"], concerns=["mun"])
    req = validate_request({"q": "srm cho da dầu", "cart_ids": ["1496"]})
    out = branches.personalized(req, intent, g)
    assert g.calls[0] == ("personalized", "da_dau", ("mun",), ("sua_rua_mat",), ("1496",))
    by_id = {c.product_id: c for c in out}
    assert by_id["266"].reason_code == "fits_skin_type" and by_id["266"].reason_params == ("da dầu",)
    assert by_id["1000"].reason_code == "targets_concern" and by_id["1000"].reason_params == ("mụn",)


def test_personalized_skin_profile_wins_and_no_profile_means_no_query():
    g = FakeGraph()
    req = validate_request({"q": "x", "skin_profile": {"skin_type": "da_kho", "concerns": []}})
    branches.personalized(req, dict(INTENT, skin_types=["da_dau"]), g)
    assert g.calls[0][1] == "da_kho"
    g2 = FakeGraph()
    assert branches.personalized(REQ, INTENT, g2) == [] and g2.calls == []


# ── semantic memo / price ──
class FakeHybrid:
    def __init__(self, delay=0.0):
        self.calls = 0
        self.delay = delay
        self.lock = threading.Lock()

    def search(self, q, limit=30):
        with self.lock:
            self.calls += 1
        time.sleep(self.delay)
        return [_hit("1", 0.5, price=500000, category_keys=["serum", "dac_tri"]),
                _hit("2", 0.4, price=300000, category_keys=["toner"]),
                _hit("3", 0.3, price=None)]


def test_semantic_memo_shares_one_inflight_search():
    hybrid = FakeHybrid(delay=0.1)
    memo = branches.SemanticMemo(hybrid, None, ttl=30)
    results = []
    threads = [threading.Thread(target=lambda: results.append(memo.hits("q"))) for _ in range(4)]
    [t.start() for t in threads]
    [t.join() for t in threads]
    assert hybrid.calls == 1 and len(results) == 4
    memo.hits("q")
    assert hybrid.calls == 1  # cached


def test_semantic_price_filter_and_category_boost():
    memo = branches.SemanticMemo(FakeHybrid(), None, ttl=0)
    intent = {"categories": ["serum"], "price": {"min_vnd": None, "max_vnd": 400000}}
    out = branches.semantic(REQ, intent, None, None, memo)
    assert [c.product_id for c in out] == ["2", "3"]  # 1 is above max price; unknown price kept
    boosted = branches.semantic(REQ, {"categories": ["serum"]}, None, None, memo)
    assert boosted[0].raw_score == pytest.approx(0.5 * 1.2)


# ── catalog category keys ──
def test_category_key_map_builds_leaf_and_parent_keys():
    rows = [{"ma_danh_muc": 1, "ten_danh_muc": "Sữa Rửa Mặt", "parent_id": 10},
            {"ma_danh_muc": 10, "ten_danh_muc": "Làm Sạch Da", "parent_id": 100},
            {"ma_danh_muc": 100, "ten_danh_muc": "Chăm Sóc Da Mặt", "parent_id": None}]
    keys = category_key_map(rows)
    assert keys["1"] == ["sua_rua_mat", "lam_sach_da", "cham_soc_da_mat"]
    product = build_product({"ma_san_pham": 1496, "ma_danh_muc": 1, "gia_ban": "250000"}, {}, {"1": "Sữa Rửa Mặt"}, keys)
    assert product["price"] == 250000 and product["category_keys"][0] == "sua_rua_mat"


# ── dictionary display names / intent wiring ──
def test_dictionary_display_names_prefer_graph_names():
    class Driver:
        def execute_query(self, query, *a, **k):
            if ":Brand" in query:
                return [{"key": "torriden", "name": "Torriden"}], None, []
            if "Category" in query:
                return [{"label": "Category", "key": "toner", "name": "Toner / Nước Cân Bằng Da"}], None, []
            return [], None, []

    snap = EntityDictionary(driver=Driver(), mongo_db=None).snapshot()
    assert snap.display_name("brand", "torriden") == "Torriden"
    assert snap.display_name("category", "toner") == "Toner / Nước Cân Bằng Da"
    assert snap.display_name("category", "unknown_key") == "unknown_key"


def test_parse_intent_adds_display_names(monkeypatch):
    from search_service import dictionary

    monkeypatch.setattr(dictionary, "get_default_dictionary", lambda: EntityDictionary(driver=None, mongo_db=None))
    intent = service.parse_intent("sữa rửa mặt dưới 300k")
    assert intent["categories"] == ["sua_rua_mat"]
    assert intent["category_names"] and intent["category_names"][0] != ""
    assert intent["price"] == {"min_vnd": None, "max_vnd": 300000}
    assert {"brands", "categories", "skin_types", "concerns", "confidence"} <= set(intent)


def test_snapshot_nowait_reloads_in_background():
    clock = {"t": 0.0}
    d = EntityDictionary(driver=None, mongo_db=None, ttl_seconds=60, clock=lambda: clock["t"])
    first = d.snapshot_nowait()
    clock["t"] = 100.0
    assert d.snapshot_nowait() is first  # stale served immediately
    for _ in range(50):
        if d._snap is not first:
            break
        time.sleep(0.01)
    assert d._snap is not first


# ── LazyGraph / breaker ──
def test_lazy_graph_raises_when_neo4j_down(monkeypatch):
    monkeypatch.setattr(neo4j_client, "get_driver", lambda: None)
    with pytest.raises(RuntimeError, match="neo4j_down"):
        LazyGraph().same_brand("torriden", [])
    # A branch with nothing to ask the graph stays healthy even when Neo4j is down.
    assert branches.same_brand(REQ, dict(INTENT, brands=[]), LazyGraph()) == []


def test_breaker_opens_on_connectivity_error_and_blocks_driver(monkeypatch):
    from neo4j.exceptions import ClientError, ServiceUnavailable

    probes = []
    monkeypatch.setattr(neo4j_client, "_start_probe", lambda: probes.append(1))
    monkeypatch.setattr(neo4j_client.config, "SEARCH_GRAPH_ENABLED", True)
    monkeypatch.setattr(neo4j_client.config, "NEO4J_URI", "bolt://example:7687")
    neo4j_client.reset()
    clock = {"t": 1000.0}
    monkeypatch.setattr(neo4j_client, "_now", lambda: clock["t"])
    monkeypatch.setattr(neo4j_client, "_driver", object())
    neo4j_client.report_failure(ClientError("syntax"))  # Cypher error, not connectivity: stays closed
    assert not neo4j_client.is_open() and neo4j_client.get_driver() is not None and probes == []
    neo4j_client.report_failure(ServiceUnavailable("down"))
    assert neo4j_client.is_open() and neo4j_client.get_driver() is None and probes == [1]
    clock["t"] += neo4j_client.RETRY_SECONDS + 1
    neo4j_client.report_failure(ValueError("Cannot resolve address neo4j:7687"))  # docker DNS failure
    assert neo4j_client.is_open()
    clock["t"] += neo4j_client.RETRY_SECONDS + 1
    assert neo4j_client.get_driver() is not None
    neo4j_client.reset()


def test_service_degrades_graph_branches_but_keeps_semantic_and_trending():
    from search_service.ranker import Candidate

    def down(req, intent):
        raise RuntimeError("neo4j_down")

    svc = SearchRecommendService(
        branches={
            "semantic": lambda r, i: [Candidate("1", 0.9, "semantic_match", "semantic")],
            "trending": lambda r, i: [Candidate("2", 10, "trending", "trending")],
            "same_brand": down, "category_alt": down, "personalized": lambda r, i: [],
        },
        intent_parser=lambda q: dict(INTENT),
    )
    out = svc.recommend(REQ)
    assert out["degraded"] is True and out["degraded_branches"] == ["category_alt", "same_brand"]
    assert [s["key"] for s in out["sections"]] == ["semantic", "trending"]
    assert out["intent"]["brands"] == ["torriden"]


def test_probe_closes_breaker_when_neo4j_is_back(monkeypatch):
    class Driver:
        def __init__(self):
            self.calls = 0

        def verify_connectivity(self):
            self.calls += 1
            if self.calls < 2:
                raise ValueError("Cannot resolve address")

    driver = Driver()
    monkeypatch.setattr(neo4j_client.time, "sleep", lambda s: None)
    monkeypatch.setattr(neo4j_client, "_driver", driver)
    monkeypatch.setattr(neo4j_client, "_down_until", neo4j_client._now() + 100)
    neo4j_client._probe_loop()
    assert driver.calls == 2 and not neo4j_client.is_open() and neo4j_client._probe_running is False


def test_lazy_graph_opens_breaker_when_a_call_hangs(monkeypatch):
    reported = []
    monkeypatch.setattr(neo4j_client, "report_failure", lambda exc=None: reported.append(exc))
    monkeypatch.setattr(neo4j_client, "get_driver", lambda: None)
    token = object()
    service._inflight[token] = time.monotonic() - 10
    try:
        with pytest.raises(RuntimeError):
            LazyGraph().same_brand("torriden", [])
    finally:
        service._inflight.pop(token, None)
    assert reported == [None]
