"""Product and user-data access with MySQL-first, MongoDB fallback behavior."""

from __future__ import annotations

import logging
import re
from typing import Any, Callable, Iterable

from . import config

logger = logging.getLogger(__name__)


def _int(value: Any, default: int = 0) -> int:
    try:
        if value in (None, ""):
            return default
        return int(float(str(value).replace(".", "").replace(",", "")))
    except (TypeError, ValueError):
        return default


def _text(value: Any) -> str:
    return str(value or "").strip()


def _first_image(value: Any) -> str:
    return next((item.strip() for item in _text(value).split("|") if item.strip()), "")


def normalize_product(raw: dict[str, Any]) -> dict[str, Any]:
    """Normalize SQL/Mongo aliases to one product record shape."""

    item = dict(raw or {})
    product_id = _text(item.get("product_id") or item.get("ma_san_pham") or item.get("id") or item.get("_id"))
    category = _text(
        item.get("category")
        or item.get("loai_san_pham")
        or item.get("danh_muc_day_du")
        or item.get("danh_muc")
    )
    main_ingredients = _text(item.get("thanh_phan_chinh") or item.get("ingredients") or item.get("thanh_phan"))
    full_ingredients = _text(
        item.get("thanh_phan_day_du")
        or item.get("thanh_phan_full")
        or item.get("thanh_phan_sach")
        or item.get("ingredients_full")
    )
    stock = item.get("stock", item.get("so_luong_ton", item.get("quantity")))
    stock_status = _text(item.get("stock_status") or "in_stock")
    if stock not in (None, "") and _int(stock) <= 0:
        stock_status = "out_of_stock"
    if _text(item.get("status") or item.get("trang_thai")).lower() in {
        "inactive", "hidden", "disabled", "off", "0", "tam_an", "ngung ban"
    }:
        stock_status = "hidden"

    return {
        "id": product_id,
        "ma_san_pham": product_id,
        "name": _text(item.get("name") or item.get("ten_san_pham")),
        "ten_san_pham": _text(item.get("ten_san_pham") or item.get("name")),
        "brand": _text(item.get("brand") or item.get("thuong_hieu") or item.get("ten_thuong_hieu")),
        "thuong_hieu": _text(item.get("thuong_hieu") or item.get("brand") or item.get("ten_thuong_hieu")),
        "category": category,
        "danh_muc": category,
        "loai_san_pham": category,
        "skin_type": _text(item.get("skin_type") or item.get("loai_da")),
        "loai_da": _text(item.get("loai_da") or item.get("skin_type")),
        "concerns": _text(item.get("concerns") or item.get("van_de_da")),
        "price": _int(item.get("price") or item.get("gia_ban")),
        "gia_ban": _int(item.get("gia_ban") or item.get("price")),
        "market_price": _int(item.get("market_price") or item.get("gia_thi_truong")),
        "discount_percent": float(item.get("discount_percent") or item.get("phan_tram_giam") or 0),
        "image": _first_image(item.get("image") or item.get("image_url") or item.get("link_hinh_anh")),
        "image_url": _first_image(item.get("image_url") or item.get("link_hinh_anh") or item.get("image")),
        "description": _text(item.get("description") or item.get("mo_ta")),
        "mo_ta": _text(item.get("mo_ta") or item.get("description")),
        "ingredients": main_ingredients,
        "thanh_phan_chinh": main_ingredients,
        "ingredients_full": full_ingredients,
        "thanh_phan_day_du": full_ingredients,
        "key_ingredients": [part.strip() for part in re.split(r"[,;|\n\r]+", main_ingredients) if part.strip()][:8],
        "rating": float(item.get("rating") or item.get("diem_danh_gia") or 0),
        "diem_danh_gia": float(item.get("diem_danh_gia") or item.get("rating") or 0),
        "stock": stock,
        "stock_status": stock_status,
        "popularity": _int(item.get("popularity") or item.get("luot_xem") or item.get("so_luong_ban")),
    }


