"""U11: per-id incremental sync + local (restricted-source) similarity. No Mongo / Neo4j needed."""

from __future__ import annotations

import random
from datetime import datetime

import pytest

from graph_sync import incremental
from graph_sync.config import Settings
from graph_sync.similarity import ProductFeatures, compute_similar
from graph_sync.transform import CategoryInfo, Lookups

LOOKUPS = Lookups(
    brands={"24": "Torriden", "30": "Cosrx"},
    categories={"1001": CategoryInfo("Chăm Sóc Da Mặt", 1, None), "2002": CategoryInfo("Làm Sạch Da", 2, "1001"),
                "1": CategoryInfo("Sữa Rửa Mặt", 3, "2002")},
    skin_types={"1": "Da dầu/Hỗn hợp dầu"}, origins={},
)
SETTINGS = Settings(mongo_uri="x", mongo_db="x", neo4j_uri="x", neo4j_user="x", neo4j_password="x", redis_url="")


def make_doc(pid: int, name: str, status: str = "active", ingredients: str = "Water, Niacinamide, Salicylic Acid") -> dict:
    return {"ma_san_pham": pid, "ten_san_pham": name, "gia_ban": 100000 + pid, "so_luong_da_ban": pid,
            "ma_thuong_hieu": 24, "ma_danh_muc": 1, "ma_loai_da": 1, "thanh_phan_full": ingredients,
            "trang_thai": status, "trang_thai_kho": "con_hang", "so_luong_ton_kho": 10,
            "updated_at": datetime(2026, 10, 1)}


class FakeCursor(list):
    def batch_size(self, _n):
        return self


class FakeCollection:
    def __init__(self, docs):
        self.docs = docs

    def find(self, query=None, projection=None):
        wanted = (query or {}).get("ma_san_pham", {}).get("$in")
        rows = [d for d in self.docs if wanted is None or d["ma_san_pham"] in wanted or str(d["ma_san_pham"]) in wanted]
        return FakeCursor(dict(r) for r in rows)


class FakeDb(dict):
    def __init__(self, docs):
        super().__init__(san_pham=FakeCollection(docs))


class FakeResult(list):
    def consume(self):
        return None


class FakeSession:
    def __init__(self, driver):
        self.driver = driver

    def __enter__(self):
        return self

    def __exit__(self, *_):
        return False

    def run(self, cypher, **params):
        self.driver.reads.append((cypher, params))
        return [{"id": n} for n in self.driver.neighbours]

    def execute_write(self, fn):
        driver = self.driver

        class Tx:
            def run(self, cypher, **params):
                driver.writes.append((cypher, params))
                if "UNWIND $rows" in cypher and "RETURN count(p) AS n" in cypher:
                    return FakeResult([{"n": len(params["rows"])}])
                if "SET p.active = false" in cypher and "RETURN count(p) AS n" in cypher:
                    return FakeResult([{"n": len(params.get("ids", []))}])
                return FakeResult()

        return fn(Tx())


class FakeDriver:
    def __init__(self, neighbours=()):
        self.neighbours = list(neighbours)
        self.writes: list = []
        self.reads: list = []
        self.closed = False

    def session(self, database=None):
        return FakeSession(self)

    def close(self):
        self.closed = True


def feat(pid, ingredients, cat="c2", brand="x", benefits=(), skin=()):
    return ProductFeatures(id=pid, ingredients=tuple(ingredients), actives=tuple(ingredients[:1]), names={},
                           cat1="c1", cat2=cat, brand=brand, benefits=frozenset(benefits), skin_types=frozenset(skin))


def test_normalize_ids_dedupes_and_rejects_junk():
    assert incremental.normalize_ids([" 1 ", "1", 2, "", "a b", "x;drop", "ok-1_2", "9" * 41]) == ["1", "2", "ok-1_2"]


def test_id_variants_cover_int_and_str():
    assert incremental.id_variants(["12", "ab"]) == ["12", 12, "ab"]


