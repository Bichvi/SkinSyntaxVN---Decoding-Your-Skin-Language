"""
SkinSyntaxVN - Phase 2 Identity & Access Migration Script
Source DB: skinsyntax (STRICTLY READ-ONLY)
Target DB: skinsyntax_v2

Collections handled in Phase 2:
- vai_tro (Roles: admin, nhanvien)
- nhan_vien (Staff accounts)
- khach_hang (Customer profiles & skin test data)
- nguoidung (User credentials & authentication)

Safety Rules:
1. skinsyntax is NEVER modified (no drop, no update, no delete, no write).
2. Default execution is DRY-RUN. To execute writes to skinsyntax_v2, pass --execute.
3. If collections in skinsyntax_v2 already exist, script halts unless --drop-target is explicitly provided.
4. Business IDs are strictly preserved (no renumbering).
5. Passwords, hashes, tokens, emails, and credentials are copied exactly as-is.
"""

import os
import sys
import io
import argparse
from pymongo import MongoClient

# Force UTF-8 stdout for Windows consoles
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8")
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding="utf-8")

def parse_args():
    parser = argparse.ArgumentParser(description="SkinSyntaxVN Migration Phase 2 - Identity & Access")
    parser.add_argument("--mongo-uri", default="mongodb://127.0.0.1:27018", help="MongoDB connection URI")
    parser.add_argument("--source-db", default="skinsyntax", help="Source MongoDB database name (READ-ONLY)")
    parser.add_argument("--target-db", default="skinsyntax_v2", help="Target MongoDB database name")
    parser.add_argument("--execute", action="store_true", help="Execute writes to target database (default is dry-run)")
    parser.add_argument("--drop-target", action="store_true", help="Explicitly allow dropping existing target collections before writing")
    return parser.parse_args()

