"""
Backup collections before makeup migration
Database: skinsyntax_v2 on 127.0.0.1:27018
Collections: san_pham, danh_muc, thuong_hieu, xuat_xu, loai_da, noi_san_xuat
"""
import os
import json
from datetime import datetime
from pymongo import MongoClient
from bson import json_util

BACKUP_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'backup_before_makeup')
os.makedirs(BACKUP_DIR, exist_ok=True)

MONGO_URI = 'mongodb://127.0.0.1:27018'
DB_NAME = 'skinsyntax_v2'

COLLECTIONS = ['san_pham', 'danh_muc', 'thuong_hieu', 'xuat_xu', 'loai_da', 'noi_san_xuat']

def run_backup():
    client = MongoClient(MONGO_URI)
    db = client[DB_NAME]
    
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    manifest = {
        'timestamp': timestamp,
        'database': DB_NAME,
        'mongo_uri': MONGO_URI,
        'collections': {}
    }
    
    print(f"Starting backup for database '{DB_NAME}' to '{BACKUP_DIR}'...")
    
    for col_name in COLLECTIONS:
        col = db[col_name]
        docs = list(col.find({}))
        file_path = os.path.join(BACKUP_DIR, f"{col_name}.json")
        
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(json_util.dumps(docs, indent=2, ensure_ascii=False))
            
        manifest['collections'][col_name] = {
            'count': len(docs),
            'file': f"{col_name}.json",
            'bytes': os.path.getsize(file_path)
        }
        print(f"  [OK] Backed up {col_name}: {len(docs)} documents ({manifest['collections'][col_name]['bytes']} bytes)")
        
    manifest_path = os.path.join(BACKUP_DIR, 'backup_manifest.json')
    with open(manifest_path, 'w', encoding='utf-8') as f:
        json.dump(manifest, f, indent=2, ensure_ascii=False)
        
    print(f"Backup completed successfully! Manifest saved to '{manifest_path}'.")
    return manifest

if __name__ == '__main__':
    run_backup()
