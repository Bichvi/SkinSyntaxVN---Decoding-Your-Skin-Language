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
GENERIC_ROLES = ["base", "humectant", "emollient", "preservative", "fragrance",
                 "colorant", "thickener", "emulsifier"]   # last three added with the U06 INCI aliases
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
    MATCH (p)-[:CONTAINS]->(:Ingredient)-[cf:CONFLICTS_WITH]-(:Ingredient)<-[:CONTAINS]-(c2:Product)
    WHERE c2.id IN $cart_ids AND cf.severity <> 'low'
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

# (5) U08: neighbours of one product on the detail page. SIMILAR_TO is stored in ONE direction per
# pair (each product keeps its top-k), so it is read undirected; a pair stored both ways yields two
# rows that ``similar.rank_candidates`` merges. ``same_leaf`` = shares the leaf category (IN_CATEGORY
# points only at leaves). ``$skin_type`` is always a string ("" = none) to keep one cached plan.
SIMILAR_PRODUCTS = """
MATCH (p:Product {id:$id})-[s:SIMILAR_TO]-(q:Product {active:true})
WHERE q.id <> $id
OPTIONAL MATCH (p)-[:IN_CATEGORY]->(c:Category)<-[:IN_CATEGORY]-(q)
WITH q, s, c IS NOT NULL AS same_leaf
RETURN q.id AS id, s.score AS score, s.reason AS reason, s.shared AS shared,
       same_leaf, q.in_stock AS in_stock, q.sold AS sold,
       head([(q)-[:OF_BRAND]->(b:Brand) | b.key]) AS brand,
       ($skin_type <> '' AND exists { (q)-[:SUITABLE_FOR]->(:SkinType {key:$skin_type}) }) AS skin_fit
ORDER BY same_leaf DESC, s.score DESC LIMIT $limit
"""

# (6) U09 cart analysis. Conflicts between cart products through (Ingredient)-[:CONFLICTS_WITH]-(Ingredient)
# edges (graph_sync --conflicts-only, data/graph/conflict_rules.yaml). Same result as UNWIND ids x ids with
# id1 < id2, but anchored on the id lists so the planner seeks both products by id. Ranks come back so
# cart_analyze can drop pH-adjuster-style hits (rank_limits of the rules file); grouping/severity is Python.
CART_CONFLICTS = """
MATCH (p1:Product)-[r1:CONTAINS]->(i1:Ingredient)-[c:CONFLICTS_WITH]-(i2:Ingredient)<-[r2:CONTAINS]-(p2:Product)
WHERE p1.id IN $ids AND p2.id IN $ids AND p1.id < p2.id
RETURN p1.id AS id1, p2.id AS id2, i1.key AS key1, i1.name AS name1, r1.rank AS rank1,
       i2.key AS key2, i2.name AS name2, r2.rank AS rank2,
       c.severity AS severity, c.timing AS timing, c.rule_id AS rule_id, c.advice_vi AS advice_vi,
       c.evidence AS evidence
"""

# Category chain (leaf + CHILD_OF ancestors, same shape as catalog ``category_keys``) and benefits of cart products.
CART_CATEGORIES = """
MATCH (p:Product) WHERE p.id IN $ids
OPTIONAL MATCH (p)-[:IN_CATEGORY]->(:Category)-[:CHILD_OF*0..2]->(t:Category)
RETURN p.id AS id, collect(DISTINCT t.key) AS categories
"""
# Benefits + conflict-relevant ingredients (key + rank) of cart products: the profile the suggestions are
# scored against and the cart side of the "does this candidate conflict with the cart" check.
CART_PROFILE = """
MATCH (p:Product) WHERE p.id IN $ids
RETURN p.id AS id,
       [(p)-[:HAS_BENEFIT]->(b:Benefit) | b.key] AS benefits,
       [(p)-[r:CONTAINS]->(i:Ingredient) WHERE i.key IN $conflict_keys | {key: i.key, rank: r.rank}] AS conflict_ings
"""

# Candidates for one missing routine step: in stock, not in the cart; best sellers first. The conflict-relevant
# ingredients (key + rank) are returned so the caller can drop products that conflict with the cart.
# $skin_type is always a string ("" = none) to keep one cached plan.
ROUTINE_CANDIDATES = """
MATCH (p:Product {active:true})-[:IN_CATEGORY]->(:Category)-[:CHILD_OF*0..2]->(t:Category)
WHERE t.key IN $categories AND NOT p.id IN $cart_ids AND p.in_stock <> false
WITH DISTINCT p
RETURN p.id AS id, p.sold AS sold,
       ($skin_type <> '' AND exists { (p)-[:SUITABLE_FOR]->(:SkinType {key:$skin_type}) }) AS skin_fit,
       [(p)-[:HAS_BENEFIT]->(b:Benefit) | b.key] AS benefits,
       [(p)-[r:CONTAINS]->(i:Ingredient) WHERE i.key IN $conflict_keys | {key: i.key, rank: r.rank}] AS conflict_ings,
       head([(p)-[:OF_BRAND]->(br:Brand) | br.key]) AS brand
ORDER BY sold DESC, id ASC LIMIT $limit
"""

