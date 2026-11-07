"""pb-fixture-v1: khách hàng, phiên xem/thêm giỏ và đơn hàng; số liệu đo bằng engine snapshot thật."""
import datetime as dt
import hashlib
import os
import re
import sys
import unittest
from collections import Counter, defaultdict

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
from agents.command_query import _category_matches
from agents.intent_parser import normalize_text
from core.product_metrics import is_revenue_order
from fixtures.prompt_builder import config as cfg
from fixtures.prompt_builder.catalog_pick import product_key
from fixtures.prompt_builder.commerce import VN, budget_fit, local_dt, skin_fit
from fixtures.prompt_builder.dataset import build_dataset, snapshot_docs
from tests.pb_catalog import build_catalog

TODAY = dt.date(2026, 10, 14)
CATALOG = build_catalog()
MIDNIGHT = local_dt(TODAY, 0)

_cache = {}


def dataset(seed=cfg.DEFAULT_SEED, today=TODAY, dirty=False):
    key = (seed, today, dirty)
    if key not in _cache:
        data = build_dataset(CATALOG.products, today, seed, with_dirty=dirty)
        _cache[key] = (data, snapshot_docs(data, CATALOG.products, today))
    return _cache[key]


def snap(snapshots, window):
    return {d["product_id"]: d for d in snapshots if d["window_days"] == window}


class CustomerTests(unittest.TestCase):
    def test_customers_have_name_emails_and_reserved_range(self):
        data, _ = dataset()
        customers = data["khach_hang"]
        self.assertEqual(len(customers), 160)
        self.assertEqual([c["ma_kh"] for c in customers], list(range(900001, 900161)))
        self.assertTrue(all(re.fullmatch(r"[a-z]+\.[a-z]+\d{3}@example\.test", c["email"]) for c in customers))
        self.assertEqual(len({c["email"] for c in customers}), 160)
        first = customers[0]
        given, family = normalize_text(first["ho_ten"]).split()[-1], normalize_text(first["ho_ten"]).split()[0]
        self.assertEqual(first["email"], f"{given}.{family}001@example.test")
        # Mật khẩu không nằm trong khach_hang: tài khoản đăng nhập do backend/scripts/seed_pb_fixture_accounts.php tạo.
        self.assertTrue(all("mat_khau" not in c and "password" not in c for c in customers))
        skin = Counter(c["fixture_persona"]["skin_type"] for c in customers)
        self.assertEqual(skin.most_common(1)[0][0], "Da dầu/Hỗn hợp dầu")
        self.assertTrue(all(1985 <= c["nam_sinh"] <= 2006 for c in customers))
        self.assertTrue(all(c["fixture_persona"]["favorite_brands"] for c in customers))
        self.assertTrue(len({c["ho_ten"] for c in customers}) > 100)

    def test_same_seed_and_day_give_same_dataset(self):
        a, _ = dataset()
        b = build_dataset(CATALOG.products, TODAY, cfg.DEFAULT_SEED)
        self.assertEqual([(o["ma_hoa_don"], o["tong_tien"], o["analytics_sid"]) for o in a["hoa_don"]],
                         [(o["ma_hoa_don"], o["tong_tien"], o["analytics_sid"]) for o in b["hoa_don"]])
        c, _ = dataset(seed=1)
        self.assertNotEqual([o["tong_tien"] for o in a["hoa_don"]], [o["tong_tien"] for o in c["hoa_don"]])


