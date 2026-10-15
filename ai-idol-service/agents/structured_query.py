"""Structured product query shared by manual mode (filter form) and the Agent.

The filter keys are the Prompt Builder selection keys (see ``fixtures/prompt_builder/cases.py:compose_brief``), so a
form selection and the sentence the Builder composes from it produce the same plan. The plan is run by the same
``execute_query`` the Agent uses; nothing here writes to the database.
"""
from __future__ import annotations

import datetime as dt
import re
import threading
import time
from typing import Any, Callable
from zoneinfo import ZoneInfo

from agents.command_query import METRIC_LABELS, _product_id, add_months, execute_query
from agents.intent_parser import normalize_text
from agents.product_resolver import normalize_catalog_product
from agents.query_sources import load_catalog, load_query_sources
from config import TIMEZONE
from core.state_machine import get_db

FILTER_ENUMS = {
    "expiry": {"", "expiry_17_19", "expiry_max_18"},
    "stock": {"", "stock_high", "stock_low", "out_of_stock"},
    "sales": {"", "best_30", "best_7", "best_all", "revenue_low_conversion", "views_no_orders", "cart", "discount_slow",
              "growth_7"},
    "warehouse_mode": {"", "warehouse", "batch"},
}
DEFAULT_LIMIT = MAX_LIMIT = 100
BRAND_CAP = 2  # "mỗi thương hiệu tối đa 2 sản phẩm" of the Prompt Builder
STOCK_HIGH_MIN, STOCK_LOW_MAX = 101, 10  # "tồn kho trên 100" / "tồn kho tối đa 10"
CACHE_TTL_SECONDS = 30
CACHE_MAX_ENTRIES = 16
# Months of remaining shelf life: (from, to), counted from today.
EXPIRY_MONTHS = {"expiry_17_19": (17, 19), "expiry_max_18": (0, 18)}
# sales filter -> (rank_by, window_days)
SALES_RANKING = {
    "best_30": ("units", 30), "best_7": ("units", 7), "best_all": ("units", None),
    "revenue_low_conversion": ("revenue_low_conversion", 30), "views_no_orders": ("views_no_orders", 30),
    "cart": ("cart", 30), "discount_slow": ("discount_slow", 30), "growth_7": ("growth", 7),
}
# rank_by -> the evidence field that decided the ranking
METRIC_EVIDENCE = {
    "units": "units_sold", "cart": "cart_sessions", "growth": "growth_rate", "views_no_orders": "viewed_sessions",
    "revenue_low_conversion": "net_revenue", "discount_slow": "units_per_day", "expiry": "expires_on",
    "stock_desc": "stock", "stock_asc": "stock",
}
COUNT_WARNING_PREFIX = "Tìm được "  # execute_query's "x/limit" notice; meaningless once q narrows the rows
TRUE_TEXT, FALSE_TEXT = {"1", "true", "on", "yes"}, {"0", "false", "off", "no", ""}


class FilterError(ValueError):
    """The request's filters are malformed (HTTP 400). Any other ValueError is a server-side failure."""


def _invalid(key: str) -> FilterError:
    return FilterError(f"Bộ lọc không hợp lệ: {key}")


def _enum(filters: dict, key: str) -> str:
    value = filters.get(key)
    value = "" if value is None else value
    if not isinstance(value, str) or value not in FILTER_ENUMS[key]:
        raise _invalid(key)
    return value


def _flag(filters: dict, key: str) -> bool:
    value = filters.get(key)
    if value is None or isinstance(value, bool):
        return bool(value)
    if isinstance(value, (int, float)) and value in (0, 1):
        return bool(value)
    if isinstance(value, str) and value.strip().lower() in TRUE_TEXT | FALSE_TEXT:
        return value.strip().lower() in TRUE_TEXT
    raise _invalid(key)


def _text(filters: dict, key: str) -> str:
    value = filters.get(key)
    if value is None:
        return ""
    if not isinstance(value, str):
        raise _invalid(key)
    return value.strip()


def _whole_number(value: Any) -> int | None:
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, float) and value.is_integer():
        return int(value)
    if isinstance(value, str) and re.fullmatch(r"\d+", value.strip(), re.ASCII):
        try:
            return int(value)
        except ValueError:  # Python refuses absurdly long digit strings
            return None
    return None


