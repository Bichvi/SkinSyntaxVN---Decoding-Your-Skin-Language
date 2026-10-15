"""pb-fixture-v1: lớp ghi Mongo (FakeDb): gắn tag, preflight, archive/restore, reset an toàn, dry-run."""
import contextlib
import copy
import datetime as dt
import io
import os
import sys
import unittest

from bson import ObjectId

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from fixtures.prompt_builder import config as cfg
from fixtures.prompt_builder import store
from fixtures.prompt_builder.__main__ import main, parse_args
from fixtures.prompt_builder.catalog_pick import canonical_id, product_key
from tests.fakes import Collection, FakeDb
from tests.pb_catalog import build_catalog

AS_OF = "2026-10-14"
NAMES = (*store.COLLECTIONS, "inventory_batches_archive", "pb_fixture_manifest", "pb_fixture_backups", "san_pham",
         "product_metrics_snapshots", "product_metrics_runs")


def tiny_data(as_of=AS_OF):
    """Dataset tối thiểu nhưng đủ 6 collection; tag đúng như bộ sinh thật."""
    tag = cfg.tag
    batch_id = ObjectId()
    data = {
        "khach_hang": [tag({"ma_kh": 900001, "email": "pb-fixture-1@example.test"})],
        "product_events": [tag({"event_id": "e1", "type": "product_view", "product_id": "101", "session_hash": "s" * 64})],
        "hoa_don": [tag({"ma_hoa_don": 500001, "ma_kh": 900001, "tong_tien": 100000}), tag({"ma_hoa_don": 500002, "ma_kh": 900001})],
        "chi_tiet_hoa_don": [tag({"id": 500001, "ma_hoa_don": 500001, "ma_san_pham": "101", "so_luong": 2, "don_gia": 35000}),
                             tag({"id": 500002, "ma_hoa_don": 500002, "ma_san_pham": "102", "so_luong": 1, "don_gia": 10000})],
        "inventory_batches": [tag({"_id": batch_id, "product_id": "101", "batch_code": "L1", "warehouse_id": "KHO-HCM-01", "status": "available",
                                   "available_quantity": 40, "expires_on": dt.datetime(2028, 3, 13, 17, tzinfo=dt.timezone.utc)})],
        "inventory_movements": [tag({"batch_id": batch_id, "product_id": "101", "type": "receipt", "quantity": 40})],
    }
    data["manifest"] = tag({"_id": cfg.DATASET_TAG, "kind": "manifest", "as_of": as_of, "counts": {k: len(v) for k, v in data.items()}})
    return data


def snapshot(db):
    return {name: copy.deepcopy(getattr(db, name).docs) for name in NAMES}


def real_db():
    """Có dữ liệu thật (không tag) ở mọi collection mà fixture đụng tới."""
    return FakeDb(
        khach_hang=Collection([{"ma_kh": 20, "email": "real@x.vn"}]),
        hoa_don=Collection([{"ma_hoa_don": 38, "ma_kh": 20}]),
        chi_tiet_hoa_don=Collection([{"id": 45, "ma_hoa_don": 38}]),
        inventory_batches=Collection([{"_id": ObjectId(), "product_id": "101", "batch_code": "REAL-1", "status": "available", "data_source": "manual"}]),
        product_events=Collection([]),
        san_pham=Collection([{"ma_san_pham": 101, "so_luong_ton_kho": 300, "so_luong_da_ban": 25}, {"ma_san_pham": 102, "so_luong_ton_kho": 5, "so_luong_da_ban": 1}]),
        product_metrics_snapshots=Collection([{"product_id": "101", "as_of": "2026-10-14", "window_days": 7},
                                              {"product_id": "101", "as_of": "2026-08-01", "window_days": 7}]),
        product_metrics_runs=Collection([{"as_of": "2026-10-14"}, {"as_of": "2026-08-01"}]),
    )


