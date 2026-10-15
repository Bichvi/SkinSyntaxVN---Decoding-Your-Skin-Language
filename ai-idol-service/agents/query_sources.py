"""Mongo read adapter. Limits fail closed, never rank a silently truncated catalog."""
from __future__ import annotations


def bounded_rows(cursor, maximum: int, label: str) -> list[dict]:
    rows = list(cursor.limit(maximum + 1))
    if len(rows) > maximum:
        raise ValueError(f"{label} vượt giới hạn truy vấn {maximum}; cần thu hẹp hoặc dùng aggregation có index.")
    return rows


def product_id_filter(product_id: object) -> dict:
    """Mongo filter matching every identifier form PHP/product_collector accept."""
    pid = str(product_id).strip()
    clauses: list[dict] = [{"ma_san_pham": pid}, {"id": pid}]
    try:
        number = int(pid)
        clauses += [{"ma_san_pham": number}, {"id": number}]
    except ValueError:
        pass
    if len(pid) == 24:
        try:
            from bson import ObjectId
            clauses.append({"_id": ObjectId(pid)})
        except Exception:
            pass
    return {"$or": clauses}


def _brand_key(value: object) -> str | None:
    """Canonical key for ma_thuong_hieu (Int64, "7" and 7.0 are the same brand)."""
    try:
        return str(int(float(str(value).strip())))
    except (TypeError, ValueError):
        return None


def load_catalog(db) -> list[dict]:
    rows = _load_catalog_rows(db)
    # san_pham only stores ma_thuong_hieu; the brand name lives in thuong_hieu.
    brands = {_brand_key(b.get("ma_thuong_hieu")): b.get("ten_thuong_hieu") for b in bounded_rows(
        db.thuong_hieu.find({}, {"_id": 0, "ma_thuong_hieu": 1, "ten_thuong_hieu": 1}), 5000, "Thương hiệu")}
    for row in rows:
        if row.get("thuong_hieu") or row.get("ten_thuong_hieu"):
            continue
        key = _brand_key(row.get("ma_thuong_hieu"))
        name = brands.get(key) if key is not None else None
        if name:
            row["ten_thuong_hieu"] = name
    return rows


def _load_catalog_rows(db) -> list[dict]:
    return bounded_rows(db.san_pham.find({}, {
        "ma_san_pham": 1, "id": 1, "ten_san_pham": 1, "thuong_hieu": 1, "ten_thuong_hieu": 1, "ma_thuong_hieu": 1,
        "loai_san_pham": 1, "danh_muc_day_du": 1, "ma_danh_muc": 1, "trang_thai": 1, "status": 1,
        "link_hinh_anh": 1, "hinh_anh": 1,
        "thanh_phan_chinh": 1, "thanh_phan_clean": 1, "thanh_phan_day_du": 1,
        "mo_ta": 1, "gia_ban": 1, "gia_thi_truong": 1,
        "so_luong_ton_kho": 1, "so_luong_ton": 1, "ton_kho": 1, "stock": 1, "quantity": 1,
        "so_luong_ban": 1, "so_luong_da_ban": 1, "luot_ban": 1,
        "expiry_date": 1, "ngay_het_han": 1, "diem_danh_gia": 1, "so_luong_danh_gia": 1,
    }), 20000, "Catalog")


def load_query_sources(db, plan: dict) -> tuple[list[dict], list[dict]]:
    batches = []
    snapshots = []
    if plan["expiry_required"] or plan["stock_min"] is not None or plan["stock_max"] is not None or plan.get("warehouse_id") or plan.get("batch_id"):
        batches = bounded_rows(db.inventory_batches.find({}, {
            "product_id": 1, "batch_id": 1, "batch_code": 1, "warehouse_id": 1,
            "expires_on": 1, "available_quantity": 1, "status": 1,
        }), 50000, "Lô hàng")
    if plan["window_days"] and plan["rank_by"] in {"units", "cart", "growth", "discount_slow", "views_no_orders", "revenue_low_conversion"}:
        snapshots = bounded_rows(db.product_metrics_snapshots.find({
            "as_of": plan["as_of"], "window_days": plan["window_days"],
        }, {"_id": 0}), 20000, "Thống kê bán hàng")
    return batches, snapshots