class OrderTests(unittest.TestCase):
    def setUp(self):
        self.data, self.snapshots = dataset()
        self.lines = defaultdict(list)
        for line in self.data["chi_tiet_hoa_don"]:
            self.lines[line["ma_hoa_don"]].append(line)

    def test_orders_internally_consistent(self):
        orders = self.data["hoa_don"]
        self.assertGreater(len(orders), 1500)
        for order in orders:
            lines = self.lines[order["ma_hoa_don"]]
            self.assertTrue(1 <= len(lines) <= 4)
            self.assertEqual(order["tam_tinh"], sum(l["so_luong"] * l["don_gia"] for l in lines))
            self.assertEqual(order["tong_tien"], order["tam_tinh"] - order["so_tien_giam"] - order["tien_giam_diem"] + order["phi_van_chuyen"])
            self.assertEqual(order["phi_van_chuyen"], 30000)
            self.assertLess(order["ngay_dat"], MIDNIGHT)
            if order["trang_thai"] == "Hoàn thành":
                self.assertGreater(order["ngay_hoan_thanh"], order["ngay_dat"])
                self.assertLess(order["ngay_hoan_thanh"], MIDNIGHT)
                self.assertEqual(order["trang_thai_normalized"], "completed")
                self.assertEqual(order["status_thanh_toan"], "Đã thanh toán")
                self.assertTrue(is_revenue_order(order))
            else:
                self.assertNotIn("ngay_hoan_thanh", order)
            if order["hinh_thuc_thanh_toan"] == "bank_transfer_qr" and order["trang_thai"] == "Hoàn thành":
                self.assertEqual(order["status_thanh_toan"], "Đã thanh toán")
        methods = Counter(o["hinh_thuc_thanh_toan"] for o in orders)
        self.assertGreater(methods["cod"], methods["bank_transfer_qr"] * 1.6)

    def test_ids_use_the_reserved_ranges(self):
        orders = self.data["hoa_don"]
        self.assertEqual(orders[0]["ma_hoa_don"], 500001)
        self.assertEqual([o["ma_hoa_don"] for o in orders], list(range(500001, 500001 + len(orders))))
        self.assertEqual([l["id"] for l in self.data["chi_tiet_hoa_don"]], list(range(500001, 500001 + len(self.data["chi_tiet_hoa_don"]))))
        self.assertTrue(all(isinstance(l["ma_san_pham"], str) for l in self.data["chi_tiet_hoa_don"]))
        for doc in [*orders, *self.data["chi_tiet_hoa_don"], *self.data["product_events"], *self.data["khach_hang"]]:
            self.assertEqual((doc["test_dataset"], doc["data_source"], doc["needs_verification"]), ("pb-fixture-v1", "pb_fixture", True))

    def test_status_matches_order_age(self):
        by_age = defaultdict(Counter)
        for order in self.data["hoa_don"]:
            age = (TODAY - order["ngay_dat"].astimezone(VN).date()).days
            by_age[age >= 5][order["trang_thai"]] += 1
            if age >= 5:
                self.assertNotIn(order["trang_thai"], {"Chờ xử lý", "Đang giao", "Đã xác nhận"})
        old = by_age[True]
        total = sum(old.values())
        self.assertGreater(old["Hoàn thành"] / total, 0.88)
        self.assertTrue(0.03 < old["Đã hủy"] / total < 0.12)
        recent = by_age[False]
        self.assertTrue({"Đang giao", "Đã xác nhận", "Chờ xử lý"} & set(recent), recent)

    def test_order_sid_follows_view(self):
        views = {}
        for event in self.data["product_events"]:
            if event["type"] == "product_view":
                views[(event["session_hash"], event["product_id"])] = min(event["event_time"], views.get((event["session_hash"], event["product_id"]), event["event_time"]))
        with_sid = [o for o in self.data["hoa_don"] if o["analytics_sid"]]
        self.assertGreater(len(with_sid), 100)
        for order in with_sid:
            self.assertRegex(order["analytics_sid"], r"^[a-f0-9]{64}$")
            for line in self.lines[order["ma_hoa_don"]]:
                seen = views.get((order["analytics_sid"], line["ma_san_pham"]))
                self.assertIsNotNone(seen, "đơn có sid nhưng không có lượt xem")
                self.assertLessEqual(seen, order["ngay_dat"])
        share = len(with_sid) / len(self.data["hoa_don"])
        self.assertTrue(0.2 < share < 0.95, share)

    def test_events_follow_tracker_schema(self):
        for event in self.data["product_events"][:2000]:
            expected = hashlib.sha256(f"{event['type']}|{event['session_hash']}|{event['product_id']}|"
                                      f"{event['event_time'].astimezone(VN).date().isoformat()}".encode()).hexdigest()
            self.assertEqual(event["event_id"], expected)
            self.assertEqual(event["schema_version"], 1)
            self.assertIn(event["type"], {"product_view", "add_to_cart"})
        self.assertEqual(len({e["event_id"] for e in self.data["product_events"]}), len(self.data["product_events"]))

    def test_skin_and_budget_affinity(self):
        customers = {c["ma_kh"]: c["fixture_persona"] for c in self.data["khach_hang"]}
        orders = {o["ma_hoa_don"]: o for o in self.data["hoa_don"]}
        products = {product_key(r): r for r in CATALOG.products}
        fits = []
        for line in self.data["chi_tiet_hoa_don"]:
            persona = customers[orders[line["ma_hoa_don"]]["ma_kh"]]
            row = products[line["ma_san_pham"]]
            fits.append((skin_fit(persona["skin_key"], row["loai_da"]) >= 0.6,
                         budget_fit(persona["budget_tier"], float(row["gia_ban"])) >= 0.9))
        self.assertGreaterEqual(sum(f[0] for f in fits) / len(fits), 0.75)
        self.assertGreaterEqual(sum(f[1] for f in fits) / len(fits), 0.70)

    def test_no_sales_for_unsellable_or_anchor_products(self):
        manifest = self.data["manifest"]
        sold = {l["ma_san_pham"] for l in self.data["chi_tiet_hoa_don"]}
        banned = set(manifest["profiles"]["stock_zero"]) | set(manifest["profiles"]["expired_status"]) | {manifest["anchors"]["A"], manifest["anchors"]["C"]}
        self.assertTrue(banned)
        self.assertFalse(sold & banned)

    def test_time_of_day_and_calendar_effects(self):
        hours = Counter(o["ngay_dat"].astimezone(VN).hour for o in self.data["hoa_don"])
        peak = hours[11] + hours[12] + hours[13] + hours[20] + hours[21] + hours[22] + hours[23]
        self.assertGreater(peak / len(self.data["hoa_don"]), 0.45)
        per_day = Counter(o["ngay_dat"].astimezone(VN).date() for o in self.data["hoa_don"])
        flash = dt.date.fromisoformat(self.data["stats"]["flash_day"])
        neighbours = [per_day[flash + dt.timedelta(days=n)] for n in (-2, -1, 1, 2) if flash + dt.timedelta(days=n) in per_day]
        self.assertGreater(per_day[flash], 1.5 * (sum(neighbours) / len(neighbours)))


