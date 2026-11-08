"""Search-stack health for operations (U11): Neo4j / Chroma / Redis / Mongo counts, stream backlog, job status.

``collect_snapshot()`` gathers raw numbers (each collector isolated: one dead service never hides the rest),
``evaluate_alerts()`` is PURE (thresholds in -> alerts out) and unit tested, ``build_health()`` combines them.
Served by ``GET /api/ops/health`` (internal token) and logged every 5 minutes by the catalog-worker.

No secrets / connection strings / personal data in the output - counts, percentages and job outcomes only.
"""

from __future__ import annotations

import logging
import os
import time
from datetime import datetime, timezone
from typing import Any, Callable, Mapping

from shared.redis_kit import keys, ops

logger = logging.getLogger("ops_health")

# name -> (max age in seconds before "not run", human schedule). Nightly jobs: 26h as required by U11.
H = 3600
EXPECTED_JOBS: dict[str, tuple[int, str]] = {
    "graph_sync_incremental": (3 * H, "hourly :10"),
    "graph_sync_full": (26 * H, "02:30 daily"),
    "graph_eval": (26 * H, "after graph_sync_full"),
    "co_purchase": (26 * H, "03:00 daily"),
    "chroma_reindex": (8 * 24 * H, "Sunday 03:30"),
    "dictionary_export": (26 * H, "04:00 daily"),
    "popular_maintain": (26 * H, "04:10 daily"),
    "ctr_boost": (26 * H, "05:00 daily"),
    "zero_result_report": (8 * 24 * H, "Monday 08:00"),
}

DEFAULTS: dict[str, float] = {
    "neo4j_mem_pct": 90.0,
    "drift_pct": 1.0,
    "pending_max": 1000,
    "pending_stuck_s": 600,
    "consecutive_failures": 2,
    "worker_grace_s": 26 * H,
}


def _thresholds(overrides: Mapping[str, float] | None) -> dict[str, float]:
    out = dict(DEFAULTS)
    out.update(overrides or {})
    return out


def _alert(code: str, level: str, message: str) -> dict[str, str]:
    return {"code": code, "level": level, "message": message}


def drift_pct(actual: float | None, expected: float | None) -> float | None:
    if actual is None or expected is None:
        return None
    if expected <= 0:
        return 0.0 if actual <= 0 else 100.0
    return abs(actual - expected) / expected * 100.0


def evaluate_alerts(snapshot: Mapping[str, Any], now: float | None = None,
                    thresholds: Mapping[str, float] | None = None) -> list[dict[str, str]]:
    """Pure: raw snapshot -> alerts (``level`` = ``warning`` | ``critical``)."""

    t = _thresholds(thresholds)
    now = time.time() if now is None else now
    alerts: list[dict[str, str]] = []

    for component in ("neo4j", "chroma", "redis", "mongo"):
        block = snapshot.get(component) or {}
        if block and block.get("ok") is False:
            alerts.append(_alert(f"{component}_down", "critical", f"{component} không truy vấn được ({block.get('error', 'unknown')})"))

    neo = snapshot.get("neo4j") or {}
    pct = neo.get("mem_pct")
    if isinstance(pct, (int, float)) and pct > t["neo4j_mem_pct"]:
        alerts.append(_alert("neo4j_ram_high", "warning",
                             f"RAM Neo4j {pct:.1f}% > {t['neo4j_mem_pct']:.0f}% giới hạn container "
                             "(không tự tăng giới hạn - xem docs/ops/search-ops.md mục Neo4j RAM)"))

    mongo = snapshot.get("mongo") or {}
    for name, block, expected_key in (("neo4j", neo, "neo4j_expected"), ("chroma", snapshot.get("chroma") or {}, "chroma_expected")):
        pct_drift = drift_pct(block.get("count"), mongo.get(expected_key))
        if pct_drift is not None and pct_drift > t["drift_pct"]:
            alerts.append(_alert(f"{name}_count_drift", "warning",
                                 f"Số sản phẩm {name} lệch {pct_drift:.2f}% so với Mongo "
                                 f"({block.get('count')} vs {mongo.get(expected_key)}) > {t['drift_pct']:.0f}%"))

    stream = (snapshot.get("redis") or {}).get("catalog_stream") or {}
    pending = stream.get("pending")
    if isinstance(pending, (int, float)) and pending > t["pending_max"]:
        alerts.append(_alert("stream_backlog", "warning", f"catalog:changes:v1 có {pending} message đang chờ (XPENDING) > {t['pending_max']:.0f}"))
    idle = stream.get("oldest_pending_idle_s")
    if isinstance(idle, (int, float)) and idle > t["pending_stuck_s"]:
        alerts.append(_alert("stream_stuck", "warning", f"Message treo {idle:.0f}s > {t['pending_stuck_s']:.0f}s chưa được xử lý"))
    if (stream.get("dead") or 0) > 0:
        alerts.append(_alert("stream_dead_letters", "warning",
                             f"{stream.get('dead')} sự kiện nằm trong catalog:changes:v1:dead (xem lại rồi replay)"))

    worker_started = snapshot.get("worker_started_at")
    jobs = snapshot.get("jobs") or {}
    for name, (max_age, _schedule) in EXPECTED_JOBS.items():
        last = jobs.get(name)
        if last is None:
            if isinstance(worker_started, (int, float)) and now - worker_started > max(max_age, t["worker_grace_s"]):
                alerts.append(_alert("job_never_ran", "warning", f"Job {name} chưa từng chạy dù worker đã bật > {max(max_age, t['worker_grace_s']) / H:.0f}h"))
            continue
        if (last.get("consecutive_failures") or 0) >= t["consecutive_failures"]:
            alerts.append(_alert("job_failed_repeatedly", "critical",
                                 f"Job {name} thất bại {last.get('consecutive_failures')} lần liên tiếp: {last.get('error', '')[:120]}"))
        finished = last.get("finished_at")
        if isinstance(finished, (int, float)) and now - finished > max_age:
            alerts.append(_alert("job_stale", "warning", f"Job {name} không hoàn tất trong {(now - finished) / H:.1f}h (> {max_age / H:.0f}h)"))
    return alerts


