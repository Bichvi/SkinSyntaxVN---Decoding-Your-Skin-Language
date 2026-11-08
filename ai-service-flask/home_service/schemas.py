"""Request validation for POST /api/home/recommend."""

from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Any

from search_service.schemas import ValidationError

from . import config

_ID_RE = re.compile(r"^[0-9]{1,12}$")


@dataclass(frozen=True)
class HomeRequest:
    sections: tuple[str, ...]
    seen_ids: tuple[str, ...]
    skin_type: str | None
    concerns: tuple[str, ...]
    concern: str
    cart_ids: tuple[str, ...]
    exclude_ids: tuple[str, ...]
    limits: dict[str, int]


def _ids(payload: dict[str, Any], name: str, cap: int) -> tuple[str, ...]:
    raw = payload.get(name)
    if raw is None:
        return ()
    if not isinstance(raw, list):
        raise ValidationError(name)
    out: list[str] = []
    for value in raw:
        if isinstance(value, bool) or not isinstance(value, (str, int)):
            continue
        text = str(value).strip()
        if _ID_RE.match(text) and text not in out:
            out.append(text)
    return tuple(out[:cap])


def _sections(payload: dict[str, Any]) -> tuple[str, ...]:
    raw = payload.get("sections")
    if not isinstance(raw, list) or not raw:
        raise ValidationError("sections")
    out: list[str] = []
    for value in raw:
        if not isinstance(value, str) or value not in config.SECTION_KEYS:
            raise ValidationError("sections")
        if value not in out:
            out.append(value)
    return tuple(out)


def _limits(payload: dict[str, Any]) -> dict[str, int]:
    limits = dict(config.DEFAULT_LIMITS)
    raw = payload.get("limits")
    if raw is None:
        return limits
    if not isinstance(raw, dict):
        raise ValidationError("limits")
    for key in config.SECTION_KEYS:
        value = raw.get(key)
        if isinstance(value, bool) or not isinstance(value, int):
            continue
        limits[key] = max(config.MIN_LIMIT, min(config.MAX_LIMIT, value))
    return limits


def validate_home_request(payload: Any) -> HomeRequest:
    """Validate a decoded JSON body; raise ``ValidationError(field)`` on bad input."""

    if not isinstance(payload, dict):
        raise ValidationError("body")
    sections = _sections(payload)

    profile = payload.get("skin_profile")
    if profile is not None and not isinstance(profile, dict):
        raise ValidationError("skin_profile")
    profile = profile or {}
    skin_type = profile.get("skin_type")
    if not isinstance(skin_type, str) or skin_type not in config.SKIN_TYPE_KEYS:
        skin_type = None
    raw_concerns = profile.get("concerns")
    concerns: list[str] = []
    if isinstance(raw_concerns, list):
        for value in raw_concerns:
            if isinstance(value, str) and value in config.CONCERN_KEYS and value not in concerns:
                concerns.append(value)

    concern = payload.get("concern")
    if concern is None or concern == "":
        concern = concerns[0] if concerns else config.DEFAULT_CONCERN
    elif not isinstance(concern, str) or concern not in config.CONCERN_KEYS:
        raise ValidationError("concern")

    return HomeRequest(
        sections=sections,
        seen_ids=_ids(payload, "seen_ids", config.MAX_SEEN_IDS),
        skin_type=skin_type,
        concerns=tuple(concerns),
        concern=concern,
        cart_ids=_ids(payload, "cart_ids", config.MAX_CART_IDS),
        exclude_ids=_ids(payload, "exclude_ids", config.MAX_EXCLUDE_IDS),
        limits=_limits(payload),
    )