def _limit(filters: dict) -> int:
    value = filters.get("limit")
    if value is None or value == "":
        return DEFAULT_LIMIT
    limit = _whole_number(value)
    if limit is None or not 1 <= limit <= MAX_LIMIT:
        raise _invalid("limit")
    return limit


def _category_ids(filters: dict) -> list[int] | None:
    value = filters.get("category_ids")
    if value is None:
        return None
    if not isinstance(value, (list, tuple)):
        raise _invalid("category_ids")
    ids = [_whole_number(item) for item in value]
    if any(item is None or item < 0 for item in ids):
        raise _invalid("category_ids")
    return list(dict.fromkeys(ids)) or None  # an empty list means "no category chosen"


def _summary(plan: dict) -> list[str]:
    lines = [
        f"Số lượng: tối đa {plan['limit']} sản phẩm",
        "Danh mục: " + (f"{len(plan['category_ids'])} mục đã chọn" if plan["category_ids"] else "Tất cả sản phẩm"),
        "Xếp hạng: " + METRIC_LABELS[plan["rank_by"]],
    ]
    for label, key in (("Tồn kho từ", "stock_min"), ("Tồn kho tối đa", "stock_max")):
        if plan[key] is not None:
            lines.append(f"{label}: {plan[key]}")
    if plan["expiry_required"]:
        lines.append(f"Hạn dùng từ {plan['expiry_from']} đến {plan['expiry_to'] or 'không giới hạn'}; loại lô hết hạn/cách ly")
    if plan["warehouse_id"]:
        lines.append("Kho: " + plan["warehouse_id"])
    if plan["batch_id"]:
        lines.append("Lô: " + plan["batch_id"])
    if plan["brand"]:
        lines.append("Ưu tiên thương hiệu: " + plan["brand"])
    if plan["per_brand_max"]:
        lines.append(f"Mỗi thương hiệu tối đa {plan['per_brand_max']} sản phẩm")
    if plan["q"]:
        lines.append("Tên hoặc mã chứa: " + plan["q"])
    return lines


def plan_from_filters(filters: dict, *, today: dt.date) -> dict:
    """Plan with the keys of ``build_query_plan`` (plus category_ids and q) for a Prompt Builder selection.

    Unknown keys are ignored. An enum value outside ``FILTER_ENUMS`` (or any other malformed value) raises
    ``FilterError("Bộ lọc không hợp lệ: <khóa>")``, a ``ValueError`` subclass; it is the only exception that
    means "bad request" (loaders raise plain ``ValueError`` for data-size failures).
    """
    if not isinstance(filters, dict):
        raise _invalid("filters")
    expiry, stock, sales, mode = (_enum(filters, key) for key in ("expiry", "stock", "sales", "warehouse_mode"))
    limit, category_ids = _limit(filters), _category_ids(filters)
    q, brand, code = _text(filters, "q"), normalize_text(_text(filters, "brand")), _text(filters, "scope_code")
    brand_cap, enough_stock = _flag(filters, "brand_cap"), _flag(filters, "enough_stock")
    if mode and not code:
        raise _invalid("scope_code")  # silently dropping the scope would widen the query
    plan: dict[str, Any] = {
        "version": 1, "category": None, "category_ids": category_ids, "q": q or None,
        "limit": limit, "limit_explicit": True, "as_of": today.isoformat(),
        "stock_min": None, "stock_max": None, "expiry_from": None, "expiry_to": None,
        "expiry_required": False, "rank_by": "catalog", "window_days": 30,
        "warehouse_id": None, "batch_id": None,
        "brand": None, "brand_mode": None, "per_brand_max": None,
        "questions": [], "assumptions": [],
    }
    if stock == "out_of_stock":  # contradicts "còn hàng": the "đủ hàng" box is ignored, as in the Builder
        plan.update(stock_max=0, rank_by="stock_asc")
    else:
        if expiry or enough_stock or stock:
            plan.update(stock_min=1, rank_by="stock_desc")
        if stock == "stock_high":
            plan["stock_min"] = STOCK_HIGH_MIN
        elif stock == "stock_low":
            plan.update(stock_max=STOCK_LOW_MAX, rank_by="stock_asc")
    if expiry:
        months_from, months_to = EXPIRY_MONTHS[expiry]
        plan.update(expiry_required=True, rank_by="expiry", expiry_from=add_months(today, months_from).isoformat(),
                    expiry_to=add_months(today, months_to).isoformat())
    if sales:
        plan["rank_by"], plan["window_days"] = SALES_RANKING[sales]
    if mode:
        plan["warehouse_id" if mode == "warehouse" else "batch_id"] = code
    if brand:
        plan.update(brand=brand, brand_mode="prefer")
    if brand_cap:
        plan["per_brand_max"] = BRAND_CAP
    plan["summary"] = _summary(plan)
    return plan


