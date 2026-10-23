"""U08: "Sản phẩm tương tự" for the product detail page - ``GET /api/products/<id>/similar``.

Primary source: ``(:Product)-[:SIMILAR_TO]-(:Product)`` (one read-only Cypher, see
``graph_repository.SIMILAR_PRODUCTS``). Neo4j down / no edges -> semantic fallback: the product's own
embedding in Chroma ``catalog_products_v1``, neighbours restricted to the same LEAF category, ``degraded=true``.

Pure helpers (``parse_args``, ``rank_candidates``, ``finalize``) carry the logic so tests need no database;
``SimilarProductsService`` wires graph / catalog / vectorstore / cache through injection.

Cache: Redis ``product:similar:v1:g<gen>:<id>:<skin|any>`` holds the ranked candidates (before ``exclude``
and ``limit``), so carts with different contents share one entry. ``publish_invalidate`` bumps ``<gen>``.
"""

from __future__ import annotations

import json
import logging
import re
import time
from dataclasses import dataclass
from typing import Any, Callable, Iterable, Mapping

from shared.redis_kit import keys, pubsub

from . import config, ranker

logger = logging.getLogger("search_service")

_ID_RE = re.compile(r"^[A-Za-z0-9_-]{1,32}$")
_SKIN_RE = re.compile(r"^[a-z0-9_]{1,32}$")
MAX_EXCLUDE = 50
REASONS = ("shared_ingredients", "same_category", "same_brand")
FALLBACK_CACHE_TTL = 120   # semantic fallback is cached briefly so a recovered Neo4j is picked up soon
SEMANTIC_POOL = 100        # Chroma neighbours fetched before the same-leaf filter


class SimilarValidationError(ValueError):
    def __init__(self, field_name: str) -> None:
        super().__init__(field_name)
        self.field = field_name


@dataclass(frozen=True)
class SimilarRequest:
    product_id: str
    limit: int = config.SIMILAR_DEFAULT_LIMIT
    exclude: tuple[str, ...] = ()
    skin_type: str | None = None


def parse_args(product_id: Any, limit: Any = None, exclude: Any = None, skin_type: Any = None) -> SimilarRequest:
    """Validate the query string. Raises ``SimilarValidationError(field)``."""

    pid = str(product_id or "").strip()
    if not _ID_RE.match(pid):
        raise SimilarValidationError("product_id")
    n = config.SIMILAR_DEFAULT_LIMIT
    if limit not in (None, ""):
        try:
            n = int(str(limit).strip())
        except ValueError:
            raise SimilarValidationError("limit") from None
        if n < 1 or n > config.SIMILAR_MAX_LIMIT:
            raise SimilarValidationError("limit")
    ids: list[str] = []
    for part in str(exclude or "").split(","):
        part = part.strip()
        if not part:
            continue
        if not _ID_RE.match(part):
            raise SimilarValidationError("exclude")
        if part not in ids:
            ids.append(part)
    if len(ids) > MAX_EXCLUDE:
        raise SimilarValidationError("exclude")
    skin = str(skin_type or "").strip().lower()
    if skin and not _SKIN_RE.match(skin):
        raise SimilarValidationError("skin_type")
    return SimilarRequest(pid, n, tuple(ids), skin or None)


def _reason(row: Mapping[str, Any]) -> tuple[str, list[str]]:
    code = str(row.get("reason") or "")
    if code not in REASONS:
        code = "same_category"
    if code == "shared_ingredients":
        shared = [str(x) for x in (row.get("shared") or []) if str(x).strip()][:3]
        return ("shared_ingredients", shared) if shared else ("same_category", [])
    return code, []


