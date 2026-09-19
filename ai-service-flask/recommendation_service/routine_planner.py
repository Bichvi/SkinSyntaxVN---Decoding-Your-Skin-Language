"""Plan standard AM/PM skincare steps and specialized queries."""

from __future__ import annotations

import logging
from dataclasses import dataclass

from .knowledge.concern_mapping import get_step_mapping
from .schemas import UserProfile

logger = logging.getLogger(__name__)


@dataclass(frozen=True)
class RoutinePlan:
    am: list[dict]
    pm: list[dict]


class RoutinePlanner:
    """Create a predictable routine shape before product retrieval."""

    def plan(self, profile: UserProfile) -> RoutinePlan:
        """Create AM and PM steps; strong treatment is PM-only by default."""

        profile_label = profile.skin_type or "mọi loại da"
        if profile.concerns:
            profile_label += " và " + ", ".join(profile.concerns[:3])
        plan = RoutinePlan(
            am=get_step_mapping(profile.concerns, "am", profile_label),
            pm=get_step_mapping(profile.concerns, "pm", profile_label),
        )
        logger.info("Routine planned", extra={"user_id": profile.user_id, "am_steps": len(plan.am), "pm_steps": len(plan.pm)})
        return plan
