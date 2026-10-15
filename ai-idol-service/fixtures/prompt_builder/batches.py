"""Sinh lô hàng và movement theo ma trận hạn dùng / tồn kho / phạm vi kho của Prompt Builder.

Hàm thuần: cùng ``rng`` seed + cùng ``today`` cho cùng kết quả. Mọi mốc ngày tính bằng
``command_query.add_months`` để biên khớp tuyệt đối với engine.
"""
from __future__ import annotations

import datetime as dt
import zlib
from collections import Counter
from dataclasses import dataclass
from zoneinfo import ZoneInfo

from bson import ObjectId

from agents.command_query import _category_matches, add_months, local_date, number
from agents.intent_parser import normalize_text
from config import TIMEZONE

from . import config as cfg
from .catalog_pick import product_key, shelf_life_months

VN = ZoneInfo(TIMEZONE)
DAY = dt.timedelta(days=1)

# Thứ tự ưu tiên: thiếu sản phẩm thì các ô cuối bị bỏ và ghi vào manifest["shortfalls"].
FOCUS_SLOTS = (
    ("w1719_edge_low", 1), ("w1719_edge_high", 1), ("w1719_out_low", 1), ("w1719_out_high", 1),
    ("le18_edge_in", 1), ("le18_edge_out", 1),
    ("w1719_big", 4), ("w1719_small", 4), ("w1719_mid", 4),
    ("le18_big", 2), ("le18_small", 2),
    ("dn_1719", 3), ("dn_le18", 3), ("dn_big", 3), ("dn_small", 3),
    ("wrong_status", 3), ("multi_lot", 2), ("near_expiry", 3),
    ("stock_101", 1), ("stock_100", 1), ("stock_big", 3), ("stock_low", 5),
    ("stock_zero", 2), ("expired_status", 2), ("expired_dirty", 1),
)
SMALL_QTY = (2, 4, 6, 9, 3, 5, 8, 10)
LOW_QTY = (1, 3, 7, 10, 11)
RECEIVE_LEAD_DAYS = 70  # lô nhập trước cửa sổ bán 60 ngày để mọi đơn đều có lô hợp lệ


def vn_midnight(day: dt.date) -> dt.datetime:
    """Nửa đêm giờ Việt Nam dưới dạng UTC aware (đúng cách InventoryBatch::parseDate lưu)."""
    return dt.datetime(day.year, day.month, day.day, tzinfo=VN).astimezone(dt.timezone.utc)


@dataclass
class LotSpec:
    product_id: str
    warehouse_id: str
    status: str
    expires_on: dt.date
    manufactured_on: dt.date
    received_on: dt.date
    available: int
    hist_sold: int = 0
    damaged: int = 0
    profile: str = "base"
    anchor: str | None = None
    brand: str = ""
    batch_code: str = ""

    @property
    def sellable(self) -> bool:
        return self.status == "available" and self.available > 0 and self.anchor is None


@dataclass
class InventoryPlan:
    today: dt.date
    lots: dict[str, list[LotSpec]]
    profiles: dict[str, list[str]]
    anchors: dict[str, str]
    shortfalls: dict[str, dict[str, int]]
    scope_batch_code: str | None
    scope_warehouse: str = cfg.SCOPE_WAREHOUSE

    @property
    def no_sale(self) -> set[str]:
        """Sản phẩm không có lô bán được: đơn hàng giả không được chứa chúng."""
        return {pid for pid, lots in self.lots.items() if not any(lot.sellable for lot in lots)}


def active_products(products) -> list[dict]:
    seen: set[str] = set()
    rows = []
    for row in sorted(products, key=lambda r: product_key(r)):
        pid = product_key(row)
        status = normalize_text(row.get("trang_thai", row.get("status", "active")))
        if not pid or pid in seen or not str(row.get("ten_san_pham") or "").strip() or status in {"inactive", "hidden", "disabled", "0"}:
            continue
        seen.add(pid)
        rows.append(row)
    return rows


def _brand_text(row: dict) -> str:
    return str(row.get("ten_thuong_hieu") or row.get("thuong_hieu") or "")


