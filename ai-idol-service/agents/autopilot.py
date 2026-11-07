"""Pure autopilot rules: each helper decides one thing and explains it in Vietnamese.

Nothing here reads the database or the clock (apart from the default ``now`` of
``make_decision``).  ``process_agent_run`` calls these helpers for runs with
``mode == "autopilot"`` and stores the returned decisions on the run.
"""
from __future__ import annotations

import datetime as dt
import re
from zoneinfo import ZoneInfo

from core.purpose import has_broadcast
from core.redact import redact_secrets

LEVELS = ("done", "adjusted", "waiting", "stopped")
DEFAULT_SLOTS = [dt.time(12, 0), dt.time(20, 0)]
SLOT_SEARCH_DAYS = 3
SCHEDULE_ROUND_MINUTES = 15
LOCAL_CHANNEL = "internal"
EXTERNAL_CHANNELS = ("youtube", "facebook")
CHANNEL_NAMES = {"internal": "Nội bộ", "youtube": "YouTube", "facebook": "Facebook"}
# build_query_plan asks this when the requested count is outside 1-50; COUNT_CAP answers it.
COUNT_RANGE_QUESTION = "Số lượng cần nằm trong khoảng"
_SLOT = re.compile(r"([0-9]{1,2}):([0-9]{2})")


def make_decision(code: str, level: str, message: str, *, now: dt.datetime | None = None) -> dict:
    """One explained autopilot choice, ready for a Mongo ``$push`` (plain JSON types only)."""
    if level not in LEVELS:
        raise ValueError(f"Mức quyết định không hợp lệ: {level!r}")
    moment = now if now is not None else dt.datetime.now(dt.timezone.utc)
    if moment.tzinfo is None:
        moment = moment.replace(tzinfo=dt.timezone.utc)
    return {
        "code": str(code),
        "level": level,
        "message": redact_secrets(message),
        "at": moment.astimezone(dt.timezone.utc).isoformat(),
    }


def autopilot_limit(requested: int, explicit: bool, *, default: int, maximum: int) -> tuple[int, dict | None]:
    """How many products to make: the prompt's number, the default, or the cap."""
    if not explicit:
        count = min(default, maximum)
        return count, make_decision("COUNT_DEFAULT", "adjusted", f"Prompt không nêu số lượng; làm với {count} sản phẩm.")
    if requested > maximum:
        message = f"Xin {requested} sản phẩm, autopilot tối đa {maximum}; làm với {maximum} sản phẩm."
        return maximum, make_decision("COUNT_CAP", "adjusted", message)
    return requested, None


def count_shortfall(requested: int, found: int) -> dict | None:
    """Explain a result with fewer products than asked for; no products at all stops the run."""
    if found <= 0:
        message = "Không tìm được sản phẩm nào phù hợp; hãy nới điều kiện rồi thử lại."
        return make_decision("NO_PRODUCTS", "stopped", message)
    if found < requested:
        message = f"Xin {requested} sản phẩm, tìm được {found}; làm với {found} sản phẩm."
        return make_decision("COUNT_SHORT", "adjusted", message)
    return None


def parse_slots(value: str) -> list[dt.time]:
    """Parse "12:00,20:00" into sorted unique times; anything malformed gives the default slots."""
    slots: set[dt.time] = set()
    for part in str(value or "").split(","):
        match = _SLOT.fullmatch(part.strip())
        if not match or int(match.group(1)) > 23 or int(match.group(2)) > 59:
            return list(DEFAULT_SLOTS)
        slots.add(dt.time(int(match.group(1)), int(match.group(2))))
    return sorted(slots)


def _aware(value: dt.datetime, tz: ZoneInfo) -> dt.datetime:
    return value if value.tzinfo is not None else value.replace(tzinfo=tz)


def _clock(value: dt.datetime, tz: ZoneInfo) -> str:
    return _aware(value, tz).astimezone(tz).strftime("%H:%M %d/%m")


def next_prime_slot(earliest: dt.datetime, slots: list[dt.time], tz: ZoneInfo) -> dt.datetime:
    """Smallest moment >= earliest whose local time of day is one of the slots (searched over 3 days)."""
    local = _aware(earliest, tz).astimezone(tz)
    ordered = sorted(slots) or list(DEFAULT_SLOTS)
    for offset in range(SLOT_SEARCH_DAYS):
        day = local.date() + dt.timedelta(days=offset)
        for slot in ordered:
            candidate = dt.datetime.combine(day, slot.replace(tzinfo=None), tzinfo=tz)
            if candidate >= local:
                return candidate
    return round_up(local)  # unreachable with at least one slot; never leave a run without a time


