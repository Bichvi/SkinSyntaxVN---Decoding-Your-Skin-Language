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
                  skin_type=None, concerns=[], cart_ids=[], id="1496", limit=40,
                  # U10 find_products / explain_product
                  universal=False, benefits=[""], include_groups=[[""]], exclude_keys=[""], avoid_keys=[""],
                  rank_limits={"": 10000}, f_categories=False, f_concerns=False, f_benefits=False, f_include=False,
                  f_exclude=False, f_avoid=False, need_ingredient_data=False, fetch=5, probe_keys=[""])
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


# -- U08: SIMILAR_PRODUCTS (detail page "Sản phẩm tương tự") --
def test_similar_products_1496_has_other_brand_cleansers_in_top8(repo):
    from search_service.similar import finalize, rank_candidates

    rows = repo.similar_products("1496", "", 40)
    assert rows and all(r["id"] != "1496" for r in rows)
    top = finalize(rank_candidates(rows), ["1496"], 8)
    assert 4 <= len(top) <= 8
    ids = [i["product_id"] for i in top]
    leaf = _categories(repo, ["1496", *ids])
    brands = _brands(repo, ["1496", *ids])
    same_leaf_other_brand = [i for i in ids if leaf.get(i) == leaf["1496"] and brands.get(i) != "torriden"]
    assert same_leaf_other_brand, "same_leaf boost should surface other-brand cleansers"
    from collections import Counter

    assert max(Counter(brands.get(i, "") for i in ids).values()) <= 3


def test_similar_products_skin_type_marks_fit_and_unknown_product_is_empty(repo):
    rows = repo.similar_products("1496", "da_dau", 40)
    assert rows and all(isinstance(r["skin_fit"], bool) for r in rows)
    assert repo.similar_products("no-such-product", "", 40) == []


# -- U09: cart analysis (needs `python -m graph_sync --conflicts-only` to have run) --
def _leave_on_with(repo, key, max_rank, cats):
    with repo.driver.session() as s:
        rec = s.run(
            "MATCH (p:Product {active:true})-[r:CONTAINS]->(:Ingredient {key:$k}) WHERE r.rank <= $mx "
            "MATCH (p)-[:IN_CATEGORY]->(c:Category) WHERE c.key IN $cats "
            "RETURN p.id AS id ORDER BY p.sold DESC LIMIT 1", k=key, mx=max_rank, cats=cats).single()
    return rec["id"] if rec else None


def test_cart_conflicts_retinol_and_salicylic_acid_is_high(repo):
    from search_service.cart_analyze import conflicts_from_rows
    from graph_sync.conflicts import load_rule_set

    retinol = _leave_on_with(repo, "retinol", 12, ["serum", "kem_duong"])
    bha = _leave_on_with(repo, "salicylic_acid", 8, ["serum", "toner", "ho_tro_tri_mun", "kem_duong"])
    if not (retinol and bha):
        pytest.skip("no leave-on retinol / salicylic acid products in this graph")
    rows = repo.cart_conflicts([retinol, bha])
    if not rows:
        pytest.skip("CONFLICTS_WITH not loaded: run python -m graph_sync --conflicts-only")
    cats = repo.cart_categories([retinol, bha])
    out = conflicts_from_rows(rows, cats, load_rule_set())
    assert out and out[0]["severity"] == "high" and {out[0]["a"], out[0]["b"]} == {retinol, bha}
    assert repo.cart_conflicts([retinol]) == [] and repo.cart_conflicts([]) == []


def test_conflict_edges_carry_the_rule_attributes(repo):
    with repo.driver.session() as s:
        rows = s.run("MATCH ()-[c:CONFLICTS_WITH]->() RETURN c.severity AS s, c.timing AS t, c.rule_id AS r, c.advice_vi AS a").data()
    if not rows or all(r["r"] is None for r in rows):
        pytest.skip("CONFLICTS_WITH not loaded with rule attributes")
    assert len(rows) >= 30
    assert all(r["s"] in ("high", "medium", "low") and r["t"] and r["r"] and r["a"] for r in rows)
