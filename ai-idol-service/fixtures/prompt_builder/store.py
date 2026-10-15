"""Lớp ghi/đọc Mongo cho dataset pb-fixture-v1. Mọi hàm nhận ``db`` và chỉ ghi khi không ở chế độ ``dry_run``.

An toàn dữ liệu: mọi document sinh ra mang ``test_dataset``; ``reset`` chỉ xoá theo tag đó; lô cũ được chép sang
``inventory_batches_archive`` và xác nhận đủ bản sao rồi mới gỡ khỏi ``inventory_batches``.
"""
from __future__ import annotations

import datetime as dt
import random

from agents.query_sources import _brand_key, bounded_rows, load_catalog

from . import config as cfg
from .catalog_pick import canonical_id, pick_restore_candidates, product_key
from .dataset import COLLECTIONS, MANIFEST_COLLECTION

ARCHIVE = "inventory_batches_archive"
BACKUPS = "pb_fixture_backups"
BACKUP_SOURCE = "san_pham_backup_6377"
ACCOUNTS = "nguoidung"  # tài khoản đăng nhập của khách fixture, do backend/scripts/seed_pb_fixture_accounts.php tạo (có tag)
LEGACY_REGEX = "^auto_generated_"
ID_FIELDS = {"hoa_don": "ma_hoa_don", "chi_tiet_hoa_don": "id", "khach_hang": "ma_kh"}
CHUNK = 500
# Trường catalog mà Agent và bộ sinh dữ liệu đọc (khớp projection của agents.query_sources + loai_da).
PRODUCT_FIELDS = (
    "ma_san_pham", "id", "ten_san_pham", "thuong_hieu", "ten_thuong_hieu", "ma_thuong_hieu", "loai_san_pham",
    "danh_muc_day_du", "trang_thai", "status", "thanh_phan_chinh", "thanh_phan_clean", "thanh_phan_day_du", "mo_ta",
    "gia_ban", "gia_thi_truong", "so_luong_ton_kho", "so_luong_ton", "ton_kho", "stock", "quantity",
    "so_luong_ban", "so_luong_da_ban", "luot_ban", "expiry_date", "ngay_het_han", "diem_danh_gia", "so_luong_danh_gia",
    "loai_da", "ma_loai_da",
)
RESTORE_PROJECTION = ("ma_san_pham", "ten_san_pham", "danh_muc_day_du", "loai_san_pham", "gia_ban", "so_luong_da_ban", "diem_danh_gia")


class PreflightError(RuntimeError):
    """Dữ liệu hiện có không cho phép nạp dataset; thông điệp nói rõ cách xử lý."""


def col(db, name: str):
    return getattr(db, name)


def _chunks(items, size=CHUNK):
    for i in range(0, len(items), size):
        yield items[i:i + size]


def _insert(db, name: str, docs: list[dict]) -> int:
    for chunk in _chunks(docs):
        col(db, name).insert_many(chunk, ordered=False)
    return len(docs)


# ---- Đọc catalog -----------------------------------------------------------------------------------------


def attach_brand_names(db, rows: list[dict]) -> list[dict]:
    brands = {_brand_key(b.get("ma_thuong_hieu")): b.get("ten_thuong_hieu") for b in bounded_rows(
        col(db, "thuong_hieu").find({}, {"_id": 0, "ma_thuong_hieu": 1, "ten_thuong_hieu": 1}), 5000, "Thương hiệu")}
    for row in rows:
        if not (row.get("thuong_hieu") or row.get("ten_thuong_hieu")):
            name = brands.get(_brand_key(row.get("ma_thuong_hieu")))
            if name:
                row["ten_thuong_hieu"] = name
    return rows


def load_products(db) -> list[dict]:
    """Catalog như Agent thấy (đã có tên thương hiệu) cộng loai_da/ma_loai_da cho persona."""
    rows = load_catalog(db)
    extras = {canonical_id(d.get("ma_san_pham")): d for d in bounded_rows(
        col(db, "san_pham").find({}, {"_id": 0, "ma_san_pham": 1, "loai_da": 1, "ma_loai_da": 1, "restored_from": 1}), 20000, "Catalog")}
    for row in rows:
        extra = extras.get(canonical_id(product_key(row)), {})
        row.update({k: v for k, v in extra.items() if k != "ma_san_pham" and v is not None})
    return rows


def restore_rng(seed: int) -> random.Random:
    return random.Random(f"{seed}|restore")


