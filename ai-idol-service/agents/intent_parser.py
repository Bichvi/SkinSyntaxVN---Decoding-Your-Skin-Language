from __future__ import annotations

import datetime as dt
import re
import unicodedata
from zoneinfo import ZoneInfo

from agents.schemas import AGENT_BRIEF_JSON_SCHEMA, AgentBrief
from config import TIMEZONE, logger


def normalize_text(value: object) -> str:
    text = unicodedata.normalize("NFKD", str(value or "").lower())
    text = "".join(char for char in text if not unicodedata.combining(char))
    return re.sub(r"\s+", " ", text.replace("đ", "d")).strip()


def _extract_explicit_ids(text: str) -> list[str]:
    patterns = (
        # An id is explicit only when the user writes "mã", "ID" or "#".
        # Do not interpret the noun phrase "sản phẩm retinol" or the
        # quantity in "1 số sản phẩm" as a product id.
        r"(?:san\s*pham\s*)?\b(?:ma|id)\b\s*(?:san\s*pham|sp)?\s*[#:\-]?\s*([a-z0-9]{1,40})",
        r"\b(?:ma\s*san\s*pham|ma\s*sp)\b\s*[#:\-]?\s*([a-z0-9]{1,40})",
        r"#([a-z0-9]{1,40})",
    )
    values: list[str] = []
    for pattern in patterns:
        for match in re.findall(pattern, text, flags=re.IGNORECASE):
            value = str(match).strip()
            if value in {"ma", "san", "pham", "sp", "id"}:
                continue
            if value and value not in values:
                values.append(value)
    return values


def _extract_duration(text: str) -> int:
    match = re.search(r"(\d{1,3})\s*(?:phut|minutes?)", text)
    if match:
        return max(1, min(int(match.group(1)), 720))
    match = re.search(r"(\d{1,2})\s*(?:gio|hours?)", text)
    if match:
        return max(1, min(int(match.group(1)) * 60, 720))
    return 60


def _extract_schedule(text: str, now: dt.datetime) -> str | None:
    iso_match = re.search(r"\b(20\d{2}-\d{2}-\d{2})[ t](\d{1,2}):?(\d{2})?\b", text)
    if iso_match:
        hour, minute = int(iso_match.group(2)), int(iso_match.group(3) or 0)
        try:
            parsed = dt.datetime.fromisoformat(iso_match.group(1)).replace(
                hour=hour, minute=minute, tzinfo=now.tzinfo
            )
            return parsed.isoformat()
        except ValueError:
            return None

    time_match = re.search(r"(?:luc\s*)?(\d{1,2})(?:\s*gio|h)(?:\s*(\d{1,2}))?", text)
    if not time_match:
        return None
    hour, minute = int(time_match.group(1)), int(time_match.group(2) or 0)
    if not (0 <= hour <= 23 and 0 <= minute <= 59):
        return None
    target = now
    if "ngay mai" in text or "toi mai" in text or "sang mai" in text:
        target += dt.timedelta(days=1)
    target = target.replace(hour=hour, minute=minute, second=0, microsecond=0)
    if target <= now:
        target += dt.timedelta(days=1)
    return target.isoformat()


def fallback_parse(brief_text: str, overrides: dict | None = None, *, now: dt.datetime | None = None) -> AgentBrief:
    overrides = overrides or {}
    local_now = now or dt.datetime.now(ZoneInfo(TIMEZONE))
    normalized = normalize_text(brief_text)
    if "khoa hoc" in normalized or "hoat chat" in normalized or "thanh phan" in normalized:
        content_mode = "Scientific Review"
    elif "van de" in normalized or "giai phap" in normalized:
        content_mode = "Problem-Solution"
    else:
        content_mode = "Product Intro"

    platforms = []
    if "youtube" in normalized:
        platforms.append("youtube")
    if "facebook" in normalized:
        platforms.append("facebook")
    if "noi bo" in normalized or "preview" in normalized or not platforms:
        platforms.insert(0, "internal")

    data = {
        "goal": brief_text,
        "product_queries": [brief_text],
        "explicit_product_ids": _extract_explicit_ids(normalized),
        "content_mode": content_mode,
        "scheduled_at": _extract_schedule(normalized, local_now),
        "duration_minutes": _extract_duration(normalized),
        "platforms": platforms,
        "autonomy_policy": overrides.get("autonomy_policy", "auto_preview"),
        "campaign_name": "",
        "missing_fields": [],
        "confidence": 0.55,
    }
    data.update({key: value for key, value in overrides.items() if value not in (None, "", [])})
    return AgentBrief.from_mapping(data, fallback_goal=brief_text)


def parse_agent_brief(
    brief_text: str,
    overrides: dict | None = None,
    *,
    llm=None,
    now: dt.datetime | None = None,
) -> AgentBrief:
    """Parse a natural-language brief, degrading to deterministic extraction."""

    overrides = overrides or {}
    if llm is None:
        return fallback_parse(brief_text, overrides, now=now)

    local_now = now or dt.datetime.now(ZoneInfo(TIMEZONE))
    system_prompt = """
Bạn là bộ phân tích lệnh cho AI Idol Studio. Chỉ trích xuất ý định, không viết kịch bản,
không tự bịa sản phẩm. Thời gian phải ở dạng ISO 8601 có múi giờ Asia/Ho_Chi_Minh.
Nếu người dùng không nói nơi phát, dùng internal. Nếu không nói thời lượng, dùng 60 phút.
Tên/mã sản phẩm phải được giữ nguyên như người dùng viết để hệ thống đối chiếu catalog.
""".strip()
    user_prompt = (
        f"Thời điểm hiện tại: {local_now.isoformat()}\n"
        f"Lệnh nhân viên: {brief_text}\n"
        f"Thiết lập giao diện ưu tiên: {overrides}"
    )
    try:
        parsed = llm.invoke_json_schema(
            system_prompt,
            user_prompt,
            schema=AGENT_BRIEF_JSON_SCHEMA,
            schema_name="ai_idol_agent_brief",
        )
        parsed.update({key: value for key, value in overrides.items() if value not in (None, "", [])})
        return AgentBrief.from_mapping(parsed, fallback_goal=brief_text)
    except Exception as exc:
        logger.warning("[AGENT-INTENT] Structured parsing degraded: %s", exc)
        return fallback_parse(brief_text, overrides, now=local_now)
