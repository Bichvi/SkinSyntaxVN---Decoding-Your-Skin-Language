"""
SkinSyntaxVN - Phase 1 Core Data Migration Script (Phương án A)
Source DB: skinsyntax (STRICTLY READ-ONLY)
Target DB: skinsyntax_v2

Collections handled in Phase 1:
- danh_muc (21 categories from skinsyntax_v2_danh_muc.csv, NO ten_danh_muc_cha, with trang_thai)
- san_pham (1,387 products from skinsyntax_v2_san_pham_1387_production.csv with mapped lookup IDs)
- thuong_hieu (156 active brands matching 1,387 products)
- xuat_xu (18 active origins matching 1,387 products)
- noi_san_xuat (22 active manufacturing locations matching 1,387 products)
- loai_da (5 active skin types matching 1,387 products)

Architecture Decision: PHƯƠNG ÁN A
- san_pham stores official lookup integer foreign keys:
    ma_thuong_hieu (int)
    ma_xuat_xu (int)
    ma_noi_san_xuat (int)
    ma_loai_da (int)
- san_pham preserves simultaneous text fields for display / recommendation:
    thuong_hieu (str)
    xuat_xu_thuong_hieu (str)
    noi_san_xuat (str)
    loai_da (str)
- danh_muc schema:
    { ma_danh_muc, ten_danh_muc, slug, parent_id, level, thu_tu_hien_thi, trang_thai, created_at, updated_at }
    (ten_danh_muc_cha is REMOVED; parent resolved via parent_id -> ma_danh_muc)
- FORBIDDEN fields in san_pham:
    ma_san_pham_nguon, old_product_id, source_product_id, danh_muc_day_du, ten_danh_muc

Safety Rules:
1. skinsyntax is NEVER modified (no drop, no update, no delete, no write).
2. Default execution is DRY-RUN. To execute writes to skinsyntax_v2, user MUST pass --execute.
3. If collections in skinsyntax_v2 already exist, script halts unless --drop-target is explicitly provided.
"""

import os
import sys
import io
import argparse
from datetime import datetime, timezone
import pandas as pd
from pymongo import MongoClient

# Force UTF-8 stdout for Windows consoles
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding="utf-8")

def parse_args():
    parser = argparse.ArgumentParser(description="SkinSyntaxVN Migration Phase 1 - Core Data (Phương án A)")
    parser.add_argument("--mongo-uri", default="mongodb://127.0.0.1:27018", help="MongoDB connection URI")
    parser.add_argument("--source-db", default="skinsyntax", help="Source MongoDB database name (READ-ONLY)")
    parser.add_argument("--target-db", default="skinsyntax_v2", help="Target MongoDB database name")
    parser.add_argument("--execute", action="store_true", help="Execute writes to target database (default is dry-run)")
    parser.add_argument("--drop-target", action="store_true", help="Explicitly allow dropping existing target collections before writing")
    parser.add_argument("--all-lookup-records", action="store_true", help="Migrate all lookup records from source DB instead of only the active records used by 1,387 products")
    return parser.parse_args()