class SessionAndMetricTests(unittest.TestCase):
    def setUp(self):
        self.data, self.snapshots = dataset()
        self.s7, self.s30 = snap(self.snapshots, 7), snap(self.snapshots, 30)
        self.engineered = self.data["manifest"]["engineered"]

    def test_sessions_covered(self):
        for docs in (self.s7, self.s30):
            self.assertTrue(all(d["coverage"]["sessions"] for d in docs.values()), next(iter(docs.values()))["coverage"])
            self.assertTrue(all(d["coverage_complete"] for d in docs.values()))
        views = Counter(e["event_time"].astimezone(VN).date() for e in self.data["product_events"] if e["type"] == "product_view")
        for n in range(1, cfg.SESSION_DAYS + 1):
            self.assertGreater(views[TODAY - dt.timedelta(days=n)], 0)
        self.assertEqual(self.data["manifest"]["tracking_started_on"], (TODAY - dt.timedelta(days=45)).isoformat())

    def test_session_volume_and_funnel(self):
        sessions_per_day = Counter()
        seen = set()
        for event in self.data["product_events"]:
            key = (event["session_hash"], event["event_time"].astimezone(VN).date())
            if key not in seen and not key[0] in seen:
                seen.add(key)
                sessions_per_day[key[1]] += 1
        counts = [n for n in sessions_per_day.values()]
        self.assertTrue(250 <= sum(counts) / len(counts) <= 900, sum(counts) / len(counts))
        views = Counter(e["type"] for e in self.data["product_events"])
        self.assertTrue(0.04 < views["add_to_cart"] / views["product_view"] < 0.20, views)

    def test_engineered_cases_measurable(self):
        e = self.engineered
        self.assertEqual(len(e["views_no_orders"]), 6)
        for pid in e["views_no_orders"]:
            self.assertGreaterEqual(self.s30[pid]["viewed_sessions"], 120)
            self.assertLessEqual(self.s30[pid]["viewed_sessions"], 260)
            self.assertEqual(self.s30[pid]["converted_sessions"], 0)
        for pid in e["growth"]:
            doc = self.s7[pid]
            self.assertGreater(doc["previous_units_sold"], 0)
            self.assertGreaterEqual(doc["units_sold"], 1.8 * doc["previous_units_sold"])
        for pid in e["decline"]:
            self.assertLess(self.s7[pid]["units_sold"], self.s7[pid]["previous_units_sold"])
        self.assertGreaterEqual(sum(1 for d in self.s30.values() if d["cart_sessions"] >= 25), 8)
        for pid in e["cart"]:
            self.assertTrue(25 <= self.s30[pid]["cart_sessions"] <= 80, self.s30[pid]["cart_sessions"])

    def test_revenue_low_conversion_group(self):
        e = self.engineered
        group = {pid: self.s30[pid] for pid in e["revenue_low_conversion"]}
        self.assertEqual(len(group), 11)
        self.assertTrue(all(d["viewed_sessions"] >= 100 for d in group.values()))
        for pid in e["whales"]:
            rate = group[pid]["converted_sessions"] / group[pid]["viewed_sessions"]
            self.assertTrue(0.015 <= rate <= 0.045, rate)
            self.assertGreaterEqual(group[pid]["viewed_sessions"], 400)
        others = [group[p] for p in e["revenue_low_conversion"] if p not in e["whales"]]
        for doc in others:
            self.assertTrue(0.07 <= doc["converted_sessions"] / doc["viewed_sessions"] <= 0.16)
        top_revenue = sorted(group.values(), key=lambda d: -d["net_revenue"])[:3]
        self.assertEqual({d["product_id"] for d in top_revenue}, set(e["whales"]))

    def test_engineered_products_are_sunscreens_and_disjoint(self):
        products = {product_key(r): r for r in CATALOG.products}
        groups = [self.engineered[k] for k in ("growth", "decline", "views_no_orders", "cart", "revenue_low_conversion")]
        ids = [pid for group in groups for pid in group]
        self.assertEqual(len(ids), len(set(ids)))
        self.assertEqual((len(ids), len(self.engineered["whales"])), (6 + 3 + 6 + 10 + 11, 3))
        self.assertTrue(all(_category_matches(products[pid], "sunscreen") for g in groups[:4] for pid in g))
        self.assertTrue(all(_category_matches(products[pid], "cleanser") for pid in groups[4]))
        self.assertFalse(set(ids) & set(self.data["manifest"]["anchors"].values()))

    def test_dirty_mode_adds_unreconciled_returns(self):
        data, snapshots = dataset(dirty=True)
        returns = [m for m in data["inventory_movements"] if m["type"] == "return"]
        self.assertGreater(len(returns), 5)
        flagged = [d for d in snapshots if "return_unreconciled" in d["coverage"]["reasons"]]
        self.assertTrue(flagged)
        for batch in data["inventory_batches"]:
            self.assertGreaterEqual(batch["available_quantity"], 0)
            self.assertEqual(batch["initial_quantity"], batch["available_quantity"] + batch["sold_quantity"] + batch["damaged_quantity"])


if __name__ == "__main__":
    unittest.main()
