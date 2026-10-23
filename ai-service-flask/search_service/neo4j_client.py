"""Lazy Neo4j driver singleton with a short circuit breaker.

``get_driver()`` returns None when the graph is disabled, unconfigured or recently down.
A failed connect (or a branch reporting a failure via ``report_failure``) opens the breaker
for ``RETRY_SECONDS`` so graph branches degrade instantly instead of waiting on timeouts;
after that the next caller retries. Drivers must be created after gunicorn forks (never at
import time) — ``warm_up`` does it in ``post_fork``.
"""

from __future__ import annotations

import logging
import os
import time
from concurrent.futures import Future, ThreadPoolExecutor
from concurrent.futures import TimeoutError as FutureTimeout
from threading import Lock, Thread
from typing import Any, Callable, TypeVar

from . import config

logger = logging.getLogger(__name__)
RETRY_SECONDS = 10.0
QUERY_TIMEOUT_S = 0.7
# U09: hard wall-clock limit for one graph call as seen by the *request* thread. The server-side
# transaction timeout (QUERY_TIMEOUT_S) cannot fire when the host is unreachable (stopped container:
# the docker DNS name fails only after ~4s), so without this a request waits for the whole connect
# failure and the next request waits again. Past the deadline the breaker opens and callers degrade.
try:
    CALL_DEADLINE_S = max(0.2, float(os.getenv("NEO4J_CALL_DEADLINE_S", "0.6")))
except ValueError:  # pragma: no cover - bad env value
    CALL_DEADLINE_S = 0.6
_T = TypeVar("_T")

_driver: Any | None = None
_down_until = 0.0
_lock = Lock()


def _now() -> float:
    return time.monotonic()


def is_enabled() -> bool:
    return bool(config.SEARCH_GRAPH_ENABLED and config.NEO4J_URI)


def get_driver() -> Any | None:
    global _driver, _down_until
    if not is_enabled():
        return None
    if _now() < _down_until:
        return None
    if _driver is not None:
        return _driver
    with _lock:
        if _driver is not None:
            return _driver
        if _now() < _down_until:
            return None
        try:
            from neo4j import GraphDatabase

            driver = GraphDatabase.driver(
                config.NEO4J_URI,
                auth=(config.NEO4J_USER, config.NEO4J_PASSWORD),
                connection_timeout=1.0,
                connection_acquisition_timeout=1.0,
                max_transaction_retry_time=0.5,
                max_connection_pool_size=20,
            )
            driver.verify_connectivity()
            _driver = driver
        except Exception as exc:
            _down_until = _now() + RETRY_SECONDS
            logger.warning("Neo4j unavailable; graph branches off until reachable: %s", type(exc).__name__)
            _driver = None
    if _driver is None:
        _start_probe()
    return _driver


def _is_connectivity_error(exc: BaseException | None) -> bool:
    """Everything except a server-side Cypher/query error (``Neo4jError``) counts as "Neo4j
    unreachable": ServiceUnavailable, SessionExpired, OSError and the ``ValueError`` the driver
    raises when the docker DNS name cannot be resolved (container stopped, ~4s to fail)."""

    if exc is None:
        return True
    try:
        from neo4j.exceptions import ClientError, Neo4jError
    except Exception:  # pragma: no cover - driver missing
        return False
    return not isinstance(exc, (Neo4jError, ClientError))


def report_failure(exc: BaseException | None = None) -> None:
    """A query failed with a connectivity error: open the breaker and let a background probe
    (not user requests) find out when Neo4j is back."""

    global _down_until
    if not _is_connectivity_error(exc):
        return
    _down_until = _now() + RETRY_SECONDS
    _start_probe()


_probe_running = False
_probe_lock = Lock()
_on_recover: Callable[[], None] | None = None


def set_on_recover(callback: Callable[[], None] | None) -> None:
    """Hook run by the probe after Neo4j is reachable again, before the breaker closes."""

    global _on_recover
    _on_recover = callback


def _start_probe() -> None:
    global _probe_running
    with _probe_lock:
        if _probe_running:
            return
        _probe_running = True
    Thread(target=_probe_loop, daemon=True, name="neo4j-probe").start()


def _probe_loop() -> None:
    """Every RETRY_SECONDS: verify connectivity; keep the breaker open until it succeeds."""

    global _down_until, _probe_running
    try:
        while True:
            time.sleep(RETRY_SECONDS)
            try:
                driver = _driver
                if driver is None:
                    from neo4j import GraphDatabase

                    driver = GraphDatabase.driver(config.NEO4J_URI, auth=(config.NEO4J_USER, config.NEO4J_PASSWORD),
                                                  connection_timeout=1.0)
                driver.verify_connectivity()
                _set_driver(driver)
                if _on_recover is not None:
                    try:  # warm query plans/page cache while the breaker still shields users
                        _on_recover()
                    except Exception as exc:
                        logger.warning("Neo4j recovery warm-up failed: %s", type(exc).__name__)
                _down_until = 0.0
                logger.warning("Neo4j reachable again; graph branches back on")
                return
            except Exception:
                _down_until = _now() + RETRY_SECONDS + 1
    finally:
        _probe_running = False


def _set_driver(driver: Any) -> None:
    global _driver
    _driver = driver


_pool: ThreadPoolExecutor | None = None
_pool_lock = Lock()


def _call_pool() -> ThreadPoolExecutor:
    """Created on first use, i.e. after gunicorn forks (threads must not exist before the fork)."""

    global _pool
    if _pool is None:
        with _pool_lock:
            if _pool is None:
                _pool = ThreadPoolExecutor(max_workers=16, thread_name_prefix="neo4j-call")
    return _pool


def run_with_deadline(fn: Callable[[], _T], deadline_s: float | None = None) -> _T:
    """Run a blocking graph call; give up after ``deadline_s`` (default ``CALL_DEADLINE_S``).

    Past the deadline the breaker opens (``report_failure(None)``) and ``RuntimeError("neo4j_timeout")``
    is raised, so this request and every following one degrade at once instead of each waiting out
    the connect failure. Errors raised by ``fn`` propagate unchanged (the caller reports them).
    """

    future: Future[_T] = _call_pool().submit(fn)
    try:
        return future.result(timeout=CALL_DEADLINE_S if deadline_s is None else deadline_s)
    except FutureTimeout:
        report_failure(None)
        raise RuntimeError("neo4j_timeout") from None


def is_open() -> bool:
    """True while the breaker blocks graph calls."""

    return _now() < _down_until


def reset() -> None:
    """Test helper: forget driver and breaker state (does not close a shared driver)."""

    global _driver, _down_until
    with _lock:
        _driver = None
        _down_until = 0.0


def health() -> str:
    if not config.SEARCH_GRAPH_ENABLED:
        return "disabled"
    driver = get_driver()
    if driver is None:
        return "down"
    try:
        driver.verify_connectivity()
        return "ok"
    except Exception as exc:
        report_failure(exc)
        return "down"
