"""U11: alert thresholds (pure) + the health document shape."""

from __future__ import annotations

from search_service import ops_health
from search_service.ops_health import evaluate_alerts

NOW = 1_800_000_000.0
H = 3600


def healthy(**over):
    snap = {
        "neo4j": {"ok": True, "count": 2853, "mem_pct": 82.0},
        "chroma": {"ok": True, "count": 2853},
        "mongo": {"ok": True, "neo4j_expected": 2853, "chroma_expected": 2853},
        "redis": {"ok": True, "catalog_stream": {"length": 3, "pending": 0, "oldest_pending_idle_s": 0, "dead": 0}},
        "jobs": {name: {"status": "ok", "finished_at": NOW - 2 * H, "consecutive_failures": 0}
                 for name in ops_health.EXPECTED_JOBS},
        "worker_started_at": NOW - 100 * H,
    }
    for key, value in over.items():
        snap[key] = value
    return snap


def codes(snapshot, **kw):
    return {a["code"] for a in evaluate_alerts(snapshot, NOW, **kw)}


def test_healthy_snapshot_has_no_alerts():
    assert evaluate_alerts(healthy(), NOW) == []


def test_neo4j_ram_threshold_is_strictly_above_90_percent():
    assert "neo4j_ram_high" not in codes(healthy(neo4j={"ok": True, "count": 2853, "mem_pct": 90.0}))
    assert "neo4j_ram_high" in codes(healthy(neo4j={"ok": True, "count": 2853, "mem_pct": 90.1}))


def test_count_drift_above_one_percent_for_neo4j_and_chroma():
    assert "neo4j_count_drift" not in codes(healthy(neo4j={"ok": True, "count": 2830, "mem_pct": 80}))      # 0.8%
    assert "neo4j_count_drift" in codes(healthy(neo4j={"ok": True, "count": 2820, "mem_pct": 80}))         # 1.16%
    assert "chroma_count_drift" in codes(healthy(chroma={"ok": True, "count": 2500}))
    assert "chroma_count_drift" not in codes(healthy(chroma={"ok": True, "count": 2853}))


def test_stream_backlog_and_stuck_messages():
    def stream(pending, idle, dead=0):
        return {"ok": True, "catalog_stream": {"length": 9, "pending": pending, "oldest_pending_idle_s": idle, "dead": dead}}

    assert codes(healthy(redis=stream(1000, 5))) == set()
    assert codes(healthy(redis=stream(1001, 5))) == {"stream_backlog"}
    assert codes(healthy(redis=stream(2, 601))) == {"stream_stuck"}
    assert codes(healthy(redis=stream(2, 599))) == set()
    assert codes(healthy(redis=stream(0, 0, dead=2))) == {"stream_dead_letters"}


def test_job_failing_twice_in_a_row_is_critical_but_once_is_not():
    jobs = healthy()["jobs"]
    jobs["graph_sync_full"] = {"status": "fail", "finished_at": NOW - H, "consecutive_failures": 1, "error": "x"}
    assert "job_failed_repeatedly" not in codes(healthy(jobs=jobs))
    jobs["graph_sync_full"]["consecutive_failures"] = 2
    alerts = evaluate_alerts(healthy(jobs=jobs), NOW)
    assert [a["level"] for a in alerts if a["code"] == "job_failed_repeatedly"] == ["critical"]


def test_nightly_job_older_than_26_hours_is_stale_and_weekly_jobs_have_their_own_limit():
    jobs = healthy()["jobs"]
    jobs["graph_sync_full"]["finished_at"] = NOW - 25.9 * H
    assert "job_stale" not in codes(healthy(jobs=jobs))
    jobs["graph_sync_full"]["finished_at"] = NOW - 26.1 * H
    assert "job_stale" in codes(healthy(jobs=jobs))
    jobs = healthy()["jobs"]
    jobs["chroma_reindex"]["finished_at"] = NOW - 7 * 24 * H           # weekly job, still within 8 days
    assert "job_stale" not in codes(healthy(jobs=jobs))
    jobs["chroma_reindex"]["finished_at"] = NOW - 9 * 24 * H
    assert "job_stale" in codes(healthy(jobs=jobs))


def test_job_that_never_ran_alerts_only_after_the_worker_has_been_up_for_a_day():
    jobs = healthy()["jobs"]
    jobs["co_purchase"] = None
    assert "job_never_ran" not in codes(healthy(jobs=jobs, worker_started_at=NOW - 5 * H))
    assert "job_never_ran" in codes(healthy(jobs=jobs, worker_started_at=NOW - 27 * H))
    assert "job_never_ran" not in codes(healthy(jobs=jobs, worker_started_at=None))


def test_dead_component_is_critical_and_does_not_hide_the_others():
    snap = healthy(chroma={"ok": False, "error": "ConnectError"})
    alerts = evaluate_alerts(snap, NOW)
    assert any(a["code"] == "chroma_down" and a["level"] == "critical" for a in alerts)


def test_build_health_shape_and_ok_flag_without_leaking_errors():
    doc = ops_health.build_health(healthy(), NOW)
    assert doc["ok"] is True and doc["alerts"] == []
    assert set(doc) >= {"generated_at", "ok", "alerts", "neo4j", "chroma", "mongo", "redis", "drift", "jobs"}
    assert doc["drift"]["neo4j_pct"] == 0.0
    bad = ops_health.build_health(healthy(neo4j={"ok": False, "error": "ServiceUnavailable"}), NOW)
    assert bad["ok"] is False and bad["alerts"][0]["code"] == "neo4j_down"


def test_neo4j_memory_parser_reads_container_memory_from_jmx():
    class Session:
        def run(self, cypher):
            if "Memory'" in cypher:
                return [{"attributes": {"HeapMemoryUsage": {"value": {"description": "java.lang.management.MemoryUsage",
                                                                       "properties": {"used": 262144000, "max": 536870912}}}}}]
            return [{"attributes": {"TotalMemorySize": {"value": 1073741824}, "FreeMemorySize": {"value": 190173184}}}]

    out = ops_health.neo4j_memory(Session())
    assert out["heap_used_mb"] == 250.0 and out["heap_max_mb"] == 512.0 and out["mem_limit_mb"] == 1024.0
    assert out["mem_pct"] == 82.3
