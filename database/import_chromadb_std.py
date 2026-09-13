# -*- coding: utf-8 -*-
import csv
import math
import os
import sys
import uuid
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

BASE_DIR = Path(__file__).resolve().parent
CSV_FILE_PATH = str(BASE_DIR / "data_clean_final.csv")
CHROMA_DB_PATH = str(BASE_DIR / "chroma_db")
COLLECTION_NAME = "products"

def main():
    print(f"📦 Reading CSV file: {CSV_FILE_PATH}...", flush=True)
    if not os.path.exists(CSV_FILE_PATH):
        print("❌ Error: CSV file not found!", flush=True)
        return

    import chromadb
    from chromadb.utils import embedding_functions

    print("🔌 Connecting to ChromaDB...", flush=True)
    client = chromadb.PersistentClient(path=CHROMA_DB_PATH)

    sentence_transformer_ef = embedding_functions.SentenceTransformerEmbeddingFunction(
        model_name="sentence-transformers/static-similarity-mrl-multilingual-v1"
    )

    collection = client.get_or_create_collection(
        name=COLLECTION_NAME,
        embedding_function=sentence_transformer_ef,
    )

    batch_size = 300
    ids, documents, metadatas = [], [], []
    imported_count = 0

    with open(CSV_FILE_PATH, mode="r", encoding="utf-8-sig", errors="replace") as f:
        reader = csv.DictReader(f)
        for index, row in enumerate(reader):
            p_id = str(row.get("ma_san_pham", "")).strip()
            if not p_id:
                p_id = f"product_{index}_{uuid.uuid4().hex[:8]}"
            else:
                p_id = f"product_{p_id}"

            doc_parts = [
                f"Tên sản phẩm: {row.get('ten_san_pham', '')}",
                f"Loại sản phẩm: {row.get('loai_san_pham', '')}",
                f"Thương hiệu: {row.get('thuong_hieu', '')}",
                f"Xuất xứ thương hiệu: {row.get('xuat_xu_thuong_hieu', '')}",
                f"Nơi sản xuất: {row.get('noi_san_xuat', '')}",
                f"Dung tích: {row.get('dung_tich', '')}",
                f"Loại da phù hợp: {row.get('loai_da', '')}",
                f"Mô tả: {row.get('mo_ta', '')}",
                f"Thành phần chính: {row.get('thanh_phan_chinh', '')}",
                f"Thành phần đầy đủ: {row.get('thanh_phan_day_du', '')}",
            ]
            document = " \n ".join(doc_parts)

            raw_images = str(row.get("link_hinh_anh", "")).strip()
            first_image = raw_images.split(" | ")[0] if raw_images else ""

            try:
                gia_ban = float(row.get("gia_ban", 0))
            except Exception:
                gia_ban = 0.0

            metadata = {
                "ma_san_pham": str(row.get("ma_san_pham", p_id)),
                "ten_san_pham": str(row.get("ten_san_pham", "")),
                "loai_san_pham": str(row.get("loai_san_pham", "")),
                "gia_ban": gia_ban,
                "thuong_hieu": str(row.get("thuong_hieu", "")),
                "xuat_xu_thuong_hieu": str(row.get("xuat_xu_thuong_hieu", "")),
                "noi_san_xuat": str(row.get("noi_san_xuat", "")),
                "dung_tich": str(row.get("dung_tich", "")),
                "loai_da": str(row.get("loai_da", "")),
                "link_hinh_anh": first_image,
                "mo_ta": str(row.get("mo_ta", ""))[:1000],
                "thanh_phan_chinh": str(row.get("thanh_phan_chinh", ""))[:1000],
            }

            ids.append(p_id)
            documents.append(document)
            metadatas.append(metadata)

            if len(ids) >= batch_size:
                collection.upsert(ids=ids, documents=documents, metadatas=metadatas)
                imported_count += len(ids)
                print(f"  Imported {imported_count} docs into ChromaDB...", flush=True)
                ids, documents, metadatas = [], [], []

        if ids:
            collection.upsert(ids=ids, documents=documents, metadatas=metadatas)
            imported_count += len(ids)

    print(f"\n✅ FULL IMPORT COMPLETED! Total docs in ChromaDB: {imported_count}", flush=True)

if __name__ == "__main__":
    main()
