"""Dependency health for the admin status strip and the agent.

Checks run in parallel with short timeouts and the result is cached for a few
seconds, so a polling UI cannot hammer Mongo, Redis, Celery or the renderers.
Nothing secret is returned: RTMP is reported as platform names only.
"""
from __future__ import annotations

import os
import threading
import time
from concurrent.futures import ThreadPoolExecutor

import requests

from config import (
    AVATAR_PROVIDER,
    AVATAR_SERVICE_URL,
    REDIS_URL,
    RTMP_TARGETS,
    SCRIPT_COUNCIL_ENABLED,
    SCRIPT_COUNCIL_URL,
    logger,
)
from core.state_machine import get_db, utcnow

CACHE_SECONDS = 15.0
CHECK_TIMEOUT = 2.0
REQUIRED_QUEUES = ("control", "media", "broadcast")

_cache: dict = {"at": 0.0, "value": None}
_lock = threading.Lock()


def _result(ok: bool | None, detail: str = "", **extra) -> dict:
    status = "ok" if ok else "not_required" if ok is None else "down"
    return {"ok": bool(ok) if ok is not None else True, "status": status, "detail": detail, **extra}


def check_mongo() -> dict:
    try:
        get_db().command("ping")
        return _result(True)
    except Exception as exc:
        logger.warning("[HEALTH] mongo: %s", exc)
        return _result(False, "Không kết nối được MongoDB.")


def check_redis() -> dict:
    try:
        import redis
        redis.Redis.from_url(REDIS_URL, socket_connect_timeout=CHECK_TIMEOUT, socket_timeout=CHECK_TIMEOUT).ping()
        return _result(True)
    except Exception as exc:
        logger.warning("[HEALTH] redis: %s", exc)
        return _result(False, "Không kết nối được Redis.")


def check_workers() -> dict:
    try:
        from celery_app import celery_app
        active = celery_app.control.inspect(timeout=CHECK_TIMEOUT).active_queues() or {}
        queues = {queue.get("name") for worker in active.values() for queue in worker}
        missing = [name for name in REQUIRED_QUEUES if name not in queues]
        detail = f"Thiếu worker cho hàng đợi: {', '.join(missing)}." if missing else ""
        return _result(not missing and bool(active), detail or ("Không có worker nào phản hồi." if not active else ""),
                       queues=sorted(name for name in queues if name), workers=len(active))
    except Exception as exc:
        logger.warning("[HEALTH] workers: %s", exc)
        return _result(False, "Không kiểm tra được worker.")


def check_script_council() -> dict:
    if not SCRIPT_COUNCIL_ENABLED:
        return _result(None, "Đang tắt trong cấu hình.")
    try:
        response = requests.get(SCRIPT_COUNCIL_URL + "/api/health", timeout=CHECK_TIMEOUT)
        return _result(response.status_code < 500, "" if response.status_code < 500 else f"HTTP {response.status_code}")
    except requests.RequestException:
        return _result(False, "Dịch vụ viết kịch bản không phản hồi; hệ thống sẽ dùng bản dự phòng.")


def check_syna() -> dict:
    from engines.syna_engine import RENDER_VERSION

    url = os.getenv("SYNA_RENDER_URL", "http://host.docker.internal:7862").rstrip("/")
    try:
        response = requests.get(url + "/health", timeout=CHECK_TIMEOUT)
        response.raise_for_status()
        renderer = response.json().get("renderer")
    except (requests.RequestException, ValueError):
        return _result(False, "Syna chưa chạy. Chạy scripts/start-ai-idol-syna.ps1 trên máy này.")
    if renderer != RENDER_VERSION:
        return _result(False, "Phiên bản Syna không khớp. Khởi động lại dịch vụ Syna.", renderer=str(renderer))
    return _result(True, renderer=renderer)


def check_avatar() -> dict:
    if AVATAR_PROVIDER == "mock":
        return _result(None, "Không bắt buộc với Syna 3D.")
    try:
        response = requests.get(AVATAR_SERVICE_URL + "/health", timeout=CHECK_TIMEOUT)
        return _result(response.status_code < 500, "" if response.status_code < 500 else f"HTTP {response.status_code}")
    except requests.RequestException:
        return _result(False, "Dịch vụ avatar không phản hồi.")


def check_rtmp() -> dict:
    platforms = sorted(RTMP_TARGETS)
    return {"ok": True, "status": "ok" if platforms else "not_configured", "detail": "", "platforms": platforms}


CHECKS = {
    "mongo": check_mongo,
    "redis": check_redis,
    "workers": check_workers,
    "script_council": check_script_council,
    "syna": check_syna,
    "avatar": check_avatar,
    "rtmp": check_rtmp,
}


def _run(name: str, check) -> tuple[str, dict]:
    try:
        return name, check()
    except Exception as exc:  # a broken probe must never break the endpoint
        logger.warning("[HEALTH] %s probe crashed: %s", name, exc)
        return name, _result(False, "Không kiểm tra được.")


def dependency_status(force: bool = False) -> dict:
    with _lock:
        if not force and _cache["value"] is not None and time.monotonic() - _cache["at"] < CACHE_SECONDS:
            return _cache["value"]
        with ThreadPoolExecutor(max_workers=len(CHECKS)) as pool:
            services = dict(pool.map(lambda item: _run(*item), list(CHECKS.items())))
        down = [name for name, item in services.items() if not item["ok"]]
        critical = {"mongo", "redis"} & set(down)
        value = {
            "overall": "down" if critical else "degraded" if down else "ok",
            "checked_at": utcnow().isoformat(),
            "services": services,
        }
        _cache.update(at=time.monotonic(), value=value)
        return value
