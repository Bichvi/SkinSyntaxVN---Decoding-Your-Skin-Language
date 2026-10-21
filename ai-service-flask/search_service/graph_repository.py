"""All Cypher for smart search lives here. Parameterized only — never format strings.

Data facts (Bước 03): ``IN_CATEGORY`` points only to LEAF categories, parents are reached via
``CHILD_OF`` (leaf 3 -> 2 -> 1), so a category filter is always
``(p)-[:IN_CATEGORY]->(:Category)-[:CHILD_OF*0..2]->(target)``. Category alternatives match
the leaf category (sua_rua_mat), never the level-2 group (lam_sach_da also holds toners).
Generic formulation ingredients (water, glycerin, ...) are excluded from "shared ingredient"
profiles via ``Ingredient.role``. Parameters keep stable types (price ints, skin_type "" not
null) because Neo4j caches plans per parameter-type signature; ``warm_plans`` compiles the
empty/non-empty list variants at worker start. Products with unknown price are kept. Verified with EXPLAIN/PROFILE on Neo4j 5.26 (Bước 05B).
"""

from __future__ import annotations

from typing import Any

# Ingredient roles that never make a meaningful "shared active" reason.
GENERIC_ROLES = ["base", "humectant", "emollient", "preservative", "fragrance"]
PROFILE_SIZE = 8
PROFILE_MAX_RANK = 15


# (1) Products of the brand; near=2 inside a requested category (leaf or parent),
# near=1 sibling leaf under the same level-2 parent, else 0.
SAME_BRAND = """
MATCH (:Brand {key:$brand})<-[:OF_BRAND]-(p:Product {active:true})
WHERE (p.price IS NULL OR (p.price >= $min_price AND p.price <= $max_price))
OPTIONAL MATCH (p)-[:IN_CATEGORY]->(c:Category)
WITH p, CASE
  WHEN c IS NULL OR size($categories) = 0 THEN 0
  WHEN exists { (c)-[:CHILD_OF*0..2]->(t:Category) WHERE t.key IN $categories } THEN 2
  WHEN exists { (c)-[:CHILD_OF]->(:Category)<-[:CHILD_OF]-(t:Category) WHERE t.key IN $categories } THEN 1
  ELSE 0 END AS near
WITH p, max(near) AS near
RETURN p.id AS id, near, p.sold AS sold, p.in_stock AS in_stock
ORDER BY near DESC, sold DESC, id ASC LIMIT 30
"""

# (2a) Same (leaf-expanded) category, other brands, ranked by intent ingredients first, then by
# shared actives with the brand's profile (taken from the brand's products in that category
# when it has any, otherwise the whole brand).
CATEGORY_ALT_BY_BRAND = """
MATCH (target:Category {key:$category})
MATCH (:Brand {key:$brand})<-[:OF_BRAND]-(bp:Product {active:true})
WITH target, collect(bp) AS brand_products
WITH target, brand_products,
     [x IN brand_products WHERE exists { (x)-[:IN_CATEGORY]->(:Category)-[:CHILD_OF*0..2]->(target) }] AS in_scope
UNWIND CASE WHEN size(in_scope) > 0 THEN in_scope ELSE brand_products END AS s
MATCH (s)-[r:CONTAINS]->(i:Ingredient)
WHERE r.rank <= $max_rank AND NOT coalesce(i.role, '') IN $generic_roles
WITH target, i, count(*) AS freq, min(r.rank) AS best_rank
ORDER BY freq DESC, best_rank ASC, i.key ASC LIMIT $profile_size
WITH target, collect(i) AS profile
MATCH (p:Product {active:true})-[:IN_CATEGORY]->(:Category)-[:CHILD_OF*0..2]->(target)
WHERE NOT exists { (p)-[:OF_BRAND]->(:Brand {key:$brand}) } AND (p.price IS NULL OR (p.price >= $min_price AND p.price <= $max_price))
OPTIONAL MATCH (p)-[:CONTAINS]->(sh:Ingredient) WHERE sh IN profile OR sh.key IN $ingredients
WITH p, profile, collect(DISTINCT sh) AS shared
WITH p, [x IN shared WHERE x.key IN $ingredients | x.name] AS wanted,
     [x IN profile WHERE x IN shared | x.name] AS common
RETURN p.id AS id, size(wanted) AS ingredient_hits, size(common) AS shared_count,
       (wanted + [n IN common WHERE NOT n IN wanted])[..3] AS shared_names, p.sold AS sold,
       head([(p)-[:OF_BRAND]->(pb:Brand) | pb.key]) AS brand
ORDER BY ingredient_hits DESC, shared_count DESC, sold DESC, id ASC LIMIT 60
"""

