from __future__ import annotations

import re
from typing import Any

from pymongo import MongoClient

from .config import MONGO_DB_NAME, MONGO_URI


def get_database():
    return MongoClient(MONGO_URI)[MONGO_DB_NAME]

# Các hàm tiện ích để chuyển đổi và chuẩn hóa dữ liệu sản phẩm từ MongoDB, bao gồm việc trích xuất thông tin, 
# xây dựng văn bản sản phẩm cho RAG context, tạo siêu dữ liệu sản phẩm và xây dựng bộ lọc MongoDB từ các tham số đầu vào.
def _to_int(value: Any, default: int = 0) -> int:
    try:
        if value is None or value == "":
            return default
        return int(float(str(value).replace(".", "").replace(",", "")))
    except Exception:
        return default

# Hàm này chuyển đổi giá trị đầu vào thành chuỗi, loại bỏ khoảng trắng và trả về chuỗi đã được chuẩn hóa.
def _text(value: Any) -> str:
    return str(value or "").strip()

# Hàm này trích xuất URL hình ảnh đầu tiên từ một chuỗi có thể chứa nhiều URL được phân tách bằng dấu "|".
def first_image(value: Any) -> str:
    parts = [p.strip() for p in str(value or "").split("|") if p.strip()]
    return parts[0] if parts else ""

# Hàm này chuẩn hóa các trường dữ liệu sản phẩm từ MongoDB thành định dạng JSON được sử dụng bởi giao diện người dùng PHP, 
# bao gồm việc trích xuất thông tin thương hiệu, danh mục, giá cả, loại da,
def normalize_product(raw: dict, db=None) -> dict:
    """Normalize MongoDB product fields into the JSON shape used by the PHP UI."""
    p = dict(raw or {})
    if db is None:
        db = get_database()

    brand = _text(p.get("thuong_hieu") or p.get("ten_thuong_hieu"))
    if not brand and p.get("ma_thuong_hieu") is not None:
        row = db.thuong_hieu.find_one({"ma_thuong_hieu": p.get("ma_thuong_hieu")})
        brand = _text((row or {}).get("ten_thuong_hieu"))

    category = _text(p.get("loai_san_pham") or p.get("danh_muc") or p.get("danh_muc_day_du"))
    if not category and p.get("ma_danh_muc") is not None:
        row = db.danh_muc.find_one({"ma_danh_muc": p.get("ma_danh_muc")})
        category = _text((row or {}).get("ten_danh_muc") or (row or {}).get("danh_muc_day_du"))

    product_id = _text(p.get("ma_san_pham") or p.get("id") or p.get("_id"))
    price = _to_int(p.get("gia_ban") or p.get("price"))
    rating = float(p.get("diem_danh_gia") or p.get("rating") or 0)
    sold_count = _to_int(p.get("so_luong_ban") or p.get("luot_mua") or p.get("sold_count"))
    popularity = max(
        sold_count,
        _to_int(p.get("luot_xem")),
        _to_int(p.get("so_luong_danh_gia")),
    )
    stock = None
    for field in ("so_luong_ton", "ton_kho", "stock", "quantity"):
        if field in p and p[field] not in (None, ""):
            stock = _to_int(p[field])
            break

    status_raw = _text(p.get("trang_thai") or p.get("status") or "active").lower()
    stock_status = "in_stock" if stock is None or stock > 0 else "out_of_stock"
    if status_raw in {"inactive", "hidden", "tam_an", "taman", "disabled", "off", "0", "ngung ban"}:
        stock_status = "hidden"

    return {
        "id": product_id,
        "ma_san_pham": product_id,
        "ten_san_pham": _text(p.get("ten_san_pham") or p.get("name")),
        "thuong_hieu": brand,
        "danh_muc": category,
        "loai_san_pham": category,
        "gia_ban": price,
        "loai_da": _text(p.get("loai_da") or p.get("skin_type")),
        "concerns": _text(p.get("concerns") or p.get("van_de_da")),
        "thanh_phan_chinh": _text(p.get("thanh_phan_chinh") or p.get("ingredients")),
        "thanh_phan_day_du": _text(p.get("thanh_phan_day_du")),
        "mo_ta": _text(p.get("mo_ta") or p.get("description")),
        "link_hinh_anh": _text(p.get("link_hinh_anh") or p.get("hinh_anh") or p.get("image_url")),
        "image_url": first_image(p.get("link_hinh_anh") or p.get("hinh_anh") or p.get("image_url")),
        "rating": rating,
        "diem_danh_gia": rating,
        "sold_count": sold_count,
        "popularity": popularity,
        "stock_status": stock_status,
    }

