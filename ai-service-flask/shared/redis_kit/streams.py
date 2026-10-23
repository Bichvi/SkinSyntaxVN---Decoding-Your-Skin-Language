"""Redis Streams helpers: bounded publish + consumer-group consume with ack, reclaim and dead-letter.

Producer:  ``publish(stream, fields, maxlen=10000)``  (never raises; ``None`` when Redis is down).
Consumer:  ``consume(stream, group, consumer, handler, ...)`` - at-least-once delivery:

* ``XGROUP CREATE ... MKSTREAM`` is idempotent (``BUSYGROUP`` ignored); new group reads from ``0`` so
  events published before the worker first started are not lost.
* ``handler(messages)`` gets a *batch* (``list[Message]``). Return ``None`` to ack everything, or an
  iterable of message ids to ack only those. Raising leaves the whole batch pending (retried).
* Pending messages idle > ``claim_idle_ms`` (default 60s) are reclaimed with ``XAUTOCLAIM`` and retried.
  A message already delivered ``max_deliveries`` (default 5) times is moved to ``<stream>:dead`` and acked.
* ``delete_on_ack=True`` also ``XDEL``s acked entries (buffer streams with a single group).
"""

from __future__ import annotations

import logging
import socket
import time
from dataclasses import dataclass, field
from typing import Any, Callable, Iterable, Mapping

from . import client as _client
from . import keys

logger = logging.getLogger("redis_kit.streams")

DEFAULT_MAXLEN = 10_000
DEAD_MAXLEN = 50_000
_META_PREFIX = "_dl_"


@dataclass(frozen=True)
class Message:
    id: str
    fields: dict[str, str]
    deliveries: int = 1


Handler = Callable[[list[Message]], "Iterable[str] | None"]


def _clean_fields(fields: Mapping[str, Any]) -> dict[str, str]:
    return {str(k): ("" if v is None else str(v)) for k, v in fields.items()}


def publish(stream: str, fields: Mapping[str, Any], *, maxlen: int = DEFAULT_MAXLEN,
            client: Any | None = None) -> str | None:
    """``XADD stream MAXLEN ~ maxlen * ...``. Returns the entry id, or ``None`` on any failure."""

    redis_client = client if client is not None else _client.get_redis()
    if redis_client is None:
        return None
    try:
        return redis_client.xadd(stream, _clean_fields(fields), maxlen=maxlen, approximate=True)
    except Exception as exc:
        _client.mark_down_if(client is None, exc)
        logger.warning("stream publish failed (%s): %s", stream, type(exc).__name__)
        return None


def publish_many(stream: str, items: Iterable[Mapping[str, Any]], *, maxlen: int = DEFAULT_MAXLEN,
                 client: Any | None = None) -> int:
    """Pipeline several ``XADD``s; returns how many were accepted (0 on failure)."""

    redis_client = client if client is not None else _client.get_redis()
    batch = [_clean_fields(i) for i in items]
    if redis_client is None or not batch:
        return 0
    try:
        pipe = redis_client.pipeline(transaction=False)
        for fields in batch:
            pipe.xadd(stream, fields, maxlen=maxlen, approximate=True)
        return len(pipe.execute())
    except Exception as exc:
        _client.mark_down_if(client is None, exc)
        logger.warning("stream publish_many failed (%s): %s", stream, type(exc).__name__)
        return 0


def ensure_group(client: Any, stream: str, group: str, start_id: str = "0") -> None:
    """Create the consumer group (and the stream) if missing."""

    try:
        client.xgroup_create(stream, group, id=start_id, mkstream=True)
    except Exception as exc:
        if "BUSYGROUP" not in str(exc):
            raise


def default_consumer_name() -> str:
    import os

    return f"{socket.gethostname()}-{os.getpid()}"


def _to_messages(entries: Iterable[tuple[str, Any]], deliveries: Mapping[str, int] | None = None) -> list[Message]:
    out: list[Message] = []
    for entry_id, data in entries:
        if data is None:  # entry was trimmed while pending
            continue
        out.append(Message(entry_id, dict(data), int((deliveries or {}).get(entry_id, 1))))
    return out


def _read_new(client: Any, stream: str, group: str, consumer: str, count: int, block_ms: int) -> list[Message]:
    resp = client.xreadgroup(group, consumer, {stream: ">"}, count=count,
                             block=block_ms if block_ms > 0 else None)
    if not resp:
        return []
    return _to_messages(resp[0][1])


def _reclaim(client: Any, stream: str, group: str, consumer: str, count: int, min_idle_ms: int) -> list[Message]:
    """Take over messages pending longer than ``min_idle_ms`` (crashed/failed consumers, including ourselves)."""

    result = client.xautoclaim(stream, group, consumer, min_idle_ms, start_id="0-0", count=count)
    entries = result[1] if len(result) > 1 else []
    ids = [e[0] for e in entries if e[1] is not None]
    dropped = [e[0] for e in entries if e[1] is None]
    if dropped:  # trimmed entries cannot be processed; drop them from the PEL
        client.xack(stream, group, *dropped)
    if not ids:
        return []
    deliveries = {}
    try:
        for row in client.xpending_range(stream, group, min=ids[0], max=ids[-1], count=len(ids) + 50):
            deliveries[row["message_id"]] = int(row["times_delivered"])
    except Exception as exc:
        logger.warning("xpending_range failed: %s", type(exc).__name__)
    return _to_messages([e for e in entries if e[1] is not None], deliveries)


