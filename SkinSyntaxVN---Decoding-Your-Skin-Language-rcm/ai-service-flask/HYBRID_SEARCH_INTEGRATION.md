# -*- coding: utf-8 -*-
"""
Integration Guide: Hybrid Search + RRF + Vietnamese Cross-Encoder

Modify chatbot_flask.py to use the new hybrid search pipeline.
This file shows the integration points and configuration.

HOW TO INTEGRATE:
1. Install required packages
2. Initialize hybrid search pipeline
3. Replace vs.similarity_search() calls with pipeline.search()
4. (Optional) Set up RAGAS evaluation
"""

# ═══════════════════════════════════════════════════════════════════════════
# STEP 1: Install Required Packages
# ═══════════════════════════════════════════════════════════════════════════
"""
pip install sentence-transformers==3.3.0
pip install ragas==0.1.12

# For monitoring and evaluation (optional)
pip install wandb matplotlib
"""

# ═══════════════════════════════════════════════════════════════════════════
# STEP 2: Add to chatbot_flask.py imports
# ═══════════════════════════════════════════════════════════════════════════

# Add these imports to the top of chatbot_flask.py:
"""
from hybrid_search import (
    HybridSearchPipeline,
    BM25Search,
    VietnameseReranker,
    RankedDocument,
    log_ranking_comparison
)
from rag_evaluation import (
    RAGEvaluator,
    EvaluationMetrics,
    print_evaluation_report,
    print_comparison_report
)
"""

# ═══════════════════════════════════════════════════════════════════════════
# STEP 3: Initialize Hybrid Search Pipeline
# ═══════════════════════════════════════════════════════════════════════════

# Add to your config/initialization section (after get_vectorstore()):
"""
_hybrid_pipeline = None

def get_hybrid_search_pipeline():
    \"\"\"Lazy-load hybrid search pipeline with Vietnamese re-ranker.\"\"\"
    global _hybrid_pipeline
    if _hybrid_pipeline is None:
        vs = get_vectorstore()
        
        # Initialize BM25 index from ChromaDB documents
        docs_for_bm25 = []
        try:
            # Get all docs from ChromaDB collection
            all_docs = vs.similarity_search("", k=10000)  # Get all docs
            for doc in all_docs:
                docs_for_bm25.append({
                    'id': doc.id or '',
                    'content': doc.page_content,
                    'metadata': doc.metadata
                })
            logger.info(f"[BM25] Indexed {len(docs_for_bm25)} documents")
        except Exception as e:
            logger.warning(f"[BM25] Could not index all docs: {e}, using empty index")
        
        bm25 = BM25Search(documents=docs_for_bm25)
        
        # Initialize pipeline with alpha=0.5 (equal weight semantic & lexical)
        _hybrid_pipeline = HybridSearchPipeline(
            vectorstore=vs,
            bm25_index=bm25,
            alpha=0.5,              # Adjust based on your corpus
            k_rrf=60.0,             # Standard RRF constant
            reranker_model="cross-encoder/mmarco-mMiniLMv2-L12-H384-v1"
        )
        logger.info("[HYBRID] Pipeline initialized with Vietnamese cross-encoder")
    
    return _hybrid_pipeline
"""

# ═══════════════════════════════════════════════════════════════════════════
# STEP 4: Replace retrieval calls in xu_ly_cau_hoi()
# ═══════════════════════════════════════════════════════════════════════════

# BEFORE (original code):
"""
    query = yc.tu_khoa_ngu_nghia or message
    docs = []
    k = min(max(int(yc.so_luong_goi_y or 3), 3), 10)

    if yc.is_routine:
        # ... routine handling ...
        docs = vs.similarity_search(query=query, k=2, filter=bo_loc_cat)
    else:
        # ... fallback stages ...
        docs = vs.similarity_search(query=query, k=k, filter=bo_loc)
"""

