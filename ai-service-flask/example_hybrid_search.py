# -*- coding: utf-8 -*-
"""
Hybrid Search Implementation: Vietnamese Beauty Corpus Example

This demonstrates the complete hybrid search pipeline with RRF and Vietnamese 
cross-encoder on real beauty product queries in Vietnamese.

Run this script to see results immediately.
"""

import logging, sys, os
os.environ["PYTHONUTF8"] = "1"
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(name)s - %(message)s')

from hybrid_search import (
    HybridSearchPipeline,
    BM25Search,
    VietnameseReranker,
    RankedDocument,
    log_ranking_comparison,
    ReciprocalRankFusion
)
from rag_evaluation import RAGEvaluator, print_evaluation_report, print_comparison_report

# Setup logging
logger = logging.getLogger(__name__)


class MockVectorStore:
    """Mock ChromaDB vectorstore for demonstration."""
    
    def __init__(self, documents=None):
        self.documents = documents or self._create_sample_documents()
    
    def _create_sample_documents(self):
        """Create sample Vietnamese beauty product documents."""
        return [
            {
                'id': 'prod_001',
                'page_content': 'Sữa Rửa Mặt BHA - Sạch sâu lỗ chân lông',
                'metadata': {
                    'ten_san_pham': 'Sữa Rửa Mặt BHA Cleanser',
                    'loai_san_pham': 'Sữa Rửa Mặt',
                    'loai_da': 'Da dầu',
                    'thanh_phan_chinh': 'BHA, Salicylic Acid, Niacinamide'
                }
            },
            {
                'id': 'prod_002',
                'page_content': 'Mặt nạ clay detox - Giúp da sạch sâu và matte',
                'metadata': {
                    'ten_san_pham': 'Clay Mask Detox',
                    'loai_san_pham': 'Mặt Nạ',
                    'loai_da': 'Da dầu',
                    'thanh_phan_chinh': 'Bentonite Clay, Charcoal'
                }
            },
            {
                'id': 'prod_003',
                'page_content': 'Serum Retinol 0.5% - Giảm nhăn, dưỡng da',
                'metadata': {
                    'ten_san_pham': 'Retinol Serum Advanced',
                    'loai_san_pham': 'Serum',
                    'loai_da': 'Da khô',
                    'thanh_phan_chinh': 'Retinol, Hyaluronic Acid, Vitamin E'
                }
            },
            {
                'id': 'prod_004',
                'page_content': 'Kem chống nắng SPF 50+ PA++++ - Bảo vệ toàn diện',
                'metadata': {
                    'ten_san_pham': 'Sunscreen SPF 50+ UV Protection',
                    'loai_san_pham': 'Kem Chống Nắng',
                    'loai_da': 'Tất cả loại da',
                    'thanh_phan_chinh': 'Zinc Oxide, Titanium Dioxide, SPF 50+'
                }
            },
            {
                'id': 'prod_005',
                'page_content': 'Toner Hyaluronic Acid - Cấp ẩm sâu cho da',
                'metadata': {
                    'ten_san_pham': 'Toner HA Hydrating',
                    'loai_san_pham': 'Toner',
                    'loai_da': 'Da khô, da thường',
                    'thanh_phan_chinh': 'Hyaluronic Acid, Glycerin, Centella Asiatica'
                }
            },
            {
                'id': 'prod_006',
                'page_content': 'Retinol không an toàn khi mang thai - Tránh sử dụng',
                'metadata': {
                    'ten_san_pham': 'Retinol Usage Warning',
                    'loai_san_pham': 'Guide',
                    'loai_da': 'Pregnant women',
                    'thanh_phan_chinh': 'N/A'
                }
            },
            {
                'id': 'prod_007',
                'page_content': 'BHA và Retinol xung đột - Không dùng chung cùng một ngày',
                'metadata': {
                    'ten_san_pham': 'Ingredient Conflict Guide',
                    'loai_san_pham': 'Guide',
                    'loai_da': 'Tất cả loại da',
                    'thanh_phan_chinh': 'N/A'
                }
            },
            {
                'id': 'prod_008',
                'page_content': 'Exponential backoff với dead-letter queue - Cấu hình tối ưu',
                'metadata': {
                    'ten_san_pham': 'Technical Config',
                    'loai_san_pham': 'Technical',
                    'loai_da': 'N/A',
                    'thanh_phan_chinh': 'N/A'
                }
            },
            {
                'id': 'prod_009',
                'page_content': 'Dead-letter queue threshold cấu hình 1000 - Ngưỡng tối đa',
                'metadata': {
                    'ten_san_pham': 'DLQ Configuration',
                    'loai_san_pham': 'Technical',
                    'loai_da': 'N/A',
                    'thanh_phan_chinh': 'N/A'
                }
            }
        ]
    
    def similarity_search(self, query, k=10, filter=None):
        """Simple similarity search returning mock documents."""
        from dataclasses import dataclass
        
        @dataclass
        class MockDoc:
            id: str
            page_content: str
            metadata: dict
            score: float
        
        # Simulate semantic similarity (simple keyword matching)
        query_terms = query.lower().split()
        
        scored = []
        for doc in self.documents:
            content_lower = (doc['page_content'] + ' ' + 
                           ' '.join(str(v).lower() for v in doc['metadata'].values()))
            
            # Count matching terms
            matches = sum(1 for term in query_terms if term in content_lower)
            score = matches / len(query_terms) if query_terms else 0
            
            if score > 0:
                scored.append((doc, score))
        
        # Sort by score
        scored.sort(key=lambda x: x[1], reverse=True)
        
        # Convert to Mock objects
        results = []
        for i, (doc, score) in enumerate(scored[:k]):
            mock_doc = MockDoc(
                id=doc['id'],
                page_content=doc['page_content'],
                metadata=doc['metadata'],
                score=score
            )
            results.append(mock_doc)
        
        return results


