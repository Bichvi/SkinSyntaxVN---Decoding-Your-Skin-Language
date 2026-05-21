# -*- coding: utf-8 -*-
"""
Hybrid Search with RRF (Reciprocal Rank Fusion) & Vietnamese Cross-Encoder Re-ranking
For SkinSyntaxVN RAG Pipeline

Combines:
1. Dense semantic search (ChromaDB)
2. Sparse BM25 keyword search (ElasticSearch/BM25 fallback)
3. RRF fusion for ranking
4. Vietnamese mMarco-based cross-encoder re-ranking
"""

import numpy as np
from typing import List, Tuple, Optional, Dict, Any
from dataclasses import dataclass, field
from sentence_transformers import CrossEncoder
import logging

logger = logging.getLogger(__name__)


@dataclass
class RankedDocument:
    """Represents a ranked document with scores from multiple methods."""
    doc_id: str
    content: str
    metadata: Dict[str, Any]
    semantic_score: Optional[float] = None
    bm25_score: Optional[float] = None
    rrf_score: Optional[float] = None
    rerank_score: Optional[float] = None
    semantic_rank: Optional[int] = None
    bm25_rank: Optional[int] = None
    final_rank: Optional[int] = None
    
    def __repr__(self):
        return (
            f"RankedDoc(id={self.doc_id[:20]}, "
            f"semantic={self.semantic_score:.3f if self.semantic_score else None}, "
            f"bm25={self.bm25_score:.3f if self.bm25_score else None}, "
            f"rrf={self.rrf_score:.3f if self.rrf_score else None}, "
            f"rerank={self.rerank_score:.3f if self.rerank_score else None})"
        )


class BM25Search:
    """
    BM25 sparse keyword search.
    Fallback implementation using simple TF-IDF when ElasticSearch is not available.
    """
    
    def __init__(self, documents: Optional[List[Dict[str, Any]]] = None):
        """
        Initialize BM25 search index.
        
        Args:
            documents: List of documents with 'id', 'content', 'metadata'
        """
        self.documents = documents or []
        self.index = {}
        self.doc_freq = {}  # Document frequency for IDF
        self.doc_lengths = {}
        self.avg_doc_length = 0
        
        if documents:
            self._build_index()
    
    def _build_index(self):
        """Build BM25 index from documents."""
        total_length = 0
        all_tokens = set()
        
        for doc in self.documents:
            doc_id = doc.get('id', '')
            content = doc.get('content', '') + ' ' + ' '.join(str(v) for v in doc.get('metadata', {}).values())
            tokens = self._tokenize(content)
            
            self.doc_lengths[doc_id] = len(tokens)
            total_length += len(tokens)
            
            # Count term frequencies
            token_set = set()
            for token in tokens:
                if token not in token_set:
                    token_set.add(token)
                    if token not in self.doc_freq:
                        self.doc_freq[token] = 0
                    self.doc_freq[token] += 1
        
        self.avg_doc_length = total_length / len(self.documents) if self.documents else 1
        logger.info(f"BM25 index built: {len(self.documents)} docs, avg length {self.avg_doc_length:.1f}")
    
    def _tokenize(self, text: str) -> List[str]:
        """Simple Vietnamese tokenization."""
        import re
        text = text.lower()
        # Remove special characters but keep Vietnamese diacritics
        text = re.sub(r'[^\w\u0100-\u01b0\u1e00-\u1ef9\s]', ' ', text)
        tokens = text.split()
        return [t for t in tokens if len(t) > 1]  # Filter very short tokens
    
    def _calculate_bm25(self, query_tokens: List[str], doc_id: str, k1: float = 1.5, b: float = 0.75) -> float:
        """
        Calculate BM25 score for document.
        
        BM25 formula: 
        score = IDF(qi) * (f(qi,D) * (k1+1)) / (f(qi,D) + k1 * (1 - b + b * (|D|/avgdl)))
        """
        score = 0.0
        doc_length = self.doc_lengths.get(doc_id, 0)
        
        for token in query_tokens:
            if token not in self.doc_freq:
                continue
            
            # IDF calculation (with log smoothing)
            idf = np.log(1 + (len(self.documents) - self.doc_freq[token] + 0.5) / 
                        (self.doc_freq[token] + 0.5))
            
            # Term frequency from query
            tf = 1  # Binary relevance for query
            
            # BM25 formula
            numerator = tf * (k1 + 1)
            denominator = 1 + k1 * (1 - b + b * (doc_length / self.avg_doc_length))
            
            score += idf * (numerator / denominator)
        
        return score
    
    def search(self, query: str, k: int = 10) -> List[Tuple[str, float]]:
        """
        Search for documents matching query.
        
        Args:
            query: Search query string
            k: Number of top results
            
        Returns:
            List of (doc_id, score) tuples
        """
        query_tokens = self._tokenize(query)
        if not query_tokens:
            return []
        
        scores = []
        for doc in self.documents:
            doc_id = doc.get('id', '')
            bm25_score = self._calculate_bm25(query_tokens, doc_id)
            if bm25_score > 0:
                scores.append((doc_id, bm25_score))
        
        # Sort by score and return top k
        scores.sort(key=lambda x: x[1], reverse=True)
        return scores[:k]


