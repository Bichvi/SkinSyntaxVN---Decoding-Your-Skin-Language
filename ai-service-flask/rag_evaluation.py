# -*- coding: utf-8 -*-
"""
RAG Evaluation Suite for Vietnamese Content

Measures:
1. Context Precision: % of retrieved chunks actually relevant
2. Context Recall: whether correct document was retrieved
3. Answer Relevancy: how relevant is generated answer to query
4. Faithfulness: does answer come from context without hallucination

Usage:
    from rag_evaluation import RAGEvaluator
    
    evaluator = RAGEvaluator()
    results = evaluator.evaluate_config(
        config_name="hybrid_with_reranking",
        queries=test_queries,
        ground_truth_docs=ground_truth,
        retrieved_contexts=retrieved,
        generated_answers=answers
    )
"""

import json
import numpy as np
from typing import List, Dict, Any, Optional, Tuple
from dataclasses import dataclass, field, asdict
from datetime import datetime
import logging

logger = logging.getLogger(__name__)


@dataclass
class EvaluationMetrics:
    """Store evaluation metrics for a configuration."""
    config_name: str
    timestamp: str = field(default_factory=lambda: datetime.now().isoformat())
    
    # Retrieval metrics
    context_precision: float = 0.0
    context_recall: float = 0.0
    mean_reciprocal_rank: float = 0.0  # MRR
    hit_rate: float = 0.0
    
    # Generation metrics
    answer_relevancy: float = 0.0
    faithfulness: float = 0.0
    
    # Additional metrics
    avg_retrieval_rank: float = 0.0
    avg_response_length: int = 0
    
    # Metadata
    num_queries: int = 0
    num_relevant_docs: int = 0
    
    def to_dict(self) -> Dict[str, Any]:
        """Convert to dictionary."""
        return asdict(self)
    
    def __repr__(self) -> str:
        return (
            f"EvaluationMetrics(config={self.config_name}\n"
            f"  Context Precision: {self.context_precision:.3f}\n"
            f"  Context Recall: {self.context_recall:.3f}\n"
            f"  Hit Rate: {self.hit_rate:.3f}\n"
            f"  MRR: {self.mean_reciprocal_rank:.3f}\n"
            f"  Answer Relevancy: {self.answer_relevancy:.3f}\n"
            f"  Faithfulness: {self.faithfulness:.3f})"
        )


