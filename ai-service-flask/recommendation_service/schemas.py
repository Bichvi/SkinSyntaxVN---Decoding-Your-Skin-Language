"""Pydantic request and response contracts for routine recommendations."""

from __future__ import annotations

import re
from typing import Any

from pydantic import BaseModel, ConfigDict, Field, field_validator


def _as_list(value: Any) -> list[str]:
    if value is None:
        return []
    if isinstance(value, str):
        return [part.strip() for part in re.split(r"[,;|\n\r]+", value) if part.strip()]
    if isinstance(value, (list, tuple, set)):
        return [str(part).strip() for part in value if str(part).strip()]
    return [str(value).strip()] if str(value).strip() else []


def _as_budget(value: Any) -> int | None:
    if value in (None, "", 0, "0"):
        return None
    if isinstance(value, bool):
        return None
    digits = re.sub(r"[^0-9]", "", str(value))
    return int(digits) if digits else None


class UserProfile(BaseModel):
    """Normalized user profile used by all pipeline stages."""

    model_config = ConfigDict(extra="ignore")

    user_id: str = ""
    skin_type: str = ""
    concerns: list[str] = Field(default_factory=list)
    sensitive_level: str = ""
    budget: int | None = None
    avoid_ingredients: list[str] = Field(default_factory=list)
    current_routine: list[Any] = Field(default_factory=list)
    view_history: list[str] = Field(default_factory=list)
    profile_document: str = ""

    @field_validator("concerns", "avoid_ingredients", "view_history", mode="before")
    @classmethod
    def normalize_lists(cls, value: Any) -> list[str]:
        return _as_list(value)

    @field_validator("budget", mode="before")
    @classmethod
    def normalize_budget(cls, value: Any) -> int | None:
        return _as_budget(value)


class RecommendRequest(BaseModel):
    """Accepted request payload; legacy field aliases are normalized at the edge."""

    model_config = ConfigDict(extra="ignore")

    user_id: str = ""
    email: str = ""
    skin_type: str = ""
    skin_concerns: list[str] = Field(default_factory=list)
    sensitive_level: str = ""
    budget: int | None = None
    avoid_ingredients: list[str] = Field(default_factory=list)
    current_routine: list[Any] = Field(default_factory=list)
    view_history: list[str] = Field(default_factory=list)
    routine_type: str = "personalized"
    query_text: str = ""
    user_profile: dict[str, Any] = Field(default_factory=dict)

    @field_validator("skin_concerns", "avoid_ingredients", "view_history", mode="before")
    @classmethod
    def normalize_lists(cls, value: Any) -> list[str]:
        return _as_list(value)

    @field_validator("budget", mode="before")
    @classmethod
    def normalize_budget(cls, value: Any) -> int | None:
        return _as_budget(value)

    @classmethod
    def from_payload(cls, payload: dict[str, Any] | None) -> "RecommendRequest":
        """Normalize canonical and legacy PHP payloads into one contract."""

        data = dict(payload or {})
        nested = data.get("user_profile") or data.get("recommendation_profile") or data.get("profile") or {}
        if not isinstance(nested, dict):
            nested = {}
        merged = {**nested, **data}
        concerns = merged.get("skin_concerns", merged.get("concerns", []))
        sensitive = merged.get("sensitive_level", merged.get("sensitivity", ""))
        return cls(
            user_id=str(merged.get("user_id") or merged.get("session_user_id") or ""),
            email=str(merged.get("email") or "").strip(),
            skin_type=str(merged.get("skin_type") or merged.get("loai_da") or "").strip(),
            skin_concerns=concerns,
            sensitive_level=str(sensitive or "").strip(),
            budget=merged.get("budget"),
            avoid_ingredients=merged.get("avoid_ingredients", merged.get("thanh_phan_can_tranh", [])),
            current_routine=merged.get("current_routine", []),
            view_history=merged.get("view_history", merged.get("recent_keywords", [])),
            routine_type=str(merged.get("routine_type") or merged.get("interaction_mode") or "personalized"),
            query_text=str(merged.get("query_text") or merged.get("user_query") or merged.get("query") or "").strip(),
            user_profile=nested,
        )


class RecommendedProduct(BaseModel):
    model_config = ConfigDict(extra="ignore")

    product_id: str = ""
    name: str = ""
    price: int = 0
    image: str = ""
    match_score: float = 0.0
    key_ingredients: list[str] = Field(default_factory=list)
    avoid_flags: list[str] = Field(default_factory=list)


class AlternativeProduct(BaseModel):
    model_config = ConfigDict(extra="ignore")

    product_id: str = ""
    name: str = ""
    price: int = 0
    match_score: float = 0.0


class RoutineStep(BaseModel):
    model_config = ConfigDict(extra="ignore")

    step_name: str
    step_order: int
    recommended_product: RecommendedProduct | None = None
    alternatives: list[AlternativeProduct] = Field(default_factory=list)
    why_this_product: str = ""


class ProfileSummary(BaseModel):
    skin_type: str = ""
    concerns: list[str] = Field(default_factory=list)
    avoided: list[str] = Field(default_factory=list)


class Combo(BaseModel):
    total_price: int = 0
    discount_percent: float = 0.0
    product_ids: list[str] = Field(default_factory=list)


class ConflictWarning(BaseModel):
    pair: list[str] = Field(default_factory=list)
    resolution: str = ""


class RecommendResponse(BaseModel):
    """Stable JSON envelope returned to PHP and other callers."""

    model_config = ConfigDict(extra="ignore")

    ok: bool = True
    schema_version: str = "1.0"
    routine_type: str = "personalized"
    profile_summary: ProfileSummary = Field(default_factory=ProfileSummary)
    am_routine: list[RoutineStep] = Field(default_factory=list)
    pm_routine: list[RoutineStep] = Field(default_factory=list)
    combo: Combo = Field(default_factory=Combo)
    conflict_warnings: list[ConflictWarning] = Field(default_factory=list)
    safety_notes: str = ""
    latency_ms: float | None = None

    def to_dict(self) -> dict[str, Any]:
        """Return JSON-safe response data."""

        return self.model_dump(mode="json", exclude_none=True)
