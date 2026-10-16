"""Recover work that a crashed worker or lost task left in a transient state.

Every update is conditional on the status (and, for jobs, the unchanged
``updated_at``) the sweep observed, so a task that finishes at the same moment
always wins and nothing is failed twice.
"""
from __future__ import annotations

import datetime as dt

from config import (
    AVATAR_SERVICE_TIMEOUT,
    RENDER_WAIT_CUTOFF_MINUTES,
    STALE_ASSEMBLING_MINUTES,
    STALE_JOB_MINUTES,
    STALE_STARTING_MINUTES,
    logger,
)
from core.campaigns import update_campaign_from_job
from core.health import check_syna
from core.state_machine import get_db, object_id, utcnow

# The avatar service may legitimately run up to AVATAR_SERVICE_TIMEOUT seconds.
SLOW_STAGE_MINUTES = {"GENERATING_AVATAR": AVATAR_SERVICE_TIMEOUT / 60 + 10}


def _job_threshold_minutes(stage: str | None) -> float:
    return max(STALE_JOB_MINUTES, SLOW_STAGE_MINUTES.get(str(stage or ""), 0))


def reap_stale_work(now: dt.datetime | None = None) -> dict[str, int]:
    db, now = get_db(), now or utcnow()
    reaped = {"jobs": 0, "starting": 0, "assembling": 0}

    base_cutoff = now - dt.timedelta(minutes=STALE_JOB_MINUTES)
    for job in db.ai_idol_jobs.find({"status": "IN_PROGRESS", "updated_at": {"$lt": base_cutoff}}).limit(50):
        stage = job.get("current_stage")
        if stage == "AUTO_REPAIR_EXHAUSTED" or job.get("status") == "AUTO_REPAIR_EXHAUSTED":
            continue  # parked for staff; never failed by the sweep
        minutes = _job_threshold_minutes(stage)
        if job["updated_at"] >= now - dt.timedelta(minutes=minutes):
            continue
        result = db.ai_idol_jobs.update_one(
            {"_id": job["_id"], "status": "IN_PROGRESS", "updated_at": job["updated_at"]},
            {"$set": {
                "status": "FAILED",
                "failed_stage": stage,
                "error_message": f"Quá {minutes:g} phút không có phản hồi ở bước {stage}. Hãy bấm tạo lại phần lỗi.",
                "updated_at": now,
            }},
        )
        if result.modified_count:
            reaped["jobs"] += 1
            logger.warning("[WATCHDOG] Job %s stuck at %s for over %s minutes", job["_id"], stage, minutes)
            if job.get("campaign_id"):
                update_campaign_from_job(str(job["_id"]))

    for key, status, minutes, message in (
        ("starting", "STARTING", STALE_STARTING_MINUTES,
         "Chương trình không bắt đầu phát được sau {m} phút. Kiểm tra worker phát sóng rồi thử lại."),
        ("assembling", "ASSEMBLING", STALE_ASSEMBLING_MINUTES,
         "Ghép video chương trình quá {m} phút không xong. Bấm tạo lại để ghép lại."),
    ):
        result = db.ai_idol_campaigns.update_many(
            {"status": status, "updated_at": {"$lt": now - dt.timedelta(minutes=minutes)}},
            {"$set": {"status": "NEEDS_ATTENTION", "error_message": message.format(m=minutes), "updated_at": now}},
        )
        reaped[key] = result.modified_count
        if result.modified_count:
            logger.warning("[WATCHDOG] %s campaign(s) left %s for over %s minutes", result.modified_count, status, minutes)
    return reaped


def resume_waiting_renders(enqueue, now: dt.datetime | None = None, *, renderer_check=None) -> dict[str, int]:
    """Requeue jobs parked as WAITING_RENDERER once Syna answers again.

    A job whose campaign airs in under RENDER_WAIT_CUTOFF_MINUTES is failed
    instead: a render started that late cannot be assembled before airtime and
    the staff must reschedule.
    """
    db, now = get_db(), now or utcnow()
    result = {"resumed": 0, "failed": 0, "waiting": 0}
    jobs = list(db.ai_idol_jobs.find({"status": "WAITING_RENDERER"}).limit(50))
    if not jobs:
        return result
    healthy = None  # probe lazily, once per sweep
    for job in jobs:
        campaign = None
        if job.get("campaign_id"):
            campaign = db.ai_idol_campaigns.find_one({"_id": object_id(str(job["campaign_id"]))}, {"scheduled_at": 1, "status": 1})
        scheduled_at = (campaign or {}).get("scheduled_at")
        if scheduled_at is not None and scheduled_at - now < dt.timedelta(minutes=RENDER_WAIT_CUTOFF_MINUTES):
            changed = db.ai_idol_jobs.update_one(
                {"_id": job["_id"], "status": "WAITING_RENDERER"},
                {"$set": {
                    "status": "FAILED", "failed_stage": "WAITING_RENDERER", "updated_at": now,
                    "error_message": f"Syna không sẵn sàng đến sát giờ phát (còn dưới {RENDER_WAIT_CUTOFF_MINUTES} phút). Bật Syna rồi đổi lịch và tạo lại phần lỗi.",
                }},
            )
            if changed.modified_count:
                result["failed"] += 1
                update_campaign_from_job(str(job["_id"]))
            continue
        if healthy is None:
            healthy = bool((renderer_check or check_syna)().get("ok"))
        if not healthy:
            result["waiting"] += 1
            continue
        claimed = db.ai_idol_jobs.update_one(
            {"_id": job["_id"], "status": "WAITING_RENDERER"},
            {"$set": {"status": "QUEUED", "current_stage": "QUEUED", "error_message": None, "updated_at": now}},
        )
        if claimed.modified_count:
            enqueue(str(job["_id"]))
            result["resumed"] += 1
    return result
