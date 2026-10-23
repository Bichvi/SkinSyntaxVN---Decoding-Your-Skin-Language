"""Runtime configuration for the smart-search recommend API (env driven)."""

from __future__ import annotations

import os


def _bool(name: str, default: bool) -> bool:
    raw = os.getenv(name)
    if raw is None or raw.strip() == "":
        return default
    return raw.strip().lower() in {"1", "true", "yes", "on"}


def _int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except ValueError:
        return default


def _float(name: str, default: float) -> float:
    try:
        return float(os.getenv(name, str(default)))
    except ValueError:
        return default


SEARCH_TIMEOUT_MS = max(50, _int("SEARCH_TIMEOUT_MS", 800))
LIMIT_PER_SECTION = max(1, min(20, _int("SEARCH_LIMIT_PER_SECTION", 8)))
CACHE_TTL = max(0, _int("SEARCH_CACHE_TTL", 300))
INTENT_CACHE_TTL = max(0, _int("SEARCH_INTENT_CACHE_TTL", 600))  # U05: /api/search/intent responses
TRENDING_CACHE_TTL = max(0, _int("SEARCH_TRENDING_CACHE_TTL", 600))
TRENDING_DAYS = max(1, _int("SEARCH_TRENDING_DAYS", 14))
CATALOG_TTL = max(30, _int("SEARCH_CATALOG_TTL", 300))
BRANCH_MAX_CANDIDATES = 30

SEARCH_GRAPH_ENABLED = _bool("SEARCH_GRAPH_ENABLED", False)
SEARCH_SEMANTIC_ENABLED = _bool("SEARCH_SEMANTIC_ENABLED", True)
# Off by default: in gunicorn (preload + fork) abandoned CPU cross-encoder runs (40 pairs ~290ms
# > 250ms budget) stalled workers 3-13s (measured step 05). Enable only with a faster scorer.
SEARCH_RERANK_ENABLED = _bool("SEARCH_RERANK_ENABLED", False)
# U12: the cross-encoder gate (p95 of 40 pairs <= 120ms) FAILED on this hardware (fp32 ~630ms, int8 ~300ms), so it stays
# off. If a faster scorer ever passes the gate, it is applied to the TOP 20 only (not 40) inside a hard-timeout executor.
RERANK_TOP_N = max(1, _int("SEARCH_RERANK_TOP_N", 20))
RERANK_BUDGET_MS = max(10, _int("SEARCH_RERANK_BUDGET_MS", 250))

WEIGHTS = {"graph": 0.35, "semantic": 0.30, "popularity": 0.20, "personal": 0.15}

# Bước 08: "bought together" boost from (:Product)-[:CO_PURCHASED]->(:Product) between the
# request's cart_ids and the candidates (graph_sync --derive co_purchase). Off by default.
SEARCH_CO_PURCHASE_ENABLED = _bool("SEARCH_CO_PURCHASE_ENABLED", False)
W_COP = 0.10
# U07: small additive boost for products whose tags come from a person (admin "manual" or an approved
# LLM suggestion "llm_approved") instead of ingredient rules only. 0 turns it off.
W_CURATED = max(0.0, _float("SEARCH_W_CURATED", 0.05))
CO_PURCHASE_TIMEOUT_MS = max(20, _int("SEARCH_CO_PURCHASE_TIMEOUT_MS", 250))

# U12 part A: click-through-rate boost. Off by default: it needs >= 5000 impressions and >= 200 clicks in 30 days
# (test traffic excluded) before the numbers mean anything. The nightly job ``ctr_boost`` fills Redis hashes
# ``search:ctr:v1:<section>`` (Bayesian-smoothed, position-normalised); the ranker adds
# ``W_CTR * (ctr_norm - 0.5)`` only for products with >= CTR_MIN_IMPRESSIONS impressions.
SEARCH_CTR_BOOST_ENABLED = _bool("SEARCH_CTR_BOOST_ENABLED", False)
W_CTR = max(0.0, _float("SEARCH_W_CTR", 0.08))
CTR_MIN_IMPRESSIONS = max(1, _int("SEARCH_CTR_MIN_IMPRESSIONS", 50))
CTR_WINDOW_DAYS = max(1, _int("SEARCH_CTR_WINDOW_DAYS", 30))
CTR_HASH_TTL_S = max(3600, _int("SEARCH_CTR_HASH_TTL_S", 2 * 86400))
CTR_GATE_IMPRESSIONS = 5000
CTR_GATE_CLICKS = 200

