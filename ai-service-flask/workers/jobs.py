"""Scheduled jobs of the catalog-worker (U11). Each job returns a small dict of counts (recorded in Redis).

Heavy jobs run as a SUBPROCESS (``python -m graph_sync ...``): their memory is returned to the OS when they
end, the worker container stays small, and the exact CLI an operator would type is what runs. The
Neo4j JVM heap is sampled while ``graph_sync_full`` runs so the peak is reported (U11 item: Neo4j RAM).
"""

from __future__ import annotations

import hashlib
import json
import logging
import os
import re
import subprocess
import sys
import tempfile
import threading
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, Callable, Mapping

from shared.redis_kit import keys, ops, popular, pubsub

from .scheduler import JobSkipped, JobSpec, Schedule

logger = logging.getLogger("ops_jobs")

SERVICE_ROOT = Path(__file__).resolve().parents[1]            # /app in the container
GRAPH_DIR = SERVICE_ROOT / "data" / "graph"
CONFLICT_RULES = GRAPH_DIR / "conflict_rules.yaml"
DICTIONARY_FILES = ("synonyms.yaml", "ingredient_aliases.yaml", "ingredient_groups.yaml", "ingredient_rules.yaml")
EXIT_LOCKED = 3
_COUNT_LINE = re.compile(r"^\s*([a-z_]+):\s*(-?\d+(?:\.\d+)?)\s*$")


def _env_path(name: str, default: str) -> str:
    return os.getenv(name, default)


# -- subprocess helper -------------------------------------------------------------------------------------

def run_cli(args: list[str], timeout_s: int, env_extra: Mapping[str, str] | None = None) -> dict[str, Any]:
    """Run ``python <args>`` in the service root. Returns exit code, whitelisted count lines, peak RSS (MiB)."""

    env = {**os.environ, **(env_extra or {})}
    started = time.monotonic()
    proc = subprocess.run([sys.executable, *args], cwd=str(SERVICE_ROOT), env=env, capture_output=True,
                          text=True, timeout=timeout_s, check=False)            # noqa: S603 - fixed argv
    counts: dict[str, float] = {}
    for line in (proc.stdout or "").splitlines():
        match = _COUNT_LINE.match(line)
        if match:
            value = float(match.group(2))
            counts[match.group(1)] = int(value) if value.is_integer() else value
    detail: dict[str, Any] = {"exit_code": proc.returncode, "seconds": round(time.monotonic() - started, 1),
                              "counts": counts}
    try:
        import resource

        detail["child_peak_rss_mb"] = round(resource.getrusage(resource.RUSAGE_CHILDREN).ru_maxrss / 1024.0, 1)
    except Exception:  # noqa: BLE001 - not on Windows hosts
        pass
    if proc.returncode not in (0, EXIT_LOCKED):
        tail = (proc.stderr or proc.stdout or "").strip().splitlines()[-3:]
        detail["error_tail"] = " | ".join(tail)[:300]
    return detail


def _require_ok(detail: dict[str, Any], what: str) -> dict[str, Any]:
    if detail["exit_code"] == EXIT_LOCKED:
        raise JobSkipped("graph_sync_lock_held")
    if detail["exit_code"] != 0:
        raise RuntimeError(f"{what} exit={detail['exit_code']} {detail.get('error_tail', '')}")
    return detail


# -- Neo4j heap sampler ------------------------------------------------------------------------------------

class Neo4jPeakSampler:
    """Polls Neo4j JMX every ``interval_s`` while a job runs; keeps the max of container RAM % and JVM heap."""

    def __init__(self, interval_s: float = 2.0, sampler: Callable[[], dict[str, Any]] | None = None) -> None:
        self.interval_s = interval_s
        self._sampler = sampler or self._default_sample
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None
        self.peak: dict[str, float] = {}

    @staticmethod
    def _default_sample() -> dict[str, Any]:
        from search_service.ops_health import _neo4j_driver, neo4j_memory

        driver = _neo4j_driver()
        try:
            with driver.session() as session:
                return neo4j_memory(session)
        finally:
            driver.close()

    def _loop(self) -> None:
        while not self._stop.is_set():
            try:
                sample = self._sampler()
                for key in ("mem_used_mb", "mem_pct", "heap_used_mb"):
                    value = sample.get(key)
                    if isinstance(value, (int, float)) and value > self.peak.get(key, 0.0):
                        self.peak[key] = float(value)
                if "mem_limit_mb" in sample:
                    self.peak["mem_limit_mb"] = float(sample["mem_limit_mb"])
            except Exception:  # noqa: BLE001 - sampling must never fail the job
                pass
            self._stop.wait(self.interval_s)

    def __enter__(self) -> "Neo4jPeakSampler":
        self._thread = threading.Thread(target=self._loop, daemon=True, name="neo4j-peak-sampler")
        self._thread.start()
        return self

    def __exit__(self, *exc: Any) -> None:
        self._stop.set()
        if self._thread:
            self._thread.join(timeout=5)


