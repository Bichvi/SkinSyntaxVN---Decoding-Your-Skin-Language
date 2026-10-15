from __future__ import annotations

import datetime as dt
import os
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor, as_completed

from config import BROADCAST_MAX_RETRIES, BROADCAST_RETRY_SECONDS, RTMP_TARGETS, logger
from core.purpose import publishes_to_product_page, purpose_of
from core.redact import redact_secrets
from core.state_machine import get_db, object_id, utcnow
from engines.program_composer import probe_duration

LOCAL_PLATFORMS = {"internal", "preview"}
# A mixed internal+RTMP campaign is closed by its own worker; only if that worker
# died without reporting do we close it, this long after it should have ended.
ORPHAN_LIVE_GRACE = dt.timedelta(minutes=15)


def _redact(value) -> str:
    """Strip RTMP targets/keys from anything about to be stored or shown."""
    return redact_secrets(value)


def configured_platforms() -> list[str]:
    return sorted(RTMP_TARGETS)


def preflight_campaign(campaign_id: str) -> dict:
    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
    if not campaign:
        raise ValueError("Campaign not found")
    path = str(campaign.get("master_video_path") or "")
    errors = []
    if not path or not os.path.isfile(path):
        errors.append("Master video is missing")
    else:
        try:
            probe_duration(path)
        except Exception as exc:
            errors.append(_redact(f"Invalid master video: {exc}"))
    for platform in campaign.get("platforms", []):
        if platform not in {"internal", "preview"} and platform not in RTMP_TARGETS:
            errors.append(f"Missing RTMP configuration for {platform}")
    result = {"ok": not errors, "errors": errors, "checked_at": utcnow()}
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "preflight": result, "updated_at": utcnow(),
    }})
    return result


def _upsert_broadcast(campaign_id: str, platform: str, status: str, **extra) -> None:
    for key in ("error_message", "note"):
        if extra.get(key):
            extra[key] = _redact(extra[key])
    get_db().ai_idol_broadcasts.update_one(
        {"campaign_id": campaign_id, "platform": platform},
        {"$set": {"status": status, "updated_at": utcnow(), **extra},
         "$setOnInsert": {"created_at": utcnow()}},
        upsert=True,
    )


def _stream_target(campaign_id: str, platform: str, media_path: str,
                   duration_seconds: int) -> tuple[str, bool, str]:
    if platform in {"internal", "preview"}:
        # Internal viewing is a real, timed broadcast: LIVE until ends_at, then closed.
        started = utcnow()
        _upsert_broadcast(
            campaign_id, platform, "LIVE", started_at=started,
            ends_at=started + dt.timedelta(seconds=duration_seconds),
            note="Phát nội bộ: xem trực tiếp trong Studio",
        )
        return platform, True, ""
    target = RTMP_TARGETS.get(platform)
    if not target:
        message = f"RTMP target is not configured for {platform}"
        _upsert_broadcast(campaign_id, platform, "FAILED", error_message=message)
        return platform, False, message

    last_error = ""
    for attempt in range(1, BROADCAST_MAX_RETRIES + 1):
        _upsert_broadcast(campaign_id, platform, "CONNECTING", attempt=attempt)
        command = [
            "ffmpeg", "-hide_banner", "-loglevel", "warning", "-re", "-stream_loop", "-1",
            "-i", media_path, "-t", str(duration_seconds),
            "-c:v", "copy", "-c:a", "copy", "-f", "flv", "-flvflags", "no_duration_filesize", target,
        ]
        # Never log the command: it contains the stream key.
        _upsert_broadcast(campaign_id, platform, "STREAMING", attempt=attempt, started_at=utcnow())
        process = subprocess.run(command, capture_output=True, text=True)
        if process.returncode == 0:
            _upsert_broadcast(campaign_id, platform, "COMPLETED", completed_at=utcnow(), error_message=None)
            return platform, True, ""
        last_error = _redact((process.stderr or "Unknown FFmpeg error")[-2000:])
        _upsert_broadcast(campaign_id, platform, "RECOVERING", error_message=last_error)
        if attempt < BROADCAST_MAX_RETRIES:
            time.sleep(BROADCAST_RETRY_SECONDS * attempt)
    _upsert_broadcast(campaign_id, platform, "FAILED", error_message=last_error)
    return platform, False, last_error


