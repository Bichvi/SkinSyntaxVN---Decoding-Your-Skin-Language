"""Publish product-page clips to Cloudflare R2 and stamp them ready for the storefront.

Run once to bring older videos onto product pages (spec D13):
    python -m engines.media_publisher backfill            # dry run
    python -m engines.media_publisher backfill --apply
"""
from __future__ import annotations

import argparse
import os
import secrets
import subprocess

from config import logger
from core.purpose import LIVE_AND_PRODUCT, PRODUCT_VIDEO, publishes_to_product_page, purpose_of
from core.redact import redact_secrets
from core.state_machine import get_db, object_id, utcnow
from engines.program_composer import probe_duration


MAX_ATTEMPTS = 5
CACHE_CONTROL = "public, max-age=31536000, immutable"
KEY_PREFIX = "product-videos"
_warned_unconfigured = False


def _r2() -> dict:
    keys = ("R2_ACCOUNT_ID", "R2_ACCESS_KEY_ID", "R2_SECRET_ACCESS_KEY", "R2_BUCKET", "R2_PUBLIC_BASE_URL")
    return {key: os.getenv(key, "").strip() for key in keys}


def r2_configured() -> bool:
    return all(_r2().values())


def _default_client():
    import boto3  # lazy: only media workers that publish need it
    settings = _r2()
    return boto3.client(
        "s3",
        endpoint_url=f"https://{settings['R2_ACCOUNT_ID']}.r2.cloudflarestorage.com",
        aws_access_key_id=settings["R2_ACCESS_KEY_ID"],
        aws_secret_access_key=settings["R2_SECRET_ACCESS_KEY"],
        region_name="auto",
    )


def _scrub(error: object) -> str:
    text = redact_secrets(error)
    for secret in (_r2()["R2_ACCESS_KEY_ID"], _r2()["R2_SECRET_ACCESS_KEY"]):
        if secret:
            text = text.replace(secret, "[redacted]")
    return text[:1000]


def make_poster(video_path: str, out_path: str, duration: float) -> str:
    """One frame early in the clip (Syna is already holding the product), long edge 640px."""
    at = max(0.0, min(1.5, float(duration or 0) / 3))
    command = [
        "ffmpeg", "-y", "-ss", f"{at:.2f}", "-i", video_path, "-frames:v", "1",
        "-vf", "scale='if(gt(iw,ih),640,-2)':'if(gt(iw,ih),-2,640)'", "-q:v", "4", out_path,
    ]
    result = subprocess.run(command, capture_output=True, text=True, timeout=120)
    if result.returncode != 0 or not os.path.isfile(out_path):
        raise RuntimeError(result.stderr[-800:])
    return out_path


def _remove(path) -> None:
    if path and os.path.isfile(str(path)):
        try:
            os.remove(str(path))
        except OSError as exc:
            logger.warning("[MEDIA-PUBLISH] Could not remove %s: %s", path, exc)


def _campaign(doc: dict) -> dict:
    campaign_id = doc.get("campaign_id")
    if not campaign_id:
        return {}
    try:
        return get_db().ai_idol_campaigns.find_one({"_id": object_id(str(campaign_id))}) or {}
    except ValueError:
        return {}


def publish_video(video_id: str, *, client=None) -> bool:
    """Upload the clip and its poster, then stamp product_page_ready_at. Idempotent."""
    global _warned_unconfigured
    db = get_db()
    query = {"_id": object_id(video_id)}
    doc = db.ai_idol_videos.find_one(query)
    if not doc:
        return False
    if doc.get("product_page_ready_at"):
        return True
    if not r2_configured():
        if not _warned_unconfigured:
            logger.warning("[MEDIA-PUBLISH] R2 is not configured; product videos stay local and off product pages")
            _warned_unconfigured = True
        # Visible in Studio, but not an attempt: configuring R2 later lets the retry beat publish it.
        db.ai_idol_videos.update_one(query, {"$set": {
            "media.last_error": "Chưa cấu hình Cloudflare R2 nên video chưa lên trang sản phẩm.",
            "updated_at": utcnow(),
        }})
        return False
    source = doc.get("product_page_file_path")
    poster = None
    try:
        if not source or not os.path.isfile(source):
            raise FileNotFoundError("Không còn file video để đăng lên trang sản phẩm")
        clip_seconds = probe_duration(source)
        poster = make_poster(source, os.path.splitext(source)[0] + ".poster.jpg", clip_seconds)
        settings = _r2()
        key = f"{KEY_PREFIX}/{video_id}-{secrets.token_hex(6)}"
        client = client or _default_client()
        client.upload_file(source, settings["R2_BUCKET"], key + ".mp4",
                           ExtraArgs={"ContentType": "video/mp4", "CacheControl": CACHE_CONTROL})
        client.upload_file(poster, settings["R2_BUCKET"], key + ".jpg",
                           ExtraArgs={"ContentType": "image/jpeg", "CacheControl": CACHE_CONTROL})
    except Exception as exc:
        _remove(poster)
        db.ai_idol_videos.update_one(query, {"$inc": {"media.attempts": 1}, "$set": {
            "media.last_error": _scrub(exc), "updated_at": utcnow(),
        }})
        logger.warning("[MEDIA-PUBLISH] Video %s not published: %s", video_id, _scrub(exc))
        return False

    base = settings["R2_PUBLIC_BASE_URL"].rstrip("/")
    campaign = _campaign(doc)
    aired = campaign.get("completed_at") if campaign.get("status") in ("COMPLETED", "PARTIAL_FAILURE") else None
    now = utcnow()
    db.ai_idol_videos.update_one(query, {"$set": {
        "media.video_url": f"{base}/{key}.mp4",
        "media.poster_url": f"{base}/{key}.jpg",
        "media.keys": [key + ".mp4", key + ".jpg"],
        "media.duration": clip_seconds,
        "media.uploaded_at": now,
        "media.last_error": None,
        "aired_at": aired,
        "campaign_name": campaign.get("name") or doc.get("campaign_name") or "",
        "product_page_ready_at": now,
        "updated_at": now,
    }})
    cleanup_local_files(video_id)
    return True


