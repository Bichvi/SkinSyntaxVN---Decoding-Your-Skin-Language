"""Phân loại sản phẩm theo category của Agent và chọn sản phẩm khôi phục từ bản backup."""
from __future__ import annotations

import random
from collections import defaultdict

from agents.command_query import SALES_FIELDS, _category_matches, _product_id, first_number, number
from agents.intent_parser import normalize_text

from . import config as cfg

# Từ cụ thể đến chung: lá của Builder trước, rồi nhóm cha.
CLASSIFY_ORDER = (
    "sheet_mask", "wash_off_mask", "body_sunscreen", "eye_cream", "makeup_remover", "exfoliant", "moisturizer",
    "lip_gloss", "lipstick", "concealer", "makeup_base", "shampoo", "conditioner", "hair_mask", "hair_care",
    "body_wash", "body_lotion", "sunscreen", "cleanser", "serum", "foundation",
    "hydration", "mask", "eye_care", "face_cleansing", "lip_makeup", "makeup", "hair_wash", "hair", "body", "face",
)
# Danh mục của catalog mà tên sản phẩm không nhất thiết chứa từ khoá của alias.
PATH_RULES = {"sunscreen": "chong nang da mat", "cleanser": "sua rua mat", "serum": "serum / tinh chat"}
RESTORE_ROOTS = ("trang diem", "cham soc co the", "cham soc toc va da dau")
RESTORE_KEYS = frozenset({
    "lipstick", "lip_gloss", "makeup_base", "concealer", "body_wash", "body_lotion", "body_sunscreen",
    "shampoo", "conditioner", "hair_mask", "hair_care",
})


def product_key(row: dict) -> str:
    return _product_id(row)


def _path(row: dict) -> str:
    return normalize_text(" ".join(str(row.get(k) or "") for k in ("loai_san_pham", "danh_muc_day_du", "category")))


def classify_product(row: dict) -> str | None:
    """Category cụ thể nhất mà catalog chứng minh được; None nếu không thuộc nhóm nào của Builder."""
    path = _path(row)
    for key in CLASSIFY_ORDER:
        if key in PATH_RULES and PATH_RULES[key] in path:
            return key
        if _category_matches(row, key):
            return key
    return None


def shelf_life_months(row: dict) -> int:
    """Hạn dùng theo category; vitamin C và mascara ngắn hơn."""
    category = classify_product(row)
    months = cfg.SHELF_LIFE_MONTHS.get(category, cfg.DEFAULT_SHELF_LIFE_MONTHS)
    text = normalize_text(" ".join(str(row.get(k) or "") for k in ("ten_san_pham", "thanh_phan_chinh")))
    if category == "serum" and ("vitamin c" in text or "ascorbic" in text):
        return 24
    if "mascara" in text:
        return 24
    return months


def popularity_prior(row: dict) -> float:
    """so_luong_da_ban^0.8 x (diem_danh_gia/5), luôn dương và tăng theo số đã bán."""
    sold = first_number(row, SALES_FIELDS) or 0.0
    rating = number(row.get("diem_danh_gia"))
    rating = 3.5 if rating is None or rating <= 0 else min(rating, 5.0)
    return (sold + 1.0) ** 0.8 * (rating / 5.0)


def canonical_id(value) -> str:
    try:
        return str(int(float(str(value).strip())))
    except (TypeError, ValueError):
        return str(value).strip()


def _second_segment(row: dict) -> str:
    parts = [p.strip() for p in str(row.get("danh_muc_day_du") or "").split("->")]
    return normalize_text(parts[1]) if len(parts) > 1 else ""


def pick_restore_candidates(backup_rows, live_ids, per_leaf: int = 22, rng: random.Random | None = None) -> list[dict]:
    """Chọn tối đa ``per_leaf`` sản phẩm thật mỗi nhánh lá (Trang điểm / Cơ thể / Tóc) chưa có trong catalog live."""
    rng = rng or random.Random(cfg.DEFAULT_SEED)
    live = {canonical_id(x) for x in live_ids}
    leaves: dict[str, list[dict]] = defaultdict(list)
    seen: set[str] = set()
    for row in sorted(backup_rows, key=lambda r: (canonical_id(product_key(r)), str(r.get("danh_muc_day_du")))):
        pid = canonical_id(product_key(row))
        price = number(row.get("gia_ban"))
        if not pid or pid in live or pid in seen or not str(row.get("ten_san_pham") or "").strip():
            continue
        if price is None or price <= 0 or _second_segment(row) not in RESTORE_ROOTS:
            continue
        if classify_product(row) not in RESTORE_KEYS:
            continue
        seen.add(pid)
        leaves[str(row.get("danh_muc_day_du"))].append(row)
    picked: list[dict] = []
    for leaf in sorted(leaves):
        # Efraimidis-Spirakis: lấy mẫu có trọng số, không lặp, ổn định theo seed.
        scored = sorted(leaves[leaf], key=lambda r: -(rng.random() ** (1.0 / popularity_prior(r))))
        picked.extend(dict(r) for r in scored[:per_leaf])
    return picked