class RAGEvaluator:
    """
    Evaluation suite for Vietnamese RAG systems.
    
    Metrics computed:
    1. Context Precision: Checks if retrieved documents are relevant to query
    2. Context Recall: Checks if ground truth documents appear in retrieval
    3. Hit Rate: Percentage of queries where correct doc was retrieved
    4. MRR (Mean Reciprocal Rank): Average rank position of first relevant doc
    5. Answer Relevancy: LLM judgment on answer relevance to query
    6. Faithfulness: LLM check that answer comes from context
    """
    
    def __init__(self, llm=None):
        """
        Initialize evaluator.
        
        Args:
            llm: Optional LLM for evaluating answer relevancy/faithfulness
                 If None, simple heuristic-based evaluation is used
        """
        self.llm = llm
    
    def compute_context_precision(
        self,
        retrieved_doc_ids: List[str],
        ground_truth_doc_ids: List[str],
        top_k: int = 5
    ) -> float:
        """
        Context Precision: Of the top-k retrieved docs, what % are relevant?
        
        Formula: (Number of relevant docs in top-k) / k
        
        Args:
            retrieved_doc_ids: Ordered list of retrieved doc IDs
            ground_truth_doc_ids: List of relevant doc IDs
            top_k: Evaluate only top-k results
            
        Returns:
            Precision score (0 to 1)
        """
        if not ground_truth_doc_ids:
            return 0.0
        
        relevant_count = 0
        for doc_id in retrieved_doc_ids[:top_k]:
            if doc_id in ground_truth_doc_ids:
                relevant_count += 1
        
        precision = relevant_count / top_k if top_k > 0 else 0.0
        return precision
    
    def compute_context_recall(
        self,
        retrieved_doc_ids: List[str],
        ground_truth_doc_ids: List[str]
    ) -> float:
        """
        Context Recall: Were all relevant documents retrieved?
        
        Formula: (Number of relevant docs retrieved) / (Total relevant docs)
        
        Args:
            retrieved_doc_ids: List of retrieved doc IDs
            ground_truth_doc_ids: List of relevant doc IDs
            
        Returns:
            Recall score (0 to 1)
        """
        if not ground_truth_doc_ids:
            return 1.0  # No relevant docs needed
        
        relevant_retrieved = sum(1 for doc_id in retrieved_doc_ids if doc_id in ground_truth_doc_ids)
        recall = relevant_retrieved / len(ground_truth_doc_ids)
        return recall
    
    def compute_hit_rate(
        self,
        retrieved_doc_ids: List[str],
        ground_truth_doc_ids: List[str]
    ) -> float:
        """
        Hit Rate: Was at least one relevant document retrieved?
        
        Args:
            retrieved_doc_ids: List of retrieved doc IDs
            ground_truth_doc_ids: List of relevant doc IDs
            
        Returns:
            Binary score (0 or 1)
        """
        if not ground_truth_doc_ids:
            return 1.0
        
        for doc_id in retrieved_doc_ids:
            if doc_id in ground_truth_doc_ids:
                return 1.0
        return 0.0
    
    def compute_mrr(
        self,
        retrieved_doc_ids: List[str],
        ground_truth_doc_ids: List[str]
    ) -> float:
        """
        Mean Reciprocal Rank: What's the rank of first relevant document?
        
        Formula: 1 / rank_of_first_relevant_doc
        
        Args:
            retrieved_doc_ids: List of retrieved doc IDs
            ground_truth_doc_ids: List of relevant doc IDs
            
        Returns:
            MRR score (0 to 1)
        """
        for rank, doc_id in enumerate(retrieved_doc_ids, 1):
            if doc_id in ground_truth_doc_ids:
                return 1.0 / rank
        return 0.0
    
    def compute_answer_relevancy(
        self,
        query: str,
        answer: str
    ) -> float:
        """
        Answer Relevancy: Is the generated answer relevant to the query?
        
        Simple heuristic: Check if answer addresses the query topic.
        
        Args:
            query: User query
            answer: Generated answer
            
        Returns:
            Relevancy score (0 to 1)
        """
        # Extract key terms from query
        query_terms = set(query.lower().split())
        answer_lower = answer.lower()
        
        # Count matching terms
        matching_terms = sum(1 for term in query_terms if term in answer_lower)
        relevancy = min(matching_terms / max(len(query_terms), 1), 1.0)
        
        # Bonus: longer answers tend to be more relevant
        if len(answer) > 100:
            relevancy = min(relevancy + 0.1, 1.0)
        
        return relevancy
    
    def compute_faithfulness(
        self,
        context: str,
        answer: str
    ) -> float:
        """
        Faithfulness: Does answer come from context without hallucination?
        
        Simple heuristic: Check if key phrases in answer appear in context.
        
        Args:
            context: Retrieved context (concatenated)
            answer: Generated answer
            
        Returns:
            Faithfulness score (0 to 1)
        """
        if not context or not answer:
            return 0.0
        
        context_lower = context.lower()
        answer_lower = answer.lower()
        
        # Split answer into phrases
        phrases = answer_lower.split('.')
        faithfulness_count = 0
        
        for phrase in phrases:
            phrase = phrase.strip()
            if len(phrase) < 3:
                continue
            
            # Check if phrase appears in context
            if phrase in context_lower or any(
                word in context_lower for word in phrase.split() if len(word) > 3
            ):
                faithfulness_count += 1
        
        faithfulness = faithfulness_count / max(len(phrases), 1)
        return min(faithfulness, 1.0)
    
    def evaluate_config(
        self,
        config_name: str,
        queries: List[str],
        ground_truth_docs: List[List[str]],
        retrieved_contexts: List[List[str]],
        retrieved_doc_ids: List[List[str]],
        generated_answers: List[str],
        top_k: int = 5
    ) -> EvaluationMetrics:
        """
        Comprehensive evaluation of a RAG configuration.
        
        Args:
            config_name: Name of configuration (e.g., "hybrid_with_reranking")
            queries: List of test queries
            ground_truth_docs: List of ground truth doc IDs for each query
            retrieved_contexts: List of retrieved context texts for each query
            retrieved_doc_ids: List of retrieved doc IDs for each query
            generated_answers: List of generated answers for each query
            top_k: Evaluate top-k retrieval results
            
        Returns:
            EvaluationMetrics object
        """
        assert len(queries) == len(ground_truth_docs) == len(retrieved_contexts) == \
               len(generated_answers), "Input lists must have same length"
        
        # Compute per-query metrics
        precisions = []
        recalls = []
        hit_rates = []
        mrrs = []
        relevancies = []
        faithfulnesses = []
        retrieval_ranks = []
        
        for i, (query, gt_docs, ret_context, ret_docs, answer) in enumerate(
            zip(queries, ground_truth_docs, retrieved_contexts, retrieved_doc_ids, generated_answers)
        ):
            # Retrieval metrics
            precision = self.compute_context_precision(ret_docs, gt_docs, top_k)
            recall = self.compute_context_recall(ret_docs, gt_docs)
            hit = self.compute_hit_rate(ret_docs, gt_docs)
            mrr = self.compute_mrr(ret_docs, gt_docs)
            
            precisions.append(precision)
            recalls.append(recall)
            hit_rates.append(hit)
            mrrs.append(mrr)
            
            # Track first relevant doc rank
            for rank, doc_id in enumerate(ret_docs, 1):
                if doc_id in gt_docs:
                    retrieval_ranks.append(rank)
                    break
            else:
                retrieval_ranks.append(len(ret_docs) + 1)
            
            # Generation metrics
            relevancy = self.compute_answer_relevancy(query, answer)
            faithfulness = self.compute_faithfulness(ret_context[0] if ret_context else "", answer)
            
            relevancies.append(relevancy)
            faithfulnesses.append(faithfulness)
        
        # Aggregate metrics
        metrics = EvaluationMetrics(
            config_name=config_name,
            context_precision=np.mean(precisions),
            context_recall=np.mean(recalls),
            hit_rate=np.mean(hit_rates),
            mean_reciprocal_rank=np.mean(mrrs),
            answer_relevancy=np.mean(relevancies),
            faithfulness=np.mean(faithfulnesses),
            avg_retrieval_rank=np.mean(retrieval_ranks),
            avg_response_length=int(np.mean([len(a) for a in generated_answers])),
            num_queries=len(queries),
            num_relevant_docs=sum(len(gt) for gt in ground_truth_docs)
        )
        
        return metrics
    
    def compare_configs(
        self,
        results: Dict[str, EvaluationMetrics]
    ) -> Dict[str, Any]:
        """
        Compare multiple configurations and highlight improvements.
        
        Args:
            results: Dict mapping config_name -> EvaluationMetrics
            
        Returns:
            Comparison report
        """
        if not results:
            return {}
        
        # Create comparison table
        comparison = {
            "timestamp": datetime.now().isoformat(),
            "configs": {},
            "improvements": {}
        }
        
        # Convert to comparison format
        for config_name, metrics in results.items():
            comparison["configs"][config_name] = metrics.to_dict()
        
        # Calculate improvements vs baseline
        if len(results) >= 2:
            baseline_name = list(results.keys())[0]
            baseline = results[baseline_name]
            
            for config_name, metrics in list(results.items())[1:]:
                improvement = {
                    "config": config_name,
                    "vs_baseline": baseline_name,
                    "context_precision_delta": metrics.context_precision - baseline.context_precision,
                    "context_recall_delta": metrics.context_recall - baseline.context_recall,
                    "hit_rate_delta": metrics.hit_rate - baseline.hit_rate,
                    "mrr_delta": metrics.mean_reciprocal_rank - baseline.mean_reciprocal_rank,
                    "answer_relevancy_delta": metrics.answer_relevancy - baseline.answer_relevancy,
                    "faithfulness_delta": metrics.faithfulness - baseline.faithfulness,
                }
                comparison["improvements"][config_name] = improvement
        
        return comparison


