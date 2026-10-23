"""Catalog change consumer (U11): Redis Stream ``catalog:changes:v1`` -> Neo4j + Chroma + cache invalidation.

Producers (PHP ``CatalogEvents`` / U07 ``TagCuration``) ``XADD`` one entry per changed product:
``{type, ma_san_pham, reason?, source?, ts}``. This consumer (group ``catalog_sync``)

1. collects events for ``CATALOG_SYNC_DEBOUNCE_S`` (30s) -> a *set* of ids (50 saves of one product = 1 sync);
2. takes the single-flight lock ``graph_sync:lock`` (waits while the nightly job holds it);
3. ``graph_sync.incremental.sync_ids`` (upsert / deactivate in Neo4j + local SIMILAR_TO);
4. ``reindex_chroma.reindex_ids`` (upsert visible, delete hidden / removed);
5. ``publish_invalidate`` once (generation bump drops search:rec / search:suggest / product:similar /
   cart:analyze / search:intent entries together) and asks PHP to rebuild the suggest index;
6. only then XACKs. Any failure -> messages stay pending and are retried (XAUTOCLAIM), dead-lettered after
   ``CATALOG_SYNC_MAX_DELIVERIES`` (default 6) to ``catalog:changes:v1:dead``.

Price / stock-only events skip the similarity step (they do not enter the score).
"""

from __future__ import annotations

import logging
import os
import time
import urllib.request
from dataclasses import dataclass, field
from typing import Any, Callable, Iterable

from shared.redis_kit import keys, ops, pubsub, streams

logger = logging.getLogger("catalog_sync")

GROUP = "catalog_sync"
JOB_NAME = "catalog_sync"
# Event types that cannot change SIMILAR_TO (price, stock).
SIMILARITY_FREE_TYPES = frozenset({"price_changed", "stock_changed"})
KNOWN_TYPES = frozenset({
    "product_created", "product_updated", "product_deleted", "product_hidden", "product_shown",
    "price_changed", "stock_changed", "bulk_import",
})
LOCAL_SIMILARITY_MAX_IDS = 50     # more than this -> leave SIMILAR_TO to the nightly --full


def _int_env(name: str, default: int) -> int:
    try:
        return max(1, int(os.getenv(name, str(default))))
    except ValueError:
        return default


@dataclass
class SyncPlan:
    """What a window of events asks for (pure data, built by :func:`build_plan`)."""

    ids: list[str] = field(default_factory=list)
    similarity: str = "none"          # "local" if any event can change SIMILAR_TO
    events: int = 0
    dropped: int = 0
    types: dict[str, int] = field(default_factory=dict)

    def __bool__(self) -> bool:
        return bool(self.ids)


def build_plan(messages: Iterable[streams.Message]) -> SyncPlan:
    """Debounce: any number of events -> the distinct ids (first-seen order) + whether similarity is needed."""

    from graph_sync.incremental import normalize_ids

    plan = SyncPlan()
    seen: dict[str, None] = {}
    for message in messages:
        fields = message.fields
        raw_id = fields.get("ma_san_pham") or fields.get("id") or ""
        ids = normalize_ids([raw_id])
        if not ids:
            plan.dropped += 1
            continue
        plan.events += 1
        event_type = fields.get("type", "product_updated")
        plan.types[event_type] = plan.types.get(event_type, 0) + 1
        seen.setdefault(ids[0], None)
        if event_type not in SIMILARITY_FREE_TYPES:
            plan.similarity = "local"
    plan.ids = list(seen)
    if len(plan.ids) > LOCAL_SIMILARITY_MAX_IDS:
        plan.similarity = "none"       # nightly full sync recomputes SIMILAR_TO
    return plan


def warm_suggest_index(base_url: str | None = None, timeout_s: float = 3.0) -> bool:
    """One real autocomplete request: PHP rebuilds ``search:suggestidx:v1`` after the response (U04).

    Best effort; the index also rebuilds on the first user request after the generation bump.
    """

    base = (base_url or os.getenv("PHP_INTERNAL_URL", "http://php-backend")).rstrip("/")
    url = f"{base}/index.php?r=api_smart_search&q=ma"
    try:
        with urllib.request.urlopen(url, timeout=timeout_s) as response:    # noqa: S310 - fixed internal URL
            response.read(2048)
        return True
    except Exception as exc:  # noqa: BLE001
        logger.info("suggest index warm-up skipped: %s", type(exc).__name__)
        return False


