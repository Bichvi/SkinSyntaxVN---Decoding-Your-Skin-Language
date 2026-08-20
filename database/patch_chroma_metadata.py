"""Bổ sung ma_san_pham vào metadata ChromaDB từ document id (product_123 → 123)."""
import os
import sys
from pathlib import Path

CHROMA_DB_PATH = os.getenv("CHROMA_DB_PATH", str(Path(__file__).resolve().parent / "chroma_db"))
COLLECTION_NAME = "products"


def main():
    import chromadb

    client = chromadb.PersistentClient(path=CHROMA_DB_PATH)
    collection = client.get_or_create_collection(name=COLLECTION_NAME)
    result = collection.get(include=["metadatas"])
    ids = result.get("ids") or []
    metas = result.get("metadatas") or []
    if not ids:
        print("Collection trống.")
        return

    updated = []
    new_metas = []
    for doc_id, meta in zip(ids, metas):
        meta = dict(meta or {})
        ma = str(doc_id or "").replace("product_", "", 1).strip()
        if ma and not ma.startswith("doc_"):
            meta["ma_san_pham"] = ma
            meta["id"] = ma
            updated.append(doc_id)
        new_metas.append(meta)

    if not updated:
        print("Không có id product_* để patch.")
        return

    collection.update(ids=ids, metadatas=new_metas)
    print(f"Đã patch metadata cho {len(updated):,} / {len(ids):,} documents.")


if __name__ == "__main__":
    main()
