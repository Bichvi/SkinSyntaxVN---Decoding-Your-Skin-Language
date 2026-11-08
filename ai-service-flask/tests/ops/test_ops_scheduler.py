"""U11: schedules (Asia/Ho_Chi_Minh), job locks, status records, catch-up, --since marker."""

from __future__ import annotations

from datetime import datetime, timezone

import pytest

from shared.redis_kit import keys, ops
from shared.redis_kit.lock import RedisLock
from workers import jobs
from workers.scheduler import JobSkipped, JobSpec, Schedule, Scheduler, local_tz

TZ = local_tz()


def at(y, mo, d, h, mi, s=0):
    return datetime(y, mo, d, h, mi, s, tzinfo=TZ)


# 2026-10-23 is a Friday
def test_job_table_next_runs():
    now = at(2026, 10, 23, 14, 3, 30)
    assert Schedule(minute=10).next_after(now) == at(2026, 10, 23, 14, 10)                   # hourly at :10
    assert Schedule(minute=10).next_after(at(2026, 10, 23, 14, 10)) == at(2026, 10, 23, 15, 10)
    assert Schedule(minute=30, hour=2).next_after(now) == at(2026, 10, 24, 2, 30)            # nightly full sync
    assert Schedule(minute=0, hour=3).next_after(now) == at(2026, 10, 24, 3, 0)              # co-purchase
    assert Schedule(minute=30, hour=3, weekday=6).next_after(now) == at(2026, 10, 25, 3, 30)  # Sunday reindex
    assert Schedule(minute=0, hour=8, weekday=0).next_after(now) == at(2026, 10, 26, 8, 0)     # Monday report
    assert Schedule(every_minutes=5).next_after(now) == at(2026, 10, 23, 14, 5)


def test_timezone_is_vietnam_not_utc():
    utc_now = datetime(2026, 10, 22, 19, 29, tzinfo=timezone.utc)        # = 02:29 on the 23rd in Vietnam
    assert Schedule(minute=30, hour=2).next_after(utc_now.astimezone(TZ)) == at(2026, 10, 23, 2, 30)


def test_schedule_validation():
    with pytest.raises(ValueError):
        Schedule(every_minutes=7)
    with pytest.raises(ValueError):
        Schedule(minute=61)
    with pytest.raises(ValueError):
        Schedule()


class Clock:
    def __init__(self, now):
        self.now = now

    def __call__(self):
        return self.now


def make(r, jobs_, clock):
    return Scheduler(jobs_, clock=clock, lock_factory=lambda key, ttl: RedisLock(key, ttl_ms=ttl * 1000, client=r),
                     record=lambda name, **kw: ops.record_job(name, client=r, **kw),
                     last_job=lambda name: ops.last_job(name, client=r))


def test_tick_runs_only_due_jobs_records_status_and_reschedules(r):
    ran = []
    spec = [JobSpec("hourly", Schedule(minute=10), lambda: ran.append("hourly") or {"n": 3}),
            JobSpec("nightly", Schedule(minute=30, hour=2), lambda: ran.append("nightly") or {})]
    clock = Clock(at(2026, 10, 23, 14, 3))
    sched = make(r, spec, clock)
    sched.start()
    assert sched.tick() == []
    clock.now = at(2026, 10, 23, 14, 10, 5)
    assert sched.tick() == ["hourly"] and ran == ["hourly"]
    assert sched.next_run["hourly"] == at(2026, 10, 23, 15, 10)
    last = ops.last_job("hourly", client=r)
    assert last["status"] == "ok" and last["ok"] is True and last["detail"] == {"n": 3}
    assert last["duration_s"] >= 0 and last["consecutive_failures"] == 0
    assert r.ttl(keys.ops_job_last("hourly")) > 29 * 86400                 # 30 day TTL


def test_failure_is_recorded_streak_counts_and_scheduler_survives(r):
    def boom():
        raise RuntimeError("neo4j unavailable")

    sched = make(r, [JobSpec("flaky", Schedule(every_minutes=5), boom)], Clock(at(2026, 10, 23, 14, 0)))
    assert sched.run_job("flaky") == "fail"
    assert sched.run_job("flaky") == "fail"
    last = ops.last_job("flaky", client=r)
    assert last["status"] == "fail" and last["ok"] is False and last["consecutive_failures"] == 2
    assert "neo4j unavailable" in last["error"]
    sched.jobs["flaky"] = JobSpec("flaky", Schedule(every_minutes=5), lambda: {})
    assert sched.run_job("flaky") == "ok"
    assert ops.last_job("flaky", client=r)["consecutive_failures"] == 0   # success resets the streak


def test_lock_prevents_a_second_instance_from_running_the_same_job(r):
    calls = []
    sched = make(r, [JobSpec("full", Schedule(minute=30, hour=2), lambda: calls.append(1) or {})], Clock(at(2026, 10, 23, 2, 30)))
    other_instance = RedisLock(keys.lock("ops_job_full"), ttl_ms=60_000, client=r)
    assert other_instance.acquire()
    assert sched.run_job("full") == "skipped" and calls == []
    assert ops.last_job("full", client=r)["detail"]["reason"] == "another_instance"
    other_instance.release()
    assert sched.run_job("full") == "ok" and calls == [1]


def test_lock_is_released_even_when_the_job_fails(r):
    sched = make(r, [JobSpec("j", Schedule(every_minutes=5), lambda: 1 / 0)], Clock(at(2026, 10, 23, 2, 30)))
    sched.run_job("j")
    assert r.get(keys.lock("ops_job_j")) is None


