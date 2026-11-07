"""Ready-to-read Vietnamese phrases for prices, discounts and deadlines."""
from datetime import datetime
from zoneinfo import ZoneInfo

_TZ = ZoneInfo("Asia/Ho_Chi_Minh")
_DIGITS = ["không", "một", "hai", "ba", "bốn", "năm", "sáu", "bảy", "tám", "chín"]
_UNITS = ["", "nghìn", "triệu", "tỷ"]


def _group_words(value: int, pad_hundreds: bool) -> str:
    """Read one 0-999 group; pad_hundreds forces 'không trăm' when a higher group exists."""
    hundreds, tens, ones = value // 100, (value // 10) % 10, value % 10
    parts = []
    if hundreds:
        parts.append(f"{_DIGITS[hundreds]} trăm")
    elif pad_hundreds:
        parts.append("không trăm")
    if tens == 1:
        parts.append("mười")
    elif tens >= 2:
        parts.append(f"{_DIGITS[tens]} mươi")
    elif ones and (hundreds or pad_hundreds):
        parts.append("linh")
    if ones:
        if ones == 1 and tens >= 2:
            parts.append("mốt")
        elif ones == 5 and tens >= 1:
            parts.append("lăm")
        else:
            parts.append(_DIGITS[ones])
    return " ".join(parts)


def number_words(n: int) -> str:
    n = int(n)
    if n < 0 or n >= 10 ** 12:
        raise ValueError("number_words supports 0 <= n < 10^12")
    if n == 0:
        return _DIGITS[0]
    groups = []
    while n:
        groups.append(n % 1000)
        n //= 1000
    parts = []
    for index in range(len(groups) - 1, -1, -1):
        value = groups[index]
        if not value:
            continue
        text = _group_words(value, pad_hundreds=index < len(groups) - 1)
        if _UNITS[index]:
            text += " " + _UNITS[index]
        parts.append(text)
    return " ".join(parts)


def money_words(n: int) -> str:
    return number_words(n) + " đồng"


def percent_words(p: int) -> str:
    return "giảm " + number_words(p) + " phần trăm"


def _day_words(day: int) -> str:
    return ("mùng " if day <= 10 else "") + number_words(day)


def _month_words(month: int) -> str:
    return "tư" if month == 4 else number_words(month)


def deadline_words(end_ts: int) -> str:
    local = datetime.fromtimestamp(int(end_ts), _TZ)
    date_part = f"ngày {_day_words(local.day)} tháng {_month_words(local.month)}"
    if local.hour == 23 and local.minute == 59:
        return f"đến hết {date_part}"
    clock = f"{number_words(local.hour)} giờ"
    if local.minute:
        clock += f" {number_words(local.minute)} phút"
    return f"đến {clock} {date_part}"


def price_phrases(pricing: dict | None) -> dict[str, str]:
    if not pricing:
        return {}
    phrases: dict[str, str] = {}
    if pricing.get("regular_price"):
        phrases["regular"] = money_words(pricing["regular_price"])
    if pricing.get("market_price"):
        phrases["market"] = money_words(pricing["market_price"])
    flash = pricing.get("flash")
    if flash:
        if flash.get("flash_price"):
            phrases["flash"] = money_words(flash["flash_price"])
        if flash.get("discount_percent"):
            phrases["discount"] = percent_words(flash["discount_percent"])
        if flash.get("end_ts"):
            phrases["deadline"] = deadline_words(flash["end_ts"])
    return phrases
