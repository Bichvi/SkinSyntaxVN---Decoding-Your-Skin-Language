"""Candidate branches. Each returns at most 30 ``Candidate`` objects or raises.

Mode B (Bước 05B): ``semantic`` + ``trending`` always; graph branches (``same_brand``,
``category_alt``, ``personalized``) need a GraphRepository (SEARCH_GRAPH_ENABLED + Neo4j up).
"""

from __future__ import annotations

import json
import logging
import time
from concurrent.futures import Future
from datetime import datetime, timedelta
from threading import Lock
from typing import Any

from . import config
from .catalog import CatalogStore, get_mongo_db
from .ranker import Candidate
from .schemas import RecommendRequest

logger = logging.getLogger(__name__)
MAX = config.BRANCH_MAX_CANDIDATES


# ── Semantic (reuses recommendation_service.HybridSearch: BM25 + Chroma, RRF) ──
class CatalogVectorstore:
    """Lazy LangChain Chroma bound to the re-indexed ``catalog_products_v1`` collection."""

    def __init__(self) -> None:
        self._store: Any | None = None
        self._failed_at = 0.0
        self._lock = Lock()

    def get(self) -> Any | None:
        if self._store is not None:
            return self._store
        if self._failed_at and time.monotonic() - self._failed_at < 30:
            return None
        with self._lock:
            if self._store is None:
                try:
                    from langchain_chroma import Chroma
                    from shared.embedding_provider import get_embedding_function

                    if config.CHROMA_HOST:
                        import chromadb

                        client = chromadb.HttpClient(host=config.CHROMA_HOST, port=config.CHROMA_PORT)
                        self._store = Chroma(collection_name=config.CATALOG_COLLECTION, client=client,
                                             embedding_function=get_embedding_function())
                    else:
                        from recommendation_service import config as rec_config

                        self._store = Chroma(collection_name=config.CATALOG_COLLECTION,
                                             persist_directory=str(rec_config.CHROMA_DIR),
                                             embedding_function=get_embedding_function())
                except Exception as exc:
                    self._failed_at = time.monotonic()
                    logger.warning("catalog vectorstore unavailable: %s", type(exc).__name__)
                    return None
        return self._store

    # Duck-typed surface used by recommendation_service.retrieval.ChromaRetrieval.
    def similarity_search_with_relevance_scores(self, query: str, k: int = 4):
        store = self.get()
        if store is None:
            raise RuntimeError("chroma_unavailable")
        return store.similarity_search_with_relevance_scores(query, k=k)

    def similarity_search(self, query: str, k: int = 4):
        store = self.get()
        if store is None:
            raise RuntimeError("chroma_unavailable")
        return store.similarity_search(query, k=k)

    def health(self) -> str:
        store = self.get()
        if store is None:
            return "down"
        try:
            store._collection.count()
            return "ok"
        except Exception:
            return "down"


def build_hybrid_search(catalog: CatalogStore, vectorstore: CatalogVectorstore) -> Any:
    from recommendation_service.hybrid_search import BM25Index, HybridSearch
    from recommendation_service.retrieval import ChromaRetrieval

    # Long BM25 TTL: the catalog snapshot refreshes in the background instead.
    return HybridSearch(repository=catalog, retrieval=ChromaRetrieval(vectorstore=vectorstore),
                        bm25=BM25Index(ttl_seconds=config.CATALOG_TTL * 12))


class SemanticMemo:
    """Per-query hybrid-search result shared by ``semantic`` and ``category_alt`` (seeds).

    Concurrent callers for the same query wait for one in-flight search (no double embedding);
    results live ``ttl`` seconds in a small in-process LRU.
    """

    def __init__(self, hybrid: Any, catalog: CatalogStore | None = None, ttl: float = 30.0, size: int = 128) -> None:
        self.hybrid = hybrid
        self.catalog = catalog
        self.ttl = ttl
        self.size = size
        self._cache: dict[str, tuple[float, list[Any]]] = {}
        self._inflight: dict[str, Future] = {}
        self._lock = Lock()

    def hits(self, q: str) -> list[Any]:
        now = time.monotonic()
        with self._lock:
            entry = self._cache.get(q)
            if entry and entry[0] > now:
                return entry[1]
            fut = self._inflight.get(q)
            owner = fut is None
            if owner:
                fut = Future()
                self._inflight[q] = fut
        if not owner:
            return fut.result(timeout=config.SEARCH_TIMEOUT_MS / 1000)
        try:
            result = self._search(q)
            with self._lock:
                if self.ttl > 0:
                    self._cache[q] = (time.monotonic() + self.ttl, result)
                    if len(self._cache) > self.size:
                        oldest = sorted(self._cache, key=lambda k: self._cache[k][0])
                        for key in oldest[: len(self._cache) - self.size]:
                            self._cache.pop(key, None)
            fut.set_result(result)
            return result
        except BaseException as exc:
            fut.set_exception(exc)
            raise
        finally:
            with self._lock:
                self._inflight.pop(q, None)

    def _search(self, q: str) -> list[Any]:
        catalog, hybrid = self.catalog, self.hybrid
        if catalog is not None:
            if not catalog.products():
                raise RuntimeError("catalog_unavailable")  # never build an empty BM25 snapshot
            if getattr(hybrid, "_catalog_version", None) != catalog.version:
                hybrid.invalidate("catalog_refresh")
                hybrid._catalog_version = catalog.version
        return list(hybrid.search(q, limit=MAX))