def test_compute_similar_only_sources_equals_filtered_full():
    rng = random.Random(7)
    pool = [f"i{n}" for n in range(30)]
    products = [feat(f"{n:03d}", rng.sample(pool, 6), cat=rng.choice(["c2", "c3", "c4"]), brand=rng.choice("xyz"),
                     benefits=rng.sample(["a", "b", "c"], 2), skin=rng.sample(["s1", "s2"], 1)) for n in range(60)]
    full = compute_similar(products, top_k=5, min_score=0.1)
    picked = {"003", "017", "042"}
    local = compute_similar(products, top_k=5, min_score=0.1, only_sources=picked)
    assert local and {e["source"] for e in local} <= picked
    assert local == [e for e in full if e["source"] in picked]       # identical edges for the picked sources


def test_compute_similar_without_only_sources_unchanged():
    a, b = feat("A", ["w", "n"]), feat("B", ["w", "n"])
    assert {(e["source"], e["target"]) for e in compute_similar([a, b])} == {("A", "B"), ("B", "A")}
    assert compute_similar([a, b], only_sources=[]) == []


def test_sync_ids_upserts_deactivates_missing_and_limits_similarity():
    docs = [make_doc(1, "Sua rua mat A"), make_doc(2, "Sua rua mat B"), make_doc(3, "Sua rua mat C", ingredients="Water, Retinol")]
    driver = FakeDriver(neighbours=["3"])
    summary = incremental.sync_ids(["1", "999"], SETTINGS, similarity="local", db=FakeDb(docs), driver=driver, lookups=LOOKUPS)
    assert summary["requested"] == 2 and summary["found"] == 1
    assert summary["upserted"] == 1
    assert summary["deactivated_missing"] == 1                              # id 999 is gone from Mongo
    deact = [w for w in driver.writes if "SET p.active = false" in w[0] and "NOT p.id" not in w[0]]
    assert deact and deact[0][1]["ids"] == ["999"]
    assert driver.reads[0][1]["ids"] == ["1", "999"]                       # neighbours read for the requested ids
    clears = [w for w in driver.writes if "DELETE s" in w[0] and "ids" in w[1]]
    cleared = {i for _c, p in clears for i in p["ids"]}
    assert cleared == {"1", "3"}                                            # id + neighbour; product 2 is untouched
    assert summary["similar_sources"] == 2
    assert not driver.closed                                                # injected driver is not closed


def test_sync_ids_without_similarity_writes_no_similar_edges():
    docs = [make_doc(1, "A"), make_doc(2, "B")]
    driver = FakeDriver()
    summary = incremental.sync_ids(["1"], SETTINGS, similarity="none", db=FakeDb(docs), driver=driver, lookups=LOOKUPS)
    assert summary["similarity"] == "none" and summary["similar_written"] == 0
    assert driver.reads == []                                               # no neighbour query
    assert not [w for w in driver.writes if "SIMILAR_TO" in w[0]]


def test_sync_ids_hidden_product_is_upserted_inactive_and_is_not_a_similarity_source():
    docs = [make_doc(1, "A", status="inactive"), make_doc(2, "B")]
    driver = FakeDriver(neighbours=["2"])
    summary = incremental.sync_ids(["1"], SETTINGS, similarity="local", db=FakeDb(docs), driver=driver, lookups=LOOKUPS)
    upsert = [w for w in driver.writes if "UNWIND $rows" in w[0]][0]
    assert upsert[1]["rows"][0]["active"] is False
    assert summary["similar_sources"] == 1                                  # only the neighbour (2) is an active source
    assert any("Product {active: false}" in w[0] and "SIMILAR_TO" in w[0] for w in driver.writes)   # inactive edges cleared


def test_sync_ids_rejects_unknown_mode():
    with pytest.raises(ValueError):
        incremental.sync_ids(["1"], SETTINGS, similarity="all", db=FakeDb([]), driver=FakeDriver(), lookups=LOOKUPS)


def test_sync_ids_empty_is_noop():
    assert incremental.sync_ids([], SETTINGS)["requested"] == 0
