"""Detect and resolve same-session active-ingredient conflicts."""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Any

from .knowledge.ingredient_rules import IngredientConflict, find_conflicts
from .safety_filter import SafetyFilter

logger = logging.getLogger(__name__)


@dataclass
class ConflictResult:
    selected: list[dict[str, Any] | None]
    warnings: list[dict[str, Any]] = field(default_factory=list)


class ConflictChecker:
    """Keep incompatible treatments out of one AM or PM session."""

    @staticmethod
    def _items(selected: list[dict[str, Any] | None]) -> list[tuple[str, str]]:
        return [
            (
                str(item.get("name") or item.get("ten_san_pham") or item.get("id")),
                " ".join(str(item.get(field) or "") for field in ("ingredients", "thanh_phan_chinh", "ingredients_full", "thanh_phan_day_du")),
            )
            for item in selected
            if item
        ]

    @classmethod
    def check(cls, selected: list[dict[str, Any] | None]) -> list[IngredientConflict]:
        """Return rule matches for one routine session."""

        return find_conflicts(cls._items(selected))

    def resolve(
        self,
        candidates_by_step: list[list[dict[str, Any]]],
        optional_steps: list[bool],
        budget: int | None,
        session: str,
    ) -> ConflictResult:
        """Pick the next compatible candidate when a top result conflicts."""

        selected = SafetyFilter.select_with_budget(candidates_by_step, optional_steps, budget)
        warnings: list[dict[str, Any]] = []
        for _ in range(3):
            conflicts = self.check(selected)
            if not conflicts:
                break
            changed = False
            for index, current in enumerate(selected):
                if current is None:
                    continue
                for alternative in candidates_by_step[index][1:]:
                    trial = list(selected)
                    trial[index] = alternative
                    if budget and sum(int(item.get("price") or item.get("gia_ban") or 0) for item in trial if item) > budget:
                        continue
                    if not self.check(trial):
                        selected = trial
                        changed = True
                        break
                if changed:
                    break
            if not changed:
                for conflict in conflicts:
                    warnings.append(
                        {
                            "pair": [conflict.first, conflict.second],
                            "resolution": conflict.resolution + f" Session: {session.upper()}.",
                        }
                    )
                break
        logger.info("Conflict check complete", extra={"session": session, "warnings": len(warnings)})
        return ConflictResult(selected=selected, warnings=warnings)