# AFTER (new code with hybrid search):
"""
    query = yc.tu_khoa_ngu_nghia or message
    docs = []
    k = min(max(int(yc.so_luong_goi_y or 3), 3), 10)
    
    # Get hybrid search pipeline
    hybrid = get_hybrid_search_pipeline()
    
    if yc.is_routine:
        print("[ROUTINE] Skincare routine requested with hybrid search")
        routine_categories = [
            ("Tẩy Trang Mặt", "Tẩy Trang"),
            ("Sữa Rửa Mặt", "Sữa Rửa Mặt"),
            ("Toner / Nước Cân Bằng Da", "Toner"),
            ("Serum / Tinh Chất", "Serum"),
            ("Kem / Gel / Dầu Dưỡng", "Kem Dưỡng"),
            ("Chống Nắng Da Mặt", "Kem Chống Nắng")
        ]
        
        for cat_name, friendly_name in routine_categories:
            # Build filter for category
            cat_filters = {"loai_san_pham": {"$eq": cat_name}}
            if yc.loai_da and yc.loai_da not in ("Unknown", None):
                cat_filters["loai_da"] = {"$eq": yc.loai_da}
            
            # Use hybrid search
            ranked_docs, metrics = hybrid.search(
                query=query,
                k_total=5,              # Retrieve more for fusion
                top_n=2,                # Return top 2 per category
                filters=cat_filters,
                use_reranker=True
            )
            
            # Convert to original Document format
            for ranked_doc in ranked_docs:
                doc = MockDocument(
                    page_content=ranked_doc.content,
                    metadata=ranked_doc.metadata,
                    id=ranked_doc.doc_id
                )
                docs.append(doc)
    else:
        # Regular product search with hybrid
        bo_loc = build_filter(yc)
        
        # Stage 1: Hybrid search with filters
        ranked_docs, metrics = hybrid.search(
            query=query,
            k_total=k*2,            # Retrieve more before fusion
            top_n=k,                # Final top-k
            filters=bo_loc,
            use_reranker=True
        )
        
        print(f"[HYBRID] Semantic: {metrics['semantic_results']}, "
              f"Lexical: {metrics['lexical_results']}, "
              f"Final: {metrics['reranked_results']}")
        
        # Convert to original Document format
        docs = []
        for ranked_doc in ranked_docs:
            doc = MockDocument(
                page_content=ranked_doc.content,
                metadata=ranked_doc.metadata,
                id=ranked_doc.doc_id,
                score=ranked_doc.rerank_score or ranked_doc.rrf_score  # Use re-rank score
            )
            docs.append(doc)
        
        # Stage 2: If no docs, fallback to pure semantic search
        if not docs:
            logger.warning("[HYBRID] No results, falling back to semantic-only search")
            ranked_docs, _ = hybrid.search(
                query=query,
                k_total=k,
                top_n=k,
                filters=bo_loc,
                use_reranker=False  # Skip re-ranker for speed
            )
            docs = [
                MockDocument(
                    page_content=rd.content,
                    metadata=rd.metadata,
                    id=rd.doc_id,
                    score=rd.semantic_score
                )
                for rd in ranked_docs
            ]
"""

# ═══════════════════════════════════════════════════════════════════════════
# STEP 5: (Optional) Add evaluation endpoint
# ═══════════════════════════════════════════════════════════════════════════

# Add new Flask route for evaluation:
"""
from flask import jsonify

@app.route('/api/evaluate', methods=['POST'])
def evaluate_rag():
    \"\"\"
    Evaluate RAG performance on test set.
    
    Request:
    {
        "test_queries": ["query1", "query2", ...],
        "ground_truth_docs": [["doc_id1", ...], ...],
        "use_hybrid": true/false
    }
    
    Returns evaluation metrics comparing configurations
    \"\"\"
    data = request.get_json()
    test_queries = data.get('test_queries', [])
    ground_truth = data.get('ground_truth_docs', [])
    use_hybrid = data.get('use_hybrid', True)
    
    if not test_queries or not ground_truth:
        return jsonify({"error": "Missing test_queries or ground_truth_docs"}), 400
    
    evaluator = RAGEvaluator()
    results = {}
    
    # Evaluate both configurations
    for use_hybrid_search in [False, True]:
        config_name = "hybrid_with_reranking" if use_hybrid_search else "dense_only"
        
        retrieved_contexts = []
        retrieved_doc_ids = []
        generated_answers = []
        
        for query in test_queries:
            if use_hybrid_search:
                hybrid = get_hybrid_search_pipeline()
                ranked_docs, metrics = hybrid.search(
                    query=query,
                    k_total=20,
                    top_n=10,
                    use_reranker=True
                )
                docs = ranked_docs
            else:
                vs = get_vectorstore()
                docs = vs.similarity_search(query, k=10)
            
            # Extract doc IDs and context
            doc_ids = [doc.id.replace('product_', '') if hasattr(doc, 'id') else str(i) 
                      for i, doc in enumerate(docs)]
            context = " ".join([doc.page_content if hasattr(doc, 'page_content') 
                              else str(doc) for doc in docs])
            
            retrieved_doc_ids.append(doc_ids)
            retrieved_contexts.append([context])
            
            # Generate answer (using existing LLM)
            answer = "Generated answer here"  # Would call actual LLM
            generated_answers.append(answer)
        
        # Evaluate
        metrics = evaluator.evaluate_config(
            config_name=config_name,
            queries=test_queries,
            ground_truth_docs=ground_truth,
            retrieved_contexts=retrieved_contexts,
            retrieved_doc_ids=retrieved_doc_ids,
            generated_answers=generated_answers,
            top_k=5
        )
        results[config_name] = metrics
    
    # Format response
    return jsonify({
        "evaluation_results": {
            name: m.to_dict() for name, m in results.items()
        },
        "improvement": {
            "context_precision": results["hybrid_with_reranking"].context_precision - 
                               results["dense_only"].context_precision,
            "context_recall": results["hybrid_with_reranking"].context_recall - 
                            results["dense_only"].context_recall,
            "answer_relevancy": results["hybrid_with_reranking"].answer_relevancy - 
                              results["dense_only"].answer_relevancy,
        }
    })
"""

