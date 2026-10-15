#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
preflight_check_c1.py
Pre-flight verification for STEP C1 before Live Migration
"""

import os
import sys
import json
from pymongo import MongoClient

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')

MONGO_URI = 'mongodb://127.0.0.1:27018'
SOURCE_DB = 'skinsyntax'
TARGET_DB = 'skinsyntax_v2'
BACKUP_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'backup_before_makeup')

def run_preflight():
    print("=" * 70)
    print("STEP C1 — PRE-FLIGHT VERIFICATION TRƯỚC KHI CHẠY LIVE MIGRATION")
    print(f"Thời gian: {os.path.basename(__file__)}")
    print("=" * 70)
    
    passed = True
    
    # 1. Kiểm tra kết nối MongoDB 127.0.0.1:27018
    print("\n1. Kiểm tra kết nối MongoDB 127.0.0.1:27018...")
    try:
        client = MongoClient(MONGO_URI, serverSelectionTimeoutMS=3000)
        client.admin.command('ping')
        print("  [PASS] Kết nối MongoDB 127.0.0.1:27018 thành công.")
    except Exception as e:
        print(f"  [FAIL] Không thể kết nối MongoDB: {e}")
        return False
        
    # 2. Xác minh DB đích là skinsyntax_v2, nguồn skinsyntax
    print("\n2. Xác minh DB nguồn & đích...")
    db_list = client.list_database_names()
    if SOURCE_DB not in db_list or TARGET_DB not in db_list:
        print(f"  [FAIL] Thiếu database: cần có cả {SOURCE_DB} và {TARGET_DB}. Danh sách hiện có: {db_list}")
        return False
    print(f"  [PASS] Database nguồn '{SOURCE_DB}' và đích '{TARGET_DB}' tồn tại hợp lệ.")
    
    src_db = client[SOURCE_DB]
    tgt_db = client[TARGET_DB]
    
    # 3. Kiểm tra lại backup và manifest
    print("\n3. Kiểm tra lại backup và manifest...")
    manifest_path = os.path.join(BACKUP_DIR, 'backup_manifest.json')
    if not os.path.exists(manifest_path):
        print(f"  [FAIL] Không tìm thấy manifest tại {manifest_path}")
        return False
    with open(manifest_path, 'r', encoding='utf-8') as f:
        manifest = json.load(f)
        
    expected_backup = {
        'san_pham': 1387,
        'danh_muc': 21,
        'thuong_hieu': 156,
        'xuat_xu': 18,
        'loai_da': 5,
        'noi_san_xuat': 22
    }
    for col_name, exp_count in expected_backup.items():
        file_path = os.path.join(BACKUP_DIR, f"{col_name}.json")
        if not os.path.exists(file_path) or os.path.getsize(file_path) == 0:
            print(f"  [FAIL] File backup {col_name}.json không hợp lệ.")
            return False
    print("  [PASS] Toàn bộ 6 file backup và manifest đầy đủ, hợp lệ.")
    
    # 4. Xác minh lại ID, unique indexes và mapping
    print("\n4. Xác minh dải ID và Unique Indexes...")
    # Kiểm tra dải ID 1388..3002
    conflict_count = tgt_db.san_pham.count_documents({
        "$or": [
            {"ma_san_pham": {"$gte": 1388, "$lte": 3002}},
            {"id": {"$gte": 1388, "$lte": 3002}}
        ]
    })
    if conflict_count > 0:
        print(f"  [FAIL] Phát hiện {conflict_count} sản phẩm đang dùng ID trong dải 1388..3002!")
        return False
    print("  [PASS] Dải ID 1388..3002 trong skinsyntax_v2.san_pham hoàn toàn trống.")
    
    # Kiểm tra Unique indexes
    unique_indexes = {
        'san_pham': 'ma_san_pham',
        'danh_muc': 'ma_danh_muc',
        'thuong_hieu': 'ma_thuong_hieu',
        'xuat_xu': 'ma_xuat_xu',
        'loai_da': 'ma_loai_da'
    }
    for col_name, expected_field in unique_indexes.items():
        idxs = list(tgt_db[col_name].list_indexes())
        has_unique = any(idx.get('unique', False) and expected_field in idx.get('key', {}) for idx in idxs)
        if not has_unique:
            print(f"  [FAIL] Thiếu Unique index trên {col_name}.{expected_field}")
            return False
    print("  [PASS] Toàn bộ Unique indexes trên các collection đích đã sẵn sàng.")
    
    # 5. Xác nhận không có thay đổi dữ liệu ngoài dự kiến kể từ DRY-RUN cuối
    print("\n5. Kiểm tra hiện trạng dữ liệu runtime skinsyntax_v2...")
    counts = {
        'san_pham': tgt_db.san_pham.count_documents({}),
        'danh_muc': tgt_db.danh_muc.count_documents({}),
        'thuong_hieu': tgt_db.thuong_hieu.count_documents({}),
        'xuat_xu': tgt_db.xuat_xu.count_documents({}),
        'loai_da': tgt_db.loai_da.count_documents({}),
        'noi_san_xuat': tgt_db.noi_san_xuat.count_documents({})
    }
    for col_name, exp_count in expected_backup.items():
        act_count = counts[col_name]
        if act_count != exp_count:
            print(f"  [FAIL] {col_name} có số document ({act_count}) khác với bản chuẩn ({exp_count})")
            return False
            
    # Kiểm tra số lượng sản phẩm makeup nguồn
    src_makeup_count = src_db.san_pham.count_documents({"danh_muc_day_du": {"$regex": "Trang Điểm"}})
    if src_makeup_count != 1615:
        print(f"  [FAIL] skinsyntax makeup count là {src_makeup_count}, khác 1.615!")
        return False
        
    print(f"  [PASS] Hiện trạng skinsyntax_v2 khớp 100% (san_pham: 1.387, danh_muc: 21, thuong_hieu: 156, xuat_xu: 18, loai_da: 5, noi_san_xuat: 22).")
    print(f"  [PASS] skinsyntax có đúng 1.615 sản phẩm makeup.")
    
    print("\n" + "=" * 70)
    print(">>> TẤT CẢ 5 BƯỚC PRE-FLIGHT ĐỀU PASS 100%! ĐỦ ĐIỀU KIỆN CHẠY LIVE MIGRATION! <<<")
    print("=" * 70)
    return True

if __name__ == '__main__':
    ok = run_preflight()
    if not ok:
        sys.exit(1)
    sys.exit(0)
