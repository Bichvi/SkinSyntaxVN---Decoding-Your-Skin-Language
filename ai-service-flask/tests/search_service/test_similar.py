"""U08: GET /api/products/<id>/similar - parsing, rerank, diversification, fallback, route, contract."""

import json
from pathlib import Path

import pytest

from search_service import config, similar
from search_service.similar import (
    SimilarProductsService, SimilarRequest, SimilarValidationError, diversify, finalize, parse_args,
    rank_candidates, semantic_rows,
)

jsonschema = pytest.importorskip("jsonschema")
SCHEMA = json.loads(
    (Path(__file__).resolve().parents[2] / "search_service" / "contract" / "similar_response.schema.json")
    .read_text(encoding="utf-8")
)
LOCAL = {"REMOTE_ADDR": "127.0.0.1"}


def row(pid, score=0.5, reason="shared_ingredients", shared=("Niacinamide",), same_leaf=False,
        in_stock=True, sold=0, brand="b", skin_fit=False):
    return {"id": pid, "score": score, "reason": reason, "shared": list(shared), "same_leaf": same_leaf,
            "in_stock": in_stock, "sold": sold, "brand": brand, "skin_fit": skin_fit}


# -- parse_args --
def test_parse_args_defaults_and_values():
    assert parse_args("1496") == SimilarRequest("1496", 8, (), None)
    req = parse_args("1496", "5", "1496, 22,22,abc_1", "Da_Dau")
    assert (req.limit, req.exclude, req.skin_type) == (5, ("1496", "22", "abc_1"), "da_dau")


@pytest.mark.parametrize("args, field", [
    (("../x",), "product_id"), (("",), "product_id"), (("a" * 33,), "product_id"), (("1 2",), "product_id"),
    (("1", "0"), "limit"), (("1", "21"), "limit"), (("1", "x"), "limit"),
    (("1", None, "1,bad id"), "exclude"), (("1", None, ",".join(str(i) for i in range(51))), "exclude"),
    (("1", None, None, "Da Dau!"), "skin_type"),
])
def test_parse_args_rejects(args, field):
    with pytest.raises(SimilarValidationError) as exc:
        parse_args(*args)
    assert exc.value.field == field


# -- rank_candidates --
def test_same_leaf_boost_reorders_close_scores():
    ranked = rank_candidates([row("a", 0.60), row("b", 0.50, same_leaf=True)])
    assert [c["product_id"] for c in ranked] == ["b", "a"]          # 0.50 + 0.15 > 0.60


def test_out_of_stock_penalised_and_skin_boost():
    ranked = rank_candidates([row("oos", 0.9, in_stock=False), row("ok", 0.6), row("skin", 0.55, skin_fit=True)])
    assert [c["product_id"] for c in ranked] == ["skin", "ok", "oos"]
    assert ranked[-1]["score"] == pytest.approx(0.4)


def test_sold_is_log_normalised_small_tiebreak():
    ranked = rank_candidates([row("low", 0.5, sold=1), row("high", 0.5, sold=1000)])
    assert ranked[0]["product_id"] == "high"
    assert ranked[0]["score"] - ranked[1]["score"] <= config.W_SIMILAR_SOLD + 1e-9


def test_duplicate_rows_keep_best_score_and_flags():
    ranked = rank_candidates([row("a", 0.3), row("a", 0.7, same_leaf=True), row("a", 0.4, skin_fit=True)])
    assert len(ranked) == 1 and ranked[0]["score"] == pytest.approx(0.7 + 0.15 + 0.1)


def test_reason_mapping():
    ranked = {c["product_id"]: c for c in rank_candidates([
        row("a", shared=("A", "B", "C", "D")),
        row("b", reason="shared_ingredients", shared=()),
        row("c", reason="same_brand", shared=("X",)),
        row("d", reason="weird"),
        row("e", reason="same_category"),
    ])}
    assert ranked["a"]["reason_code"] == "shared_ingredients" and ranked["a"]["reason_params"] == ["A", "B", "C"]
    assert ranked["b"]["reason_code"] == "same_category" and ranked["b"]["reason_params"] == []
    assert ranked["c"]["reason_code"] == "same_brand" and ranked["c"]["reason_params"] == []
    assert ranked["d"]["reason_code"] == "same_category"
    assert ranked["e"]["reason_code"] == "same_category"


# -- diversify / finalize --
def test_diversify_caps_three_per_brand():
    items = [{"product_id": str(i), "brand": "x"} for i in range(5)] + [{"product_id": "y1", "brand": "y"}]
    out = diversify(items, cap=3, min_keep=2)
    assert [i["product_id"] for i in out] == ["0", "1", "2", "y1"]


