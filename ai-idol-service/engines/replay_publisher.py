"""Upload a finished livestream's master video and poster to Cloudflare R2 for the storefront /live replay.

Run once to bring older livestreams onto the storefront:
    python -m engines.replay_publisher backfill            # dry run
    python -m engines.replay_publisher backfill --apply
"""
from __future__ import annotations

import argparse
import datetime as dt
import os
import secrets

from config import logger
from core.purpose import campaign_wants_replay
from core.state_machine import get_db, object_id, utcnow
from engines.media_publisher import (
    CACHE_CONTROL, MAX_ATTEMPTS, _default_client, _r2, _remove, _scrub, make_poster, r2_configured,
    release_master_video, retry_due, transfer_config,
)
from engines.program_composer import probe_duration


KEY_PREFIX = "live-replays"
CLAIM_STALE = dt.timedelta(minutes=30)
_UNCONFIGURED = "Chưa cấu hình Cloudflare R2 nên chưa có video xem lại."
_MASTER_MISSING = "Không còn file video gốc để lưu bản xem lại."
_warned_unconfigured = False


def replay_publishable(campaign: dict) -> bool:
    if not campaign_wants_replay(campaign):
        return False
    status = campaign.get("status")
    if status == "COMPLETED":
        return True
    return status == "PARTIAL_FAILURE" and get_db().ai_idol_broadcasts.find_one({
        "campaign_id": str(campaign.get("_id")), "platform": "internal", "status": "COMPLETED",
    }) is not None


def publish_campaign_replay(campaign_id: str, *, client=None) -> bool:
    """Upload the master video and poster, then stamp replay.uploaded_at. Idempotent, never raises."""
    try:
        return _publish(campaign_id, client)
    except Exception as exc:  # a bad id or a database blip: a stale claim expires and the retry beat tries again
        logger.warning("[REPLAY-PUBLISH] Campaign %s replay not published: %s", campaign_id, _scrub(exc))
        return False


def _publish(campaign_id: str, client) -> bool:
    global _warned_unconfigured
    db = get_db()
    oid = object_id(campaign_id)
    campaign = db.ai_idol_campaigns.find_one({"_id": oid})
    if not campaign:
        return False
    if (campaign.get("replay") or {}).get("uploaded_at"):
        release_master_video(campaign_id)  # a clip finished after the upload may have left the master behind
        return True
    if not replay_publishable(campaign):
        return False
    if not r2_configured():
        if not _warned_unconfigured:
            logger.warning("[REPLAY-PUBLISH] R2 is not configured; livestream replays are not stored")
            _warned_unconfigured = True
        # Visible in Studio, but not an attempt: configuring R2 later lets the retry beat publish it.
        db.ai_idol_campaigns.update_one({"_id": oid, "replay.uploaded_at": {"$exists": False}}, {"$set": {
            "replay.last_error": _UNCONFIGURED, "updated_at": utcnow(),
        }})
        return False
    master = campaign.get("master_video_path")
    if not master or not os.path.isfile(str(master)):
        db.ai_idol_campaigns.update_one({"_id": oid, "replay.uploaded_at": {"$exists": False}}, {"$set": {
            "replay.last_error": _MASTER_MISSING, "replay.attempts": MAX_ATTEMPTS, "updated_at": utcnow(),
        }})
        return False

    now = utcnow()
    claimed = db.ai_idol_campaigns.update_one({
        "_id": oid,
        "replay.uploaded_at": {"$exists": False},
        "$or": [{"replay.started_at": {"$exists": False}}, {"replay.started_at": {"$lt": now - CLAIM_STALE}}],
    }, {"$set": {"replay.started_at": now, "updated_at": now}})
    if not claimed.modified_count:
        return False  # another worker is uploading it

    poster = None
    try:
        duration = probe_duration(master)
        poster = make_poster(master, os.path.splitext(master)[0] + ".replay-poster.jpg", duration)
        settings = _r2()
        key = f"{KEY_PREFIX}/{campaign_id}-{secrets.token_hex(6)}"
        client = client or _default_client()
        transfer = transfer_config()
        # Poster first: a poster failure then never leaves the large video orphaned in R2.
        client.upload_file(poster, settings["R2_BUCKET"], key + ".jpg",
                           ExtraArgs={"ContentType": "image/jpeg", "CacheControl": CACHE_CONTROL}, Config=transfer)
        client.upload_file(master, settings["R2_BUCKET"], key + ".mp4",
                           ExtraArgs={"ContentType": "video/mp4", "CacheControl": CACHE_CONTROL}, Config=transfer)
    except Exception as exc:
        _remove(poster)
        failed_at = utcnow()
        db.ai_idol_campaigns.update_one({"_id": oid}, {
            "$inc": {"replay.attempts": 1},
            "$set": {"replay.last_error": _scrub(exc), "replay.last_attempt_at": failed_at, "updated_at": failed_at},
            "$unset": {"replay.started_at": ""},
        })
        logger.warning("[REPLAY-PUBLISH] Campaign %s replay not published: %s", campaign_id, _scrub(exc))
        return False

    base = settings["R2_PUBLIC_BASE_URL"].rstrip("/")
    done = utcnow()
    db.ai_idol_campaigns.update_one({"_id": oid}, {
        "$set": {
            "replay.video_url": f"{base}/{key}.mp4",
            "replay.poster_url": f"{base}/{key}.jpg",
            "replay.keys": [key + ".mp4", key + ".jpg"],
            "replay.duration": duration,
            "replay.uploaded_at": done,
            "replay.last_error": None,
            "updated_at": done,
        },
        "$unset": {"replay.started_at": ""},
    })
    _remove(poster)
    release_master_video(campaign_id)
    return True