def test_skipped_job_is_not_a_failure(r):
    def nothing():
        raise JobSkipped("graph_sync_lock_held")

    sched = make(r, [JobSpec("j", Schedule(every_minutes=5), nothing)], Clock(at(2026, 10, 23, 2, 30)))
    assert sched.run_job("j") == "skipped"
    last = ops.last_job("j", client=r)
    assert last["status"] == "skipped" and last["consecutive_failures"] == 0


def test_chained_job_runs_after_success_only(r):
    order = []
    spec = [JobSpec("full", Schedule(minute=30, hour=2), lambda: order.append("full") or {}, after=("eval",)),
            JobSpec("eval", None, lambda: order.append("eval") or {"constraint_pass_pct": 100.0})]
    sched = make(r, spec, Clock(at(2026, 10, 23, 2, 30)))
    sched.start()
    assert "eval" not in sched.next_run                                    # chained only
    sched.run_job("full")
    assert order == ["full", "eval"]
    assert ops.last_job("eval", client=r)["detail"]["constraint_pass_pct"] == 100.0
    order.clear()
    sched.jobs["full"] = JobSpec("full", Schedule(minute=30, hour=2), lambda: 1 / 0, after=("eval",))
    sched.run_job("full")
    assert order == []


def test_catch_up_only_for_jobs_that_ran_before_and_missed_a_slot(r):
    spec = [JobSpec("nightly", Schedule(minute=30, hour=2), lambda: {}),
            JobSpec("never_ran", Schedule(minute=30, hour=2), lambda: {}),
            JobSpec("fresh", Schedule(minute=30, hour=2), lambda: {})]
    clock = Clock(at(2026, 10, 23, 9, 0))
    sched = make(r, spec, clock)
    slot = at(2026, 10, 23, 2, 30).timestamp()
    ops.record_job("nightly", started_at=slot - 86400, finished_at=slot - 86300, client=r)    # last ran yesterday
    ops.record_job("fresh", started_at=slot + 5, finished_at=slot + 60, client=r)              # ran today's slot
    assert sched.start() == ["nightly"]


# -- --since marker of the safety-net job -----------------------------------------------------------------------

def test_incremental_job_uses_and_advances_the_since_marker(monkeypatch):
    from graph_sync import incremental
    incremental.reset_shared_db()
    store: dict[str, str] = {keys.OPS_GRAPH_SYNC_SINCE: "2026-10-23T05:00:00Z"}
    monkeypatch.setattr(jobs.ops, "get_text", lambda key, **_: store.get(key))
    monkeypatch.setattr(jobs.ops, "set_text", lambda key, value, **_: store.__setitem__(key, value) or True)
    seen = {}

    class Coll:
        def find(self, query, projection=None):
            seen["query"] = query
            return [{"ma_san_pham": 11}, {"ma_san_pham": 12}]

    from graph_sync import extract
    monkeypatch.setattr(extract, "connect", lambda *_a, **_k: {"san_pham": Coll()})
    applied = {}
    from workers import catalog_sync
    monkeypatch.setattr(catalog_sync.CatalogSyncer, "apply", lambda self, plan: applied.update(ids=plan.ids, sim=plan.similarity) or {"neo4j_upserted": 2})
    detail = jobs.graph_sync_incremental()
    assert seen["query"]["$or"][0]["updated_at"]["$gte"] == datetime(2026, 10, 23, 5, 0)
    assert applied == {"ids": ["11", "12"], "sim": "local"} and detail["changed"] == 2
    assert store[keys.OPS_GRAPH_SYNC_SINCE] != "2026-10-23T05:00:00Z" and store[keys.OPS_GRAPH_SYNC_SINCE].endswith("Z")


def test_incremental_job_does_not_advance_the_marker_when_the_sync_fails(monkeypatch):
    from graph_sync import incremental
    incremental.reset_shared_db()
    store = {keys.OPS_GRAPH_SYNC_SINCE: "2026-10-23T05:00:00Z"}
    monkeypatch.setattr(jobs.ops, "get_text", lambda key, **_: store.get(key))
    monkeypatch.setattr(jobs.ops, "set_text", lambda key, value, **_: store.__setitem__(key, value) or True)

    class Coll:
        def find(self, query, projection=None):
            return [{"ma_san_pham": 1}]

    from graph_sync import extract
    from workers import catalog_sync
    monkeypatch.setattr(extract, "connect", lambda *_a, **_k: {"san_pham": Coll()})
    monkeypatch.setattr(catalog_sync.CatalogSyncer, "apply", lambda self, plan: (_ for _ in ()).throw(RuntimeError("down")))
    with pytest.raises(RuntimeError):
        jobs.graph_sync_incremental()
    assert store[keys.OPS_GRAPH_SYNC_SINCE] == "2026-10-23T05:00:00Z"


def test_job_table_matches_the_prompt():
    table = {j.name: j for j in jobs.build_jobs()}
    now = at(2026, 10, 23, 0, 0)
    nxt = {n: j.schedule.next_after(now) for n, j in table.items() if j.schedule}
    assert nxt["graph_sync_incremental"] == at(2026, 10, 23, 0, 10)
    assert nxt["graph_sync_full"] == at(2026, 10, 23, 2, 30)
    assert nxt["co_purchase"] == at(2026, 10, 23, 3, 0)
    assert nxt["chroma_reindex"] == at(2026, 10, 25, 3, 30)
    assert nxt["dictionary_export"] == at(2026, 10, 23, 4, 0)
    assert nxt["popular_maintain"] == at(2026, 10, 23, 4, 10)
    assert nxt["zero_result_report"] == at(2026, 10, 26, 8, 0)
    assert table["graph_sync_full"].after == ("graph_eval",) and table["graph_eval"].schedule is None
