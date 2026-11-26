"""Score frozen recommendation runs with relevance labels, without network access."""

import argparse
import json
import math
import statistics
from pathlib import Path


def evaluate(cases, k):
    scored = []
    skipped = 0
    for case in cases:
        relevant = set(case["relevant_ids"])
        ranked = list(dict.fromkeys(case["ranked_ids"]))[:k]
        if not relevant:
            skipped += 1
            continue
        hits = [product_id in relevant for product_id in ranked]
        count = sum(hits)
        precision, recall = count / k, count / len(relevant)
        dcg = sum(hit / math.log2(rank + 2) for rank, hit in enumerate(hits))
        ideal = sum(1 / math.log2(rank + 2) for rank in range(min(k, len(relevant))))
        scored.append({
            "precision": precision,
            "recall": recall,
            "f1": 2 * precision * recall / (precision + recall) if count else 0,
            "ndcg": dcg / ideal,
            "mrr": 1 / (hits.index(True) + 1) if count else 0,
        })
    query_counts = [case["retrieval_calls"] for case in cases if case.get("retrieval_calls") is not None]
    return {
        "k": k,
        "queries_scored": len(scored),
        "queries_without_relevance": skipped,
        "macro": {metric: statistics.mean(row[metric] for row in scored) for metric in scored[0]} if scored else {},
        "queries_with_retrieval_count": len(query_counts),
        "mean_retrieval_calls": statistics.mean(query_counts) if query_counts else None,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("cases", type=Path, help="JSON list with relevant_ids, ranked_ids and optional retrieval_calls")
    parser.add_argument("--k", type=int, nargs="+", default=[3, 5, 10])
    args = parser.parse_args()
    if min(args.k) < 1:
        parser.error("k must be positive")
    cases = json.loads(args.cases.read_text(encoding="utf-8"))
    print(json.dumps([evaluate(cases, k) for k in args.k], indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