class ApplyTests(unittest.TestCase):
    def test_apply_tags_everything(self):
        db, data = real_db(), tiny_data()
        result = store.apply_dataset(db, data)
        self.assertEqual(result["written"], {name: len(data[name]) for name in store.COLLECTIONS})
        for name in store.COLLECTIONS:
            tagged = [d for d in getattr(db, name).docs if d.get("test_dataset")]
            self.assertEqual(len(tagged), len(data[name]), name)
            for doc in tagged:
                self.assertEqual((doc["test_dataset"], doc["data_source"], doc["needs_verification"]), ("pb-fixture-v1", "pb_fixture", True))
        manifest = db.pb_fixture_manifest.docs
        self.assertEqual((len(manifest), manifest[0]["as_of"]), (1, AS_OF))
        self.assertEqual(len(db.hoa_don.docs), 3)  # đơn thật vẫn còn

    def test_apply_refuses_when_id_range_taken(self):
        for name, doc in (("hoa_don", {"ma_hoa_don": 500005}), ("chi_tiet_hoa_don", {"id": 500010}), ("khach_hang", {"ma_kh": 900002})):
            with self.subTest(collection=name):
                db = real_db()
                getattr(db, name).insert_one(dict(doc))
                before = snapshot(db)
                with self.assertRaisesRegex(store.PreflightError, "dải ID"):
                    store.apply_dataset(db, tiny_data())
                self.assertEqual(snapshot(db), before)

    def test_apply_refuses_when_legacy_active(self):
        db = real_db()
        db.inventory_batches.insert_one({"product_id": "9", "batch_code": "AUTO-9", "status": "available", "data_source": "auto_generated_diverse_demo"})
        before = snapshot(db)
        with self.assertRaisesRegex(store.PreflightError, "1 lô auto_generated_\\*"):
            store.apply_dataset(db, tiny_data())
        self.assertEqual(snapshot(db), before)

    def test_apply_refuses_existing_dataset_with_other_as_of(self):
        db = real_db()
        store.apply_dataset(db, tiny_data("2026-10-01"))
        with self.assertRaisesRegex(store.PreflightError, "reset"):
            store.apply_dataset(db, tiny_data(AS_OF))
        self.assertEqual(len(db.hoa_don.docs), 3)

    def test_apply_refuses_partial_leftovers(self):
        db = real_db()
        db.product_events.insert_one(cfg.tag({"event_id": "left-over"}))
        with self.assertRaisesRegex(store.PreflightError, "dở dang"):
            store.apply_dataset(db, tiny_data())

    def test_dry_run_writes_nothing(self):
        db = real_db()
        db.inventory_batches.insert_one({"product_id": "9", "batch_code": "AUTO-9", "status": "available", "data_source": "auto_generated_x"})
        before = snapshot(db)
        self.assertTrue(store.archive_legacy(db, dry_run=True)["dry_run"])
        self.assertEqual(store.reset(db, dry_run=True)["before"]["hoa_don"], 0)
        self.assertEqual(snapshot(db), before)
        clean = real_db()
        before = snapshot(clean)
        result = store.apply_dataset(clean, tiny_data(), dry_run=True, sync_stock=True)
        self.assertEqual(result["would_write"]["hoa_don"], 2)
        self.assertEqual(snapshot(clean), before)
        for name in NAMES:
            self.assertEqual(getattr(clean, name).writes, [])


class ArchiveTests(unittest.TestCase):
    def legacy_db(self):
        db = real_db()
        for n in range(3):
            db.inventory_batches.insert_one({"product_id": str(n), "batch_code": f"AUTO-{n}", "status": "available",
                                             "data_source": "auto_generated_default", "available_quantity": 300})
        return db

    def test_archive_then_restore_roundtrip(self):
        db = self.legacy_db()
        original = {str(d["_id"]): {k: v for k, v in d.items()} for d in db.inventory_batches.docs}
        result = store.archive_legacy(db)
        self.assertEqual((result["legacy_batches"], result["archived"], result["removed"]), (3, 3, 3))
        self.assertEqual([d["batch_code"] for d in db.inventory_batches.docs], ["REAL-1"])  # lô thật không bị đụng
        archived = db.inventory_batches_archive.docs
        self.assertTrue(all(d["archived_by_dataset"] == "pb-fixture-v1" and d["archived_at"] for d in archived))
        self.assertEqual(store.archive_legacy(db)["legacy_batches"], 0)  # chạy lại không làm gì
        restored = store.restore_legacy(db)
        self.assertEqual(restored["restored"], 3)
        self.assertEqual(db.inventory_batches_archive.docs, [])
        back = {str(d["_id"]): d for d in db.inventory_batches.docs if d.get("data_source", "").startswith("auto_generated_")}
        self.assertEqual(set(back), set(original) - {str(d["_id"]) for d in db.inventory_batches.docs if d["batch_code"] == "REAL-1"})
        self.assertEqual(len(back), 3)
        for key, doc in back.items():
            self.assertEqual(doc, original[key])

    def test_archive_keeps_originals_if_copies_are_missing(self):
        db = self.legacy_db()
        db.inventory_batches_archive.insert_many = lambda docs, ordered=True: None  # mô phỏng ghi archive thất bại
        with self.assertRaisesRegex(RuntimeError, "0/3"):
            store.archive_legacy(db)
        self.assertEqual(len(db.inventory_batches.docs), 4)


