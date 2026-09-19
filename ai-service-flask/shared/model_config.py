"""Central model and retrieval configuration shared by AI services."""

from __future__ import annotations

import os

EMBEDDING_MODEL = os.getenv(
    "EMBEDDING_MODEL",
    "sentence-transformers/static-similarity-mrl-multilingual-v1",
).strip()
RERANKER_MODEL = os.getenv(
    "RERANKER_MODEL",
    "cross-encoder/mmarco-mMiniLMv2-L12-H384-v1",
).strip()

EMBEDDING_MODEL_KWARGS = {"device": os.getenv("EMBEDDING_DEVICE", "cpu")}
EMBEDDING_ENCODE_KWARGS = {"normalize_embeddings": True}
RERANKER_MODEL_KWARGS = {"device": os.getenv("RERANKER_DEVICE", "cpu")}

# 60 is the standard RRF constant. Keep it explicit in logs and evaluations.
RRF_K = int(os.getenv("RECOMMENDATION_RRF_K", "60"))
RRF_ALPHA = float(os.getenv("RECOMMENDATION_RRF_ALPHA", "0.5"))
BM25_TTL_SECONDS = int(os.getenv("RECOMMENDATION_BM25_TTL", "300"))