class ReciprocalRankFusion:
    """
    Combine ranking results from multiple retrieval methods using RRF.
    
    Formula:
    RRF(d) = Σ 1/(k + rank(d))
    where k is a constant (typically 60) and rank is the position in each ranking.
    
    With weighted RRF:
    RRF(d) = α * (semantic_component) + (1-α) * (lexical_component)
    """
    
    def __init__(self, k: float = 60.0, alpha: float = 0.5):
        """
        Initialize RRF.
        
        Args:
            k: Constant for RRF formula (typically 60)
            alpha: Weight for semantic (dense) vs lexical (sparse)
                  alpha=0.5 = equal weight
                  alpha=0.75 = favor semantic
                  alpha=0.25 = favor lexical
        """
        self.k = k
        self.alpha = alpha
    
    @staticmethod
    def _rrf_component(rank: Optional[int], k: float = 60.0) -> float:
        """Calculate single RRF component."""
        if rank is None or rank < 0:
            return 0.0
        return 1.0 / (k + rank + 1)
    
    def fuse(
        self,
        semantic_results: List[Tuple[str, float]],
        lexical_results: List[Tuple[str, float]],
        documents_map: Dict[str, RankedDocument]
    ) -> List[RankedDocument]:
        """
        Fuse semantic and lexical search results using RRF with alpha weighting.
        
        Args:
            semantic_results: List of (doc_id, score) from semantic search
            lexical_results: List of (doc_id, score) from BM25 search
            documents_map: Map of doc_id -> RankedDocument
            
        Returns:
            List of RankedDocument sorted by RRF score
        """
        # Collect all unique documents
        all_doc_ids = set()
        for doc_id, _ in semantic_results + lexical_results:
            all_doc_ids.add(doc_id)
        
        # Create rank maps
        semantic_ranks = {doc_id: rank for rank, (doc_id, _) in enumerate(semantic_results)}
        lexical_ranks = {doc_id: rank for rank, (doc_id, _) in enumerate(lexical_results)}
        
        # Calculate RRF scores
        fused_results = []
        for doc_id in all_doc_ids:
            doc = documents_map.get(doc_id)
            if not doc:
                continue
            
            # Get ranks (None if not in that result set)
            sem_rank = semantic_ranks.get(doc_id)
            lex_rank = lexical_ranks.get(doc_id)
            
            # Calculate RRF components
            sem_component = self._rrf_component(sem_rank, self.k) if sem_rank is not None else 0
            lex_component = self._rrf_component(lex_rank, self.k) if lex_rank is not None else 0
            
            # Weighted fusion
            rrf_score = self.alpha * sem_component + (1 - self.alpha) * lex_component
            
            # Update document
            doc.semantic_rank = sem_rank
            doc.bm25_rank = lex_rank
            doc.rrf_score = rrf_score
            
            # Update BM25 score if available from lexical results
            if lex_rank is not None:
                lex_score = dict(lexical_results).get(doc_id)
                if lex_score is not None:
                    doc.bm25_score = float(lex_score)
            
            fused_results.append((doc_id, rrf_score, doc))
        
        # Sort by RRF score
        fused_results.sort(key=lambda x: x[1], reverse=True)
        
        # Assign final ranks
        for final_rank, (_, _, doc) in enumerate(fused_results):
            doc.final_rank = final_rank
        
        return [doc for _, _, doc in fused_results]


