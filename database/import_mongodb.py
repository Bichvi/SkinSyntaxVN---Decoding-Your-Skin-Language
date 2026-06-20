"""
Import data from data_clean_final.csv into MongoDB.

Run locally:
  pip install pymongo pandas tqdm
  python database/import_mongodb.py

Run in Docker (after stack is up):
  docker compose exec ai-service python /app/database/import_mongodb.py
"""

import os
import sys
import pandas as pd
from pathlib import Path
from tqdm import tqdm

try:
    from pymongo import MongoClient, ASCENDING
except ImportError:
    sys.exit("pymongo is not installed. Run: pip install pymongo")

BASE_DIR   = Path(__file__).resolve().parent
CSV_PATH   = BASE_DIR / "data_clean_final.csv"
MONGO_URI  = os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017")
MONGO_DB   = os.getenv("MONGO_DB",  "skinsyntax")
BATCH_SIZE = 500


def _safe_int(val, default=0) -> int:
    try:
        v = float(str(val).replace(",", "").strip())
        return int(v)
    except (ValueError, TypeError):
        return default


def _safe_float(val) -> float | None:
    try:
        v = float(str(val).strip())
        return None if (v != v) else round(v, 2)
    except (ValueError, TypeError):
        return None


def _clean(val) -> str:
    if val is None:
        return ""
    s = str(val).strip()
    return "" if s.lower() in ("nan", "none") else s


