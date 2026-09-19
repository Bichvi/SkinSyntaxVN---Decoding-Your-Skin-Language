"""Process-local shared infrastructure for the Flask AI services.

The module is intentionally small: services share model factories and clients,
while each service keeps its own orchestration and domain rules.
"""

from .embedding_provider import get_cross_encoder, get_embedding_function
from .reranker import get_reranker

__all__ = ["get_cross_encoder", "get_embedding_function", "get_reranker"]
