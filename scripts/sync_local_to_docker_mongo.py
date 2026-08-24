"""
Sync all databases & collections from Local MongoDB (127.0.0.1:27017) to Docker MongoDB (127.0.0.1:27018).
"""
import sys
import os
from pymongo import MongoClient

# Force UTF-8 stdout
if sys.platform == "win32":
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

SOURCE_URI = "mongodb://127.0.0.1:27017"
TARGET_URI = "mongodb://127.0.0.1:27018"

DATABASES_TO_SYNC = ["skinsyntax", "skinsyntax_ai"]

def sync_mongodb():
    print(f"[*] Connecting to Source Local MongoDB ({SOURCE_URI})...")
    src_client = MongoClient(SOURCE_URI, serverSelectionTimeoutMS=5000)
    src_client.admin.command("ping")
    print("  [+] Source Local MongoDB connected!")

    print(f"[*] Connecting to Target Docker MongoDB ({TARGET_URI})...")
    tgt_client = MongoClient(TARGET_URI, serverSelectionTimeoutMS=5000)
    tgt_client.admin.command("ping")
    print("  [+] Target Docker MongoDB connected!\n")

    for db_name in DATABASES_TO_SYNC:
        print(f"[>] Syncing database: '{db_name}'...")
        src_db = src_client[db_name]
        tgt_db = tgt_client[db_name]

        collections = src_db.list_collection_names()
        for col_name in collections:
            if col_name.startswith("system."):
                continue

            src_col = src_db[col_name]
            tgt_col = tgt_db[col_name]

            # Count source docs
            doc_count = src_col.count_documents({})
            if doc_count == 0:
                print(f"   - Collection '{col_name}': empty (skipped)")
                continue

            # Drop target collection to replace cleanly
            tgt_col.drop()

            # Copy documents in batches
            docs = list(src_col.find())
            batch_size = 500
            for i in range(0, len(docs), batch_size):
                batch = docs[i:i + batch_size]
                tgt_col.insert_many(batch)

            # Copy indexes
            indexes = src_col.list_indexes()
            for idx in indexes:
                idx_name = idx.get("name", "")
                if idx_name == "_id_":
                    continue
                key = idx.get("key")
                unique = idx.get("unique", False)
                if key:
                    key_list = list(key.items())
                    try:
                        tgt_col.create_index(key_list, name=idx_name, unique=unique)
                    except Exception as e:
                        pass

            print(f"   [+] Collection '{col_name}': copied {len(docs):,} documents")

        print(f"[+] Database '{db_name}' sync complete!\n")

    print("[SUCCESS] ALL LOCAL MONGODB DATA SUCCESSFULLY MIGRATED TO DOCKER MONGODB!")

if __name__ == "__main__":
    sync_mongodb()
