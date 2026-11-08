"""Smart-search hybrid recommend API (POST /api/search/recommend)."""

import logging
import os
import sys

_LOG_FORMAT = "[%(asctime)s] [%(process)d] [%(levelname)s] %(name)s: %(message)s"


def configure_logging() -> logging.Logger:
    """Gắn handler stdout cho logger ``search_service`` (idempotent).

    Root logger của gunicorn/Flask để mức WARNING và không có handler, nên các dòng INFO
    (JSON ``search_recommend`` kèm correlation_id) trước đây bị nuốt. Mức đọc từ
    ``SEARCH_LOG_LEVEL`` (mặc định INFO). Không ghi ra file -> log sạch qua ``docker logs``.
    """
    log = logging.getLogger("search_service")
    level = getattr(logging, os.getenv("SEARCH_LOG_LEVEL", "INFO").strip().upper(), logging.INFO)
    log.setLevel(level)
    if not any(getattr(h, "_search_service_handler", False) for h in log.handlers):
        handler = logging.StreamHandler(sys.stdout)
        handler.setFormatter(logging.Formatter(_LOG_FORMAT))
        handler._search_service_handler = True  # type: ignore[attr-defined]
        log.addHandler(handler)
        log.propagate = False
    return log


configure_logging()
