"""catalog-worker (U11): the always-on process that keeps Neo4j / Chroma / caches in step with the catalog.

    python -m workers.catalog_worker                 # consumer thread + scheduler loop (compose service)
    python -m workers.catalog_worker --list          # jobs, schedules, next runs
    python -m workers.catalog_worker --run-now graph_sync_full     # run one job once, record status, exit
    python -m workers.catalog_worker --replay-dead   # move catalog:changes:v1:dead back onto the stream

Threads: ``catalog_sync`` consumer (stream ``catalog:changes:v1``, 30s debounce) and the main thread
running the scheduler (jobs sequential, one Redis lock per job). Heartbeat file for the compose healthcheck.
"""

from __future__ import annotations

import argparse
import logging
import os
import signal
import sys
import threading
import time

from shared.redis_kit import keys, ops, streams

from . import catalog_sync, jobs
from .scheduler import Scheduler

logger = logging.getLogger("catalog_worker")
HEARTBEAT_FILE = os.getenv("CATALOG_WORKER_HEARTBEAT", "/tmp/catalog_worker.heartbeat")
TICK_S = 15


def _touch_heartbeat() -> None:
    try:
        with open(HEARTBEAT_FILE, "a"):
            pass
        os.utime(HEARTBEAT_FILE, None)
    except OSError:
        pass


def _mark_started() -> None:
    from shared.redis_kit.client import get_redis

    client = get_redis()
    if client is None:
        return
    try:
        client.set(keys.OPS_WORKER_STARTED, str(int(time.time())), nx=True, ex=keys.OPS_JOB_TTL_S)
    except Exception as exc:  # noqa: BLE001
        logger.warning("could not record worker start: %s", type(exc).__name__)


def _warm_embedding() -> None:
    """Load the (static) embedding model once, off the request path: the first Chroma upsert would otherwise
    spend ~10s on it inside the 60s event-to-index budget."""

    try:
        from shared.embedding_provider import get_embedding_function

        get_embedding_function()
    except Exception as exc:  # noqa: BLE001 - warm-up only
        logger.warning("embedding warm-up failed: %s", type(exc).__name__)


def build_scheduler() -> Scheduler:
    return Scheduler(jobs.build_jobs())


def run(stop: threading.Event, *, consumer: bool = True) -> None:
    scheduler = build_scheduler()
    _mark_started()
    if consumer:
        threading.Thread(target=_warm_embedding, daemon=True, name="embedding-warmup").start()
        thread = threading.Thread(
            target=catalog_sync.run_consumer, kwargs={"stop": stop.is_set, "on_cycle": lambda _n: _touch_heartbeat()},
            daemon=True, name="catalog-consumer")
        thread.start()
    for name in scheduler.start():
        logger.warning("catching up missed job %s", name)
        scheduler.next_run[name] = scheduler._clock()          # noqa: SLF001 - run at the first tick
    logger.info("catalog worker started; next runs: %s",
                {n: at.strftime("%a %H:%M") for n, at in sorted(scheduler.next_run.items(), key=lambda kv: kv[1])[:4]})
    while not stop.is_set():
        _touch_heartbeat()
        scheduler.tick()
        stop.wait(TICK_S)


def main(argv: list[str] | None = None) -> int:
    logging.basicConfig(level=os.getenv("SEARCH_LOG_LEVEL", "INFO").upper(),
                        format="%(asctime)s %(levelname)s %(name)s %(message)s")
    for noisy in ("pymongo", "neo4j", "httpx", "urllib3"):
        logging.getLogger(noisy).setLevel(logging.WARNING)
    parser = argparse.ArgumentParser(prog="python -m workers.catalog_worker")
    parser.add_argument("--run-now", metavar="JOB", help="run one job now (status recorded in Redis) and exit")
    parser.add_argument("--list", action="store_true", help="list jobs and next runs")
    parser.add_argument("--replay-dead", action="store_true", help="replay catalog:changes:v1:dead")
    parser.add_argument("--no-consumer", action="store_true", help="scheduler only (debug)")
    args = parser.parse_args(argv)

    if args.replay_dead:
        logger.info("replayed %d dead-lettered event(s)", streams.replay_dead(keys.CATALOG_CHANGES_STREAM, count=1000))
        return 0
    scheduler = build_scheduler()
    if args.list:
        scheduler.start()
        for name, job in scheduler.jobs.items():
            nxt = scheduler.next_run.get(name)
            print(f"{name:26s} next={nxt.isoformat(timespec='minutes') if nxt else 'chained'}")
        return 0
    if args.run_now:
        if args.run_now not in scheduler.jobs:
            print(f"unknown job {args.run_now!r}; choose one of: {', '.join(scheduler.jobs)}", file=sys.stderr)
            return 2
        outcome = scheduler.run_job(args.run_now)
        print(f"{args.run_now}: {outcome}")
        return 0 if outcome in (ops.STATUS_OK, ops.STATUS_SKIPPED) else 1

    stop = threading.Event()
    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, lambda *_: stop.set())
    _touch_heartbeat()
    run(stop, consumer=not args.no_consumer)
    return 0


if __name__ == "__main__":
    sys.exit(main())
