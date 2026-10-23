"""Cypher for the homepage sections. Parameterized only, read-only.

Every product query returns only ``active = true AND coalesce(in_stock, true) = true`` products,
ordered by relevance then ``sold DESC, id ASC`` and capped at ``$limit`` (CANDIDATE_LIMIT).
"Main ingredient" = ``CONTAINS.rank <= 5`` and role not in GENERIC_ROLES (null role counts as
non-generic). Parameters keep stable types (skin_type "" not null) so Neo4j reuses one plan.
"""

from __future__ import annotations

from typing import Any

from search_service.graph_repository import GENERIC_ROLES

from .config import CANDIDATE_LIMIT

MAIN_RANK = 5
ALLOWED_LABELS = ("SkinType", "Concern")

# (1) Neighbours of the seed through SIMILAR_TO (undirected), best score per neighbour, plus up
# to 3 shared main ingredients (both sides rank <= 5).
SEEN_CANDIDATES = """
MATCH (s:Product {id:$seed_id})-[r:SIMILAR_TO]-(p:Product {active:true})
WHERE p.id <> $seed_id AND coalesce(p.in_stock, true) = true
WITH s, p, max(coalesce(r.score, 0.0)) AS sim
OPTIONAL MATCH (s)-[rs:CONTAINS]->(i:Ingredient)<-[rp:CONTAINS]-(p)
WHERE rs.rank <= $main_rank AND rp.rank <= $main_rank AND NOT coalesce(i.role, '') IN $generic_roles
WITH p, sim, i, rs
ORDER BY rs.rank ASC, i.key ASC
WITH p, sim, [x IN collect(DISTINCT i.name) WHERE x IS NOT NULL][..3] AS shared
RETURN p.id AS id, toFloat(sim) AS sim, shared,
       head([(p)-[:OF_BRAND]->(b:Brand) | b.key]) AS brand, toFloat(coalesce(p.sold, 0)) AS sold
ORDER BY sim DESC, sold DESC, id ASC LIMIT $limit
"""

SEED_NAMES = """
MATCH (p:Product) WHERE p.id IN $ids
RETURN p.id AS id, p.name AS name
"""

# (2) Products suitable for the skin type (fit data > inferred > all-skin), concern hits as tie-break.
SKIN_CANDIDATES = """
MATCH (p:Product {active:true})-[sf:SUITABLE_FOR]->(st:SkinType)
WHERE coalesce(p.in_stock, true) = true AND st.key IN [$skin_type, 'moi_loai_da']
WITH p, max(CASE
  WHEN st.key = $skin_type AND sf.source = 'data' THEN 3
  WHEN st.key = $skin_type THEN 2
  ELSE 1 END) AS fit_score
OPTIONAL MATCH (p)-[:TARGETS]->(cn:Concern) WHERE cn.key IN $concerns
WITH p, fit_score, collect(DISTINCT cn.name) AS concern_names
RETURN p.id AS id,
       CASE fit_score WHEN 3 THEN 'data' WHEN 2 THEN 'inferred' ELSE 'all' END AS fit,
       concern_names[..2] AS concern_names, toFloat(coalesce(p.sold, 0)) AS sold
ORDER BY fit_score DESC, size(concern_names) DESC, sold DESC, id ASC LIMIT $limit
"""

# (3) Products targeting the concern; reasons from main ingredients that HELP the concern.
CONCERN_CANDIDATES = """
MATCH (c:Concern {key:$concern})
MATCH (p:Product {active:true})-[:TARGETS]->(c)
WHERE coalesce(p.in_stock, true) = true
WITH p, c,
     [(p)-[r:CONTAINS]->(i:Ingredient)-[:HELPS]->(c)
      WHERE r.rank <= $main_rank AND NOT coalesce(i.role, '') IN $generic_roles | i.name][..2] AS helps,
     ($skin_type <> '' AND exists { (p)-[:SUITABLE_FOR]->(:SkinType {key:$skin_type}) }) AS skin_fit
RETURN p.id AS id, helps, skin_fit, toFloat(coalesce(p.sold, 0)) AS sold,
       CASE WHEN p.rating IS NULL THEN null ELSE toFloat(p.rating) END AS rating,
       head([(p)-[:OF_BRAND]->(b:Brand) | b.key]) AS brand
ORDER BY size(helps) DESC, skin_fit DESC, sold DESC, id ASC LIMIT $limit
"""

CONFLICTING = """
MATCH (c:Product) WHERE c.id IN $candidate_ids
  AND exists {
    MATCH (c)-[:CONTAINS]->(:Ingredient)-[:CONFLICTS_WITH]-(:Ingredient)<-[:CONTAINS]-(k:Product)
    WHERE k.id IN $cart_ids
  }
RETURN c.id AS id
"""

LABEL_NAME = {
    "SkinType": "MATCH (n:SkinType {key:$key}) RETURN n.name AS name",
    "Concern": "MATCH (n:Concern {key:$key}) RETURN n.name AS name",
}

ALL_QUERIES = {
    "seen_candidates": SEEN_CANDIDATES,
    "seed_names": SEED_NAMES,
    "skin_candidates": SKIN_CANDIDATES,
    "concern_candidates": CONCERN_CANDIDATES,
    "conflicting": CONFLICTING,
}


class HomeGraphRepository:
    def __init__(self, driver: Any, timeout_s: float | None = None) -> None:
        self.driver = driver
        self.timeout_s = timeout_s

    def _run(self, cypher: str, **params: Any) -> list[dict[str, Any]]:
        from neo4j import Query

        query = Query(cypher, timeout=self.timeout_s) if self.timeout_s else Query(cypher)
        with self.driver.session() as session:
            return [dict(record) for record in session.run(query, **params)]

    def seen_candidates(self, seed_id: str) -> list[dict[str, Any]]:
        return self._run(SEEN_CANDIDATES, seed_id=str(seed_id), main_rank=MAIN_RANK,
                         generic_roles=GENERIC_ROLES, limit=CANDIDATE_LIMIT)

    def seed_names(self, ids: list[str]) -> dict[str, str]:
        if not ids:
            return {}
        rows = self._run(SEED_NAMES, ids=[str(x) for x in ids])
        return {str(r["id"]): str(r["name"]) for r in rows if r.get("name")}

    def skin_candidates(self, skin_type: str, concerns: list[str]) -> list[dict[str, Any]]:
        return self._run(SKIN_CANDIDATES, skin_type=skin_type or "", concerns=list(concerns),
                         limit=CANDIDATE_LIMIT)

    def concern_candidates(self, concern: str, skin_type: str | None) -> list[dict[str, Any]]:
        return self._run(CONCERN_CANDIDATES, concern=concern, skin_type=skin_type or "",
                         main_rank=MAIN_RANK, generic_roles=GENERIC_ROLES, limit=CANDIDATE_LIMIT)

    def conflicting_ids(self, cart_ids: list[str], candidate_ids: list[str]) -> set[str]:
        if not cart_ids or not candidate_ids:
            return set()
        rows = self._run(CONFLICTING, cart_ids=[str(x) for x in cart_ids],
                         candidate_ids=[str(x) for x in candidate_ids])
        return {str(r["id"]) for r in rows}

    def label_name(self, label: str, key: str) -> str | None:
        if label not in ALLOWED_LABELS:
            raise ValueError(f"label must be one of {ALLOWED_LABELS}")
        rows = self._run(LABEL_NAME[label], key=key)
        return str(rows[0]["name"]) if rows and rows[0].get("name") else None