def restore_candidates(db, seed: int, live_ids) -> list[dict]:
    """Sản phẩm thật Trang điểm/Cơ thể/Tóc từ bản backup (chỉ đọc), giữ nguyên field gốc."""
    rows = bounded_rows(col(db, BACKUP_SOURCE).find({}, {"_id": 0, **{k: 1 for k in RESTORE_PROJECTION}}), 50000, "Backup catalog")
    picked = pick_restore_candidates(rows, live_ids, rng=restore_rng(seed))
    ids = [int(canonical_id(product_key(r))) for r in picked if canonical_id(product_key(r)).isdigit()]
    full = {canonical_id(product_key(d)): d for d in bounded_rows(
        col(db, BACKUP_SOURCE).find({"ma_san_pham": {"$in": ids}}, {"_id": 0}), 50000, "Backup catalog")}
    docs = []
    for row in picked:
        doc = dict(full.get(canonical_id(product_key(row)), row))
        doc["trang_thai"] = "active"
        doc["restored_from"] = BACKUP_SOURCE
        docs.append(doc)
    return docs


def simulate_restore(db, seed: int, live_products: list[dict]) -> list[dict]:
    """Các dòng catalog sẽ có sau ``restore-categories`` (không ghi), cùng dạng với ``load_products``.
    Đã khôi phục rồi thì không có gì để mô phỏng thêm."""
    if any(r.get("restored_from") for r in live_products):
        return []
    docs = restore_candidates(db, seed, {canonical_id(product_key(r)) for r in live_products})
    rows = [{k: v for k, v in doc.items() if k in PRODUCT_FIELDS} for doc in docs]
    return attach_brand_names(db, rows)


# ---- apply ---------------------------------------------------------------------------------------------------


def preflight(db, data: dict) -> list[str]:
    """Danh sách lý do không được nạp (rỗng = an toàn)."""
    problems = []
    manifest = col(db, MANIFEST_COLLECTION).find_one({"_id": cfg.DATASET_TAG})
    if manifest is not None:
        problems.append(f"dataset {cfg.DATASET_TAG} đã được nạp (as_of={manifest.get('as_of')}, mới là {data['manifest']['as_of']}); "
                        "chạy reset rồi apply lại để mốc ngày hạn dùng khớp.")
    for name in (*COLLECTIONS, "san_pham"):
        n = col(db, name).count_documents({"test_dataset": cfg.DATASET_TAG})
        if n and manifest is None and name != "san_pham":
            problems.append(f"{name} còn {n} document {cfg.DATASET_TAG} từ lần nạp dở dang; chạy reset trước.")
    for name, field in ID_FIELDS.items():
        taken = col(db, name).count_documents({field: {"$gte": cfg.ID_BASE[name]}})
        if taken:
            problems.append(f"{name}.{field} đã có {taken} document từ {cfg.ID_BASE[name]} trở lên (dải ID dành cho fixture bị chiếm).")
    legacy = col(db, "inventory_batches").count_documents({"data_source": {"$regex": LEGACY_REGEX}})
    if legacy:
        problems.append(f"còn {legacy} lô auto_generated_* đang active; chạy archive-legacy trước (lô cũ làm sai kết quả hạn dùng).")
    return problems


def _effective_stock(batches: list[dict], today: dt.date) -> dict[str, int]:
    from agents.command_query import local_date
    stock: dict[str, int] = {}
    for b in batches:
        if b["status"] == "available" and local_date(b["expires_on"]) > today:
            stock[b["product_id"]] = stock.get(b["product_id"], 0) + int(b["available_quantity"])
    return stock


def sync_catalog(db, data: dict, *, stock: bool, sold: bool, dry_run: bool = False) -> int:
    """Tuỳ chọn: đồng bộ so_luong_ton_kho / so_luong_da_ban của san_pham, có backup giá trị cũ để reset hoàn nguyên."""
    today = dt.date.fromisoformat(data["manifest"]["as_of"])
    totals = _effective_stock(data["inventory_batches"], today)
    units: dict[str, int] = {}
    for line in data["chi_tiet_hoa_don"]:
        units[line["ma_san_pham"]] = units.get(line["ma_san_pham"], 0) + int(line["so_luong"])
    changed = 0
    for pid in sorted(set(totals) | set(units)):
        key = {"ma_san_pham": int(pid) if pid.isdigit() else pid}
        doc = col(db, "san_pham").find_one(key)
        if doc is None:
            continue
        update = {}
        if stock:
            update["so_luong_ton_kho"] = totals.get(pid, 0)
        if sold and pid in units:
            update["so_luong_da_ban"] = int(doc.get("so_luong_da_ban") or 0) + units[pid]
        if not update:
            continue
        changed += 1
        if dry_run:
            continue
        col(db, BACKUPS).insert_one(cfg.tag({"collection": "san_pham", "key": key,
                                             "fields": {k: doc.get(k) for k in update}, "had": [k for k in update if k in doc]}))
        col(db, "san_pham").update_one(key, {"$set": update})
    return changed


