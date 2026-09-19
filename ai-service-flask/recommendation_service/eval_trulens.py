"""Optional TruLens adapter; deterministic metrics remain available without it."""

from __future__ import annotations

from typing import Any

from .rag_evaluation import evaluate_retrieval


def evaluate_case(retrieved_ids: list[str], relevant_ids: list[str]) -> dict[str, Any]:
    """Evaluate one retrieval case without requiring the optional TruLens SDK."""

    return evaluate_retrieval(retrieved_ids, relevant_ids)


def build_trulens_recorder(*args: Any, **kwargs: Any) -> Any | None:
    """Return a TruLens recorder when installed, otherwise ``None``."""

    try:  # pragma: no cover - optional dependency
        from trulens_eval import Tru

        return Tru(*args, **kwargs)
    except Exception:
        return None