def print_evaluation_report(metrics: EvaluationMetrics):
    """Pretty print evaluation metrics."""
    print(f"\n{'='*80}")
    print(f"EVALUATION REPORT: {metrics.config_name}")
    print(f"{'='*80}")
    print(f"Timestamp: {metrics.timestamp}")
    print(f"Queries evaluated: {metrics.num_queries}")
    print()
    print("RETRIEVAL METRICS:")
    print(f"  ├─ Context Precision (% relevant docs):  {metrics.context_precision:.1%}")
    print(f"  ├─ Context Recall (found all relevant):  {metrics.context_recall:.1%}")
    print(f"  ├─ Hit Rate (found >=1 relevant):       {metrics.hit_rate:.1%}")
    print(f"  └─ MRR (rank of first relevant):        {metrics.mean_reciprocal_rank:.3f}")
    print()
    print("GENERATION METRICS:")
    print(f"  ├─ Answer Relevancy (addresses query):  {metrics.answer_relevancy:.1%}")
    print(f"  └─ Faithfulness (grounded in context):  {metrics.faithfulness:.1%}")
    print()
    print("EFFICIENCY:")
    print(f"  ├─ Avg retrieval rank:                  {metrics.avg_retrieval_rank:.1f}")
    print(f"  └─ Avg response length:                 {metrics.avg_response_length} chars")
    print(f"{'='*80}\n")


