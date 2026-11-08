"""Search-events flusher: Redis Stream ``search:events:v1`` -> Mongo ``search_events`` in batches.

PHP (``SearchEventTracker``) ``XADD``s each beacon event; this worker (consumer group ``flusher``)
collects up to ``SEARCH_EVENTS_FLUSH_BATCH`` (500) events or ``SEARCH_EVENTS_FLUSH_INTERVAL_S`` (5s),
writes them with one ``insert_many(ordered=False)`` and only then ``XACK``s (+ ``XDEL``s) them.

* Idempotent: ``_id = "sev1-<stream id>"``, so a retry after a partial failure cannot duplicate (E11000 = ok).
* Mongo failure -> handler raises -> messages stay pending, reclaimed after 60s, dead-lettered to
  ``search:events:v1:dead`` after 5 deliveries (replay: ``python -m workers.search_events_flusher --replay-dead``).
* Invalid entries are dropped (acked) and counted; the document shape matches ``SearchEventTracker::buildDocs``.

Run: ``python -m workers.search_events_flusher`` (compose service ``search-worker``).
"""

from __future__ import annotations

import logging
import os
import re
import signal
import sys
import threading
import time
from datetime import datetime, timezone
from typing import Any, Iterable

from shared.redis_kit import keys, streams

logger = logging.getLogger("search_events_flusher")

STREAM = keys.SEARCH_EVENTS_STREAM
GROUP = "flusher"
COLLECTION = "search_events"
HEARTBEAT_FILE = os.getenv("SEARCH_WORKER_HEARTBEAT", "/tmp/search_worker.heartbeat")

ACTIONS = {"impression", "click", "add_to_cart", "chip_click"}
SECTIONS = {"category_alt", "same_brand", "personalized", "semantic", "trending", "best_sellers", "similar", "routine", "conflict", "chips"}

# U12 optional fields (the PHP beacon validates them; this is the second line of defence before they reach Mongo).
PRODUCT_ID_RE = re.compile(r"^[A-Za-z0-9_-]{1,32}$")
EXPERIMENT_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,39}$")
VARIANT_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,19}$")
MAX_PRODUCT_IDS = 20
MAX_POSITION = 50


def _int_env(name: str, default: int) -> int:
    try:
        return max(1, int(os.getenv(name, str(default))))
    except ValueError:
        return default


def build_doc(message: streams.Message) -> dict[str, Any] | None:
    """Stream entry -> Mongo document (same fields as the PHP direct insert); ``None`` if invalid."""

    f = message.fields
    action, section = f.get("action", ""), f.get("section", "")
    q_norm, session_hash = f.get("q_norm", ""), f.get("session_hash", "")
    if action not in ACTIONS or section not in SECTIONS or not q_norm or not session_hash:
        return None
    try:
        ts = datetime.fromtimestamp(int(f["ts_ms"]) / 1000.0, tz=timezone.utc)
    except (KeyError, ValueError, OverflowError, OSError):
        return None
    doc: dict[str, Any] = {
        "_id": f"sev1-{message.id}",
        "ts": ts,
        "q_norm": q_norm[:100],
        "section": section,
        "product_id": f.get("product_id", ""),
        "action": action,
        "user_hash": f.get("user_hash") or None,
        "session_hash": session_hash,
    }
    if f.get("chip"):
        doc["chip"] = f["chip"]
    # U12: ordered ids shown by an impression (position = index + 1), rank of a clicked/added card, A/B arm.
    ids = [p for p in (f.get("product_ids") or "").split(",") if PRODUCT_ID_RE.match(p)][:MAX_PRODUCT_IDS]
    if action == "impression" and ids:
        doc["product_ids"] = ids
    position = f.get("position") or ""
    if action in ("click", "add_to_cart") and position.isdigit() and 1 <= int(position) <= MAX_POSITION:
        doc["position"] = int(position)
    experiment, variant = f.get("experiment") or "", f.get("variant") or ""
    if EXPERIMENT_RE.match(experiment) and VARIANT_RE.match(variant):
        doc["experiment"], doc["variant"] = experiment, variant
    return doc


def flush(messages: list[streams.Message], collection: Any) -> Iterable[str]:
    """Insert a batch; returns the ids that may be acked. Raises when Mongo is unavailable."""

    docs: list[dict[str, Any]] = []
    for message in messages:
        doc = build_doc(message)
        if doc is None:
            logger.warning("dropping invalid search event %s", message.id)
        else:
            docs.append(doc)
    if docs:
        try:
            collection.insert_many(docs, ordered=False)
        except Exception as exc:
            details = getattr(exc, "details", None)  # BulkWriteError.details is a dict; AutoReconnect's is not
            errors = details.get("writeErrors") if isinstance(details, dict) else None
            if errors and all(e.get("code") == 11000 for e in errors):
                logger.info("flush: %d duplicate event(s) already stored", len(errors))  # idempotent retry
            else:
                raise
    logger.info("flushed %d event(s) (%d dropped)", len(docs), len(messages) - len(docs))
    return [m.id for m in messages]


def _mongo_collection() -> Any:
    from search_service.catalog import get_mongo_db

    db = get_mongo_db()
    if db is None:
        raise RuntimeError("mongo_unavailable")
    return db[COLLECTION]


def _touch_heartbeat() -> None:
    try:
        with open(HEARTBEAT_FILE, "a"):
            pass
        os.utime(HEARTBEAT_FILE, None)
    except OSError:
        pass


def run(stop: threading.Event | None = None) -> None:
    stop = stop or threading.Event()
    batch = _int_env("SEARCH_EVENTS_FLUSH_BATCH", 500)
    linger_ms = _int_env("SEARCH_EVENTS_FLUSH_INTERVAL_S", 5) * 1000
    consumer = streams.default_consumer_name()
    logger.info("search events flusher started: stream=%s group=%s consumer=%s batch=%d linger_ms=%d",
                STREAM, GROUP, consumer, batch, linger_ms)

    def handler(messages: list[streams.Message]) -> Iterable[str]:
        return flush(messages, _mongo_collection())

    streams.consume(
        STREAM, GROUP, consumer, handler,
        batch=batch, block_ms=2000, linger_ms=linger_ms, claim_idle_ms=60_000, max_deliveries=5,
        delete_on_ack=True, stop=stop.is_set, on_cycle=lambda _n: _touch_heartbeat(),
    )


def main(argv: list[str] | None = None) -> int:
    logging.basicConfig(level=os.getenv("SEARCH_LOG_LEVEL", "INFO").upper(),
                        format="%(asctime)s %(levelname)s %(name)s %(message)s")
    logging.getLogger("pymongo").setLevel(logging.WARNING)
    args = list(sys.argv[1:] if argv is None else argv)
    if "--replay-dead" in args:
        moved = streams.replay_dead(STREAM, count=1000)
        logger.info("replayed %d dead-lettered event(s)", moved)
        return 0
    stop = threading.Event()
    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, lambda *_: stop.set())
    _touch_heartbeat()
    run(stop)
    return 0


if __name__ == "__main__":
    sys.exit(main())
