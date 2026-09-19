"""Lazy, process-local singleton factories for embedding and reranking models."""

from __future__ import annotations

import logging
from threading import Lock
from typing import Any

from .model_config import (
    EMBEDDING_ENCODE_KWARGS,
    EMBEDDING_MODEL,
    EMBEDDING_MODEL_KWARGS,
    RERANKER_MODEL,
    RERANKER_MODEL_KWARGS,
)

logger = logging.getLogger(__name__)

_embedding_function: Any | None = None
_cross_encoder: Any | None = None
_model_lock = Lock()


def get_embedding_function() -> Any:
    """Return one shared LangChain embedding function, loading it on first use."""

    global _embedding_function
    if _embedding_function is not None:
        return _embedding_function

    with _model_lock:
        if _embedding_function is not None:
            return _embedding_function
        from langchain_huggingface import HuggingFaceEmbeddings

        _embedding_function = HuggingFaceEmbeddings(
            model_name=EMBEDDING_MODEL,
            model_kwargs=EMBEDDING_MODEL_KWARGS,
            encode_kwargs=EMBEDDING_ENCODE_KWARGS,
        )
        logger.info("Shared embedding model ready: %s", EMBEDDING_MODEL)
        return _embedding_function


def get_cross_encoder(model_name: str | None = None) -> Any:
    """Return one shared LangChain cross-encoder wrapper.

    ``model_name`` is accepted for compatibility, but the shared provider keeps
    one configured model per process to prevent accidental duplicate loads.
    """

    global _cross_encoder
    if _cross_encoder is not None:
        return _cross_encoder

    with _model_lock:
        if _cross_encoder is not None:
            return _cross_encoder
        from langchain_community.cross_encoders import HuggingFaceCrossEncoder

        requested = (model_name or RERANKER_MODEL).strip()
        if requested != RERANKER_MODEL:
            logger.warning(
                "Ignoring alternate reranker model %s; shared model is %s",
                requested,
                RERANKER_MODEL,
            )
        _cross_encoder = HuggingFaceCrossEncoder(
            model_name=RERANKER_MODEL,
            model_kwargs=RERANKER_MODEL_KWARGS,
        )
        logger.info("Shared cross-encoder ready: %s", RERANKER_MODEL)
        return _cross_encoder


def reset_model_singletons() -> None:
    """Reset factories for tests or controlled worker restarts."""

    global _embedding_function, _cross_encoder
    with _model_lock:
        _embedding_function = None
        _cross_encoder = None