def demo_hybrid_search():
    """Demonstrate hybrid search with RRF and Vietnamese re-ranker."""
    
    print("="*100)
    print("HYBRID SEARCH DEMONSTRATION: Vietnamese Beauty Products")
    print("="*100)
    
    # Create mock vectorstore and BM25 index
    vectorstore = MockVectorStore()
    
    documents_for_bm25 = [
        {
            'id': doc['id'],
            'content': doc['page_content'],
            'metadata': doc['metadata']
        }
        for doc in vectorstore.documents
    ]
    
    bm25 = BM25Search(documents=documents_for_bm25)
    
    # Create hybrid pipeline with Vietnamese re-ranker
    pipeline = HybridSearchPipeline(
        vectorstore=vectorstore,
        bm25_index=bm25,
        alpha=0.5,  # Equal weight semantic and lexical
        reranker_model="cross-encoder/mmarco-mMiniLMv2-L12-H384-v1"
    )
    
    # Test queries
    test_queries = [
        "Tôi có da dầu mụn, sản phẩm nào phù hợp?",
        "Có thể dùng Retinol khi bầu được không?",
        "Dead-letter queue threshold cấu hình bao nhiêu?"
    ]
    
    print("\n")
    for query in test_queries:
        print(f"\n{'-'*100}")
        print(f"QUERY: {query}")
        print(f"{'-'*100}\n")
        
        # Run hybrid search
        ranked_docs, metrics = pipeline.search(
            query=query,
            k_total=6,
            top_n=5,
            use_reranker=True
        )
        
        print(f"Metrics: Semantic={metrics['semantic_results']}, "
              f"Lexical={metrics['lexical_results']}, "
              f"Final={metrics['reranked_results']}")
        print()
        
        # Show ranking changes
        log_ranking_comparison(query, ranked_docs, max_display=5)


