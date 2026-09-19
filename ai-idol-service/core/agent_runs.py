from __future__ import annotations

import hashlib
from typing import Any

from core.state_machine import get_db, object_id, serialize, utcnow


TERMINAL_AGENT_STATUSES = {"COMPLETED", "CANCELLED"}


def _serialize_with_campaign(document: dict[str, Any], db) -> dict[str, Any]:
    result = serialize(document)
    campaign_id = document.get("campaign_id")
    if not campaign_id:
        return result
    try:
        campaign = db.ai_idol_campaigns.find_one(
            {"_id": object_id(str(campaign_id))},
            {"status": 1, "progress": 1, "error_message": 1, "master_video_path": 1},
        )
    except ValueError:
        campaign = None
    if campaign:
        result["campaign_status"] = campaign.get("status")
        result["campaign_progress"] = serialize(campaign.get("progress") or {})
        result["campaign_error_message"] = campaign.get("error_message")
        result["master_video_ready"] = bool(campaign.get("master_video_path"))
    return result


def create_agent_run(payload: dict[str, Any], created_by: str | None = None) -> tuple[str, bool]:
    brief = str(payload.get("brief") or "").strip()
    if len(brief) < 12:
        raise ValueError("Mô tả cần ít nhất 12 ký tự để Agent hiểu yêu cầu")
    if len(brief) > 4000:
        raise ValueError("Mô tả không được vượt quá 4.000 ký tự")

    idempotency_key = str(payload.get("idempotency_key") or "").strip()
    if not idempotency_key:
        source = f"{created_by or 'admin'}|{brief}|{payload.get('autonomy_policy', 'auto_preview')}"
        idempotency_key = hashlib.sha256(source.encode("utf-8")).hexdigest()

    db = get_db()
    existing = db.ai_idol_agent_runs.find_one({"idempotency_key": idempotency_key})
    if existing:
        return str(existing["_id"]), False

    now = utcnow()
    document = {
        "brief": brief,
        "overrides": {
            "autonomy_policy": payload.get("autonomy_policy", "auto_preview"),
            "scheduled_at": payload.get("scheduled_at"),
            "duration_minutes": payload.get("duration_minutes"),
            "platforms": payload.get("platforms"),
            "content_mode": payload.get("content_mode"),
            "explicit_product_ids": payload.get("product_ids"),
        },
        "status": "RECEIVED",
        "current_stage": "RECEIVED",
        "intent_classification": None,
        "normalized_brief": None,
        "plan": None,
        "campaign_id": None,
        "error_message": None,
        "idempotency_key": idempotency_key,
        "created_by": created_by,
        "retry_count": 0,
        "created_at": now,
        "updated_at": now,
    }
    run_id = str(db.ai_idol_agent_runs.insert_one(document).inserted_id)
    return run_id, True


def get_agent_run(run_id: str) -> dict[str, Any] | None:
    db = get_db()
    document = db.ai_idol_agent_runs.find_one({"_id": object_id(run_id)})
    return _serialize_with_campaign(document, db) if document else None


def list_agent_runs(limit: int = 20) -> list[dict[str, Any]]:
    db = get_db()
    cursor = db.ai_idol_agent_runs.find().sort("created_at", -1).limit(max(1, min(limit, 100)))
    return [_serialize_with_campaign(document, db) for document in cursor]


def update_agent_run(run_id: str, values: dict[str, Any]) -> None:
    payload = dict(values)
    payload["updated_at"] = utcnow()
    get_db().ai_idol_agent_runs.update_one({"_id": object_id(run_id)}, {"$set": payload})


def mark_agent_failed(run_id: str, stage: str, error: Exception | str) -> None:
    update_agent_run(run_id, {
        "status": "NEEDS_ATTENTION",
        "current_stage": stage,
        "error_message": str(error)[:2000],
    })
