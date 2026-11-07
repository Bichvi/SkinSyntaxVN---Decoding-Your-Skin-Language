"""Hằng số của bộ dữ liệu pb-fixture-v1."""
from __future__ import annotations

DATASET_TAG = "pb-fixture-v1"
DATA_SOURCE = "pb_fixture"
DEFAULT_SEED = 20261014

WAREHOUSES = {"KHO-HCM-01": 0.6, "KHO-HN-01": 0.3, "KHO-DN-01": 0.1}
SCOPE_WAREHOUSE = "KHO-DN-01"
REGION_WAREHOUSE = {"south": "KHO-HCM-01", "north": "KHO-HN-01", "central": "KHO-DN-01"}

# Dải ID riêng, tách khỏi dữ liệu thật.
ID_BASE = {"hoa_don": 500001, "chi_tiet_hoa_don": 500001, "khach_hang": 900001}

# Hạn dùng (tháng kể từ ngày sản xuất) theo category của command_query.CATEGORY_PATHS / CATEGORY_ALIASES.
SHELF_LIFE_MONTHS = {
    "sunscreen": 36, "cleanser": 36, "serum": 30, "foundation": 36,
    "face": 30, "face_cleansing": 36, "makeup_remover": 36, "exfoliant": 30, "hydration": 30,
    "moisturizer": 30, "mask": 36, "sheet_mask": 36, "wash_off_mask": 30, "eye_care": 24, "eye_cream": 24,
    "body_sunscreen": 36, "makeup": 36, "makeup_base": 36, "concealer": 36, "lip_makeup": 36,
    "lipstick": 36, "lip_gloss": 36, "body": 36, "body_wash": 36, "body_lotion": 36,
    "hair": 36, "hair_wash": 36, "shampoo": 36, "hair_care": 36, "conditioner": 36, "hair_mask": 30,
}
DEFAULT_SHELF_LIFE_MONTHS = 30

FOCUS_CATEGORIES = ("sunscreen", "cleanser", "serum", "sheet_mask", "shampoo")
FOCUS_BRANDS = ("Cocoon", "La Roche-Posay", "L'Oreal")

# Cửa sổ thời gian (ngày, tính lùi từ T = ngày chạy).
ORDER_DAYS = 60
SESSION_DAYS = 45


def tag(doc: dict) -> dict:
    """Gắn 3 trường bắt buộc lên mọi document fixture."""
    doc["test_dataset"] = DATASET_TAG
    doc["data_source"] = DATA_SOURCE
    doc["needs_verification"] = True
    return doc
