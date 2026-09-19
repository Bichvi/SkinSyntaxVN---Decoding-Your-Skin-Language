"""Cached BM25 + Chroma retrieval fused with Reciprocal Rank Fusion."""

from __future__ import annotations

import logging
import math
import re
import time
import unicodedata
from collections import Counter, defaultdict
from dataclasses import dataclass
from threading import Lock
from typing import Any, Iterable

from . import config
from .product_repository import ProductRepository, product_text
from .retrieval import ChromaRetrieval, VectorHit, get_retrieval

logger = logging.getLogger(__name__)


def _norm(value: object) -> str:
    text = unicodedata.normalize("NFKD", str(value or "").lower())
    return "".join(ch for ch in text if not unicodedata.combining(ch))


def _tokens(value: object) -> list[str]:
    return [token for token in re.findall(r"[\w]+", _norm(value)) if len(token) > 1]


@dataclass(frozen=True)
class HybridHit:
    product: dict[str, Any]
    semantic_score: float = 0.0
    lexical_score: float = 0.0
    rrf_score: float = 0.0
    semantic_rank: int | None = None
    lexical_rank: int | None = None


class BM25Index:
    """In-memory BM25 index rebuilt only on TTL expiry or explicit invalidation."""

    def __init__(self, ttl_seconds: int = config.PRODUCT_REFRESH_TTL) -> None:
        self.ttl_seconds = max(1, ttl_seconds)
        self._products: list[dict[str, Any]] = []
        self._tokens: dict[str, list[str]] = {}
        self._term_frequency: dict[str, Counter[str]] = {}
        self._document_frequency: Counter[str] = Counter()
        self._avg_length = 1.0
        self._built_at = 0.0
        self._dirty = True
        self._lock = Lock()

    @property
    def ready(self) -> bool:
        return bool(self._products) and not self._dirty

    @property
    def products(self) -> list[dict[str, Any]]:
        """Return the current immutable-by-convention catalog snapshot."""

        return list(self._products)

    def is_stale(self) -> bool:
        return self._dirty or (time.monotonic() - self._built_at) >= self.ttl_seconds

    def invalidate(self, reason: str = "product_update") -> None:
        """Mark the index dirty; the next request rebuilds it once."""

        with self._lock:
            self._dirty = True
        logger.info("BM25 index invalidated", extra={"reason": reason})

    def ensure(self, products: Iterable[dict[str, Any]]) -> None:
        """Build one snapshot when missing, stale, or explicitly invalidated."""

        if not self.is_stale():
            return
        with self._lock:
            if not self.is_stale():
                return
            snapshot = [dict(product) for product in products if product.get("id")]
            frequencies: Counter[str] = Counter()
            term_frequency: dict[str, Counter[str]] = {}
            token_map: dict[str, list[str]] = {}
            total_length = 0
            for product in snapshot:
                product_id = str(product["id"])
                tokens = _tokens(product_text(product))
                token_map[product_id] = tokens
                term_frequency[product_id] = Counter(tokens)
                frequencies.update(set(tokens))
                total_length += len(tokens)
            self._products = snapshot
            self._tokens = token_map
            self._term_frequency = term_frequency
            self._document_frequency = frequencies
            self._avg_length = total_length / max(1, len(snapshot))
            self._built_at = time.monotonic()
            self._dirty = False
            logger.info("BM25 index ready", extra={"documents": len(snapshot)})

    def search(self, query: str, limit: int = config.TOP_K) -> list[tuple[str, float]]:
        """Return ``(product_id, score)`` in descending BM25 score order."""

        q_tokens = _tokens(query)
        if not q_tokens or not self._products:
            return []
        n_docs = len(self._products)
        scored: list[tuple[str, float]] = []
        for product in self._products:
            product_id = str(product["id"])
            terms = self._term_frequency.get(product_id, Counter())
            length = len(self._tokens.get(product_id, []))
            score = 0.0
            for token in q_tokens:
                frequency = terms.get(token, 0)
                if not frequency:
                    continue
                doc_frequency = self._document_frequency.get(token, 0)
                idf = math.log(1 + (n_docs - doc_frequency + 0.5) / (doc_frequency + 0.5))
                denominator = frequency + 1.5 * (1 - 0.75 + 0.75 * length / max(1.0, self._avg_length))
                score += idf * frequency * 2.5 / max(0.001, denominator)
            if score > 0:
                scored.append((product_id, score))
        scored.sort(key=lambda item: (-item[1], item[0]))
        return scored[: max(1, limit)]