class VietnameseReranker:
    """
    Vietnamese cross-encoder for precise re-ranking.
    Uses mMarco model optimized for multilingual retrieval.
    
    Model: cross-encoder/mmarco-mMiniLMv2-L12-H384-v1
    - Optimized for multiple languages including Vietnamese
    - Lightweight (~130MB)
    - Fast inference for production use
    """
    
    def __init__(self, model_name: str = "cross-encoder/mmarco-mMiniLMv2-L12-H384-v1", batch_size: int = 32):
        """
        Initialize Vietnamese cross-encoder re-ranker.
        
        Args:
            model_name: Model identifier from HuggingFace
            batch_size: Batch size for inference
        """
        self.model_name = model_name
        self.batch_size = batch_size
        self.model = None
        self._initialize_model()
    
    def _initialize_model(self):
        """Lazy load cross-encoder model."""
        try:
            self.model = CrossEncoder(self.model_name)
            logger.info(f"✓ Loaded Vietnamese re-ranker: {self.model_name}")
        except Exception as e:
            logger.error(f"✗ Failed to load re-ranker: {e}")
            raise
    
    def rerank(
        self,
        query: str,
        documents: List[RankedDocument],
        top_n: int = 5
    ) -> List[RankedDocument]:
        """
        Re-rank documents using cross-encoder.
        
        Args:
            query: Search query
            documents: List of RankedDocument to re-rank
            top_n: Return top N documents
            
        Returns:
            Re-ranked documents
        """
        if not documents:
            return []
        
        if not self.model:
            logger.warning("Re-ranker model not initialized, returning original order")
            return documents[:top_n]
        
        # Prepare pairs for cross-encoder
        pairs = [
            (query, doc.content)
            for doc in documents
        ]
        
        try:
            # Get cross-encoder scores
            scores = self.model.predict(pairs, batch_size=self.batch_size)
            
            # Attach scores to documents
            for doc, score in zip(documents, scores):
                doc.rerank_score = float(score)
            
            # Sort by re-rank score
            reranked = sorted(documents, key=lambda x: x.rerank_score, reverse=True)
            
            # Log ranking changes
            if len(documents) >= 3:
                original_top = documents[0].doc_id
                reranked_top = reranked[0].doc_id
                if original_top != reranked_top:
                    logger.info(f"Re-ranking changed top result: {original_top[:20]} → {reranked_top[:20]}")
            
            return reranked[:top_n]
            
        except Exception as e:
            logger.error(f"Re-ranking failed: {e}, returning original order")
            return documents[:top_n]


