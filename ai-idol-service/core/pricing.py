"""Current pricing (regular / market / active flash sale) and price-claim validity for product videos.

Mirrors backend/app/models/FlashSaleCampaign.php (status + effective price rules). One deliberate
difference: ``resolve_pricing`` skips flash sales ending in under ``min_remaining`` seconds, because
it feeds script generation; validity checks pass ``min_remaining=0`` to match PHP.
"""
from __future__ import annotations

import datetime as dt

from bson import ObjectId

FLASH_MIN_REMAINING_SECONDS = 7200


def _aware(value):
    if not isinstance(value, dt.datetime):
        return None
    return value if value.tzinfo else value.replace(tzinfo=dt.timezone.utc)


def _price(value) -> int:
    try:
        return int(value or 0)
    except (TypeError, ValueError):
        return 0


def _item_price(item: dict) -> int:
    return _price(item.get("flash_price", item.get("gia_flash")))


def _find_flash(product_id: str, regular: int, now: dt.datetime, min_remaining: int, db) -> dict | None:
    best = None
    for camp in db.flash_sale_campaigns.find({"end_at": {"$gt": now}}):
        if not (camp.get("is_active") or camp.get("enabled")):
            continue
        start, end = _aware(camp.get("start_at")), _aware(camp.get("end_at"))
        if start is None or end is None or not (start <= now < end):
            continue
        if (end - now).total_seconds() < min_remaining:
            continue
        for item in camp.get("items") or []:
            code = item.get("ma_san_pham", item.get("product_id"))
            if code is None or str(code) != product_id:
                continue
            price = _item_price(item)
            if 0 < price < regular and (best is None or price < best["flash_price"]):
                best = {
                    "campaign_id": camp.get("ma_campaign"),
                    "campaign_name": camp.get("ten_campaign") or camp.get("name") or "",
                    "flash_price": price,
                    "discount_percent": round((regular - price) / regular * 100),
                    "end_ts": int(end.timestamp()),
                }
    return best


def resolve_pricing(product_id: str, product: dict, now: dt.datetime, db=None,
                    min_remaining: int = FLASH_MIN_REMAINING_SECONDS) -> dict | None:
    """Return the spec 3.1 pricing dict, or None when the product has no usable price."""
    if db is None:
        from core.state_machine import get_db
        db = get_db()
    now = _aware(now)
    regular = _price(product.get("gia_ban"))
    market_raw = _price(product.get("gia_thi_truong"))
    if regular <= 0 and market_raw > 0:
        regular = market_raw
    if regular <= 0:
        return None
    return {
        "regular_price": regular,
        "market_price": market_raw if market_raw > regular else None,
        "flash": _find_flash(str(product_id).strip(), regular, now, min_remaining, db),
        "captured_ts": int(now.timestamp()),
    }


def current_pricing(product_id: str, now: dt.datetime, db=None) -> dict | None:
    """Look the product up and resolve pricing without the 2-hour floor (validity checks)."""
    if db is None:
        from core.state_machine import get_db
        db = get_db()
    pid_str = str(product_id).strip()
    or_query = [{"ma_san_pham": pid_str}, {"id": pid_str}]
    try:
        pid_int = int(pid_str)
        or_query += [{"ma_san_pham": pid_int}, {"id": pid_int}]
    except ValueError:
        pass
    if len(pid_str) == 24:
        try:
            or_query.append({"_id": ObjectId(pid_str)})
        except Exception:
            pass
    product = db.san_pham.find_one({"$or": or_query})
    if not product:
        return None
    return resolve_pricing(pid_str, product, now, db=db, min_remaining=0)


def claim_from_pricing(product_id: str, pricing: dict | None) -> dict | None:
    """Spec 3.2: the prices a video was allowed to speak."""
    if not pricing:
        return None
    flash = pricing.get("flash")
    return {
        "product_id": str(product_id),
        "regular_price": pricing["regular_price"],
        "market_price": pricing.get("market_price"),
        "flash": ({"campaign_id": flash["campaign_id"], "flash_price": flash["flash_price"],
                   "end_ts": flash["end_ts"]} if flash else None),
        "valid_until_ts": flash["end_ts"] if flash else None,
    }


def price_claim_state(claim: dict | None, current: dict | None, now_ts: int) -> str | None:
    """Spec 3.3: None when the claim still holds, else the first failing reason."""
    if not claim:
        return None
    valid_until = claim.get("valid_until_ts")
    if valid_until is not None and now_ts >= valid_until:
        return "flash_ended"
    if current is None or current.get("regular_price") != claim.get("regular_price"):
        return "regular_changed"
    if claim.get("market_price") is not None and current.get("market_price") != claim["market_price"]:
        return "market_changed"
    claimed = claim.get("flash")
    if claimed:
        now_flash = current.get("flash")
        if (not now_flash or now_flash.get("campaign_id") != claimed.get("campaign_id")
                or now_flash.get("flash_price") != claimed.get("flash_price")):
            return "flash_changed"
    return None