# --------------------------------------------------------------------------------------------------------
# Collectors (I/O). Each returns a dict with ``ok`` and never raises.
# --------------------------------------------------------------------------------------------------------

def _guard(fn: Callable[[], dict[str, Any]]) -> dict[str, Any]:
    try:
        out = fn()
        out.setdefault("ok", True)
        return out
    except Exception as exc:  # noqa: BLE001 - health must degrade per component
        return {"ok": False, "error": type(exc).__name__}


def _neo4j_driver() -> Any:
    from neo4j import GraphDatabase

    driver = GraphDatabase.driver(
        os.getenv("NEO4J_URI", "bolt://neo4j:7687").strip(),
        auth=(os.getenv("NEO4J_USER", "neo4j"), os.getenv("NEO4J_PASSWORD", "")),
        connection_timeout=3.0,
    )
    return driver


def neo4j_memory(session: Any) -> dict[str, Any]:
    """JVM heap + container-aware OS memory from JMX (``dbms.queryJmx``): works without Docker access."""

    heap_used = heap_max = total = free = None
    for row in session.run("CALL dbms.queryJmx('java.lang:type=Memory')"):
        value = row["attributes"].get("HeapMemoryUsage", {}).get("value") or {}
        usage = value.get("properties", value) if isinstance(value, dict) else {}    # composite: {description, properties:{used,max,..}}
        heap_used, heap_max = usage.get("used"), usage.get("max")
    for row in session.run("CALL dbms.queryJmx('java.lang:type=OperatingSystem')"):
        attrs = row["attributes"]
        total = attrs.get("TotalMemorySize", {}).get("value")
        free = attrs.get("FreeMemorySize", {}).get("value")
    mb = lambda v: None if v is None else round(v / 1048576, 1)  # noqa: E731
    out: dict[str, Any] = {"heap_used_mb": mb(heap_used), "heap_max_mb": mb(heap_max),
                           "mem_limit_mb": mb(total)}
    if total and free is not None:
        out["mem_used_mb"] = mb(total - free)
        out["mem_pct"] = round((total - free) / total * 100.0, 1)
    return out


def collect_neo4j() -> dict[str, Any]:
    def run() -> dict[str, Any]:
        driver = _neo4j_driver()
        try:
            with driver.session() as session:
                active = session.run("MATCH (p:Product {active: true}) RETURN count(p) AS n").single()["n"]
                similar = session.run("MATCH ()-[r:SIMILAR_TO]->() RETURN count(r) AS n").single()["n"]
                out = {"count": int(active), "similar_to": int(similar)}
                try:
                    out.update(neo4j_memory(session))
                except Exception as exc:  # noqa: BLE001 - JMX may be disabled
                    out["memory_error"] = type(exc).__name__
                return out
        finally:
            driver.close()

    return _guard(run)


