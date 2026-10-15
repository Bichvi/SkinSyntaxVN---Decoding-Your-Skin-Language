#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
restore_backup.py
Script khôi phục khẩn cấp dữ liệu từ database/backup_before_makeup/ vào skinsyntax_v2.

CẢNH BÁO AN TOÀN:
- Script này CHỈ được dùng khi migration gặp sự cố cần rollback về trạng thái trước khi nhập makeup.
- Để tránh vô tình thực thi, script yêu cầu truyền chính xác cờ:
    python database/restore_backup.py --confirm-restore-backup
"""

import os
import sys
import argparse
from datetime import datetime
from pymongo import MongoClient
from bson import json_util

if sys.platform == 'win32':
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')

MONGO_URI = 'mongodb://127.0.0.1:27018'
TARGET_DB = 'skinsyntax_v2'
BACKUP_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'backup_before_makeup')

COLLECTIONS = ['san_pham', 'danh_muc', 'thuong_hieu', 'xuat_xu', 'loai_da', 'noi_san_xuat']

def main():
    parser = argparse.ArgumentParser(description="Emergency Restore Backup to skinsyntax_v2")
    parser.add_argument('--confirm-restore-backup', action='store_true', help="Xác nhận khôi phục khẩn cấp từ bản backup.")
    args = parser.parse_args()

    if not args.confirm_restore_backup:
        print("[AN TOÀN] Script restore đang ở chế độ bảo vệ.")
        print("Nếu thực sự cần rollback cơ sở dữ liệu về bản backup, hãy chạy:")
        print("  python database/restore_backup.py --confirm-restore-backup")
        sys.exit(0)

    print("=" * 70)
    print("KHÔI PHỤC DỮ LIỆU TỪ BẢN SAO LƯU (EMERGENCY ROLLBACK)")
    print(f"Target DB: {TARGET_DB} on {MONGO_URI}")
    print(f"Backup Dir: {BACKUP_DIR}")
    print("=" * 70)

    client = MongoClient(MONGO_URI)
    db = client[TARGET_DB]

    for col_name in COLLECTIONS:
        file_path = os.path.join(BACKUP_DIR, f"{col_name}.json")
        if not os.path.exists(file_path):
            print(f"[FAIL] Không tìm thấy file {file_path}")
            sys.exit(1)

        with open(file_path, 'r', encoding='utf-8') as f:
            docs = json_util.loads(f.read())

        col = db[col_name]
        # Xóa các bản ghi đã chèn thêm trong migration (ví dụ ma_san_pham >= 1388, ma_danh_muc >= 22)
        # hoặc khôi phục đúng danh sách ban đầu:
        print(f"Đang khôi phục collection {col_name} ({len(docs)} documents)...")
        col.delete_many({})
        if docs:
            col.insert_many(docs)
        print(f"  [OK] Đã khôi phục {col_name}: {col.count_documents({})} documents.")

    print("\nKhôi phục hoàn tất thành công 100%!")

if __name__ == '__main__':
    main()
