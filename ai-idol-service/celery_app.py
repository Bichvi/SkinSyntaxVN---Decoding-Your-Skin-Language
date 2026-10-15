from celery import Celery
from celery.schedules import crontab

from config import DISPATCH_INTERVAL_SECONDS, REDIS_URL, TIMEZONE


TASK_ROUTES = {
    "ai_idol.process_agent_run": {"queue": "control"},
    "ai_idol.prepare_campaign": {"queue": "control"},
    "ai_idol.dispatch_due_campaigns": {"queue": "control"},
    "ai_idol.preflight_campaigns": {"queue": "control"},
    "ai_idol.reap_stale_work": {"queue": "control"},
    "ai_idol.resume_waiting_renders": {"queue": "control"},
    "ai_idol.finalize_live_campaigns": {"queue": "control"},
    "ai_idol.build_product_metrics": {"queue": "control"},
    "ai_idol.generate_job": {"queue": "media"},
    "ai_idol.assemble_campaign": {"queue": "media"},
    # Publishing reads rendered files and runs ffmpeg, so it shares the media worker's storage.
    "ai_idol.publish_product_video": {"queue": "media"},
    "ai_idol.publish_campaign_videos": {"queue": "media"},
    "ai_idol.retry_product_video_publish": {"queue": "media"},
    "ai_idol.broadcast_campaign": {"queue": "broadcast"},
}

celery_app = Celery("ai_idol", broker=REDIS_URL, backend=REDIS_URL, include=["tasks"])
celery_app.conf.update(
    timezone=TIMEZONE,
    enable_utc=True,
    task_acks_late=True,
    task_reject_on_worker_lost=True,
    worker_prefetch_multiplier=1,
    result_expires=86400,
    broker_transport_options={"visibility_timeout": 14400},
    # Separate queues so a long render never starves scheduling or the agent:
    # control = fast orchestration, media = generate/assemble, broadcast = live streaming.
    task_routes=TASK_ROUTES,
    beat_schedule={
        "dispatch-due-campaigns": {
            "task": "ai_idol.dispatch_due_campaigns",
            "schedule": DISPATCH_INTERVAL_SECONDS,
            "options": {"expires": max(30.0, DISPATCH_INTERVAL_SECONDS * 6)},
        },
        "preflight-campaigns": {
            "task": "ai_idol.preflight_campaigns", "schedule": 60.0, "options": {"expires": 120},
        },
        "reap-stale-work": {
            "task": "ai_idol.reap_stale_work", "schedule": 60.0, "options": {"expires": 120},
        },
        "resume-waiting-renders": {
            "task": "ai_idol.resume_waiting_renders", "schedule": 60.0, "options": {"expires": 120},
        },
        "finalize-live-campaigns": {
            "task": "ai_idol.finalize_live_campaigns", "schedule": 30.0, "options": {"expires": 60},
        },
        "retry-product-video-publish": {
            "task": "ai_idol.retry_product_video_publish", "schedule": 600.0, "options": {"expires": 900},
        },
        "build-product-metrics": {
            "task": "ai_idol.build_product_metrics", "schedule": crontab(minute=30), "options": {"expires": 3000},
        },
    },
)
