#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
post_migration_verify_c3.py
Post-Migration Verification for STEP C3
"""

import sys
import os
import json
from pymongo import MongoClient

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')

MONGO_URI = 'mongodb://127.0.0.1:27018'
TARGET_DB = 'skinsyntax_v2'
SOURCE_DB = 'skinsyntax'

def main():
    print("=" * 70)
    print("STEP C3 — POST-MIGRATION VERIFICATION")
    print("=" * 70)

    client = MongoClient(MONGO_URI)
    tgt_db = client[TARGET_DB]
    src_db = client[SOURCE_DB]

    all_passed = True

    def check(name, condition, detail=""):
        nonlocal all_passed
        if condition:
            print(f"  [PASS] {name}")
        else:
            print(f"  [FAIL] {name} ({detail})")
            all_passed = False

    # 1. Tổng san_pham: 3.002
    total_sp = tgt_db.san_pham.count_documents({})
    check("Tổng san_pham: 3.002", total_sp == 3002, f"Thực tế: {total_sp}")

    # 2. Skincare: 1.387, ID 1-1387, dữ liệu không thay đổi
    skincare_count = tgt_db.san_pham.count_documents({"ma_san_pham": {"$lte": 1387}})
    check("Skincare: 1.387 sản phẩm ID 1..1387", skincare_count == 1387, f"Thực tế: {skincare_count}")
    
    # Kiểm tra không có nganh_hang được gán cho sản phẩm skincare cũ
    old_with_nganh_hang = tgt_db.san_pham.count_documents({
        "ma_san_pham": {"$lte": 1387},
        "nganh_hang": {"$exists": True}
    })
    check("Không tự ý cập nhật trường nganh_hang của 1.387 sản phẩm cũ", old_with_nganh_hang == 0, f"Thực tế: {old_with_nganh_hang}")

    # 3. Makeup: 1.615, ID 1388-3002
    makeup_count = tgt_db.san_pham.count_documents({"ma_san_pham": {"$gte": 1388, "$lte": 3002}})
    check("Makeup: 1.615 sản phẩm ID 1388..3002", makeup_count == 1615, f"Thực tế: {makeup_count}")

    # 4. Tổng danh_muc: 59
    total_cat = tgt_db.danh_muc.count_documents({})
    check("Tổng danh_muc: 59", total_cat == 59, f"Thực tế: {total_cat}")
    
    skincare_cats = tgt_db.danh_muc.count_documents({"ma_danh_muc": {"$lte": 21}})
    makeup_cats = tgt_db.danh_muc.count_documents({"ma_danh_muc": {"$gte": 22, "$lte": 59}})
    check("Danh mục Skincare: 21 (IDs 1..21)", skincare_cats == 21, f"Thực tế: {skincare_cats}")
    check("Danh mục Makeup: 38 (IDs 22..59)", makeup_cats == 38, f"Thực tế: {makeup_cats}")

    # 5. Tổng thương hiệu dự kiến: 216
    total_brands = tgt_db.thuong_hieu.count_documents({})
    check("Tổng thương hiệu: 216 (156 cũ + 60 mới)", total_brands == 216, f"Thực tế: {total_brands}")

    # 6. Tổng xuất xứ dự kiến: 19
    total_origins = tgt_db.xuat_xu.count_documents({})
    check("Tổng xuất xứ: 19 (18 cũ + 1 mới - Malaysia ID 21)", total_origins == 19, f"Thực tế: {total_origins}")

    # 7. Tổng loại da dự kiến: 6
    total_skins = tgt_db.loai_da.count_documents({})
    check("Tổng loại da: 6 (5 cũ + 1 mới - Không áp dụng ID 4)", total_skins == 6, f"Thực tế: {total_skins}")

    # 8. Không duplicate IDs
    all_sp_ids = [p['ma_san_pham'] for p in tgt_db.san_pham.find({}, {"ma_san_pham": 1})]
    check("Không duplicate IDs sản phẩm", len(all_sp_ids) == len(set(all_sp_ids)), f"Total: {len(all_sp_ids)}, Unique: {len(set(all_sp_ids))}")
    check("Dải ID sản phẩm liên tục 1..3002", sorted(all_sp_ids) == list(range(1, 3003)))

    all_cat_ids = [c['ma_danh_muc'] for c in tgt_db.danh_muc.find({}, {"ma_danh_muc": 1})]
    check("Không duplicate IDs danh mục", len(all_cat_ids) == len(set(all_cat_ids)), f"Total: {len(all_cat_ids)}, Unique: {len(set(all_cat_ids))}")
    check("Dải ID danh mục liên tục 1..59", sorted(all_cat_ids) == list(range(1, 60)))

    # 9. Không orphan category, brand, origin, manufacturer hoặc skin type
    cat_ids_set = set(all_cat_ids)
    brand_ids_set = {b['ma_thuong_hieu'] for b in tgt_db.thuong_hieu.find({}, {"ma_thuong_hieu": 1})}
    origin_ids_set = {o['ma_xuat_xu'] for o in tgt_db.xuat_xu.find({}, {"ma_xuat_xu": 1})}
    skin_ids_set = {s['ma_loai_da'] for s in tgt_db.loai_da.find({}, {"ma_loai_da": 1})}

    orphan_cats = 0
    orphan_brands = 0
    orphan_origins = 0
    orphan_skins = 0

    invalid_price = 0
    invalid_images = 0
    invalid_name = 0

    makeup_prods = list(tgt_db.san_pham.find({"ma_san_pham": {"$gte": 1388}}))
    for p in makeup_prods:
        if p.get('ma_danh_muc') not in cat_ids_set:
            orphan_cats += 1
        if p.get('ma_thuong_hieu') not in brand_ids_set:
            orphan_brands += 1
        if p.get('ma_xuat_xu') and p.get('ma_xuat_xu') not in origin_ids_set:
            orphan_origins += 1
        if p.get('ma_loai_da') and p.get('ma_loai_da') not in skin_ids_set:
            orphan_skins += 1
            
        if not p.get('gia_ban') or float(p.get('gia_ban')) <= 0:
            invalid_price += 1
        if not p.get('link_hinh_anh') or not str(p.get('link_hinh_anh')).strip():
            invalid_images += 1
        if not p.get('ten_san_pham') or not str(p.get('ten_san_pham')).strip():
            invalid_name += 1

    check("Không orphan categories", orphan_cats == 0, f"Phát hiện {orphan_cats}")
    check("Không orphan brands", orphan_brands == 0, f"Phát hiện {orphan_brands}")
    check("Không orphan origins", orphan_origins == 0, f"Phát hiện {orphan_origins}")
    check("Không orphan skin types", orphan_skins == 0, f"Phát hiện {orphan_skins}")

    # 10. 1.615 sản phẩm makeup có giá, ảnh và tên hợp lệ
    check("1.615 sản phẩm makeup có giá bán > 0", invalid_price == 0, f"Phát hiện {invalid_price}")
    check("1.615 sản phẩm makeup có link hình ảnh hợp lệ", invalid_images == 0, f"Phát hiện {invalid_images}")
    check("1.615 sản phẩm makeup có tên sản phẩm hợp lệ", invalid_name == 0, f"Phát hiện {invalid_name}")

    print("\n" + "=" * 70)
    if all_passed:
        print(">>> TẤT CẢ TIÊU CHÍ VERIFICATION STEP C3 ĐỀU PASS 100%! <<<")
    else:
        print(">>> PHÁT HIỆN LỖI VERIFICATION! <<<")
        sys.exit(1)
    print("=" * 70)

if __name__ == '__main__':
    main()