class HybridSearch:
    """Combine lexical and semantic candidate rankings with RRF."""

    def __init__(
        self,
        repository: ProductRepository | None = None,
        retrieval: ChromaRetrieval | None = None,
        bm25: BM25Index | None = None,
        alpha: float = config.RRF_ALPHA,
        rrf_k: int = config.RRF_K,
    ) -> None:
        self.repository = repository or ProductRepository()
        self.retrieval = retrieval or get_retrieval()
        self.bm25 = bm25 or BM25Index()
        self.alpha = min(1.0, max(0.0, alpha))
        self.rrf_k = max(1, rrf_k)

    def invalidate(self, reason: str = "product_update") -> None:
        """Invalidate only the RAM lexical snapshot; Chroma remains reusable."""

        self.bm25.invalidate(reason)

    def search(self, query: str, limit: int = config.TOP_K, user_id: str = "") -> list[HybridHit]:
        """Run hybrid retrieval and return factual product candidates."""

        products = self.bm25.products if not self.bm25.is_stale() else self.repository.list_visible()
        self.bm25.ensure(products)
        product_by_id = {str(product.get("id")): product for product in products}

        lexical = self.bm25.search(query, limit=max(limit * 3, 20))
        semantic: list[VectorHit] = []
        try:
            semantic = self.retrieval.search(query, limit=max(limit * 3, 20))
        except Exception as exc:  # defensive boundary for connector implementations
            logger.warning("Semantic search degraded", extra={"user_id": user_id, "error": type(exc).__name__})

        lexical_rank = {product_id: (rank, score) for rank, (product_id, score) in enumerate(lexical, 1)}
        semantic_rank = {hit.product_id: (rank, hit.score, hit) for rank, hit in enumerate(semantic, 1)}
        all_ids = set(lexical_rank) | set(semantic_rank)
        rows: list[HybridHit] = []
        for product_id in all_ids:
            product = product_by_id.get(product_id)
            if product is None and product_id in semantic_rank:
                metadata = semantic_rank[product_id][2].metadata
                product = {
                    "id": product_id,
                    "ma_san_pham": product_id,
                    "name": str(metadata.get("name") or metadata.get("ten_san_pham") or ""),
                    "ten_san_pham": str(metadata.get("ten_san_pham") or metadata.get("name") or ""),
                    "category": str(metadata.get("category") or ""),
                    "loai_san_pham": str(metadata.get("category") or ""),
                    "price": int(metadata.get("price") or 0),
                    "gia_ban": int(metadata.get("price") or 0),
                    "ingredients": str(metadata.get("ingredients") or ""),
                    "thanh_phan_day_du": str(metadata.get("ingredients") or ""),
                }
            if not product:
                continue
            lex_rank, lex_score = lexical_rank.get(product_id, (None, 0.0))
            sem_rank, sem_score, _ = semantic_rank.get(product_id, (None, 0.0, None))
            rrf = 0.0
            if lex_rank is not None:
                rrf += (1 - self.alpha) / (self.rrf_k + lex_rank)
            if sem_rank is not None:
                rrf += self.alpha / (self.rrf_k + sem_rank)
            rows.append(HybridHit(dict(product), sem_score, lex_score, rrf, sem_rank, lex_rank))

        rows.sort(key=lambda hit: (-hit.rrf_score, -hit.semantic_score, -hit.lexical_score, str(hit.product.get("id"))))
        logger.info(
            "Hybrid retrieval complete",
            extra={"user_id": user_id, "lexical": len(lexical), "semantic": len(semantic), "rrf_k": self.rrf_k},
        )
        return rows[: max(1, limit)]


_default_search: HybridSearch | None = None


def get_hybrid_search() -> HybridSearch:
    """Return the process-local hybrid search orchestrator."""

    global _default_search
    if _default_search is None:
        _default_search = HybridSearch()
    return _default_search
