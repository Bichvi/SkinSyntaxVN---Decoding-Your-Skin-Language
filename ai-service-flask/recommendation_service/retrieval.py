"""Chroma retrieval and refresh helpers for recommendation documents."""

from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any, Iterable

from . import config
from .product_repository import product_text

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class VectorHit:
    product_id: str
    score: float
    metadata: dict[str, Any]
    content: str = ""


class ChromaRetrieval:
    """Lazy Chroma client; a failed vector branch never fails the request."""

    def __init__(self, vectorstore: Any | None = None) -> None:
        self._vectorstore = vectorstore
        self._failed = False

    def _get_vectorstore(self) -> Any | None:
        if self._vectorstore is not None:
            return self._vectorstore
        if self._failed:
            return None
        try:
            from langchain_chroma import Chroma
            from shared.embedding_provider import get_embedding_function

            self._vectorstore = Chroma(
                collection_name=config.CHROMA_COLLECTION,
                persist_directory=str(config.CHROMA_DIR),
                embedding_function=get_embedding_function(),
            )
        except Exception as exc:  # pragma: no cover - optional infra
            self._failed = True
            logger.info("Chroma unavailable; vector branch disabled: %s", type(exc).__name__)
        return self._vectorstore

    @staticmethod
    def _product_id(metadata: dict[str, Any]) -> str:
        return str(metadata.get("product_id") or metadata.get("ma_san_pham") or metadata.get("id") or "").strip()

    def search(self, query: str, limit: int = 24) -> list[VectorHit]:
        """Search Chroma with a safe empty-list fallback."""

        vectorstore = self._get_vectorstore()
        if vectorstore is None or not query.strip():
            return []
        try:
            raw = vectorstore.similarity_search_with_relevance_scores(query, k=max(1, limit))
        except Exception:
            try:
                docs = vectorstore.similarity_search(query, k=max(1, limit))
                raw = [(doc, 0.0) for doc in docs]
            except Exception as exc:
                logger.warning("Chroma retrieval degraded: %s", type(exc).__name__)
                return []

        hits: list[VectorHit] = []
        for doc, score in raw:
            metadata = dict(getattr(doc, "metadata", {}) or {})
            product_id = self._product_id(metadata)
            if not product_id:
                continue
            hits.append(
                VectorHit(
                    product_id=product_id,
                    score=float(score or 0.0),
                    metadata=metadata,
                    content=str(getattr(doc, "page_content", "") or ""),
                )
            )
        return hits

    def build_or_refresh(self, products: Iterable[dict[str, Any]]) -> int:
        """Upsert factual product documents; return 0 when Chroma is unavailable."""

        product_list = [product for product in products if product.get("id")]
        if not product_list:
            return 0
        vectorstore = self._get_vectorstore()
        if vectorstore is None:
            return 0
        try:
            from langchain_core.documents import Document

            documents = [
                Document(
                    page_content=product_text(product),
                    metadata={
                        "product_id": str(product["id"]),
                        "category": str(product.get("category") or ""),
                        "price": int(product.get("price") or 0),
                    },
                )
                for product in product_list
            ]
            vectorstore.add_documents(documents, ids=[f"product_{p['id']}" for p in product_list])
            return len(documents)
        except Exception as exc:
            logger.warning("Chroma refresh degraded: %s", type(exc).__name__)
            return 0


_default_retrieval: ChromaRetrieval | None = None


def get_retrieval() -> ChromaRetrieval:
    """Return the process-local Chroma retrieval wrapper."""

    global _default_retrieval
    if _default_retrieval is None:
        _default_retrieval = ChromaRetrieval()
    return _default_retrieval
