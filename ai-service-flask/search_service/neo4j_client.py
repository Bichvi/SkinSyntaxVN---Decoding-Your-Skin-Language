"""Lazy Neo4j driver singleton with a short circuit breaker.

``get_driver()`` returns None when the graph is disabled, unconfigured or recently down.
A failed connect (or a branch reporting a failure via ``report_failure``) opens the breaker
for ``RETRY_SECONDS`` so graph branches degrade instantly instead of waiting on timeouts;
after that the next caller retries. Drivers must be created after gunicorn forks (never at
import time) — ``warm_up`` does it in ``post_fork``.
"""

from __future__ import annotations

import logging
import time
from threading import Lock, Thread
from typing import Any, Callable

from . import config

logger = logging.getLogger(__name__)
RETRY_SECONDS = 10.0
QUERY_TIMEOUT_S = 0.7

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
