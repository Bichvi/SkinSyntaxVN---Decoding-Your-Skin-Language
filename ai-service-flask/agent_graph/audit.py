from __future__ import annotations

import logging
import re
from datetime import datetime, timezone
from typing import Any, Callable

logger = logging.getLogger(__name__)


_PHONE_KEYS = {"phone", "phone_number", "sdt", "sdt_nguoi_nhan", "so_dien_thoai", "mobile"}
_ADDRESS_KEYS = {"address", "full_address", "dia_chi", "dia_chi_giao_hang", "street", "duong"}
_NAME_KEYS = {"ten_nguoi_nhan", "receiver_name", "full_name", "recipient_name", "ho_ten"}


def _mask_value(key: str | None, value: Any) -> Any:
    is_phone = key in _PHONE_KEYS
    is_address = key in _ADDRESS_KEYS
    is_name = key in _NAME_KEYS
    if key is not None and key.lower() in {"token", "csrf", "authorization"}:
        return "***"
    if (is_phone or is_address or is_name) and (
        not isinstance(value, (str, int, float)) or isinstance(value, bool)
    ):
        return "***"

    if isinstance(value, dict):
        return {child_key: _mask_value(child_key, child_value) for child_key, child_value in value.items()}
    if isinstance(value, list):
        return [_mask_value(None, child_value) for child_value in value]

    if is_name:
        text = str(value).strip()
        return "***" if not text else f"{text[0]}***"

    if is_phone:
        digits = re.sub(r"[^0-9]", "", str(value))
        return "***" if len(digits) < 6 else f"{digits[:2]}xx xxx {digits[-3:]}"

    if is_address:
        if not isinstance(value, str):
            return value
        return "…" if "," not in value else f"…, {value.rsplit(',', 1)[1].strip()}"

    return value


def mask(params: dict) -> dict:
    """Return a recursively masked copy of audit parameters."""
    return _mask_value(None, params)


def _default_insert(document: dict) -> None:
    # Import lazily so importing this module never opens a Mongo connection.
    try:
        from chatbot_service.profile_service import get_mongodb_connection
    except ImportError:
        from profile_service import get_mongodb_connection

    get_mongodb_connection().chat_agent_audit.insert_one(document)


def record(
    actor: str,
    action: str,
    params: dict,
    outcome: str,
    *,
    insert: Callable[[dict], Any] | None = None,
) -> None:
    try:
        (insert or _default_insert)({
            "ts": datetime.now(timezone.utc),
            "actor": actor,
            "action": action,
            "params": mask(params),
            "outcome": outcome,
            "source": "flask",
        })
    except Exception as exc:
        logger.warning("chat agent audit insert failed: %s", type(exc).__name__)
