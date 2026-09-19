from celery import Celery

from config import DISPATCH_INTERVAL_SECONDS, REDIS_URL, TIMEZONE


celery_app = Celery("ai_idol", broker=REDIS_URL, backend=REDIS_URL, include=["tasks"])
celery_app.conf.update(
    timezone=TIMEZONE,
    enable_utc=True,
    task_acks_late=True,
    task_reject_on_worker_lost=True,
    worker_prefetch_multiplier=1,
    result_expires=86400,
    broker_transport_options={"visibility_timeout": 14400},
    beat_schedule={
        "dispatch-due-campaigns": {
            "task": "ai_idol.dispatch_due_campaigns",
            "schedule": DISPATCH_INTERVAL_SECONDS,
        },
        "preflight-campaigns": {"task": "ai_idol.preflight_campaigns", "schedule": 60.0},
    },
)
