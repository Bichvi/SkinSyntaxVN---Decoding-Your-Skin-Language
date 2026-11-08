"""Runtime configuration for the label-photo scan (env driven, read at import)."""

from __future__ import annotations

import os


def _bool(name: str, default: bool) -> bool:
    raw = os.getenv(name)
    if raw is None or raw.strip() == "":
        return default
    return raw.strip().lower() in {"1", "true", "yes", "on"}


def _int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except ValueError:
        return default


def _float(name: str, default: float) -> float:
    try:
        return float(os.getenv(name, str(default)))
    except ValueError:
        return default


def _str(name: str, default: str) -> str:
    return (os.getenv(name) or "").strip() or default


def _list(name: str, default: list[str]) -> list[str]:
    items = [p.strip().lower() for p in (os.getenv(name) or "").split(",") if p.strip()]
    return items or list(default)


def _rate_limit(raw: str | None, default: tuple[int, float] = (60, 60.0)) -> tuple[int, float]:
    try:
        count, window = (raw or "").split("/", 1)
        c, w = int(count), float(window)
        if c > 0 and w > 0:
            return c, w
    except ValueError:
        pass
    return default


LABEL_SCAN_ENABLED = _bool("LABEL_SCAN_ENABLED", False)
LABEL_VISION_PROVIDERS = _list("LABEL_VISION_PROVIDERS", ["groq", "gemini"])
LABEL_GROQ_VISION_MODEL = _str("LABEL_GROQ_VISION_MODEL", "qwen/qwen3.8-27b")
LABEL_GEMINI_VISION_MODEL = _str("LABEL_GEMINI_VISION_MODEL", "gemini-2.5-flash")
LABEL_VISION_TIMEOUT_S = _float("LABEL_VISION_TIMEOUT_S", 12.0)
LABEL_MAX_IMAGE_BYTES = max(1, int(_float("LABEL_MAX_IMAGE_MB", 4.0) * 1024 * 1024))
LABEL_RATE_LIMIT = _str("LABEL_RATE_LIMIT", "60/60")
RATE_LIMIT_COUNT, RATE_LIMIT_WINDOW_S = _rate_limit(LABEL_RATE_LIMIT)
MAX_INGREDIENTS_TEXT = 6000
MIN_INGREDIENTS_TEXT = 20


def groq_key() -> str:
    """Groq API key, read at call time. Never log or return elsewhere."""
    return os.getenv("GROQ_API_KEY", "").strip()


def gemini_key() -> str:
    """Gemini key: GOOGLE_API_KEY, else first non-empty of GOOGLE_API_KEYS."""
    key = os.getenv("GOOGLE_API_KEY", "").strip()
    if key:
        return key
    return next((k.strip() for k in os.getenv("GOOGLE_API_KEYS", "").split(",") if k.strip()), "")