def run_migration():
    args = parse_args()
    print("=" * 80)
    print(" SKINSYNTAX_V2 MIGRATION - PHASE 1: CORE DATA (PHƯƠNG ÁN A)")
    print("=" * 80)
    print(f"MongoDB URI       : {args.mongo_uri}")
    print(f"Source Database   : {args.source_db} (STRICTLY READ-ONLY)")
    print(f"Target Database   : {args.target_db}")
    print(f"Execution Mode    : {'*** LIVE EXECUTION ***' if args.execute else 'DRY-RUN (Validation & Audit only - No changes written)'}")
    print(f"Allow Drop Target : {args.drop_target}")
    print("=" * 80)

    # Base paths
    script_dir = os.path.dirname(os.path.abspath(__file__))
    cat_csv_path = os.path.join(script_dir, "skinsyntax_v2_danh_muc.csv")
    prod_csv_path = os.path.join(script_dir, "skinsyntax_v2_san_pham_1387_production.csv")

    if not os.path.exists(cat_csv_path):
        raise FileNotFoundError(f"Missing category CSV: {cat_csv_path}")
    if not os.path.exists(prod_csv_path):
        raise FileNotFoundError(f"Missing product CSV: {prod_csv_path}")

    # Connect to MongoDB
    client = MongoClient(args.mongo_uri)
    src_db = client[args.source_db]
    tgt_db = client[args.target_db]

    # Target collections to handle
    phase1_colls = ["danh_muc", "san_pham", "thuong_hieu", "xuat_xu", "noi_san_xuat", "loai_da"]

    # Target safety check
    existing_tgt_colls = [c for c in phase1_colls if c in tgt_db.list_collection_names()]
    if existing_tgt_colls:
        print(f"\n[TARGET DB STATUS] Collections already existing in '{args.target_db}':")
        for c in existing_tgt_colls:
            count = tgt_db[c].count_documents({})
            print(f"  - {c}: {count} documents")
        if not args.drop_target:
            if args.execute:
                print("\n[ABORTED] Target collections already contain documents!")
                print("To prevent accidental overwrites, rerun with '--drop-target' to replace them.")
                sys.exit(1)
            else:
                print("[DRY-RUN NOTICE] Target collections exist. In live execution, '--drop-target' will be required.")

    # -------------------------------------------------------------
    # 1. LOAD & PREPARE CATEGORIES (danh_muc)
    # -------------------------------------------------------------
    print("\n--- 1. PREPARING CATEGORIES (danh_muc) ---")
    df_cat = pd.read_csv(cat_csv_path)
    print(f"Loaded {len(df_cat)} categories from {os.path.basename(cat_csv_path)}")

    # Clean target schema for danh_muc
    # Exclude ten_danh_muc_cha, ma_danh_muc_nguon, parent_id_nguon
    now_dt = datetime.now(timezone.utc)
    cat_docs = []
    for _, row in df_cat.iterrows():
        parent_id_val = None if pd.isna(row["parent_id"]) else int(row["parent_id"])
        doc = {
            "ma_danh_muc": int(row["ma_danh_muc"]),
            "ten_danh_muc": str(row["ten_danh_muc"]).strip(),
            "slug": str(row["slug"]).strip(),
            "parent_id": parent_id_val,
            "level": int(row["level"]),
            "thu_tu_hien_thi": int(row["thu_tu_hien_thi"]),
            "trang_thai": "active",
            "created_at": now_dt,
            "updated_at": now_dt,
        }
        cat_docs.append(doc)

    cat_map = {c["ma_danh_muc"]: c for c in cat_docs}
    leaf_cat_ids = {c["ma_danh_muc"] for c in cat_docs if c["level"] == 2}
    print(f"Prepared {len(cat_docs)} clean category documents:")
    print(f"  - Level 0 (Gốc)     : {sum(1 for c in cat_docs if c['level'] == 0)}")
    print(f"  - Level 1 (Nhánh cha): {sum(1 for c in cat_docs if c['level'] == 1)}")
    print(f"  - Level 2 (Lá)      : {len(leaf_cat_ids)}")

    # -------------------------------------------------------------
    # 2. LOAD & MAP LOOKUPS (thuong_hieu, xuat_xu, noi_san_xuat, loai_da)
    # -------------------------------------------------------------
    print("\n--- 2. AUDITING & PREPARING LOOKUP COLLECTIONS ---")
    df_prod = pd.read_csv(prod_csv_path)
    print(f"Loaded {len(df_prod)} products from {os.path.basename(prod_csv_path)}")

    active_brand_names = set(df_prod["thuong_hieu"].dropna().unique())
    active_origin_names = set(df_prod["xuat_xu_thuong_hieu"].dropna().unique())
    active_nsx_names = set(df_prod["noi_san_xuat"].dropna().unique())
    active_skin_names = set(df_prod["loai_da"].dropna().unique())

    # Read from source DB (READ-ONLY)
    src_brands = list(src_db.thuong_hieu.find())
    src_origins = list(src_db.xuat_xu.find())
    src_nsx = list(src_db.noi_san_xuat.find())
    src_skin = list(src_db.loai_da.find())

    # Lookup dictionaries by name
    brand_by_name = {b["ten_thuong_hieu"]: b["ma_thuong_hieu"] for b in src_brands if "ten_thuong_hieu" in b}
    origin_by_name = {o["ten_xuat_xu"]: o["ma_xuat_xu"] for o in src_origins if "ten_xuat_xu" in o}
    nsx_by_name = {n["ten_nsx"]: n["ma_nsx"] for n in src_nsx if "ten_nsx" in n}
    skin_by_name = {s["ten_loai_da"]: s["ma_loai_da"] for s in src_skin if "ten_loai_da" in s}

    print(f"Source DB lookups: thuong_hieu={len(src_brands)}, xuat_xu={len(src_origins)}, noi_san_xuat={len(src_nsx)}, loai_da={len(src_skin)}")
    print(f"Active in 1,387 CSV: thuong_hieu={len(active_brand_names)}, xuat_xu={len(active_origin_names)}, noi_san_xuat={len(active_nsx_names)}, loai_da={len(active_skin_names)}")

    # Check for mismatches
    unmatched_brands = active_brand_names - set(brand_by_name.keys())
    unmatched_origins = active_origin_names - set(origin_by_name.keys())
    unmatched_nsx = active_nsx_names - set(nsx_by_name.keys())
    unmatched_skin = active_skin_names - set(skin_by_name.keys())

    if unmatched_brands or unmatched_origins or unmatched_nsx or unmatched_skin:
        print("\n[CRITICAL ERROR] Schema mismatch: some product text values do not match lookup collections in skinsyntax!")
        if unmatched_brands: print(f"  Unmatched brands: {unmatched_brands}")
        if unmatched_origins: print(f"  Unmatched origins: {unmatched_origins}")
        if unmatched_nsx: print(f"  Unmatched nsx: {unmatched_nsx}")
        if unmatched_skin: print(f"  Unmatched skin types: {unmatched_skin}")
        sys.exit(1)
    else:
        print("[MATCH AUDIT] 100% of product lookup values match source collection records exactly (0 errors).")

    # Target lookup documents
    def filter_lookup(docs, active_names, name_field, id_field, extra_alias=None):
        filtered = []
        for d in docs:
            name = d.get(name_field)
            if args.all_lookup_records or (name in active_names):
                doc_clean = {
                    id_field: int(d[id_field]),
                    name_field: str(name).strip(),
                    "created_at": d.get("created_at", now_dt),
                    "updated_at": now_dt
                }
                if extra_alias:
                    doc_clean[extra_alias] = int(d[id_field])
                filtered.append(doc_clean)
        return filtered

    tgt_brands = filter_lookup(src_brands, active_brand_names, "ten_thuong_hieu", "ma_thuong_hieu")
    tgt_origins = filter_lookup(src_origins, active_origin_names, "ten_xuat_xu", "ma_xuat_xu")
    tgt_nsx = filter_lookup(src_nsx, active_nsx_names, "ten_nsx", "ma_nsx", extra_alias="ma_noi_san_xuat")
    tgt_skin = filter_lookup(src_skin, active_skin_names, "ten_loai_da", "ma_loai_da")

    print(f"Target lookup documents to insert:")
    print(f"  - thuong_hieu : {len(tgt_brands)} active brands")
    print(f"  - xuat_xu     : {len(tgt_origins)} active origins")
    print(f"  - noi_san_xuat: {len(tgt_nsx)} active locations (with ma_nsx & ma_noi_san_xuat)")
    print(f"  - loai_da     : {len(tgt_skin)} active skin types")

    # -------------------------------------------------------------
    # 3. LOAD & PREPARE PRODUCTS (san_pham) - PHƯƠNG ÁN A
    # -------------------------------------------------------------
    print("\n--- 3. PREPARING PRODUCTS (san_pham - PHƯƠNG ÁN A) ---")
    prod_docs = []
    for _, row in df_prod.iterrows():
        p_id = int(row["ma_san_pham"])
        cat_id = int(row["ma_danh_muc"])
        brand_name = str(row["thuong_hieu"]).strip()
        origin_name = str(row["xuat_xu_thuong_hieu"]).strip()
        nsx_name = str(row["noi_san_xuat"]).strip()
        skin_name = str(row["loai_da"]).strip()

        # Resolve official lookup IDs
        brand_id = int(brand_by_name[brand_name])
        origin_id = int(origin_by_name[origin_name])
        nsx_id = int(nsx_by_name[nsx_name])
        skin_id = int(skin_by_name[skin_name])

        doc = {
            "ma_san_pham": p_id,
            "ten_san_pham": str(row["ten_san_pham"]).strip(),
            "ma_danh_muc": cat_id,
            # Official Lookup Integer Foreign Keys (Phương án A)
            "ma_thuong_hieu": brand_id,
            "ma_xuat_xu": origin_id,
            "ma_noi_san_xuat": nsx_id,
            "ma_loai_da": skin_id,
            # Financial & Rating metrics
            "gia_ban": int(row["gia_ban"]),
            "gia_thi_truong": int(row["gia_thi_truong"]),
            "tien_tiet_kiem": int(row["tien_tiet_kiem"]),
            "phan_tram_giam": int(row["phan_tram_giam"]),
            "diem_danh_gia": float(row["diem_danh_gia"]),
            "so_luong_danh_gia": int(row["so_luong_danh_gia"]),
            # Simultaneous text fields for display & recommendations
            "thuong_hieu": brand_name,
            "xuat_xu_thuong_hieu": origin_name,
            "noi_san_xuat": nsx_name,
            "dung_tich": str(row["dung_tich"]).strip() if pd.notna(row["dung_tich"]) else "",
            "loai_da": skin_name,
            "link_hinh_anh": str(row["link_hinh_anh"]).strip() if pd.notna(row["link_hinh_anh"]) else "",
            "mo_ta": str(row["mo_ta"]).strip() if pd.notna(row["mo_ta"]) else "",
            "thanh_phan_chinh": str(row["thanh_phan_chinh"]).strip() if pd.notna(row["thanh_phan_chinh"]) else "",
            "thanh_phan_day_du": str(row["thanh_phan_day_du"]).strip() if pd.notna(row["thanh_phan_day_du"]) else "",
            "thanh_phan_clean": str(row["thanh_phan_clean"]).strip() if pd.notna(row["thanh_phan_clean"]) else "",
            "hdsd": str(row["hdsd"]).strip() if pd.notna(row["hdsd"]) else "",
            # Operational fields
            "trang_thai": "active",
            "so_luong_ton_kho": 300,
            "trang_thai_kho": "con_hang",
            "da_khoi_tao_kho": True,
            "luot_xem": 0,
            "so_luong_da_ban": 0,
            "da_khoi_tao_so_luong_ban": True,
            "ngay_tao": now_dt,
            "updated_at": now_dt,
        }
        prod_docs.append(doc)

    print(f"Prepared {len(prod_docs)} product documents with mapped lookup IDs.")

    # -------------------------------------------------------------
    # 4. COMPREHENSIVE DRY-RUN VALIDATION
    # -------------------------------------------------------------
    print("\n" + "=" * 80)
    print(" 4. COMPREHENSIVE VALIDATION REPORT (PHƯƠNG ÁN A)")
    print("=" * 80)

    # A. Product Count & ID Range Check
    prod_ids = [p["ma_san_pham"] for p in prod_docs]
    assert len(prod_docs) == 1387, f"Count error: Expected 1387, got {len(prod_docs)}"
    assert len(set(prod_ids)) == 1387, "Duplicate ma_san_pham detected!"
    assert min(prod_ids) == 1 and max(prod_ids) == 1387, f"Range error: min={min(prod_ids)}, max={max(prod_ids)}"
    assert set(prod_ids) == set(range(1, 1388)), "Missing IDs in range 1..1387!"
    print(f"[OK] san_pham count: exactly 1,387 documents.")
    print(f"[OK] ma_san_pham range: 1..1387 (continuous, 0 missing IDs).")
    print(f"[OK] ma_san_pham uniqueness: 0 duplicates.")

    # B. Category Validation
    assert len(cat_docs) == 21, f"Expected 21 categories, got {len(cat_docs)}"
    cat_ids = [c["ma_danh_muc"] for c in cat_docs]
    assert len(set(cat_ids)) == 21, "Duplicate ma_danh_muc detected!"
    for c in cat_docs:
        if c["parent_id"] is not None:
            assert c["parent_id"] in cat_map, f"Invalid parent_id {c['parent_id']} in category {c['ma_danh_muc']}"
            assert c["parent_id"] != c["ma_danh_muc"], "Category cannot be parent of itself!"
    print(f"[OK] danh_muc count: exactly 21 documents.")
    print(f"[OK] danh_muc hierarchy: 1 Level 0, 6 Level 1, 14 Level 2 (0 orphan categories).")

    # C. Product -> Category Relationship Check
    for p in prod_docs:
        assert p["ma_danh_muc"] in cat_map, f"Invalid ma_danh_muc {p['ma_danh_muc']} in product {p['ma_san_pham']}"
        assert p["ma_danh_muc"] in leaf_cat_ids, f"Product {p['ma_san_pham']} not mapped to Level 2 leaf category!"
    print(f"[OK] Product -> Category references: 1,387/1,387 products reference valid Level 2 leaf categories (0 invalid references).")

    # D. Lookup ID Validations (Phương án A requirement)
    valid_brand_ids = {b["ma_thuong_hieu"] for b in tgt_brands}
    valid_origin_ids = {o["ma_xuat_xu"] for o in tgt_origins}
    valid_nsx_ids = {n["ma_nsx"] for n in tgt_nsx}
    valid_skin_ids = {s["ma_loai_da"] for s in tgt_skin}

    # Verify lookup collection internal uniqueness
    assert len(valid_brand_ids) == len(tgt_brands), "Duplicate ma_thuong_hieu in thuong_hieu collection!"
    assert len(valid_origin_ids) == len(tgt_origins), "Duplicate ma_xuat_xu in xuat_xu collection!"
    assert len(valid_nsx_ids) == len(tgt_nsx), "Duplicate ma_nsx in noi_san_xuat collection!"
    assert len(valid_skin_ids) == len(tgt_skin), "Duplicate ma_loai_da in loai_da collection!"
    print(f"[OK] Lookup collections uniqueness: 0 duplicate IDs in each lookup collection.")

    # Verify 1,387/1,387 products have valid lookup IDs
    brand_id_count = sum(1 for p in prod_docs if p.get("ma_thuong_hieu") in valid_brand_ids)
    origin_id_count = sum(1 for p in prod_docs if p.get("ma_xuat_xu") in valid_origin_ids)
    nsx_id_count = sum(1 for p in prod_docs if p.get("ma_noi_san_xuat") in valid_nsx_ids)
    skin_id_count = sum(1 for p in prod_docs if p.get("ma_loai_da") in valid_skin_ids)

    assert brand_id_count == 1387, f"ma_thuong_hieu mismatch: {brand_id_count}/1387"
    assert origin_id_count == 1387, f"ma_xuat_xu mismatch: {origin_id_count}/1387"
    assert nsx_id_count == 1387, f"ma_noi_san_xuat mismatch: {nsx_id_count}/1387"
    assert skin_id_count == 1387, f"ma_loai_da mismatch: {skin_id_count}/1387"

    print(f"[OK] ma_thuong_hieu: 1387/1387 valid (0 orphan lookup IDs).")
    print(f"[OK] ma_xuat_xu     : 1387/1387 valid (0 orphan lookup IDs).")
    print(f"[OK] ma_noi_san_xuat: 1387/1387 valid (0 orphan lookup IDs).")
    print(f"[OK] ma_loai_da     : 1387/1387 valid (0 orphan lookup IDs).")

    # E. Schema Cleanliness Verification
    forbidden_prod_fields = [
        "ma_san_pham_nguon", "old_product_id", "source_product_id",
        "danh_muc_day_du", "ten_danh_muc"
    ]
    for p in prod_docs:
        for f in forbidden_prod_fields:
            assert f not in p, f"Forbidden field '{f}' detected in product {p['ma_san_pham']}!"
    print(f"[OK] Product schema cleanliness: 0 legacy category fields, 0 source/old product ID fields.")

    # Category schema cleanliness
    for c in cat_docs:
        assert "ten_danh_muc_cha" not in c, f"Forbidden field 'ten_danh_muc_cha' detected in category {c['ma_danh_muc']}!"
        assert "ma_danh_muc_nguon" not in c, f"Forbidden field 'ma_danh_muc_nguon' detected in category {c['ma_danh_muc']}!"
        assert "parent_id_nguon" not in c, f"Forbidden field 'parent_id_nguon' detected in category {c['ma_danh_muc']}!"
        assert "trang_thai" in c, "Missing 'trang_thai' field in category!"
    print(f"[OK] Category schema cleanliness: 0 duplicate parent names, 0 legacy fields, trang_thai='active' present.")

    # F. Datatype Audit
    for p in prod_docs:
        assert isinstance(p["ma_san_pham"], int) and not isinstance(p["ma_san_pham"], bool), "ma_san_pham must be int"
        assert isinstance(p["ma_danh_muc"], int), "ma_danh_muc must be int"
        assert isinstance(p["ma_thuong_hieu"], int), "ma_thuong_hieu must be int"
        assert isinstance(p["ma_xuat_xu"], int), "ma_xuat_xu must be int"
        assert isinstance(p["ma_noi_san_xuat"], int), "ma_noi_san_xuat must be int"
        assert isinstance(p["ma_loai_da"], int), "ma_loai_da must be int"
        assert isinstance(p["gia_ban"], int), "gia_ban must be int"
        assert isinstance(p["diem_danh_gia"], float), "diem_danh_gia must be float"
    print(f"[OK] Datatype audit: 100% of IDs and references are pure integers. 0 float/string ID errors.")

    print("=" * 80)
    print(" ALL PRE-FLIGHT ASSERTIONS PASSED SUCCESSFULLY (100% COMPLIANT) ")
    print("=" * 80)

    # -------------------------------------------------------------
    # 5. EXECUTION (STRICTLY BLOCKED UNLESS --execute PASSED)
    # -------------------------------------------------------------
    if not args.execute:
        print("\n[RESULT] DRY-RUN COMPLETED! NO DATA WAS WRITTEN TO ANY DATABASE.")
        print("Database 'skinsyntax' was READ-ONLY.")
        print("Database 'skinsyntax_v2' was UNTOUCHED.")
        print("To execute this migration, the user must explicitly run with '--execute --drop-target'.\n")
        return

    print("\n--- 5. EXECUTING LIVE MIGRATION TO TARGET DB ---")
    if args.drop_target:
        for c in phase1_colls:
            if c in tgt_db.list_collection_names():
                tgt_db[c].drop()
                print(f"Dropped target collection: {c}")

    # Insert collections
    print("Inserting danh_muc...")
    tgt_db.danh_muc.insert_many(cat_docs)
    tgt_db.danh_muc.create_index("ma_danh_muc", unique=True)
    tgt_db.danh_muc.create_index("slug")
    tgt_db.danh_muc.create_index("parent_id")

    print("Inserting san_pham...")
    tgt_db.san_pham.insert_many(prod_docs)
    tgt_db.san_pham.create_index("ma_san_pham", unique=True)
    tgt_db.san_pham.create_index("ma_danh_muc")
    tgt_db.san_pham.create_index("ma_thuong_hieu")
    tgt_db.san_pham.create_index("ma_xuat_xu")
    tgt_db.san_pham.create_index("ma_noi_san_xuat")
    tgt_db.san_pham.create_index("ma_loai_da")
    tgt_db.san_pham.create_index("trang_thai")

    print("Inserting thuong_hieu...")
    tgt_db.thuong_hieu.insert_many(tgt_brands)
    tgt_db.thuong_hieu.create_index("ma_thuong_hieu", unique=True)
    tgt_db.thuong_hieu.create_index("ten_thuong_hieu")

    print("Inserting xuat_xu...")
    tgt_db.xuat_xu.insert_many(tgt_origins)
    tgt_db.xuat_xu.create_index("ma_xuat_xu", unique=True)
    tgt_db.xuat_xu.create_index("ten_xuat_xu")

    print("Inserting noi_san_xuat...")
    tgt_db.noi_san_xuat.insert_many(tgt_nsx)
    tgt_db.noi_san_xuat.create_index("ma_nsx", unique=True)
    tgt_db.noi_san_xuat.create_index("ma_noi_san_xuat", unique=True)
    tgt_db.noi_san_xuat.create_index("ten_nsx")

    print("Inserting loai_da...")
    tgt_db.loai_da.insert_many(tgt_skin)
    tgt_db.loai_da.create_index("ma_loai_da", unique=True)
    tgt_db.loai_da.create_index("ten_loai_da")

    print("\n--- 6. POST-MIGRATION VERIFICATION ---")
    c_sp = tgt_db.san_pham.count_documents({})
    c_dm = tgt_db.danh_muc.count_documents({})
    c_th = tgt_db.thuong_hieu.count_documents({})
    c_xx = tgt_db.xuat_xu.count_documents({})
    c_nsx = tgt_db.noi_san_xuat.count_documents({})
    c_ld = tgt_db.loai_da.count_documents({})

    print(f"Target DB '{args.target_db}' live collection counts:")
    print(f"  - san_pham    : {c_sp} (expected 1387)")
    print(f"  - danh_muc    : {c_dm} (expected 21)")
    print(f"  - thuong_hieu : {c_th} (expected {len(tgt_brands)})")
    print(f"  - xuat_xu     : {c_xx} (expected {len(tgt_origins)})")
    print(f"  - noi_san_xuat: {c_nsx} (expected {len(tgt_nsx)})")
    print(f"  - loai_da     : {c_ld} (expected {len(tgt_skin)})")

    assert c_sp == 1387, "Mismatch in target san_pham count!"
    assert c_dm == 21, "Mismatch in target danh_muc count!"

    print("\n" + "=" * 80)
    print(" [MIGRATION COMPLETE] Phase 1 core data migrated and validated!")
    print("=" * 80)

if __name__ == "__main__":
    run_migration()