# ═══════════════════════════════════════════════════════════════════════════
# STEP 6: Configuration Tuning Guide
# ═══════════════════════════════════════════════════════════════════════════

"""
TUNING HYBRID SEARCH FOR YOUR CORPUS:

1. ALPHA PARAMETER (semantic vs lexical weight):
   - alpha = 1.0: Pure semantic search (original)
   - alpha = 0.75: 75% semantic, 25% lexical
   - alpha = 0.5: Equal weight (recommended for mixed queries)
   - alpha = 0.25: 25% semantic, 75% lexical
   - alpha = 0.0: Pure BM25 lexical search
   
   RECOMMENDED VALUES BY CORPUS TYPE:
   - Long-form documentation: alpha = 0.65-0.75 (favor semantic for paraphrase)
   - Technical guides with exact terms: alpha = 0.35-0.5 (favor lexical)
   - Product database (like yours): alpha = 0.45-0.55 (balanced)

2. K_TOTAL PARAMETER (documents to retrieve before fusion):
   - Should be 2-3x your final top_k
   - Example: if top_n=5, use k_total=12-15
   - Larger k_total gives fusion more documents to work with
   - Cost: more LLM token usage

3. RE-RANKING:
   - Enable for important queries (high-stakes content)
   - Can be disabled for speed on low-stakes queries
   - Adds ~100ms latency per 20 documents

4. METADATA FILTERING:
   - Critical for reducing search space
   - Example: filter by skin_type BEFORE hybrid search
   - Improves both recall and precision

OPTIMIZATION WORKFLOW:
1. Start with alpha=0.5 (safe default)
2. Run evaluation on your test set
3. Check if BM25 is helping (k_lexical > 0 in metrics)
4. Adjust alpha based on evaluation results:
   - If recall is lower than precision: increase alpha toward semantic
   - If precision is lower than recall: decrease alpha toward lexical
5. Iterate until Context Precision >= 0.75 and Recall >= 0.85
"""

# ═══════════════════════════════════════════════════════════════════════════
# STEP 7: Monitoring and Logging
# ═══════════════════════════════════════════════════════════════════════════

"""
ADD LOGGING TO TRACK PERFORMANCE:

Example in your search function:

    # Log ranking changes for debugging
    logger.info(f"Query: {query[:60]}")
    logger.info(f"  Semantic results: {metrics['semantic_results']}")
    logger.info(f"  Lexical results: {metrics['lexical_results']}")
    logger.info(f"  Top result changed: {top_doc_id}")
    logger.info(f"  Semantic rank → Final rank: {sem_rank} → {final_rank}")
    
    # Optional: detailed ranking analysis
    if query_is_important:
        log_ranking_comparison(query, ranked_docs[:10])

METRICS TO MONITOR:
1. Average retrieval latency
2. % of queries where reranker changed top result
3. Context Precision per query type
4. Hit rate by product category
5. Answer quality feedback from users

CLOUD MONITORING (optional):
- Use Weights & Biases for experiment tracking
- Log metrics to monitor corpus quality over time
- Track how evaluation metrics change with new data
"""

# ═══════════════════════════════════════════════════════════════════════════
# EXAMPLE: Complete Integration
# ═══════════════════════════════════════════════════════════════════════════

"""
Here's a minimal working example to add to your chatbot_flask.py:

import logging
from hybrid_search import HybridSearchPipeline, BM25Search
from rag_evaluation import RAGEvaluator

logger = logging.getLogger(__name__)

# Global pipeline
_hybrid_pipeline = None

def get_hybrid_pipeline():
    global _hybrid_pipeline
    if _hybrid_pipeline is None:
        vs = get_vectorstore()
        
        # Simple BM25 index (lazy-loaded)
        bm25 = BM25Search()
        
        _hybrid_pipeline = HybridSearchPipeline(
            vectorstore=vs,
            bm25_index=bm25,
            alpha=0.5,
            reranker_model="cross-encoder/mmarco-mMiniLMv2-L12-H384-v1"
        )
    return _hybrid_pipeline

# In your xu_ly_cau_hoi function, replace:
#   docs = vs.similarity_search(query, k=10)
# With:
#   hybrid = get_hybrid_pipeline()
#   ranked_docs, metrics = hybrid.search(query, k_total=20, top_n=10, use_reranker=True)
#   docs = [convert_to_original_format(doc) for doc in ranked_docs]
"""

print("Integration guide ready. See above for step-by-step instructions.")