def _in_price(product: dict, price: dict | None) -> bool:
    if not price:
        return True
    value = product.get("price")
    if not isinstance(value, (int, float)) or value <= 0:
        return True  # unknown price: keep (PHP shows the real price)
    lo, hi = price.get("min_vnd"), price.get("max_vnd")
    return (lo is None or value >= lo) and (hi is None or value <= hi)


def semantic(req: RecommendRequest, intent: dict | None, hybrid: Any, catalog: CatalogStore | None = None,
             memo: SemanticMemo | None = None) -> list[Candidate]:
    if memo is None:
        memo = SemanticMemo(hybrid, catalog, ttl=0)
    hits = memo.hits(req.q)
    categories = set((intent or {}).get("categories") or [])
    price = (intent or {}).get("price")
    out: list[Candidate] = []
    for hit in hits:
        pid = str(hit.product.get("id") or "")
        if not pid or not _in_price(hit.product, price):
            continue
        score = float(hit.rrf_score)
        keys = set(hit.product.get("category_keys") or [])
        if hit.product.get("category_key"):
            keys.add(str(hit.product.get("category_key")))
        if categories and keys & categories:
            score *= 1.2
        out.append(Candidate(pid, score, "semantic_match", "semantic"))
    return out[:MAX]


# ── Trending (Mongo read-only, Redis-cached 10 min) ──
class TrendingSource:
    REDIS_KEY = "search:trending:v1"

    def __init__(self, catalog: CatalogStore, db_factory=get_mongo_db, redis_factory=None) -> None:
        self.catalog = catalog
        self.db_factory = db_factory
        self.redis_factory = redis_factory
        self._local: tuple[float, list[list[Any]]] | None = None
        self._lock = Lock()

    def _redis(self) -> Any | None:
        try:
            if self.redis_factory is not None:
                return self.redis_factory()
            from shared.redis_store import get_redis

            return get_redis()
        except Exception:
            return None

    def _from_orders(self) -> list[list[Any]]:
        db = self.db_factory()
        if db is None:
            return []
        since = datetime.now() - timedelta(days=config.TRENDING_DAYS)
        order_ids = [
            row["ma_hoa_don"]
            for row in db["hoa_don"].find(
                {"ngay_dat": {"$gte": since}, "trang_thai": {"$nin": list(config.CANCELLED_ORDER_STATUSES)}},
                {"_id": 0, "ma_hoa_don": 1},
            )
            if row.get("ma_hoa_don") is not None
        ]
        if not order_ids:
            return []
        rows = db["chi_tiet_hoa_don"].aggregate([
            {"$match": {"ma_hoa_don": {"$in": order_ids}}},
            {"$group": {"_id": "$ma_san_pham", "qty": {"$sum": "$so_luong"}}},
            {"$sort": {"qty": -1}},
            {"$limit": 60},
        ])
        return [[str(r["_id"]), float(r.get("qty") or 0)] for r in rows if r.get("_id") is not None]

    def _from_sold(self) -> list[list[Any]]:
        products = sorted(self.catalog.products(), key=lambda p: -int(p.get("sold") or 0))
        return [[p["id"], float(p.get("sold") or 0)] for p in products[:60] if p.get("sold")]

    def top(self) -> tuple[list[list[Any]], str]:
        now = time.monotonic()
        if self._local and self._local[0] > now:
            return self._local[1], "local"
        client = self._redis()
        if client is not None:
            try:
                raw = client.get(self.REDIS_KEY)
                if raw:
                    rows = json.loads(raw)
                    self._local = (now + config.TRENDING_CACHE_TTL, rows)
                    return rows, "redis"
            except Exception:
                client = None
        with self._lock:
            rows = self._from_orders()
            source = "orders"
            if len(rows) < 8:
                rows = self._from_sold()
                source = "sold"
            self._local = (now + config.TRENDING_CACHE_TTL, rows)
            if client is not None and rows:
                try:
                    client.setex(self.REDIS_KEY, config.TRENDING_CACHE_TTL, json.dumps(rows))
                except Exception:
                    pass
        return rows, source


