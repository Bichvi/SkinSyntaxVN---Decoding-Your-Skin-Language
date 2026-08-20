# -*- coding: utf-8 -*-
"""Shared HuggingFace / LangChain model identifiers."""

EMBEDDING_MODEL = "sentence-transformers/static-similarity-mrl-multilingual-v1"
RERANKER_MODEL = "cross-encoder/mmarco-mMiniLMv2-L12-H384-v1"

EMBEDDING_MODEL_KWARGS = {"device": "cpu"}
EMBEDDING_ENCODE_KWARGS = {"normalize_embeddings": True}
RERANKER_MODEL_KWARGS = {"device": "cpu"}