class _Builder:
    def __init__(self, today: dt.date, rng):
        self.today, self.rng = today, rng
        self.lo17, self.hi18, self.hi19 = add_months(today, 17), add_months(today, 18), add_months(today, 19)
        self.seen_codes: set[tuple[str, str, str]] = set()

    # ---- giá trị ngẫu nhiên có chủ đích -------------------------------------------------
    def warehouse(self) -> str:
        return self.rng.choices(list(cfg.WAREHOUSES), weights=list(cfg.WAREHOUSES.values()))[0]

    def in_window(self) -> dt.date:
        return self.lo17 + dt.timedelta(days=5) + dt.timedelta(days=self.rng.randint(0, (self.hi19 - self.lo17).days - 10))

    def beyond(self, shelf: int) -> dt.date:
        """HSD sau mốc 19 tháng (không lọt 17–19 lẫn tối đa 18)."""
        months = self.rng.randint(20, max(20, min(30, shelf - 4)))
        return add_months(self.today, months) + dt.timedelta(days=self.rng.randint(1, 20))

    def code(self, spec: LotSpec) -> str:
        brand = "".join(c for c in normalize_text(spec.brand) if c.isalpha()).upper()[:3].ljust(3, "X")
        style = zlib.crc32(spec.brand.encode("utf-8")) % 3
        n = self.rng.randint(1, 99)
        m = spec.manufactured_on
        code = (f"{m:%y%m}{chr(65 + self.rng.randint(0, 5))}{n:02d}" if style == 0
                else f"L{m:%y%m%d}{self.rng.randint(1, 3)}" if style == 1
                else f"{brand}{m.year % 10}{m.timetuple().tm_yday:03d}")
        base, suffix = code, 0
        while (spec.product_id, spec.warehouse_id, code) in self.seen_codes:
            suffix += 1
            code = f"{base}{chr(64 + suffix)}"
        self.seen_codes.add((spec.product_id, spec.warehouse_id, code))
        return code

    def lot(self, row: dict, hsd: dt.date, qty: int, *, warehouse: str | None = None, status: str = "available",
            profile: str = "base", hist: int | None = None, nsx: dt.date | None = None,
            anchor: str | None = None, damaged: int | None = None, exact: bool = False) -> LotSpec:
        """``exact``: giữ nguyên HSD (lô biên). Lô thường suy ra HSD từ NSX để hạn dùng luôn đúng bằng NSX + shelf-life
        (add_months kẹp ngày cuối tháng nên HSD có thể lệch tối đa 3 ngày so với giá trị đề xuất)."""
        shelf = shelf_life_months(row)
        if nsx is None:
            nsx = add_months(hsd, -shelf)
            if not exact:
                hsd = add_months(nsx, shelf)
        received = min(nsx + dt.timedelta(days=self.rng.randint(20, 120)), self.today - dt.timedelta(days=RECEIVE_LEAD_DAYS))
        received = max(received, nsx)
        spec = LotSpec(
            product_id=product_key(row), warehouse_id=warehouse or self.warehouse(), status=status,
            expires_on=hsd, manufactured_on=nsx, received_on=received, available=qty,
            hist_sold=((self.rng.randint(0, 400) if self.rng.random() < 0.7 else 0) if hist is None else hist),
            damaged=(self.rng.choices((0, 1, 2), weights=(7, 2, 1))[0] if damaged is None else damaged),
            profile=profile, anchor=anchor, brand=_brand_text(row))
        spec.batch_code = self.code(spec)
        return spec

    # ---- profile -> danh sách lô --------------------------------------------------------
    def build(self, profile: str, row: dict, k: int) -> list[LotSpec]:
        rng, today = self.rng, self.today
        shelf = shelf_life_months(row)
        L = lambda hsd, qty, **kw: self.lot(row, hsd, qty, profile=profile, **kw)  # noqa: E731
        if profile == "w1719_edge_low":
            return [L(self.lo17, rng.randint(40, 90), warehouse="KHO-HCM-01", exact=True)]
        if profile == "w1719_edge_high":
            return [L(self.hi19, rng.randint(40, 90), warehouse="KHO-HN-01", exact=True)]
        if profile == "w1719_out_low":
            return [L(self.lo17 - DAY, rng.randint(40, 90), warehouse="KHO-HCM-01", exact=True)]
        if profile == "w1719_out_high":
            return [L(self.hi19 + DAY, rng.randint(40, 90), warehouse="KHO-HN-01", exact=True)]
        if profile == "le18_edge_in":
            return [L(self.hi18, rng.randint(40, 90), warehouse="KHO-HCM-01", exact=True)]
        if profile == "le18_edge_out":
            return [L(self.hi18 + DAY, rng.randint(40, 90), warehouse="KHO-HN-01", exact=True)]
        if profile == "w1719_big":
            return [L(self.in_window(), rng.randint(120, 400), warehouse=("KHO-HCM-01", "KHO-HN-01")[k % 2])]
        if profile == "w1719_small":
            return [L(self.in_window(), SMALL_QTY[k % len(SMALL_QTY)])]
        if profile == "w1719_mid":
            return [L(self.in_window(), rng.randint(20, 90))]
        if profile == "le18_big":
            return [L(today + dt.timedelta(days=rng.randint(100, 440)), rng.randint(120, 300), warehouse=("KHO-HCM-01", "KHO-HN-01")[k % 2])]
        if profile == "le18_small":
            return [L(today + dt.timedelta(days=rng.randint(100, 440)), SMALL_QTY[(k + 3) % len(SMALL_QTY)])]
        if profile == "dn_1719":
            return [L(self.in_window(), rng.randint(20, 90), warehouse=cfg.SCOPE_WAREHOUSE)]
        if profile == "dn_le18":
            return [L(today + dt.timedelta(days=rng.randint(90, 450)), rng.randint(20, 80), warehouse=cfg.SCOPE_WAREHOUSE)]
        if profile == "dn_big":
            return [L(self.beyond(shelf), rng.randint(120, 300), warehouse=cfg.SCOPE_WAREHOUSE)]
        if profile == "dn_small":
            return [L(self.beyond(shelf), rng.randint(2, 9), warehouse=cfg.SCOPE_WAREHOUSE)]
        if profile == "wrong_status":
            hsd = self.in_window()
            if k % 3 == 0:
                return [L(hsd, rng.randint(30, 60), status="quarantined")]
            if k % 3 == 1:
                return [L(hsd, 0, status="depleted", hist=rng.randint(20, 60))]
            return [L(hsd, 0, hist=rng.randint(20, 60))]  # status available nhưng số lượng 0
        if profile == "multi_lot":
            return [L(self.in_window(), rng.randint(50, 80), warehouse="KHO-HCM-01"),
                    L(self.beyond(shelf), rng.randint(30, 60), warehouse="KHO-HN-01")]
        if profile == "near_expiry":
            return [L(add_months(today, rng.randint(1, 5)) + dt.timedelta(days=rng.randint(1, 20)), rng.randint(10, 60))]
        if profile == "stock_101":
            return [L(self.beyond(shelf), 101, warehouse="KHO-HCM-01")]
        if profile == "stock_100":
            return [L(self.beyond(shelf), 100, warehouse="KHO-HCM-01")]
        if profile == "stock_big":
            return [L(self.beyond(shelf), rng.randint(140, 900))]
        if profile == "stock_low":
            return [L(self.beyond(shelf), LOW_QTY[k % len(LOW_QTY)])]
        if profile == "stock_zero":
            return [L(self.beyond(shelf), 0, status="depleted", hist=rng.randint(20, 60))]
        if profile == "expired_status":
            return [L(today - dt.timedelta(days=rng.randint(5, 90)), rng.randint(5, 30), status="expired")]
        if profile == "expired_dirty":  # dữ liệu bẩn kiểu cũ: còn "available" nhưng ngày đã qua
            return [L(today - dt.timedelta(days=rng.randint(6, 40)), rng.randint(10, 50))]
        raise ValueError(profile)

    def base_lots(self, row: dict) -> list[LotSpec]:
        shelf = shelf_life_months(row)
        count = self.rng.choices((1, 2, 3), weights=(50, 35, 15))[0]
        upper = max(91, min(34, shelf - 4) * 30)
        lots = []
        for _ in range(count):
            qty = max(12, min(2500, int(self.rng.lognormvariate(4.3, 1.0))))
            lots.append(self.lot(row, self.today + dt.timedelta(days=self.rng.randint(90, upper)), qty))
        return lots