def test_diversify_relaxes_when_nearly_empty():
    items = [{"product_id": str(i), "brand": "x"} for i in range(6)]
    assert len(diversify(items, cap=3, min_keep=4)) == 4


def test_diversify_unknown_brand_uncapped():
    items = [{"product_id": str(i), "brand": ""} for i in range(6)]
    assert len(diversify(items, cap=3, min_keep=1)) == 6


def test_finalize_excludes_limits_and_hides_internal_fields():
    cands = rank_candidates([row(str(i), 1.0 - i / 100, brand=f"b{i}") for i in range(12)])
    out = finalize(cands, ["0", "1"], 5)
    assert [i["product_id"] for i in out] == ["2", "3", "4", "5", "6"]
    assert set(out[0]) == {"product_id", "score", "reason_code", "reason_params"}


# -- service --
class FakeGraph:
    def __init__(self, rows=None, error=None):
        self.rows, self.error, self.calls = rows or [], error, []

    def similar_products(self, product_id, skin_type="", limit=40):
        self.calls.append((product_id, skin_type, limit))
        if self.error:
            raise self.error
        return self.rows


class DictCache:
    def __init__(self):
        self.data, self.ttls = {}, []

    def get(self, req):
        return self.data.get((req.product_id, req.skin_type))

    def set(self, req, value, ttl):
        self.data[(req.product_id, req.skin_type)] = value
        self.ttls.append(ttl)


class FakeCatalog:
    def __init__(self, products):
        self.products = products

    def get(self, pid):
        return self.products.get(pid)


class FakeCollection:
    def __init__(self, vectors, neighbours):
        self.vectors, self.neighbours = vectors, neighbours

    def get(self, ids, include):
        vec = self.vectors.get(ids[0])
        return {"ids": ids if vec else [], "embeddings": [vec] if vec else []}

    def query(self, query_embeddings, n_results, include):
        return {"ids": [[n[0] for n in self.neighbours]], "distances": [[n[1] for n in self.neighbours]]}


class FakeStore:
    def __init__(self, collection):
        self._collection = collection


class FakeVectorstore:
    def __init__(self, collection=None):
        self.store = FakeStore(collection) if collection else None

    def get(self):
        return self.store


def cat(pid, leaf, brand="B", sold=1, in_stock=True):
    return {"id": pid, "category_keys": [leaf, "parent"], "thuong_hieu": brand, "sold": sold, "in_stock": in_stock}


def test_service_graph_path_matches_contract_and_caches():
    graph = FakeGraph([row("2", 0.7, same_leaf=True, brand="a"), row("3", 0.6, brand="b"), row("2", 0.7, brand="a")])
    cache = DictCache()
    svc = SimilarProductsService(graph, None, None, cache)
    body = svc.similar(SimilarRequest("1", 8, ("3",)))
    jsonschema.validate(body, SCHEMA)
    assert body["degraded"] is False and body["source"] == "graph"
    assert [i["product_id"] for i in body["items"]] == ["2"]
    again = svc.similar(SimilarRequest("1", 8, ()))
    assert [i["product_id"] for i in again["items"]] == ["2", "3"]
    assert len(graph.calls) == 1                          # second request served from cache (exclude applied after)
    assert cache.ttls == [config.SIMILAR_CACHE_TTL]


def test_service_never_returns_the_product_itself():
    svc = SimilarProductsService(FakeGraph([row("1", 0.9), row("2", 0.5)]), None, None)
    assert [i["product_id"] for i in svc.similar(SimilarRequest("1"))["items"]] == ["2"]


def test_service_passes_skin_type_to_graph():
    graph = FakeGraph([row("2")])
    SimilarProductsService(graph, None, None).similar(SimilarRequest("1", 8, (), "da_dau"))
    assert graph.calls == [("1", "da_dau", config.SIMILAR_CANDIDATES)]


def test_neo4j_error_falls_back_to_semantic_same_leaf():
    catalog = FakeCatalog({"1": cat("1", "sua_rua_mat"), "2": cat("2", "sua_rua_mat", "X"), "3": cat("3", "toner"),
                           "4": cat("4", "sua_rua_mat", "Y"), "7": cat("7", "sua_rua_mat", "Z")})
    coll = FakeCollection({"1": [0.1, 0.2]}, [("1", 0.0), ("2", 0.2), ("3", 0.3), ("4", 0.5), ("9", 0.6)])
    cache = DictCache()
    svc = SimilarProductsService(FakeGraph(error=RuntimeError("neo4j_down")), catalog, FakeVectorstore(coll), cache)
    body = svc.similar(SimilarRequest("1", 8))
    jsonschema.validate(body, SCHEMA)
    assert body["degraded"] is True and body["source"] == "semantic"
    assert [i["product_id"] for i in body["items"]] == ["2", "4"]       # 3 = other leaf, 9 = hidden, 1 = itself
    assert all(i["reason_code"] == "same_category" for i in body["items"])
    assert cache.ttls == [similar.FALLBACK_CACHE_TTL]