# -- jobs ---------------------------------------------------------------------------------------------------

def graph_sync_incremental() -> dict[str, Any]:
    """Safety net for lost events: everything changed in Mongo since the last run goes through the event pipeline."""

    from graph_sync import extract, incremental
    from graph_sync.config import load_settings

    from .catalog_sync import CatalogSyncer, LOCAL_SIMILARITY_MAX_IDS, SyncPlan

    settings = load_settings()
    now = datetime.now(timezone.utc)
    marker = ops.get_text(keys.OPS_GRAPH_SYNC_SINCE)
    try:
        since = extract.parse_since(marker) if marker else (now - timedelta(hours=2)).replace(tzinfo=None)
    except ValueError:
        since = (now - timedelta(hours=2)).replace(tzinfo=None)
    db = incremental.shared_db(settings)
    query = {"$or": [{extract.UPDATED_FIELD: {"$gte": since}}, {extract.TAG_UPDATED_FIELD: {"$gte": since}}]}
    docs = extract.with_retry(lambda: list(db["san_pham"].find(query, {"_id": 0, "ma_san_pham": 1})), "mongo changed ids")
    ids = [str(d["ma_san_pham"]) for d in docs if d.get("ma_san_pham") is not None]
    detail: dict[str, Any] = {"since": since.isoformat(timespec="seconds") + "Z", "changed": len(ids)}
    if ids:
        plan = SyncPlan(ids=ids, similarity="local" if len(ids) <= LOCAL_SIMILARITY_MAX_IDS else "none",
                        events=len(ids), types={"safety_net": len(ids)})
        detail.update(CatalogSyncer().apply(plan))
    # overlap by 2 minutes: an update that raced the query is picked up next hour
    ops.set_text(keys.OPS_GRAPH_SYNC_SINCE, (now - timedelta(minutes=2)).strftime("%Y-%m-%dT%H:%M:%SZ"))
    return detail


def graph_sync_full() -> dict[str, Any]:
    with Neo4jPeakSampler() as sampler:
        detail = _require_ok(run_cli(["-m", "graph_sync", "--full"], 3600), "graph_sync --full")
    detail["neo4j_peak"] = sampler.peak
    detail["invalidated"] = pubsub.publish_invalidate("catalog")
    return detail


def graph_eval() -> dict[str, Any]:
    """Re-run the tool layer of the Syna graph eval (no LLM) after the nightly sync; record the pass rate."""

    out = os.path.join(tempfile.gettempdir(), "graph_eval.json")
    detail = run_cli(["scripts/eval_graph_tools.py", "--out", out], 900)
    summary: dict[str, Any] = {}
    try:
        report = json.loads(Path(out).read_text(encoding="utf-8"))
        summary = {k: report.get(k) for k in ("constraint_pass_pct", "checks", "checks_passed", "cases",
                                              "cases_passed", "tool_ms_p50", "tool_ms_p95", "target_pct")}
    except (OSError, ValueError):
        pass
    summary["exit_code"] = detail["exit_code"]
    if not summary.get("checks"):
        raise RuntimeError(f"eval_graph_tools produced no report (exit={detail['exit_code']}) {detail.get('error_tail', '')}")
    if detail["exit_code"] != 0:       # below target: recorded as a failure so the health alert shows it
        raise RuntimeError(f"graph eval below target: {summary.get('constraint_pass_pct')}% < {summary.get('target_pct')}%")
    return summary


def co_purchase() -> dict[str, Any]:
    detail = _require_ok(run_cli(["-m", "graph_sync", "--derive", "co_purchase", "--days", "90"], 1800), "co_purchase")
    detail["invalidated"] = pubsub.publish_invalidate("catalog")
    return detail


def chroma_reindex() -> dict[str, Any]:
    detail = _require_ok(run_cli(["-m", "search_service.scripts.reindex_chroma"], 3600), "reindex_chroma")
    detail["invalidated"] = pubsub.publish_invalidate("catalog")
    return detail


def dictionary_export() -> dict[str, Any]:
    out = _env_path("OPS_DICTIONARY_OUT", "/export/search_dictionary.json")
    if not Path(out).parent.is_dir():
        raise JobSkipped("dictionary_output_not_mounted")
    detail = _require_ok(run_cli(["scripts/export_search_dictionary.py", "--out", out], 600), "export_search_dictionary")
    detail["invalidated"] = pubsub.publish_invalidate("dictionary")       # Flask reloads YAML + dictionary
    return detail


def popular_maintain() -> dict[str, Any]:
    result = popular.maintain()
    if result is None:
        raise RuntimeError("redis_unavailable")
    return result


