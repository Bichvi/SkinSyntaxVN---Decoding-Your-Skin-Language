from __future__ import annotations

import datetime as dt

from celery_app import celery_app
from agents.service import process_agent_run
from core.agent_runs import mark_agent_failed
from core.campaigns import claim_campaign_for_assembly, prepare_campaign, resume_ready_campaign, update_campaign_from_job
from core.pipeline import run_ai_idol_pipeline
from core.purpose import PRODUCT_VIDEO, purpose_of
from config import BROADCAST_LATE_GRACE_MINUTES
from core.health import check_syna
from core.product_metrics import run_due_snapshots
from core.state_machine import fail_job, get_db, object_id, utcnow
from core.watchdog import reap_stale_work, resume_waiting_renders
from engines.broadcast_engine import broadcast_campaign, finalize_live_campaigns, preflight_campaign
from engines.media_publisher import publish_campaign_videos, publish_video, retry_pending
from engines.program_composer import assemble_campaign_program


@celery_app.task(name="ai_idol.process_agent_run", bind=True, max_retries=2)
def process_agent_run_task(self, run_id: str):
    try:
        return process_agent_run(
            run_id,
            enqueue_campaign=lambda campaign_id: prepare_campaign_task.delay(campaign_id),
            renderer_check=check_syna,
        )
    except ValueError as exc:
        # Input/validation errors will not fix themselves: no retry.
        mark_agent_failed(run_id, "AGENT_ORCHESTRATION", exc)
        return {"run_id": run_id, "ok": False, "error": str(exc)}
    except Exception as exc:
        mark_agent_failed(run_id, "AGENT_ORCHESTRATION", exc)
        raise self.retry(exc=exc, countdown=min(60, 5 * (self.request.retries + 1)))


@celery_app.task(name="ai_idol.prepare_campaign", autoretry_for=(Exception,), retry_backoff=True, max_retries=3)
def prepare_campaign_task(campaign_id: str):
    job_ids = prepare_campaign(campaign_id)
    for job_id in job_ids:
        generate_job_task.delay(job_id)
    if not job_ids and resume_ready_campaign(campaign_id):
        # Every job was already READY (re-run after a crash): go straight to assembly.
        assemble_campaign_task.delay(campaign_id)
    return {"campaign_id": campaign_id, "jobs": len(job_ids)}


@celery_app.task(name="ai_idol.generate_job", bind=True, max_retries=2)
def generate_job_task(self, job_id: str):
    db = get_db()
    job = db.ai_idol_jobs.find_one({"_id": object_id(job_id)}, {"status": 1, "campaign_id": 1, "current_stage": 1})
    if not job:
        return False
    campaign_id = job.get("campaign_id")
    if campaign_id:
        campaign = db.ai_idol_campaigns.find_one({"_id": object_id(str(campaign_id))}, {"status": 1})
        if campaign and campaign.get("status") == "CANCELLED":
            fail_job(job_id, "CANCELLED", "Chương trình đã bị hủy; không tạo video nữa.")
            return False

    if job.get("status") == "READY":
        success = True  # idempotent: a duplicate delivery only re-syncs the campaign
    else:
        try:
            success = run_ai_idol_pipeline(job_id)
        except Exception as exc:
            if self.request.retries < self.max_retries:
                raise self.retry(exc=exc, countdown=min(60, 5 * (self.request.retries + 1)))
            # Out of retries: record the failure instead of leaving the job IN_PROGRESS.
            fail_job(job_id, job.get("current_stage") or "PIPELINE", f"Lỗi không xác định sau nhiều lần thử: {exc}")
            success = False
    campaign_id = update_campaign_from_job(job_id)
    if campaign_id and claim_campaign_for_assembly(campaign_id):
        assemble_campaign_task.delay(campaign_id)
    if success:
        # Product videos go straight to their product page; livestream clips wait for the show.
        done = db.ai_idol_jobs.find_one({"_id": object_id(job_id)}, {"purpose": 1, "video_id": 1})
        if done and purpose_of(done) == PRODUCT_VIDEO and done.get("video_id"):
            publish_product_video_task.delay(str(done["video_id"]))
    return success


@celery_app.task(name="ai_idol.assemble_campaign")
def assemble_campaign_task(campaign_id: str):
    try:
        return assemble_campaign_program(campaign_id)
    except Exception as exc:
        get_db().ai_idol_campaigns.update_one({"_id": object_id(campaign_id)}, {"$set": {
            "status": "NEEDS_ATTENTION", "error_message": str(exc), "updated_at": utcnow(),
        }})
        raise


@celery_app.task(name="ai_idol.dispatch_due_campaigns")
def dispatch_due_campaigns_task():
    db, now, dispatched = get_db(), utcnow(), 0
    late_before = now - dt.timedelta(minutes=BROADCAST_LATE_GRACE_MINUTES)
    campaigns = db.ai_idol_campaigns.find({"status": "SCHEDULED", "scheduled_at": {"$lte": now}}).limit(20)
    for campaign in campaigns:
        if campaign["scheduled_at"] < late_before:
            # Too late to go live "as scheduled": never start a stale slot silently.
            db.ai_idol_campaigns.update_one(
                {"_id": campaign["_id"], "status": "SCHEDULED"},
                {"$set": {
                    "status": "NEEDS_ATTENTION", "updated_at": now,
                    "error_message": f"Đã quá giờ phát hơn {BROADCAST_LATE_GRACE_MINUTES} phút nên chương trình không tự phát. Hãy đổi lịch hoặc chọn phát nội bộ ngay.",
                }},
            )
            continue
        result = db.ai_idol_campaigns.update_one(
            {"_id": campaign["_id"], "status": "SCHEDULED"},
            {"$set": {"status": "STARTING", "updated_at": now}},
        )
        if result.modified_count:
            broadcast_campaign_task.apply_async(args=[str(campaign["_id"])], queue="broadcast")
            dispatched += 1
    return dispatched


@celery_app.task(name="ai_idol.preflight_campaigns")
def preflight_campaigns_task():
    db, now = get_db(), utcnow()
    window_end = now + dt.timedelta(minutes=15)
    count = 0
    for campaign in db.ai_idol_campaigns.find({
        "status": "SCHEDULED", "scheduled_at": {"$gt": now, "$lte": window_end},
    }).limit(20):
        preflight_campaign(str(campaign["_id"]))
        count += 1
    return count


@celery_app.task(name="ai_idol.broadcast_campaign")
def broadcast_campaign_task(campaign_id: str):
    return broadcast_campaign(campaign_id)


@celery_app.task(name="ai_idol.reap_stale_work")
def reap_stale_work_task():
    return reap_stale_work()


@celery_app.task(name="ai_idol.resume_waiting_renders")
def resume_waiting_renders_task():
    return resume_waiting_renders(lambda job_id: generate_job_task.delay(job_id))


@celery_app.task(name="ai_idol.finalize_live_campaigns")
def finalize_live_campaigns_task():
    return finalize_live_campaigns()


@celery_app.task(name="ai_idol.build_product_metrics")
def build_product_metrics_task():
    return run_due_snapshots()


@celery_app.task(name="ai_idol.publish_product_video")
def publish_product_video_task(video_id: str):
    return publish_video(video_id)


@celery_app.task(name="ai_idol.publish_campaign_videos")
def publish_campaign_videos_task(campaign_id: str):
    return publish_campaign_videos(campaign_id)


@celery_app.task(name="ai_idol.retry_product_video_publish")
def retry_product_video_publish_task():
    return retry_pending()
