"""Ops status in Redis (U11): last run of every scheduled job + the markers the scheduler needs.

``ops:job:<name>:last`` JSON, TTL 30 days; ``ops:job:<name>:fails`` consecutive failures. Everything here is
best effort: Redis down -> functions return ``None``/``False`` and never raise (ops must not break jobs).
"""

from __future__ import annotations

import json
import logging
import time
from typing import Any, Iterable, Mapping

from . import client as _client
from . import keys

logger = logging.getLogger("redis_kit.ops")

STATUS_OK, STATUS_FAIL, STATUS_SKIPPED = "ok", "fail", "skipped"


def _redis(client: Any | None) -> Any | None:
    return client if client is not None else _client.get_redis()


def record_job(name: str, *, started_at: float, finished_at: float | None = None, status: str = STATUS_OK,
               detail: Mapping[str, Any] | None = None, error: str | None = None,
               client: Any | None = None) -> dict[str, Any]:
    """Write ``ops:job:<name>:last`` (+ bump / reset the failure streak). Returns the stored document."""

    finished = time.time() if finished_at is None else finished_at
    doc: dict[str, Any] = {
        "name": name, "started_at": round(started_at, 3), "finished_at": round(finished, 3),
        "duration_s": round(max(0.0, finished - started_at), 2), "status": status,
        "ok": status != STATUS_FAIL,
    }
    if detail:
        doc["detail"] = dict(detail)
    if error:
        doc["error"] = str(error)[:300]
    redis_client = _redis(client)
    if redis_client is None:
        return doc
    try:
        redis_client.set(keys.ops_job_last(name), json.dumps(doc, ensure_ascii=False, default=str),
                         ex=keys.OPS_JOB_TTL_S)
        if status == STATUS_FAIL:
            redis_client.incr(keys.ops_job_fails(name))
            redis_client.expire(keys.ops_job_fails(name), keys.OPS_JOB_TTL_S)
        elif status == STATUS_OK:
            redis_client.set(keys.ops_job_fails(name), 0, ex=keys.OPS_JOB_TTL_S)
    except Exception as exc:
        _client.mark_down_if(client is None, exc)
        logger.warning("could not record job %s: %s", name, type(exc).__name__)
    return doc


def last_job(name: str, *, client: Any | None = None) -> dict[str, Any] | None:
    redis_client = _redis(client)
    if redis_client is None:
        return None
    try:
        raw = redis_client.get(keys.ops_job_last(name))
        fails = redis_client.get(keys.ops_job_fails(name))
    except Exception as exc:
        _client.mark_down_if(client is None, exc)
        return None
    if not raw:
        return None
    try:
        doc = json.loads(raw)
    except (TypeError, ValueError):
        return None
    if isinstance(doc, dict):
        doc["consecutive_failures"] = int(fails or 0)
        return doc
    return None


def all_jobs(names: Iterable[str], *, client: Any | None = None) -> dict[str, dict[str, Any] | None]:
    return {name: last_job(name, client=client) for name in names}


def get_text(key: str, *, client: Any | None = None) -> str | None:
    redis_client = _redis(client)
    if redis_client is None:
        return None
    try:
        value = redis_client.get(key)
    except Exception as exc:
        _client.mark_down_if(client is None, exc)
        return None
    return None if value is None else (value.decode() if isinstance(value, bytes) else str(value))


def set_text(key: str, value: str, *, ttl_s: int | None = None, client: Any | None = None) -> bool:
    redis_client = _redis(client)
    if redis_client is None:
        return False
    try:
        redis_client.set(key, value, ex=ttl_s)
        return True
    except Exception as exc:
        _client.mark_down_if(client is None, exc)
        return False
