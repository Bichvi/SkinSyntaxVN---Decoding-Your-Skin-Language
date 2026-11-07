"""pb-fixture-v1: lô hàng theo ma trận hạn dùng / tồn kho / phạm vi kho (T = 2026-10-14)."""
import datetime as dt
import os
import random
import sys
import unittest
from collections import Counter

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from agents.command_query import add_months, local_date
from fixtures.prompt_builder import config as cfg
from fixtures.prompt_builder.batches import allocate_fefo, effective_stock, generate_batches, generate_inventory
from fixtures.prompt_builder.catalog_pick import classify_product, product_key, shelf_life_months
from tests.pb_catalog import build_catalog

TODAY = dt.date(2026, 10, 14)
CATALOG = build_catalog()


def build(seed=5, sold=None):
    return generate_batches(CATALOG.products, TODAY, random.Random(seed), sold)


class BatchMatrixTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.batches, cls.manifest = build()
        cls.by_product = {}
        for batch in cls.batches:
            cls.by_product.setdefault(batch["product_id"], []).append(batch)

    def expiry_dates(self):
        return {local_date(b["expires_on"]).isoformat() for b in self.batches}

    def test_boundary_lots_exist(self):
        dates = self.expiry_dates()
        for expected in ("2028-03-14", "2028-05-14", "2028-03-13", "2028-05-15", "2028-04-14", "2028-04-15"):
            self.assertIn(expected, dates)

    def test_boundary_lot_is_stored_at_vietnam_midnight(self):
        lot = next(b for b in self.batches if local_date(b["expires_on"]) == dt.date(2028, 3, 14))
        self.assertEqual((lot["expires_on"].hour, lot["expires_on"].minute), (17, 0))  # 00:00 giờ VN = 17:00Z hôm trước
        self.assertEqual(lot["expires_on"].date(), dt.date(2028, 3, 13))

    def test_anchor_lots_match_user_examples(self):
        a, b, c = (self.manifest["anchors"][k] for k in "ABC")
        lot_a, = self.by_product[a]
        self.assertEqual((lot_a["available_quantity"], local_date(lot_a["manufactured_on"]), local_date(lot_a["expires_on"])),
                         (200, dt.date(2025, 12, 20), dt.date(2028, 5, 20)))
        lot_b = next(x for x in self.by_product[b] if x["anchor"] == "B")
        self.assertEqual((lot_b["available_quantity"], local_date(lot_b["manufactured_on"]), local_date(lot_b["expires_on"])),
                         (100, dt.date(2026, 1, 1), dt.date(2028, 6, 1)))
        lot_c, = self.by_product[c]
        self.assertEqual((lot_c["available_quantity"], local_date(lot_c["expires_on"])), (20, add_months(TODAY, 20)))
        # Ba ví dụ nằm ngoài cả "17–19 tháng" lẫn "tối đa 18 tháng" (ca âm), A và B lọt "tồn kho trên 100".
        for lot in (lot_a, lot_b, lot_c):
            expiry = local_date(lot["expires_on"])
            self.assertFalse(add_months(TODAY, 17) <= expiry <= add_months(TODAY, 19))
            self.assertFalse(expiry <= add_months(TODAY, 18))
        self.assertGreater(effective_stock(self.batches, a, TODAY), 100)
        self.assertGreater(effective_stock(self.batches, b, TODAY), 100)
        for pid in (a, b, c):
            self.assertFalse([x for x in self.by_product[pid] if add_months(TODAY, 17) <= local_date(x["expires_on"]) <= add_months(TODAY, 19)])

    def test_shelf_life_consistent(self):
        products = {product_key(r): r for r in CATALOG.products}
        checked = 0
        for batch in self.batches:
            self.assertLessEqual(local_date(batch["received_on"]), TODAY)
            self.assertGreaterEqual(local_date(batch["received_on"]), local_date(batch["manufactured_on"]))
            if batch["anchor"] in {"A", "B"}:
                continue  # ví dụ của người dùng có NSX/HSD cố định
            months = shelf_life_months(products[batch["product_id"]])
            self.assertEqual(add_months(local_date(batch["manufactured_on"]), months), local_date(batch["expires_on"]), batch["case_profile"])
            checked += 1
        self.assertGreater(checked, 1000)

    def test_every_active_product_has_ledger(self):
        self.assertEqual({product_key(r) for r in CATALOG.products}, set(self.by_product))

    def test_no_duplicate_identity_and_varied_quantities(self):
        identity = Counter((b["product_id"], b["warehouse_id"], b["batch_code"]) for b in self.batches)
        self.assertEqual([k for k, n in identity.items() if n > 1], [])
        self.assertGreaterEqual(len({b["initial_quantity"] for b in self.batches}) / len(self.batches), 0.4)
        self.assertGreater(len({b["batch_code"][:1] for b in self.batches}), 3)  # nhiều kiểu mã lô

    def test_stock_boundaries(self):
        totals = Counter(effective_stock(self.batches, pid, TODAY) for pid in self.by_product)
        for value in (0, 1, 10, 11, 100, 101):
            self.assertGreater(totals[value], 0, f"không có sản phẩm tồn hiệu lực = {value}")

    def test_every_focus_category_has_the_designed_cases(self):
        profiles = self.manifest["profiles"]
        self.assertEqual(self.manifest["shortfalls"], {})
        self.assertGreaterEqual(len(profiles["w1719_big"]) + len(profiles["w1719_small"]) + len(profiles["w1719_mid"]),
                                12 * len(cfg.FOCUS_CATEGORIES))
        self.assertGreaterEqual(len(profiles["dn_1719"]), 3 * len(cfg.FOCUS_CATEGORIES))

    def test_wrong_status_lots_never_count(self):
        for pid in self.manifest["profiles"]["wrong_status"]:
            self.assertEqual(effective_stock(self.batches, pid, TODAY), 0)
        for pid in self.manifest["profiles"]["expired_status"] + self.manifest["profiles"]["expired_dirty"]:
            self.assertEqual(effective_stock(self.batches, pid, TODAY), 0)

    def test_scope_batch_and_warehouse_manifest(self):
        self.assertEqual(self.manifest["scope_warehouse"], "KHO-DN-01")
        lots = [b for b in self.batches if b["batch_code"] == self.manifest["scope_batch_code"]]
        self.assertEqual(len(lots), 1)
        self.assertEqual(lots[0]["warehouse_id"], "KHO-DN-01")
        self.assertEqual(Counter(b["warehouse_id"] for b in self.batches).most_common(1)[0][0], "KHO-HCM-01")

    def test_everything_is_tagged_and_deterministic(self):
        for batch in self.batches:
            self.assertEqual((batch["test_dataset"], batch["data_source"], batch["needs_verification"]),
                             ("pb-fixture-v1", "pb_fixture", True))
        again, _ = build()
        self.assertEqual([(b["product_id"], b["batch_code"], b["initial_quantity"]) for b in self.batches],
                         [(b["product_id"], b["batch_code"], b["initial_quantity"]) for b in again])