# Hàm này xây dựng một bộ lọc MongoDB để chỉ lấy các sản phẩm có trạng thái hiển thị và tồn kho phù hợp, 
# có thể kết hợp với các điều kiện bổ sung nếu được cung cấp.
def visible_filter(extra: dict | None = None) -> dict:
    stock_fields = ("so_luong_ton", "ton_kho", "stock", "quantity")
    missing_all_stock = [{field: {"$exists": False}} for field in stock_fields]
    positive_stock = [{field: {"$gt": 0}} for field in stock_fields]
    base = {
        "$and": [
            {"trang_thai": {"$nin": ["inactive", "hidden", "tam_an", "taman", "disabled", "off", "0"]}},
            {"$or": [{"$and": missing_all_stock}, *positive_stock]},
        ]
    }
    if extra:
        return {"$and": [base, extra]}
    return base

# Hàm này xây dựng văn bản sản phẩm được sử dụng làm ngữ cảnh RAG (Retrieval-Augmented Generation) cho hệ thống recommendation,
# bằng cách kết hợp các trường thông tin quan trọng của sản phẩm như tên, thương hiệu, danh mục, giá cả, loại da, vấn đề da, 
# thành phần chính, mô tả
def build_product_text(product: dict) -> str:
    # Product text is the RAG context. The API only returns MongoDB product records from these docs.
    fields = [
        ("Name", product.get("ten_san_pham")),
        ("Brand", product.get("thuong_hieu")),
        ("Category", product.get("danh_muc")),
        ("Price", product.get("gia_ban")),
        ("Skin type", product.get("loai_da")),
        ("Concerns", product.get("concerns")),
        ("Ingredients", product.get("thanh_phan_chinh") or product.get("thanh_phan_day_du")),
        ("Description", product.get("mo_ta")),
        ("Rating", product.get("rating")),
        ("Sold count", product.get("sold_count")),
        ("Popularity", product.get("popularity")),
    ]
    return "\n".join(f"{label}: {value}" for label, value in fields if _text(value) != "")

# Hàm này tạo một đối tượng siêu dữ liệu sản phẩm chứa các trường thông tin quan trọng như ID sản phẩm, 
# giá cả, thương hiệu, danh mục, loại da, vấn đề da, điểm đánh giá, số lượng đã bán, trạng thái tồn kho và độ phổ biến.
def product_metadata(product: dict) -> dict:
    return {
        "product_id": product["id"],
        "price": product["gia_ban"],
        "brand": product["thuong_hieu"],
        "category": product["danh_muc"],
        "skin_type": product["loai_da"],
        "concerns": product["concerns"],
        "rating": product["rating"],
        "sold_count": product["sold_count"],
        "stock_status": product["stock_status"],
        "popularity": product["popularity"],
    }

# Hàm này xây dựng một bộ lọc MongoDB từ các tham số đầu vào, cho phép lọc sản phẩm dựa trên khoảng giá, 
# danh mục, thương hiệu, loại da, vấn đề da và trạng thái tồn kho.
def mongo_filter_from_params(params: dict) -> dict:
    clauses: list[dict] = []
    price = {}
    if params.get("price_min") is not None:
        price["$gte"] = _to_int(params.get("price_min"))
    if params.get("price_max") is not None:
        price["$lte"] = _to_int(params.get("price_max"))
    if price:
        clauses.append({"gia_ban": price})

    regex_fields = {
        "category": "danh_muc_day_du",
        "brand": "ten_thuong_hieu",
        "skin_type": "loai_da",
        "concerns": "van_de_da",
    }
    for key, field in regex_fields.items():
        value = _text(params.get(key))
        if value:
            if key == "brand":
                clauses.append({"$or": [{"thuong_hieu": re.compile(re.escape(value), re.I)}, {"ten_thuong_hieu": re.compile(re.escape(value), re.I)}]})
            else:
                clauses.append({field: re.compile(re.escape(value), re.I)})

    status = _text(params.get("stock_status"))
    if status == "in_stock":
        clauses.append(visible_filter())

    return {"$and": clauses} if clauses else {}
