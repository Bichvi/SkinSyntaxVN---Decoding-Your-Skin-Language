// 001 — constraints & indexes (idempotent). Neo4j is a derived copy of MongoDB.
CREATE CONSTRAINT product_id IF NOT EXISTS FOR (n:Product) REQUIRE n.id IS UNIQUE;
CREATE CONSTRAINT brand_key IF NOT EXISTS FOR (n:Brand) REQUIRE n.key IS UNIQUE;
CREATE CONSTRAINT category_key IF NOT EXISTS FOR (n:Category) REQUIRE n.key IS UNIQUE;
CREATE CONSTRAINT ingredient_key IF NOT EXISTS FOR (n:Ingredient) REQUIRE n.key IS UNIQUE;
CREATE CONSTRAINT skintype_key IF NOT EXISTS FOR (n:SkinType) REQUIRE n.key IS UNIQUE;
CREATE CONSTRAINT concern_key IF NOT EXISTS FOR (n:Concern) REQUIRE n.key IS UNIQUE;
CREATE CONSTRAINT benefit_key IF NOT EXISTS FOR (n:Benefit) REQUIRE n.key IS UNIQUE;
CREATE INDEX product_active IF NOT EXISTS FOR (n:Product) ON (n.active);
CREATE INDEX product_sold IF NOT EXISTS FOR (n:Product) ON (n.sold);
CREATE FULLTEXT INDEX entity_name IF NOT EXISTS FOR (n:Product|Brand|Category|Ingredient) ON EACH [n.name];
