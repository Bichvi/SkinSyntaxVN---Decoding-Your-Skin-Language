"""U09 (from the U08 review): the Neo4j breaker must trip on the FIRST connectivity failure.

Before: a stopped Neo4j container made every request wait for the docker DNS failure (~4s) and the
breaker only opened after that call finished, so the next request waited again. Now a graph call
past ``CALL_DEADLINE_S`` opens the breaker for everyone, and a connection error opens it at once.
"""

import time

import pytest
from neo4j.exceptions import ClientError, ServiceUnavailable

from search_service import neo4j_client, service
from search_service.service import LazyGraph


@pytest.fixture(autouse=True)
def _clean_breaker(monkeypatch):
    monkeypatch.setattr(neo4j_client, "_start_probe", lambda: None)   # no background thread in unit tests
    neo4j_client.reset()
    yield
    neo4j_client.reset()


def _enable(monkeypatch, driver):
    monkeypatch.setattr(neo4j_client.config, "SEARCH_GRAPH_ENABLED", True)
    monkeypatch.setattr(neo4j_client.config, "NEO4J_URI", "bolt://example:7687")
    monkeypatch.setattr(neo4j_client, "_driver", driver)


def test_run_with_deadline_returns_fast_results():
    assert neo4j_client.run_with_deadline(lambda: 42, deadline_s=1.0) == 42


def test_run_with_deadline_propagates_errors_unchanged():
    def boom():
        raise ServiceUnavailable("refused")

    with pytest.raises(ServiceUnavailable):
        neo4j_client.run_with_deadline(boom, deadline_s=1.0)


def test_run_with_deadline_opens_breaker_when_call_hangs():
    started = time.monotonic()
    with pytest.raises(RuntimeError, match="neo4j_timeout"):
        neo4j_client.run_with_deadline(lambda: time.sleep(1.5), deadline_s=0.1)
    assert time.monotonic() - started < 0.8          # did not wait for the hanging call
    assert neo4j_client.is_open()


def test_connection_refused_opens_breaker_on_first_call_and_second_call_skips_graph(monkeypatch):
    calls = []

    class Repo:
        def __init__(self, driver, timeout_s=None):
            pass

        def same_brand(self, *a, **k):
            calls.append(1)
            raise ServiceUnavailable("Connection refused")

    _enable(monkeypatch, object())
    monkeypatch.setattr("search_service.graph_repository.GraphRepository", Repo)
    graph = LazyGraph()
    with pytest.raises(ServiceUnavailable):
        graph.same_brand("torriden", [])
    assert neo4j_client.is_open()
    t0 = time.monotonic()
    with pytest.raises(RuntimeError, match="neo4j_down"):
        graph.same_brand("torriden", [])
    assert calls == [1]                                # second request never reached the driver
    assert time.monotonic() - t0 < 0.05


def test_hanging_driver_call_degrades_within_the_deadline_and_blocks_the_next_request(monkeypatch):
    class Repo:
        def __init__(self, driver, timeout_s=None):
            pass

        def same_brand(self, *a, **k):
            time.sleep(1.5)       # docker DNS failure for a stopped container
            raise ValueError("Cannot resolve address neo4j:7687")

    _enable(monkeypatch, object())
    monkeypatch.setattr(neo4j_client, "CALL_DEADLINE_S", 0.15)
    monkeypatch.setattr("search_service.graph_repository.GraphRepository", Repo)
    graph = LazyGraph()
    t0 = time.monotonic()
    with pytest.raises(RuntimeError, match="neo4j_timeout"):
        graph.same_brand("torriden", [])
    assert time.monotonic() - t0 < 0.8
    t1 = time.monotonic()
    with pytest.raises(RuntimeError, match="neo4j_down"):
        graph.same_brand("torriden", [])
    assert time.monotonic() - t1 < 0.05


def test_cypher_error_does_not_open_breaker(monkeypatch):
    class Repo:
        def __init__(self, driver, timeout_s=None):
            pass

        def same_brand(self, *a, **k):
            raise ClientError("syntax")

    _enable(monkeypatch, object())
    monkeypatch.setattr("search_service.graph_repository.GraphRepository", Repo)
    with pytest.raises(ClientError):
        LazyGraph().same_brand("torriden", [])
    assert not neo4j_client.is_open()


def test_module_level_deadline_is_below_the_php_similar_timeout():
    # PHP SEARCH_SIMILAR_TIMEOUT_MS defaults to 800ms: the graph must give up before the caller does.
    assert neo4j_client.CALL_DEADLINE_S < 0.8
    assert hasattr(service, "LazyGraph")
