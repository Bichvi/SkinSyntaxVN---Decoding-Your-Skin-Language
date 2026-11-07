"""Neo4j writes. All Cypher is parameterised; each batch is one ``execute_write`` transaction."""

from __future__ import annotations

import logging
from typing import Any, Iterable, Sequence

from .rules import ConflictPair

log = logging.getLogger("graph_sync.load")

UPSERT_CATEGORIES = """
UNWIND $cats AS c
MERGE (n:Category {key: c.key})
SET n.name = c.name, n.level = c.level
"""

REPLACE_CHILD_OF = """
MATCH (:Category)-[old:CHILD_OF]->(:Category) DELETE old
"""

UPSERT_CHILD_OF = """
UNWIND $edges AS e
MATCH (c:Category {key: e.child}), (p:Category {key: e.parent})
MERGE (c)-[:CHILD_OF]->(p)
"""

# Relationships owned by a product are deleted and recreated => idempotent re-runs.
# SIMILAR_TO / CO_PURCHASED are owned by other jobs and are not touched here.
UPSERT_PRODUCTS = """
UNWIND $rows AS r
MERGE (p:Product {id: r.id})
SET p += r.props, p.active = r.active,
    p.ingredient_source = r.ingredient_source, p.category_source = r.category_source
WITH p, r
OPTIONAL MATCH (p)-[old:OF_BRAND|IN_CATEGORY|CONTAINS|SUITABLE_FOR|TARGETS|HAS_BENEFIT|MADE_IN]->()
DELETE old
WITH DISTINCT p, r
FOREACH (b IN r.brand |
  MERGE (n:Brand {key: b.key})
  ON CREATE SET n.name = b.name
  FOREACH (_ IN CASE WHEN $overwrite_names THEN [1] ELSE [] END | SET n.name = b.name)
  MERGE (p)-[:OF_BRAND]->(n))
FOREACH (k IN CASE WHEN r.leaf_category IS NULL THEN [] ELSE [r.leaf_category] END |
  MERGE (c:Category {key: k})
  MERGE (p)-[:IN_CATEGORY]->(c))
FOREACH (i IN r.ingredients |
  MERGE (n:Ingredient {key: i.key})
  ON CREATE SET n.name = i.name
  SET n.inci = coalesce(i.inci, n.inci), n.name_vi = coalesce(i.name_vi, n.name_vi),
      n.role = coalesce(i.role, n.role),
      n.name = CASE WHEN i.inci IS NULL THEN n.name ELSE i.name END
  MERGE (p)-[:CONTAINS {rank: i.rank}]->(n))
FOREACH (s IN r.skin_types |
  MERGE (n:SkinType {key: s.key}) SET n.name = s.name
  MERGE (p)-[:SUITABLE_FOR {source: s.source}]->(n))
FOREACH (c IN r.concerns |
  MERGE (n:Concern {key: c.key}) SET n.name = c.name
  MERGE (p)-[:TARGETS {source: 'inferred'}]->(n))
FOREACH (b IN r.benefits |
  MERGE (n:Benefit {key: b.key}) SET n.name = b.name
  MERGE (p)-[:HAS_BENEFIT {source: 'inferred'}]->(n))
FOREACH (o IN r.origin |
  MERGE (n:Origin {key: o.key}) SET n.name = o.name
  MERGE (p)-[:MADE_IN]->(n))
RETURN count(p) AS n
"""

DEACTIVATE_MISSING = """
MATCH (p:Product) WHERE p.active = true AND NOT p.id IN $ids
SET p.active = false
RETURN count(p) AS n
"""

REPLACE_CONFLICTS = "MATCH (:Ingredient)-[old:CONFLICTS_WITH]->(:Ingredient) DELETE old"
UPSERT_CONFLICTS = """
UNWIND $pairs AS c
MERGE (a:Ingredient {key: c.a}) ON CREATE SET a.name = c.a_name, a.inci = c.a_name
MERGE (b:Ingredient {key: c.b}) ON CREATE SET b.name = c.b_name, b.inci = c.b_name
MERGE (a)-[r:CONFLICTS_WITH]->(b)
SET r.severity = c.severity, r.reason = c.reason
"""

REPLACE_HELPS = "MATCH (:Ingredient)-[old:HELPS]->(:Concern) DELETE old"
UPSERT_HELPS = """
UNWIND $rows AS h
MATCH (i:Ingredient {key: h.ingredient})
MERGE (c:Concern {key: h.concern}) ON CREATE SET c.name = h.name
MERGE (i)-[:HELPS]->(c)
"""

