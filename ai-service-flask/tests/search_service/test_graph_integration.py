"""Integration (Bước 05B): every Cypher in graph_repository against the real local Neo4j.

Run: NEO4J_TEST_URI=bolt://localhost:7687 NEO4J_PASSWORD=... pytest -m integration
Skipped when no URI/password is set. Read-only queries; relies on the Bước 03 ETL data.
"""

from __future__ import annotations

import os
import time

import pytest

from search_service import graph_repository as gr

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
    yield gr.GraphRepository(driver, timeout_s=5.0)
    driver.close()


def _categories(repo, ids):
    with repo.driver.session() as s:
        return {r["id"]: r["cat"] for r in s.run(
            "MATCH (p:Product)-[:IN_CATEGORY]->(c:Category) WHERE p.id IN $ids RETURN p.id AS id, c.key AS cat",
            ids=list(ids))}


def _brands(repo, ids):
    with repo.driver.session() as s:
        return {r["id"]: r["b"] for r in s.run(
            "MATCH (p:Product)-[:OF_BRAND]->(b:Brand) WHERE p.id IN $ids RETURN p.id AS id, b.key AS b", ids=list(ids))}


def test_all_queries_pass_explain(repo):
    params = dict(brand="x", categories=[], category="x", ingredients=[], min_price=None, max_price=None,
                  generic_roles=gr.GENERIC_ROLES, max_rank=15, profile_size=8, seed_ids=[], exclude_brand_ids=[],
                  skin_type=None, concerns=[], cart_ids=[])
    for name, cypher in gr.ALL_QUERIES.items():
        with repo.driver.session() as s:
            s.run("EXPLAIN " + cypher, **params).consume()


def test_same_brand_puts_requested_category_first(repo):
    rows = repo.same_brand("torriden", ["sua_rua_mat"])
    assert rows and rows[0]["id"] == "1496" and rows[0]["near"] == 2
    assert {r["id"] for r in rows} <= set(_brands(repo, [r["id"] for r in rows]))
    assert set(_brands(repo, [r["id"] for r in rows]).values()) == {"torriden"}


def test_category_alt_for_torriden_cleanser_returns_other_cleansers(repo):
    rows = repo.category_alt("sua_rua_mat", "torriden")
    ids = [r["id"] for r in rows]
    assert len(ids) >= 8
    cats = _categories(repo, ids)
    assert all(cats[i] == "sua_rua_mat" for i in ids), "category_alt must stay in the LEAF category"
    assert "torriden" not in set(_brands(repo, ids).values())
    assert rows[0]["shared_names"], "top alternative should share actives with the Torriden profile"
    generic = {"Water", "Glycerin", "Butylene Glycol"}
    assert not generic & {n for r in rows for n in r["shared_names"]}


def test_category_alt_parent_category_expands_via_child_of(repo):
    rows = repo.category_alt("mat_na", None)
    cats = set(_categories(repo, [r["id"] for r in rows]).values())
    assert rows and cats <= {"mat_na_lot", "mat_na_ngu", "mat_na_giay", "mat_na_rua", "mat_na"}


def test_category_alt_ingredient_and_price(repo):
    rows = repo.category_alt("serum", None, ["ascorbic_acid"], {"min_vnd": None, "max_vnd": 400000})
    assert rows and rows[0]["ingredient_hits"] >= 1
    with repo.driver.session() as s:
        prices = [r["price"] for r in s.run("MATCH (p:Product) WHERE p.id IN $ids RETURN p.price AS price",
                                            ids=[r["id"] for r in rows])]
    assert all(p is None or p <= 400000 for p in prices)


def test_similar_to_stays_in_seed_leaf_category(repo):
    rows = repo.similar_to(["1496"], "torriden")
    ids = [r["id"] for r in rows]
    assert ids and all(c == "sua_rua_mat" for c in _categories(repo, ids).values())


def test_personalized_skin_type_and_cart_conflicts(repo):
    rows = repo.personalized("da_dau", ["mun"], ["sua_rua_mat"], [])
    assert rows and all(c == "sua_rua_mat" for c in _categories(repo, [r["id"] for r in rows]).values())
    # retinol-containing product in cart -> no conflicting (aha/bha/bpo) product returned
    with repo.driver.session() as s:
        cart = [r["id"] for r in s.run(
            "MATCH (p:Product {active:true})-[:CONTAINS]->(i:Ingredient)-[:CONFLICTS_WITH]-() "
            "RETURN DISTINCT p.id AS id LIMIT 1")]
    if cart:
        rows = repo.personalized(None, ["mun"], [], cart)
        with repo.driver.session() as s:
            bad = s.run(
                "MATCH (p:Product)-[:CONTAINS]->(:Ingredient)-[:CONFLICTS_WITH]-(:Ingredient)<-[:CONTAINS]-(c:Product) "
                "WHERE p.id IN $ids AND c.id IN $cart RETURN count(*) AS n",
                ids=[r["id"] for r in rows], cart=cart).single()["n"]
        assert bad == 0


def test_warm_queries_are_fast(repo):
    repo.category_alt("sua_rua_mat", "torriden")
    started = time.perf_counter()
    for _ in range(3):
        repo.same_brand("torriden", ["sua_rua_mat"])
        repo.category_alt("sua_rua_mat", "torriden")
        repo.personalized("da_dau", [], ["sua_rua_mat"], [])
    assert (time.perf_counter() - started) / 3 < 0.5