def zero_result_report() -> dict[str, Any]:
    out = _env_path("OPS_REPORT_DIR", "/reports/search-metrics")
    Path(out).mkdir(parents=True, exist_ok=True)
    detail = _require_ok(run_cli(["scripts/search_metrics.py", "--zero-result-report", "--days", "7",
                                  "--min-count", "2", "--out", out], 900), "zero-result report")
    detail["out_dir"] = out
    return detail


def ctr_boost() -> dict[str, Any]:
    """U12 part A: 30-day CTR per (section, product) -> Redis ``search:ctr:v1:<section>``. Safe to run while the boost
    flag is off (the ranker ignores the hashes then); the job also records the data gate (impressions / clicks)."""

    from search_service import ctr
    from search_service.catalog import get_mongo_db
    from shared.redis_kit import client as redis_client

    db = get_mongo_db()
    if db is None:
        raise RuntimeError("mongo_unavailable")
    return ctr.run_job(db, redis_client.get_redis())


def _sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def config_watch() -> dict[str, Any]:
    """YAML changed -> conflict rules: ``--conflicts-only`` + invalidate; dictionary/aliases: invalidate (reload)."""

    detail: dict[str, Any] = {"conflict_rules": "unchanged", "dictionary": "unchanged"}
    if CONFLICT_RULES.is_file():
        current = _sha256(CONFLICT_RULES)
        stored = ops.get_text(keys.ops_filehash("conflict_rules"))
        if stored is None:
            ops.set_text(keys.ops_filehash("conflict_rules"), current, ttl_s=keys.OPS_JOB_TTL_S)
            detail["conflict_rules"] = "baseline_recorded"
        elif stored != current:
            run = _require_ok(run_cli(["-m", "graph_sync", "--conflicts-only"], 600), "graph_sync --conflicts-only")
            ops.set_text(keys.ops_filehash("conflict_rules"), current, ttl_s=keys.OPS_JOB_TTL_S)
            pubsub.publish_invalidate("catalog")
            detail["conflict_rules"] = "synced"
            detail["conflicts"] = run["counts"]
    digest = hashlib.sha256()
    for name in DICTIONARY_FILES:
        path = GRAPH_DIR / name
        if path.is_file():
            digest.update(name.encode() + b"\0" + path.read_bytes())
    dict_hash = digest.hexdigest()
    stored_dict = ops.get_text(keys.ops_filehash("dictionary"))
    if stored_dict is None:
        ops.set_text(keys.ops_filehash("dictionary"), dict_hash, ttl_s=keys.OPS_JOB_TTL_S)
        detail["dictionary"] = "baseline_recorded"
    elif stored_dict != dict_hash:
        pubsub.publish_invalidate("dictionary")
        ops.set_text(keys.ops_filehash("dictionary"), dict_hash, ttl_s=keys.OPS_JOB_TTL_S)
        detail["dictionary"] = "reloaded"
    return detail


def health_check() -> dict[str, Any]:
    from search_service.ops_health import build_health

    health = build_health(log_alerts=True)
    return {"ok": health["ok"], "alerts": len(health["alerts"]),
            "codes": sorted({a["code"] for a in health["alerts"]})}


def build_jobs() -> list[JobSpec]:
    """The job table of ``docs/ops/search-ops.md`` (Asia/Ho_Chi_Minh)."""

    return [
        JobSpec("graph_sync_incremental", Schedule(minute=10), graph_sync_incremental, lock_ttl_s=1800),
        JobSpec("graph_sync_full", Schedule(minute=30, hour=2), graph_sync_full, lock_ttl_s=3900, after=("graph_eval",)),
        JobSpec("graph_eval", None, graph_eval, lock_ttl_s=1200, catch_up=False),     # chained after graph_sync_full
        JobSpec("co_purchase", Schedule(minute=0, hour=3), co_purchase, lock_ttl_s=2000),
        JobSpec("chroma_reindex", Schedule(minute=30, hour=3, weekday=6), chroma_reindex, lock_ttl_s=3900),
        JobSpec("dictionary_export", Schedule(minute=0, hour=4), dictionary_export, lock_ttl_s=700),
        JobSpec("popular_maintain", Schedule(minute=10, hour=4), popular_maintain, lock_ttl_s=300),
        JobSpec("ctr_boost", Schedule(minute=0, hour=5), ctr_boost, lock_ttl_s=1200, catch_up=False),
        JobSpec("zero_result_report", Schedule(minute=0, hour=8, weekday=0), zero_result_report, lock_ttl_s=1000),
        JobSpec("config_watch", Schedule(every_minutes=5), config_watch, lock_ttl_s=700, catch_up=False),
        JobSpec("health_check", Schedule(every_minutes=5), health_check, lock_ttl_s=120, catch_up=False),
    ]