def rank_candidates(rows: Iterable[Mapping[str, Any]]) -> list[dict[str, Any]]:
    """Rows from Cypher / semantic fallback -> candidates sorted by ``final`` (best first).

    ``final = score + 0.15*same_leaf + 0.05*log1p(sold)_norm - 0.5*(not in_stock) + 0.1*skin_fit``.
    A product that appears twice (pair stored in both directions) keeps its best ``score``.
    """

    best: dict[str, dict[str, Any]] = {}
    for row in rows:
        pid = str(row.get("id") or "")
        if not pid:
            continue
        score = float(row.get("score") or 0.0)
        prev = best.get(pid)
        if prev is None or score > prev["score"]:
            best[pid] = {
                "id": pid, "score": score, "same_leaf": bool(row.get("same_leaf")),
                "in_stock": row.get("in_stock") is not False,
                "sold": int(row.get("sold") or 0), "brand": str(row.get("brand") or ""),
                "skin_fit": bool(row.get("skin_fit")), "row": row,
            }
        else:
            prev["same_leaf"] = prev["same_leaf"] or bool(row.get("same_leaf"))
            prev["skin_fit"] = prev["skin_fit"] or bool(row.get("skin_fit"))
    popularity = ranker.popularity_scores({pid: c["sold"] for pid, c in best.items()}, best.keys())
    out: list[dict[str, Any]] = []
    for pid, c in best.items():
        final = (c["score"] + config.W_SIMILAR_SAME_LEAF * c["same_leaf"]
                 + config.W_SIMILAR_SOLD * popularity.get(pid, 0.0)
                 + config.W_SIMILAR_SKIN * c["skin_fit"]
                 - (0.0 if c["in_stock"] else config.OUT_OF_STOCK_PENALTY))
        code, params = _reason(c["row"])
        out.append({"product_id": pid, "score": round(final, 4), "reason_code": code, "reason_params": params,
                    "brand": c["brand"], "sold": c["sold"]})
    out.sort(key=lambda x: (-x["score"], -x["sold"], x["product_id"]))
    return out


def diversify(items: list[dict[str, Any]], cap: int = config.SIMILAR_BRAND_CAP,
              min_keep: int = config.SIMILAR_MIN_AFTER_CAP) -> list[dict[str, Any]]:
    """At most ``cap`` products per brand (unknown brand = uncapped). If that leaves fewer than
    ``min_keep`` items, the best overflow is appended so a one-brand neighbourhood still shows something."""

    seen: dict[str, int] = {}
    kept: list[dict[str, Any]] = []
    overflow: list[dict[str, Any]] = []
    for item in items:
        brand = item.get("brand") or ""
        seen[brand] = seen.get(brand, 0) + 1
        (kept if not brand or seen[brand] <= cap else overflow).append(item)
    if len(kept) < min_keep:
        kept.extend(overflow[: min_keep - len(kept)])
    return kept


def finalize(candidates: list[dict[str, Any]], exclude: Iterable[str], limit: int) -> list[dict[str, Any]]:
    """Drop excluded ids, apply the brand cap, cut to ``limit``; public shape (no internal fields)."""

    skip = set(exclude)
    remaining = [c for c in candidates if c["product_id"] not in skip]
    picked = diversify(remaining, min_keep=min(config.SIMILAR_MIN_AFTER_CAP, limit))[:limit]
    return [{"product_id": c["product_id"], "score": c["score"], "reason_code": c["reason_code"],
             "reason_params": list(c["reason_params"])} for c in picked]


def semantic_rows(product_id: str, catalog: Any, vectorstore: Any, pool: int = SEMANTIC_POOL) -> list[dict[str, Any]]:
    """Neighbours of the product's own embedding, same leaf category only. Raises when Chroma is unavailable."""

    store = vectorstore.get() if vectorstore is not None else None
    if store is None:
        raise RuntimeError("chroma_unavailable")
    collection = store._collection
    got = collection.get(ids=[product_id], include=["embeddings"])
    embeddings = got.get("embeddings") if isinstance(got, dict) else None
    if embeddings is None or len(embeddings) == 0:
        return []
    vector = [float(x) for x in embeddings[0]]
    result = collection.query(query_embeddings=[vector], n_results=pool, include=["distances"])
    ids = list((result.get("ids") or [[]])[0])
    distances = list((result.get("distances") or [[]])[0])
    target = catalog.get(product_id) if catalog is not None else None
    target_keys = list((target or {}).get("category_keys") or [])
    leaf = target_keys[0] if target_keys else ""
    rows: list[dict[str, Any]] = []
    for pid, dist in zip(ids, distances):
        pid = str(pid)
        if pid == product_id:
            continue
        product = catalog.get(pid) if catalog is not None else None
        if product is None:
            continue    # hidden / not in the visible catalog
        keys_ = list(product.get("category_keys") or [])
        if leaf and (not keys_ or keys_[0] != leaf):
            continue
        rows.append({
            "id": pid, "score": 1.0 / (1.0 + max(0.0, float(dist))), "reason": "same_category", "shared": [],
            "same_leaf": bool(leaf), "in_stock": product.get("in_stock", True), "sold": product.get("sold") or 0,
            "brand": str(product.get("thuong_hieu") or "").strip().lower(), "skin_fit": False,
        })
    return rows