# U12 part B: A/B experiments (data/experiments.yaml). Only ``status: running`` experiments are applied.
SEARCH_EXPERIMENTS_ENABLED = _bool("SEARCH_EXPERIMENTS_ENABLED", True)
EXPERIMENTS_FILE = os.getenv("SEARCH_EXPERIMENTS_FILE", "").strip()   # default: <service>/data/experiments.yaml
EXPERIMENTS_RELOAD_S = max(1, _int("SEARCH_EXPERIMENTS_RELOAD_S", 10))
EXPERIMENT_MIN_IMPRESSIONS = 1000     # per variant; below it the report says "not enough sample"
OUT_OF_STOCK_PENALTY = 0.5

# U08: "Sản phẩm tương tự" on the product detail page (GET /api/products/<id>/similar).
SEARCH_SIMILAR_ENABLED = _bool("SEARCH_SIMILAR_ENABLED", True)
SIMILAR_CACHE_TTL = max(0, _int("SEARCH_SIMILAR_CACHE_TTL", 3600))
SIMILAR_CANDIDATES = 40       # Cypher LIMIT
SIMILAR_DEFAULT_LIMIT = 8
SIMILAR_MAX_LIMIT = 20
SIMILAR_BRAND_CAP = 3         # at most N products of one brand
SIMILAR_MIN_AFTER_CAP = 4     # below this the brand cap is relaxed so the block is not nearly empty
W_SIMILAR_SAME_LEAF = 0.15
W_SIMILAR_SOLD = 0.05
W_SIMILAR_SKIN = 0.10

# U09: cart analysis (POST /api/cart/analyze): active-ingredient conflicts + missing routine steps.
SEARCH_CART_ENABLED = _bool("SEARCH_CART_ENABLED", True)
CART_CACHE_TTL = max(0, _int("SEARCH_CART_CACHE_TTL", 600))
CART_MAX_IDS = 50
CART_MAX_STEPS = 3            # suggested steps per response (core steps first)
CART_ITEMS_PER_STEP = 4
CART_CANDIDATES = 60          # Cypher LIMIT per step before conflict filtering / re-ranking
W_CART_BENEFIT = 0.5          # benefit overlap with the cart
W_CART_SOLD = 0.3
W_CART_SKIN = 0.2

# U03: sliding-window limit per caller (PHP BFF). Mostly stops error loops, so it is generous. 0 = off.
RATE_LIMIT_PER_WINDOW = max(0, _int("SEARCH_RATE_LIMIT_RPS", 50))
RATE_LIMIT_WINDOW_S = 1.0

# Shared-secret header for internal callers (PHP BFF). Empty = loopback/docker only.
INTERNAL_TOKEN = os.getenv("SEARCH_INTERNAL_TOKEN", "").strip()
INTERNAL_TOKEN_HEADER = "X-Internal-Token"

CHROMA_HOST = os.getenv("CHROMA_HOST", "").strip()
CHROMA_PORT = _int("CHROMA_PORT", 8000)
CATALOG_COLLECTION = os.getenv("SEARCH_CHROMA_COLLECTION", "catalog_products_v1").strip()

MONGO_URI = os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017")
MONGO_DB_NAME = os.getenv("MONGO_DB_NAME", os.getenv("MONGO_DB", "skinsyntax"))

NEO4J_URI = os.getenv("NEO4J_URI", "").strip()
NEO4J_USER = os.getenv("NEO4J_USER", "neo4j").strip()
NEO4J_PASSWORD = os.getenv("NEO4J_PASSWORD", "")

SECTION_ORDER = ("category_alt", "same_brand", "personalized", "semantic", "trending")
REASON_CODES = (
    "same_brand", "same_category", "shared_ingredients", "fits_skin_type",
    "targets_concern", "semantic_match", "trending", "bought_together",
)
CANCELLED_ORDER_STATUSES = ("Da huy", "Đã hủy")

# Intent parser (Bước 04): entity dictionary TTL, rapidfuzz brand cutoff, query cap.
SEARCH_DICT_TTL = max(30, _int("SEARCH_DICT_TTL", 600))
FUZZY_CUTOFF = max(50, min(100, _int("SEARCH_FUZZY_CUTOFF", 88)))
MAX_QUERY_LEN = 200
