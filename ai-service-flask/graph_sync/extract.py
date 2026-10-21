"""Read-only MongoDB extraction (``find`` with projection only; never writes)."""

from __future__ import annotations

import logging
import time
from datetime import datetime, timezone
from typing import Any, Callable, Iterator, TypeVar

from .transform import CategoryInfo, Lookups, code

log = logging.getLogger("graph_sync.extract")
T = TypeVar("T")

PRODUCT_PROJECTION = {
    "_id": 0, "ma_san_pham": 1, "ten_san_pham": 1, "gia_ban": 1, "gia_thi_truong": 1,
    "so_luong_da_ban": 1, "diem_danh_gia": 1, "ma_thuong_hieu": 1, "ma_danh_muc": 1,
    "danh_muc_day_du": 1, "ma_loai_da": 1, "loai_da": 1, "ma_xuat_xu": 1,
    "thanh_phan_full": 1, "thanh_phan_sach": 1, "trang_thai": 1, "trang_thai_kho": 1,
    "so_luong_ton_kho": 1, "so_luong_ton": 1, "ton_kho": 1, "stock": 1, "quantity": 1,
    "updated_at": 1,
}
UPDATED_FIELD = "updated_at"   # 100% of san_pham (Bước 00 audit)
RETRIES = 4


class SinceUnsupported(RuntimeError):
    """Raised when the collection has no update timestamp to filter on."""


def with_retry(fn: Callable[[], T], what: str, retries: int = RETRIES) -> T:
    """Retry transient Atlas errors (SRV DNS lookups, server selection, reconnects)."""

    delay = 1.0
    for attempt in range(1, retries + 1):
        try:
            return fn()
        except Exception as exc:  # pymongo ConfigurationError/ServerSelectionTimeoutError/AutoReconnect
            if attempt == retries:
                raise
            log.warning("%s failed (attempt %d/%d): %s; retrying in %.0fs",
                        what, attempt, retries, type(exc).__name__, delay)
            time.sleep(delay)
            delay *= 2
    raise RuntimeError("unreachable")


def connect(uri: str, db_name: str) -> Any:
    from pymongo import MongoClient

    def _open() -> Any:
        client = MongoClient(uri, serverSelectionTimeoutMS=15000, connectTimeoutMS=15000, appname="graph_sync")
        client.admin.command("ping")
        return client[db_name]

    return with_retry(_open, "mongo connect")


def load_lookups(db: Any) -> Lookups:
    def _read() -> Lookups:
        brands = {code(d.get("ma_thuong_hieu")): str(d.get("ten_thuong_hieu") or "")
                  for d in db["thuong_hieu"].find({}, {"_id": 0, "ma_thuong_hieu": 1, "ten_thuong_hieu": 1})}
        categories = {
            code(d.get("ma_danh_muc")): CategoryInfo(
                str(d.get("ten_danh_muc") or ""), int(d.get("level") or 1),
                code(d.get("parent_id")) or None,
            )
            for d in db["danh_muc"].find({}, {"_id": 0, "ma_danh_muc": 1, "ten_danh_muc": 1, "level": 1, "parent_id": 1})
        }
        skin = {code(d.get("ma_loai_da")): str(d.get("ten_loai_da") or "")
                for d in db["loai_da"].find({}, {"_id": 0, "ma_loai_da": 1, "ten_loai_da": 1})}
        origins = {code(d.get("ma_xuat_xu")): str(d.get("ten_xuat_xu") or "")
                   for d in db["xuat_xu"].find({}, {"_id": 0, "ma_xuat_xu": 1, "ten_xuat_xu": 1})}
        return Lookups(brands=brands, categories=categories, skin_types=skin, origins=origins)

    return with_retry(_read, "mongo lookups")


def parse_since(value: str) -> datetime:
    """ISO8601 -> naive UTC datetime (Mongo stores naive UTC)."""

    parsed = datetime.fromisoformat(value.strip().replace("Z", "+00:00"))
    if parsed.tzinfo is not None:
        parsed = parsed.astimezone(timezone.utc).replace(tzinfo=None)
    return parsed


def ensure_since_supported(db: Any) -> None:
    doc = with_retry(lambda: db["san_pham"].find_one({UPDATED_FIELD: {"$exists": True}}, {"_id": 1}),
                     "mongo since check")
    if doc is None:
        raise SinceUnsupported(f"san_pham has no '{UPDATED_FIELD}' field: --since is unsupported, use --full")


def iter_products(db: Any, since: datetime | None = None, batch_size: int = 500) -> Iterator[dict[str, Any]]:
    """All products (visible and hidden: visibility is decided in transform), optionally changed since."""

    query: dict[str, Any] = {UPDATED_FIELD: {"$gte": since}} if since is not None else {}
    cursor = db["san_pham"].find(query, PRODUCT_PROJECTION).batch_size(batch_size)
    for doc in cursor:
        yield dict(doc)


def extract_products(db: Any, since: datetime | None = None, batch_size: int = 500) -> list[dict[str, Any]]:
    """Materialise ``iter_products`` with retry (catalog is a few thousand docs)."""

    return with_retry(lambda: list(iter_products(db, since, batch_size)), "mongo extract")