# (2b) No brand: same category ranked by requested ingredients, then sales.
CATEGORY_ALT_BY_SOLD = """
MATCH (target:Category {key:$category})
MATCH (p:Product {active:true})-[:IN_CATEGORY]->(:Category)-[:CHILD_OF*0..2]->(target)
WHERE (p.price IS NULL OR (p.price >= $min_price AND p.price <= $max_price))
OPTIONAL MATCH (p)-[:CONTAINS]->(x:Ingredient) WHERE x.key IN $ingredients
WITH p, collect(DISTINCT x.name) AS wanted
RETURN p.id AS id, size(wanted) AS ingredient_hits, 0 AS shared_count, wanted[..3] AS shared_names,
       p.sold AS sold, head([(p)-[:OF_BRAND]->(pb:Brand) | pb.key]) AS brand
ORDER BY ingredient_hits DESC, sold DESC, id ASC LIMIT 60
"""

# (2c) No category: neighbours of semantic seeds restricted to the seed's LEAF category.
# Score = SIMILAR_TO score (if any) + 0.05 per shared non-generic ingredient (top ranks).
SIMILAR_TO = """
MATCH (s:Product) WHERE s.id IN $seed_ids
MATCH (s)-[:IN_CATEGORY]->(:Category)<-[:IN_CATEGORY]-(p:Product {active:true})
WHERE NOT p.id IN $seed_ids AND NOT p.id IN $exclude_brand_ids AND (p.price IS NULL OR (p.price >= $min_price AND p.price <= $max_price))
OPTIONAL MATCH (s)-[r:SIMILAR_TO]-(p)
WITH s, p, max(coalesce(r.score, 0.0)) AS sim
OPTIONAL MATCH (s)-[rs:CONTAINS]->(i:Ingredient)<-[:CONTAINS]-(p)
WHERE rs.rank <= $max_rank AND NOT coalesce(i.role, '') IN $generic_roles
WITH p, sim, collect(i.name) AS shared
WITH p, max(sim + 0.05 * size(shared)) AS score, collect(shared) AS lists
WITH p, score, reduce(acc = [], l IN lists | acc + [n IN l WHERE NOT n IN acc]) AS shared
WHERE score > 0
RETURN p.id AS id, score, size(shared) AS shared_count, shared[..3] AS shared_names, p.sold AS sold,
       head([(p)-[:OF_BRAND]->(pb:Brand) | pb.key]) AS brand
ORDER BY score DESC, sold DESC, id ASC LIMIT 60
"""

# (3) Skin type / concern fit (skin_profile or intent), optional category, no conflicts with cart.
PERSONALIZED = """
MATCH (p:Product {active:true})
WHERE ($skin_type = '' OR exists { (p)-[:SUITABLE_FOR]->(:SkinType {key:$skin_type}) })
  AND (size($categories) = 0
       OR exists { (p)-[:IN_CATEGORY]->(:Category)-[:CHILD_OF*0..2]->(t:Category) WHERE t.key IN $categories })
  AND (size($concerns) = 0 OR $skin_type <> ''
       OR exists { (p)-[:TARGETS]->(c:Concern) WHERE c.key IN $concerns })
  AND (p.price IS NULL OR (p.price >= $min_price AND p.price <= $max_price))
  AND (size($cart_ids) = 0 OR NOT exists {
    MATCH (p)-[:CONTAINS]->(:Ingredient)-[:CONFLICTS_WITH]-(:Ingredient)<-[:CONTAINS]-(c2:Product)
    WHERE c2.id IN $cart_ids
  })
OPTIONAL MATCH (p)-[:TARGETS]->(cn:Concern) WHERE cn.key IN $concerns
WITH p, collect(cn.name) AS concern_names
RETURN p.id AS id, size(concern_names) AS concern_hits, concern_names[..3] AS concern_names, p.sold AS sold
ORDER BY concern_hits DESC, sold DESC, id ASC LIMIT 30
"""

# Touch every product relationship + ingredient node once so a freshly (re)started Neo4j has
# the (small) store in its page cache before user queries arrive (warm-up only).
WARM_CACHE = """
MATCH (p:Product)-[r]->(x)
RETURN count(r) AS rels, count(DISTINCT x) AS targets, sum(size(keys(x))) AS props
"""

