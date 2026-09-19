"""Dependency-free retrieval metrics used by recommendation evaluation."""

from __future__ import annotations

from typing import Iterable


def hit_rate(retrieved: Iterable[str], relevant: Iterable[str]) -> float:
    return float(bool(set(map(str, retrieved)) & set(map(str, relevant))))


def mean_reciprocal_rank(retrieved: Iterable[str], relevant: Iterable[str]) -> float:
    relevant_set = set(map(str, relevant))
    for rank, item in enumerate(retrieved, 1):
        if str(item) in relevant_set:
            return 1.0 / rank
    return 0.0


def context_precision(retrieved: Iterable[str], relevant: Iterable[str]) -> float:
    values = list(map(str, retrieved))
    if not values:
        return 0.0
    return len(set(values) & set(map(str, relevant))) / len(values)


def evaluate_retrieval(retrieved: Iterable[str], relevant: Iterable[str]) -> dict[str, float]:
    """Return basic hit-rate, MRR, precision, and recall metrics."""

    retrieved_values = list(map(str, retrieved))
    relevant_values = set(map(str, relevant))
    found = len(set(retrieved_values) & relevant_values)
    return {
        "hit_rate": float(bool(found)),
        "mrr": mean_reciprocal_rank(retrieved_values, relevant_values),
        "context_precision": context_precision(retrieved_values, relevant_values),
        "context_recall": found / len(relevant_values) if relevant_values else 0.0,
    }