def _dead_letter(client: Any, stream: str, group: str, messages: list[Message], dead_stream: str,
                 dead_maxlen: int, delete_on_ack: bool) -> None:
    now_ms = str(int(time.time() * 1000))
    for msg in messages:
        meta = {f"{_META_PREFIX}src_id": msg.id, f"{_META_PREFIX}group": group,
                f"{_META_PREFIX}deliveries": str(msg.deliveries), f"{_META_PREFIX}at": now_ms}
        client.xadd(dead_stream, {**msg.fields, **meta}, maxlen=dead_maxlen, approximate=True)
    ids = [m.id for m in messages]
    client.xack(stream, group, *ids)
    if delete_on_ack:
        client.xdel(stream, *ids)
    logger.error("moved %d message(s) to %s after too many failed deliveries", len(ids), dead_stream)


def poll_once(client: Any, stream: str, group: str, consumer: str, handler: Handler, *,
              batch: int = 100, block_ms: int = 2000, linger_ms: int = 0,
              claim_idle_ms: int = 60_000, max_deliveries: int = 5, dead_maxlen: int = DEAD_MAXLEN,
              delete_on_ack: bool = False, dead_stream: str | None = None, failure_pause_s: float = 1.0) -> int:
    """One read/handle/ack cycle. Returns the number of messages acked (0 when idle or the handler failed)."""

    dead = dead_stream or keys.dead_stream(stream)
    messages = _reclaim(client, stream, group, consumer, batch, claim_idle_ms) if claim_idle_ms > 0 else []
    poisoned = [m for m in messages if m.deliveries > max_deliveries]
    if poisoned:
        _dead_letter(client, stream, group, poisoned, dead, dead_maxlen, delete_on_ack)
        messages = [m for m in messages if m.deliveries <= max_deliveries]

    if len(messages) < batch:
        messages += _read_new(client, stream, group, consumer, batch - len(messages),
                              0 if messages else block_ms)
    if messages and linger_ms > 0 and len(messages) < batch:  # let a batch fill (e.g. 500 events or 5s)
        deadline = time.monotonic() + linger_ms / 1000.0
        while len(messages) < batch:
            remaining_ms = int((deadline - time.monotonic()) * 1000)
            if remaining_ms <= 0:
                break
            messages += _read_new(client, stream, group, consumer, batch - len(messages), min(remaining_ms, 500))
    if not messages:
        return 0

    try:
        result = handler(messages)
    except Exception as exc:
        logger.warning("stream handler failed for %d message(s) on %s: %s (retry after %dms idle)",
                       len(messages), stream, type(exc).__name__, claim_idle_ms)
        if failure_pause_s > 0:
            time.sleep(failure_pause_s)  # do not spin while a downstream (e.g. Atlas) is failing
        return 0
    ack_ids = [m.id for m in messages] if result is None else [str(i) for i in result]
    if ack_ids:
        client.xack(stream, group, *ack_ids)
        if delete_on_ack:
            client.xdel(stream, *ack_ids)
    return len(ack_ids)


def consume(stream: str, group: str, consumer: str, handler: Handler, *, batch: int = 100,
            block_ms: int = 2000, linger_ms: int = 0, claim_idle_ms: int = 60_000, max_deliveries: int = 5,
            dead_maxlen: int = DEAD_MAXLEN, delete_on_ack: bool = False, client: Any | None = None,
            stop: Callable[[], bool] | None = None, on_cycle: Callable[[int], None] | None = None) -> None:
    """Blocking consume loop. Survives Redis outages (reconnect with back-off); returns when ``stop()`` is true."""

    redis_client = client if client is not None else _client.make_client(socket_timeout=block_ms / 1000.0 + 5.0)
    backoff = 1.0
    group_ready = False
    while not (stop and stop()):
        try:
            if not group_ready:
                ensure_group(redis_client, stream, group)
                group_ready = True
                backoff = 1.0
            done = poll_once(redis_client, stream, group, consumer, handler, batch=batch, block_ms=block_ms,
                             linger_ms=linger_ms, claim_idle_ms=claim_idle_ms, max_deliveries=max_deliveries,
                             dead_maxlen=dead_maxlen, delete_on_ack=delete_on_ack)
            backoff = 1.0
            if on_cycle:
                on_cycle(done)
        except Exception as exc:  # connection lost, NOGROUP after a flush, ...
            group_ready = False
            logger.warning("stream consume error on %s: %s; retry in %.0fs", stream, type(exc).__name__, backoff)
            if on_cycle:
                on_cycle(-1)
            time.sleep(backoff)
            backoff = min(backoff * 2, 30.0)


def replay_dead(stream: str, *, count: int = 100, client: Any | None = None) -> int:
    """Move up to ``count`` dead-lettered entries back onto ``stream`` (operator tool after fixing the cause)."""

    redis_client = client if client is not None else _client.get_redis()
    if redis_client is None:
        return 0
    dead = keys.dead_stream(stream)
    moved = 0
    for entry_id, data in redis_client.xrange(dead, count=count):
        payload = {k: v for k, v in data.items() if not k.startswith(_META_PREFIX)}
        redis_client.xadd(stream, payload, maxlen=DEFAULT_MAXLEN * 10, approximate=True)
        redis_client.xdel(dead, entry_id)
        moved += 1
    return moved
