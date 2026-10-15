"""Remove stream targets and keys from any text that may be stored or shown."""
from __future__ import annotations

import re

from config import RTMP_TARGETS

_RTMP_URL = re.compile(r"rtmps?://\S+", re.IGNORECASE)
MASK = "[đã ẩn]"


def redact_secrets(value: object) -> str:
    """Replace configured RTMP targets, their stream keys and any rtmp(s):// URL."""
    text = str(value if value is not None else "")
    for target in sorted((item for item in RTMP_TARGETS.values() if item), key=len, reverse=True):
        text = text.replace(target, MASK)
        key = target.rstrip("/").rsplit("/", 1)[-1]
        if len(key) >= 6:  # FFmpeg sometimes prints the key without the URL
            text = text.replace(key, MASK)
    return _RTMP_URL.sub(MASK, text)
