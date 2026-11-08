"""Read-only Mongo catalog snapshot (brand/category names resolved by code lookup).

Shared by the BM25 branch, popularity/in-stock lookup, cross-encoder texts and the
Chroma re-index script. Never writes to Mongo.
"""

from __future__ import annotations

import logging
import time
from threading import Lock, Thread
from typing import Any

from . import config

logger = logging.getLogger(__name__)

_PROJECTION = {
    "_id": 0, "ma_san_pham": 1, "ten_san_pham": 1, "ma_thuong_hieu": 1, "ma_danh_muc": 1,
    "danh_muc_day_du": 1, "loai_da": 1, "thanh_phan_sach": 1, "thanh_phan_full": 1,
    "so_luong_da_ban": 1, "so_luong_ton_kho": 1, "trang_thai_kho": 1, "trang_thai": 1, "gia_ban": 1,
    "tag_source": 1,
}
_CURATED_SOURCES = ("manual", "llm_approved")
# Phải trùng graph_sync.transform.HIDDEN_STATUSES (Neo4j) và PHP SanPham::visibleProductFilter(); có test khóa.
_HIDDEN = ["inactive", "hidden", "tam_an", "taman", "disabled", "off", "0"]

_mongo_db: Any | None = None
_mongo_lock = Lock()


def get_mongo_db() -> Any | None:
    """Lazy Mongo database handle (Atlas). None on failure."""

    global _mongo_db
    if _mongo_db is not None:
        return _mongo_db
    with _mongo_lock:
        if _mongo_db is None:
            from pymongo import MongoClient

            for attempt in range(2):
                try:
                    _mongo_db = MongoClient(
                        config.MONGO_URI, serverSelectionTimeoutMS=5000, connectTimeoutMS=5000,
                    )[config.MONGO_DB_NAME]
                    break
                except Exception as exc:  # e.g. SRV DNS ConfigurationError
                    logger.warning("search catalog: Mongo unavailable (try %d): %s", attempt + 1, type(exc).__name__)
                    time.sleep(0.5)
            if _mongo_db is None:
                return None
    return _mongo_db


def _key(value: Any) -> str:
    return str(value).strip() if value is not None else ""


def build_product(raw: dict[str, Any], brands: dict[str, str], categories: dict[str, str],
                  category_keys: dict[str, list[str]] | None = None) -> dict[str, Any]:
    """Map a raw ``san_pham`` doc to the field names ``product_text`` understands.

    ``category_keys`` (optional): ``ma_danh_muc`` -> graph keys of the leaf and its ancestors,
    used by the semantic branch to boost the intent category (Bước 05B).
    """

    pid = _key(raw.get("ma_san_pham"))
    category = categories.get(_key(raw.get("ma_danh_muc"))) or _key(raw.get("danh_muc_day_du")).rstrip("> -")
    ingredients = _key(raw.get("thanh_phan_sach") or raw.get("thanh_phan_full"))
    stock = raw.get("so_luong_ton_kho")
    in_stock = not (raw.get("trang_thai_kho") == "het_hang" or (isinstance(stock, (int, float)) and stock <= 0))
    return {
        "id": pid,
        "ma_san_pham": pid,
        "ten_san_pham": _key(raw.get("ten_san_pham")),
        "name": _key(raw.get("ten_san_pham")),
        "thuong_hieu": brands.get(_key(raw.get("ma_thuong_hieu")), ""),
        "loai_san_pham": category,
        "category": category,
        "loai_da": "" if _key(raw.get("loai_da")).lower() == "unknown" else _key(raw.get("loai_da")),
        "thanh_phan_day_du": ingredients[:600],
        "sold": int(raw.get("so_luong_da_ban") or 0),
        "in_stock": in_stock,
        "curated": _key(raw.get("tag_source")).lower() in _CURATED_SOURCES,
        "price": _price(raw.get("gia_ban")),
        "category_keys": list((category_keys or {}).get(_key(raw.get("ma_danh_muc")), [])),
    }


def _price(value: Any) -> int | None:
    try:
        return int(float(value)) if value not in (None, "") else None
    except (TypeError, ValueError):
        return None


def category_key_map(rows: list[dict[str, Any]]) -> dict[str, list[str]]:
    """``ma_danh_muc`` -> [leaf key, parent key, ...] (keys as built by graph_sync)."""

    try:
        from graph_sync.normalize import group_synonyms, normalize_key

        syn = group_synonyms("categories")
    except Exception:  # pragma: no cover - YAML missing: no boost, never fail the catalog
        return {}
    by_id = {_key(r.get("ma_danh_muc")): r for r in rows}
    out: dict[str, list[str]] = {}
    for code, row in by_id.items():
        chain: list[str] = []
        current, seen = code, set()
        while current and current in by_id and current not in seen:
            seen.add(current)
            node = by_id[current]
            chain.append(normalize_key(node.get("ten_danh_muc"), syn))
            current = _key(node.get("parent_id"))
        out[code] = [k for k in chain if k]
    return out