def cleanup_local_files(video_id: str) -> None:
    """Drop local copies that now live on R2. Livestream full clips wait for publish_campaign_videos."""
    doc = get_db().ai_idol_videos.find_one({"_id": object_id(video_id)})
    if not doc or not doc.get("product_page_ready_at"):
        return
    page_file = doc.get("product_page_file_path")
    if page_file:
        _remove(os.path.splitext(page_file)[0] + ".poster.jpg")
    if doc.get("product_page_backfill"):
        return  # older videos may still be needed by their own campaign
    purpose = purpose_of(doc)
    if purpose == PRODUCT_VIDEO or (purpose == LIVE_AND_PRODUCT and page_file != doc.get("file_path")):
        _remove(page_file)


def campaign_publishable(campaign: dict, broadcasts: list[dict]) -> bool:
    status = campaign.get("status")
    if status == "COMPLETED":
        return True
    return status == "PARTIAL_FAILURE" and any(item.get("status") == "COMPLETED" for item in broadcasts)


def publish_campaign_videos(campaign_id: str, *, client=None) -> int:
    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
    if not campaign or not publishes_to_product_page(purpose_of(campaign)):
        return 0
    if not campaign_publishable(campaign, list(db.ai_idol_broadcasts.find({"campaign_id": campaign_id}))):
        return 0
    videos = list(db.ai_idol_videos.find({"campaign_id": campaign_id, "product_page_file_path": {"$ne": None}}))
    published = sum(1 for video in videos if publish_video(str(video["_id"]), client=client))
    if videos and published == len(videos):
        # Every product-page clip is on R2 and the show is over: the long-form media can go.
        for video in videos:
            if video.get("file_path") != video.get("product_page_file_path"):
                _remove(video.get("file_path"))
        _remove(campaign.get("master_video_path"))
    return published


def _retry_eligible(doc: dict) -> bool:
    if doc.get("product_page_backfill") or purpose_of(doc) == PRODUCT_VIDEO:
        return True
    campaign = _campaign(doc)
    broadcasts = list(get_db().ai_idol_broadcasts.find({"campaign_id": str(doc.get("campaign_id"))}))
    return bool(campaign) and campaign_publishable(campaign, broadcasts)


def retry_pending(limit: int = 20, *, client=None) -> int:
    if not r2_configured():
        return 0
    candidates = get_db().ai_idol_videos.find({
        "product_page_ready_at": {"$exists": False},
        "product_page_file_path": {"$ne": None},
    }).limit(200)
    published = 0
    for doc in candidates:
        if published >= limit:
            break
        if int((doc.get("media") or {}).get("attempts") or 0) >= MAX_ATTEMPTS or not _retry_eligible(doc):
            continue
        published += int(publish_video(str(doc["_id"]), client=client))
    return published


def backfill(apply: bool, *, client=None) -> list[dict]:
    """One-time (spec D13): every older video whose file still exists goes onto its product page."""
    db = get_db()
    rows = []
    # Only videos rendered before purposes existed: anything newer already follows its purpose
    # (live_only never, live_and_product after the show, blocked clips never).
    for doc in db.ai_idol_videos.find({"product_page_ready_at": {"$exists": False}, "purpose": {"$exists": False}}):
        path = doc.get("file_path")
        if not path or not os.path.isfile(path):
            continue
        row = {"video_id": str(doc["_id"]), "product_id": str(doc.get("product_id") or ""), "file_path": path,
               "published": False}
        if apply:
            db.ai_idol_videos.update_one({"_id": doc["_id"]}, {"$set": {
                "product_page_backfill": True,
                "product_page_file_path": doc.get("product_page_file_path") or path,
                "updated_at": utcnow(),
            }})
            row["published"] = publish_video(row["video_id"], client=client)
        rows.append(row)
    return rows


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description="Publish AI Idol videos to product pages")
    sub = parser.add_subparsers(dest="command", required=True)
    run = sub.add_parser("backfill", help="one-time: put every older video on its product page")
    run.add_argument("--apply", action="store_true", help="write and upload (default: dry run)")
    args = parser.parse_args(argv)
    if args.apply and not r2_configured():
        print("R2 chưa cấu hình (R2_ACCOUNT_ID, R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET, R2_PUBLIC_BASE_URL).")
        return 2
    rows = backfill(apply=args.apply)
    for row in rows:
        state = "published" if row["published"] else ("would publish" if not args.apply else "FAILED")
        print(f"{state}: video={row['video_id']} product={row['product_id']} file={row['file_path']}")
    print(f"{len(rows)} video(s){'' if args.apply else ' (dry run)'}")
    return 0 if not args.apply or all(row["published"] for row in rows) else 1


if __name__ == "__main__":
    raise SystemExit(main())