def _focus_pools(rows: list[dict], reserved: set[str]) -> dict[str, list[dict]]:
    """Mỗi sản phẩm chỉ thuộc một nhóm trọng tâm; chỉ lấy sản phẩm mà engine nhìn thấy trong category đó."""
    taken: set[str] = set(reserved)
    pools: dict[str, list[dict]] = {}
    for category in cfg.FOCUS_CATEGORIES:
        pool = [r for r in rows if product_key(r) not in taken and _category_matches(r, category)]
        pools[category] = pool
        taken.update(product_key(r) for r in pool)
    return pools


def pick_anchors(products, rng, reserved=frozenset()) -> dict[str, str]:
    """3 sản phẩm kem chống nắng thật dùng cho ví dụ A/B/C của người dùng."""
    pool = [r for r in active_products(products) if product_key(r) not in reserved and _category_matches(r, "sunscreen")]
    if len(pool) < 3:
        return {}
    return dict(zip("ABC", (product_key(r) for r in rng.sample(pool, 3))))


def plan_inventory(products, today: dt.date, rng, *, reserved=frozenset(), anchors: dict[str, str] | None = None) -> InventoryPlan:
    rows = active_products(products)
    by_id = {product_key(r): r for r in rows}
    builder = _Builder(today, rng)
    anchors = pick_anchors(rows, rng, reserved) if anchors is None else anchors
    taken = set(reserved) | set(anchors.values())
    lots: dict[str, list[LotSpec]] = {}
    profiles: dict[str, list[str]] = {}
    shortfalls: dict[str, dict[str, int]] = {}

    if anchors:
        a, b, c = (by_id[anchors[x]] for x in "ABC")
        lots[anchors["A"]] = [builder.lot(a, dt.date(2028, 5, 20), 200, warehouse="KHO-HCM-01", profile="anchor_a",
                                          nsx=dt.date(2025, 12, 20), anchor="A", hist=0, damaged=0)]
        lots[anchors["B"]] = [builder.lot(b, dt.date(2028, 6, 1), 100, warehouse="KHO-HCM-01", profile="anchor_b",
                                          nsx=dt.date(2026, 1, 1), anchor="B", hist=0, damaged=0),
                              # 100 đơn vị không đủ "tồn kho trên 100": B có thêm lô nền ngoài cả hai cửa sổ hạn.
                              builder.lot(b, builder.beyond(shelf_life_months(b)) + dt.timedelta(days=60), rng.randint(20, 40),
                                          warehouse="KHO-HN-01", profile="anchor_b_extra", hist=0, damaged=0)]
        lots[anchors["C"]] = [builder.lot(c, add_months(today, 20), 20, warehouse="KHO-HN-01", profile="anchor_c",
                                          anchor="C", hist=0, damaged=0, exact=True)]
        profiles.update(anchor_a=[anchors["A"]], anchor_b=[anchors["B"]], anchor_b_extra=[anchors["B"]], anchor_c=[anchors["C"]])

    for category, pool in _focus_pools(rows, taken).items():
        pool = [r for r in pool if product_key(r) not in lots]
        rng.shuffle(pool)
        cursor = 0
        for profile, count in FOCUS_SLOTS:
            for k in range(count):
                if cursor >= len(pool):
                    shortfalls.setdefault(category, {})[profile] = shortfalls.get(category, {}).get(profile, 0) + 1
                    continue
                row = pool[cursor]
                cursor += 1
                lots[product_key(row)] = builder.build(profile, row, k)
                profiles.setdefault(profile, []).append(product_key(row))
        for row in pool[cursor:]:
            lots[product_key(row)] = builder.base_lots(row)
            profiles.setdefault("base", []).append(product_key(row))
    for row in rows:
        pid = product_key(row)
        if pid not in lots:
            lots[pid] = builder.base_lots(row)
            profiles.setdefault("base", []).append(pid)

    codes = Counter(lot.batch_code for specs in lots.values() for lot in specs)  # mã lô dùng cho phạm vi "một lô" phải duy nhất
    scope = next((lot.batch_code for pid in profiles.get("dn_1719", []) for lot in lots[pid] if codes[lot.batch_code] == 1), None)
    return InventoryPlan(today=today, lots=lots, profiles=profiles, anchors=anchors, shortfalls=shortfalls, scope_batch_code=scope)


