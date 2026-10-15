from __future__ import annotations

import datetime as dt
import hashlib
from typing import Any

from agents.autopilot import make_decision
from core.product_videos import product_page_state
from core.purpose import purpose_of
from core.redact import redact_secrets
from core.state_machine import get_db, object_id, serialize, utcnow


TERMINAL_AGENT_STATUSES = {"COMPLETED", "CANCELLED"}
# Only the fields product_page_state() reads; keeps the status poll light.
_VIDEO_PAGE_PROJECTION = {"purpose": 1, "product_page_backfill": 1, "product_page_ready_at": 1,
                          "hidden_from_product_page": 1, "product_page_block_reason": 1, "media.last_error": 1}


def _redacted(value: Any) -> Any:
    """Recursively strip stream targets/keys from strings before they reach the UI."""
    if isinstance(value, str):
        return redact_secrets(value)
    if isinstance(value, dict):
        return {key: _redacted(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [_redacted(item) for item in value]
    return value


def _segment_video_pages(segments: list[dict[str, Any]], db) -> dict[str, dict[str, Any]]:
    """Product page state per video id, from one query; invalid ids are skipped."""
    video_oids = []
    for item in segments:
        video_id = item.get("video_id")
        if not video_id:
            continue
        try:
            video_oids.append(object_id(str(video_id)))
        except ValueError:
            continue
    if not video_oids:
        return {}
    return {
        str(video["_id"]): _redacted(product_page_state(video))
        for video in db.ai_idol_videos.find({"_id": {"$in": video_oids}}, _VIDEO_PAGE_PROJECTION)
    }


def _serialize_with_campaign(document: dict[str, Any], db) -> dict[str, Any]:
    result = serialize(document)
    campaign_id = document.get("campaign_id")
    result["status_effective"] = document.get("status")  # derived for display only, never stored
    if not campaign_id:
        return result
    try:
        campaign = db.ai_idol_campaigns.find_one(
            {"_id": object_id(str(campaign_id))},
            {"status": 1, "progress": 1, "error_message": 1, "master_video_path": 1, "scheduled_at": 1,
             "platforms": 1, "preflight": 1, "started_at": 1, "ends_at": 1, "purpose": 1},
        )
    except ValueError:
        campaign = None
    if campaign:
        result["campaign_status"] = campaign.get("status")
        result["campaign_purpose"] = purpose_of(campaign)
        result["campaign_progress"] = serialize(campaign.get("progress") or {})
        result["campaign_error_message"] = _redacted(campaign.get("error_message"))
        result["master_video_ready"] = bool(campaign.get("master_video_path"))
        result["campaign_scheduled_at"] = serialize(campaign.get("scheduled_at"))
        result["campaign_platforms"] = list(campaign.get("platforms") or [])
        result["campaign_started_at"] = serialize(campaign.get("started_at"))
        result["campaign_ends_at"] = serialize(campaign.get("ends_at"))
        result["campaign_preflight"] = _redacted(serialize(campaign.get("preflight") or {}))
        result["status_effective"] = campaign.get("status") or document.get("status")
        segments = list(db.ai_idol_segments.find(
            {"campaign_id": str(campaign_id)},
            {"product_id": 1, "position": 1, "status": 1, "job_id": 1, "video_id": 1},
        ).sort("position", 1))
        video_pages = _segment_video_pages(segments, db)
        stages = {}
        job_ids = [item["job_id"] for item in segments if item.get("job_id")]
        if job_ids:
            try:
                stages = {
                    str(job["_id"]): job.get("current_stage")
                    for job in db.ai_idol_jobs.find({"_id": {"$in": [object_id(str(i)) for i in job_ids]}},
                                                    {"current_stage": 1})
                }
            except ValueError:
                stages = {}
        result["campaign_segments"] = [
            {"product_id": item.get("product_id"), "status": item.get("status"),
             "job_stage": stages.get(str(item.get("job_id"))),
             "product_page": video_pages.get(str(item["video_id"])) if item.get("video_id") else None}
            for item in segments
        ]
        result["campaign_broadcasts"] = [
            _redacted(serialize({key: item.get(key) for key in
                                 ("platform", "status", "attempt", "started_at", "ends_at", "completed_at", "error_message")
                                 if item.get(key) is not None}))
            for item in db.ai_idol_broadcasts.find({"campaign_id": str(campaign_id)})
        ]
    return result


def create_agent_run(payload: dict[str, Any], created_by: str | None = None) -> tuple[str, bool]:
    brief = str(payload.get("brief") or "").strip()
    if len(brief) < 12:
        raise ValueError("Mô tả cần ít nhất 12 ký tự để Agent hiểu yêu cầu")
    if len(brief) > 4000:
        raise ValueError("Mô tả không được vượt quá 4.000 ký tự")

    # Studio's "Tự động" tab sends mode="autopilot"; anything else (or nothing) keeps the classic behaviour.
    mode = "autopilot" if str(payload.get("mode") or "").strip().lower() == "autopilot" else "classic"
    idempotency_key = str(payload.get("idempotency_key") or "").strip()
    if not idempotency_key:
        source = f"{created_by or 'admin'}|{brief}|{payload.get('autonomy_policy', 'auto_preview')}"
        if mode == "autopilot":  # classic requests keep their original hash, so existing dedupe is unchanged
            source += f"|{mode}"
        idempotency_key = hashlib.sha256(source.encode("utf-8")).hexdigest()

    db = get_db()
    existing = db.ai_idol_agent_runs.find_one({"idempotency_key": idempotency_key})
    if existing:
        return str(existing["_id"]), False

    now = utcnow()
    document = {
        "brief": brief,
        "mode": mode,
        "overrides": {
            # An autopilot run never waits for a script review: it always previews and auto-approves.
            "autonomy_policy": "auto_preview" if mode == "autopilot" else payload.get("autonomy_policy", "auto_preview"),
            "scheduled_at": payload.get("scheduled_at"),
            "duration_minutes": payload.get("duration_minutes"),
            "platforms": payload.get("platforms"),
            "content_mode": payload.get("content_mode"),
            "explicit_product_ids": payload.get("product_ids"),
            "purpose": payload.get("purpose"),
        },
        "status": "RECEIVED",
        "current_stage": "RECEIVED",
        "intent_classification": None,
        "normalized_brief": None,
        "plan": None,
        "decisions": [],
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


STAGE_HISTORY_LIMIT = 30


def update_agent_run(run_id: str, values: dict[str, Any]) -> None:
    payload = dict(values)
    now = utcnow()
    payload["updated_at"] = now
    update: dict[str, Any] = {"$set": payload}
    if "status" in values or "current_stage" in values:
        # Bounded audit trail shown as the "what is the agent doing" timeline.
        update["$push"] = {"stage_history": {
            "$each": [{"status": values.get("status"), "stage": values.get("current_stage"), "at": now}],
            "$slice": -STAGE_HISTORY_LIMIT,
        }}
    get_db().ai_idol_agent_runs.update_one({"_id": object_id(run_id)}, update)


def _decision_moment(value: Any) -> dt.datetime | None:
    """The ``at`` a decision already carries (ISO-8601), or None to stamp it now."""
    try:
        return dt.datetime.fromisoformat(str(value))
    except (TypeError, ValueError):
        return None


def append_decisions(run_id: str, items: list[dict[str, Any]]) -> None:
    """Add explained autopilot decisions to a run's timeline (one ``$push``, order kept).

    Every item goes through ``make_decision`` again, so the message is redacted and ``at`` is always set.
    A blank, malformed or unknown ``run_id`` is ignored: a decision must never fail the work it describes.
    """
    if not run_id or not items:
        return
    try:
        run_oid = object_id(str(run_id))
    except ValueError:
        return
    decisions = [
        make_decision(item.get("code", ""), item.get("level", ""), item.get("message", ""),
                      now=_decision_moment(item.get("at")))
        for item in items if isinstance(item, dict)
    ]
    if decisions:
        get_db().ai_idol_agent_runs.update_one({"_id": run_oid}, {"$push": {"decisions": {"$each": decisions}}})


def mark_agent_failed(run_id: str, stage: str, error: Exception | str) -> None:
    update_agent_run(run_id, {
        "status": "NEEDS_ATTENTION",
        "current_stage": stage,
        "error_message": str(error)[:2000],
    })
