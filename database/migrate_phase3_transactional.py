"""
SkinSyntaxVN - Phase 3 Transactional Data Migration Script
Source DB: skinsyntax (STRICTLY READ-ONLY)
Target DB: skinsyntax_v2

Collections handled in Phase 3:
- chi_tiet_hoa_don (Order line items)
- hoa_don (Orders)
- danh_gia_san_pham (Product reviews)

Rules:
1. skinsyntax is NEVER modified (no drop, no update, no delete, no write).
2. Default execution is DRY-RUN. To execute writes to skinsyntax_v2, pass --execute.
3. If collections in skinsyntax_v2 already exist, script halts unless --drop-target is explicitly provided.
4. Product IDs are remapped to new integer range 1..1387 using skinsyntax_v2_product_id_map.csv.
5. Records referencing products not in the 1,387 curated list or invalid customers are dropped.
6. Orders are migrated ONLY if they have >= 1 valid order line after product filtering.
7. Legacy collection 'danh_gia' is NOT migrated in Phase 3.
"""

import os
import sys
import io
import argparse
from datetime import datetime, timezone
import pandas as pd
from pymongo import MongoClient
from collections import defaultdict

# Force UTF-8 stdout for Windows consoles
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding="utf-8")

def parse_args():
    parser = argparse.ArgumentParser(description="SkinSyntaxVN Migration Phase 3 - Transactional Data")
    parser.add_argument("--mongo-uri", default="mongodb://127.0.0.1:27018", help="MongoDB connection URI")
    parser.add_argument("--source-db", default="skinsyntax", help="Source MongoDB database name (READ-ONLY)")
    parser.add_argument("--target-db", default="skinsyntax_v2", help="Target MongoDB database name")
    parser.add_argument("--execute", action="store_true", help="Execute writes to target database (default is dry-run)")
    parser.add_argument("--drop-target", action="store_true", help="Explicitly allow dropping existing target collections before writing")
    parser.add_argument("--recalculate-total", action="store_true", help="Recalculate order tong_tien when order lines have been dropped, saving tong_tien_goc")
    return parser.parse_args()

