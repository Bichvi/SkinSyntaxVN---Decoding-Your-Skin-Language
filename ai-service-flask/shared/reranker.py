"""Shared cross-encoder access for chatbot and recommendation services."""

from .embedding_provider import get_cross_encoder


def get_reranker():
    """Return the process-wide shared cross-encoder instance."""

    return get_cross_encoder()


__all__ = ["get_cross_encoder", "get_reranker"]
