#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
verify_backup_integrity.py
Xác minh tính toàn vẹn và khả năng phục hồi của các file backup tại database/backup_before_makeup/
"""

import os
import sys
import json
from datetime import datetime
from bson import json_util, ObjectId

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')

BACKUP_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'backup_before_makeup')
MANIFEST_PATH = os.path.join(BACKUP_DIR, 'backup_manifest.json')

EXPECTED_COUNTS = {
    'san_pham': 1387,
    'danh_muc': 21,
    'thuong_hieu': 156,
    'xuat_xu': 18,
    'loai_da': 5,
    'noi_san_xuat': 22
}

def verify_backup():
    print("=" * 70)
    print("XÁC MINH TÍNH TOÀN VẸN CÁC FILE BACKUP")
    print(f"Thư mục backup: {BACKUP_DIR}")
    print("=" * 70)

    if not os.path.exists(MANIFEST_PATH):
        print(f"[FAIL] Không tìm thấy file manifest tại {MANIFEST_PATH}")
        sys.exit(1)

    with open(MANIFEST_PATH, 'r', encoding='utf-8') as f:
        manifest = json.load(f)

    print(f"Manifest timestamp: {manifest.get('timestamp')}")
    print(f"Database nguồn:    {manifest.get('database')}")

    all_passed = True

    for col_name, expected_count in EXPECTED_COUNTS.items():
        file_name = f"{col_name}.json"
        file_path = os.path.join(BACKUP_DIR, file_name)

        if not os.path.exists(file_path):
            print(f"[FAIL] Thiếu file backup {file_name}")
            all_passed = False
            continue

        file_size = os.path.getsize(file_path)
        if file_size == 0:
            print(f"[FAIL] File backup {file_name} rỗng (0 bytes)")
            all_passed = False
            continue

        # Đọc và deserialize BSON JSON
        with open(file_path, 'r', encoding='utf-8') as f:
            raw_content = f.read()

        try:
            docs = json_util.loads(raw_content)
        except Exception as e:
            print(f"[FAIL] Lỗi parse BSON json_util cho {file_name}: {e}")
            all_passed = False
            continue

        actual_count = len(docs)
        if actual_count != expected_count:
            print(f"[FAIL] {col_name}: Số lượng document ({actual_count}) không khớp mong đợi ({expected_count})")
            all_passed = False
            continue

        # Kiểm tra BSON Types mẫu
        sample = docs[0]
        has_valid_id = isinstance(sample.get('_id'), ObjectId)
        
        has_valid_date = True
        if 'created_at' in sample and sample['created_at'] is not None:
            has_valid_date = isinstance(sample['created_at'], datetime)

        if not has_valid_id:
            print(f"[FAIL] {col_name}: _id không phải kiểu bson.ObjectId")
            all_passed = False
            continue

        if not has_valid_date:
            print(f"[FAIL] {col_name}: created_at không phải kiểu datetime.datetime")
            all_passed = False
            continue

        print(f"  [PASS] {col_name:12} : {actual_count:5} documents | Size: {file_size:10} bytes | ObjectId & Datetime: VALID")

    print("\n" + "=" * 70)
    if all_passed:
        print("[KẾT LUẬN] 100% FILE BACKUP TOÀN VẸN VÀ SẴN SÀNG ĐỂ KHÔI PHỤC KHI CẦN THIẾT!")
    else:
        print("[KẾT LUẬN] PHÁT HIỆN LỖI TRONG FILE BACKUP!")
        sys.exit(1)
    print("=" * 70)

if __name__ == '__main__':
    verify_backup()
