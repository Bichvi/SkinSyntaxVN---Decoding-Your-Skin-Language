"""U11: catalog change consumer - debounce plan, ordering, retry, backlog after downtime."""

from __future__ import annotations

import pytest

from shared.redis_kit import keys, streams
from shared.redis_kit.lock import LockHeld, RedisLock
from workers import catalog_sync
from workers.catalog_sync import CatalogSyncer, SyncPlan, build_plan


def msg(i, pid, type_="product_updated", **extra):
    return streams.Message(f"{i}-0", {"type": type_, "ma_san_pham": str(pid), "ts": "1", **extra})


# -- debounce ------------------------------------------------------------------------------------------------

def test_fifty_saves_of_one_product_collapse_to_one_id():
    plan = build_plan([msg(i, 1496) for i in range(50)])
    assert plan.ids == ["1496"] and plan.events == 50 and plan.similarity == "local"


def test_distinct_ids_keep_first_seen_order_and_drop_invalid():
    plan = build_plan([msg(1, 5), msg(2, 3), msg(3, 5), streams.Message("4-0", {"type": "product_updated"}),
                       streams.Message("5-0", {"ma_san_pham": "bad id!"}), msg(6, 9)])
    assert plan.ids == ["5", "3", "9"] and plan.dropped == 2 and plan.events == 4


def test_price_or_stock_only_skips_similarity_but_any_other_event_needs_it():
    assert build_plan([msg(1, 7, "price_changed"), msg(2, 8, "stock_changed")]).similarity == "none"
    assert build_plan([msg(1, 7, "price_changed"), msg(2, 7, "product_hidden")]).similarity == "local"
    assert build_plan([msg(1, 7, "product_updated", reason="tags")]).similarity == "local"   # U07 producer format


def test_big_batches_leave_similarity_to_the_nightly_job():
    plan = build_plan([msg(i, 1000 + i) for i in range(catalog_sync.LOCAL_SIMILARITY_MAX_IDS + 1)])
    assert len(plan.ids) == catalog_sync.LOCAL_SIMILARITY_MAX_IDS + 1 and plan.similarity == "none"


# -- applying a plan -------------------------------------------------------------------------------------------

class FakeLock:
    key = "graph_sync:lock"

    def __init__(self, calls, free=True, degraded=False):
        self.calls, self.free, self.degraded, self.acquired = calls, free, degraded, False

    def acquire(self):
        self.calls.append("lock")
        self.acquired = self.free
        return self.acquired

    def extend(self):
        return True

    def release(self):
        self.calls.append("unlock")


def make_syncer(calls, *, graph_fail=False, chroma_fail=False, lock=None):
    records = []

    def graph(ids, similarity):
        calls.append(("graph", tuple(ids), similarity))
        if graph_fail:
            raise RuntimeError("neo4j down")
        return {"upserted": len(ids), "deactivated_missing": 0, "similar_written": 4, "requested": len(ids)}

    def chroma(ids):
        calls.append(("chroma", tuple(ids)))
        if chroma_fail:
            raise RuntimeError("chroma down")
        return {"upserted": len(ids), "deleted": 0}

    syncer = CatalogSyncer(graph_sync=graph, chroma=chroma,
                           invalidate=lambda scope, ids: calls.append(("invalidate", scope, tuple(ids))) or True,
                           warm=lambda: calls.append("warm") or True,
                           lock_factory=lambda: lock or FakeLock(calls),
                           record=lambda name, **kw: records.append((name, kw)))
    return syncer, records


def test_apply_runs_neo4j_then_chroma_then_invalidates_under_the_lock():
    calls = []
    syncer, records = make_syncer(calls)
    detail = syncer.apply(SyncPlan(ids=["1", "2"], similarity="local", events=3, types={"product_updated": 3}))
    assert calls == ["lock", ("graph", ("1", "2"), "local"), ("chroma", ("1", "2")), "unlock",
                     ("invalidate", "catalog", ("1", "2")), "warm"]
    assert detail["neo4j_upserted"] == 2 and detail["chroma_upserted"] == 2 and detail["invalidated"] is True
    assert records[0][0] == "catalog_sync" and records[0][1]["status"] == "ok"


def test_failure_releases_lock_and_never_invalidates():
    calls = []
    syncer, records = make_syncer(calls, chroma_fail=True)
    with pytest.raises(RuntimeError):
        syncer.handle([msg(1, 1)])
    assert calls[-1] == "unlock" and not any(isinstance(c, tuple) and c[0] == "invalidate" for c in calls)
    assert records[-1][1]["status"] == "fail" and "chroma down" in records[-1][1]["error"]