# (7) U10 Syna graph tools. ``find_products`` filters by every requested constraint at once; each constraint is a
# parameter (never concatenated into the text). A constraint is absent when its ``$f_*`` flag is false (or the string
# is ''); list parameters are then padded with a '' sentinel so every call has the SAME parameter types and one cached
# plan (an empty list and a list of strings compile separately, ~0.5s each). ``$include_groups`` is a list of
# ingredient families (any key of a family satisfies it), ``$exclude_keys`` are forbidden ingredients, ``$avoid_keys``
# are ingredients the product must not CONFLICT with (medium/high edges only, ingredient above its rank limit ignored
# like the cart analysis). When an exclusion/avoid constraint is present, products with fewer than 3 mapped ingredients
# are skipped: an unparsed ingredient list must never read as "free of" something. ``$universal`` lets the
# "Mọi loại da" tag satisfy a skin type (the result says which products matched exactly).
FIND_PRODUCTS = """
MATCH (p:Product {active:true})
WHERE (p.price IS NULL OR (p.price >= $min_price AND p.price <= $max_price))
  AND (NOT $f_categories
       OR exists { (p)-[:IN_CATEGORY]->(:Category)-[:CHILD_OF*0..2]->(t:Category) WHERE t.key IN $categories })
  AND ($brand = '' OR exists { (p)-[:OF_BRAND]->(:Brand {key:$brand}) })
  AND ($skin_type = ''
       OR exists { (p)-[:SUITABLE_FOR]->(st:SkinType) WHERE st.key = $skin_type OR ($universal AND st.key = 'moi_loai_da') })
  AND (NOT $f_concerns OR ALL(c IN $concerns WHERE exists { (p)-[:TARGETS]->(:Concern {key:c}) }))
  AND (NOT $f_benefits OR ALL(b IN $benefits WHERE exists { (p)-[:HAS_BENEFIT]->(:Benefit {key:b}) }))
  AND (NOT $f_include OR ALL(fam IN $include_groups WHERE exists { (p)-[:CONTAINS]->(i:Ingredient) WHERE i.key IN fam }))
  AND (NOT $need_ingredient_data OR size([(p)-[:CONTAINS]->(x:Ingredient) | x]) >= 3)
  AND (NOT $f_exclude OR NOT exists { (p)-[:CONTAINS]->(e:Ingredient) WHERE e.key IN $exclude_keys })
  AND (NOT $f_avoid OR NOT exists {
    (p)-[r:CONTAINS]->(i2:Ingredient)-[cf:CONFLICTS_WITH]-(a:Ingredient)
    WHERE a.key IN $avoid_keys AND cf.severity IN ['medium', 'high'] AND r.rank <= coalesce($rank_limits[i2.key], 10000)
  })
WITH p, exists { (p)-[:SUITABLE_FOR]->(:SkinType {key:$skin_type}) } AS skin_exact
RETURN p.id AS id, p.name AS name, p.price AS price, p.in_stock AS in_stock, p.sold AS sold, skin_exact,
       head([(p)-[:OF_BRAND]->(b:Brand) | b.name]) AS brand,
       head([(p)-[:IN_CATEGORY]->(c:Category) | c.name]) AS category,
       [(p)-[r:CONTAINS]->(i:Ingredient) WHERE any(fam IN $include_groups WHERE i.key IN fam)
         | {key: i.key, name: i.name, rank: r.rank}] AS include_hits,
       [(p)-[r:TARGETS]->(c:Concern) WHERE c.key IN $concerns | {key: c.key, name: c.name, source: r.source}] AS concern_hits,
       [(p)-[r:HAS_BENEFIT]->(b:Benefit) WHERE b.key IN $benefits | {key: b.key, name: b.name, source: r.source}] AS benefit_hits,
       size([(p)-[:CONTAINS]->(x:Ingredient) | x]) AS n_ingredients
ORDER BY skin_exact DESC, p.sold DESC, p.id ASC LIMIT $fetch
"""