def apply_dataset(db, data: dict, *, dry_run: bool = False, sync_stock: bool = False, sync_sold: bool = False) -> dict:
    problems = preflight(db, data)
    if problems:
        raise PreflightError("; ".join(problems))
    counts = {name: len(data[name]) for name in COLLECTIONS}
    if dry_run:
        return {"dry_run": True, "would_write": counts}
    for name in COLLECTIONS:
        _insert(db, name, data[name])
    synced = sync_catalog(db, data, stock=sync_stock, sold=sync_sold) if (sync_stock or sync_sold) else 0
    col(db, MANIFEST_COLLECTION).insert_one(dict(data["manifest"]))  # ghi sau cùng: có manifest nghĩa là đã nạp đủ
    return {"dry_run": False, "written": counts, "catalog_synced": synced}


def refresh_customer_emails(db, data: dict, *, dry_run: bool = False) -> dict:
    """Đưa email khách fixture đã nạp về đúng định dạng của bộ sinh hiện tại (theo ma_kh), kể cả tài khoản đăng nhập."""
    wanted = {c["ma_kh"]: c for c in data["khach_hang"]}
    loaded = list(col(db, "khach_hang").find({"test_dataset": cfg.DATASET_TAG}, {"ma_kh": 1, "email": 1, "ho_ten": 1}))
    drift = [d.get("ma_kh") for d in loaded if d.get("ma_kh") in wanted and wanted[d["ma_kh"]].get("ho_ten") != d.get("ho_ten")]
    if drift:
        raise PreflightError(f"{len(drift)} khách sinh lại không khớp họ tên trong DB (sai --as-of/--seed hoặc catalog đã đổi); không đổi email.")
    changed = 0
    for doc in loaded:
        email = wanted.get(doc.get("ma_kh"), {}).get("email")
        if not email or email == doc.get("email"):
            continue
        changed += 1
        if dry_run:
            continue
        col(db, "khach_hang").update_one({"ma_kh": doc["ma_kh"], "test_dataset": cfg.DATASET_TAG}, {"$set": {"email": email}})
        col(db, ACCOUNTS).update_one({"id": doc["ma_kh"], "test_dataset": cfg.DATASET_TAG}, {"$set": {"email": email}})
    return {"dry_run": dry_run, "changed": changed}


# ---- lô cũ ---------------------------------------------------------------------------------------------------


def archive_legacy(db, *, dry_run: bool = False) -> dict:
    legacy = list(col(db, "inventory_batches").find({"data_source": {"$regex": LEGACY_REGEX}}))
    ids = [b["_id"] for b in legacy]
    if dry_run or not legacy:
        return {"dry_run": dry_run, "legacy_batches": len(legacy), "archived": 0}
    already = {b["_id"] for b in col(db, ARCHIVE).find({"_id": {"$in": ids}})}
    now = dt.datetime.now(dt.timezone.utc)
    copies = [{**b, "archived_at": now, "archived_by_dataset": cfg.DATASET_TAG} for b in legacy if b["_id"] not in already]
    if copies:
        _insert(db, ARCHIVE, copies)
    archived = col(db, ARCHIVE).count_documents({"_id": {"$in": ids}})
    if archived != len(legacy):
        raise RuntimeError(f"Archive chỉ có {archived}/{len(legacy)} bản sao; không gỡ lô gốc.")
    removed = col(db, "inventory_batches").delete_many({"_id": {"$in": ids}}).deleted_count
    return {"dry_run": False, "legacy_batches": len(legacy), "archived": archived, "removed": removed}


def restore_legacy(db, *, dry_run: bool = False) -> dict:
    archived = list(col(db, ARCHIVE).find({"archived_by_dataset": cfg.DATASET_TAG}))
    if dry_run or not archived:
        return {"dry_run": dry_run, "archived_batches": len(archived), "restored": 0}
    ids = [b["_id"] for b in archived]
    present = {b["_id"] for b in col(db, "inventory_batches").find({"_id": {"$in": ids}})}
    originals = [{k: v for k, v in b.items() if k not in {"archived_at", "archived_by_dataset"}} for b in archived if b["_id"] not in present]
    if originals:
        _insert(db, "inventory_batches", originals)
    if col(db, "inventory_batches").count_documents({"_id": {"$in": ids}}) != len(archived):
        raise RuntimeError("Không khôi phục đủ lô; giữ nguyên archive.")
    col(db, ARCHIVE).delete_many({"_id": {"$in": ids}})
    return {"dry_run": False, "archived_batches": len(archived), "restored": len(originals)}


# ---- danh mục khôi phục ----------------------------------------------------------------------------------------


