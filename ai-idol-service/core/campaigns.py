from __future__ import annotations

import datetime as dt
import hashlib
import os
from zoneinfo import ZoneInfo

from pymongo import ReturnDocument

from config import (
    CAMPAIGN_MAX_PRODUCTS,
    ESTIMATED_MINUTES_PER_PRODUCT,
    MIN_LEAD_MINUTES,
    TIMEZONE,
)
from core.narration import normalize_styles
from core.product_videos import product_page_state
from core.purpose import (
    DEFAULT_PURPOSE, LIVE_AND_PRODUCT, PRODUCT_VIDEO, PRODUCT_VIDEO_LENGTH_MODE,
    has_broadcast, normalize_purpose, purpose_of,
)
from core.state_machine import create_job, get_db, object_id, serialize, utcnow
from engines.product_collector import collect_product_and_snapshot


ALLOWED_PLATFORMS = {"internal", "youtube", "facebook", "preview"}
# Channels that need an admin's go-ahead before a campaign may stream to them.
EXTERNAL_PLATFORMS = ("youtube", "facebook")
GENERATION_PHASE_STATUSES = ("VALIDATING", "GENERATING", "AWAITING_APPROVAL", "NEEDS_ATTENTION")


class CampaignNotFound(LookupError):
    """The campaign id does not exist (HTTP 404)."""