def demo_evaluation():
    """Demonstrate RAGAS evaluation comparing configurations."""
    
    print("\n" + "="*100)
    print("RAGAS EVALUATION: Comparing Dense vs Hybrid with RRF")
    print("="*100 + "\n")
    
    # Prepare test set (Vietnamese beauty queries)
    test_queries = [
        "Tôi có da dầu mụn, sản phẩm nào phù hợp?",
        "Cách sử dụng Retinol an toàn thế nào?",
        "Chống nắng SPF bao nhiêu là đủ?"
    ]
    
    ground_truth_docs = [
        ["prod_001", "prod_002"],  # BHA and clay mask for oily acne
        ["prod_003", "prod_006"],  # Retinol serum and warning
        ["prod_004"]               # SPF 50+ sunscreen
    ]
    
    # Simulate retrieved documents (what system returns)
    retrieved_doc_ids_dense = [
        ["prod_001", "prod_003", "prod_004"],
        ["prod_003", "prod_005", "prod_001"],
        ["prod_004", "prod_001", "prod_005"]
    ]
    
    retrieved_doc_ids_hybrid = [
        ["prod_001", "prod_002", "prod_003"],
        ["prod_006", "prod_003", "prod_005"],
        ["prod_004", "prod_003", "prod_002"]
    ]
    
    # Prepare contexts (what gets passed to LLM)
    def get_context_for_docs(doc_ids, all_docs):
        contexts = []
        for did in doc_ids:
            for doc in all_docs:
                if doc['id'] == did:
                    contexts.append(doc['page_content'])
                    break
        return [" ".join(contexts)]
    
    vectorstore = MockVectorStore()
    all_docs = vectorstore.documents
    
    retrieved_contexts_dense = [
        get_context_for_docs(doc_list, all_docs)
        for doc_list in retrieved_doc_ids_dense
    ]
    
    retrieved_contexts_hybrid = [
        get_context_for_docs(doc_list, all_docs)
        for doc_list in retrieved_doc_ids_hybrid
    ]
    
    # Simulate generated answers
    generated_answers_dense = [
        "Sữa rửa mặt BHA là tốt nhất cho da dầu mụn vì chứa salicylic acid",
        "Retinol có thể sử dụng khi mang thai nhưng cần thận trọng",
        "Chống nắng SPF 30 là đủ cho hàng ngày"
    ]
    
    generated_answers_hybrid = [
        "Cho da dầu mụn, sử dụng sữa rửa mặt BHA kết hợp mặt nạ clay detox sẽ hiệu quả nhất",
        "Retinol không nên sử dụng khi mang thai vì có nguy hiểm ảnh hưởng đến thai nhi",
        "SPF 50+ PA++++ là tiêu chuẩn bảo vệ tối ưu cho tất cả loại da"
    ]
    
    # Evaluate both configurations
    evaluator = RAGEvaluator()
    
    metrics_dense = evaluator.evaluate_config(
        config_name="Dense Semantic Only",
        queries=test_queries,
        ground_truth_docs=ground_truth_docs,
        retrieved_contexts=retrieved_contexts_dense,
        retrieved_doc_ids=retrieved_doc_ids_dense,
        generated_answers=generated_answers_dense,
        top_k=5
    )
    
    metrics_hybrid = evaluator.evaluate_config(
        config_name="Hybrid with RRF + Vietnamese Re-ranker",
        queries=test_queries,
        ground_truth_docs=ground_truth_docs,
        retrieved_contexts=retrieved_contexts_hybrid,
        retrieved_doc_ids=retrieved_doc_ids_hybrid,
        generated_answers=generated_answers_hybrid,
        top_k=5
    )
    
    # Print detailed evaluation
    print("\n" + "="*100)
    print("CONFIGURATION 1: Dense Semantic Only (Baseline)")
    print("="*100)
    print_evaluation_report(metrics_dense)
    
    print("\n" + "="*100)
    print("CONFIGURATION 2: Hybrid with RRF + Vietnamese Re-ranker")
    print("="*100)
    print_evaluation_report(metrics_hybrid)
    
    # Print comparison
    print("\n" + "="*100)
    print("COMPARISON SUMMARY")
    print("="*100)
    results = {
        "dense": metrics_dense,
        "hybrid": metrics_hybrid
    }
    print_comparison_report(results)
    
    # Calculate improvements
    print("\n" + "="*100)
    print("IMPROVEMENTS (Hybrid vs Dense)")
    print("="*100)
    print(f"Context Precision:  {metrics_dense.context_precision:.1%} -> {metrics_hybrid.context_precision:.1%} "
          f"(+{(metrics_hybrid.context_precision - metrics_dense.context_precision)*100:.1f}%)")
    print(f"Context Recall:     {metrics_dense.context_recall:.1%} -> {metrics_hybrid.context_recall:.1%} "
          f"(+{(metrics_hybrid.context_recall - metrics_dense.context_recall)*100:.1f}%)")
    print(f"Hit Rate:           {metrics_dense.hit_rate:.1%} -> {metrics_hybrid.hit_rate:.1%} "
          f"(+{(metrics_hybrid.hit_rate - metrics_dense.hit_rate)*100:.1f}%)")
    print(f"Answer Relevancy:   {metrics_dense.answer_relevancy:.1%} -> {metrics_hybrid.answer_relevancy:.1%} "
          f"(+{(metrics_hybrid.answer_relevancy - metrics_dense.answer_relevancy)*100:.1f}%)")
    print(f"Faithfulness:       {metrics_dense.faithfulness:.1%} -> {metrics_hybrid.faithfulness:.1%} "
          f"(+{(metrics_hybrid.faithfulness - metrics_dense.faithfulness)*100:.1f}%)")
    print("="*100)


