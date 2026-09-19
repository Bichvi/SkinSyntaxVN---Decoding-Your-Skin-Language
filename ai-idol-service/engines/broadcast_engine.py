from __future__ import annotations

import os
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor, as_completed

from config import BROADCAST_MAX_RETRIES, BROADCAST_RETRY_SECONDS, RTMP_TARGETS, logger
from core.state_machine import get_db, object_id, utcnow
from engines.program_composer import probe_duration


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
            errors.append(f"Invalid master video: {exc}")
    for platform in campaign.get("platforms", []):
        if platform not in {"internal", "preview"} and platform not in RTMP_TARGETS:
            errors.append(f"Missing RTMP configuration for {platform}")
    result = {"ok": not errors, "errors": errors, "checked_at": utcnow()}
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "preflight": result, "updated_at": utcnow(),
    }})
    return result


def _upsert_broadcast(campaign_id: str, platform: str, status: str, **extra) -> None:
    get_db().ai_idol_broadcasts.update_one(
        {"campaign_id": campaign_id, "platform": platform},
        {"$set": {"status": status, "updated_at": utcnow(), **extra},
         "$setOnInsert": {"created_at": utcnow()}},
        upsert=True,
    )


def _stream_target(campaign_id: str, platform: str, media_path: str,
                   duration_seconds: int) -> tuple[str, bool, str]:
    if platform in {"internal", "preview"}:
        _upsert_broadcast(campaign_id, platform, "COMPLETED", note="Master video is available internally")
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
        last_error = (process.stderr or "Unknown FFmpeg error")[-2000:]
        _upsert_broadcast(campaign_id, platform, "RECOVERING", error_message=last_error)
        if attempt < BROADCAST_MAX_RETRIES:
            time.sleep(BROADCAST_RETRY_SECONDS * attempt)
    _upsert_broadcast(campaign_id, platform, "FAILED", error_message=last_error)
    return platform, False, last_error


def broadcast_campaign(campaign_id: str) -> bool:
    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
    if not campaign:
        raise ValueError("Campaign not found")
    check = preflight_campaign(campaign_id)
    if not check["ok"]:
        db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
            "status": "NEEDS_ATTENTION", "error_message": "; ".join(check["errors"]), "updated_at": utcnow(),
        }})
        return False

    media_path = str(campaign["master_video_path"])
    platforms = campaign.get("platforms") or ["internal"]
    duration_seconds = max(60, min(int(campaign.get("duration_minutes", 60)) * 60, 43200))
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "status": "LIVE", "started_at": utcnow(), "updated_at": utcnow(),
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
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "status": "COMPLETED" if ok else "PARTIAL_FAILURE",
        "completed_at": utcnow(), "updated_at": utcnow(),
        "error_message": None if ok else "One or more platforms failed",
    }})
    return ok