def _lot_doc(spec: LotSpec, oid: ObjectId, today: dt.date) -> dict:
    created = vn_midnight(today)
    initial = spec.hist_sold + spec.available + spec.damaged
    return cfg.tag({
        "_id": oid, "product_id": spec.product_id, "batch_code": spec.batch_code, "warehouse_id": spec.warehouse_id,
        "manufactured_on": vn_midnight(spec.manufactured_on), "expires_on": vn_midnight(spec.expires_on),
        "received_on": vn_midnight(spec.received_on) + dt.timedelta(hours=9),
        "initial_quantity": initial, "available_quantity": spec.available, "reserved_quantity": 0,
        "sold_quantity": spec.hist_sold, "damaged_quantity": spec.damaged, "status": spec.status,
        "case_profile": spec.profile, "anchor": spec.anchor,
        "verification_status": "unverified",
        "source_note": "Lô giả lập pb-fixture-v1 để test Prompt Builder; không phải tồn kho thật.",
        "created_by": "pb-fixture", "created_at": created, "updated_at": created,
    })


def _eligible(lot: dict, when: dt.datetime) -> bool:
    expiry = local_date(lot["expires_on"])
    return (lot["status"] == "available" and lot["available_quantity"] > 0 and not lot.get("anchor")
            and expiry is not None and expiry > when.astimezone(VN).date() and lot["received_on"] <= when)


