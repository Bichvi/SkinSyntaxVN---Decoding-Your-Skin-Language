"""Idempotent re-index of the catalog into Chroma collection ``catalog_products_v1``.

- Reads Mongo READ-ONLY (san_pham + thuong_hieu + danh_muc lookups).
- Chroma id = ma_san_pham; upsert, so re-running is safe. Ids no longer in the catalog
  are deleted from THIS collection only. The chatbot's ``products`` collection is untouched.
- Uses the shared embedding model (shared.embedding_provider).

Run inside the AI container (Chroma server 0.5.20):
    docker exec skinsyntax-ai python -m search_service.scripts.reindex_chroma
"""

from __future__ import annotations

import sys
import time

from search_service import config
from search_service.catalog import get_mongo_db, load_catalog
from recommendation_service.product_repository import product_text

BATCH = 128


def main() -> int:
    started = time.time()
    db = get_mongo_db()
    if db is None:
        print("ERROR: Mongo unavailable")
        return 1
    products = load_catalog(db)
    print(f"catalog products: {len(products)}")
    if not products:
        return 1

    import chromadb
    from shared.embedding_provider import get_embedding_function

    if config.CHROMA_HOST:
        client = chromadb.HttpClient(host=config.CHROMA_HOST, port=config.CHROMA_PORT)
    else:
        from recommendation_service import config as rec_config

        client = chromadb.PersistentClient(path=str(rec_config.CHROMA_DIR))
    # LangChain Chroma default space is l2 on normalized vectors; keep it consistent.
    collection = client.get_or_create_collection(config.CATALOG_COLLECTION)
    embed = get_embedding_function()

    for start in range(0, len(products), BATCH):
        chunk = products[start:start + BATCH]
        docs = [product_text(p) for p in chunk]
        collection.upsert(
            ids=[p["id"] for p in chunk],
            documents=docs,
            embeddings=embed.embed_documents(docs),
            metadatas=[
                {
                    "product_id": p["id"],
                    "ma_san_pham": p["id"],
                    "name": p["ten_san_pham"][:200],
                    "brand": p["thuong_hieu"],
                    "category": p["loai_san_pham"],
                    "sold": int(p.get("sold") or 0),
                }
                for p in chunk
            ],
        )
        print(f"upserted {min(start + BATCH, len(products))}/{len(products)}")

    wanted = {p["id"] for p in products}
    existing = collection.get(include=[])["ids"]
    stale = [i for i in existing if i not in wanted]
    if stale:
        collection.delete(ids=stale)
    print(f"collection={config.CATALOG_COLLECTION} count={collection.count()} removed_stale={len(stale)} "
          f"took={time.time() - started:.1f}s")
    return 0


if __name__ == "__main__":
    sys.exit(main())