def demo_alpha_tuning():
    """Demonstrate how alpha parameter affects ranking."""
    
    print("\n" + "="*100)
    print("ALPHA PARAMETER TUNING: Impact on RRF Ranking")
    print("="*100 + "\n")
    
    # Simulate semantic and lexical results
    semantic_results = [
        ("prod_001", 0.95),  # BHA cleanser - high semantic score
        ("prod_003", 0.87),  # Retinol - medium semantic score
        ("prod_005", 0.72),  # Toner - lower semantic score
    ]
    
    lexical_results = [
        ("prod_001", 0.88),  # Contains "da dầu"
        ("prod_002", 0.82),  # Contains "sạch sâu"
        ("prod_003", 0.45),  # Lower lexical relevance
    ]
    
    # Create mock documents
    mock_docs = {
        "prod_001": RankedDocument(
            doc_id="prod_001",
            content="Sữa Rửa Mặt BHA",
            metadata={'name': 'BHA Cleanser'},
            semantic_score=0.95
        ),
        "prod_002": RankedDocument(
            doc_id="prod_002",
            content="Mặt nạ clay detox",
            metadata={'name': 'Clay Mask'},
            semantic_score=0.70
        ),
        "prod_003": RankedDocument(
            doc_id="prod_003",
            content="Serum Retinol",
            metadata={'name': 'Retinol Serum'},
            semantic_score=0.87
        ),
        "prod_005": RankedDocument(
            doc_id="prod_005",
            content="Toner HA",
            metadata={'name': 'HA Toner'},
            semantic_score=0.72
        ),
    }
    
    print("Query: 'Tôi có da dầu mụn, sản phẩm nào phù hợp?'\n")
    print(f"Semantic results: {semantic_results}")
    print(f"Lexical results:  {lexical_results}\n")
    
    # Test different alpha values
    alpha_values = [0.0, 0.25, 0.5, 0.75, 1.0]
    
    print(f"{'Alpha':<8} {'Weight':<20} {'Ranking':<40}")
    print("-" * 70)
    
    for alpha in alpha_values:
        rrf = ReciprocalRankFusion(alpha=alpha)
        fused = rrf.fuse(semantic_results, lexical_results, mock_docs)
        
        ranking = " > ".join([doc.doc_id for doc in fused[:3]])
        weight = f"{int(alpha*100)}% semantic, {int((1-alpha)*100)}% lexical"
        print(f"{alpha:<8.2f} {weight:<20} {ranking:<40}")
    
    print("\nINTERPRETATION:")
    print("  Alpha 0.0 (pure lexical): Favors documents with exact keyword matches")
    print("  Alpha 0.5 (balanced): Both semantic understanding and keyword relevance")
    print("  Alpha 1.0 (pure semantic): Only conceptual similarity, ignores exact terms")
    print("\nRECOMMENDATION FOR YOUR BEAUTY CORPUS:")
    print("  Use alpha=0.5 (balanced) - Product queries need both semantic understanding")
    print("  (e.g., 'phù hợp' = 'suitable') and exact term matching (e.g., 'BHA', 'Retinol')")


if __name__ == "__main__":
    # Run all demonstrations
    print("\nStarting Hybrid Search Demonstrations...\n")
    
    # Comment/uncomment to run specific demos
    demo_hybrid_search()      # Shows RRF and re-ranking in action
    demo_evaluation()         # Compares dense vs hybrid with metrics
    demo_alpha_tuning()       # Shows alpha parameter impact
    
    print("\n" + "="*100)
    print("Demonstrations Complete!")
    print("="*100)
    print("\nNEXT STEPS:")
    print("1. Review HYBRID_SEARCH_INTEGRATION.md for integration instructions")
    print("2. Install sentence-transformers and required packages")
    print("3. Modify your chatbot_flask.py to use HybridSearchPipeline")
    print("4. Run evaluation on your actual test data")
    print("5. Tune alpha parameter based on evaluation results")
    print("\nFor production deployment:")
    print("- Set up monitoring dashboards for evaluation metrics")
    print("- Log ranking changes for debugging")
    print("- Track Context Precision >= 0.75 and Recall >= 0.85")
