"""Tiny cron-like scheduler for the catalog-worker (U11).

Why not APScheduler / supercronic: neither is in the shared AI image (``requirements.txt``) and adding a
dependency means rebuilding the 5 GB image (torch). The need is small - ~10 jobs with minute/hour/weekday
granularity in Asia/Ho_Chi_Minh - so this is ~100 lines of standard library, fully unit tested, with the
two properties that matter: a Redis lock per job (only one instance runs it) and sequential execution
(jobs share ``graph_sync:lock`` and Neo4j's 1 GiB, so they must not overlap).
"""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from datetime import datetime, timedelta
from typing import Any, Callable, Mapping
from zoneinfo import ZoneInfo

from shared.redis_kit import keys, ops
from shared.redis_kit.lock import LockHeld, RedisLock

logger = logging.getLogger("ops_scheduler")

TZ_NAME = "Asia/Ho_Chi_Minh"
_MAX_LOOKAHEAD_MIN = 8 * 24 * 60


def local_tz() -> ZoneInfo:
    return ZoneInfo(TZ_NAME)


@dataclass(frozen=True)
class Schedule:
    """``minute`` always; ``hour`` / ``weekday`` (Mon=0..Sun=6) optional filters; or ``every_minutes`` (divides 60)."""

    minute: int | None = None
    hour: int | None = None
    weekday: int | None = None
    every_minutes: int | None = None

    def __post_init__(self) -> None:
        if self.every_minutes is not None:
            if self.every_minutes < 1 or 60 % self.every_minutes:
                raise ValueError("every_minutes must divide 60")
        elif self.minute is None or not 0 <= self.minute <= 59:
            raise ValueError("minute 0..59 required")
        if self.hour is not None and not 0 <= self.hour <= 23:
            raise ValueError("hour 0..23")
        if self.weekday is not None and not 0 <= self.weekday <= 6:
            raise ValueError("weekday 0..6")

    def matches(self, moment: datetime) -> bool:
        if self.every_minutes is not None:
            return moment.minute % self.every_minutes == 0
        return (moment.minute == self.minute
                and (self.hour is None or moment.hour == self.hour)
                and (self.weekday is None or moment.weekday() == self.weekday))

    def next_after(self, now: datetime) -> datetime:
        """First matching minute strictly after ``now`` (timezone of ``now``; Vietnam has no DST)."""

        probe = now.replace(second=0, microsecond=0) + timedelta(minutes=1)
        for _ in range(_MAX_LOOKAHEAD_MIN):
            if self.matches(probe):
                return probe
            probe += timedelta(minutes=1)
        raise ValueError("schedule never fires")

    def previous_at_or_before(self, now: datetime) -> datetime:
        probe = now.replace(second=0, microsecond=0)
        for _ in range(_MAX_LOOKAHEAD_MIN):
            if self.matches(probe):
                return probe
            probe -= timedelta(minutes=1)
        raise ValueError("schedule never fires")


@dataclass(frozen=True)
class JobSpec:
    name: str
    schedule: Schedule | None      # None = only runs when chained (``after``) or via --run-now
    run: Callable[[], Mapping[str, Any] | None]
    lock_ttl_s: int = 3600
    catch_up: bool = True              # run once at start if a slot was missed while the worker was down
    after: tuple[str, ...] = ()        # job names to run right after a successful run (chained, same thread)


class JobSkipped(Exception):
    """Raised by a job that found nothing to do / another sync holding the lock; recorded as ``skipped``."""

    def __init__(self, reason: str) -> None:
        super().__init__(reason)
        self.reason = reason


class Scheduler:
    def __init__(self, jobs: list[JobSpec], *, clock: Callable[[], datetime] | None = None,
                 lock_factory: Callable[[str, int], Any] | None = None,
                 record: Callable[..., Any] | None = None,
                 last_job: Callable[[str], Mapping[str, Any] | None] | None = None) -> None:
        self.jobs = {job.name: job for job in jobs}
        self._clock = clock or (lambda: datetime.now(local_tz()))
        self._lock_factory = lock_factory or (lambda key, ttl_s: RedisLock(key, ttl_ms=ttl_s * 1000, fail_open=True))
        self._record = record or ops.record_job
        self._last_job = last_job or (lambda name: ops.last_job(name))
        self.next_run: dict[str, datetime] = {}

    # -- planning ------------------------------------------------------------------------------------
    def start(self) -> list[str]:
        """Compute next runs; returns the jobs that missed their last slot while the worker was down."""

        now = self._clock()
        catch_up: list[str] = []
        for name, job in self.jobs.items():
            if job.schedule is None:
                continue
            self.next_run[name] = job.schedule.next_after(now)
            if not job.catch_up:
                continue
            last = self._last_job(name)
            if last is None:
                continue                     # never ran: do not launch every heavy job on first deploy
            slot = job.schedule.previous_at_or_before(now).timestamp()
            if (last.get("started_at") or 0) < slot - 60:
                catch_up.append(name)
        return catch_up

    def due(self, now: datetime | None = None) -> list[str]:
        now = now or self._clock()
        return [name for name, at in self.next_run.items() if at <= now]

    # -- execution -----------------------------------------------------------------------------------
    def run_job(self, name: str, *, chain: bool = True) -> str:
        """Run one job under its Redis lock. Returns ``ok`` / ``fail`` / ``skipped``. Never raises."""

        job = self.jobs[name]
        started = time.time()
        lock = self._lock_factory(keys.lock(f"ops_job_{name}"), job.lock_ttl_s)
        try:
            lock.acquire()
            if not lock.acquired and not getattr(lock, "degraded", False):
                self._record(name, started_at=started, status=ops.STATUS_SKIPPED, detail={"reason": "another_instance"})
                logger.info("job %s skipped: another instance holds the lock", name)
                return ops.STATUS_SKIPPED
            logger.info("job %s started", name)
            try:
                detail = job.run() or {}
            except JobSkipped as skip:
                self._record(name, started_at=started, status=ops.STATUS_SKIPPED, detail={"reason": skip.reason})
                logger.info("job %s skipped: %s", name, skip.reason)
                return ops.STATUS_SKIPPED
            except LockHeld as held:
                self._record(name, started_at=started, status=ops.STATUS_SKIPPED, detail={"reason": f"locked:{held}"})
                return ops.STATUS_SKIPPED
            except Exception as exc:  # noqa: BLE001 - job boundary: record + keep the scheduler alive
                message = f"{type(exc).__name__}: {str(exc)[:200]}"
                self._record(name, started_at=started, status=ops.STATUS_FAIL, error=message)
                logger.error("job %s failed: %s", name, message)
                return ops.STATUS_FAIL
            self._record(name, started_at=started, status=ops.STATUS_OK, detail=dict(detail))
            logger.info("job %s ok in %.1fs", name, time.time() - started)
        finally:
            lock.release()
        if chain:
            for follower in job.after:
                if follower in self.jobs:
                    self.run_job(follower, chain=False)
        return ops.STATUS_OK

    def tick(self, now: datetime | None = None) -> list[str]:
        """Run everything due (sequentially) and schedule the next slots. Returns the names run."""

        now = now or self._clock()
        ran: list[str] = []
        for name in self.due(now):
            schedule = self.jobs[name].schedule
            if schedule is not None:
                self.next_run[name] = schedule.next_after(now)
            self.run_job(name)
            ran.append(name)
        return ran