class ResetTests(unittest.TestCase):
    def test_reset_only_removes_tagged(self):
        db = real_db()
        untagged = {name: copy.deepcopy(getattr(db, name).docs) for name in (*store.COLLECTIONS, "san_pham")}
        store.apply_dataset(db, tiny_data(), sync_stock=True, sync_sold=True)
        db.san_pham.insert_one(cfg.tag({"ma_san_pham": 777, "restored_from": "san_pham_backup_6377"}))
        result = store.reset(db)
        self.assertEqual(result["before"]["hoa_don"], 2)
        self.assertTrue(all(n == 0 for k, n in result["after"].items() if k not in {"product_metrics_snapshots", "product_metrics_runs"}))
        for name in (*store.COLLECTIONS, "san_pham"):
            self.assertEqual([d for d in getattr(db, name).docs], untagged[name], name)
        self.assertEqual(db.pb_fixture_manifest.docs, [])
        self.assertEqual(db.pb_fixture_backups.docs, [])

    def test_reset_removes_fixture_login_accounts_only(self):
        db = real_db()
        db.nguoidung.insert_one({"id": 1, "email": "real@x.vn", "mat_khau": "$2y$real"})
        store.apply_dataset(db, tiny_data())
        db.nguoidung.insert_one(cfg.tag({"id": 900001, "email": "pb-fixture-1@example.test", "mat_khau": "$2y$fixture"}))
        result = store.reset(db)
        self.assertEqual(result["before"]["nguoidung"], 1)
        self.assertEqual(result["after"]["nguoidung"], 0)
        self.assertEqual([d["email"] for d in db.nguoidung.docs], ["real@x.vn"])

    def test_refresh_emails_updates_customers_and_their_accounts(self):
        db = real_db()
        data = tiny_data()
        store.apply_dataset(db, data)
        db.nguoidung.insert_one(cfg.tag({"id": 900001, "email": "pb-fixture-1@example.test"}))
        data["khach_hang"][0]["email"] = "lan.nguyen001@example.test"
        self.assertEqual(store.refresh_customer_emails(db, data, dry_run=True)["changed"], 1)
        self.assertEqual(db.khach_hang.find_one({"ma_kh": 900001})["email"], "pb-fixture-1@example.test")
        self.assertEqual(store.refresh_customer_emails(db, data)["changed"], 1)
        self.assertEqual(db.khach_hang.find_one({"ma_kh": 900001})["email"], "lan.nguyen001@example.test")
        self.assertEqual(db.nguoidung.find_one({"id": 900001})["email"], "lan.nguyen001@example.test")
        self.assertEqual(db.khach_hang.find_one({"ma_kh": 20})["email"], "real@x.vn")
        self.assertEqual(store.refresh_customer_emails(db, data)["changed"], 0)

    def test_refresh_emails_refuses_when_regenerated_names_drift(self):
        db = real_db()
        data = tiny_data()
        store.apply_dataset(db, data)
        data["khach_hang"][0].update(email="other.name001@example.test", ho_ten="Tên Khác")
        with self.assertRaises(store.PreflightError):
            store.refresh_customer_emails(db, data)
        self.assertEqual(db.khach_hang.find_one({"ma_kh": 900001})["email"], "pb-fixture-1@example.test")

    def test_reset_restores_catalog_values_and_drops_recent_snapshots(self):
        db = real_db()
        store.apply_dataset(db, tiny_data(), sync_stock=True, sync_sold=True)
        synced = {d["ma_san_pham"]: d for d in db.san_pham.docs}
        self.assertEqual(synced[101]["so_luong_ton_kho"], 40)
        self.assertEqual(synced[101]["so_luong_da_ban"], 27)
        store.reset(db)
        restored = {d["ma_san_pham"]: d for d in db.san_pham.docs}
        self.assertEqual((restored[101]["so_luong_ton_kho"], restored[101]["so_luong_da_ban"]), (300, 25))
        self.assertEqual((restored[102]["so_luong_ton_kho"], restored[102]["so_luong_da_ban"]), (5, 1))
        self.assertEqual([d["as_of"] for d in db.product_metrics_snapshots.docs], ["2026-08-01"])
        self.assertEqual([d["as_of"] for d in db.product_metrics_runs.docs], ["2026-08-01"])

    def test_reset_is_idempotent(self):
        db = real_db()
        store.apply_dataset(db, tiny_data())
        store.reset(db)
        before = snapshot(db)
        again = store.reset(db)
        self.assertEqual(again["before"]["hoa_don"], 0)
        self.assertEqual(snapshot(db), before)