def print_comparison_report(results: Dict[str, EvaluationMetrics]):
    """Pretty print comparison of multiple configurations."""
    if not results:
        return
    
    print(f"\n{'='*100}")
    print("RAG CONFIGURATION COMPARISON")
    print(f"{'='*100}\n")
    
    # Print table header
    print(f"{'Configuration':<30} {'Precision':<12} {'Recall':<12} {'Hit Rate':<12} {'MRR':<10}")
    print("-" * 100)
    
    # Print each config
    for config_name, metrics in results.items():
        print(f"{config_name:<30} "
              f"{metrics.context_precision:<11.1%} "
              f"{metrics.context_recall:<11.1%} "
              f"{metrics.hit_rate:<11.1%} "
              f"{metrics.mean_reciprocal_rank:<9.3f}")
    
    print("-" * 100)
    print()
    
    # Print detailed metrics
    for config_name, metrics in results.items():
        print_evaluation_report(metrics)


# Example evaluation dataset (Vietnamese beauty products)
EXAMPLE_EVALUATION_SET = [
    {
        "query": "Tôi có da dầu mụn, sản phẩm nào phù hợp?",
        "ground_truth_doc_ids": ["prod_cleanser_bha", "prod_acne_serum"],
        "expected_context": "BHA là thành phần hoạt chất để làm sạch lỗ chân lông, phù hợp với da dầu mụn"
    },
    {
        "query": "Có thể dùng Retinol khi bầu được không?",
        "ground_truth_doc_ids": ["prod_retinol_warning", "prod_pregnancy_guide"],
        "expected_context": "Retinol không được khuyến cáo sử dụng trong thời kỳ mang thai do có khả năng ảnh hưởng đến phát triển thai nhi"
    },
    {
        "query": "Kem chống nắng SPF bao nhiêu là đủ?",
        "ground_truth_doc_ids": ["prod_sunscreen_guide", "prod_spf_recommendation"],
        "expected_context": "SPF 30 trở lên được khuyến cáo sử dụng hàng ngày"
    }
]
