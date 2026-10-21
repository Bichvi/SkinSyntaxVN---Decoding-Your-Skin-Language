import os

bind             = f"0.0.0.0:{os.getenv('CHATBOT_PORT', '5001')}"
workers          = int(os.getenv("GUNICORN_WORKERS", "2"))
timeout          = 120
graceful_timeout = 30
keepalive        = 5
# gthread: a long chat/LLM request must not block short /api/search/recommend
# calls queued behind it (single sync worker = head-of-line blocking, 5-20s).
worker_class     = os.getenv("GUNICORN_WORKER_CLASS", "gthread")
threads          = int(os.getenv("GUNICORN_THREADS", "8"))
preload_app      = True

loglevel  = "info"
accesslog = "-"
errorlog  = "-"


def post_fork(server, worker):
    try:
        from chatbot_service.retrieval import get_vectorstore, get_hybrid_pipeline
        from chatbot_service.llm_pool import get_llms
        from chatbot_service.session_cache import expire_old
        get_vectorstore()
        get_hybrid_pipeline()
        get_llms()
        expire_old()
        server.log.info(f"[Worker {worker.pid}] Models pre-warmed successfully")
    except Exception as e:
        server.log.warning(f"[Worker {worker.pid}] Pre-warm failed: {e}")
    try:
        # Smart-search warm-up before serving, not on the first user request: catalog, BM25,
        # embedding, EntityDictionary and the Neo4j driver + query plans (Bước 05B). The driver
        # is created HERE, after fork (never in the preloaded master: sockets must not be shared).
        # Marks this pid so before_app_request won't re-run it.
        from search_service import routes as search_routes
        from search_service.service import warm_up
        search_routes._warm_pid = os.getpid()
        warm_up()
        server.log.info(f"[Worker {worker.pid}] search_service warmed")
    except Exception as e:
        server.log.warning(f"[Worker {worker.pid}] search warm-up failed: {type(e).__name__}")