def test_handle_ignores_windows_without_valid_ids():
    calls = []
    syncer, _ = make_syncer(calls)
    syncer.handle([streams.Message("1-0", {"type": "product_updated"})])
    assert calls == []


def test_lock_wait_succeeds_when_the_nightly_job_finishes_and_times_out_otherwise():
    calls = []

    class Flaky(FakeLock):
        def acquire(self):
            self.calls.append("try")
            self.acquired = len([c for c in self.calls if c == "try"]) >= 3
            return self.acquired

    syncer, _ = make_syncer(calls, lock=Flaky(calls))
    assert syncer.acquire_lock(30, poll_s=0, sleep=lambda _s: None).acquired
    held, _ = make_syncer([], lock=FakeLock([], free=False))
    with pytest.raises(LockHeld):
        held.acquire_lock(0, poll_s=0, sleep=lambda _s: None)


def test_real_redis_lock_blocks_a_second_syncer(r):
    legacy = RedisLock(keys.LEGACY_GRAPH_SYNC_LOCK, ttl_ms=60_000, client=r)
    assert legacy.acquire()           # e.g. the nightly graph_sync CLI
    calls = []
    syncer, _ = make_syncer(calls, lock=RedisLock(keys.LEGACY_GRAPH_SYNC_LOCK, ttl_ms=60_000, client=r))
    with pytest.raises(LockHeld):
        syncer.apply(SyncPlan(ids=["1"], similarity="none"), lock_wait_s=0)
    assert not any(isinstance(c, tuple) and c[0] == "graph" for c in calls)


def test_bulk_plan_is_applied_in_chunks():
    calls = []
    syncer, _ = make_syncer(calls)
    ids = [str(n) for n in range(1, 1202)]
    syncer.apply(SyncPlan(ids=ids, similarity="none"))
    graph_calls = [c for c in calls if isinstance(c, tuple) and c[0] == "graph"]
    assert [len(c[1]) for c in graph_calls] == [500, 500, 201]


# -- stream behaviour: backlog after downtime, retry, dead letter ----------------------------------------------------

def publish(r, pid, type_="product_updated"):
    return streams.publish(keys.CATALOG_CHANGES_STREAM, {"type": type_, "ma_san_pham": pid, "ts": "1"}, client=r)


def poll(r, syncer, **kw):
    return streams.poll_once(r, keys.CATALOG_CHANGES_STREAM, catalog_sync.GROUP, "w1",
                             lambda msgs: syncer.handle(msgs) or None, batch=1000, block_ms=0, claim_idle_ms=0,
                             failure_pause_s=0, **kw)


def test_backlog_published_while_the_worker_was_down_is_processed_and_acked(r):
    for pid in (1, 2, 3, 2, 1):           # events piled up while the worker was stopped
        publish(r, pid)
    streams.ensure_group(r, keys.CATALOG_CHANGES_STREAM, catalog_sync.GROUP)
    calls = []
    syncer, _ = make_syncer(calls)
    assert poll(r, syncer) == 5
    assert ("graph", ("1", "2", "3"), "local") in calls
    assert r.xpending(keys.CATALOG_CHANGES_STREAM, catalog_sync.GROUP)["pending"] == 0


def test_failed_sync_leaves_messages_pending_and_a_later_retry_acks_them(r):
    publish(r, 7)
    streams.ensure_group(r, keys.CATALOG_CHANGES_STREAM, catalog_sync.GROUP)
    bad, _ = make_syncer([], graph_fail=True)
    assert poll(r, bad) == 0
    assert r.xpending(keys.CATALOG_CHANGES_STREAM, catalog_sync.GROUP)["pending"] == 1
    import time

    time.sleep(0.02)                      # let the pending entry idle past claim_idle_ms
    good_calls = []
    good, _ = make_syncer(good_calls)
    assert streams.poll_once(r, keys.CATALOG_CHANGES_STREAM, catalog_sync.GROUP, "w1",
                             lambda msgs: good.handle(msgs) or None, batch=10, block_ms=0, claim_idle_ms=1,
                             failure_pause_s=0) == 1
    assert r.xpending(keys.CATALOG_CHANGES_STREAM, catalog_sync.GROUP)["pending"] == 0
    assert ("graph", ("7",), "local") in good_calls
