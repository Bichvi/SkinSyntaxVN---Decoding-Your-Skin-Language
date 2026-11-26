"""Stage chatbot-compatible metadata alongside existing catalog vectors."""

import argparse
import json
import os
import re

import numpy as np

from recommendation_service.product_repository import product_text
from search_service.catalog import get_mongo_db, load_catalog

MODEL = "sentence-transformers/static-similarity-mrl-multilingual-v1"
DIMENSION = 1024
BATCH = 128
SOURCE = "catalog_products_v1"
FIELDS = (
    "ma_san_pham", "ma_xuat_xu", "xuat_xu_thuong_hieu", "noi_san_xuat", "dung_tich",
    "link_hinh_anh", "hinh_anh", "mo_ta", "thanh_phan_chinh", "thanh_phan_sach",
    "thanh_phan_full", "thanh_phan_day_du", "thanh_phan",
)


def text(value):
    return str(value).strip() if value is not None else ""


def chatbot_metadata(product, raw):
    full = text(raw.get("thanh_phan_sach") or raw.get("thanh_phan_full")
                or raw.get("thanh_phan_day_du") or raw.get("thanh_phan"))
    return {
        "ma_san_pham": product["id"],
        "ten_san_pham": product["ten_san_pham"],
        "thuong_hieu": product["thuong_hieu"],
        "loai_san_pham": product["loai_san_pham"],
        "loai_da": product["loai_da"] or "Unknown",
        "gia_ban": product["price"] or 0,
        "link_hinh_anh": text(raw.get("link_hinh_anh") or raw.get("hinh_anh")).split("|")[0].strip(),
        "mo_ta": text(raw.get("mo_ta"))[:1000],
        "thanh_phan_chinh": (text(raw.get("thanh_phan_chinh")) or full)[:1000],
        "thanh_phan_day_du": full,
        **{key: text(raw.get(key)) for key in ("xuat_xu_thuong_hieu", "noi_san_xuat", "dung_tich")},
    }


def read_rows(collection, ids):
    for start in range(0, len(ids), BATCH):
        batch = collection.get(ids=ids[start:start + BATCH],
                               include=["documents", "metadatas", "embeddings"])
        yield from zip(batch["ids"], batch["documents"], batch["metadatas"],
                       batch["embeddings"], strict=True)


