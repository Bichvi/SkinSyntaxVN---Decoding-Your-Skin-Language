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


SEARCH_TIMEOUT_MS = max(50, _int("SEARCH_TIMEOUT_MS", 800))
LIMIT_PER_SECTION = max(1, min(20, _int("SEARCH_LIMIT_PER_SECTION", 8)))
CACHE_TTL = max(0, _int("SEARCH_CACHE_TTL", 300))
TRENDING_CACHE_TTL = max(0, _int("SEARCH_TRENDING_CACHE_TTL", 600))
TRENDING_DAYS = max(1, _int("SEARCH_TRENDING_DAYS", 14))
CATALOG_TTL = max(30, _int("SEARCH_CATALOG_TTL", 300))
BRANCH_MAX_CANDIDATES = 30

SEARCH_GRAPH_ENABLED = _bool("SEARCH_GRAPH_ENABLED", False)
SEARCH_SEMANTIC_ENABLED = _bool("SEARCH_SEMANTIC_ENABLED", True)
# Off by default: in gunicorn (preload + fork) abandoned CPU cross-encoder runs (40 pairs ~290ms
# > 250ms budget) stalled workers 3-13s (measured step 05). Enable only with a faster scorer.
SEARCH_RERANK_ENABLED = _bool("SEARCH_RERANK_ENABLED", False)
RERANK_TOP_N = max(1, _int("SEARCH_RERANK_TOP_N", 40))
RERANK_BUDGET_MS = max(10, _int("SEARCH_RERANK_BUDGET_MS", 250))

WEIGHTS = {"graph": 0.35, "semantic": 0.30, "popularity": 0.20, "personal": 0.15}

# Bước 08: "bought together" boost from (:Product)-[:CO_PURCHASED]->(:Product) between the
# request's cart_ids and the candidates (graph_sync --derive co_purchase). Off by default.
SEARCH_CO_PURCHASE_ENABLED = _bool("SEARCH_CO_PURCHASE_ENABLED", False)
W_COP = 0.10
CO_PURCHASE_TIMEOUT_MS = max(20, _int("SEARCH_CO_PURCHASE_TIMEOUT_MS", 250))
OUT_OF_STOCK_PENALTY = 0.5

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
