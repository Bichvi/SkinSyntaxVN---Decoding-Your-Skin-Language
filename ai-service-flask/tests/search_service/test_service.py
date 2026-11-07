import time

from search_service.branches import TrendingSource, trending
from search_service.ranker import Candidate
from search_service.schemas import validate_request
from search_service.service import RedisJsonCache, SearchRecommendService, cache_key


def sem(req, intent):
    return [Candidate("1", 0.9, "semantic_match", "semantic"), Candidate("2", 0.5, "semantic_match", "semantic")]


def trend(req, intent):
    return [Candidate("3", 40, "trending", "trending"), Candidate("1", 1, "trending", "trending")]


def boom(req, intent):
    raise RuntimeError("down")


def slow(req, intent):
    time.sleep(1.0)
    return [Candidate("9", 1, "same_brand", "same_brand")]


class MemCache:
    def __init__(self):
        self.store, self.sets = {}, 0

    def get(self, key):
        return self.store.get(key)

    def set(self, key, value):
        self.sets += 1
        self.store[key] = dict(value)


REQ = validate_request({"q": "srm cho da dầu"})


def test_happy_path_shape_and_dedupe():
    out = SearchRecommendService({"semantic": sem, "trending": trend}).recommend(REQ)
    assert out["mode"] == "fallback" and out["degraded"] is False and out["intent"] is None
    assert [s["key"] for s in out["sections"]] == ["semantic", "trending"]
    ids = [i["product_id"] for s in out["sections"] for i in s["items"]]
    assert sorted(ids) == ["1", "2", "3"]


def test_branch_exception_marks_degraded_others_survive():
    out = SearchRecommendService({"semantic": sem, "same_brand": boom, "trending": trend}).recommend(REQ)
    assert out["degraded"] is True and out["degraded_branches"] == ["same_brand"]
    assert {s["key"] for s in out["sections"]} == {"semantic", "trending"}


def test_branch_timeout_dropped():
    started = time.perf_counter()
    out = SearchRecommendService({"semantic": sem, "same_brand": slow}, timeout_ms=200).recommend(REQ)
    assert time.perf_counter() - started < 0.8
    assert out["degraded_branches"] == ["same_brand"]
    assert [s["key"] for s in out["sections"]] == ["semantic"]


def test_cache_hit_and_no_cache_when_degraded():
    cache = MemCache()
    calls = {"n": 0}

    def counting(req, intent):
        calls["n"] += 1
        return sem(req, intent)

    svc = SearchRecommendService({"semantic": counting}, cache=cache)
    svc.recommend(REQ)
    svc.recommend(REQ)
    assert calls["n"] == 1 and cache.sets == 1

    cache2 = MemCache()
    SearchRecommendService({"semantic": sem, "x": boom}, cache=cache2).recommend(REQ)
    assert cache2.sets == 0


def test_redis_errors_are_ignored():
    class Broken:
        def get(self, k):
            raise ConnectionError("redis down")

        def setex(self, *a):
            raise ConnectionError("redis down")

    cache = RedisJsonCache(redis_factory=lambda: Broken(), ttl=300)
    out = SearchRecommendService({"semantic": sem}, cache=cache).recommend(REQ)
    assert out["sections"][0]["key"] == "semantic"
    assert RedisJsonCache(redis_factory=lambda: None, ttl=300).get("k") is None


def test_exclude_ids_and_cache_key_normalization():
    req = validate_request({"q": "srm", "exclude_ids": ["1"]})
    out = SearchRecommendService({"semantic": sem}).recommend(req)
    assert all(i["product_id"] != "1" for s in out["sections"] for i in s["items"])
    a = cache_key(validate_request({"q": "Sữa  Rửa Mặt", "cart_ids": ["2", "1"]}))
    b = cache_key(validate_request({"q": "sua rua mat", "cart_ids": ["1", "2"]}))
    assert a == b and a.startswith("search:rec:v2:")


def test_trending_branch_falls_back_to_sold_and_skips_out_of_stock():
    class Cat:
        def products(self):
            return [{"id": str(i), "sold": i, "in_stock": i != 5} for i in range(1, 12)]

        def stock_map(self):
            return {p["id"]: p["in_stock"] for p in self.products()}

    src = TrendingSource(Cat(), db_factory=lambda: None, redis_factory=lambda: None)
    rows, origin = src.top()
    assert origin == "sold" and rows[0][0] == "11"
    assert "5" not in [c.product_id for c in trending(REQ, None, src)]


def test_semantic_refuses_empty_catalog_and_reindexes_on_new_version():
    import pytest

    from search_service.branches import semantic

    class Hit:
        def __init__(self, pid, rrf):
            self.product, self.rrf_score = {"id": pid}, rrf

    class Hybrid:
        def __init__(self):
            self.invalidations = 0

        def invalidate(self, reason=""):
            self.invalidations += 1

        def search(self, q, limit=30):
            return [Hit("7", 0.02), Hit("8", 0.01)]

    class Cat:
        version = 0

        def __init__(self, items):
            self.items = items

        def products(self):
            return self.items

    hybrid = Hybrid()
    with pytest.raises(RuntimeError):
        semantic(REQ, None, hybrid, Cat([]))
    cat = Cat([{"id": "7"}])
    cat.version = 1
    assert [c.product_id for c in semantic(REQ, None, hybrid, cat)] == ["7", "8"]
    semantic(REQ, None, hybrid, cat)
    assert hybrid.invalidations == 1