def allocate_fefo(batches_of_product: list[dict], sales) -> list[dict]:
    """Trừ kho theo FEFO: mỗi dòng đơn một movement ``sale``; ``sold_quantity`` và ``initial_quantity`` cùng tăng
    để ``initial = available + sold + damaged + reserved`` luôn đúng và ``available`` không bao giờ âm.
    Lô cùng kho với đơn được ưu tiên, sau đó mới đến lô kho khác."""
    movements = []
    for when, warehouse, qty in sorted(sales, key=lambda s: (s[0], s[1], s[2])):
        candidates = [lot for lot in batches_of_product if _eligible(lot, when)]
        if not candidates or qty <= 0:
            continue
        lot = min(candidates, key=lambda b: (b["warehouse_id"] != warehouse, local_date(b["expires_on"]), b["batch_code"]))
        lot["sold_quantity"] += qty
        lot["initial_quantity"] += qty
        movements.append({
            "batch_id": lot["_id"], "product_id": lot["product_id"], "warehouse_id": lot["warehouse_id"],
            "type": "sale", "quantity": qty, "note": "Bán hàng giả lập pb-fixture-v1",
            "created_by": "pb-fixture", "occurred_at": when})
    return movements


def _ledger(lot: dict, window_sales: list[dict], returns: list[dict], rng, today: dt.date) -> list[dict]:
    """Receipt + bán lịch sử + hư hỏng + bán/hoàn trả trong cửa sổ, đánh số quantity_before/after theo thời gian."""
    events = [cfg.tag({"batch_id": lot["_id"], "product_id": lot["product_id"], "warehouse_id": lot["warehouse_id"],
                       "type": "receipt", "quantity": lot["initial_quantity"], "note": "Nhập lô pb-fixture-v1",
                       "created_by": "pb-fixture", "occurred_at": lot["received_on"]})]
    window_total = sum(m["quantity"] for m in window_sales)
    hist = lot["sold_quantity"] + sum(m["quantity"] for m in returns) - window_total
    received_day = local_date(lot["received_on"])
    if hist > 0:
        day = min(received_day + dt.timedelta(days=rng.randint(5, 40)), today - dt.timedelta(days=61))
        events.append(cfg.tag({"batch_id": lot["_id"], "product_id": lot["product_id"], "warehouse_id": lot["warehouse_id"],
                               "type": "sale", "quantity": hist, "note": "Bán lịch sử trước cửa sổ 60 ngày",
                               "created_by": "pb-fixture", "occurred_at": vn_midnight(day) + dt.timedelta(hours=14)}))
    if lot["damaged_quantity"] > 0:
        day = min(received_day + dt.timedelta(days=rng.randint(2, 20)), today - dt.timedelta(days=61))
        events.append(cfg.tag({"batch_id": lot["_id"], "product_id": lot["product_id"], "warehouse_id": lot["warehouse_id"],
                               "type": "damage", "quantity": lot["damaged_quantity"], "note": "Hư hỏng khi kiểm kho",
                               "created_by": "pb-fixture", "occurred_at": vn_midnight(day) + dt.timedelta(hours=10)}))
    events.extend(cfg.tag(m) for m in [*window_sales, *returns])
    events.sort(key=lambda e: (e["occurred_at"], e["type"] != "receipt"))
    running = 0
    for event in events:
        event["quantity_before"] = running
        running += event["quantity"] if event["type"] in {"receipt", "return"} else -event["quantity"]
        event["quantity_after"] = running
    return events


