from __future__ import annotations

import datetime as dt
import hashlib
from zoneinfo import ZoneInfo

from pymongo import ReturnDocument

from config import (
    CAMPAIGN_MAX_PRODUCTS,
    ESTIMATED_MINUTES_PER_PRODUCT,
    MIN_LEAD_MINUTES,
    TIMEZONE,
)
from core.state_machine import create_job, get_db, object_id, serialize, utcnow
from engines.product_collector import collect_product_and_snapshot


ALLOWED_PLATFORMS = {"internal", "youtube", "facebook", "preview"}


def parse_schedule(value: str) -> dt.datetime:
    if not value:
        raise ValueError("scheduled_at is required")
    parsed = dt.datetime.fromisoformat(value.replace("Z", "+00:00"))
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=ZoneInfo(TIMEZONE))
    return parsed.astimezone(dt.timezone.utc)


def normalize_product_ids(values) -> list[str]:
    if not isinstance(values, list):
        raise ValueError("product_ids must be an array")
    result, seen = [], set()
    for raw in values:
        product_id = str(raw).strip()
        if product_id and product_id not in seen:
            seen.add(product_id)
            result.append(product_id)
    if not result:
        raise ValueError("Select at least one product")
    if len(result) > CAMPAIGN_MAX_PRODUCTS:
        raise ValueError(f"A campaign supports at most {CAMPAIGN_MAX_PRODUCTS} products")
    return result


def create_campaign(payload: dict, created_by: str | None = None) -> tuple[str, bool]:
    product_ids = normalize_product_ids(payload.get("product_ids"))
    scheduled_at = parse_schedule(str(payload.get("scheduled_at", "")))
    preparation_minutes = max(MIN_LEAD_MINUTES, len(product_ids) * ESTIMATED_MINUTES_PER_PRODUCT)
    minimum_schedule = utcnow() + dt.timedelta(minutes=preparation_minutes)
    if scheduled_at < minimum_schedule:
        raise ValueError(
            f"Schedule needs at least {preparation_minutes:g} minutes to prepare "
            f"{len(product_ids)} product(s)"
        )
    platforms = [str(item).lower() for item in payload.get("platforms", ["internal"])]
    platforms = list(dict.fromkeys(item for item in platforms if item in ALLOWED_PLATFORMS))
    if not platforms:
        raise ValueError("Select at least one supported platform")
    configuration = payload.get("configuration") or {}
    if not isinstance(configuration, dict):
        raise ValueError("configuration must be an object")
    if configuration.get("avatar_mode") == "syna_3d":
        if configuration.get("format", "16:9") != "16:9":
            raise ValueError("Syna 3D hiện hỗ trợ khung ngang 16:9")
        configuration = {**configuration, "format": "16:9", "avatar_asset": ""}

    name = str(payload.get("name") or f"AI Idol {scheduled_at.date().isoformat()}").strip()[:160]
    idempotency_key = str(payload.get("idempotency_key") or "").strip()
    if not idempotency_key:
        source = f"{name}|{scheduled_at.isoformat()}|{'|'.join(product_ids)}"
        idempotency_key = hashlib.sha256(source.encode()).hexdigest()

    db = get_db()
    existing = db.ai_idol_campaigns.find_one({"idempotency_key": idempotency_key})
    if existing:
        return str(existing["_id"]), False

    now = utcnow()
    campaign = {
        "name": name,
        "product_ids": product_ids,
        "platforms": platforms,
        "scheduled_at": scheduled_at,
        "timezone": TIMEZONE,
        "duration_minutes": max(1, min(int(payload.get("duration_minutes", 60)), 720)),
        "interaction_enabled": bool(payload.get("interaction_enabled", False)),
        "content_mode": str(payload.get("content_mode", "Product Intro"))[:80],
        "configuration": configuration,
        "idempotency_key": idempotency_key,
        "status": "VALIDATING",
        "progress": {"total": len(product_ids), "ready": 0, "failed": 0},
        "master_video_path": None,
        "error_message": None,
        "created_by": created_by,
        "created_at": now,
        "updated_at": now,
    }
    campaign_id = str(db.ai_idol_campaigns.insert_one(campaign).inserted_id)
    db.ai_idol_segments.insert_many([{
        "campaign_id": campaign_id,
        "product_id": product_id,
        "position": position,
        "status": "QUEUED",
        "job_id": None,
        "error_message": None,
        "created_at": now,
        "updated_at": now,
    } for position, product_id in enumerate(product_ids)])
    return campaign_id, True