def restore_categories(db, seed: int, *, dry_run: bool = False) -> dict:
    live = load_products(db)
    docs = restore_candidates(db, seed, {canonical_id(product_key(r)) for r in live})
    if dry_run or not docs:
        return {"dry_run": dry_run, "candidates": len(docs), "inserted": 0}
    already = col(db, "san_pham").count_documents({"test_dataset": cfg.DATASET_TAG})
    if already:
        raise PreflightError(f"san_pham đã có {already} sản phẩm khôi phục; chạy reset trước.")
    return {"dry_run": False, "candidates": len(docs), "inserted": _insert(db, "san_pham", [cfg.tag(d) for d in docs])}


# ---- reset ---------------------------------------------------------------------------------------------------


def reset(db, *, dry_run: bool = False) -> dict:
    """Xoá mọi document mang tag, hoàn nguyên giá trị catalog đã sao lưu, xoá snapshot cần dựng lại."""
    names = (*COLLECTIONS, "san_pham", ACCOUNTS, BACKUPS, MANIFEST_COLLECTION)
    manifest = col(db, MANIFEST_COLLECTION).find_one({"_id": cfg.DATASET_TAG})
    before = {name: col(db, name).count_documents({"test_dataset": cfg.DATASET_TAG}) for name in names}
    snap_floor = None
    if manifest is not None:
        snap_floor = (dt.date.fromisoformat(manifest["as_of"]) - dt.timedelta(days=30)).isoformat()
        before["product_metrics_snapshots"] = col(db, "product_metrics_snapshots").count_documents({"as_of": {"$gte": snap_floor}})
        before["product_metrics_runs"] = col(db, "product_metrics_runs").count_documents({"as_of": {"$gte": snap_floor}})
    if dry_run:
        return {"dry_run": True, "before": before, "after": dict(before)}
    restored = 0
    for backup in list(col(db, BACKUPS).find({"test_dataset": cfg.DATASET_TAG})):
        if backup.get("collection") == "san_pham":
            col(db, "san_pham").update_one(backup["key"], {"$set": backup["fields"]})
            restored += 1
    for name in names:
        col(db, name).delete_many({"test_dataset": cfg.DATASET_TAG})
    if snap_floor is not None:
        for name in ("product_metrics_snapshots", "product_metrics_runs"):
            col(db, name).delete_many({"as_of": {"$gte": snap_floor}})
    after = {name: col(db, name).count_documents({"test_dataset": cfg.DATASET_TAG}) for name in names}
    for name in ("product_metrics_snapshots", "product_metrics_runs"):
        if snap_floor is not None:
            after[name] = col(db, name).count_documents({"as_of": {"$gte": snap_floor}})
    return {"dry_run": False, "before": before, "after": after, "catalog_restored": restored}


# ---- verify online -------------------------------------------------------------------------------------------


def verify_online(db) -> tuple[list[dict], dict]:
    """Chạy toàn bộ CASES trên DB thật: catalog qua ``load_catalog``, lô/snapshot qua ``load_query_sources``."""
    from .cases import Ctx, build_cases, run_case
    manifest = col(db, MANIFEST_COLLECTION).find_one({"_id": cfg.DATASET_TAG})
    if manifest is None:
        raise PreflightError("Chưa nạp dataset (không có pb_fixture_manifest); chạy apply trước.")
    legacy = col(db, "inventory_batches").count_documents({"data_source": {"$regex": LEGACY_REGEX}})
    if legacy:
        raise PreflightError(f"còn {legacy} lô auto_generated_* đang active; chạy archive-legacy trước khi verify.")
    today = dt.date.fromisoformat(manifest["as_of"])
    snapshots = bounded_rows(col(db, "product_metrics_snapshots").find({"as_of": manifest["as_of"]}, {"_id": 0}), 20000, "Snapshot")
    if not snapshots:
        raise PreflightError(f"Chưa có snapshot as_of={manifest['as_of']}; chạy python -m core.product_metrics build --as-of {manifest['as_of']}.")
    dataset = {
        "manifest": manifest, "khach_hang": [],
        "inventory_batches": bounded_rows(col(db, "inventory_batches").find({}), 50000, "Lô hàng"),
        "hoa_don": bounded_rows(col(db, "hoa_don").find({}), 200000, "Đơn hàng"),
        "chi_tiet_hoa_don": bounded_rows(col(db, "chi_tiet_hoa_don").find({}), 500000, "Chi tiết đơn"),
        "product_events": bounded_rows(col(db, "product_events").find({}), 500000, "Sự kiện sản phẩm"),
    }
    ctx = Ctx(dataset, load_products(db), today, snapshots)
    return [run_case(case, ctx, db=db) for case in build_cases(ctx)], manifest
