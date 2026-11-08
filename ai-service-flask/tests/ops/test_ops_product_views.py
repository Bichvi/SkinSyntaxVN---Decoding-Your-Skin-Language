"""Job product_views_flush: rút bộ đếm lượt xem Redis sang Mongo (fakeredis + db giả, không chạm Mongo thật)."""

import pytest

from shared.redis_kit import keys
from workers import jobs


class FakeResult:
    def __init__(self, n):
        self.matched_count = n


class FakeColl:
    def __init__(self, error=None):
        self.calls = []
        self.error = error

    def bulk_write(self, ops, ordered=True):
        if self.error:
            raise self.error
        self.calls.append((list(ops), ordered))
        return FakeResult(len(ops))


class FakeDb:
    def __init__(self, error=None):
        self.coll = FakeColl(error)

    def __getitem__(self, name):
        assert name == "san_pham"
        return self.coll


def _ops(db):
    return db.coll.calls[0][0]


def test_rename_and_flush(r):
    r.hset(keys.PRODUCT_VIEWS, mapping={"SP1": 3, "SP2": 2})
    db = FakeDb()
    out = jobs.flush_product_views(r, db)
    assert out == {"products": 2, "views": 5, "matched": 2, "leftover": False}
    assert not r.exists(keys.PRODUCT_VIEWS) and not r.exists(keys.PRODUCT_VIEWS_FLUSHING)
    assert db.coll.calls[0][1] is False


def test_digit_id_matches_str_and_int(r):
    r.hset(keys.PRODUCT_VIEWS, mapping={"123": 4})
    db = FakeDb()
    jobs.flush_product_views(r, db)
    op = _ops(db)[0]
    assert op._filter == {"ma_san_pham": {"$in": ["123", 123]}}
    assert op._doc == {"$inc": {"luot_xem": 4}}


def test_non_digit_id(r):
    r.hset(keys.PRODUCT_VIEWS, mapping={"SP-9": 1})
    db = FakeDb()
    jobs.flush_product_views(r, db)
    assert _ops(db)[0]._filter == {"ma_san_pham": {"$in": ["SP-9"]}}


def test_ignores_bad_values(r):
    r.hset(keys.PRODUCT_VIEWS, mapping={"A": "abc", "B": 0, "C": -2, "D": 6})
    db = FakeDb()
    out = jobs.flush_product_views(r, db)
    assert out["products"] == 1 and out["views"] == 6


def test_empty_returns_zero(r):
    db = FakeDb()
    assert jobs.flush_product_views(r, db) == {"products": 0, "views": 0}
    assert db.coll.calls == []


def test_leftover_processed_first_main_untouched(r):
    r.hset(keys.PRODUCT_VIEWS_FLUSHING, mapping={"OLD": 7})
    r.hset(keys.PRODUCT_VIEWS, mapping={"NEW": 2})
    db = FakeDb()
    out = jobs.flush_product_views(r, db)
    assert out["leftover"] is True and out["views"] == 7
    assert _ops(db)[0]._filter == {"ma_san_pham": {"$in": ["OLD"]}}
    assert not r.exists(keys.PRODUCT_VIEWS_FLUSHING)
    assert r.hget(keys.PRODUCT_VIEWS, "NEW") == "2"


def test_mongo_error_keeps_flushing_key(r):
    r.hset(keys.PRODUCT_VIEWS, mapping={"SP1": 3})
    db = FakeDb(error=RuntimeError("mongo down"))
    with pytest.raises(RuntimeError):
        jobs.flush_product_views(r, db)
    assert r.hget(keys.PRODUCT_VIEWS_FLUSHING, "SP1") == "3"
    assert not r.exists(keys.PRODUCT_VIEWS)


def test_job_registered_every_5_minutes():
    spec = next(j for j in jobs.build_jobs() if j.name == "product_views_flush")
    assert spec.schedule.every_minutes == 5
    assert spec.catch_up is False and spec.lock_ttl_s == 240