class RedisSimilarCache:
    """Redis-only; any failure means "no cache". Keyed by the shared cache generation."""

    def __init__(self, redis_factory: Callable[[], Any] | None = None) -> None:
        self.redis_factory = redis_factory

    def _client(self) -> Any | None:
        if self.redis_factory is not None:
            return self.redis_factory()
        from shared.redis_kit import client as redis_client

        return redis_client.get_redis()

    def _key(self, client: Any, req: SimilarRequest) -> str:
        return keys.product_similar_key(req.product_id, req.skin_type, pubsub.current_generation(client))

    def get(self, req: SimilarRequest) -> dict | None:
        if config.SIMILAR_CACHE_TTL <= 0:
            return None
        try:
            client = self._client()
            if client is None:
                return None
            raw = client.get(self._key(client, req))
            return json.loads(raw) if raw else None
        except Exception:
            return None

    def set(self, req: SimilarRequest, value: dict, ttl: int) -> None:
        if config.SIMILAR_CACHE_TTL <= 0 or ttl <= 0:
            return
        try:
            client = self._client()
            if client is not None:
                client.setex(self._key(client, req), ttl, json.dumps(value, ensure_ascii=False))
        except Exception:
            pass


class SimilarProductsService:
    def __init__(self, graph: Any | None, catalog: Any | None, vectorstore: Any | None,
                 cache: Any | None = None) -> None:
        self.graph = graph
        self.catalog = catalog
        self.vectorstore = vectorstore
        self.cache = cache

    def _from_graph(self, req: SimilarRequest) -> list[dict[str, Any]] | None:
        """Ranked candidates, or None when the graph cannot answer (down / disabled / no edges)."""

        if self.graph is None:
            return None
        try:
            rows = self.graph.similar_products(req.product_id, req.skin_type or "", config.SIMILAR_CANDIDATES)
        except Exception as exc:
            logger.warning("similar_products graph degraded: %s", type(exc).__name__)
            return None
        return rank_candidates(rows) if rows else None

    def _from_semantic(self, req: SimilarRequest) -> list[dict[str, Any]]:
        try:
            return rank_candidates(semantic_rows(req.product_id, self.catalog, self.vectorstore))
        except Exception as exc:
            logger.warning("similar_products semantic degraded: %s", type(exc).__name__)
            return []

    def similar(self, req: SimilarRequest, correlation_id: str = "") -> dict:
        started = time.perf_counter()
        entry = self.cache.get(req) if self.cache is not None else None
        cache_hit = entry is not None
        if entry is None:
            candidates = self._from_graph(req)
            if candidates is not None:
                entry = {"degraded": False, "source": "graph", "candidates": candidates}
                ttl = config.SIMILAR_CACHE_TTL
            else:
                candidates = self._from_semantic(req)
                entry = {"degraded": True, "source": "semantic", "candidates": candidates}
                ttl = FALLBACK_CACHE_TTL if candidates else 0
            if self.cache is not None and ttl:
                self.cache.set(req, entry, ttl)
        items = finalize(entry["candidates"], (req.product_id, *req.exclude), req.limit)
        body = {
            "product_id": req.product_id,
            "items": items,
            "degraded": bool(entry["degraded"]),
            "source": entry["source"],
            "took_ms": int((time.perf_counter() - started) * 1000),
        }
        logger.info(json.dumps({
            "event": "similar_products", "correlation_id": correlation_id, "product_id": req.product_id,
            "source": body["source"], "count": len(items), "cache_hit": cache_hit, "took_ms": body["took_ms"],
        }, ensure_ascii=False))
        return body

