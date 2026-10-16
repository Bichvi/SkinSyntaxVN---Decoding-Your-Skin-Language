"""Read model for AI Idol videos shown on storefront product pages.

A video reaches a product page only after media publishing stamped
``product_page_ready_at``. Legacy documents carry no ``purpose`` and always
spoke prices, so they are never listed here.
"""
from __future__ import annotations

import logging

from core.pricing import current_pricing, price_claim_state
from core.state_machine import get_db, object_id, serialize, utcnow

logger = logging.getLogger(__name__)

PRODUCT_PAGE_PURPOSES = ("product_video", "live_and_product")
# Campaigns created before purposes existed were plain livestreams.
LIVE_PURPOSES = ("live_only", "live_and_product")
MAX_VIDEOS = 30
CAMPAIGN_PEERS = 3


def _campaign_peers(campaign_id, product_id: str, cache: dict) -> list[str]:
    if not campaign_id:
        return []
    key = str(campaign_id)
    if key not in cache:
        try:
            campaign = get_db().ai_idol_campaigns.find_one({"_id": object_id(key)}, {"product_ids": 1})
        except ValueError:
            campaign = None
        cache[key] = [str(item) for item in (campaign or {}).get("product_ids") or []]
    return [item for item in cache[key] if item != product_id][:CAMPAIGN_PEERS]


def _video_card(doc: dict, cache: dict) -> dict:
    product_id = str(doc.get("product_id") or "")
    media = doc.get("media") or {}
    aired_at = doc.get("aired_at")
    return serialize({
        "video_id": doc["_id"],
        "video_url": media.get("video_url"),
        "poster_url": media.get("poster_url"),
        # The published clip may be shorter than the render (livestream sale section cut off).
        "duration": media.get("duration") or doc.get("duration"),
        "format": doc.get("format") or "16:9",
        "date": aired_at or doc.get("created_at"),
        "date_kind": "aired" if aired_at else "created",
        "campaign_name": doc.get("campaign_name") or "",
        "product_ids": [product_id] + _campaign_peers(doc.get("campaign_id"), product_id, cache),
    })


def _live_campaign(product_id: str) -> dict | None:
    # LIVE campaigns are few at any moment; membership is checked here so the
    # query stays a plain status lookup.
    for campaign in get_db().ai_idol_campaigns.find({"status": "LIVE"}).limit(50):
        if (campaign.get("purpose") or "live_only") not in LIVE_PURPOSES:
            continue
        if product_id in [str(item) for item in campaign.get("product_ids") or []]:
            return {"campaign_id": str(campaign["_id"]), "name": campaign.get("name") or ""}
    return None


def list_product_videos(product_id: str, limit: int = MAX_VIDEOS) -> dict:
    product_id = str(product_id).strip()
    limit = max(1, min(int(MAX_VIDEOS if limit is None else limit), MAX_VIDEOS))
    docs = get_db().ai_idol_videos.find({
        "product_id": product_id,
        # Older videos reach product pages only through the one-time backfill (spec D13).
        "$or": [{"purpose": {"$in": list(PRODUCT_PAGE_PURPOSES)}}, {"product_page_backfill": True}],
        "product_page_ready_at": {"$exists": True},
        "hidden_from_product_page": {"$ne": True},
    }).sort("product_page_ready_at", -1).limit(limit)
    cache: dict = {}
    return {"live": _live_campaign(product_id), "videos": [_video_card(doc, cache) for doc in docs]}


def set_video_visibility(video_id: str, hidden: bool, by: str) -> dict:
    db = get_db()
    query = {"_id": object_id(video_id)}
    if not db.ai_idol_videos.find_one(query, {"_id": 1}):
        raise LookupError("Video not found")
    db.ai_idol_videos.update_one(query, {"$set": {
        "hidden_from_product_page": bool(hidden),
        "hidden_at": utcnow() if hidden else None,
        "hidden_by": str(by or "")[:120] or None,
    }})
    return product_page_state(db.ai_idol_videos.find_one(query))


def _price_state(video: dict) -> str | None:
    """Why the price spoken in the video no longer holds, or None (fine, no claim, or lookup failed)."""
    claim = video.get("price_claim")
    if not claim:
        return None
    try:
        now = utcnow()
        current = current_pricing(str(claim.get("product_id")), now)
        return price_claim_state(claim, current, int(now.timestamp()))
    except Exception as exc:
        logger.warning("Price check failed for video %s: %s", video.get("_id"), type(exc).__name__)
        return None


def product_page_state(video: dict) -> dict:
    eligible = bool(video.get("product_page_backfill")) or video.get("purpose") in PRODUCT_PAGE_PURPOSES
    return {
        "video_id": str(video["_id"]),
        "ready": bool(video.get("product_page_ready_at")),
        "hidden": bool(video.get("hidden_from_product_page")),
        # False for livestream-only and pre-purpose videos: they never reach product pages.
        "eligible": eligible,
        "block_reason": video.get("product_page_block_reason"),
        "last_error": (video.get("media") or {}).get("last_error"),
        "price_state": _price_state(video),
    }
