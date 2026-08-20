import os

bind             = f"0.0.0.0:{os.getenv('CHATBOT_PORT', '5001')}"
workers          = int(os.getenv("GUNICORN_WORKERS", "2"))
timeout          = 120
graceful_timeout = 30
keepalive        = 5
worker_class     = os.getenv("GUNICORN_WORKER_CLASS", "gevent")
preload_app      = True

loglevel  = "info"
accesslog = "-"
errorlog  = "-"


def post_fork(server, worker):
    try:
        from retrieval import get_vectorstore, get_hybrid_pipeline
        from llm_pool import get_llms
        from session_cache import expire_old
        get_vectorstore()
        get_hybrid_pipeline()
        get_llms()
        expire_old()
        server.log.info(f"[Worker {worker.pid}] Models pre-warmed successfully")
    except Exception as e:
        server.log.warning(f"[Worker {worker.pid}] Pre-warm failed: {e}")