def stage_catalog(source, target, products, raw_rows, embed, expected, apply=False):
    if (source.metadata or {}).get("hnsw:space", "l2") != "l2":
        raise ValueError("unexpected_source_metric")
    if target is not None and (target.metadata or {}).get("hnsw:space") != "l2":
        raise ValueError("unexpected_target_metric")
    source_ids = sorted(source.get(include=[])["ids"])
    catalog = {p["id"]: p for p in products}
    raw = {text(row["ma_san_pham"]): row for row in raw_rows}
    if (expected <= 0 or source.count() != expected or len(source_ids) != expected
            or len(products) != expected or set(source_ids) != catalog.keys()):
        raise ValueError("catalog_id_or_count_mismatch")
    if len(raw) != len(raw_rows) or not catalog.keys() <= raw.keys():
        raise ValueError("mongo_metadata_id_mismatch")

    staged = {}
    sample_ids = {source_ids[0], source_ids[len(source_ids) // 2], source_ids[-1]}
    for pid, document, metadata, embedding in read_rows(source, source_ids):
        if (not re.fullmatch(r"[A-Za-z0-9_-]+", pid) or not document
                or not catalog[pid]["ten_san_pham"]
                or document != product_text(catalog[pid])
                or (metadata or {}).get("product_id") != pid):
            raise ValueError("source_document_or_metadata_mismatch")
        vector = np.asarray(embedding, dtype=np.float32)
        if vector.shape != (DIMENSION,) or not np.isclose(np.linalg.norm(vector), 1, atol=0.001):
            raise ValueError("source_embedding_shape_or_norm_mismatch")
        if pid in sample_ids:
            reference = np.asarray(embed.embed_documents([document])[0], dtype=np.float32)
            if reference.shape != vector.shape or not np.allclose(reference, vector, atol=0.0001):
                raise ValueError("source_embedding_model_mismatch")
        staged["product_" + pid] = (document, chatbot_metadata(catalog[pid], raw[pid]), vector)
    if len(staged) != expected:
        raise ValueError("source_changed_during_read")

    existing = set(target.get(include=[])["ids"]) if target is not None else set()
    if not existing <= staged.keys():
        raise ValueError("unexpected_target_ids")
    verified = set()
    for pid, doc, metadata, vector in read_rows(target, sorted(existing)):
        expected_doc, expected_meta, expected_vector = staged[pid]
        if (doc != expected_doc or metadata != expected_meta
                or not np.array_equal(np.asarray(vector, dtype=np.float32), expected_vector)):
            raise ValueError("target_row_conflict")
        verified.add(pid)
    if verified != existing:
        raise ValueError("target_changed_during_read")
    missing = sorted(staged.keys() - existing)
    if apply:
        if target is None:
            raise ValueError("target_must_be_created_first")
        for start in range(0, len(missing), BATCH):
            ids = missing[start:start + BATCH]
            docs, metas, vectors = zip(*(staged[pid] for pid in ids), strict=True)
            target.add(ids=ids, documents=list(docs), metadatas=list(metas),
                       embeddings=[vector.tolist() for vector in vectors])
        if target.count() != expected:
            raise ValueError("target_count_mismatch_after_add")
    return {"source": expected, "verified_existing": len(existing), "missing": len(missing),
            "mode": "apply" if apply else "dry_run"}


def main():
    import chromadb
    from shared.embedding_provider import get_embedding_function
    from shared.model_config import EMBEDDING_MODEL

    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--target", required=True)
    parser.add_argument("--expected", type=int, required=True)
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    if not re.fullmatch(r"products_rag_[a-z0-9_]{1,40}", args.target):
        parser.error("target must be a dedicated products_rag_* collection")
    if EMBEDDING_MODEL != MODEL:
        parser.error("unexpected EMBEDDING_MODEL")
    try:
        client = chromadb.HttpClient(host=os.environ["CHROMA_HOST"],
                                     port=int(os.getenv("CHROMA_PORT", "8000")))
        source = client.get_collection(SOURCE, embedding_function=None)
        names = {getattr(item, "name", item) for item in client.list_collections()}
        target = (client.get_collection(args.target, embedding_function=None)
                  if args.target in names else None)
        if args.apply and target is None:
            raise ValueError("target_must_be_created_first")
        db = get_mongo_db()
        if db is None:
            raise ValueError("mongo_unavailable")
        products = load_catalog(db)
        ids = [p["id"] for p in products]
        ids += [int(pid) for pid in ids if re.fullmatch(r"[0-9]{1,18}", pid)]
        raw = list(db["san_pham"].find({"ma_san_pham": {"$in": ids}},
                                     {"_id": 0, **dict.fromkeys(FIELDS, 1)}))
        origins = {text(row.get("ma_xuat_xu")): text(row.get("ten_xuat_xu"))
                   for row in db["xuat_xu"].find({}, {"_id": 0, "ma_xuat_xu": 1, "ten_xuat_xu": 1})}
        for row in raw:
            row["xuat_xu_thuong_hieu"] = (origins.get(text(row.get("ma_xuat_xu")))
                                          or row.get("xuat_xu_thuong_hieu"))
        result = stage_catalog(source, target, products, raw, get_embedding_function(),
                               args.expected, args.apply)
        print(json.dumps(result))
        return 0
    except ValueError as exc:
        # Only emit fixed diagnostics from this module, never client exception text.
        code = str(exc)
        print(code if re.fullmatch(r"[a-z_]+", code) else type(exc).__name__)
    except Exception as exc:
        print(type(exc).__name__)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