def test_no_graph_edges_uses_semantic_and_graph_disabled_too():
    catalog = FakeCatalog({"1": cat("1", "leaf"), "2": cat("2", "leaf")})
    coll = FakeCollection({"1": [1.0]}, [("2", 0.1)])
    for graph in (FakeGraph([]), None):
        body = SimilarProductsService(graph, catalog, FakeVectorstore(coll)).similar(SimilarRequest("1"))
        assert body["degraded"] is True and [i["product_id"] for i in body["items"]] == ["2"]


def test_everything_down_returns_empty_degraded_and_is_not_cached():
    cache = DictCache()
    svc = SimilarProductsService(FakeGraph(error=RuntimeError("x")), FakeCatalog({}), FakeVectorstore(None), cache)
    body = svc.similar(SimilarRequest("1"))
    jsonschema.validate(body, SCHEMA)
    assert body["items"] == [] and body["degraded"] is True and cache.data == {}


def test_semantic_rows_product_without_embedding_is_empty():
    assert semantic_rows("9", FakeCatalog({}), FakeVectorstore(FakeCollection({}, []))) == []


def test_semantic_rows_raise_when_chroma_down():
    with pytest.raises(RuntimeError):
        semantic_rows("1", FakeCatalog({}), FakeVectorstore(None))


# -- Redis cache (fake client) --
def test_redis_cache_roundtrip_and_generation():
    fakeredis = pytest.importorskip("fakeredis")
    client = fakeredis.FakeRedis(decode_responses=True)
    cache = similar.RedisSimilarCache(lambda: client)
    req = SimilarRequest("1496", 8, (), "da_dau")
    assert cache.get(req) is None
    cache.set(req, {"degraded": False, "source": "graph", "candidates": []}, 60)
    assert cache.get(req)["source"] == "graph"
    assert any(k.startswith("product:similar:v1:g0:1496:da_dau") for k in client.keys("*"))
    client.incr("search:cachegen:v1")                       # publish_invalidate orphans the entry
    assert cache.get(req) is None


# -- route --
@pytest.fixture
def client(monkeypatch):
    from flask import Flask

    from search_service import routes

    app = Flask(__name__)
    app.register_blueprint(routes.search_bp)
    monkeypatch.setattr(routes, "_warm_pid", __import__("os").getpid())
    monkeypatch.setattr(routes.config, "INTERNAL_TOKEN", "")
    app.config["TESTING"] = True
    return app.test_client()


def test_route_validates_and_serves(client, monkeypatch):
    from search_service import service

    svc = SimilarProductsService(FakeGraph([row("2", 0.7, brand="a")]), None, None)
    monkeypatch.setattr(service, "get_similar_service", lambda: svc)
    ok = client.get("/api/products/1496/similar?limit=3&exclude=9&skin_type=da_dau", environ_overrides=LOCAL)
    assert ok.status_code == 200
    jsonschema.validate(ok.get_json(), SCHEMA)
    assert client.get("/api/products/1496/similar?limit=99", environ_overrides=LOCAL).status_code == 400
    assert client.get("/api/products/bad.id/similar", environ_overrides=LOCAL).status_code == 400
    assert client.get("/api/products/1496/similar", environ_overrides={"REMOTE_ADDR": "8.8.8.8"}).status_code == 401


def test_route_token_required_when_configured(client, monkeypatch):
    from search_service import routes

    monkeypatch.setattr(routes.config, "INTERNAL_TOKEN", "secret")
    assert client.get("/api/products/1/similar", environ_overrides=LOCAL).status_code == 401
    wrong = client.get("/api/products/1/similar", headers={"X-Internal-Token": "wrong"}, environ_overrides=LOCAL)
    assert wrong.status_code == 401


def test_route_survives_service_crash(client, monkeypatch):
    from search_service import service

    class Boom:
        def similar(self, *a, **k):
            raise RuntimeError("boom")

    monkeypatch.setattr(service, "get_similar_service", lambda: Boom())
    res = client.get("/api/products/1/similar", environ_overrides=LOCAL)
    assert res.status_code == 200 and res.get_json()["items"] == [] and res.get_json()["degraded"] is True


def test_cypher_is_parameterised_read_only_and_registered():
    from search_service import graph_repository as gr

    text = gr.SIMILAR_PRODUCTS
    assert "$id" in text and "$skin_type" in text and "$limit" in text
    assert gr.ALL_QUERIES["similar_products"] is text
    assert all(word not in text for word in ("CREATE", "MERGE", "SET ", "DELETE"))
