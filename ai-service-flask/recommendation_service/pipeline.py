"""Five-stage Recommendation & Routine Solver orchestration."""

from __future__ import annotations

import logging
import time
from typing import Any

from . import config
from .conflict_checker import ConflictChecker
from .hybrid_search import HybridSearch, get_hybrid_search
from .output_builder import OutputBuilder
from .profile_builder import ProfileBuilder
from .reranker import Reranker
from .routine_planner import RoutinePlan, RoutinePlanner
from .safety_filter import SafetyFilter
from .schemas import RecommendRequest, RecommendResponse

logger = logging.getLogger(__name__)


class RecommendationPipeline:
    """Orchestrate profile, planning, retrieval, safety, reranking, and XAI output."""

    def __init__(
        self,
        profile_builder: ProfileBuilder | None = None,
        planner: RoutinePlanner | None = None,
        search: HybridSearch | None = None,
        safety_filter: SafetyFilter | None = None,
        reranker: Reranker | None = None,
        conflict_checker: ConflictChecker | None = None,
        output_builder: OutputBuilder | None = None,
    ) -> None:
        self.search = search or get_hybrid_search()
        repository = self.search.repository
        self.profile_builder = profile_builder or ProfileBuilder(repository)
        self.planner = planner or RoutinePlanner()
        self.safety_filter = safety_filter or SafetyFilter()
        self.reranker = reranker or Reranker()
        self.conflict_checker = conflict_checker or ConflictChecker()
        self.output_builder = output_builder or OutputBuilder()

    @classmethod
    def default(cls) -> "RecommendationPipeline":
        """Return the default process-local pipeline."""

        return cls()

    @staticmethod
    def _price(items: list[dict[str, Any] | None]) -> int:
        return sum(int(item.get("price") or item.get("gia_ban") or 0) for item in items if item)

    def _retrieve_session(
        self,
        profile: Any,
        steps: list[dict[str, Any]],
        user_id: str,
        rejected: list[dict[str, str]],
    ) -> list[list[dict[str, Any]]]:
        """Retrieve, hard-filter, and rerank each step's bounded candidate set."""

        all_candidates: list[list[dict[str, Any]]] = []
        query = profile.profile_document
        for step in steps:
            step_query = str(step.get("query") or query)
            try:
                hits = self.search.search(step_query, limit=config.TOP_K, user_id=user_id)
            except Exception as exc:
                logger.warning("Hybrid step degraded", extra={"user_id": user_id, "error": type(exc).__name__})
                hits = []
            products: list[dict[str, Any]] = []
            for hit in hits:
                item = dict(hit.product)
                item.update(
                    {
                        "semantic_score": hit.semantic_score,
                        "lexical_score": hit.lexical_score,
                        "rrf_score": hit.rrf_score,
                        "profile_text": self.search_text(item),
                    }
                )
                products.append(item)
            decision = self.safety_filter.filter_candidates(products, profile, str(step.get("category") or ""), user_id)
            rejected.extend(decision.rejected)
            reranked = self.reranker.rerank(
                f"{query} {step_query}",
                decision.candidates,
                limit=min(6, config.TOP_K),
            )
            all_candidates.append(reranked)
        return all_candidates

    @staticmethod
    def search_text(product: dict[str, Any]) -> str:
        return " ".join(
            str(product.get(field) or "")
            for field in ("name", "ten_san_pham", "category", "loai_san_pham", "ingredients", "description")
        )

    def recommend(self, payload: RecommendRequest | dict[str, Any]) -> RecommendResponse:
        """Generate a response while degrading each external branch independently."""

        started = time.perf_counter()
        request = payload if isinstance(payload, RecommendRequest) else RecommendRequest.from_payload(payload)
        profile = self.profile_builder.build(request)
        plan: RoutinePlan = self.planner.plan(profile)
        rejected: list[dict[str, str]] = []

        candidates = {
            "am": self._retrieve_session(profile, plan.am, request.user_id, rejected),
            "pm": self._retrieve_session(profile, plan.pm, request.user_id, rejected),
        }
        optional = {
            "am": [bool(step.get("optional")) for step in plan.am],
            "pm": [bool(step.get("optional")) for step in plan.pm],
        }
        selected: dict[str, list[dict[str, Any] | None]] = {"am": [], "pm": []}
        conflict_warnings: list[dict[str, Any]] = []
        remaining_budget = profile.budget
        for session in ("am", "pm"):
            result = self.conflict_checker.resolve(
                candidates[session],
                optional[session],
                remaining_budget,
                session,
            )
            selected[session] = result.selected
            conflict_warnings.extend(result.warnings)
            if remaining_budget:
                remaining_budget = max(0, remaining_budget - self._price(result.selected))

        response = self.output_builder.build(
            profile,
            plan,
            selected,
            candidates,
            conflict_warnings=conflict_warnings,
            rejected=rejected,
            latency_ms=(time.perf_counter() - started) * 1000,
        )
        logger.info(
            "Recommendation pipeline complete",
            extra={
                "user_id": request.user_id,
                "selected": len(response.combo.product_ids),
                "latency_ms": response.latency_ms,
            },
        )
        return response
