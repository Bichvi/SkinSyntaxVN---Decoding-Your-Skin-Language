from bson import ObjectId
from pymongo import MongoClient
from core.state_machine import get_db
from config import logger

def collect_product_and_snapshot(product_id) -> dict:
    """Query MongoDB for product details and build a historical snapshot."""
    logger.info(f"[PRODUCT-COLLECTOR] Collecting data for product_id: {product_id}")
    db = get_db()
    
    # 1. Build identifier filter query mimicking PHP buildProductIdQuery
    pid_str = str(product_id).strip()
    or_query = [
        {"ma_san_pham": pid_str},
        {"id": pid_str}
    ]
    
    try:
        pid_int = int(pid_str)
        or_query.append({"ma_san_pham": pid_int})
        or_query.append({"id": pid_int})
    except ValueError:
        pass

    if len(pid_str) == 24:
        try:
            or_query.append({"_id": ObjectId(pid_str)})
        except Exception:
            pass

    query = {"$or": or_query}
    
    # 2. Query san_pham collection
    product = db.san_pham.find_one(query)
    if not product:
        raise ValueError(f"Sản phẩm với ID {product_id} không tồn tại trong database.")

    # 3. Mux details (Brand, Category, Origin) to build joint view
    brand_name = ""
    category_name = ""
    origin_name = ""

    ma_thuong_hieu = product.get("ma_thuong_hieu")
    if ma_thuong_hieu is not None:
        brand = db.thuong_hieu.find_one({"ma_thuong_hieu": ma_thuong_hieu})
        if brand:
            brand_name = brand.get("ten_thuong_hieu", "")

    ma_danh_muc = product.get("ma_danh_muc")
    if ma_danh_muc is not None:
        cat = db.danh_muc.find_one({"ma_danh_muc": ma_danh_muc})
        if cat:
            category_name = cat.get("ten_danh_muc", "")

    ma_xuat_xu = product.get("ma_xuat_xu")
    if ma_xuat_xu is not None:
        origin = db.xuat_xu.find_one({"ma_xuat_xu": ma_xuat_xu})
        if origin:
            origin_name = origin.get("ten_xuat_xu", "")

    # 4. Resolve details into clean snapshot dictionary
    snapshot = {
        "id": str(product.get("ma_san_pham", product.get("id", product_id))),
        "name": product.get("ten_san_pham", "UNKNOWN"),
        "brand": brand_name or product.get("thuong_hieu", "UNKNOWN"),
        "category": category_name or product.get("loai_san_pham", "UNKNOWN"),
        "origin": origin_name or product.get("xuat_xu_thuong_hieu", "UNKNOWN"),
        "price": int(product.get("gia_ban", 0)),
        "sale_price": int(product.get("gia_khuyen_mai", product.get("gia_uu_dai", product.get("gia_ban", 0))) or 0),
        "image": product.get("link_hinh_anh", product.get("hinh_anh", "")),
        "ingredients": product.get("thanh_phan_chinh", product.get("thanh_phan", "UNKNOWN")),
        "description": product.get("mo_ta", "UNKNOWN"),
        "usage": product.get("hdsd", "UNKNOWN"),
        "skin_type": product.get("loai_da", "UNKNOWN"),
        "warnings": product.get("canh_bao", ""),
        "responsible_organization": product.get("to_chuc_chiu_trach_nhiem", ""),
        "promotion_valid_until": product.get("khuyen_mai_den_ngay"),
        "stock": int(product.get("so_luong_ton", product.get("ton_kho", 0)) or 0),
    }

    if "gia_thi_truong" in product and product["gia_thi_truong"]:
        try:
            snapshot["market_price"] = int(product["gia_thi_truong"])
        except ValueError:
            pass

    logger.info(f"[PRODUCT-COLLECTOR] Snapshot built for '{snapshot['name']}' ({snapshot['brand']})")
    return snapshot
