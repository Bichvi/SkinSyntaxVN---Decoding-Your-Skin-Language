"""Request/response shapes and validation for POST /api/search/recommend."""

from __future__ import annotations

import re
from dataclasses import asdict, dataclass, field
from typing import Any

from . import config

_ID_RE = re.compile(r"^[A-Za-z0-9_-]{1,32}$")
_MAX_IDS = 50


class ValidationError(ValueError):
    def __init__(self, field_name: str) -> None:
        super().__init__(field_name)
        self.field = field_name


@dataclass(frozen=True)
class RecommendRequest:
    q: str
    user_hash: str | None = None
    skin_profile: dict[str, Any] | None = None
    cart_ids: tuple[str, ...] = ()
    exclude_ids: tuple[str, ...] = ()
    limit_per_section: int = config.LIMIT_PER_SECTION


@dataclass
class RecommendItem:
    product_id: str
    score: float
    reason_code: str
    reason_params: list[str] = field(default_factory=list)


@dataclass
class Section:
    key: str
    items: list[RecommendItem]


@dataclass
class RecommendResponse:
    mode: str
    degraded: bool
    degraded_branches: list[str]
    took_ms: int
    intent: dict[str, Any] | None
    sections: list[Section]

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def _ids(payload: dict[str, Any], name: str) -> tuple[str, ...]:
    raw = payload.get(name)
    if raw is None:
        return ()
    if not isinstance(raw, list) or len(raw) > _MAX_IDS:
        raise ValidationError(name)
    out: list[str] = []
    for value in raw:
        if isinstance(value, bool) or not isinstance(value, (str, int)):
            raise ValidationError(name)
        text = str(value).strip()
        if not _ID_RE.match(text):
            raise ValidationError(name)
        out.append(text)
    return tuple(out)


def _skin_profile(raw: Any) -> dict[str, Any] | None:
    if raw is None:
        return None
    if not isinstance(raw, dict):
        raise ValidationError("skin_profile")
    skin_type = raw.get("skin_type")
    if skin_type is not None and (not isinstance(skin_type, str) or len(skin_type) > 64):
        raise ValidationError("skin_profile")
    concerns = raw.get("concerns") or []
    if not isinstance(concerns, list) or len(concerns) > 20:
        raise ValidationError("skin_profile")
    if any(not isinstance(c, str) or len(c) > 64 for c in concerns):
        raise ValidationError("skin_profile")
    return {"skin_type": skin_type or None, "concerns": list(concerns)}


def validate_request(payload: Any) -> RecommendRequest:
    """Validate a decoded JSON body; raise ``ValidationError(field)`` on bad input."""

    if not isinstance(payload, dict):
        raise ValidationError("body")
    q = payload.get("q")
    if not isinstance(q, str):
        raise ValidationError("q")
    q = q.strip()
    if not 1 <= len(q) <= 200:
        raise ValidationError("q")

    user_hash = payload.get("user_hash")
    if user_hash is not None and (not isinstance(user_hash, str) or not re.fullmatch(r"[A-Fa-f0-9]{1,128}", user_hash)):
        raise ValidationError("user_hash")

    limit = payload.get("limit_per_section", config.LIMIT_PER_SECTION)
    if isinstance(limit, bool) or not isinstance(limit, int) or not 1 <= limit <= 20:
        raise ValidationError("limit_per_section")

    return RecommendRequest(
        q=q,
        user_hash=user_hash,
        skin_profile=_skin_profile(payload.get("skin_profile")),
        cart_ids=_ids(payload, "cart_ids"),
        exclude_ids=_ids(payload, "exclude_ids"),
        limit_per_section=limit,
    )


# ---------------------------------------------------------------- intent parser (Bước 04)


@dataclass(frozen=True)
class PriceRange:
    min_vnd: int | None = None
    max_vnd: int | None = None

    def to_dict(self) -> dict[str, int | None]:
        return {"min_vnd": self.min_vnd, "max_vnd": self.max_vnd}


@dataclass(frozen=True)
class Intent:
    """Deterministic parse of a search query; entity lists hold graph keys."""

    raw: str
    normalized: str
    brands: list[str] = field(default_factory=list)
    categories: list[str] = field(default_factory=list)
    ingredients: list[str] = field(default_factory=list)
    skin_types: list[str] = field(default_factory=list)
    concerns: list[str] = field(default_factory=list)
    benefits: list[str] = field(default_factory=list)
    price: PriceRange | None = None
    residual_terms: list[str] = field(default_factory=list)
    confidence: float = 0.0

    def to_dict(self) -> dict[str, Any]:
        return {
            "raw": self.raw,
            "normalized": self.normalized,
            "brands": list(self.brands),
            "categories": list(self.categories),
            "ingredients": list(self.ingredients),
            "skin_types": list(self.skin_types),
            "concerns": list(self.concerns),
            "benefits": list(self.benefits),
            "price": self.price.to_dict() if self.price else None,
            "residual_terms": list(self.residual_terms),
            "confidence": self.confidence,
        }

    @property
    def is_empty(self) -> bool:
        return not (self.brands or self.categories or self.ingredients or self.skin_types
                    or self.concerns or self.benefits or self.price)
