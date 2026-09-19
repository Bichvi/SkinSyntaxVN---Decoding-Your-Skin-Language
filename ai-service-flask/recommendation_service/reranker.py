"""Shared Vietnamese cross-encoder reranking with a deterministic fallback."""

from __future__ import annotations

import logging
from typing import Any

from . import config

logger = logging.getLogger(__name__)


class Reranker:
    """Rerank a small candidate set against the profile document."""

    def __init__(self, model: Any | None = None) -> None:
        self._model = model
        self._attempted = model is not None

    def _get_model(self) -> Any | None:
        if self._attempted:
            return self._model
        self._attempted = True
        try:
            from shared.embedding_provider import get_cross_encoder

            self._model = get_cross_encoder(config.RERANKER_MODEL)
        except Exception as exc:  # pragma: no cover - model/runtime dependent
            logger.info("Cross-encoder unavailable; retaining RRF order: %s", type(exc).__name__)
            self._model = None
        return self._model

    @staticmethod
    def _fallback_score(query: str, product: dict[str, Any]) -> float:
        query_terms = {term for term in query.lower().split() if len(term) > 2}
        text = " ".join(str(product.get(field) or "") for field in ("name", "ten_san_pham", "category", "ingredients", "description")).lower()
        overlap = sum(1 for term in query_terms if term in text)
        rating = min(5.0, max(0.0, float(product.get("rating") or 0))) / 5.0
        return float(overlap) + rating * 0.1

    def rerank(self, query: str, candidates: list[dict[str, Any]], limit: int = 8) -> list[dict[str, Any]]:
        """Attach ``rerank_score`` and return the best candidates."""

        if not candidates:
            return []
        model = self._get_model()
        scores: list[float]
        if model is None:
            scores = [self._fallback_score(query, candidate) for candidate in candidates]
        else:
            try:
                pairs = [[query, str(candidate.get("profile_text") or candidate.get("description") or candidate.get("name") or candidate.get("ten_san_pham") or "")] for candidate in candidates]
                raw_scores = model.score(pairs)
                scores = [float(value) for value in raw_scores]
            except Exception as exc:
                logger.warning("Cross-encoder scoring degraded: %s", type(exc).__name__)
                scores = [self._fallback_score(query, candidate) for candidate in candidates]

        ranked: list[dict[str, Any]] = []
        for candidate, score in zip(candidates, scores):
            item = dict(candidate)
            item["rerank_score"] = round(float(score), 6)
            item["match_score"] = round(max(0.0, min(100.0, float(score) * 100.0)), 2)
            ranked.append(item)
        ranked.sort(key=lambda item: (-float(item.get("rerank_score") or 0.0), str(item.get("id"))))
        return ranked[: max(1, limit)]