class CampaignConflict(ValueError):
    """The campaign is in a state where the action cannot help (HTTP 409)."""


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
    if payload.get("purpose"):
        purpose = normalize_purpose(payload.get("purpose"))
    else:
        # A caller that asked for a time slot but no mode still wants its livestream.
        purpose = LIVE_AND_PRODUCT if payload.get("scheduled_at") else DEFAULT_PURPOSE
    scheduled_at = None
    platforms = []
    pending_external_platforms: list[str] = []
    if has_broadcast(purpose):
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
        # Autopilot streams to the internal channel only; these wait for the admin's permission.
        requested_pending = payload.get("pending_external_platforms")
        if isinstance(requested_pending, (list, tuple)):
            pending_external_platforms = list(dict.fromkeys(
                item for item in (str(raw).strip().lower() for raw in requested_pending)
                if item in EXTERNAL_PLATFORMS
            ))
    configuration = payload.get("configuration") or {}
    if not isinstance(configuration, dict):
        raise ValueError("configuration must be an object")
    if configuration.get("avatar_mode") == "syna_3d":
        if configuration.get("format", "16:9") != "16:9":
            raise ValueError("Syna 3D hiện hỗ trợ khung ngang 16:9")
        configuration = {**configuration, "format": "16:9", "avatar_asset": ""}
    # A product video never speaks a price, so price styles are dropped for it; always stored as a list.
    narration_styles, _ = normalize_styles(configuration.get("narration_styles"), purpose)
    configuration = {**configuration, "narration_styles": narration_styles}

    label_day = (scheduled_at or utcnow()).date().isoformat()
    name = str(payload.get("name") or f"AI Idol {label_day}").strip()[:160]
    idempotency_key = str(payload.get("idempotency_key") or "").strip()
    if not idempotency_key:
        # Without a time slot there is nothing natural to dedupe on: every request is new.
        moment = scheduled_at.isoformat() if scheduled_at else f"{purpose}|{utcnow().isoformat()}"
        source = f"{name}|{moment}|{'|'.join(product_ids)}"
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
        "pending_external_platforms": pending_external_platforms,
        "scheduled_at": scheduled_at,
        "timezone": TIMEZONE,
        "duration_minutes": max(1, min(int(payload.get("duration_minutes", 60)), 720)),
        "interaction_enabled": bool(payload.get("interaction_enabled", False)),
        "content_mode": str(payload.get("content_mode", "Product Intro"))[:80],
        "purpose": purpose,
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
    """Create missing jobs and return the job ids that still need generating.

    Jobs that are already READY are never returned (re-running them would only
    repeat the pipeline); their segments are re-synchronised instead.
    """
    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
    if not campaign:
        raise ValueError("Campaign not found")

    job_ids, ready_job_ids, errors = [], [], []
    for segment in db.ai_idol_segments.find({"campaign_id": campaign_id}).sort("position", 1):
        if segment.get("job_id"):
            existing = db.ai_idol_jobs.find_one({"_id": object_id(str(segment["job_id"]))}, {"status": 1})
            if existing and existing.get("status") == "READY":
                ready_job_ids.append(str(segment["job_id"]))
            else:
                job_ids.append(str(segment["job_id"]))
            continue
        try:
            snapshot = collect_product_and_snapshot(segment["product_id"])
            purpose = purpose_of(campaign)
            configuration = dict(campaign.get("configuration") or {})
            if purpose == PRODUCT_VIDEO:
                configuration.setdefault("length_mode", PRODUCT_VIDEO_LENGTH_MODE)
            job_id = create_job(
                segment["product_id"],
                campaign.get("content_mode", "Product Intro"),
                configuration,
                campaign_id=campaign_id,
                segment_id=str(segment["_id"]),
                product_snapshot=snapshot,
                purpose=purpose,
            )
            db.ai_idol_segments.update_one({"_id": segment["_id"]}, {"$set": {
                "job_id": job_id, "status": "GENERATING", "error_message": None, "updated_at": utcnow(),
            }})
            job_ids.append(job_id)
        except Exception as exc:
            errors.append(f"Sản phẩm {segment.get('product_id')}: {exc}")
            db.ai_idol_segments.update_one({"_id": segment["_id"]}, {"$set": {
                "status": "FAILED", "error_message": str(exc), "updated_at": utcnow(),
            }})

    for ready_job_id in ready_job_ids:
        update_campaign_from_job(ready_job_id)

    values = {
        "status": "GENERATING" if (job_ids or ready_job_ids) else "NEEDS_ATTENTION",
        "progress.failed": len(errors),
        "updated_at": utcnow(),
    }
    if not job_ids and not ready_job_ids:
        # Every segment failed to start: say why instead of a blank NEEDS_ATTENTION.
        values["error_message"] = ("Không tạo được video nào. " + "; ".join(errors))[:2000]
    db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": values})
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
    # A cancelled segment stays cancelled even if its job finishes afterwards.
    db.ai_idol_segments.update_one({"job_id": job_id, "status": {"$ne": "CANCELLED"}}, {"$set": {
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
    # Conditional on a generation-phase status so a cancelled/scheduled/live
    # campaign is never pulled back by a late job callback.
    open_states = {"$in": list(GENERATION_PHASE_STATUSES)}
    if generating == 0 and failed:
        db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id), "status": open_states}, {"$set": {
            "status": "NEEDS_ATTENTION", "updated_at": utcnow(),
        }})
    elif generating == 0 and awaiting_approval:
        db.ai_idol_campaigns.update_one({"_id": object_id(campaign_id), "status": open_states}, {"$set": {
            "status": "AWAITING_APPROVAL", "updated_at": utcnow(),
        }})
    return campaign_id if generating == 0 and awaiting_approval == 0 and failed == 0 else None


def mark_product_videos_ready(campaign_id: str, from_status: str = "GENERATING") -> bool:
    """Close a product-video campaign: every clip is rendered and nothing will be broadcast."""
    now = utcnow()
    doc = get_db().ai_idol_campaigns.find_one_and_update(
        {"_id": object_id(campaign_id), "status": from_status},
        {"$set": {"status": "VIDEOS_READY", "completed_at": now, "error_message": None, "updated_at": now}},
        return_document=ReturnDocument.AFTER,
    )
    return bool(doc)