def product_text(product: dict[str, Any]) -> str:
    """Build searchable text from factual product fields only."""

    return "\n".join(
        f"{label}: {product.get(field)}"
        for label, field in (
            ("Name", "ten_san_pham"),
            ("Brand", "thuong_hieu"),
            ("Category", "loai_san_pham"),
            ("Skin type", "loai_da"),
            ("Concerns", "concerns"),
            ("Ingredients", "thanh_phan_day_du"),
            ("Description", "mo_ta"),
        )
        if _text(product.get(field))
    )


class ProductRepository:
    """Read products and profile context without making the pipeline depend on one DB."""

    def __init__(
        self,
        mysql_connection_factory: Callable[[], Any] | None = None,
        mongo_database: Any | None = None,
    ) -> None:
        self._mysql_connection_factory = mysql_connection_factory
        self._mongo_database = mongo_database
        self._mysql_disabled = False
        self._mongo_disabled = False

    def _mysql_connection(self) -> Any | None:
        if self._mysql_disabled:
            return None
        try:
            if self._mysql_connection_factory is not None:
                return self._mysql_connection_factory()
            import pymysql

            return pymysql.connect(
                host=config.MYSQL_HOST,
                port=config.MYSQL_PORT,
                user=config.MYSQL_USER,
                password=config.MYSQL_PASSWORD,
                database=config.MYSQL_DATABASE,
                connect_timeout=config.MYSQL_CONNECT_TIMEOUT,
                read_timeout=config.MYSQL_READ_TIMEOUT,
                cursorclass=pymysql.cursors.DictCursor,
                autocommit=True,
            )
        except Exception as exc:
            logger.info("MySQL unavailable; using MongoDB fallback: %s", type(exc).__name__)
            self._mysql_disabled = True
            return None

    @staticmethod
    def _safe_identifier(value: str) -> str:
        if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", value):
            raise ValueError("unsafe SQL identifier")
        return value

    def _mysql_products(self, limit: int) -> list[dict[str, Any]]:
        connection = self._mysql_connection()
        if connection is None:
            return []
        table = self._safe_identifier(config.MYSQL_PRODUCTS_TABLE)
        cursor = None
        try:
            cursor = connection.cursor()
            # The project schema stores ingredient facts on san_pham. Optional
            # ingredient junction tables are intentionally not required at read time.
            query = f"""
                SELECT p.*, b.ten_thuong_hieu AS ten_thuong_hieu,
                       d.ten_danh_muc AS danh_muc_day_du
                FROM `{table}` p
                LEFT JOIN thuong_hieu b ON b.ma_thuong_hieu = p.ma_thuong_hieu
                LEFT JOIN danh_muc d ON d.ma_danh_muc = p.ma_danh_muc
                ORDER BY COALESCE(p.luot_xem, 0) DESC, p.ma_san_pham DESC
                LIMIT %s
            """
            try:
                cursor.execute(query, (max(1, int(limit)),))
            except Exception:
                # Some deployments use the project's older schema, which has
                # no product status column; the authoritative rows are still
                # normalized and filtered by stock/status aliases afterwards.
                cursor.execute(query.replace("ORDER BY", "WHERE 1 = 1 ORDER BY"), (max(1, int(limit)),))
            rows = cursor.fetchall() or []
            return [normalize_product(dict(row)) for row in rows]
        except Exception as exc:
            logger.warning("MySQL product query degraded: %s", type(exc).__name__)
            return []
        finally:
            try:
                if cursor is not None:
                    cursor.close()
                connection.close()
            except Exception:
                pass

    def _mongo_database_or_none(self) -> Any | None:
        if self._mongo_disabled:
            return None
        if self._mongo_database is not None:
            return self._mongo_database
        try:
            from pymongo import MongoClient

            self._mongo_database = MongoClient(
                config.MONGO_URI,
                serverSelectionTimeoutMS=350,
                connectTimeoutMS=350,
            )[config.MONGO_DB_NAME]
            self._mongo_database.command("ping")
            return self._mongo_database
        except Exception as exc:
            logger.info("MongoDB unavailable; recommendation will use empty catalog: %s", type(exc).__name__)
            self._mongo_disabled = True
            return None

    def _mongo_products(self, limit: int) -> list[dict[str, Any]]:
        database = self._mongo_database_or_none()
        if database is None:
            return []
        try:
            cursor = database[config.MONGO_COLLECTION].find(
                {"trang_thai": {"$nin": ["inactive", "hidden", "disabled", "0"]}},
                sort=[("luot_xem", -1), ("ma_san_pham", -1)],
                limit=max(1, int(limit)),
            )
            return [normalize_product(dict(row)) for row in cursor]
        except Exception as exc:
            logger.warning("MongoDB product query degraded: %s", type(exc).__name__)
            return []

    def list_visible(self, limit: int = 2000) -> list[dict[str, Any]]:
        """Return visible products, preferring MySQL and falling back to MongoDB."""

        products = self._mysql_products(limit)
        return products or self._mongo_products(limit)

    def get_by_ids(self, product_ids: Iterable[str]) -> dict[str, dict[str, Any]]:
        """Hydrate selected IDs from the authoritative product store."""

        wanted = {str(value).strip() for value in product_ids if str(value).strip()}
        if not wanted:
            return {}
        rows = self.list_visible(max(2000, len(wanted) * 4))
        return {str(row.get("id")): row for row in rows if str(row.get("id")) in wanted}

    def get_user_context(self, user_id: str, email: str = "") -> dict[str, Any]:
        """Load optional profile/history context; failures return an empty dict."""

        if not user_id and not email:
            return {}
        connection = self._mysql_connection()
        if connection is not None:
            cursor = None
            try:
                cursor = connection.cursor()
                if user_id and str(user_id).isdigit():
                    cursor.execute("SELECT * FROM khach_hang WHERE ma_kh = %s LIMIT 1", (int(user_id),))
                else:
                    cursor.execute("SELECT * FROM khach_hang WHERE email = %s LIMIT 1", (email,))
                row = cursor.fetchone()
                if row:
                    return dict(row)
            except Exception as exc:
                logger.info("MySQL profile lookup degraded: %s", type(exc).__name__)
            finally:
                try:
                    if cursor is not None:
                        cursor.close()
                    connection.close()
                except Exception:
                    pass

        database = self._mongo_database_or_none()
        if database is not None:
            try:
                clauses: list[dict[str, Any]] = []
                if user_id:
                    clauses.extend([{"ma_kh": user_id}, {"ma_kh": _int(user_id, -1)}])
                if email:
                    clauses.append({"email": re.compile("^" + re.escape(email) + "$", re.I)})
                return dict(database.khach_hang.find_one({"$or": clauses}) or {}) if clauses else {}
            except Exception as exc:
                logger.info("MongoDB profile lookup degraded: %s", type(exc).__name__)
        return {}

    def get_history(self, user_id: str, email: str = "") -> list[str]:
        """Return a small, privacy-minimized list of recent product names."""

        database = self._mongo_database_or_none()
        if database is None:
            return []
        try:
            customer = self.get_user_context(user_id, email)
            customer_id = customer.get("ma_kh") or user_id
            names: list[str] = []
            for order in database.hoa_don.find({"ma_kh": customer_id}, sort=[("ngay_dat", -1)], limit=3):
                for detail in database.chi_tiet_hoa_don.find({"ma_hoa_don": order.get("ma_hoa_don")}, limit=3):
                    product = database[config.MONGO_COLLECTION].find_one({"ma_san_pham": detail.get("ma_san_pham")})
                    name = _text((product or {}).get("ten_san_pham"))
                    if name and name not in names:
                        names.append(name)
            return names[:8]
        except Exception as exc:
            logger.info("History lookup degraded: %s", type(exc).__name__)
            return []