class FefoTests(unittest.TestCase):
    def test_fefo_never_negative_and_identity_holds(self):
        pids = [product_key(r) for r in CATALOG.products if classify_product(r) == "sunscreen"][:40]
        rng = random.Random(3)
        sold = {pid: [(dt.datetime(2026, 9, 1, 3, tzinfo=dt.timezone.utc) + dt.timedelta(days=rng.randint(0, 40)),
                       rng.choice(list(cfg.WAREHOUSES)), rng.randint(1, 4)) for _ in range(30)] for pid in pids}
        batches, movements, _ = generate_inventory(CATALOG.products, TODAY, random.Random(5), sold)
        for batch in batches:
            self.assertGreaterEqual(batch["available_quantity"], 0)
            self.assertEqual(batch["initial_quantity"], batch["available_quantity"] + batch["sold_quantity"]
                             + batch["damaged_quantity"] + batch["reserved_quantity"])
        sales = [m for m in movements if m["type"] == "sale"]
        self.assertGreater(len(sales), 0)
        for movement in movements:
            self.assertGreaterEqual(movement["quantity_after"], 0)
        lot_ids = {b["_id"] for b in batches}
        self.assertTrue(all(m["batch_id"] in lot_ids for m in movements))

    def test_movements_replay_to_available_quantity(self):
        batches, movements, _ = generate_inventory(CATALOG.products, TODAY, random.Random(5), {})
        by_lot = {}
        for movement in movements:
            by_lot.setdefault(movement["batch_id"], []).append(movement)
        for batch in batches:
            events = sorted(by_lot[batch["_id"]], key=lambda m: m["occurred_at"])
            self.assertEqual(events[0]["type"], "receipt")
            self.assertEqual(events[-1]["quantity_after"], batch["available_quantity"])

    def test_allocate_fefo_uses_earliest_expiry_in_same_warehouse_first(self):
        def lot(code, warehouse, expires, qty):
            return {"_id": code, "product_id": "1", "batch_code": code, "warehouse_id": warehouse, "status": "available",
                    "available_quantity": qty, "initial_quantity": qty, "sold_quantity": 0, "anchor": None,
                    "expires_on": dt.datetime(*expires, tzinfo=dt.timezone.utc), "received_on": dt.datetime(2026, 1, 1, tzinfo=dt.timezone.utc)}
        lots = [lot("late", "KHO-HN-01", (2028, 1, 1), 5), lot("soon", "KHO-HN-01", (2027, 1, 1), 5),
                lot("other", "KHO-HCM-01", (2026, 12, 1), 5)]
        moves = allocate_fefo(lots, [(dt.datetime(2026, 10, 1, tzinfo=dt.timezone.utc), "KHO-HN-01", 2)])
        self.assertEqual([m["batch_id"] for m in moves], ["soon"])
        self.assertEqual(lots[1]["sold_quantity"], 2)
        self.assertEqual(lots[1]["initial_quantity"], 7)
        self.assertEqual(lots[1]["available_quantity"], 5)

    def test_history_sales_precede_the_order_window(self):
        _, movements, _ = generate_inventory(CATALOG.products, TODAY, random.Random(5), {})
        self.assertFalse([m for m in movements if m["type"] == "sale" and m["occurred_at"] >= dt.datetime(2026, 8, 15, tzinfo=dt.timezone.utc)])


if __name__ == "__main__":
    unittest.main()