def claim_campaign_for_assembly(campaign_id: str) -> bool:
    campaign = get_db().ai_idol_campaigns.find_one({"_id": object_id(campaign_id)}, {"purpose": 1})
    if campaign and not has_broadcast(purpose_of(campaign)):
        # Product videos have no master program to assemble; finishing generation ends them.
        mark_product_videos_ready(campaign_id)
        return False
    doc = get_db().ai_idol_campaigns.find_one_and_update(
        {"_id": object_id(campaign_id), "status": "GENERATING"},
        {"$set": {"status": "ASSEMBLING", "updated_at": utcnow()}},
        return_document=ReturnDocument.AFTER,
    )
    return bool(doc)


CANCELLABLE_STATUSES = ("VALIDATING", "GENERATING", "AWAITING_APPROVAL", "NEEDS_ATTENTION", "SCHEDULED")
RESCHEDULABLE_STATUSES = CANCELLABLE_STATUSES
LOCAL_PLATFORMS = {"internal", "preview"}
RESCHEDULE_LEAD_MINUTES = 2


def cancel_campaign(campaign_id: str) -> dict:
    """Cancel before airtime. Atomic: a campaign already STARTING/LIVE is a conflict."""
    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
    if not campaign:
        raise CampaignNotFound("Campaign not found")
    now = utcnow()
    claimed = db.ai_idol_campaigns.find_one_and_update(
        {"_id": campaign["_id"], "status": {"$in": list(CANCELLABLE_STATUSES)}},
        {"$set": {"status": "CANCELLED", "cancelled_at": now, "updated_at": now,
                  "error_message": None}},
        return_document=ReturnDocument.AFTER,
    )
    if not claimed:
        current = db.ai_idol_campaigns.find_one({"_id": campaign["_id"]}, {"status": 1}) or {}
        if current.get("status") in {"STARTING", "LIVE"}:
            raise CampaignConflict("Chương trình đang lên sóng nên không thể hủy. Hãy dừng phiên live ở nơi phát.")
        raise CampaignConflict(f"Chương trình đang ở trạng thái {current.get('status')}, không thể hủy.")
    # Segments that are not finished stop; finished videos are kept.
    pending = list(db.ai_idol_segments.find({
        "campaign_id": campaign_id, "status": {"$nin": ["READY", "CANCELLED"]},
    }))
    for segment in pending:
        db.ai_idol_segments.update_one(
            {"_id": segment["_id"], "status": {"$nin": ["READY", "CANCELLED"]}},
            {"$set": {"status": "CANCELLED", "updated_at": now}},
        )
        if segment.get("job_id"):
            db.ai_idol_jobs.update_one(
                {"_id": object_id(str(segment["job_id"])),
                 "status": {"$in": ["CREATED", "QUEUED", "WAITING_RENDERER", "AWAITING_APPROVAL"]}},
                {"$set": {"status": "FAILED", "failed_stage": "CANCELLED",
                          "error_message": "Chương trình đã bị hủy.", "updated_at": now}},
            )
    db.ai_idol_agent_runs.update_many(
        {"campaign_id": campaign_id, "status": {"$nin": ["CANCELLED", "COMPLETED"]}},
        {"$set": {"status": "COMPLETED", "current_stage": "CAMPAIGN_CANCELLED", "updated_at": now}},
    )
    return {"status": "CANCELLED", "cancelled_segments": len(pending)}