def collect_chroma() -> dict[str, Any]:
    def run() -> dict[str, Any]:
        import chromadb

        from search_service import config

        client = chromadb.HttpClient(host=config.CHROMA_HOST, port=config.CHROMA_PORT) if config.CHROMA_HOST else None
        if client is None:
            raise RuntimeError("chroma_host_not_configured")
        return {"count": int(client.get_collection(config.CATALOG_COLLECTION).count())}

    return _guard(run)


def collect_mongo() -> dict[str, Any]:
    def run() -> dict[str, Any]:
        from graph_sync.transform import HIDDEN_STATUSES
        from search_service import catalog

        db = catalog.get_mongo_db()
        if db is None:
            raise RuntimeError("mongo_unavailable")
        coll = db["san_pham"]
        return {
            "total_products": int(coll.count_documents({})),
            "neo4j_expected": int(coll.count_documents({"trang_thai": {"$nin": list(HIDDEN_STATUSES)}})),
            "chroma_expected": int(coll.count_documents({"trang_thai": {"$nin": list(catalog._HIDDEN)}})),
        }

    return _guard(run)


def collect_redis(client: Any | None = None) -> dict[str, Any]:
    def run() -> dict[str, Any]:
        from shared.redis_kit.client import get_redis

        redis_client = client if client is not None else get_redis()
        if redis_client is None:
            raise RuntimeError("redis_unavailable")
        info = redis_client.info("memory")
        out: dict[str, Any] = {"used_memory_mb": round(int(info.get("used_memory", 0)) / 1048576, 1)}
        stream = keys.CATALOG_CHANGES_STREAM
        block: dict[str, Any] = {"length": int(redis_client.xlen(stream)), "pending": 0,
                                 "oldest_pending_idle_s": 0, "dead": int(redis_client.xlen(keys.CATALOG_CHANGES_DEAD))}
        try:
            summary = redis_client.xpending(stream, "catalog_sync")
            block["pending"] = int(summary.get("pending") or 0)
            if block["pending"]:
                rows = redis_client.xpending_range(stream, "catalog_sync", min="-", max="+", count=100)
                block["oldest_pending_idle_s"] = round(max((r.get("time_since_delivered", 0) for r in rows), default=0) / 1000.0, 1)
        except Exception as exc:  # noqa: BLE001 - group not created yet (worker never started)
            if "NOGROUP" not in str(exc):
                raise
            block["group"] = "missing"
        out["catalog_stream"] = block
        return out

    return _guard(run)


def collect_snapshot(redis_client: Any | None = None) -> dict[str, Any]:
    from shared.redis_kit.client import get_redis

    redis_client = redis_client if redis_client is not None else get_redis()
    started = ops.get_text(keys.OPS_WORKER_STARTED, client=redis_client)
    return {
        "neo4j": collect_neo4j(),
        "chroma": collect_chroma(),
        "mongo": collect_mongo(),
        "redis": collect_redis(redis_client),
        "jobs": ops.all_jobs([*EXPECTED_JOBS, "catalog_sync", "config_watch", "health_check"], client=redis_client),
        "worker_started_at": float(started) if started and started.replace(".", "", 1).isdigit() else None,
    }


def build_health(snapshot: Mapping[str, Any] | None = None, now: float | None = None,
                 log_alerts: bool = False) -> dict[str, Any]:
    snap = dict(snapshot) if snapshot is not None else collect_snapshot()
    now = time.time() if now is None else now
    alerts = evaluate_alerts(snap, now)
    mongo = snap.get("mongo") or {}
    drift = {
        "neo4j_pct": drift_pct((snap.get("neo4j") or {}).get("count"), mongo.get("neo4j_expected")),
        "chroma_pct": drift_pct((snap.get("chroma") or {}).get("count"), mongo.get("chroma_expected")),
    }
    jobs_view: dict[str, Any] = {}
    for name, doc in (snap.get("jobs") or {}).items():
        if doc is None:
            jobs_view[name] = None
            continue
        jobs_view[name] = {k: doc.get(k) for k in ("status", "ok", "started_at", "finished_at", "duration_s",
                                                   "consecutive_failures", "detail", "error")}
    out = {
        "generated_at": datetime.fromtimestamp(now, tz=timezone.utc).isoformat(timespec="seconds"),
        "ok": not any(a["level"] == "critical" for a in alerts),
        "alerts": alerts,
        "neo4j": snap.get("neo4j"), "chroma": snap.get("chroma"), "mongo": snap.get("mongo"),
        "redis": snap.get("redis"), "drift": drift, "jobs": jobs_view,
    }
    for alert in alerts if log_alerts else ():
        logger.warning("ops alert %s: %s", alert["code"], alert["message"])
    return out