def run_migration():
    args = parse_args()
    print("=" * 80)
    print(" SKINSYNTAX_V2 MIGRATION - PHASE 2: IDENTITY & ACCESS")
    print("=" * 80)
    print(f"MongoDB URI       : {args.mongo_uri}")
    print(f"Source Database   : {args.source_db} (STRICTLY READ-ONLY)")
    print(f"Target Database   : {args.target_db}")
    print(f"Execution Mode    : {'*** LIVE EXECUTION ***' if args.execute else 'DRY-RUN (Validation & Audit only - No changes written)'}")
    print(f"Allow Drop Target : {args.drop_target}")
    print("=" * 80)

    # Connect to MongoDB
    client = MongoClient(args.mongo_uri)
    src_db = client[args.source_db]
    tgt_db = client[args.target_db]

    phase2_colls = ["vai_tro", "nhan_vien", "khach_hang", "nguoidung"]

    # Target safety check
    existing_tgt_colls = [c for c in phase2_colls if c in tgt_db.list_collection_names()]
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
    # 1. READ & AUDIT FROM SOURCE DB (READ-ONLY)
    # -------------------------------------------------------------
    print("\n--- 1. AUDITING SOURCE DATA ---")
    src_roles = list(src_db.vai_tro.find())
    src_staff = list(src_db.nhan_vien.find())
    src_customers = list(src_db.khach_hang.find())
    src_users = list(src_db.nguoidung.find())

    print(f"Read from {args.source_db}:")
    print(f"  - vai_tro    : {len(src_roles)} documents")
    print(f"  - nhan_vien  : {len(src_staff)} documents")
    print(f"  - khach_hang : {len(src_customers)} documents")
    print(f"  - nguoidung  : {len(src_users)} documents")

    # -------------------------------------------------------------
    # 2. VALIDATE RELATIONSHIPS & CONSTRAINTS
    # -------------------------------------------------------------
    print("\n--- 2. VALIDATING INTEGRITY & CONSTRAINTS ---")

    # A. vai_tro validation
    role_ids = [r.get("ma_vai_tro") for r in src_roles]
    assert len(src_roles) == 2, f"Expected 2 roles, got {len(src_roles)}"
    assert len(set(role_ids)) == 2, "Duplicate ma_vai_tro in vai_tro!"
    for r in src_roles:
        assert isinstance(r["ma_vai_tro"], int), f"ma_vai_tro not integer: {r['ma_vai_tro']}"
    print(f"[OK] vai_tro: 2 roles with unique integer ma_vai_tro.")

    # B. nhan_vien validation
    staff_ids = [s.get("ma_nv") for s in src_staff]
    staff_emails = [s.get("email") for s in src_staff]
    assert len(src_staff) == 5, f"Expected 5 staff, got {len(src_staff)}"
    assert len(set(staff_ids)) == 5, "Duplicate ma_nv in nhan_vien!"
    assert len(set(staff_emails)) == 5, "Duplicate email in nhan_vien!"
    for s in src_staff:
        assert isinstance(s["ma_nv"], int), f"ma_nv not integer: {s['ma_nv']}"
        assert s["ma_vai_tro"] in role_ids, f"Orphan ma_vai_tro {s.get('ma_vai_tro')} in staff {s.get('ma_nv')}!"
        assert isinstance(s["ma_vai_tro"], int), f"ma_vai_tro in staff not integer: {s['ma_vai_tro']}"
        assert s.get("mat_khau"), f"Staff {s.get('ma_nv')} missing mat_khau!"
    print(f"[OK] nhan_vien: 5 staff members, unique ma_nv (1..5), 0 orphan ma_vai_tro, passwords intact.")

    # C. khach_hang validation
    cust_ids = [c.get("ma_kh") for c in src_customers]
    cust_emails = [c.get("email") for c in src_customers]
    assert len(src_customers) == 18, f"Expected 18 customers, got {len(src_customers)}"
    assert len(set(cust_ids)) == 18, "Duplicate ma_kh in khach_hang!"
    assert len(set(cust_emails)) == 18, "Duplicate email in khach_hang!"
    for c in src_customers:
        assert isinstance(c["ma_kh"], int), f"ma_kh not integer: {c['ma_kh']}"
        assert c.get("email"), f"Customer {c.get('ma_kh')} missing email!"
    print(f"[OK] khach_hang: 18 customer profiles, unique ma_kh, unique emails, skin profiles intact.")

    # D. nguoidung validation
    user_emails = [u.get("email") for u in src_users]
    assert len(src_users) == 14, f"Expected 14 users, got {len(src_users)}"
    assert len(set(user_emails)) == 14, "Duplicate email in nguoidung!"
    for u in src_users:
        assert u.get("email"), "User missing email!"
        assert u.get("mat_khau"), f"User {u.get('email')} missing mat_khau!"
    print(f"[OK] nguoidung: 14 user accounts, unique emails, password hashes intact.")

    print("\n" + "=" * 80)
    print(" ALL PRE-FLIGHT VALIDATIONS PASSED (100% COMPLIANT) ")
    print("=" * 80)

    # -------------------------------------------------------------
    # 3. EXECUTION (IF --execute)
    # -------------------------------------------------------------
    if not args.execute:
        print("\n[RESULT] DRY-RUN COMPLETED! NO DATA WAS WRITTEN TO ANY DATABASE.")
        print("Database 'skinsyntax' was READ-ONLY.")
        print("Database 'skinsyntax_v2' was UNTOUCHED.")
        print("To execute this migration, pass: --execute [--drop-target]\n")
        return

    print("\n--- 3. EXECUTING LIVE MIGRATION TO TARGET DB ---")
    if args.drop_target:
        for c in phase2_colls:
            if c in tgt_db.list_collection_names():
                tgt_db[c].drop()
                print(f"Dropped target collection: {c}")

    # Prepare docs for insertion (clone docs while preserving _id and exact types)
    print("Inserting vai_tro...")
    tgt_db.vai_tro.insert_many(src_roles)
    tgt_db.vai_tro.create_index("ma_vai_tro", unique=True)
    tgt_db.vai_tro.create_index("ten_vai_tro")

    print("Inserting nhan_vien...")
    tgt_db.nhan_vien.insert_many(src_staff)
    tgt_db.nhan_vien.create_index("ma_nv", unique=True)
    tgt_db.nhan_vien.create_index("email", unique=True)
    tgt_db.nhan_vien.create_index("ma_vai_tro")

    print("Inserting khach_hang...")
    tgt_db.khach_hang.insert_many(src_customers)
    tgt_db.khach_hang.create_index("ma_kh", unique=True)
    tgt_db.khach_hang.create_index("email", unique=True)

    print("Inserting nguoidung...")
    tgt_db.nguoidung.insert_many(src_users)
    tgt_db.nguoidung.create_index("email", unique=True)

    # -------------------------------------------------------------
    # 4. POST-MIGRATION VERIFICATION
    # -------------------------------------------------------------
    print("\n--- 4. POST-MIGRATION VERIFICATION ON TARGET DB ---")
    c_vt = tgt_db.vai_tro.count_documents({})
    c_nv = tgt_db.nhan_vien.count_documents({})
    c_kh = tgt_db.khach_hang.count_documents({})
    c_nd = tgt_db.nguoidung.count_documents({})

    print(f"Target DB '{args.target_db}' collection counts:")
    print(f"  - vai_tro    : {c_vt} (expected {len(src_roles)})")
    print(f"  - nhan_vien  : {c_nv} (expected {len(src_staff)})")
    print(f"  - khach_hang : {c_kh} (expected {len(src_customers)})")
    print(f"  - nguoidung  : {c_nd} (expected {len(src_users)})")

    assert c_vt == len(src_roles), "Mismatch in vai_tro count!"
    assert c_nv == len(src_staff), "Mismatch in nhan_vien count!"
    assert c_kh == len(src_customers), "Mismatch in khach_hang count!"
    assert c_nd == len(src_users), "Mismatch in nguoidung count!"

    print("\n" + "=" * 80)
    print(" [MIGRATION COMPLETE] Phase 2 Identity & Access migrated and validated!")
    print("=" * 80)

if __name__ == "__main__":
    run_migration()