def main():
    print(f"CSV file: {CSV_PATH}")
    if not CSV_PATH.exists():
        sys.exit(f"File not found: {CSV_PATH}")

    df = pd.read_csv(CSV_PATH, dtype=str).fillna("")
    print(f"Loaded {len(df):,} rows")

    print(f"Connecting to MongoDB: {MONGO_URI} / db={MONGO_DB}")
    client = MongoClient(MONGO_URI, serverSelectionTimeoutMS=10_000)
    client.admin.command("ping")
    db = client[MONGO_DB]

    COLLECTIONS = ["san_pham", "thuong_hieu", "danh_muc", "xuat_xu", "noi_san_xuat", "loai_san_pham"]
    for col in COLLECTIONS:
        db[col].drop()
        print(f"  Dropped collection: {col}")

    thuong_hieu_map: dict[str, int] = {}
    danh_muc_map:   dict[str, int] = {}
    xuat_xu_map:    dict[str, int] = {}

    th_docs, dm_docs, xx_docs = [], [], []
    th_id = dm_id = xx_id = 1

    for _, row in df.iterrows():
        th = _clean(row.get("thuong_hieu", ""))
        dm = _clean(row.get("danh_muc_day_du", ""))
        xx = _clean(row.get("xuat_xu_thuong_hieu", ""))

        if th and th not in thuong_hieu_map:
            thuong_hieu_map[th] = th_id
            th_docs.append({"ma_thuong_hieu": th_id, "ten_thuong_hieu": th})
            th_id += 1

        if dm and dm not in danh_muc_map:
            danh_muc_map[dm] = dm_id
            dm_docs.append({"ma_danh_muc": dm_id, "ten_danh_muc": dm, "danh_muc_day_du": dm})
            dm_id += 1

        if xx and xx not in xuat_xu_map:
            xuat_xu_map[xx] = xx_id
            xx_docs.append({"ma_xuat_xu": xx_id, "ten_xuat_xu": xx})
            xx_id += 1

    if th_docs:
        db["thuong_hieu"].insert_many(th_docs)
        db["thuong_hieu"].create_index([("ma_thuong_hieu", ASCENDING)], unique=True)
    if dm_docs:
        db["danh_muc"].insert_many(dm_docs)
        db["danh_muc"].create_index([("ma_danh_muc", ASCENDING)], unique=True)
    if xx_docs:
        db["xuat_xu"].insert_many(xx_docs)
        db["xuat_xu"].create_index([("ma_xuat_xu", ASCENDING)], unique=True)

    print(f"  thuong_hieu : {len(th_docs):,}")
    print(f"  danh_muc    : {len(dm_docs):,}")
    print(f"  xuat_xu     : {len(xx_docs):,}")

    seen_ma: set[str] = set()
    sp_batch: list[dict] = []
    total_inserted = 0

    for _, row in tqdm(df.iterrows(), total=len(df), desc="Import san_pham"):
        ma  = _clean(row.get("ma_san_pham", ""))
        ten = _clean(row.get("ten_san_pham", ""))
        if not ma or not ten:
            continue
        if ma in seen_ma:
            continue
        seen_ma.add(ma)

        th = _clean(row.get("thuong_hieu", ""))
        dm = _clean(row.get("danh_muc_day_du", ""))
        xx = _clean(row.get("xuat_xu_thuong_hieu", ""))

        raw_images  = _clean(row.get("link_hinh_anh", ""))
        first_image = raw_images.split(" | ")[0].strip() if raw_images else ""

        doc = {
            "ma_san_pham"        : ma,
            "ten_san_pham"       : ten,
            "ma_thuong_hieu"     : thuong_hieu_map.get(th),
            "ma_danh_muc"        : danh_muc_map.get(dm),
            "ma_xuat_xu"         : xuat_xu_map.get(xx),
            "danh_muc_day_du"    : dm,
            "loai_san_pham"      : _clean(row.get("loai_san_pham", "")),
            "thuong_hieu"        : th,
            "xuat_xu_thuong_hieu": xx,
            "noi_san_xuat"       : _clean(row.get("noi_san_xuat", "")),
            "gia_ban"            : _safe_int(row.get("gia_ban",          0)),
            "gia_thi_truong"     : _safe_int(row.get("gia_thi_truong",   0)),
            "tien_tiet_kiem"     : _safe_int(row.get("tien_tiet_kiem",   0)),
            "phan_tram_giam"     : _safe_int(row.get("phan_tram_giam",   0)),
            "diem_danh_gia"      : _safe_float(row.get("diem_danh_gia",  "")),
            "so_luong_danh_gia"  : _safe_int(row.get("so_luong_danh_gia",0)),
            "dung_tich"          : _clean(row.get("dung_tich",          "")),
            "loai_da"            : _clean(row.get("loai_da",             "")),
            "link_hinh_anh"      : raw_images,
            "hinh_anh"           : first_image,
            "mo_ta"              : _clean(row.get("mo_ta",               "")),
            "thanh_phan_chinh"   : _clean(row.get("thanh_phan_chinh",    "")),
            "thanh_phan_day_du"  : _clean(row.get("thanh_phan_day_du",   "")),
            "thanh_phan_clean"   : _clean(row.get("thanh_phan_clean",    "")),
            "hdsd"               : _clean(row.get("hdsd",                "")),
            "trang_thai"         : "active",
            "status"             : "active",
            "luot_xem"           : 0,
            "so_luong_ban"       : 0,
        }
        sp_batch.append(doc)

        if len(sp_batch) >= BATCH_SIZE:
            db["san_pham"].insert_many(sp_batch, ordered=False)
            total_inserted += len(sp_batch)
            sp_batch.clear()

    if sp_batch:
        db["san_pham"].insert_many(sp_batch, ordered=False)
        total_inserted += len(sp_batch)

    db["san_pham"].create_index([("ma_san_pham",     ASCENDING)], unique=True)
    db["san_pham"].create_index([("ten_san_pham",    ASCENDING)])
    db["san_pham"].create_index([("danh_muc_day_du", ASCENDING)])
    db["san_pham"].create_index([("thuong_hieu",     ASCENDING)])
    db["san_pham"].create_index([("trang_thai",      ASCENDING)])
    db["san_pham"].create_index([("gia_ban",         ASCENDING)])
    db["san_pham"].create_index([("diem_danh_gia",   ASCENDING)])
    db["san_pham"].create_index([("luot_xem",        ASCENDING)])

    print("\nDone!")
    print(f"  san_pham    : {total_inserted:,} products")
    print(f"  thuong_hieu : {len(th_docs):,}")
    print(f"  danh_muc    : {len(dm_docs):,}")
    print(f"  xuat_xu     : {len(xx_docs):,}")


if __name__ == "__main__":
    main()