def load_catalog(db: Any, ids: list[str] | None = None) -> list[dict[str, Any]]:
    """Visible catalog; ``ids`` (U11) restricts it to those ``ma_san_pham`` (int or str in Atlas)."""

    brands = {
        _key(b.get("ma_thuong_hieu")): _key(b.get("ten_thuong_hieu"))
        for b in db["thuong_hieu"].find({}, {"_id": 0, "ma_thuong_hieu": 1, "ten_thuong_hieu": 1})
    }
    category_rows = list(db["danh_muc"].find({}, {"_id": 0, "ma_danh_muc": 1, "ten_danh_muc": 1, "parent_id": 1}))
    categories = {_key(c.get("ma_danh_muc")): _key(c.get("ten_danh_muc")) for c in category_rows}
    keys = category_key_map(category_rows)
    query: dict[str, Any] = {"trang_thai": {"$nin": _HIDDEN}}
    if ids is not None:
        wanted: list[Any] = []
        for value in ids:
            wanted.append(str(value))
            if str(value).isdigit():
                wanted.append(int(value))
        query["ma_san_pham"] = {"$in": wanted}
    rows = db["san_pham"].find(query, _PROJECTION)
    return [p for p in (build_product(dict(r), brands, categories, keys) for r in rows) if p["id"]]


class CatalogStore:
    """In-process TTL snapshot of the visible catalog."""

    def __init__(self, loader=None, ttl_seconds: int = config.CATALOG_TTL) -> None:
        self._loader = loader
        self.ttl = ttl_seconds
        self._products: list[dict[str, Any]] = []
        self._by_id: dict[str, dict[str, Any]] = {}
        self._stock: dict[str, bool] = {}
        self._sold: dict[str, float] = {}
        self._curated: dict[str, bool] = {}
        self._texts: dict[str, str] = {}
        self._loaded_at = 0.0
        self.version = 0
        self._refreshing = False
        self._lock = Lock()

    def _load(self) -> list[dict[str, Any]]:
        if self._loader is not None:
            return self._loader()
        db = get_mongo_db()
        return load_catalog(db) if db is not None else []

    def _refresh(self) -> None:
        try:
            products = self._load()
        except Exception as exc:
            logger.warning("search catalog load degraded: %s", type(exc).__name__)
            products = []
        if products:
            self._products = products
            self._by_id = {p["id"]: p for p in products}
            self._stock = {p["id"]: bool(p.get("in_stock", True)) for p in products}
            self._sold = {p["id"]: float(p.get("sold") or 0) for p in products}
            self._curated = {p["id"]: True for p in products if p.get("curated")}
            self._texts = {p["id"]: f"{p.get('ten_san_pham', '')} - {p.get('loai_san_pham', '')}" for p in products}
            self._loaded_at = time.monotonic()
            self.version += 1

    def invalidate(self) -> None:
        """Mark the snapshot stale: the next read serves it once while a background reload runs."""

        self._loaded_at = 0.0

    def _background_refresh(self) -> None:
        try:
            self._refresh()
        finally:
            self._refreshing = False

    def products(self) -> list[dict[str, Any]]:
        """Return the snapshot; stale data is served while a background reload runs."""

        fresh = time.monotonic() - self._loaded_at < self.ttl
        if self._products and fresh:
            return self._products
        if self._products:
            if not self._refreshing:
                self._refreshing = True
                Thread(target=self._background_refresh, daemon=True).start()
            return self._products
        with self._lock:
            if not self._products:
                self._refresh()
        return self._products

    # ProductRepository-compatible hook used by recommendation_service.HybridSearch.
    def list_visible(self, limit: int = 0) -> list[dict[str, Any]]:
        return self.products()

    def get(self, product_id: str) -> dict[str, Any] | None:
        self.products()
        return self._by_id.get(product_id)

    def sold_map(self) -> dict[str, float]:
        self.products()
        return self._sold

    def curated_map(self) -> dict[str, bool]:
        """Product ids whose tags are human-curated (admin manual / approved LLM), U07."""

        self.products()
        return self._curated

    def stock_map(self) -> dict[str, bool]:
        self.products()
        return self._stock

    def text_map(self) -> dict[str, str]:
        self.products()
        return self._texts