# (8) U10 ``explain_product``: key actives, benefits/concerns (with ``source``), skin types and a probe of requested
# ingredient keys ("có cồn không?") for ONE product. Read-only, parameterized.
EXPLAIN_PRODUCT = """
MATCH (p:Product {id:$id})
RETURN p.id AS id, p.name AS name, p.price AS price, p.in_stock AS in_stock, p.active AS active,
       head([(p)-[:OF_BRAND]->(b:Brand) | b.name]) AS brand,
       head([(p)-[:IN_CATEGORY]->(c:Category) | c.name]) AS category,
       [(p)-[r:CONTAINS]->(i:Ingredient) WHERE i.role IN ['active', 'uv_filter'] AND r.rank <= $max_rank
         | {key: i.key, name: i.name, rank: r.rank, role: i.role}] AS actives,
       [(p)-[r:CONTAINS]->(i:Ingredient) WHERE i.key IN $probe_keys | {key: i.key, name: i.name, rank: r.rank}] AS probe_hits,
       [(p)-[r:HAS_BENEFIT]->(b:Benefit) | {key: b.key, name: b.name, source: r.source}] AS benefits,
       [(p)-[r:TARGETS]->(c:Concern) | {key: c.key, name: c.name, source: r.source}] AS concerns,
       [(p)-[r:SUITABLE_FOR]->(s:SkinType) | {key: s.key, name: s.name, source: r.source}] AS skin_types,
       size([(p)-[:CONTAINS]->(x:Ingredient) | x]) AS n_ingredients
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
    "similar_products": SIMILAR_PRODUCTS,
    "cart_conflicts": CART_CONFLICTS,
    "cart_categories": CART_CATEGORIES,
    "cart_profile": CART_PROFILE,
    "routine_candidates": ROUTINE_CANDIDATES,
    "find_products": FIND_PRODUCTS,
    "explain_product": EXPLAIN_PRODUCT,
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

    def similar_products(self, product_id: str, skin_type: str | None = None, limit: int = 40) -> list[dict[str, Any]]:
        return self._run(SIMILAR_PRODUCTS, id=str(product_id), skin_type=skin_type or "", limit=int(limit))

    def cart_conflicts(self, ids: list[str]) -> list[dict[str, Any]]:
        if len(ids) < 2:
            return []
        return self._run(CART_CONFLICTS, ids=[str(x) for x in ids])

    def cart_categories(self, ids: list[str]) -> dict[str, list[str]]:
        if not ids:
            return {}
        return {str(r["id"]): [str(k) for k in (r["categories"] or []) if k]
                for r in self._run(CART_CATEGORIES, ids=[str(x) for x in ids])}

    def cart_profile(self, ids: list[str], conflict_keys: list[str]) -> dict[str, dict[str, Any]]:
        if not ids:
            return {}
        rows = self._run(CART_PROFILE, ids=[str(x) for x in ids], conflict_keys=[str(k) for k in conflict_keys])
        return {str(r["id"]): {"benefits": [str(b) for b in (r["benefits"] or [])],
                               "conflict_ings": list(r["conflict_ings"] or [])} for r in rows}

    def routine_candidates(self, categories: list[str], cart_ids: list[str], skin_type: str | None,
                           conflict_keys: list[str], limit: int = 60) -> list[dict[str, Any]]:
        return self._run(ROUTINE_CANDIDATES, categories=[str(c) for c in categories],
                         cart_ids=[str(x) for x in cart_ids], skin_type=skin_type or "",
                         conflict_keys=[str(k) for k in conflict_keys], limit=int(limit))

    def find_products(self, *, categories: list[str], brand: str, skin_type: str, universal: bool,
                      concerns: list[str], benefits: list[str], include_groups: list[list[str]],
                      exclude_keys: list[str], avoid_keys: list[str], rank_limits: dict[str, int],
                      price: dict | None = None, fetch: int = 80) -> list[dict[str, Any]]:
        """U10. Every argument is validated by ``chatbot_service.graph_tools``; types stay stable for plan reuse."""

        def pad(values: list[str]) -> list[str]:
            return [str(v) for v in values] or [""]

        groups = [[str(k) for k in fam] for fam in include_groups if fam]
        return self._run(FIND_PRODUCTS, categories=pad(categories), brand=brand or "",
                         skin_type=skin_type or "", universal=bool(universal), concerns=pad(concerns),
                         benefits=pad(benefits), include_groups=groups or [[""]],
                         exclude_keys=pad(exclude_keys), avoid_keys=pad(avoid_keys),
                         rank_limits={str(k): int(v) for k, v in rank_limits.items()} or {"": 10000},
                         f_categories=bool(categories), f_concerns=bool(concerns), f_benefits=bool(benefits),
                         f_include=bool(groups), f_exclude=bool(exclude_keys), f_avoid=bool(avoid_keys),
                         need_ingredient_data=bool(exclude_keys or avoid_keys), fetch=int(fetch), **_price(price))

    def explain_product(self, product_id: str, probe_keys: list[str] | None = None, max_rank: int = 15) -> dict[str, Any] | None:
        rows = self._run(EXPLAIN_PRODUCT, id=str(product_id), probe_keys=[str(k) for k in (probe_keys or [])] or [""],
                         max_rank=int(max_rank))
        return rows[0] if rows else None

    def warm_cache(self) -> dict[str, Any]:
        rows = self._run(WARM_CACHE)
        return rows[0] if rows else {}

    def skin_type_name(self, key: str) -> str | None:
        rows = self._run(SKIN_TYPE_NAME, key=key)
        return str(rows[0]["name"]) if rows and rows[0].get("name") else None
