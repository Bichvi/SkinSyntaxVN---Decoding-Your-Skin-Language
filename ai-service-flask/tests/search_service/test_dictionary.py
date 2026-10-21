"""EntityDictionary (Bước 04): Neo4j source, Mongo fallback, TTL reload, single loader."""

from __future__ import annotations

import threading
import time

from search_service.dictionary import EntityDictionary


class Clock:
    def __init__(self) -> None:
        self.now = 1000.0

    def __call__(self) -> float:
        return self.now


class Driver:
    def __init__(self, brands=None, fail=False, delay=0.0) -> None:
        self.brands = brands or [{"key": "torriden", "name": "Torriden"}]
        self.fail = fail
        self.delay = delay
        self.calls = 0
        self._lock = threading.Lock()

    def execute_query(self, query, *args, **kwargs):
        with self._lock:
            self.calls += 1
        if self.delay:
            time.sleep(self.delay)
        if self.fail:
            raise RuntimeError("down")
        if ":Brand" in query:
            return list(self.brands), None, []
        return [], None, []


class Collection:
    def __init__(self, values) -> None:
        self.values = values
        self.calls = 0

    def distinct(self, field, *args, **kwargs):
        assert field == "ten_thuong_hieu"
        self.calls += 1
        return list(self.values)


class MongoDb:
    def __init__(self, values) -> None:
        self.thuong_hieu = Collection(values)

    def __getitem__(self, name):
        assert name == "thuong_hieu"
        return self.thuong_hieu


def test_loads_from_neo4j():
    d = EntityDictionary(driver=Driver(), mongo_db=None)
    snap = d.snapshot()
    assert snap.source == "neo4j"
    assert snap.lookup(("torriden",)) == ("brand", "torriden")
    assert snap.lookup(("sua", "rua", "mat")) == ("category", "sua_rua_mat")
    assert snap.lookup(("vitamin", "c")) == ("ingredient", "ascorbic_acid")


def test_neo4j_failure_falls_back_to_mongo_and_warns_once(caplog):
    mongo = MongoDb(["Torriden", "Some By Mi", None, ""])
    d = EntityDictionary(driver=Driver(fail=True), mongo_db=mongo, ttl_seconds=60, clock=Clock())
    with caplog.at_level("WARNING", logger="search_service.dictionary"):
        snap = d.snapshot()
        d.reload()
    assert snap.source == "mongo"
    assert snap.lookup(("some", "by", "mi")) == ("brand", "some_by_mi")
    assert snap.lookup(("toner",)) == ("category", "toner")  # YAML still loaded
    warnings = [r for r in caplog.records if "fallback" in r.getMessage()]
    assert len(warnings) == 1


def test_no_sources_still_has_yaml():
    d = EntityDictionary(driver=None, mongo_db=None)
    snap = d.snapshot()
    assert snap.source == "yaml"
    assert snap.lookup(("srm",)) == ("category", "sua_rua_mat")
    assert snap.brand_forms == ()


def test_ttl_expiry_reloads():
    clock = Clock()
    driver = Driver()
    d = EntityDictionary(driver=driver, mongo_db=None, ttl_seconds=600, clock=clock)
    d.snapshot()
    first_calls = driver.calls
    clock.now += 599
    d.snapshot()
    assert driver.calls == first_calls
    driver.brands = [{"key": "cerave", "name": "CeraVe"}]
    clock.now += 2
    snap = d.snapshot()
    assert driver.calls > first_calls
    assert snap.lookup(("cerave",)) == ("brand", "cerave")
    assert snap.lookup(("torriden",)) is None


def test_failed_reload_keeps_previous_snapshot():
    clock = Clock()
    driver = Driver()
    d = EntityDictionary(driver=driver, mongo_db=None, ttl_seconds=60, clock=clock)
    assert d.snapshot().source == "neo4j"
    driver.fail = True
    clock.now += 61
    snap = d.snapshot()  # neo4j down, no mongo -> keep the richer previous neo4j snapshot
    assert snap.lookup(("torriden",)) == ("brand", "torriden")


def test_concurrent_callers_load_once():
    driver = Driver(delay=0.05)
    d = EntityDictionary(driver=driver, mongo_db=None)
    barrier = threading.Barrier(8)
    results = []

    def worker():
        barrier.wait()
        results.append(d.snapshot())

    threads = [threading.Thread(target=worker) for _ in range(8)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    assert driver.calls == 3  # brands + other labels + ingredients, loaded once
    assert len({id(s) for s in results}) == 1


def test_stale_reload_does_not_block_other_threads():
    clock = Clock()
    driver = Driver()
    d = EntityDictionary(driver=driver, mongo_db=None, ttl_seconds=60, clock=clock)
    old = d.snapshot()
    driver.delay = 0.3
    clock.now += 61
    t = threading.Thread(target=d.snapshot)
    t.start()
    time.sleep(0.05)  # the reloader now holds the lock
    start = time.perf_counter()
    got = d.snapshot()
    assert time.perf_counter() - start < 0.1
    assert got is old
    t.join()
