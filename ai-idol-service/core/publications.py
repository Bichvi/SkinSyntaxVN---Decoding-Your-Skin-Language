"""Finished product-page videos put themselves on the storefront (spec D10, D12).

The product page lists ``ai_product_videos`` (the PHP ``ProductVideo`` model). When a
``product_video`` is rendered, or a ``live_and_product`` show has aired, the price-free clip
is known to exist, so this module writes the publication record the admin would have created
by hand (same schema as ``ProductVideo::publishVideo``) and clears the storefront's cached
video lists. The admin can still unpublish, re-prioritise or attach a voucher afterwards, and
a record that already exists is never touched.
"""
from __future__ import annotations

import os
import re
from typing import Any

from agents.autopilot import make_decision
from config import logger
from core.agent_runs import append_decisions
from core.purpose import publishes_to_product_page, purpose_of
from core.redact import redact_secrets
from core.state_machine import get_db, object_id, utcnow
from engines.program_composer import probe_duration


DEFAULT_TITLE = "Video tư vấn sản phẩm Syna 3D"  # what the product page shows for an untitled record
DEFAULT_AVATAR_MODE = "syna_3d"
DISPLAY_PRIORITY = 10
TITLE_LIMIT = 160
SOURCE = "auto"
CACHE_KEY_PATTERN = "product:videos:*"  # SanPhamController caches each product's list as product:videos:<id>
DEFAULT_CACHE_PREFIX = "skinsyntax:"
SOCKET_TIMEOUT_SECONDS = 2
SCAN_BATCH = 100
_GLOB_SPECIALS = re.compile(r"([\\*?\[\]])")


def _valkey_client(url: str):
    import redis  # already installed for Celery; only needed when a publication is created
    return redis.Redis.from_url(
        url, socket_connect_timeout=SOCKET_TIMEOUT_SECONDS, socket_timeout=SOCKET_TIMEOUT_SECONDS,
    )


def purge_product_video_cache() -> int:
    """Delete the storefront's cached product video lists; returns how many keys went.

    Never raises and never logs the connection string. Without ``VALKEY_URL`` (or when Valkey is
    down) the lists simply expire by themselves within 30 minutes.
    """
    url = os.getenv("VALKEY_URL", "").strip()
    if not url:
        logger.warning("[PRODUCT-PAGE] VALKEY_URL is not set; product video lists refresh within 30 minutes on their own")
        return 0
    prefix = os.getenv("CACHE_PREFIX", "").strip() or DEFAULT_CACHE_PREFIX
    pattern = _GLOB_SPECIALS.sub(r"\\\1", prefix) + CACHE_KEY_PATTERN
    client = None
    try:
        client = _valkey_client(url)
        deleted, batch = 0, []
        for key in client.scan_iter(match=pattern, count=SCAN_BATCH):
            batch.append(key)
            if len(batch) >= SCAN_BATCH:
                deleted += int(client.delete(*batch))
                batch = []
        if batch:
            deleted += int(client.delete(*batch))
        return deleted
    except Exception as exc:  # the message may carry the connection string: log the kind only
        logger.warning("[PRODUCT-PAGE] Could not clear the product video cache (%s); it refreshes within 30 minutes on its own",
                       type(exc).__name__)
        return 0
    finally:
        if client is not None:
            try:
                client.close()
            except Exception:
                pass


def _campaign(video: dict[str, Any]) -> dict[str, Any]:
    campaign_id = video.get("campaign_id")
    if not campaign_id:
        return {}
    try:
        return get_db().ai_idol_campaigns.find_one({"_id": object_id(str(campaign_id))}) or {}
    except ValueError:
        return {}


def _clip_duration(video: dict[str, Any]) -> float:
    """Length of the clip the product page plays (a livestream's price-free cut is shorter than its render)."""
    uploaded = (video.get("media") or {}).get("duration")
    if uploaded:
        return float(uploaded)
    page_file = video.get("product_page_file_path")
    if page_file and page_file != video.get("file_path") and os.path.isfile(str(page_file)):
        try:
            return float(probe_duration(str(page_file)))
        except Exception as exc:
            logger.warning("[PRODUCT-PAGE] Could not measure %s, using the render length: %s", page_file, redact_secrets(exc))
    return float(video.get("duration") or 0)


def _product_label(video: dict[str, Any], product_id: str) -> str:
    try:
        job = get_db().ai_idol_jobs.find_one({"_id": object_id(str(video.get("job_id") or ""))}, {"product_snapshot": 1})
    except ValueError:
        job = None
    name = str(((job or {}).get("product_snapshot") or {}).get("name") or "").strip()
    return f"{name} (mã {product_id})" if name and name != "UNKNOWN" else f"mã {product_id}"