class RestoreCategoriesTests(unittest.TestCase):
    def db_with_backup(self):
        catalog = build_catalog()
        db = real_db()
        db.san_pham = Collection(catalog.live)
        db.san_pham_backup_6377 = Collection(catalog.backup)
        db.thuong_hieu = Collection([{"ma_thuong_hieu": k, "ten_thuong_hieu": v} for k, v in __import__("tests.pb_catalog", fromlist=["BRANDS"]).BRANDS.items()])
        return db, catalog

    def test_restore_inserts_tagged_active_products_and_reset_removes_them(self):
        db, catalog = self.db_with_backup()
        live_before = len(db.san_pham.docs)
        result = store.restore_categories(db, cfg.DEFAULT_SEED)
        self.assertEqual(result["inserted"], len(catalog.restored))
        added = [d for d in db.san_pham.docs if d.get("test_dataset")]
        self.assertEqual(len(added), result["inserted"])
        self.assertTrue(all(d["trang_thai"] == "active" and d["restored_from"] == "san_pham_backup_6377" for d in added))
        live_ids = {canonical_id(d["ma_san_pham"]) for d in db.san_pham.docs[:live_before]}
        self.assertFalse(live_ids & {canonical_id(d["ma_san_pham"]) for d in added})
        self.assertEqual(len(db.san_pham_backup_6377.docs), len(catalog.backup))  # backup không bị đụng
        with self.assertRaises(store.PreflightError):
            store.restore_categories(db, cfg.DEFAULT_SEED)
        store.reset(db)
        self.assertEqual(len(db.san_pham.docs), live_before)

    def test_plan_simulation_equals_real_restore(self):
        db, _ = self.db_with_backup()
        simulated = store.simulate_restore(db, cfg.DEFAULT_SEED, store.load_products(db))
        store.restore_categories(db, cfg.DEFAULT_SEED)
        after = store.load_products(db)
        restored_ids = {canonical_id(product_key(r)) for r in after if r.get("restored_from")}
        self.assertEqual({canonical_id(product_key(r)) for r in simulated}, restored_ids)
        self.assertEqual(store.simulate_restore(db, cfg.DEFAULT_SEED, after), [])  # đã khôi phục: không mô phỏng lại
        self.assertTrue(all(r.get("ten_thuong_hieu") for r in simulated))

    def test_dry_run_inserts_nothing(self):
        db, _ = self.db_with_backup()
        before = len(db.san_pham.docs)
        self.assertGreater(store.restore_categories(db, 1, dry_run=True)["candidates"], 0)
        self.assertEqual(len(db.san_pham.docs), before)


class CliTests(unittest.TestCase):
    def test_parse_defaults_and_flags(self):
        args = parse_args(["apply", "--dry-run", "--with-dirty", "--sync-catalog-stock", "--seed", "7"])
        self.assertEqual((args.command, args.seed, args.dry_run, args.with_dirty, args.sync_catalog_stock, args.sync_catalog_sold),
                         ("apply", 7, True, True, True, False))
        self.assertEqual(parse_args(["plan"]).seed, cfg.DEFAULT_SEED)

    def test_write_commands_need_explicit_confirmation(self):
        for command in ("apply", "reset", "archive-legacy", "restore-categories", "restore-legacy"):
            with contextlib.redirect_stderr(io.StringIO()) as err:
                self.assertEqual(main([command]), 2, command)
            self.assertIn("--yes", err.getvalue())


if __name__ == "__main__":
    unittest.main()