class CatalogSyncer:
    """Runs a :class:`SyncPlan`. Collaborators are injectable so unit tests need no database."""

    def __init__(self, *, graph_sync: Callable[..., dict[str, Any]] | None = None,
                 chroma: Callable[[list[str]], dict[str, int]] | None = None,
                 invalidate: Callable[[str, list[str]], bool] | None = None,
                 warm: Callable[[], bool] | None = None,
                 lock_factory: Callable[[], Any] | None = None,
                 record: Callable[..., Any] | None = None) -> None:
        self._graph_sync = graph_sync or self._default_graph_sync
        self._chroma = chroma or self._default_chroma
        self._invalidate = invalidate or (lambda scope, ids: pubsub.publish_invalidate(scope, ids))
        self._warm = warm or warm_suggest_index
        self._lock_factory = lock_factory or self._default_lock
        self._record = record or ops.record_job

    # -- default collaborators (real services) -------------------------------------------------------
    @staticmethod
    def _default_graph_sync(ids: list[str], similarity: str) -> dict[str, Any]:
        from graph_sync.config import load_settings
        from graph_sync.incremental import sync_ids

        return sync_ids(ids, load_settings(), similarity=similarity)

    @staticmethod
    def _default_chroma(ids: list[str]) -> dict[str, int]:
        from search_service.scripts.reindex_chroma import reindex_ids

        return reindex_ids(ids)

    @staticmethod
    def _default_lock() -> Any:
        from graph_sync.config import load_settings
        from shared.redis_kit.client import make_client
        from shared.redis_kit.lock import RedisLock

        settings = load_settings()
        client = make_client(settings.redis_url or None, socket_timeout=2.0, connect_timeout=2.0)
        return RedisLock(settings.lock_key, ttl_ms=15 * 60 * 1000, client=client, fail_open=True)

    # -- main entry ----------------------------------------------------------------------------------
    def acquire_lock(self, wait_s: float, poll_s: float = 2.0, sleep: Callable[[float], None] = time.sleep) -> Any:
        """Take ``graph_sync:lock``, waiting up to ``wait_s`` (a nightly job may hold it). ``LockHeld`` if not."""

        from shared.redis_kit.lock import LockHeld

        lock = self._lock_factory()
        deadline = time.monotonic() + wait_s
        while True:
            if lock.acquire() or lock.degraded:
                return lock
            if time.monotonic() >= deadline:
                raise LockHeld(getattr(lock, "key", "graph_sync:lock"))
            sleep(poll_s)

    def apply(self, plan: SyncPlan, lock_wait_s: float | None = None) -> dict[str, Any]:
        """Graph + Chroma + invalidate for the plan. Raises on any failure (caller leaves messages pending)."""

        started = time.time()
        wait = lock_wait_s if lock_wait_s is not None else _int_env("CATALOG_SYNC_LOCK_WAIT_S", 300)
        lock = self.acquire_lock(wait)
        graph: dict[str, Any] = {}
        chroma: dict[str, int] = {}
        try:
            from graph_sync.incremental import MAX_IDS

            for start in range(0, len(plan.ids), MAX_IDS):      # a bulk import may exceed one chunk
                chunk = plan.ids[start:start + MAX_IDS]
                for key, value in self._graph_sync(chunk, plan.similarity).items():
                    if isinstance(value, (int, float)) and not isinstance(value, bool):
                        graph[key] = graph.get(key, 0) + value
                for key, value in self._chroma(chunk).items():
                    chroma[key] = chroma.get(key, 0) + value
                lock.extend()
        finally:
            lock.release()
        # Invalidate only after both stores hold the new data, so a reader that refills the cache sees it.
        invalidated = bool(self._invalidate("catalog", plan.ids))
        warmed = bool(self._warm())
        detail = {"ids": len(plan.ids), "events": plan.events, "similarity": plan.similarity,
                  "types": plan.types, "neo4j_upserted": graph.get("upserted"),
                  "neo4j_deactivated": graph.get("deactivated_missing"),
                  "similar_written": graph.get("similar_written"), "chroma_upserted": chroma.get("upserted"),
                  "chroma_deleted": chroma.get("deleted"), "invalidated": invalidated, "suggest_warmed": warmed}
        self._record(JOB_NAME, started_at=started, status=ops.STATUS_OK, detail=detail)
        logger.info("catalog sync ok: %s", detail)
        return detail

    def handle(self, messages: list[streams.Message]) -> None:
        """Stream handler: debounce window -> plan -> apply. Returning normally acks the whole window."""

        plan = build_plan(messages)
        if plan.dropped:
            logger.warning("dropped %d catalog event(s) without a valid ma_san_pham", plan.dropped)
        if not plan:
            return
        try:
            self.apply(plan)
        except Exception as exc:
            self._record(JOB_NAME, started_at=time.time(), status=ops.STATUS_FAIL,
                         detail={"ids": len(plan.ids), "events": plan.events}, error=f"{type(exc).__name__}: {exc}")
            raise


def run_consumer(syncer: CatalogSyncer | None = None, stop: Callable[[], bool] | None = None,
                 on_cycle: Callable[[int], None] | None = None) -> None:
    """Blocking consume loop (own thread in the catalog-worker)."""

    syncer = syncer or CatalogSyncer()
    debounce_ms = _int_env("CATALOG_SYNC_DEBOUNCE_S", 30) * 1000
    consumer = streams.default_consumer_name()
    logger.info("catalog consumer started: stream=%s group=%s consumer=%s debounce_ms=%d",
                keys.CATALOG_CHANGES_STREAM, GROUP, consumer, debounce_ms)
    streams.consume(
        keys.CATALOG_CHANGES_STREAM, GROUP, consumer, lambda msgs: syncer.handle(msgs) or None,
        batch=1000, block_ms=2000, linger_ms=debounce_ms, claim_idle_ms=60_000,
        max_deliveries=_int_env("CATALOG_SYNC_MAX_DELIVERIES", 6), stop=stop, on_cycle=on_cycle,
    )