def _publication(video: dict[str, Any], video_id: str, product_id: str, campaign: dict[str, Any]) -> dict[str, Any]:
    segment = get_db().ai_idol_segments.find_one({"video_id": video_id}, {"_id": 1})
    title = str(campaign.get("name") or video.get("campaign_name") or "").strip()[:TITLE_LIMIT]
    now = utcnow()
    # Field for field what ProductVideo::publishVideo inserts, plus where the record came from.
    return {
        "video_id": video_id,
        "job_id": str(video.get("job_id") or ""),
        "campaign_id": str(video.get("campaign_id") or ""),
        "segment_id": str(segment["_id"]) if segment else "",
        "title": title or DEFAULT_TITLE,
        "duration": _clip_duration(video),
        "format": str(video.get("format") or "16:9"),
        "resolution": str(video.get("resolution") or "1280x720"),
        "avatar_mode": str((campaign.get("configuration") or {}).get("avatar_mode") or DEFAULT_AVATAR_MODE),
        "associated_product_ids": [product_id],
        "primary_product_id": product_id,
        "display_priority": DISPLAY_PRIORITY,
        "is_published": True,
        "source": SOURCE,
        "created_at": now,
        "updated_at": now,
        "published_at": now,
    }


_index_ready = False


def _ensure_publish_key_index(db) -> None:
    """Create the unique publish_key index once per process; tolerate it existing or the call failing."""
    global _index_ready
    if _index_ready:
        return
    try:
        db.ai_product_videos.create_index(
            "publish_key", unique=True, name="uniq_publish_key",
            partialFilterExpression={"publish_key": {"$type": "string"}},  # legacy/admin records have no key
        )
    except Exception as exc:
        logger.warning("[PRODUCT-PAGE] publish_key index not ensured: %s", redact_secrets(exc))
    _index_ready = True


def _script_version(video: dict[str, Any]) -> int:
    try:
        job = get_db().ai_idol_jobs.find_one({"_id": object_id(str(video.get("job_id") or ""))}, {"artifacts": 1, "script_version": 1})
    except ValueError:
        job = None
    job = job or {}
    raw = (job.get("artifacts") or {}).get("script_version") or job.get("script_version")
    try:
        return max(int(raw), 1)
    except (TypeError, ValueError):
        return 1


def _publish(video_id: str) -> dict[str, Any] | None:
    try:
        video_oid = object_id(video_id)
    except ValueError:
        return None
    db = get_db()
    video = db.ai_idol_videos.find_one({"_id": video_oid})
    if not video or not publishes_to_product_page(purpose_of(video)) or video.get("hidden_from_product_page"):
        return None  # live_only and pre-purpose videos spoke prices; hidden ones were taken down on purpose
    if not (video.get("product_page_file_path") or (video.get("media") or {}).get("video_url")):
        return None  # no price-free clip (yet)
    product_id = str(video.get("product_id") or "").strip()
    if not product_id:
        return None
    video_id = str(video_oid)  # the key ProductVideo reads back
    publish_key = f"{video.get('job_id') or ''}:{product_id}:{_script_version(video)}"
    for existing in db.ai_product_videos.find({"video_id": video_id}, {"publish_key": 1}):
        if existing.get("publish_key") in (None, publish_key):
            return None  # published once already, perhaps edited or unpublished since: not ours to change

    _ensure_publish_key_index(db)
    campaign = _campaign(video)
    record = {**_publication(video, video_id, product_id, campaign), "publish_key": publish_key}
    # $setOnInsert: if another worker created the record meanwhile, nothing is overwritten.
    result = db.ai_product_videos.update_one({"video_id": video_id, "publish_key": {"$in": [publish_key, None]}}, {"$setOnInsert": record}, upsert=True)
    if result.upserted_id is None:
        return None
    purge_product_video_cache()
    run_id = (campaign.get("configuration") or {}).get("agent_run_id")
    if run_id:
        append_decisions(run_id, [make_decision(
            "PRODUCT_PAGE_PUBLISHED", "done",
            f"Đã đăng video lên trang sản phẩm {_product_label(video, product_id)}.",
        )])
    return {**record, "publication_id": str(result.upserted_id)}


def auto_publish_video(video_id: str) -> dict[str, Any] | None:
    """Publish a finished product-page video to its product page; the new record, or None when nothing was done.

    Best effort: any failure is logged as a warning, so a render or media-publish task never fails because of it.
    """
    try:
        return _publish(str(video_id or ""))
    except Exception as exc:
        logger.warning("[PRODUCT-PAGE] Video %s was not published to its product page: %s", video_id, redact_secrets(exc))
        return None
