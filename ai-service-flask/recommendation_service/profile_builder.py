"""Build a compact, factual user skin profile document."""

from __future__ import annotations

import logging
from typing import Any

from .product_repository import ProductRepository
from .schemas import RecommendRequest, UserProfile

logger = logging.getLogger(__name__)


def _list(value: Any) -> list[str]:
    if value is None:
        return []
    if isinstance(value, str):
        return [part.strip() for part in value.replace(";", ",").split(",") if part.strip()]
    if isinstance(value, (list, tuple, set)):
        return [str(part).strip() for part in value if str(part).strip()]
    return [str(value).strip()] if str(value).strip() else []


class ProfileBuilder:
    """Merge request fields with optional stored context without requiring a DB."""

    def __init__(self, repository: ProductRepository | None = None) -> None:
        self.repository = repository or ProductRepository()

    def build(self, request: RecommendRequest) -> UserProfile:
        """Return normalized profile and embedding-ready text."""

        stored = self.repository.get_user_context(request.user_id, request.email)
        skin_type = str(request.skin_type or stored.get("skin_type") or stored.get("loai_da") or "").strip()
        concerns = request.skin_concerns or _list(stored.get("concerns") or stored.get("van_de_da"))
        avoid = request.avoid_ingredients or _list(
            stored.get("avoid_ingredients") or stored.get("thanh_phan_tranh")
        )
        budget = request.budget
        if budget is None:
            try:
                budget = int(stored.get("budget") or stored.get("ngan_sach"))
            except (TypeError, ValueError):
                budget = None
        history = list(dict.fromkeys(request.view_history + self.repository.get_history(request.user_id, request.email)))
        sensitive = request.sensitive_level or str(
            stored.get("sensitive_level") or stored.get("muc_do_nhay_cam") or ""
        ).strip()
        document_parts = ["User skin profile for routine recommendation."]
        if skin_type:
            document_parts.append(f"Skin type: {skin_type}.")
        if concerns:
            document_parts.append("Concerns: " + ", ".join(concerns) + ".")
        if sensitive:
            document_parts.append(f"Sensitivity level: {sensitive}.")
        if avoid:
            document_parts.append("Avoid ingredients: " + ", ".join(avoid) + ".")
        if budget:
            document_parts.append(f"Total routine budget: {budget} VND.")
        if request.current_routine:
            document_parts.append("Current routine: " + ", ".join(map(str, request.current_routine)) + ".")
        if history:
            document_parts.append("Recent product history: " + ", ".join(history[:8]) + ".")

        profile = UserProfile(
            user_id=request.user_id,
            skin_type=skin_type,
            concerns=list(dict.fromkeys(concerns)),
            sensitive_level=sensitive,
            budget=budget,
            avoid_ingredients=list(dict.fromkeys(avoid)),
            current_routine=request.current_routine,
            view_history=history,
            profile_document=" ".join(document_parts),
        )
        logger.info("Profile built", extra={"user_id": request.user_id, "has_stored_context": bool(stored)})
        return profile
