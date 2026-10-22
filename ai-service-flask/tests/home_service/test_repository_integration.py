"""Integration: HomeGraphRepository Cypher against the real local Neo4j (read-only).

Run: NEO4J_TEST_URI=bolt://localhost:7687 NEO4J_PASSWORD=... pytest -m integration
Skipped when no URI/password is set or Neo4j is unreachable.
"""

from __future__ import annotations

import os
import time

import pytest

from home_service.repository import HomeGraphRepository

pytestmark = pytest.mark.integration

URI = os.getenv("NEO4J_TEST_URI") or os.getenv("NEO4J_URI") or ""
PASSWORD = os.getenv("NEO4J_PASSWORD") or ""


@pytest.fixture(scope="module")
def repo():
    if not URI or not PASSWORD:
        pytest.skip("NEO4J_TEST_URI/NEO4J_URI and NEO4J_PASSWORD not set")
    neo4j = pytest.importorskip("neo4j")
    driver = neo4j.GraphDatabase.driver(URI, auth=(os.getenv("NEO4J_USER", "neo4j"), PASSWORD),
                                        connection_timeout=2.0)
    try:
        driver.verify_connectivity()
    except Exception as exc:  # pragma: no cover - environment dependent
        driver.close()
        pytest.skip(f"Neo4j unreachable: {type(exc).__name__}")
    yield HomeGraphRepository(driver, timeout_s=5.0)
    driver.close()


def _seed_with_similar(repo) -> str:
    with repo.driver.session() as s:
        rec = s.run(
            "MATCH (a:Product {active:true})-[:SIMILAR_TO]-(b:Product {active:true}) "
            "WHERE coalesce(b.in_stock,true)=true RETURN a.id AS id, count(DISTINCT b) AS n "
            "ORDER BY n DESC, id ASC LIMIT 1").single()
    assert rec, "graph has no SIMILAR_TO edges"
    return rec["id"]


def test_seen_candidates_shape(repo):
    seed = _seed_with_similar(repo)
    rows = repo.seen_candidates(seed)
    assert rows and len(rows) <= 60
    for r in rows:
        assert {"id", "sim", "shared", "brand", "sold"} <= set(r)
        assert isinstance(r["sim"], float) and isinstance(r["sold"], float)
        assert len(r["shared"]) <= 3
    assert seed not in {r["id"] for r in rows}


def test_skin_candidates_fit_values(repo):
    rows = repo.skin_candidates("da_dau", ["tham_mun"])
    assert rows and len(rows) <= 60
    assert {r["fit"] for r in rows} <= {"data", "inferred", "all"}
    assert all(len(r["concern_names"]) <= 2 and "sold" in r for r in rows)


def test_concern_candidates_targets(repo):
    rows = repo.concern_candidates("mun", None)
    assert len(rows) >= 8
    assert all({"id", "helps", "skin_fit", "sold", "rating", "brand"} <= set(r) for r in rows)
    assert all(len(r["helps"]) <= 2 for r in rows)
    with repo.driver.session() as s:
        targeted = {r["id"] for r in s.run(
            "MATCH (p:Product)-[:TARGETS]->(:Concern {key:'mun'}) WHERE p.id IN $ids RETURN p.id AS id",
            ids=[r["id"] for r in rows])}
    assert targeted == {r["id"] for r in rows}


def test_conflicting_ids_empty_cart(repo):
    assert repo.conflicting_ids([], ["1"]) == set()


def test_conflicting_ids_matches_ingredient_conflicts(repo):
    with repo.driver.session() as s:
        row = s.run(
            "MATCH (a:Product {active:true})-[:CONTAINS]->(:Ingredient)-[:CONFLICTS_WITH]-(:Ingredient)"
            "<-[:CONTAINS]-(b:Product {active:true}) WHERE a.id <> b.id RETURN a.id AS a, b.id AS b LIMIT 1").single()
    if not row:
        pytest.skip("no conflicting product pair in graph")
    assert repo.conflicting_ids([row["a"]], [row["b"]]) == {row["b"]}


def test_label_name(repo):
    assert repo.label_name("Concern", "tham_mun") == "Thâm mụn"
    with pytest.raises(ValueError):
        repo.label_name("Product", "1")


def test_queries_fast(repo):
    seed = _seed_with_similar(repo)
    calls = {
        "seen": lambda: repo.seen_candidates(seed),
        "skin": lambda: repo.skin_candidates("da_dau", ["tham_mun"]),
        "concern": lambda: repo.concern_candidates("mun", "da_dau"),
        "conflicts": lambda: repo.conflicting_ids([seed], ["1", "2", "3"]),
        "names": lambda: repo.seed_names([seed]),
        "label": lambda: repo.label_name("Concern", "mun"),
    }
    for name, fn in calls.items():
        fn()
        started = time.perf_counter()
        fn()
        elapsed = time.perf_counter() - started
        print(f"timing {name}: {elapsed * 1000:.1f}ms")
        assert elapsed < 0.3, f"{name} took {elapsed * 1000:.0f}ms"