CLEAR_SIMILAR_FROM = """
UNWIND $ids AS id
MATCH (p:Product {id: id})-[s:SIMILAR_TO]->()
DELETE s
"""
CLEAR_SIMILAR_INACTIVE = "MATCH (p:Product {active: false})-[s:SIMILAR_TO]-() DELETE s"
UPSERT_SIMILAR = """
UNWIND $edges AS e
MATCH (a:Product {id: e.source}), (b:Product {id: e.target})
MERGE (a)-[s:SIMILAR_TO]->(b)
SET s.score = e.score, s.reason = e.reason, s.shared = e.shared
"""

STATS_NODES = "MATCH (n) RETURN labels(n)[0] AS label, count(*) AS n ORDER BY label"
STATS_RELS = "MATCH ()-[r]->() RETURN type(r) AS type, count(*) AS n ORDER BY type"


def _chunks(items: Sequence[Any], size: int) -> Iterable[Sequence[Any]]:
    for start in range(0, len(items), size):
        yield items[start:start + size]


class GraphLoader:
    def __init__(self, driver: Any, batch_size: int = 500, database: str | None = None) -> None:
        self.driver = driver
        self.batch_size = batch_size
        self.database = database

    def _write(self, cypher: str, **params: Any) -> list[dict[str, Any]]:
        def work(tx: Any) -> list[dict[str, Any]]:
            return [dict(record) for record in tx.run(cypher, **params)]

        with self.driver.session(database=self.database) as session:
            return session.execute_write(work)

    def _write_many(self, statements: list[tuple[str, dict[str, Any]]]) -> None:
        def work(tx: Any) -> None:
            for cypher, params in statements:
                tx.run(cypher, **params).consume()

        with self.driver.session(database=self.database) as session:
            session.execute_write(work)

    def upsert_categories(self, categories: list[dict[str, Any]], edges: list[dict[str, str]], replace_edges: bool) -> None:
        statements: list[tuple[str, dict[str, Any]]] = [(UPSERT_CATEGORIES, {"cats": categories})]
        if replace_edges:
            statements.append((REPLACE_CHILD_OF, {}))
        statements.append((UPSERT_CHILD_OF, {"edges": edges}))
        self._write_many(statements)

    def upsert_batch(self, rows: Sequence[dict[str, Any]], overwrite_names: bool = True) -> int:
        result = self._write(UPSERT_PRODUCTS, rows=list(rows), overwrite_names=overwrite_names)
        return int(result[0]["n"]) if result else 0

    def upsert_all(self, rows: Sequence[dict[str, Any]], overwrite_names: bool = True) -> int:
        total = 0
        for index, chunk in enumerate(_chunks(rows, self.batch_size), start=1):
            total += self.upsert_batch(chunk, overwrite_names)
            log.info("upserted batch %d (%d products, total %d)", index, len(chunk), total)
        return total

    def deactivate_missing(self, ids: Sequence[str]) -> int:
        result = self._write(DEACTIVATE_MISSING, ids=list(ids))
        return int(result[0]["n"]) if result else 0

    def load_conflicts(self, pairs: Sequence[ConflictPair], names: dict[str, str]) -> None:
        rows = [
            {"a": p.a, "b": p.b, "severity": p.severity, "reason": p.reason,
             "a_name": names.get(p.a, p.a), "b_name": names.get(p.b, p.b)}
            for p in pairs
        ]
        self._write_many([(REPLACE_CONFLICTS, {}), (UPSERT_CONFLICTS, {"pairs": rows})])

    def load_helps(self, rows: Sequence[dict[str, str]]) -> None:
        self._write_many([(REPLACE_HELPS, {}), (UPSERT_HELPS, {"rows": list(rows)})])

    def write_similar(self, edges: Sequence[dict[str, Any]], source_ids: Sequence[str], edge_batch: int = 5000) -> int:
        for chunk in _chunks(list(source_ids), self.batch_size):
            self._write(CLEAR_SIMILAR_FROM, ids=list(chunk))
        self._write(CLEAR_SIMILAR_INACTIVE)
        written = 0
        for chunk in _chunks(list(edges), edge_batch):
            self._write(UPSERT_SIMILAR, edges=list(chunk))
            written += len(chunk)
        return written

    def stats(self) -> dict[str, dict[str, int]]:
        with self.driver.session(database=self.database) as session:
            nodes = {r["label"]: int(r["n"]) for r in session.run(STATS_NODES)}
            rels = {r["type"]: int(r["n"]) for r in session.run(STATS_RELS)}
            active = session.run("MATCH (p:Product {active: true}) RETURN count(p) AS n").single()["n"]
        return {"nodes": nodes, "relationships": rels, "active_products": {"Product": int(active)}}
