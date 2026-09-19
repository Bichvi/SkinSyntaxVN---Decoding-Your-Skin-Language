"""Concern-to-active and routine-step query mapping."""

from __future__ import annotations

import unicodedata
from dataclasses import dataclass


def _norm(value: object) -> str:
    text = unicodedata.normalize("NFKD", str(value or "").lower())
    return "".join(ch for ch in text if not unicodedata.combining(ch))


@dataclass(frozen=True)
class StepMapping:
    name: str
    category: str
    query_template: str
    target_ingredients: tuple[str, ...] = ()
    optional: bool = False


CONCERN_ACTIVES: dict[str, tuple[str, ...]] = {
    "mụn": ("BHA", "niacinamide", "azelaic acid"),
    "acne": ("BHA", "niacinamide", "azelaic acid"),
    "thâm": ("vitamin C", "niacinamide", "azelaic acid"),
    "tham": ("vitamin C", "niacinamide", "azelaic acid"),
    "nám": ("vitamin C", "azelaic acid"),
    "dầu": ("niacinamide", "BHA"),
    "dau": ("niacinamide", "BHA"),
    "khô": ("hyaluronic acid", "ceramide"),
    "kho": ("hyaluronic acid", "ceramide"),
    "nhạy cảm": ("ceramide", "panthenol"),
    "nhay cam": ("ceramide", "panthenol"),
    "lão hóa": ("retinol", "peptides"),
    "lao hoa": ("retinol", "peptides"),
    "nếp nhăn": ("retinol", "peptides"),
}


AM_STEPS: tuple[StepMapping, ...] = (
    StepMapping("Làm sạch", "cleanser", "sữa rửa mặt dịu nhẹ cho {profile}", optional=False),
    StepMapping("Dưỡng ẩm", "moisturizer", "kem dưỡng phục hồi và cấp ẩm cho {profile}", optional=False),
    StepMapping("Chống nắng", "sunscreen", "kem chống nắng da mặt SPF 30+ cho {profile}", optional=False),
)

PM_STEPS: tuple[StepMapping, ...] = (
    StepMapping("Làm sạch", "cleanser", "sữa rửa mặt dịu nhẹ cho {profile}", optional=False),
    StepMapping("Đặc trị", "treatment", "serum đặc trị {actives} cho {profile}", optional=True),
    StepMapping("Dưỡng ẩm", "moisturizer", "kem dưỡng phục hồi và cấp ẩm cho {profile}", optional=False),
)


def actives_for_concerns(concerns: list[str]) -> list[str]:
    """Return stable, deduplicated actives for the user's concerns."""

    result: list[str] = []
    for concern in concerns:
        folded = _norm(concern)
        for key, actives in CONCERN_ACTIVES.items():
            if _norm(key) in folded or folded in _norm(key):
                for active in actives:
                    if active not in result:
                        result.append(active)
    return result


def get_step_mapping(concerns: list[str], session: str, profile_label: str) -> list[dict]:
    """Build specialized product queries for AM or PM routine steps."""

    actives = actives_for_concerns(concerns)
    active_text = ", ".join(actives[:3]) or "phù hợp da"
    source = AM_STEPS if session == "am" else PM_STEPS
    result: list[dict] = []
    order = 1
    for step in source:
        result.append(
            {
                "step_name": step.name,
                "step_order": order,
                "category": step.category,
                "query": step.query_template.format(profile=profile_label, actives=active_text),
                "target_ingredients": list(step.target_ingredients) + actives[:3] if step.name == "Đặc trị" else [],
                "optional": step.optional,
                "session": session,
            }
        )
        order += 1
    return result