def _send_publish_task(campaign_id: str) -> None:
    # By task name: importing tasks here would be circular.
    from celery_app import celery_app
    celery_app.send_task("ai_idol.publish_campaign_videos", args=[campaign_id])


def _queue_product_publish(campaign: dict) -> None:
    """After a show ends, its evergreen clips go to product pages (spec §4.4)."""
    if not publishes_to_product_page(purpose_of(campaign)):
        return
    try:
        _send_publish_task(str(campaign["_id"]))
    except Exception as exc:  # the beat retry picks it up later
        logger.warning("[BROADCAST] Could not queue product-page publish for %s: %s", campaign.get("_id"), exc)


def broadcast_campaign(campaign_id: str) -> bool:
    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
    if not campaign:
        raise ValueError("Campaign not found")
    check = preflight_campaign(campaign_id)
    if not check["ok"]:
        db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
            "status": "NEEDS_ATTENTION", "error_message": _redact("; ".join(check["errors"])), "updated_at": utcnow(),
        }})
        return False

    media_path = str(campaign["master_video_path"])
    platforms = campaign.get("platforms") or ["internal"]
    duration_seconds = max(60, min(int(campaign.get("duration_minutes", 60)) * 60, 43200))
    started = utcnow()
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "status": "LIVE", "started_at": started, "updated_at": started,
        "ends_at": started + dt.timedelta(seconds=duration_seconds),
    }})
    results = []
    with ThreadPoolExecutor(max_workers=len(platforms)) as executor:
        futures = [
            executor.submit(_stream_target, campaign_id, platform, media_path, duration_seconds)
            for platform in platforms
        ]
        for future in as_completed(futures):
            results.append(future.result())
    ok = all(item[1] for item in results)
    if all(platform in LOCAL_PLATFORMS for platform in platforms):
        # Internal-only: stay LIVE until ends_at; finalize_live_campaigns closes it.
        return ok
    db.ai_idol_broadcasts.update_many(
        {"campaign_id": campaign_id, "platform": {"$in": sorted(LOCAL_PLATFORMS)}, "status": "LIVE"},
        {"$set": {"status": "COMPLETED", "completed_at": utcnow(), "updated_at": utcnow()}},
    )
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "status": "COMPLETED" if ok else "PARTIAL_FAILURE",
        "completed_at": utcnow(), "updated_at": utcnow(),
        "error_message": None if ok else "One or more platforms failed",
    }})
    _queue_product_publish(campaign)
    return ok


def finalize_live_campaigns(now: dt.datetime | None = None) -> dict[str, int]:
    """Close campaigns whose broadcast time is over (conditional on status LIVE)."""
    db, now = get_db(), now or utcnow()
    closed = {"completed": 0, "partial_failure": 0}
    for campaign in db.ai_idol_campaigns.find({"status": "LIVE", "ends_at": {"$lte": now}}).limit(50):
        platforms = campaign.get("platforms") or ["internal"]
        internal_only = all(platform in LOCAL_PLATFORMS for platform in platforms)
        if not internal_only and campaign["ends_at"] + ORPHAN_LIVE_GRACE > now:
            continue
        failed = db.ai_idol_broadcasts.count_documents(
            {"campaign_id": str(campaign["_id"]), "status": "FAILED"}
        )
        orphan = not internal_only
        status = "PARTIAL_FAILURE" if (failed or orphan) else "COMPLETED"
        claimed = db.ai_idol_campaigns.update_one(
            {"_id": campaign["_id"], "status": "LIVE"},
            {"$set": {
                "status": status, "completed_at": now, "updated_at": now,
                "error_message": (
                    "Worker phát sóng không báo kết thúc; hãy kiểm tra kênh phát."
                    if orphan else "Một hoặc nhiều nơi phát bị lỗi." if failed else None
                ),
            }},
        )
        if claimed.modified_count:
            db.ai_idol_broadcasts.update_many(
                {"campaign_id": str(campaign["_id"]), "status": "LIVE"},
                {"$set": {"status": "COMPLETED", "completed_at": now, "updated_at": now}},
            )
            closed["partial_failure" if status == "PARTIAL_FAILURE" else "completed"] += 1
            _queue_product_publish(campaign)
    return closed