def run_migration():
    args = parse_args()
    print("=" * 80)
    print(" SKINSYNTAX_V2 MIGRATION - PHASE 3: TRANSACTIONAL DATA")
    print("=" * 80)
    print(f"MongoDB URI       : {args.mongo_uri}")
    print(f"Source Database   : {args.source_db} (STRICTLY READ-ONLY)")
    print(f"Target Database   : {args.target_db}")
    print(f"Execution Mode    : {'*** LIVE EXECUTION ***' if args.execute else 'DRY-RUN (Validation & Audit only - No changes written)'}")
    print(f"Allow Drop Target : {args.drop_target}")
    print(f"Recalculate Total : {args.recalculate_total}")
    print("=" * 80)

    # Base paths
    script_dir = os.path.dirname(os.path.abspath(__file__))
    map_csv_path = os.path.join(script_dir, "skinsyntax_v2_product_id_map.csv")
    if not os.path.exists(map_csv_path):
        raise FileNotFoundError(f"Missing product ID mapping file: {map_csv_path}")

    # Load Product ID Mapping
    map_df = pd.read_csv(map_csv_path)
    prod_map = dict(zip(map_df["ma_san_pham_nguon"].astype(str), map_df["ma_san_pham"].astype(int)))
    print(f"Loaded {len(prod_map)} product mappings from {os.path.basename(map_csv_path)}")

    # Connect to MongoDB
    client = MongoClient(args.mongo_uri)
    src_db = client[args.source_db]
    tgt_db = client[args.target_db]

    phase3_colls = ["chi_tiet_hoa_don", "hoa_don", "danh_gia_san_pham"]

    # Target safety check
    existing_tgt_colls = [c for c in phase3_colls if c in tgt_db.list_collection_names()]
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

    # Validate target reference foundations
    tgt_prod_ids = set(tgt_db.san_pham.distinct("ma_san_pham"))
    tgt_cust_ids = set(tgt_db.khach_hang.distinct("ma_kh"))
    print(f"\nTarget Database foundations:")
    print(f"  - Valid products in target  : {len(tgt_prod_ids)}")
    print(f"  - Valid customers in target : {len(tgt_cust_ids)}")
    assert len(tgt_prod_ids) == 1387, f"Target database missing products: count={len(tgt_prod_ids)}"
    assert len(tgt_cust_ids) == 18, f"Target database missing customers: count={len(tgt_cust_ids)}"

    # -------------------------------------------------------------
    # 1. AUDIT & PREPARE CHI_TIET_HOA_DON
    # -------------------------------------------------------------
    print("\n--- 1. AUDITING CHI_TIET_HOA_DON ---")
    src_lines = list(src_db.chi_tiet_hoa_don.find())
    valid_lines_docs = []
    dropped_lines_count = 0

    for l in src_lines:
        raw_sp = str(l.get("ma_san_pham", "")).strip()
        if raw_sp in prod_map:
            new_sp = prod_map[raw_sp]
            if new_sp in tgt_prod_ids:
                doc_clean = {
                    "id": int(l["id"]) if l.get("id") is not None else None,
                    "ma_hoa_don": int(l["ma_hoa_don"]),
                    "ma_san_pham": int(new_sp),
                    "so_luong": int(l.get("so_luong", 1)),
                    "don_gia": int(l.get("don_gia", 0)),
                    "status_thanh_toan": l.get("status_thanh_toan"),
                    "hinh_thuc_thanh_toan": l.get("hinh_thuc_thanh_toan"),
                    "created_at": l.get("created_at", datetime.now(timezone.utc)),
                }
                valid_lines_docs.append(doc_clean)
            else:
                dropped_lines_count += 1
        else:
            dropped_lines_count += 1

    print(f"Total source lines : {len(src_lines)}")
    print(f"Valid mapped lines : {len(valid_lines_docs)}")
    print(f"Dropped lines      : {dropped_lines_count}")

    # Group valid lines by ma_hoa_don
    valid_lines_by_order = defaultdict(list)
    for l in valid_lines_docs:
        valid_lines_by_order[l["ma_hoa_don"]].append(l)

    all_lines_by_order = defaultdict(list)
    for l in src_lines:
        all_lines_by_order[int(l["ma_hoa_don"])].append(l)

    # -------------------------------------------------------------
    # 2. AUDIT & PREPARE HOA_DON
    # -------------------------------------------------------------
    print("\n--- 2. AUDITING HOA_DON ---")
    src_orders = list(src_db.hoa_don.find())
    migratable_orders_docs = []
    dropped_orders_count = 0
    orphan_customer_orders_count = 0

    matching_totals_count = 0
    discrepant_orders = []

    for o in src_orders:
        oid = int(o["ma_hoa_don"])
        cid = int(o["ma_kh"]) if o.get("ma_kh") is not None else None

        # Check if order has valid lines
        order_lines = valid_lines_by_order.get(oid, [])
        if not order_lines:
            dropped_orders_count += 1
            continue

        # Check customer reference
        if cid not in tgt_cust_ids:
            orphan_customer_orders_count += 1
            print(f"[WARNING] Order #{oid} has customer #{cid} not in target khach_hang!")
            continue

        orig_total = int(o.get("tong_tien", 0))
        shipping = int(o.get("phi_van_chuyen") or 0)
        discount = int(o.get("so_tien_giam") or 0)
        try:
            voucher = float(str(o.get("giam_gia_voucher") or 0))
        except:
            voucher = 0.0
        point_discount = int(o.get("tien_giam_diem") or 0)

        # Calculation
        valid_items_sum = sum(l["so_luong"] * l["don_gia"] for l in order_lines)
        all_items_sum = sum(int(l.get("so_luong", 0)) * int(l.get("don_gia", 0)) for l in all_lines_by_order.get(oid, []))
        calc_net = int(valid_items_sum + shipping - discount - voucher - point_discount)

        is_match = (orig_total == valid_items_sum) or (orig_total == calc_net)
        if is_match:
            matching_totals_count += 1
            final_total = orig_total
        else:
            discrepant_orders.append({
                "ma_hoa_don": oid,
                "ma_kh": cid,
                "orig_tong_tien": orig_total,
                "all_items_sum": all_items_sum,
                "valid_items_sum": valid_items_sum,
                "phi_van_chuyen": shipping,
                "so_tien_giam": discount,
                "giam_gia_voucher": voucher,
                "calc_net": calc_net,
                "all_lines_count": len(all_lines_by_order.get(oid, [])),
                "valid_lines_count": len(order_lines),
            })
            if args.recalculate_total:
                final_total = calc_net
            else:
                final_total = orig_total

        doc_order = {
            "ma_hoa_don": oid,
            "ma_kh": cid,
            "ngay_dat": o.get("ngay_dat"),
            "tong_tien": final_total,
            "trang_thai": o.get("trang_thai"),
            "dia_chi_giao_hang": o.get("dia_chi_giao_hang"),
            "phi_van_chuyen": shipping,
            "so_tien_giam": discount,
            "giam_gia_voucher": voucher,
            "ma_voucher": o.get("ma_voucher"),
            "voucher_code": o.get("voucher_code"),
            "ten_nguoi_nhan": o.get("ten_nguoi_nhan"),
            "sdt_nguoi_nhan": o.get("sdt_nguoi_nhan"),
            "hinh_thuc_thanh_toan": o.get("hinh_thuc_thanh_toan"),
            "status_thanh_toan": o.get("status_thanh_toan"),
            "diem_cong": o.get("diem_cong", 0),
            "da_tich_diem": o.get("da_tich_diem", False),
            "diem_su_dung": o.get("diem_su_dung", 0),
            "tien_giam_diem": point_discount,
            "da_hoan_diem": o.get("da_hoan_diem", False),
            "ly_do_huy": o.get("ly_do_huy"),
            "created_at": o.get("created_at", datetime.now(timezone.utc)),
            "updated_at": o.get("updated_at", datetime.now(timezone.utc)),
        }
        if args.recalculate_total and not is_match:
            doc_order["tong_tien_goc"] = orig_total

        migratable_orders_docs.append(doc_order)

    print(f"Total source orders   : {len(src_orders)}")
    print(f"Migratable orders     : {len(migratable_orders_docs)} (has >= 1 valid item line)")
    print(f"Dropped orders        : {dropped_orders_count} (0 valid items)")
    print(f"Orphan customer orders: {orphan_customer_orders_count}")
    print(f"Orders matching total : {matching_totals_count}")
    print(f"Orders discrepant     : {len(discrepant_orders)}")

    if discrepant_orders:
        print("\n--- ORDER DISCREPANCY AUDIT ---")
        for d in discrepant_orders:
            print(f"  Order #{d['ma_hoa_don']} (KH #{d['ma_kh']}):")
            print(f"    Orig tong_tien  : {d['orig_tong_tien']}")
            print(f"    All lines sum   : {d['all_items_sum']} ({d['all_lines_count']} lines)")
            print(f"    Valid lines sum : {d['valid_items_sum']} ({d['valid_lines_count']} lines)")
            print(f"    Shipping/Discount: ship={d['phi_van_chuyen']}, discount={d['so_tien_giam']}, voucher={d['giam_gia_voucher']}")
            print(f"    Calculated net  : {d['calc_net']}")

    # -------------------------------------------------------------
    # 3. AUDIT & PREPARE DANH_GIA_SAN_PHAM
    # -------------------------------------------------------------
    print("\n--- 3. AUDITING DANH_GIA_SAN_PHAM ---")
    src_reviews = list(src_db.danh_gia_san_pham.find())
    migratable_reviews_docs = []
    dropped_reviews_count = 0

    for r in src_reviews:
        raw_sp = str(r.get("ma_san_pham", "")).strip()
        cid = int(r["ma_khach_hang"]) if r.get("ma_khach_hang") is not None else None

        has_mapping = raw_sp in prod_map
        mapped_sp = prod_map.get(raw_sp)
        sp_valid = mapped_sp in tgt_prod_ids if has_mapping else False
        cust_valid = cid in tgt_cust_ids

        if sp_valid and cust_valid:
            doc_review = {
                "ma_danh_gia": int(r["ma_danh_gia"]) if r.get("ma_danh_gia") is not None else None,
                "ma_san_pham": int(mapped_sp),
                "ma_khach_hang": int(cid),
                "ten_khach_hang": r.get("ten_khach_hang"),
                "so_sao": int(r.get("so_sao", 5)),
                "noi_dung": str(r.get("noi_dung", "")).strip(),
                "hinh_anh": r.get("hinh_anh", []),
                "ngay_danh_gia": r.get("ngay_danh_gia", datetime.now(timezone.utc)),
                "da_mua_hang": bool(r.get("da_mua_hang", False)),
                "phan_hoi_shop": r.get("phan_hoi_shop"),
                "trang_thai": r.get("trang_thai", "hien_thi"),
            }
            migratable_reviews_docs.append(doc_review)
        else:
            dropped_reviews_count += 1
            print(f"  [DROP REVIEW] Review #{r.get('ma_danh_gia')}: raw_sp={raw_sp} (valid={sp_valid}), cust_id={cid} (valid={cust_valid})")

    print(f"Total source reviews : {len(src_reviews)}")
    print(f"Migratable reviews   : {len(migratable_reviews_docs)}")
    print(f"Dropped reviews      : {dropped_reviews_count}")

    # -------------------------------------------------------------
    # 4. PRE-FLIGHT REFERENTIAL INTEGRITY AUDIT
    # -------------------------------------------------------------
    print("\n" + "=" * 80)
    print(" 4. REFERENTIAL INTEGRITY VALIDATION")
    print("=" * 80)

    # Validate lines -> orders
    migratable_order_ids = {o["ma_hoa_don"] for o in migratable_orders_docs}
    for l in valid_lines_docs:
        assert l["ma_hoa_don"] in migratable_order_ids, f"Orphan order line! ma_hoa_don {l['ma_hoa_don']} not in orders"
        assert l["ma_san_pham"] in tgt_prod_ids, f"Orphan product reference! ma_san_pham {l['ma_san_pham']} not in target products"
        assert isinstance(l["ma_san_pham"], int), "ma_san_pham in line item must be int"

    # Validate orders -> customers
    for o in migratable_orders_docs:
        assert o["ma_kh"] in tgt_cust_ids, f"Orphan customer reference! ma_kh {o['ma_kh']} not in target customers"
        assert isinstance(o["ma_hoa_don"], int), "ma_hoa_don must be int"
        assert isinstance(o["ma_kh"], int), "ma_kh must be int"

    # Validate reviews -> products & customers
    for r in migratable_reviews_docs:
        assert r["ma_san_pham"] in tgt_prod_ids, f"Orphan product in review! ma_san_pham {r['ma_san_pham']} not in target products"
        assert r["ma_khach_hang"] in tgt_cust_ids, f"Orphan customer in review! ma_khach_hang {r['ma_khach_hang']} not in target customers"
        assert isinstance(r["ma_san_pham"], int), "ma_san_pham in review must be int"
        assert isinstance(r["ma_khach_hang"], int), "ma_khach_hang in review must be int"

    print("[OK] chi_tiet_hoa_don: 0 orphan product references, 0 orphan order references.")
    print("[OK] hoa_don: 0 orphan customer references.")
    print("[OK] danh_gia_san_pham: 0 orphan product references, 0 orphan customer references.")
    print("[OK] Datatypes: 100% of product and customer references are pure integers.")
    print("=" * 80)

    # -------------------------------------------------------------
    # 5. EXECUTION (IF --execute)
    # -------------------------------------------------------------
    if not args.execute:
        print("\n[RESULT] DRY-RUN COMPLETED! NO DATA WAS WRITTEN TO ANY DATABASE.")
        print("Database 'skinsyntax' was READ-ONLY.")
        print("Database 'skinsyntax_v2' was UNTOUCHED.")
        print("To execute this migration, pass: --execute [--drop-target] [--recalculate-total]\n")
        return

    print("\n--- 5. EXECUTING LIVE MIGRATION TO TARGET DB ---")
    if args.drop_target:
        for c in phase3_colls:
            if c in tgt_db.list_collection_names():
                tgt_db[c].drop()
                print(f"Dropped target collection: {c}")

    print("Inserting chi_tiet_hoa_don...")
    tgt_db.chi_tiet_hoa_don.insert_many(valid_lines_docs)
    tgt_db.chi_tiet_hoa_don.create_index("ma_hoa_don")
    tgt_db.chi_tiet_hoa_don.create_index("ma_san_pham")

    print("Inserting hoa_don...")
    tgt_db.hoa_don.insert_many(migratable_orders_docs)
    tgt_db.hoa_don.create_index("ma_hoa_don", unique=True)
    tgt_db.hoa_don.create_index("ma_kh")
    tgt_db.hoa_don.create_index("trang_thai")

    print("Inserting danh_gia_san_pham...")
    tgt_db.danh_gia_san_pham.insert_many(migratable_reviews_docs)
    tgt_db.danh_gia_san_pham.create_index("ma_san_pham")
    tgt_db.danh_gia_san_pham.create_index("ma_khach_hang")

    # -------------------------------------------------------------
    # 6. POST-MIGRATION VERIFICATION
    # -------------------------------------------------------------
    print("\n--- 6. POST-MIGRATION VERIFICATION ON TARGET DB ---")
    c_ct = tgt_db.chi_tiet_hoa_don.count_documents({})
    c_hd = tgt_db.hoa_don.count_documents({})
    c_dg = tgt_db.danh_gia_san_pham.count_documents({})

    print(f"Target DB '{args.target_db}' collection counts:")
    print(f"  - chi_tiet_hoa_don : {c_ct} (expected {len(valid_lines_docs)})")
    print(f"  - hoa_don          : {c_hd} (expected {len(migratable_orders_docs)})")
    print(f"  - danh_gia_san_pham: {c_dg} (expected {len(migratable_reviews_docs)})")

    assert c_ct == len(valid_lines_docs), "Mismatch in chi_tiet_hoa_don count!"
    assert c_hd == len(migratable_orders_docs), "Mismatch in hoa_don count!"
    assert c_dg == len(migratable_reviews_docs), "Mismatch in danh_gia_san_pham count!"

    print("\n" + "=" * 80)
    print(" [MIGRATION COMPLETE] Phase 3 Transactional Data migrated and validated!")
    print("=" * 80)

if __name__ == "__main__":
    run_migration()
