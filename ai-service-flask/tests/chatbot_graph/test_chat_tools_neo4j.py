"""U10 integration: the tools against the real local Neo4j (read-only).

Run: NEO4J_TEST_URI=bolt://127.0.0.1:7687 NEO4J_PASSWORD=... pytest -m integration ai-service-flask/tests/chatbot_graph
Skipped without a URI/password. Mongo is not needed (facts come from the graph copy); the full question set with Mongo
price checks is ``scripts/eval_graph_tools.py`` (run inside the ai container).
"""

from __future__ import annotations

import os

import pytest

from chatbot_service import graph_tools as gt
from graph_fakes import vocab
from graph_sync.conflicts import load_rule_set
from search_service import graph_repository as gr

pytestmark = pytest.mark.integration

URI = os.getenv("NEO4J_TEST_URI") or os.getenv("NEO4J_URI") or ""
PASSWORD = os.getenv("NEO4J_PASSWORD") or ""


@pytest.fixture(scope="module")
def driver():
    if not URI or not PASSWORD:
        pytest.skip("NEO4J_TEST_URI/NEO4J_URI and NEO4J_PASSWORD not set")
    neo4j = pytest.importorskip("neo4j")
    drv = neo4j.GraphDatabase.driver(URI, auth=(os.getenv("NEO4J_USER", "neo4j"), PASSWORD), connection_timeout=2.0)
    try:
        drv.verify_connectivity()
    except Exception as exc:  # pragma: no cover - environment dependent
        drv.close()
        pytest.skip(f"Neo4j unreachable: {type(exc).__name__}")
    yield drv
    drv.close()


@pytest.fixture(scope="module")
def tools(driver):
    deps = gt.ToolDeps(graph=gr.GraphRepository(driver, timeout_s=10.0), vocab=vocab(), groups=gt.load_groups(),
                       rule_set=load_rule_set())
    return gt.GraphTools(deps)


def rows(driver, cypher, **params):
    with driver.session() as session:
        return [dict(r) for r in session.run(cypher, **params)]


def test_serum_for_oily_skin_not_conflicting_with_retinol(driver, tools):
    result = tools.call("find_products", {"category": "serum", "skin_type": "da_dau", "avoid_conflict_with": ["retinol"]})
    assert result["products"]
    rules = load_rule_set()
    bad = {r["k"] for r in rows(driver, "MATCH (:Ingredient {key:'retinol'})-[c:CONFLICTS_WITH]-(o:Ingredient) "
                                        "WHERE c.severity IN ['medium','high'] RETURN o.key AS k")}
    for item in result["products"]:
        pid = item["id"]
        assert rows(driver, "MATCH (:Product {id:$id})-[:IN_CATEGORY]->(:Category)-[:CHILD_OF*0..3]->(:Category {key:'serum'}) "
                            "RETURN count(*) AS n", id=pid)[0]["n"] > 0
        assert item["skin"] == "exact" and rows(
            driver, "MATCH (:Product {id:$id})-[:SUITABLE_FOR]->(:SkinType {key:'da_dau'}) RETURN count(*) AS n", id=pid)[0]["n"] == 1
        have = {r["k"]: r["rank"] for r in rows(driver, "MATCH (:Product {id:$id})-[c:CONTAINS]->(i:Ingredient) "
                                                        "RETURN i.key AS k, c.rank AS rank", id=pid)}
        assert not {k for k in bad & set(have) if have[k] <= rules.rank_limit(k)}


def test_cleanser_without_alcohol_under_300k(driver, tools):
    result = tools.call("find_products", {"category": "sua_rua_mat", "exclude_ingredients": ["alcohol_denat"], "price_max": 300000})
    assert result["products"]
    alcohol = set(gt.load_groups().exclusion["alcohol"].keys)
    for item in result["products"]:
        assert item["price"] <= 300000
        have = {r["k"] for r in rows(driver, "MATCH (:Product {id:$id})-[:CONTAINS]->(i:Ingredient) RETURN i.key AS k", id=item["id"])}
        assert len(have) >= 3 and not alcohol & have


def test_brand_category_existence_matches_the_graph(driver, tools):
    result = tools.call("find_products", {"brand": "torriden", "category": "kem_chong_nang"})
    truth = rows(driver, "MATCH (p:Product {active:true})-[:OF_BRAND]->(:Brand {key:'torriden'}) "
                         "MATCH (p)-[:IN_CATEGORY]->(:Category)-[:CHILD_OF*0..3]->(:Category {key:'kem_chong_nang'}) "
                         "WHERE p.in_stock <> false RETURN count(DISTINCT p) AS n")[0]["n"]
    assert (len(result["products"]) > 0) == (truth > 0)


def test_retinol_with_bha_is_a_high_conflict_in_both_rules_and_graph(driver, tools):
    result = tools.call("check_conflicts", {"ingredient_keys": ["retinol", "salicylic_acid"]})
    assert result["pairs"][0]["severity"] == "high" and result["consult_expert"]
    edge = rows(driver, "MATCH (:Ingredient {key:'retinol'})-[c:CONFLICTS_WITH]-(:Ingredient {key:'salicylic_acid'}) "
                        "RETURN c.severity AS s")
    assert edge and edge[0]["s"] == "high"


def test_explain_product_probe_agrees_with_contains_edges(driver, tools):
    result = tools.call("explain_product", {"product_id": "1496", "probe_ingredients": ["alcohol_denat"]})
    keys = set(gt.load_groups().exclusion["alcohol"].keys)
    truth = rows(driver, "MATCH (:Product {id:'1496'})-[:CONTAINS]->(i:Ingredient) WHERE i.key IN $k RETURN count(i) AS n", k=list(keys))[0]["n"]
    assert result["probe"][0]["present"] == bool(truth) and result["probe"][0]["readable"]


def test_warm_shapes_run_without_errors(driver):
    from search_service.service import _warm_chat_tools

    _warm_chat_tools(gr.GraphRepository(driver, timeout_s=15.0))