def prepare_campaign(campaign_id: str) -> list[str]:
    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
    if not campaign:
        raise ValueError("Campaign not found")

    job_ids, failures = [], 0
    for segment in db.ai_idol_segments.find({"campaign_id": campaign_id}).sort("position", 1):
        if segment.get("job_id"):
            job_ids.append(str(segment["job_id"]))
            continue
        try:
            snapshot = collect_product_and_snapshot(segment["product_id"])
            job_id = create_job(
                segment["product_id"],
                campaign.get("content_mode", "Product Intro"),
                campaign.get("configuration", {}),
                campaign_id=campaign_id,
                segment_id=str(segment["_id"]),
                product_snapshot=snapshot,
            )
            db.ai_idol_segments.update_one({"_id": segment["_id"]}, {"$set": {
                "job_id": job_id, "status": "GENERATING", "updated_at": utcnow(),
            }})
            job_ids.append(job_id)
        except Exception as exc:
            failures += 1
            db.ai_idol_segments.update_one({"_id": segment["_id"]}, {"$set": {
                "status": "FAILED", "error_message": str(exc), "updated_at": utcnow(),
            }})

    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "status": "GENERATING" if job_ids else "NEEDS_ATTENTION",
        "progress.failed": failures,
        "updated_at": utcnow(),
    }})
    return job_ids


def update_campaign_from_job(job_id: str) -> str | None:
    db = get_db()
    job = db.ai_idol_jobs.find_one({"_id": object_id(job_id)})
    if not job or not job.get("campaign_id"):
        return None
    campaign_id = str(job["campaign_id"])
    job_status = job.get("status")
    if job_status == "READY":
        segment_status = "READY"
    elif job_status == "AWAITING_APPROVAL":
        segment_status = "AWAITING_APPROVAL"
    elif job_status == "FAILED":
        segment_status = "FAILED"
    else:
        segment_status = "GENERATING"
    db.ai_idol_segments.update_one({"job_id": job_id}, {"$set": {
        "status": segment_status,
        "video_id": job.get("video_id"),
        "video_path": job.get("final_video_path"),
        "error_message": job.get("error_message"),
        "updated_at": utcnow(),
    }})

    ready = db.ai_idol_segments.count_documents({"campaign_id": campaign_id, "status": "READY"})
    failed = db.ai_idol_segments.count_documents({"campaign_id": campaign_id, "status": "FAILED"})
    generating = db.ai_idol_segments.count_documents({
        "campaign_id": campaign_id, "status": {"$in": ["QUEUED", "GENERATING"]},
    })
    awaiting_approval = db.ai_idol_segments.count_documents({
        "campaign_id": campaign_id, "status": "AWAITING_APPROVAL",
    })
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
        "progress.ready": ready, "progress.failed": failed, "updated_at": utcnow(),
    }})
    if generating == 0 and failed:
        db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
            "status": "NEEDS_ATTENTION", "updated_at": utcnow(),
        }})
    elif generating == 0 and awaiting_approval:
        db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
            "status": "AWAITING_APPROVAL", "updated_at": utcnow(),
        }})
    return campaign_id if generating == 0 and awaiting_approval == 0 and failed == 0 else None


def claim_campaign_for_assembly(campaign_id: str) -> bool:
    doc = get_db().ai_idol_campaigns.find_one_and_update(
        {"_id": object_id(campaign_id), "status": "GENERATING"},
        {"$set": {"status": "ASSEMBLING", "updated_at": utcnow()}},
        return_document=ReturnDocument.AFTER,
    )
    return bool(doc)


def get_campaign(campaign_id: str) -> dict | None:
    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
    if not campaign:
        return None
    segments = list(db.ai_idol_segments.find({"campaign_id": campaign_id}).sort("position", 1))
    for segment in segments:
        job_id = segment.get("job_id")
        if not job_id:
            continue
        try:
            job = db.ai_idol_jobs.find_one({"_id": object_id(job_id)}, {
                "status": 1, "current_stage": 1, "script_status": 1,
                "artifacts.script_text": 1,
            })
        except ValueError:
            job = None
        if job:
            segment["job_status"] = job.get("status")
            segment["current_stage"] = job.get("current_stage")
            segment["script_status"] = job.get("script_status")
            segment["script_text"] = (job.get("artifacts") or {}).get("script_text", "")
    campaign["segments"] = segments
    campaign["broadcasts"] = list(db.ai_idol_broadcasts.find({"campaign_id": campaign_id}))
    return serialize(campaign)


def list_campaigns(limit: int = 30) -> list[dict]:
    cursor = get_db().ai_idol_campaigns.find().sort("created_at", -1).limit(max(1, min(limit, 100)))
    return [serialize(doc) for doc in cursor]
