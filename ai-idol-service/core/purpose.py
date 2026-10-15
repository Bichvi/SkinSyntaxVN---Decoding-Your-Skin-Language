"""Broadcast mode chosen once per campaign: what Syna may say and where the video goes."""
from __future__ import annotations


PRODUCT_VIDEO = "product_video"
LIVE_AND_PRODUCT = "live_and_product"
LIVE_ONLY = "live_only"
PURPOSES = frozenset({PRODUCT_VIDEO, LIVE_AND_PRODUCT, LIVE_ONLY})
DEFAULT_PURPOSE = PRODUCT_VIDEO
# Campaigns and jobs created before purposes existed were livestreams that spoke prices.
LEGACY_PURPOSE = LIVE_ONLY
PRODUCT_VIDEO_LENGTH_MODE = "Long (90-150s)"


def normalize_purpose(value) -> str:
    """Admin input: anything unknown or empty becomes the default product video."""
    purpose = str(value or "").strip().lower()
    return purpose if purpose in PURPOSES else DEFAULT_PURPOSE


def purpose_of(doc: dict | None) -> str:
    """Stored documents: a missing or unknown purpose is the legacy livestream."""
    purpose = str((doc or {}).get("purpose") or "").strip().lower()
    return purpose if purpose in PURPOSES else LEGACY_PURPOSE


def has_broadcast(purpose: str) -> bool:
    return purpose in (LIVE_AND_PRODUCT, LIVE_ONLY)


def publishes_to_product_page(purpose: str) -> bool:
    return purpose in (PRODUCT_VIDEO, LIVE_AND_PRODUCT)