def trending(req: RecommendRequest, intent: dict | None, source: TrendingSource) -> list[Candidate]:
    rows, _ = source.top()
    stock = source.catalog.stock_map()
    out = [
        Candidate(pid, float(qty), "trending", "trending")
        for pid, qty in rows
        if stock.get(pid, True)
    ]
    return out[:MAX]


# ── Graph branches (mode B) ──
PER_BRAND_CAP = 2
SIMILAR_MIN_CONFIDENCE = 0.5
SIMILAR_SEEDS = 3


def _first(intent: dict | None, key: str) -> str | None:
    values = (intent or {}).get(key) or []
    return str(values[0]) if values else None


def _diversify(rows: list[dict], cap: int = PER_BRAND_CAP) -> list[dict]:
    """Keep order but allow at most ``cap`` products per brand before the overflow."""

    seen: dict[str, int] = {}
    head, tail = [], []
    for row in rows:
        brand = str(row.get("brand") or "")
        seen[brand] = seen.get(brand, 0) + 1
        (head if not brand or seen[brand] <= cap else tail).append(row)
    return head + tail


def same_brand(req, intent, graph) -> list[Candidate]:
    brand = _first(intent, "brands")
    if graph is None or not brand:
        return []
    rows = graph.same_brand(brand, list((intent or {}).get("categories") or []), (intent or {}).get("price"))
    return [Candidate(str(r["id"]), float(r.get("near") or 0) * 1000 + float(r.get("sold") or 0),
                      "same_brand", "same_brand", (brand,)) for r in rows][:MAX]


def _alt_candidates(rows: list[dict]) -> list[Candidate]:
    out = []
    n = len(rows)
    for pos, r in enumerate(_diversify(rows)):
        names = tuple(str(x) for x in (r.get("shared_names") or [])[:3])
        code = "shared_ingredients" if names else "same_category"
        # Rank-based score keeps the graph (diversified) order through min-max normalisation.
        out.append(Candidate(str(r["id"]), float(n - pos), code, "category_alt", names))
    return out[:MAX]


def category_alt(req, intent, graph, memo: SemanticMemo | None = None) -> list[Candidate]:
    """Same LEAF category alternatives (other brands), or SIMILAR_TO of semantic seeds."""

    if graph is None:
        return []
    intent = intent or {}
    category = _first(intent, "categories")
    brand = _first(intent, "brands")
    price = intent.get("price")
    if category:
        return _alt_candidates(graph.category_alt(category, brand, list(intent.get("ingredients") or []), price))
    # No category: neighbours of the top semantic hits, same leaf category only.
    if memo is None or float(intent.get("confidence") or 0) < SIMILAR_MIN_CONFIDENCE:
        return []
    seeds = [str(h.product.get("id")) for h in memo.hits(req.q)[:SIMILAR_SEEDS] if h.product.get("id")]
    if not seeds:
        return []
    return _alt_candidates(graph.similar_to(seeds, brand, price))


def personalized(req, intent, graph) -> list[Candidate]:
    """skin_profile from PHP, else skin types/concerns typed in the query (``srm cho da dầu``)."""

    intent = intent or {}
    profile = req.skin_profile or {}
    skin_type = profile.get("skin_type") or _first(intent, "skin_types")
    concerns = list(dict.fromkeys(list(profile.get("concerns") or []) + list(intent.get("concerns") or [])))
    if graph is None or not (skin_type or concerns):
        return []
    rows = graph.personalized(skin_type, concerns, list(intent.get("categories") or []), list(req.cart_ids),
                              intent.get("price"))
    skin_label = _label(graph, skin_type)
    out = []
    for r in rows:
        hits = int(r.get("concern_hits") or 0)
        if hits:
            params = tuple(str(n).lower() for n in (r.get("concern_names") or [])[:3])
            code = "targets_concern"
        else:
            params = (skin_label,) if skin_label else ()
            code = "fits_skin_type"
        out.append(Candidate(str(r["id"]), hits * 1000 + float(r.get("sold") or 0), code, "personalized", params))
    return out[:MAX]


_LABELS: dict[str, str] = {}


def _label(graph, skin_type: str | None) -> str | None:
    """Display label for a skin type ("da dầu"); cached, falls back to the key."""

    if not skin_type:
        return None
    if skin_type not in _LABELS:
        try:
            name = graph.skin_type_name(skin_type)
        except Exception:
            name = None
        if not name:
            return skin_type
        _LABELS[skin_type] = str(name).lower()
    return _LABELS[skin_type]
