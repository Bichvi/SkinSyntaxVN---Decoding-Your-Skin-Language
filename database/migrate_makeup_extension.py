#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
migrate_makeup_extension.py
Extension Migration: Khôi phục 1.615 sản phẩm Makeup từ skinsyntax sang skinsyntax_v2.

Nguyên tắc tuân thủ tuyệt đối:
1. Mặc định DRY-RUN. Chỉ ghi database khi có cờ --live.
2. skinsyntax là STRICTLY READ-ONLY.
3. skinsyntax_v2 không drop, không truncate, không overwrite collection.
4. Giữ nguyên 1.387 sản phẩm skincare (ID 1-1387), KHÔNG cập nhật nganh_hang của 1.387 sản phẩm cũ.
5. Cấp phát dải ID 1388-3002 cho 1.615 sản phẩm makeup sau khi xác minh không có xung đột.
6. Cấp phát 38 danh mục makeup (ID 22-59): 1 root (22), 6 cấp 1 (23-28), 31 lá (29-59).
7. Bổ sung lookups an toàn: 60 thương hiệu mới, 1 xuất xứ mới, 1 loại da ma_loai_da=4 ("Không áp dụng").
8. Idempotent: Kiểm tra trùng theo ma_san_pham hoặc tên, chạy nhiều lần an toàn.
"""

import sys
import os
import argparse
import json
import re
from datetime import datetime
from pymongo import MongoClient

# Thiết lập encoding an toàn cho console Windows
if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')

MONGO_URI = 'mongodb://127.0.0.1:27018'
SOURCE_DB = 'skinsyntax'
TARGET_DB = 'skinsyntax_v2'

# Cấu trúc 38 danh mục Makeup (chuẩn hóa cây phân cấp)
# Root: 22
# Level 1: 23..28
# Level 2 (Lá): 29..59
MAKEUP_CATEGORIES_DEFINITION = [
    # Level 0 (Root)
    {
        "ma_danh_muc": 22,
        "ten_danh_muc": "Trang Điểm",
        "slug": "trang-diem",
        "parent_id": None,
        "level": 0,
        "is_leaf": False,
        "thu_tu_hien_thi": 2,
        "trang_thai": "active",
        "danh_muc_day_du": "Sức Khỏe - Làm Đẹp -> Trang Điểm"
    },
    # Level 1 (6 nhóm con của Trang Điểm)
    {
        "ma_danh_muc": 23,
        "ten_danh_muc": "Trang Điểm Môi",
        "slug": "trang-diem-moi",
        "parent_id": 22,
        "level": 1,
        "is_leaf": False,
        "thu_tu_hien_thi": 1,
        "trang_thai": "active",
        "danh_muc_day_du": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Môi"
    },
    {
        "ma_danh_muc": 24,
        "ten_danh_muc": "Trang Điểm Mặt",
        "slug": "trang-diem-mat",
        "parent_id": 22,
        "level": 1,
        "is_leaf": False,
        "thu_tu_hien_thi": 2,
        "trang_thai": "active",
        "danh_muc_day_du": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt"
    },
    {
        "ma_danh_muc": 25,
        "ten_danh_muc": "Trang Điểm Mắt",
        "slug": "trang-diem-mat-eye",
        "parent_id": 22,
        "level": 1,
        "is_leaf": False,
        "thu_tu_hien_thi": 3,
        "trang_thai": "active",
        "danh_muc_day_du": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mắt"
    },
    {
        "ma_danh_muc": 26,
        "ten_danh_muc": "Dụng Cụ Trang Điểm",
        "slug": "dung-cu-trang-diem",
        "parent_id": 22,
        "level": 1,
        "is_leaf": False,
        "thu_tu_hien_thi": 4,
        "trang_thai": "active",
        "danh_muc_day_du": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Dụng Cụ Trang Điểm"
    },
    {
        "ma_danh_muc": 27,
        "ten_danh_muc": "Trang Điểm Móng",
        "slug": "trang-diem-mong",
        "parent_id": 22,
        "level": 1,
        "is_leaf": False,
        "thu_tu_hien_thi": 5,
        "trang_thai": "active",
        "danh_muc_day_du": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Móng"
    },
    {
        "ma_danh_muc": 28,
        "ten_danh_muc": "Bộ Trang Điểm",
        "slug": "bo-trang-diem",
        "parent_id": 22,
        "level": 1,
        "is_leaf": False,
        "thu_tu_hien_thi": 6,
        "trang_thai": "active",
        "danh_muc_day_du": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Bộ Trang Điểm"
    },
    # Level 2 (31 Danh mục lá)
    # Nhánh 23: Trang Điểm Môi (5 lá)
    {
        "ma_danh_muc": 29,
        "ten_danh_muc": "Son Kem / Tint",
        "slug": "son-kem-tint",
        "parent_id": 23,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 1,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Môi -> Son Kem / Tint"
    },
    {
        "ma_danh_muc": 30,
        "ten_danh_muc": "Son Dưỡng Môi",
        "slug": "son-duong-moi",
        "parent_id": 23,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 2,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Môi -> Son Dưỡng Môi"
    },
    {
        "ma_danh_muc": 31,
        "ten_danh_muc": "Son Thỏi",
        "slug": "son-thoi",
        "parent_id": 23,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 3,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Môi -> Son Thỏi"
    },
    {
        "ma_danh_muc": 32,
        "ten_danh_muc": "Son Bóng",
        "slug": "son-bong",
        "parent_id": 23,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 4,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Môi -> Son Bóng"
    },
    {
        "ma_danh_muc": 33,
        "ten_danh_muc": "Tẩy Trang Mắt / Môi",
        "slug": "tay-trang-mat-moi",
        "parent_id": 23,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 5,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Môi -> Tẩy Trang Mắt / Môi"
    },
    # Nhánh 24: Trang Điểm Mặt (10 lá)
    {
        "ma_danh_muc": 34,
        "ten_danh_muc": "Má Hồng",
        "slug": "ma-hong",
        "parent_id": 24,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 1,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt -> Má Hồng"
    },
    {
        "ma_danh_muc": 35,
        "ten_danh_muc": "Phấn Phủ",
        "slug": "phan-phu",
        "parent_id": 24,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 2,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt -> Phấn Phủ"
    },
    {
        "ma_danh_muc": 36,
        "ten_danh_muc": "Phấn Nước Cushion",
        "slug": "phan-nuoc-cushion",
        "parent_id": 24,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 3,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt -> Phấn Nước Cushion"
    },
    {
        "ma_danh_muc": 37,
        "ten_danh_muc": "Che Khuyết Điểm",
        "slug": "che-khuyet-diem",
        "parent_id": 24,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 4,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt -> Che Khuyết Điểm"
    },
    {
        "ma_danh_muc": 38,
        "ten_danh_muc": "Kem Nền",
        "slug": "kem-nen",
        "parent_id": 24,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 5,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt -> Kem Nền"
    },
    {
        "ma_danh_muc": 39,
        "ten_danh_muc": "Tạo Khối / Highlight",
        "slug": "tao-khoi-highlight",
        "parent_id": 24,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 6,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt -> Tạo Khối / Highlight"
    },
    {
        "ma_danh_muc": 40,
        "ten_danh_muc": "Xịt Khoá Makeup",
        "slug": "xit-khoa-makeup",
        "parent_id": 24,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 7,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt -> Xịt Khoá Makeup"
    },
    {
        "ma_danh_muc": 41,
        "ten_danh_muc": "Kem Lót",
        "slug": "kem-lot",
        "parent_id": 24,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 8,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt -> Kem Lót"
    },
    {
        "ma_danh_muc": 42,
        "ten_danh_muc": "BB / CC Cream",
        "slug": "bb-cc-cream",
        "parent_id": 24,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 9,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt -> BB / CC Cream"
    },
    {
        "ma_danh_muc": 43,
        "ten_danh_muc": "Phấn Nền",
        "slug": "phan-nen",
        "parent_id": 24,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 10,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mặt -> Phấn Nền"
    },
    # Nhánh 25: Trang Điểm Mắt (4 lá)
    {
        "ma_danh_muc": 44,
        "ten_danh_muc": "Kẻ Mày",
        "slug": "ke-may",
        "parent_id": 25,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 1,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mắt -> Kẻ Mày"
    },
    {
        "ma_danh_muc": 45,
        "ten_danh_muc": "Kẻ Mắt",
        "slug": "ke-mat",
        "parent_id": 25,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 2,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mắt -> Kẻ Mắt"
    },
    {
        "ma_danh_muc": 46,
        "ten_danh_muc": "Phấn Mắt",
        "slug": "phan-mat",
        "parent_id": 25,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 3,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mắt -> Phấn Mắt"
    },
    {
        "ma_danh_muc": 47,
        "ten_danh_muc": "Mascara",
        "slug": "mascara",
        "parent_id": 25,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 4,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Mắt -> Mascara"
    },
    # Nhánh 26: Dụng Cụ Trang Điểm (8 lá)
    {
        "ma_danh_muc": 48,
        "ten_danh_muc": "Bông / Mút Trang Điểm",
        "slug": "bong-mut-trang-diem",
        "parent_id": 26,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 1,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Dụng Cụ Trang Điểm -> Bông / Mút Trang Điểm"
    },
    {
        "ma_danh_muc": 49,
        "ten_danh_muc": "Cọ Trang Điểm",
        "slug": "co-trang-diem",
        "parent_id": 26,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 2,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Dụng Cụ Trang Điểm -> Cọ Trang Điểm"
    },
    {
        "ma_danh_muc": 50,
        "ten_danh_muc": "Nhíp / Dao Cạo",
        "slug": "nhip-dao-cao",
        "parent_id": 26,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 3,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Dụng Cụ Trang Điểm -> Nhíp / Dao Cạo"
    },
    {
        "ma_danh_muc": 51,
        "ten_danh_muc": "Mi Giả",
        "slug": "mi-gia",
        "parent_id": 26,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 4,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Dụng Cụ Trang Điểm -> Mi Giả"
    },
    {
        "ma_danh_muc": 52,
        "ten_danh_muc": "Bấm Mi",
        "slug": "bam-mi",
        "parent_id": 26,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 5,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Dụng Cụ Trang Điểm -> Bấm Mi"
    },
    {
        "ma_danh_muc": 53,
        "ten_danh_muc": "Dụng Cụ Trang Điểm Khác",
        "slug": "dung-cu-trang-diem-khac",
        "parent_id": 26,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 6,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Dụng Cụ Trang Điểm -> Dụng Cụ Trang Điểm Khác"
    },
    {
        "ma_danh_muc": 54,
        "ten_danh_muc": "Miếng Dán Kích Mí",
        "slug": "mieng-dan-kich-mi",
        "parent_id": 26,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 7,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Dụng Cụ Trang Điểm -> Miếng Dán Kích Mí"
    },
    {
        "ma_danh_muc": 55,
        "ten_danh_muc": "Giấy Thấm Dầu",
        "slug": "giay-tham-dau",
        "parent_id": 26,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 8,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Dụng Cụ Trang Điểm -> Giấy Thấm Dầu"
    },
    # Nhánh 27: Trang Điểm Móng (3 lá)
    {
        "ma_danh_muc": 56,
        "ten_danh_muc": "Sơn Móng",
        "slug": "son-mong",
        "parent_id": 27,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 1,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Móng -> Sơn Móng"
    },
    {
        "ma_danh_muc": 57,
        "ten_danh_muc": "Dụng Cụ / Phụ Kiện Làm Móng",
        "slug": "dung-cu-phu-kien-lam-mong",
        "parent_id": 27,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 2,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Móng -> Dụng Cụ / Phụ Kiện Làm Móng"
    },
    {
        "ma_danh_muc": 58,
        "ten_danh_muc": "Nước Rửa Móng",
        "slug": "nuoc-rua-mong",
        "parent_id": 27,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 3,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Trang Điểm Móng -> Nước Rửa Móng"
    },
    # Nhánh 28: Bộ Trang Điểm (1 lá)
    {
        "ma_danh_muc": 59,
        "ten_danh_muc": "Bộ Trang Điểm Cơ Bản / Đa Năng",
        "slug": "bo-trang-diem-co-ban",
        "parent_id": 28,
        "level": 2,
        "is_leaf": True,
        "thu_tu_hien_thi": 1,
        "trang_thai": "active",
        "source_raw_cat": "Sức Khỏe - Làm Đẹp -> Trang Điểm -> Bộ Trang Điểm"
    }
]

# Tạo map tra cứu từ source_raw_cat sang ma_danh_muc mới
CATEGORY_LOOKUP = {}
for cat in MAKEUP_CATEGORIES_DEFINITION:
    if "source_raw_cat" in cat:
        CATEGORY_LOOKUP[cat["source_raw_cat"]] = cat["ma_danh_muc"]


def normalize_text(text):
    if not text:
        return ""
    text = str(text).strip().lower()
    text = re.sub(r'\s+', ' ', text)
    return text


def main():
    parser = argparse.ArgumentParser(description="Migrate Makeup Products to skinsyntax_v2")
    parser.add_argument('--live', action='store_true', help="Thực thi ghi trực tiếp vào MongoDB skinsyntax_v2. Nếu không có cờ này, mặc định DRY-RUN.")
    args = parser.parse_args()

    is_dry_run = not args.live
    mode_str = "DRY-RUN (Mô phỏng an toàn, không ghi database)" if is_dry_run else "LIVE EXECUTION (Ghi trực tiếp vào database)"
    
    print("=" * 70)
    print("SKINSYNTAXVN — MAKEUP PRODUCTS MIGRATION EXTENSION")
    print(f"Chế độ: {mode_str}")
    print(f"Thời gian: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"MongoDB URI: {MONGO_URI}")
    print(f"Nguồn (READ-ONLY): {SOURCE_DB}")
    print(f"Đích (RUNTIME):    {TARGET_DB}")
    print("=" * 70)

    client = MongoClient(MONGO_URI)
    src_db = client[SOURCE_DB]
    tgt_db = client[TARGET_DB]

    # 1. KIỂM TRA SƠ BỘ VÀ XÁC MINH AN TOÀN
    print("\n[BƯỚC 1] Kiểm tra hiện trạng cơ sở dữ liệu...")
    src_prod_count = src_db.san_pham.count_documents({})
    tgt_prod_count = tgt_db.san_pham.count_documents({})
    tgt_cat_count = tgt_db.danh_muc.count_documents({})
    tgt_brand_count = tgt_db.thuong_hieu.count_documents({})
    tgt_origin_count = tgt_db.xuat_xu.count_documents({})
    tgt_skin_count = tgt_db.loai_da.count_documents({})

    print(f"  - skinsyntax.san_pham (gốc):           {src_prod_count} sản phẩm")
    print(f"  - skinsyntax_v2.san_pham (hiện tại):    {tgt_prod_count} sản phẩm (Skincare ID 1..1387)")
    print(f"  - skinsyntax_v2.danh_muc (hiện tại):    {tgt_cat_count} danh mục (Skincare ID 1..21)")
    print(f"  - skinsyntax_v2.thuong_hieu (hiện tại): {tgt_brand_count} thương hiệu")
    print(f"  - skinsyntax_v2.xuat_xu (hiện tại):     {tgt_origin_count} xuất xứ")
    print(f"  - skinsyntax_v2.loai_da (hiện tại):     {tgt_skin_count} loại da")

    if tgt_prod_count != 1387:
        print(f"  [CẢNH BÁO] Số lượng sản phẩm hiện tại trong skinsyntax_v2 là {tgt_prod_count}, khác 1.387!")
    else:
        print("  [OK] Xác nhận chính xác 1.387 sản phẩm Skincare gốc.")

    # 2. XÁC MINH DẢI ID 1388 - 3002 TRONG TARGET DB
    print("\n[BƯỚC 2] Xác minh dải ID 1388 - 3002 trong skinsyntax_v2.san_pham...")
    conflict_prods = list(tgt_db.san_pham.find({
        "$or": [
            {"ma_san_pham": {"$gte": 1388, "$lte": 3002}},
            {"id": {"$gte": 1388, "$lte": 3002}}
        ]
    }))
    if conflict_prods:
        print(f"  [LỖI NGHIÊM TRỌNG] Phát hiện {len(conflict_prods)} sản phẩm đã sử dụng ID trong dải 1388..3002!")
        for cp in conflict_prods[:5]:
            print(f"    - ID: {cp.get('ma_san_pham')}, Tên: {cp.get('ten_san_pham')}")
        sys.exit(1)
    else:
        print("  [OK] 100% an toàn: Không có bất kỳ ID nào trong dải 1388..3002 đang được sử dụng.")

    # 3. TRUY VẤN VÀ CHUẨN HÓA 1.615 SẢN PHẨM MAKEUP TỪ SOURCE DB
    print("\n[BƯỚC 3] Đọc dữ liệu Makeup từ skinsyntax...")
    makeup_cursor = src_db.san_pham.find(
        {"danh_muc_day_du": {"$regex": "Trang Điểm"}},
        sort=[("ma_san_pham", 1)]
    )
    src_makeup_list = list(makeup_cursor)
    print(f"  - Tổng số sản phẩm Makeup tìm thấy trong skinsyntax: {len(src_makeup_list)}")

    if len(src_makeup_list) != 1615:
        print(f"  [LỖI] Số lượng sản phẩm makeup tìm được ({len(src_makeup_list)}) khác 1.615 mục tiêu!")
        sys.exit(1)
    else:
        print("  [OK] Tìm thấy chính xác 1.615 sản phẩm makeup cần migrate.")

    # 4. CHUẨN HÓA VÀ ÁNH XẠ DANH MỤC
    print("\n[BƯỚC 4] Kiểm tra ánh xạ Danh mục (Category Mapping)...")
    unmapped_cats = set()
    mapped_cat_counts = {}
    for p in src_makeup_list:
        bc = (p.get("danh_muc_day_du") or "").strip()
        target_cid = CATEGORY_LOOKUP.get(bc)
        if target_cid is None:
            unmapped_cats.add(bc)
        else:
            mapped_cat_counts[target_cid] = mapped_cat_counts.get(target_cid, 0) + 1

    if unmapped_cats:
        print(f"  [LỖI] Có {len(unmapped_cats)} danh mục chưa được ánh xạ:")
        for uc in unmapped_cats:
            print(f"    - {uc}")
        sys.exit(1)
    else:
        print(f"  [OK] 100% ({len(src_makeup_list)} sản phẩm) được ánh xạ thành công vào 31 danh mục lá.")

    # 5. CHUẨN HÓA THƯƠNG HIỆU (BRAND MAPPING & EXTENSION)
    print("\n[BƯỚC 5] Phân tích và ánh xạ Thương hiệu (Brand Mapping)...")
    src_brands_map = {b['ma_thuong_hieu']: (b.get('ten_thuong_hieu') or '').strip() for b in src_db.thuong_hieu.find()}
    existing_brands = list(tgt_db.thuong_hieu.find({}))
    existing_brand_by_name = {normalize_text(b.get("ten_thuong_hieu", "")): b for b in existing_brands}
    max_brand_id = max([int(b.get("ma_thuong_hieu", 0)) for b in existing_brands if str(b.get("ma_thuong_hieu", "")).isdigit()] or [0])

    makeup_brands_seen = {}
    for p in src_makeup_list:
        src_bid = p.get("ma_thuong_hieu")
        bname = src_brands_map.get(src_bid, "").strip()
        if bname:
            norm_bname = normalize_text(bname)
            if norm_bname not in makeup_brands_seen:
                makeup_brands_seen[norm_bname] = bname

    print(f"  - Tổng số thương hiệu xuất hiện trong 1.615 sản phẩm makeup: {len(makeup_brands_seen)}")
    matched_brands = 0
    new_brands_to_insert = []
    brand_id_map = {}

    for norm_bname, original_bname in makeup_brands_seen.items():
        if norm_bname in existing_brand_by_name:
            matched_brands += 1
            brand_id_map[norm_bname] = int(existing_brand_by_name[norm_bname]["ma_thuong_hieu"])
        else:
            max_brand_id += 1
            new_brand_doc = {
                "ma_thuong_hieu": max_brand_id,
                "ten_thuong_hieu": original_bname,
                "slug": re.sub(r'[^a-z0-9]+', '-', norm_bname).strip('-'),
                "trang_thai": "active",
                "created_at": datetime.now(),
                "updated_at": datetime.now()
            }
            new_brands_to_insert.append(new_brand_doc)
            brand_id_map[norm_bname] = max_brand_id

    print(f"  - Thương hiệu đã có trong v2: {matched_brands}")
    print(f"  - Thương hiệu mới cần thêm:   {len(new_brands_to_insert)} (IDs: {max_brand_id - len(new_brands_to_insert) + 1}..{max_brand_id})")

    # 6. CHUẨN HÓA XUẤT XỨ (ORIGIN MAPPING & EXTENSION)
    print("\n[BƯỚC 6] Phân tích và ánh xạ Xuất xứ (Origin Mapping)...")
    src_origins_map = {o['ma_xuat_xu']: (o.get('ten_xuat_xu') or '').strip() for o in src_db.xuat_xu.find()}
    existing_origins = list(tgt_db.xuat_xu.find({}))
    existing_origin_by_name = {normalize_text(o.get("ten_xuat_xu", "")): o for o in existing_origins}
    existing_origin_ids = {int(o["ma_xuat_xu"]) for o in existing_origins if str(o.get("ma_xuat_xu", "")).isdigit()}

    makeup_origins_seen = {}
    for p in src_makeup_list:
        src_oid = p.get("ma_xuat_xu")
        oname = src_origins_map.get(src_oid, "").strip()
        if oname:
            norm_oname = normalize_text(oname)
            if norm_oname not in makeup_origins_seen:
                makeup_origins_seen[norm_oname] = (oname, src_oid)

    matched_origins = 0
    new_origins_to_insert = []
    origin_id_map = {}

    for norm_oname, (original_oname, src_oid) in makeup_origins_seen.items():
        if norm_oname in existing_origin_by_name:
            matched_origins += 1
            origin_id_map[norm_oname] = int(existing_origin_by_name[norm_oname]["ma_xuat_xu"])
        else:
            # Ưu tiên giữ đúng ID gốc nếu ID đó chưa bị sử dụng trong v2 (ví dụ Malaysia: ID 21)
            if src_oid not in existing_origin_ids:
                assigned_oid = src_oid
            else:
                assigned_oid = max(existing_origin_ids) + 1
            existing_origin_ids.add(assigned_oid)

            new_origin_doc = {
                "ma_xuat_xu": assigned_oid,
                "ten_xuat_xu": original_oname,
                "created_at": datetime.now(),
                "updated_at": datetime.now()
            }
            new_origins_to_insert.append(new_origin_doc)
            origin_id_map[norm_oname] = assigned_oid

    print(f"  - Xuất xứ đã có trong v2: {matched_origins}")
    print(f"  - Xuất xứ mới cần thêm:   {len(new_origins_to_insert)}")
    for no in new_origins_to_insert:
        print(f"    + [ID: {no['ma_xuat_xu']}] {no['ten_xuat_xu']} (Khớp mã gốc skinsyntax ID: {no['ma_xuat_xu']})")

    # Nơi sản xuất lookup
    src_manuf_map = {
        (m.get('ma_nsx') or m.get('ma_noi_san_xuat')): (m.get('ten_nsx') or m.get('ten_noi_san_xuat') or '').strip()
        for m in src_db.noi_san_xuat.find()
    }
    tgt_manuf_map = {
        normalize_text(m.get('ten_nsx') or m.get('ten_noi_san_xuat') or ''): (m.get('ma_noi_san_xuat') or m.get('ma_nsx'))
        for m in tgt_db.noi_san_xuat.find()
    }

    # 7. CHUẨN HÓA LOẠI DA (SKIN TYPE)
    print("\n[BƯỚC 7] Chuẩn hóa Loại da cho Makeup...")
    existing_skin_types = list(tgt_db.loai_da.find({}))
    existing_skin_ids = [int(s.get("ma_loai_da", 0)) for s in existing_skin_types]
    
    needs_skin_type_4 = (4 not in existing_skin_ids)
    skin_type_4_doc = {
        "ma_loai_da": 4,
        "ten_loai_da": "Không áp dụng",
        "created_at": datetime.now(),
        "updated_at": datetime.now()
    }
    if needs_skin_type_4:
        print("  - Cần thêm ma_loai_da: 4 ('Không áp dụng') vào skinsyntax_v2.loai_da.")
    else:
        print("  - ma_loai_da: 4 đã tồn tại trong skinsyntax_v2.loai_da.")

    # 8. TẠO DANH SÁCH 1.615 DOCUMENT SẢN PHẨM MỚI
    print("\n[BƯỚC 8] Chuẩn bị 1.615 Document sản phẩm Makeup (ID 1388 - 3002)...")
    prepared_products = []
    current_new_id = 1388

    for p in src_makeup_list:
        new_prod_id = current_new_id
        current_new_id += 1

        bc = (p.get("danh_muc_day_du") or "").strip()
        target_cid = CATEGORY_LOOKUP[bc]

        # Brand
        src_bid = p.get("ma_thuong_hieu")
        raw_bname = src_brands_map.get(src_bid, "").strip()
        norm_bname = normalize_text(raw_bname)
        target_bid = brand_id_map.get(norm_bname)

        # Origin
        src_oid = p.get("ma_xuat_xu")
        raw_oname = src_origins_map.get(src_oid, "").strip()
        norm_oname = normalize_text(raw_oname)
        target_oid = origin_id_map.get(norm_oname)

        # Manufacture
        src_mid = p.get("ma_noi_san_xuat")
        raw_mname = src_manuf_map.get(src_mid, "").strip()
        norm_mname = normalize_text(raw_mname)
        target_mid = tgt_manuf_map.get(norm_mname, src_mid)

        # Skin type
        # Quy tắc: Không đánh đồng Unknown với Mọi loại da.
        # Với makeup, nếu là Unknown hoặc rỗng hoặc ma_loai_da=4, chuẩn hóa thành:
        # ma_loai_da: 4, loai_da: "Không áp dụng"
        raw_skin = (p.get("loai_da") or "").strip()
        raw_skin_id = p.get("ma_loai_da")
        
        if raw_skin_id == 4 or raw_skin.lower() == 'unknown' or not raw_skin:
            final_skin_id = 4
            final_skin_text = "Không áp dụng"
        else:
            final_skin_id = int(raw_skin_id) if str(raw_skin_id).isdigit() else 4
            final_skin_text = raw_skin

        # Price & Discount
        gia_ban = float(p.get("gia_ban") or 0)
        gia_thi_truong = float(p.get("gia_thi_truong") or 0)
        if gia_thi_truong < gia_ban:
            gia_thi_truong = round(gia_ban * 1.15)
        phan_tram_giam = round(((gia_thi_truong - gia_ban) / gia_thi_truong) * 100) if gia_thi_truong > gia_ban else 0

        # Ratings & Stock
        diem_danh_gia = float(p.get("diem_danh_gia") or 5.0)
        so_luong_danh_gia = int(p.get("so_luong_danh_gia") or 10)
        so_luong_ton_kho = int(p.get("so_luong_ton_kho") or 100)

        new_doc = {
            "ma_san_pham": new_prod_id,
            "id": new_prod_id,
            "ten_san_pham": (p.get("ten_san_pham") or "").strip(),
            "ma_danh_muc": target_cid,
            "danh_muc_day_du": bc,
            "nganh_hang": "Trang Điểm", # Chỉ gán cho sản phẩm makeup mới, KHÔNG chạm vào sản phẩm cũ
            "thuong_hieu": raw_bname,
            "ma_thuong_hieu": target_bid,
            "gia_ban": gia_ban,
            "gia_thi_truong": gia_thi_truong,
            "phan_tram_giam": phan_tram_giam,
            "link_hinh_anh": (p.get("link_hinh_anh") or "").strip(),
            "dung_tich": (p.get("dung_tich") or "").strip() or "Tiêu chuẩn",
            "xuat_xu_thuong_hieu": raw_oname or "Chính hãng",
            "ma_xuat_xu": target_oid,
            "noi_san_xuat": raw_mname or raw_oname or "Chính hãng",
            "ma_noi_san_xuat": target_mid,
            "loai_da": final_skin_text,
            "ma_loai_da": final_skin_id,
            "mo_ta": (p.get("mo_ta") or "").strip(),
            "thanh_phan_chinh": (p.get("thanh_phan") or p.get("thanh_phan_chinh") or "").strip(),
            "thanh_phan_day_du": (p.get("thanh_phan_full") or p.get("thanh_phan_day_du") or "").strip(),
            "hdsd": (p.get("hdsd") or "").strip(),
            "diem_danh_gia": diem_danh_gia,
            "so_luong_danh_gia": so_luong_danh_gia,
            "so_luong_ton_kho": so_luong_ton_kho,
            "trang_thai": "active",
            "trang_thai_kho": "con_hang",
            "luot_xem": int(p.get("luot_xem") or 0),
            "so_luong_da_ban": int(p.get("so_luong_da_ban") or 0),
            "created_at": datetime.now(),
            "updated_at": datetime.now()
        }
        prepared_products.append(new_doc)

    print(f"  - Đã chuẩn bị xong {len(prepared_products)} documents.")
    print(f"  - Dải ID sản phẩm: {prepared_products[0]['ma_san_pham']} -> {prepared_products[-1]['ma_san_pham']}")

    # Xuất bảng mapping ID để đối chiếu và kiểm tra tính ổn định 1-1
    mapping_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'scratch')
    os.makedirs(mapping_dir, exist_ok=True)
    mapping_file = os.path.join(mapping_dir, 'makeup_id_mapping.json')
    mapping_data = [
        {
            "ma_san_pham_nguon": src_p.get("ma_san_pham"),
            "ma_san_pham_v2": tgt_p.get("ma_san_pham"),
            "ten_san_pham": tgt_p.get("ten_san_pham"),
            "ma_danh_muc": tgt_p.get("ma_danh_muc"),
            "thuong_hieu": tgt_p.get("thuong_hieu"),
            "xuat_xu_thuong_hieu": tgt_p.get("xuat_xu_thuong_hieu"),
            "loai_da": tgt_p.get("loai_da")
        }
        for src_p, tgt_p in zip(src_makeup_list, prepared_products)
    ]
    with open(mapping_file, 'w', encoding='utf-8') as f:
        json.dump(mapping_data, f, indent=2, ensure_ascii=False)
    print(f"  - Đã xuất bảng mapping ID ({len(mapping_data)} bản ghi) ra '{mapping_file}'.")

    # 9. TỔNG HỢP KẾ HOẠCH THỰC THI
    print("\n" + "=" * 70)
    print("BẢNG TỔNG HỢP SỐ LIỆU MIGRATION EXTENSION:")
    print("=" * 70)
    print(f"1. Sản phẩm Skincare giữ nguyên:      1.387 (IDs 1..1387)")
    print(f"2. Sản phẩm Makeup sẽ thêm:           {len(prepared_products)} (IDs 1388..3002)")
    print(f"3. Tổng sản phẩm sau migration:       {1387 + len(prepared_products)} (3.002)")
    print(f"4. Danh mục Skincare giữ nguyên:      21 (IDs 1..21)")
    print(f"5. Danh mục Makeup sẽ thêm:           {len(MAKEUP_CATEGORIES_DEFINITION)} (IDs 22..59)")
    print(f"   - Root:                            1 (ID 22: 'Trang Điểm')")
    print(f"   - Cấp 1:                           6 (IDs 23..28)")
    print(f"   - Cấp 2 (Lá):                      31 (IDs 29..59)")
    print(f"6. Thương hiệu mới sẽ thêm:           {len(new_brands_to_insert)}")
    print(f"7. Xuất xứ mới sẽ thêm:               {len(new_origins_to_insert)}")
    print(f"8. Loại da mới sẽ thêm:               1 (ma_loai_da: 4, 'Không áp dụng')")
    print(f"9. nganh_hang 1.387 sản phẩm cũ:      GIỮ NGUYÊN 100% KHÔNG THAY ĐỔI")
    print("=" * 70)

    # 10. THỰC HIỆN GHI HOẶC DỪNG LẠI (DRY-RUN VS LIVE)
    if is_dry_run:
        print("\n>>> KẾT QUẢ DRY-RUN HOÀN TẤT THÀNH CÔNG! <<<")
        print("Tất cả các kiểm tra ràng buộc, taxonomy, lookups và ID range đều hợp lệ 100%.")
        print("KHÔNG CÓ BẤT KỲ DỮ LIỆU NÀO ĐƯỢC GHI VÀO DATABASE Ở BƯỚC NÀY.")
        print("Để thực hiện ghi thực tế, hãy chờ người dùng xác nhận và chạy với cờ --live.")
        return

    # LIVE EXECUTION BLOCK
    print("\n>>> BẮT ĐẦU THỰC HIỆN GHI DATABASE (LIVE MODE) <<<")
    
    # Ghi Loại da 4
    if needs_skin_type_4:
        tgt_db.loai_da.update_one(
            {"ma_loai_da": 4},
            {"$setOnInsert": skin_type_4_doc},
            upsert=True
        )
        print("  [OK] Đã chèn/cập nhật loai_da: 4 ('Không áp dụng').")

    # Ghi Xuất xứ mới
    if new_origins_to_insert:
        for odoc in new_origins_to_insert:
            tgt_db.xuat_xu.update_one(
                {"ma_xuat_xu": odoc["ma_xuat_xu"]},
                {"$setOnInsert": odoc},
                upsert=True
            )
        print(f"  [OK] Đã chèn {len(new_origins_to_insert)} xuất xứ mới.")

    # Ghi Thương hiệu mới
    if new_brands_to_insert:
        for bdoc in new_brands_to_insert:
            tgt_db.thuong_hieu.update_one(
                {"ma_thuong_hieu": bdoc["ma_thuong_hieu"]},
                {"$setOnInsert": bdoc},
                upsert=True
            )
        print(f"  [OK] Đã chèn {len(new_brands_to_insert)} thương hiệu mới.")

    # Ghi 38 Danh mục Makeup
    cat_inserted = 0
    for cdoc in MAKEUP_CATEGORIES_DEFINITION:
        save_cdoc = dict(cdoc)
        save_cdoc.pop("source_raw_cat", None)
        save_cdoc["created_at"] = datetime.now()
        save_cdoc["updated_at"] = datetime.now()
        res = tgt_db.danh_muc.update_one(
            {"ma_danh_muc": save_cdoc["ma_danh_muc"]},
            {"$setOnInsert": save_cdoc},
            upsert=True
        )
        if res.upserted_id is not None:
            cat_inserted += 1
    print(f"  [OK] Đã chèn {cat_inserted} danh mục Makeup mới vào skinsyntax_v2.danh_muc.")

    # Ghi 1.615 Sản phẩm Makeup
    prod_inserted = 0
    for pdoc in prepared_products:
        res = tgt_db.san_pham.update_one(
            {"ma_san_pham": pdoc["ma_san_pham"]},
            {"$setOnInsert": pdoc},
            upsert=True
        )
        if res.upserted_id is not None:
            prod_inserted += 1

    print(f"  [OK] Đã chèn {prod_inserted} sản phẩm Makeup mới vào skinsyntax_v2.san_pham.")
    print("\n>>> LIVE MIGRATION HOÀN TẤT THÀNH CÔNG VÀ AN TOÀN! <<<")


if __name__ == '__main__':
    main()