def round_up(value: dt.datetime, minutes: int = SCHEDULE_ROUND_MINUTES) -> dt.datetime:
    """Round up to the next multiple of ``minutes`` since midnight; seconds are dropped."""
    if minutes < 1:
        raise ValueError("Bước làm tròn phải lớn hơn 0 phút")
    floor = value.replace(second=0, microsecond=0)
    since_midnight = floor.hour * 60 + floor.minute
    remainder = since_midnight % minutes
    if remainder == 0 and floor == value:
        return value
    return floor + dt.timedelta(minutes=minutes - remainder)


def resolve_schedule(
    requested: dt.datetime | None,
    earliest: dt.datetime,
    slots: list[dt.time],
    tz: ZoneInfo,
    *,
    unreadable: bool = False,
) -> tuple[dt.datetime, dict]:
    """Pick the broadcast time: the requested one if still feasible, else the nearest feasible one."""
    if requested is None:
        when = next_prime_slot(earliest, slots, tz)
        reason = "Prompt không nêu giờ phát rõ ràng (không đọc được giờ trong prompt)" if unreadable else "Prompt không nêu giờ phát"
        return when, make_decision("SLOT_PICKED", "adjusted", f"{reason}; chọn khung giờ vàng {_clock(when, tz)}.")
    requested = _aware(requested, tz)
    if requested >= _aware(earliest, tz):
        return requested, make_decision("SCHEDULE_KEPT", "done", f"Giữ lịch phát {_clock(requested, tz)} theo yêu cầu.")
    when = round_up(_aware(earliest, tz).astimezone(tz))
    message = (
        f"Dời lịch từ {_clock(requested, tz)} → {_clock(when, tz)} "
        "vì giờ đã chọn đã qua hoặc chưa đủ thời gian chuẩn bị."
    )
    return when, make_decision("SLOT_MOVED", "adjusted", message)


def split_platforms(requested: list[str], configured: set[str]) -> tuple[list[str], list[str], list[dict]]:
    """Livestreams always start on the internal channel; external ones wait for the admin's permission."""
    available = {str(item).strip().lower() for item in configured}
    internal = CHANNEL_NAMES[LOCAL_CHANNEL]
    decisions = [make_decision("PLATFORM_INTERNAL", "done", f"Phát trên kênh {internal}.")]
    pending: list[str] = []
    seen: set[str] = set()
    for item in requested:
        channel = str(item).strip().lower()
        if channel not in EXTERNAL_CHANNELS or channel in seen:
            continue
        seen.add(channel)
        name = CHANNEL_NAMES[channel]
        if channel in available:
            pending.append(channel)
            message = f"{name} chờ bạn cho phép; hiện chỉ phát {internal.lower()}."
            decisions.append(make_decision("PLATFORM_PENDING", "waiting", message))
        else:
            message = f"Chưa cấu hình RTMP cho {name}; chỉ phát {internal.lower()}."
            decisions.append(make_decision("PLATFORM_UNCONFIGURED", "adjusted", message))
    return [LOCAL_CHANNEL], pending, decisions


def _quantity(value) -> float | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def drop_out_of_stock(products: list[dict], stock: dict[str, float | None], purpose: str) -> tuple[list[dict], dict | None]:
    """Remove sold-out products from a livestream; unknown stock is kept, product videos are untouched."""
    if not has_broadcast(purpose):
        return products, None
    kept: list[dict] = []
    dropped: list[str] = []
    for product in products:
        product_id = product.get("product_id")
        quantity = None if product_id is None else _quantity(stock.get(str(product_id), stock.get(product_id)))
        if quantity is not None and quantity <= 0:
            dropped.append(str(product.get("name") or product_id))
        else:
            kept.append(product)
    if not dropped:
        return products, None
    message = f"Bỏ {len(dropped)} sản phẩm hết hàng khỏi livestream: {', '.join(dropped)}."
    return kept, make_decision("LIVE_OOS_DROPPED", "adjusted", message)


def query_questions_decision(questions: list[str]) -> dict | None:
    """Stop when the query planner is unsure what was meant; the count-range question is answered by the cap."""
    asked = [str(item).strip() for item in questions or []]
    unclear = [item for item in asked if item and not item.startswith(COUNT_RANGE_QUESTION)]
    if not unclear:
        return None
    return make_decision("QUERY_UNCLEAR", "stopped", " ".join(unclear))
