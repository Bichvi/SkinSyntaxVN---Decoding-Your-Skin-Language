from __future__ import annotations

import datetime as dt

from celery_app import celery_app
from agents.service import process_agent_run
from core.agent_runs import mark_agent_failed
from core.campaigns import claim_campaign_for_assembly, prepare_campaign, update_campaign_from_job
from core.pipeline import run_ai_idol_pipeline
from core.state_machine import get_db, object_id, utcnow
from engines.broadcast_engine import broadcast_campaign, preflight_campaign
from engines.program_composer import assemble_campaign_program


@celery_app.task(name="ai_idol.process_agent_run", bind=True, max_retries=2)
def process_agent_run_task(self, run_id: str):
    try:
        return process_agent_run(
            run_id,
            enqueue_campaign=lambda campaign_id: prepare_campaign_task.delay(campaign_id),
        )
    except Exception as exc:
        mark_agent_failed(run_id, "AGENT_ORCHESTRATION", exc)
        raise self.retry(exc=exc, countdown=min(60, 5 * (self.request.retries + 1)))


@celery_app.task(name="ai_idol.prepare_campaign", autoretry_for=(Exception,), retry_backoff=True, max_retries=3)
def prepare_campaign_task(campaign_id: str):
    job_ids = prepare_campaign(campaign_id)
    for job_id in job_ids:
        generate_job_task.delay(job_id)
    return {"campaign_id": campaign_id, "jobs": len(job_ids)}


@celery_app.task(name="ai_idol.generate_job", bind=True, max_retries=2)
def generate_job_task(self, job_id: str):
    try:
        success = run_ai_idol_pipeline(job_id)
    except Exception as exc:
        raise self.retry(exc=exc, countdown=min(60, 5 * (self.request.retries + 1)))
    campaign_id = update_campaign_from_job(job_id)
    if campaign_id and claim_campaign_for_assembly(campaign_id):
        assemble_campaign_task.delay(campaign_id)
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
    campaigns = db.ai_idol_campaigns.find({"status": "SCHEDULED", "scheduled_at": {"$lte": now}}).limit(20)
    for campaign in campaigns:
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