def reschedule_campaign(campaign_id: str, scheduled_at) -> dict:
    """Move the airtime. ``"now"`` (internal-only campaigns) airs on the next dispatch."""
    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
    if not campaign:
        raise CampaignNotFound("Campaign not found")
    now = utcnow()
    if str(scheduled_at or "").strip().lower() == "now":
        if not set(campaign.get("platforms") or []) <= LOCAL_PLATFORMS:
            raise ValueError("Chỉ phát ngay được với chương trình chỉ phát nội bộ.")
        new_time = now
    else:
        try:
            new_time = parse_schedule(str(scheduled_at or "").strip())
        except (TypeError, ValueError):
            raise ValueError("Lịch phát không hợp lệ.") from None
        if new_time < now + dt.timedelta(minutes=RESCHEDULE_LEAD_MINUTES):
            raise ValueError(f"Lịch mới phải cách hiện tại ít nhất {RESCHEDULE_LEAD_MINUTES} phút.")

    values = {"scheduled_at": new_time, "updated_at": now}
    master = str(campaign.get("master_video_path") or "")
    ready = (
        campaign.get("status") == "NEEDS_ATTENTION"
        and bool(master) and os.path.isfile(master)
        and db.ai_idol_segments.count_documents({"campaign_id": campaign_id, "status": {"$nin": ["READY", "CANCELLED"]}}) == 0
    )
    if ready:  # a finished programme that only missed its slot goes straight back on the schedule
        values.update({"status": "SCHEDULED", "error_message": None})
    claimed = db.ai_idol_campaigns.find_one_and_update(
        {"_id": campaign["_id"], "status": {"$in": list(RESCHEDULABLE_STATUSES)}},
        {"$set": values}, return_document=ReturnDocument.AFTER,
    )
    if not claimed:
        raise CampaignConflict(f"Chương trình đang ở trạng thái {campaign.get('status')}, không thể đổi lịch.")
    return {"status": claimed["status"], "scheduled_at": new_time.isoformat()}


def resume_ready_campaign(campaign_id: str) -> bool:
    """Claim for assembly when every segment is already READY (nothing left to render)."""
    db = get_db()
    total = db.ai_idol_segments.count_documents({"campaign_id": campaign_id})
    ready = db.ai_idol_segments.count_documents({"campaign_id": campaign_id, "status": "READY"})
    return bool(total and total == ready and claim_campaign_for_assembly(campaign_id))