class HybridSearchPipeline:
    """
    Complete hybrid search pipeline combining:
    1. Dense semantic search (ChromaDB)
    2. Sparse BM25 search
    3. RRF fusion
    4. Vietnamese cross-encoder re-ranking
    """
    
    def __init__(
        self,
        vectorstore,  # ChromaDB instance
        bm25_index: Optional[BM25Search] = None,
        alpha: float = 0.5,
        k_rrf: float = 60.0,
        reranker_model: str = "cross-encoder/mmarco-mMiniLMv2-L12-H384-v1"
    ):
        """
        Initialize hybrid search pipeline.
        
        Args:
            vectorstore: ChromaDB vectorstore instance
            bm25_index: BM25 search index
            alpha: RRF weighting factor (0.5 = equal, 0.75 = favor semantic)
            k_rrf: RRF constant (default 60)
            reranker_model: Vietnamese cross-encoder model name
        """
        self.vectorstore = vectorstore
        self.bm25_index = bm25_index or BM25Search()
        self.rrf = ReciprocalRankFusion(k=k_rrf, alpha=alpha)
        
        try:
            self.reranker = VietnameseReranker(reranker_model)
        except Exception as e:
            logger.warning(f"Re-ranker initialization failed: {e}, will skip re-ranking")
            self.reranker = None
    
    def search(
        self,
        query: str,
        k_total: int = 20,
        top_n: int = 5,
        filters: Optional[Dict] = None,
        use_reranker: bool = True
    ) -> Tuple[List[RankedDocument], Dict[str, Any]]:
        """
        Hybrid search with RRF and re-ranking.
        
        Args:
            query: Search query
            k_total: Number of documents to retrieve from each method before fusion
            top_n: Final number of documents to return
            filters: Metadata filters for ChromaDB
            use_reranker: Whether to apply cross-encoder re-ranking
            
        Returns:
            Tuple of (ranked_documents, metrics)
        """
        metrics = {
            "query": query,
            "k_total": k_total,
            "top_n": top_n,
            "semantic_results": 0,
            "lexical_results": 0,
            "fused_results": 0,
            "reranked_results": 0
        }
        
        # Stage 1: Semantic search
        semantic_docs = self.vectorstore.similarity_search(query, k=k_total, filter=filters)
        semantic_results = [
            (doc.id or f"doc_{i}", getattr(doc, 'score', 1.0 - i*0.01))
            for i, doc in enumerate(semantic_docs)
        ]
        metrics["semantic_results"] = len(semantic_results)
        logger.info(f"[SEMANTIC] Found {len(semantic_results)} documents")
        
        # Stage 2: Lexical (BM25) search
        lexical_results = self.bm25_index.search(query, k=k_total) if self.bm25_index else []
        metrics["lexical_results"] = len(lexical_results)
        logger.info(f"[LEXICAL] Found {len(lexical_results)} documents")
        
        # Create document map
        documents_map = {}
        for i, doc in enumerate(semantic_docs):
            doc_id = doc.id or f"doc_{i}"
            ranked_doc = RankedDocument(
                doc_id=doc_id,
                content=doc.page_content,
                metadata=doc.metadata,
                semantic_score=getattr(doc, 'score', 1.0 - i*0.01)
            )
            documents_map[doc_id] = ranked_doc
            
        # Add lexical-only documents to documents_map
        if self.bm25_index and hasattr(self.bm25_index, 'documents'):
            bm25_docs_lookup = {d.get('id'): d for d in self.bm25_index.documents if d.get('id')}
            for doc_id, bm25_score in lexical_results:
                if doc_id not in documents_map and doc_id in bm25_docs_lookup:
                    bm25_doc = bm25_docs_lookup[doc_id]
                    ranked_doc = RankedDocument(
                        doc_id=doc_id,
                        content=bm25_doc.get('content', ''),
                        metadata=bm25_doc.get('metadata', {}),
                        bm25_score=float(bm25_score)
                    )
                    documents_map[doc_id] = ranked_doc
        
        # Stage 3: RRF fusion
        fused_docs = self.rrf.fuse(semantic_results, lexical_results, documents_map)
        metrics["fused_results"] = len(fused_docs)
        logger.info(f"[RRF] Fused {len(fused_docs)} documents with alpha={self.rrf.alpha}")
        
        # Stage 4: Cross-encoder re-ranking (optional)
        if use_reranker and self.reranker:
            final_docs = self.reranker.rerank(query, fused_docs[:k_total], top_n=top_n)
            metrics["reranked_results"] = len(final_docs)
            logger.info(f"[RERANK] Re-ranked to top {len(final_docs)} documents")
        else:
            final_docs = fused_docs[:top_n]
            logger.info(f"[NO-RERANK] Returning top {len(final_docs)} documents")
        
        return final_docs, metrics


def log_ranking_comparison(
    query: str,
    documents: List[RankedDocument],
    max_display: int = 10
):
    """
    Log ranking changes across stages for debugging/analysis.
    Useful for understanding why re-ranking changed results.
    """
    print(f"\n{'='*100}")
    print(f"RANKING ANALYSIS FOR QUERY: {query[:80]}")
    print(f"{'='*100}")
    
    for i, doc in enumerate(documents[:max_display]):
        print(f"\nRank {i+1} (Final): {doc.doc_id[:40]}")
        print(f"  Content: {doc.content[:80]}")
        print(f"  Semantic Score:  {doc.semantic_score:.4f} (rank {doc.semantic_rank})" if (doc.semantic_rank is not None and doc.semantic_score is not None) else f"  Semantic Score:  N/A (rank {doc.semantic_rank})")
        print(f"  BM25 Score:      {doc.bm25_score:.4f} (rank {doc.bm25_rank})" if (doc.bm25_rank is not None and doc.bm25_score is not None) else f"  BM25 Score:      N/A (rank {doc.bm25_rank})")
        print(f"  RRF Score:       {doc.rrf_score:.4f}" if doc.rrf_score is not None else "  RRF Score:       N/A")
        print(f"  Re-rank Score:   {doc.rerank_score:.4f}" if doc.rerank_score is not None else "  Re-rank Score:   N/A (not used)")
    
    print(f"\n{'='*100}\n")
