from __future__ import annotations

from dataclasses import asdict, dataclass, field
from typing import Any


AUTONOMY_POLICIES = {"assisted", "auto_preview", "auto_publish"}
CONTENT_MODES = {"Product Intro", "Problem-Solution", "Scientific Review"}
PLATFORMS = {"internal", "youtube", "facebook"}


@dataclass
class AgentBrief:
    goal: str
    product_queries: list[str] = field(default_factory=list)
    explicit_product_ids: list[str] = field(default_factory=list)
    content_mode: str = "Product Intro"
    scheduled_at: str | None = None
    duration_minutes: int = 60
    platforms: list[str] = field(default_factory=lambda: ["internal"])
    autonomy_policy: str = "auto_preview"
    campaign_name: str = ""
    missing_fields: list[str] = field(default_factory=list)
    confidence: float = 0.0

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)

    @classmethod
    def from_mapping(cls, data: dict[str, Any], *, fallback_goal: str = "") -> "AgentBrief":
        policy = str(data.get("autonomy_policy") or "auto_preview").strip().lower()
        if policy not in AUTONOMY_POLICIES:
            policy = "auto_preview"

        mode = str(data.get("content_mode") or "Product Intro").strip()
        if mode not in CONTENT_MODES:
            mode = "Product Intro"

        platforms = [
            str(item).strip().lower()
            for item in (data.get("platforms") or ["internal"])
            if str(item).strip().lower() in PLATFORMS
        ]
        if not platforms:
            platforms = ["internal"]

        product_queries = [
            str(item).strip()[:180]
            for item in (data.get("product_queries") or [])
            if str(item).strip()
        ][:20]
        explicit_ids = [
            str(item).strip()[:80]
            for item in (data.get("explicit_product_ids") or [])
            if str(item).strip()
        ][:100]

        try:
            duration = int(data.get("duration_minutes") or 60)
        except (TypeError, ValueError):
            duration = 60

        try:
            confidence = float(data.get("confidence") or 0.0)
        except (TypeError, ValueError):
            confidence = 0.0

        return cls(
            goal=str(data.get("goal") or fallback_goal).strip()[:2000],
            product_queries=product_queries,
            explicit_product_ids=explicit_ids,
            content_mode=mode,
            scheduled_at=str(data.get("scheduled_at") or "").strip() or None,
            duration_minutes=max(1, min(duration, 720)),
            platforms=list(dict.fromkeys(platforms)),
            autonomy_policy=policy,
            campaign_name=str(data.get("campaign_name") or "").strip()[:160],
            missing_fields=[
                str(item).strip()[:80]
                for item in (data.get("missing_fields") or [])
                if str(item).strip()
            ][:20],
            confidence=max(0.0, min(confidence, 1.0)),
        )


AGENT_BRIEF_JSON_SCHEMA: dict[str, Any] = {
    "type": "object",
    "additionalProperties": False,
    "required": [
        "goal",
        "product_queries",
        "explicit_product_ids",
        "content_mode",
        "scheduled_at",
        "duration_minutes",
        "platforms",
        "campaign_name",
        "missing_fields",
        "confidence",
    ],
    "properties": {
        "goal": {"type": "string"},
        "product_queries": {"type": "array", "items": {"type": "string"}},
        "explicit_product_ids": {"type": "array", "items": {"type": "string"}},
        "content_mode": {
            "type": "string",
            "enum": ["Product Intro", "Problem-Solution", "Scientific Review"],
        },
        "scheduled_at": {"type": ["string", "null"]},
        "duration_minutes": {"type": "integer", "minimum": 1, "maximum": 720},
        "platforms": {
            "type": "array",
            "items": {"type": "string", "enum": ["internal", "youtube", "facebook"]},
        },
        "campaign_name": {"type": "string"},
        "missing_fields": {"type": "array", "items": {"type": "string"}},
        "confidence": {"type": "number", "minimum": 0, "maximum": 1},
    },
}