class _TtlCache:
    """Small thread-safe cache; a failed load is never stored. Entries are evicted oldest-first past the cap."""

    def __init__(self, ttl: float, max_entries: int):
        self._ttl, self._max_entries = ttl, max_entries
        self._items: dict[Any, tuple[float, Any]] = {}
        self._lock = threading.Lock()

    def get(self, key: Any, now: float, loader: Callable[[], Any]) -> Any:
        with self._lock:  # one loader at a time, so concurrent requests share a single database read
            entry = self._items.get(key)
            if entry is not None and now - entry[0] < self._ttl:
                return entry[1]
            value = loader()
            self._items = {k: v for k, v in self._items.items() if k != key and now - v[0] < self._ttl}
            while len(self._items) >= self._max_entries:
                del self._items[min(self._items, key=lambda k: self._items[k][0])]
            self._items[key] = (now, value)
            return value

    def clear(self) -> None:
        with self._lock:
            self._items = {}


_catalog_cache = _TtlCache(CACHE_TTL_SECONDS, 1)
_sources_cache = _TtlCache(CACHE_TTL_SECONDS, CACHE_MAX_ENTRIES)


def clear_cache() -> None:
    _catalog_cache.clear()
    _sources_cache.clear()


def _catalog_index(db) -> tuple[list[dict], dict[str, dict]]:
    rows = load_catalog(db)
    return rows, {_product_id(row): row for row in rows}


def _sources_key(plan: dict) -> tuple:
    return (plan["as_of"], plan["window_days"], plan["rank_by"], plan["expiry_required"],
            plan["stock_min"] is not None or plan["stock_max"] is not None, plan["warehouse_id"], plan["batch_id"])


def _clean_number(value: Any) -> Any:
    return int(value) if isinstance(value, float) and value.is_integer() else value


def _image_raw(raw: dict) -> str:
    return str(raw.get("link_hinh_anh") or raw.get("hinh_anh") or "").split("|")[0].strip()


def _product(selected: dict, raw: dict, rank_by: str) -> dict:
    evidence = selected["evidence"]
    stock = evidence.get("eligible_quantity", evidence.get("stock"))  # the quantity the filters were applied to
    field = METRIC_EVIDENCE.get(rank_by)
    metric = stock if field == "stock" else evidence.get(field) if field else None
    return {
        "product_id": selected["product_id"], "name": selected["name"], "image_raw": _image_raw(raw),
        "stock": _clean_number(stock), "price": _clean_number(evidence.get("price")),
        "brand": normalize_catalog_product(raw)["brand"],
        "metric_label": selected["reason"], "metric_value": _clean_number(metric),
    }


def run_structured_query(filters: dict, *, db=None, today: dt.date | None = None,
                         clock: Callable[[], float] = time.monotonic) -> dict:
    """Run a filter form and return display-ready products. Sources are cached for 30 s; results never are."""
    now = clock()
    plan = plan_from_filters(filters, today=today or dt.datetime.now(ZoneInfo(TIMEZONE)).date())
    db = db if db is not None else get_db()
    catalog, by_id = _catalog_cache.get("catalog", now, lambda: _catalog_index(db))
    batches, snapshots = _sources_cache.get(_sources_key(plan), now, lambda: load_query_sources(db, plan))
    # q narrows the ranked rows, so execute_query must not cut at the limit before q is applied.
    result = execute_query({**plan, "limit": len(catalog) + 1}, catalog, batches=batches, snapshots=snapshots)
    selected = result["selected"]
    if plan["q"]:
        needle = normalize_text(plan["q"])
        selected = [row for row in selected if needle in normalize_text(row["name"]) or needle in normalize_text(row["product_id"])]
    return {
        "ok": True,
        "products": [_product(row, by_id[row["product_id"]], plan["rank_by"]) for row in selected[:plan["limit"]]],
        "matched_count": len(selected),
        "warnings": [text for text in result["warnings"] if not text.startswith(COUNT_WARNING_PREFIX)],
        "missing_data": result["missing_data"],
        "checked_at": result["checked_at"],
    }
