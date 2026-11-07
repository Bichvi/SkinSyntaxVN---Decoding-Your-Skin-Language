from __future__ import annotations

import datetime
from types import SimpleNamespace

import pytest
from bson import Int64, ObjectId

import config
from agents import catalog_cache
from agents.query_sources import load_vocabulary
from tests.fakes import FakeDb


class FakeRedis:
    def __init__(self):
        self.store, self.stream, self.down = {}, [], False

    def _check(self):
        if self.down:
            raise ConnectionError("down")

    def get(self, key):
        self._check()
        return self.store.get(key)

    def set(self, key, value, ex=None):
        self._check()
        self.store[key] = value

    def xrevrange(self, name, max="+", min="-", count=None):
        self._check()
        return [(entry_id, {}) for entry_id in reversed(self.stream)][:count]


@pytest.fixture
def redis_env(monkeypatch):
    cache, events = FakeRedis(), FakeRedis()
    monkeypatch.setattr(config, "CATALOG_CACHE_TTL_SECONDS", 300)
    monkeypatch.setattr(config, "REDIS_URL", "redis://cache")
    monkeypatch.setattr(config, "CATALOG_EVENTS_REDIS_URL", "redis://events")
    monkeypatch.setattr(catalog_cache, "_cacheable", lambda db: True)
    monkeypatch.setattr(catalog_cache, "_client", lambda url: cache if url == "redis://cache" else events)
    return SimpleNamespace(cache=cache, events=events, db=SimpleNamespace(name="testdb"))


def counting_loader(result=None):
    calls = []

    def loader():
        calls.append(1)
        return [{"n": len(calls)}] if result is None else result
    return loader, calls


def test_second_load_hits_cache(redis_env):
    loader, calls = counting_loader()
    first = catalog_cache.cached("catalog", redis_env.db, loader, use_events=True)
    second = catalog_cache.cached("catalog", redis_env.db, loader, use_events=True)
    assert first == second and len(calls) == 1
    assert "ai_idol:catalog:v1:testdb" in redis_env.cache.store


def test_new_stream_entry_reloads(redis_env):
    loader, calls = counting_loader()
    catalog_cache.cached("catalog", redis_env.db, loader, use_events=True)
    redis_env.events.stream.append(b"1-0")
    assert catalog_cache.cached("catalog", redis_env.db, loader, use_events=True) == [{"n": 2}]
    assert catalog_cache.cached("catalog", redis_env.db, loader, use_events=True) == [{"n": 2}]
    assert len(calls) == 2


def test_stream_redis_down_still_caches_by_ttl(redis_env):
    redis_env.events.down = True
    loader, calls = counting_loader()
    catalog_cache.cached("catalog", redis_env.db, loader, use_events=True)
    catalog_cache.cached("catalog", redis_env.db, loader, use_events=True)
    assert len(calls) == 1


def test_cache_redis_down_falls_back_without_raising(redis_env):
    redis_env.cache.down = True
    loader, calls = counting_loader()
    for _ in range(2):
        assert catalog_cache.cached("catalog", redis_env.db, loader, use_events=True)
    assert len(calls) == 2


def test_loader_error_propagates_and_is_not_cached(redis_env):
    def boom():
        raise ValueError("mongo down")
    with pytest.raises(ValueError):
        catalog_cache.cached("catalog", redis_env.db, boom, use_events=True)
    assert redis_env.cache.store == {}


def test_ttl_zero_disables(monkeypatch):
    from pymongo.database import Database
    db = Database.__new__(Database)  # a real Database type, never used to connect
    monkeypatch.setattr(config, "CATALOG_CACHE_TTL_SECONDS", 300)
    assert catalog_cache._cacheable(db) is True
    monkeypatch.setattr(config, "CATALOG_CACHE_TTL_SECONDS", 0)
    assert catalog_cache._cacheable(db) is False


def test_fake_db_never_touches_redis(monkeypatch):
    monkeypatch.setattr(config, "CATALOG_CACHE_TTL_SECONDS", 300)
    monkeypatch.setattr(catalog_cache, "_client", lambda url: pytest.fail("Redis touched"))
    loader, calls = counting_loader()
    catalog_cache.cached("catalog", FakeDb(), loader, use_events=True)
    catalog_cache.cached("catalog", FakeDb(), loader, use_events=True)
    assert len(calls) == 2


def test_round_trip_types(redis_env):
    oid = ObjectId()
    when = datetime.datetime(2026, 1, 2, 3, 4, 5, 678000, tzinfo=datetime.timezone.utc)
    rows = [{"_id": oid, "at": when, "big": Int64(2**40), "small": 7, "price": 12.5, "none": None,
             "nested": [[1, 2], {"a": [None, "x"]}]}]
    catalog_cache.cached("catalog", redis_env.db, lambda: rows, use_events=False)
    got = catalog_cache.cached("catalog", redis_env.db, lambda: [], use_events=False)[0]
    assert got["_id"] == oid and isinstance(got["_id"], ObjectId)
    assert got["at"] == when and got["at"].tzinfo is not None
    assert got["big"] == 2**40 and got["small"] == 7 and isinstance(got["small"], int)
    assert got["price"] == 12.5 and got["none"] is None and got["nested"] == rows[0]["nested"]


def test_results_are_independent(redis_env):
    loader, _ = counting_loader([{"a": 1}])
    catalog_cache.cached("catalog", redis_env.db, loader, use_events=False)[0]["a"] = 99
    first = catalog_cache.cached("catalog", redis_env.db, loader, use_events=False)
    first[0]["a"] = 5
    assert catalog_cache.cached("catalog", redis_env.db, loader, use_events=False) == [{"a": 1}]


def test_vocabulary_is_cached(redis_env):
    class Coll:
        def __init__(self, rows):
            self.rows, self.finds = rows, 0

        def find(self, *_a, **_k):
            self.finds += 1
            return FakeCursor(self.rows)

    class FakeCursor:
        def __init__(self, rows):
            self.rows = rows

        def limit(self, n):
            return self.rows[:n]

    db = SimpleNamespace(
        name="testdb",
        thuong_hieu=Coll([{"ma_thuong_hieu": 1, "ten_thuong_hieu": "Cosrx"}]),
        xuat_xu=Coll([{"ma_xuat_xu": 1, "ten_xuat_xu": "Han Quoc"}]),
        noi_san_xuat=Coll([{"ma_nsx": 1, "ten_nsx": "Nha may A"}]),
        loai_da=Coll([{"ma_loai_da": 1, "ten_loai_da": "Da dau"}]))
    first = load_vocabulary(db)
    second = load_vocabulary(db)
    assert db.thuong_hieu.finds == 1 and db.loai_da.finds == 1
    assert type(first) is type(second)
