from __future__ import annotations

import datetime as dt
from typing import Any, Iterable

from bson import ObjectId
from pymongo import ASCENDING, MongoClient, ReturnDocument

from config import MONGO_DB, MONGO_URI, logger


_client: MongoClient | None = None


def utcnow() -> dt.datetime:
    return dt.datetime.now(dt.timezone.utc)


def get_db():
    global _client
    if _client is None:
        _client = MongoClient(MONGO_URI, tz_aware=True, connectTimeoutMS=5000)
    return _client[MONGO_DB]


def ensure_indexes() -> None:
    db = get_db()
    db.ai_idol_jobs.create_index([("status", ASCENDING), ("updated_at", ASCENDING)])
    db.ai_idol_jobs.create_index("campaign_id")
    db.ai_idol_campaigns.create_index([("status", ASCENDING), ("scheduled_at", ASCENDING)])
    db.ai_idol_campaigns.create_index("idempotency_key", unique=True, sparse=True)
    db.ai_idol_segments.create_index([("campaign_id", ASCENDING), ("position", ASCENDING)], unique=True)
    db.ai_idol_broadcasts.create_index([("campaign_id", ASCENDING), ("platform", ASCENDING)], unique=True)
    db.ai_idol_agent_runs.create_index([("status", ASCENDING), ("updated_at", ASCENDING)])
    db.ai_idol_agent_runs.create_index("campaign_id", sparse=True)
    db.ai_idol_agent_runs.create_index("idempotency_key", unique=True, sparse=True)


def object_id(value: str) -> ObjectId:
    if not ObjectId.is_valid(value):
        raise ValueError("Invalid identifier")
    return ObjectId(value)


def serialize(value: Any) -> Any:
    if isinstance(value, ObjectId):
        return str(value)
    if isinstance(value, dt.datetime):
        return value.isoformat()
    if isinstance(value, dict):
        return {key: serialize(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [serialize(item) for item in value]
    return value


def create_job(product_id: str, content_mode: str, configuration: dict, *, campaign_id=None,
               segment_id=None, product_snapshot=None) -> str:
    now = utcnow()
    job = {
        "product_id": str(product_id), "campaign_id": campaign_id, "segment_id": segment_id,
        "content_mode": content_mode, "configuration": configuration or {}, "status": "CREATED",
        "current_stage": "CREATED", "retry_count": 0, "error_message": None,
        "failed_stage": None, "product_snapshot": product_snapshot, "artifacts": {},
        "created_at": now, "updated_at": now,
    }
    job_id = str(get_db().ai_idol_jobs.insert_one(job).inserted_id)
    logger.info("[STATE] Created job %s for product %s", job_id, product_id)
    return job_id


def update_job_stage(job_id: str, stage: str, status: str = "IN_PROGRESS") -> None:
    get_db().ai_idol_jobs.update_one({"_id": object_id(job_id)}, {"$set": {
        "current_stage": stage, "status": status, "updated_at": utcnow(),
    }})


def checkpoint_job(job_id: str, values: dict) -> None:
    payload = dict(values)
    payload["updated_at"] = utcnow()
    get_db().ai_idol_jobs.update_one({"_id": object_id(job_id)}, {"$set": payload})


def update_job_snapshot(job_id: str, snapshot: dict) -> None:
    checkpoint_job(job_id, {"product_snapshot": snapshot})


def fail_job(job_id: str, stage: str, error_msg: str) -> None:
    get_db().ai_idol_jobs.update_one({"_id": object_id(job_id)}, {"$set": {
        "status": "FAILED", "current_stage": stage, "failed_stage": stage,
        "error_message": str(error_msg)[:4000], "updated_at": utcnow(),
    }})
    logger.error("[STATE] Job %s failed at %s: %s", job_id, stage, error_msg)


def get_job(job_id: str) -> dict | None:
    doc = get_db().ai_idol_jobs.find_one({"_id": object_id(job_id)})
    return serialize(doc) if doc else None


def claim_transition(collection: str, item_id: str, expected_statuses: Iterable[str],
                     new_status: str, extra: dict | None = None) -> dict | None:
    values = {"status": new_status, "updated_at": utcnow(), **(extra or {})}
    doc = get_db()[collection].find_one_and_update(
        {"_id": object_id(item_id), "status": {"$in": list(expected_statuses)}},
        {"$set": values}, return_document=ReturnDocument.AFTER,
    )
    return serialize(doc) if doc else None