def _candidates() -> list[dict]:
    found = get_db().ai_idol_campaigns.find({
        "status": {"$in": ["COMPLETED", "PARTIAL_FAILURE"]},
        "master_video_path": {"$ne": None},
        "replay.uploaded_at": {"$exists": False},
        "$or": [{"replay.attempts": {"$exists": False}}, {"replay.attempts": {"$lt": MAX_ATTEMPTS}}],
    })
    now = utcnow()
    return [
        campaign for campaign in found
        if retry_due(int((campaign.get("replay") or {}).get("attempts") or 0),
                     (campaign.get("replay") or {}).get("last_attempt_at"), now)
        and replay_publishable(campaign)
    ]


def retry_pending_replays(limit: int = 5, *, client=None) -> int:
    if not r2_configured():
        return 0
    published = attempted = 0
    for campaign in _candidates():
        if attempted >= limit:
            break
        master = campaign.get("master_video_path")
        if not master or not os.path.isfile(str(master)):
            continue  # nothing to upload: leave the campaign untouched
        attempted += 1
        try:
            published += int(publish_campaign_replay(str(campaign["_id"]), client=client))
        except Exception as exc:  # defensive: one campaign never stops the others
            logger.warning("[REPLAY-PUBLISH] Retry of %s failed: %s", campaign.get("_id"), _scrub(exc))
    return published


def backfill(apply: bool, *, client=None) -> list[dict]:
    """One-time: every finished livestream whose master file still exists gets a storefront replay."""
    rows = []
    for campaign in _candidates():
        master = campaign.get("master_video_path")
        if not master or not os.path.isfile(str(master)):
            continue
        row = {"campaign_id": str(campaign["_id"]), "name": campaign.get("name") or "",
               "master": master, "published": False}
        if apply:
            row["published"] = publish_campaign_replay(row["campaign_id"], client=client)
        rows.append(row)
    return rows


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Publish AI Idol livestream replays to the storefront")
    sub = parser.add_subparsers(dest="command", required=True)
    run = sub.add_parser("backfill", help="one-time: store a replay for every finished livestream")
    run.add_argument("--apply", action="store_true", help="write and upload (default: dry run)")
    args = parser.parse_args(argv)
    if args.apply and not r2_configured():
        print("R2 chưa cấu hình (R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET, R2_PUBLIC_BASE_URL).")
        return 2
    rows = backfill(apply=args.apply)
    for row in rows:
        state = "published" if row["published"] else ("would publish" if not args.apply else "FAILED")
        print(f"{state}: campaign={row['campaign_id']} name={row['name']} master={row['master']}")
    print(f"{len(rows)} livestream(s){'' if args.apply else ' (dry run)'}")
    return 0 if not args.apply or all(row["published"] for row in rows) else 1


if __name__ == "__main__":
    raise SystemExit(main())