def build_inventory(plan: InventoryPlan, rng, sold_by_product=None, returns=None) -> tuple[list[dict], list[dict], dict]:
    """(lô, movement, manifest). ``sold_by_product``: {pid: [(ngay_dat, warehouse_id, qty)]}; ``returns``: danh sách
    hoàn trả của ``generate_commerce(with_dirty=True)`` (trả hàng về đúng lô đã xuất)."""
    sold_by_product = sold_by_product or {}
    returns_by_product: dict[str, list[dict]] = {}
    for item in returns or []:
        returns_by_product.setdefault(item["product_id"], []).append(item)
    batches: list[dict] = []
    movements: list[dict] = []
    for pid in sorted(plan.lots):
        docs = [_lot_doc(spec, ObjectId(rng.randbytes(12)), plan.today) for spec in plan.lots[pid]]
        sales = allocate_fefo(docs, sold_by_product.get(pid, []))
        by_lot: dict = {}
        returned_by_lot: dict = {}
        lots_by_id = {doc["_id"]: doc for doc in docs}
        for item in returns_by_product.get(pid, []):
            sale = next((m for m in sales if m["occurred_at"] == item["ngay_dat"] and m["quantity"] >= item["quantity"]), None)
            if sale is None:
                continue
            lot = lots_by_id[sale["batch_id"]]
            lot["available_quantity"] += item["quantity"]
            lot["sold_quantity"] -= item["quantity"]
            returned_by_lot.setdefault(lot["_id"], []).append({
                "batch_id": lot["_id"], "product_id": pid, "warehouse_id": lot["warehouse_id"], "type": "return",
                "quantity": item["quantity"], "note": f"Khách trả hàng đơn {item['ma_hoa_don']}",
                "created_by": "pb-fixture", "occurred_at": item["occurred_at"]})
        for movement in sales:
            by_lot.setdefault(movement["batch_id"], []).append(movement)
        for doc in docs:
            movements.extend(_ledger(doc, by_lot.get(doc["_id"], []), returned_by_lot.get(doc["_id"], []), rng, plan.today))
        batches.extend(docs)
    manifest = {
        "as_of": plan.today.isoformat(), "scope_warehouse": plan.scope_warehouse,
        "scope_batch_code": plan.scope_batch_code, "anchors": dict(plan.anchors),
        "profiles": {name: list(ids) for name, ids in plan.profiles.items() if name != "base"},
        "base_product_count": len(plan.profiles.get("base", [])), "shortfalls": plan.shortfalls,
        "lot_count": len(batches),
    }
    return batches, movements, manifest


def generate_inventory(products, today: dt.date, rng, sold_by_product=None, *, reserved=frozenset()):
    plan = plan_inventory(products, today, rng, reserved=reserved)
    return build_inventory(plan, rng, sold_by_product)


def generate_batches(products, today: dt.date, rng, sold_by_product=None, *, reserved=frozenset()) -> tuple[list[dict], dict]:
    batches, _, manifest = generate_inventory(products, today, rng, sold_by_product, reserved=reserved)
    return batches, manifest


def effective_stock(batches: list[dict], product_id: str, today: dt.date, warehouse: str | None = None) -> int:
    """Tồn khả dụng như engine hiểu: lô available, chưa hết hạn."""
    return sum(int(number(b["available_quantity"]) or 0) for b in batches
               if b["product_id"] == product_id and b["status"] == "available"
               and local_date(b["expires_on"]) > today and (warehouse is None or b["warehouse_id"] == warehouse))
