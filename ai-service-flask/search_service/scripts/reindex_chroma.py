"""Idempotent re-index of the catalog into Chroma collection ``catalog_products_v1``.

- Reads Mongo READ-ONLY (san_pham + thuong_hieu + danh_muc lookups).
- Chroma id = ma_san_pham; upsert, so re-running is safe. Ids no longer in the catalog
  are deleted from THIS collection only. The chatbot's ``products`` collection is untouched.
- Uses the shared embedding model (shared.embedding_provider).

Run inside the AI container (Chroma server 0.5.20):
    docker exec skinsyntax-ai python -m search_service.scripts.reindex_chroma [--ids 1,2,3]

``--ids`` / ``reindex_ids`` (U11) touch only those ids: upsert the visible ones, delete hidden/removed ones.
"""

from __future__ import annotations

import sys
import time
from typing import Any, Callable

from search_service import config
from search_service.catalog import get_mongo_db, load_catalog
from recommendation_service.product_repository import product_text

BATCH = 128


def _collection() -> Any:
    import chromadb

    if config.CHROMA_HOST:
        client = chromadb.HttpClient(host=config.CHROMA_HOST, port=config.CHROMA_PORT)
    else:
        from recommendation_service import config as rec_config

        client = chromadb.PersistentClient(path=str(rec_config.CHROMA_DIR))
    # LangChain Chroma default space is l2 on normalized vectors; keep it consistent.
    # get_collection first: the pinned client (chromadb 1.5.x) cannot call get_or_create_collection on the
    # 0.5.20 server ("KeyError('_type')"), but it reads/upserts an existing collection fine.
    try:
        return client.get_collection(config.CATALOG_COLLECTION)
    except Exception:  # noqa: BLE001 - missing collection (first run) -> create it
        return client.get_or_create_collection(config.CATALOG_COLLECTION)


def _upsert(collection: Any, products: list[dict[str, Any]], embed: Any, log: Callable[[str], None] = print) -> None:
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
        log(f"upserted {min(start + BATCH, len(products))}/{len(products)}")


def reindex_ids(ids: list[str], db: Any | None = None, collection: Any | None = None,
                embed: Any | None = None) -> dict[str, int]:
    """U11 event sync: upsert the VISIBLE products among ``ids``, delete the others from the collection.

    Only touches ``ids`` (no full scan of the collection). Returns counts.
    """

    wanted = [str(i) for i in ids]
    db = db if db is not None else get_mongo_db()
    if db is None:
        raise RuntimeError("mongo_unavailable")
    products = load_catalog(db, wanted)
    collection = collection if collection is not None else _collection()
    if products:
        if embed is None:
            from shared.embedding_provider import get_embedding_function

            embed = get_embedding_function()
        _upsert(collection, products, embed, log=lambda _m: None)
    visible = {p["id"] for p in products}
    gone = [i for i in wanted if i not in visible]
    if gone:
        collection.delete(ids=gone)
    return {"requested": len(wanted), "upserted": len(products), "deleted": len(gone)}


def main(argv: list[str] | None = None) -> int:
    args = list(sys.argv[1:] if argv is None else argv)
    if "--ids" in args:      # python -m search_service.scripts.reindex_chroma --ids 1,2,3
        raw = args[args.index("--ids") + 1] if args.index("--ids") + 1 < len(args) else ""
        ids = [i.strip() for i in raw.split(",") if i.strip()]
        if not ids:
            print("ERROR: --ids needs a comma separated list")
            return 2
        print(reindex_ids(ids))
        return 0
    started = time.time()
    db = get_mongo_db()
    if db is None:
        print("ERROR: Mongo unavailable")
        return 1
    products = load_catalog(db)
    print(f"catalog products: {len(products)}")
    if not products:
        return 1

    from shared.embedding_provider import get_embedding_function

    collection = _collection()
    _upsert(collection, products, get_embedding_function())

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