def retry_campaign(campaign_id: str, *, enqueue_job=None, enqueue_prepare=None, enqueue_assemble=None) -> dict:
    """Recover a campaign that needs attention. Returns what was queued.

    1. FAILED segment with a job      -> reset the job, queue it again.
    2. FAILED segment without a job   -> queue campaign preparation.
    3. every segment READY, no master -> NEEDS_ATTENTION -> ASSEMBLING, queue assembly.
    4. master fine, preflight failed  -> CampaignConflict with instructions.
    """
    if enqueue_job is None or enqueue_prepare is None or enqueue_assemble is None:
        from tasks import assemble_campaign_task, generate_job_task, prepare_campaign_task
        enqueue_job = enqueue_job or generate_job_task.delay
        enqueue_prepare = enqueue_prepare or prepare_campaign_task.delay
        enqueue_assemble = enqueue_assemble or assemble_campaign_task.delay

    db = get_db()
    campaign = db.ai_idol_campaigns.find_one({"_id": object_id(campaign_id)})
    if not campaign:
        raise CampaignNotFound("Campaign not found")
    status = campaign.get("status")
    if status not in {"NEEDS_ATTENTION", "GENERATING", "AWAITING_APPROVAL"}:
        raise CampaignConflict(f"Chương trình đang ở trạng thái {status}, không có phần nào cần tạo lại.")

    segments = list(db.ai_idol_segments.find({"campaign_id": campaign_id}).sort("position", 1))
    failed = [segment for segment in segments if segment.get("status") == "FAILED"]
    result = {"queued_segments": 0, "assembly_queued": False, "prepare_queued": False}

    if failed:
        claimed = db.ai_idol_campaigns.find_one_and_update(
            {"_id": campaign["_id"], "status": {"$in": ["NEEDS_ATTENTION", "GENERATING", "AWAITING_APPROVAL"]}},
            {"$set": {"status": "GENERATING", "error_message": None, "updated_at": utcnow()}},
            return_document=ReturnDocument.AFTER,
        )
        if not claimed:
            raise CampaignConflict("Chương trình vừa đổi trạng thái, hãy tải lại rồi thử lại.")
        needs_prepare, skipped = False, []
        for segment in failed:
            job_id = segment.get("job_id")
            if not job_id:
                needs_prepare = True
                continue
            reset = db.ai_idol_jobs.update_one(
                {"_id": object_id(str(job_id)), "status": "FAILED"},
                {"$set": {"status": "QUEUED", "failed_stage": None, "error_message": None, "updated_at": utcnow()},
                 "$inc": {"retry_count": 1}},
            )
            if not reset.modified_count:
                skipped.append(str(job_id))  # the job moved on by itself; do not queue it twice
                continue
            db.ai_idol_segments.update_one({"_id": segment["_id"], "status": "FAILED"}, {"$set": {
                "status": "GENERATING", "error_message": None, "updated_at": utcnow(),
            }})
            enqueue_job(str(job_id))
            result["queued_segments"] += 1
        for job_id in skipped:
            update_campaign_from_job(job_id)  # re-sync the segment with the job's real state
        if needs_prepare:
            enqueue_prepare(campaign_id)
            result["prepare_queued"] = True
        return result

    all_ready = bool(segments) and all(segment.get("status") == "READY" for segment in segments)
    master = str(campaign.get("master_video_path") or "")
    master_ok = bool(master) and os.path.isfile(master)
    if all_ready and status == "NEEDS_ATTENTION" and not has_broadcast(purpose_of(campaign)):
        if not mark_product_videos_ready(campaign_id, from_status="NEEDS_ATTENTION"):
            raise CampaignConflict("Chương trình vừa đổi trạng thái, hãy tải lại rồi thử lại.")
        result["videos_ready"] = True
        return result
    if all_ready and not master_ok and status == "NEEDS_ATTENTION":
        claimed = db.ai_idol_campaigns.find_one_and_update(
            {"_id": campaign["_id"], "status": "NEEDS_ATTENTION"},
            {"$set": {"status": "ASSEMBLING", "error_message": None, "updated_at": utcnow()}},
            return_document=ReturnDocument.AFTER,
        )
        if not claimed:
            raise CampaignConflict("Chương trình vừa đổi trạng thái, hãy tải lại rồi thử lại.")
        enqueue_assemble(campaign_id)
        result["assembly_queued"] = True
        return result
    if all_ready and master_ok:
        problems = (campaign.get("preflight") or {}).get("errors") or [campaign.get("error_message") or "chưa rõ nguyên nhân"]
        raise CampaignConflict(
            "Video chương trình đã ghép xong; lỗi nằm ở bước kiểm tra phát sóng: "
            + "; ".join(str(item) for item in problems)
            + ". Hãy cấu hình nơi phát hoặc chọn phát nội bộ rồi tạo lại lịch."
        )
    raise CampaignConflict("Không có phần nào bị lỗi để tạo lại. Các phần còn lại đang được xử lý.")


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
                "artifacts.script_text": 1, "artifacts.rejected_script_text": 1,
                "artifacts.validation_feedback": 1,
            })
        except ValueError:
            job = None
        if job:
            segment["job_status"] = job.get("status")
            segment["current_stage"] = job.get("current_stage")
            segment["script_status"] = job.get("script_status")
            artifacts = job.get("artifacts") or {}
            segment["script_text"] = artifacts.get("script_text", "")
            segment["rejected_script_text"] = artifacts.get("rejected_script_text", "")
            segment["validation_feedback"] = artifacts.get("validation_feedback", "")
    for segment in segments:
        video_id = segment.get("video_id")
        try:
            video = db.ai_idol_videos.find_one({"_id": object_id(str(video_id))}) if video_id else None
        except ValueError:
            video = None
        if video:
            segment["product_page"] = product_page_state(video)
    campaign["segments"] = segments
    campaign["broadcasts"] = list(db.ai_idol_broadcasts.find({"campaign_id": campaign_id}))
    return serialize(campaign)


def list_campaigns(limit: int = 30) -> list[dict]:
    cursor = get_db().ai_idol_campaigns.find().sort("created_at", -1).limit(max(1, min(limit, 100)))
    return [serialize(doc) for doc in cursor]