# (4) Bước 08: CO_PURCHASED counts from cart products to candidate products (sum over cart).
CO_PURCHASED = """
MATCH (c:Product)-[r:CO_PURCHASED]->(p:Product)
WHERE c.id IN $cart_ids AND p.id IN $ids AND NOT p.id IN $cart_ids
RETURN p.id AS id, sum(r.count) AS count
"""

SKIN_TYPE_NAME = "MATCH (s:SkinType {key:$key}) RETURN s.name AS name"
BRAND_PRODUCT_IDS = "MATCH (:Brand {key:$brand})<-[:OF_BRAND]-(p:Product) RETURN collect(p.id) AS ids"

ALL_QUERIES = {
    "same_brand": SAME_BRAND,
    "category_alt_by_brand": CATEGORY_ALT_BY_BRAND,
    "category_alt_by_sold": CATEGORY_ALT_BY_SOLD,
    "similar_to": SIMILAR_TO,
    "personalized": PERSONALIZED,
    "co_purchased": CO_PURCHASED,
    "warm_cache": WARM_CACHE,
}


PRICE_MAX = 10**12


def _price(price: dict | None) -> dict[str, Any]:
    """Always integers (never null) so Neo4j reuses ONE cached plan per query: the plan cache
    keys on parameter types, and null vs int would compile (300ms-2s cold) a second plan."""

    price = price or {}
    lo, hi = price.get("min_vnd"), price.get("max_vnd")
    return {"min_price": int(lo) if lo is not None else 0, "max_price": int(hi) if hi is not None else PRICE_MAX}


class GraphRepository:
    def __init__(self, driver: Any, timeout_s: float | None = None) -> None:
        self.driver = driver
        self.timeout_s = timeout_s

    def _run(self, cypher: str, **params: Any) -> list[dict[str, Any]]:
        from neo4j import Query

        query = Query(cypher, timeout=self.timeout_s) if self.timeout_s else Query(cypher)
        with self.driver.session() as session:
            return [dict(record) for record in session.run(query, **params)]

    def same_brand(self, brand: str, categories: list[str], price: dict | None = None) -> list[dict[str, Any]]:
        return self._run(SAME_BRAND, brand=brand, categories=list(categories), **_price(price))

    def category_alt(self, category: str, brand: str | None, ingredients: list[str] | None = None,
                     price: dict | None = None) -> list[dict[str, Any]]:
        params = dict(category=category, ingredients=list(ingredients or []), **_price(price))
        if brand:
            rows = self._run(CATEGORY_ALT_BY_BRAND, brand=brand, generic_roles=GENERIC_ROLES,
                             max_rank=PROFILE_MAX_RANK, profile_size=PROFILE_SIZE, **params)
            if rows:
                return rows
        return self._run(CATEGORY_ALT_BY_SOLD, **params)

    def similar_to(self, seed_ids: list[str], exclude_brand: str | None = None,
                   price: dict | None = None) -> list[dict[str, Any]]:
        exclude_ids: list[str] = []
        if exclude_brand:
            rows = self._run(BRAND_PRODUCT_IDS, brand=exclude_brand)
            exclude_ids = list(rows[0]["ids"]) if rows else []
        return self._run(SIMILAR_TO, seed_ids=list(seed_ids), exclude_brand_ids=exclude_ids,
                         generic_roles=GENERIC_ROLES, max_rank=PROFILE_MAX_RANK, **_price(price))

    def personalized(self, skin_type: str | None, concerns: list[str], categories: list[str],
                     cart_ids: list[str], price: dict | None = None) -> list[dict[str, Any]]:
        return self._run(PERSONALIZED, skin_type=skin_type or "", concerns=list(concerns),
                         categories=list(categories), cart_ids=list(cart_ids), **_price(price))

    def co_purchased(self, cart_ids: list[str], ids: list[str]) -> dict[str, int]:
        if not cart_ids or not ids:
            return {}
        rows = self._run(CO_PURCHASED, cart_ids=[str(x) for x in cart_ids], ids=[str(x) for x in ids])
        return {str(r["id"]): int(r.get("count") or 0) for r in rows}

    def warm_cache(self) -> dict[str, Any]:
        rows = self._run(WARM_CACHE)
        return rows[0] if rows else {}

    def skin_type_name(self, key: str) -> str | None:
        rows = self._run(SKIN_TYPE_NAME, key=key)
        return str(rows[0]["name"]) if rows and rows[0].get("name") else None
