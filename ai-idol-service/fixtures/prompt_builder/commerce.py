"""Khách hàng giả lập, phiên xem/thêm giỏ và đơn hàng có logic mua hàng thật.

Hàm thuần: không I/O, mọi ngẫu nhiên đi qua ``rng``. Mọi mốc ngày tính theo giờ Việt Nam; ``today`` (T) là ngày chạy,
dữ liệu chỉ nằm trong T-60 ... T-1.
"""
from __future__ import annotations

import datetime as dt
import functools
import hashlib
import itertools
import math
from collections import Counter, defaultdict
from zoneinfo import ZoneInfo

from agents.command_query import _category_matches, number
from agents.intent_parser import normalize_text
from config import TIMEZONE

from . import config as cfg
from .catalog_pick import classify_product, popularity_prior, product_key
from .names_vi import DEM_NAM, DEM_NU, HO, PROVINCES, STREETS, TEN_NAM, TEN_NU

VN = ZoneInfo(TIMEZONE)
UTC = dt.timezone.utc

SKIN_TYPES = (  # (khoá, tên loại da như collection loai_da, tỉ trọng)
    ("oily", "Da dầu/Hỗn hợp dầu", 35), ("dry", "Da khô/Hỗn hợp khô", 20), ("combo", "Da hỗn hợp thiên dầu", 20),
    ("normal", "Da thường/Mọi loại da", 15), ("sensitive", "Da nhạy cảm", 10),
)
CONCERNS = ("mụn", "thâm sau mụn", "lão hóa", "lỗ chân lông to", "da khô ráp", "da xỉn màu", "nám", "đỏ rát")
BUDGET_TIERS = (("low", 45, 250_000, 300_000), ("mid", 40, 600_000, 700_000), ("high", 15, 10**9, 1_500_000))
HOUR_WEIGHTS = (1, 1, 1, 1, 1, 1, 2, 3, 4, 5, 6, 8, 9, 8, 6, 5, 5, 6, 7, 8, 10, 11, 10, 9)  # đỉnh 11-13h và 20-23h
SESSIONS_PER_DAY = (250, 600)
ORDER_PER_SESSION_WITH_CART = 0.25
DIRECT_ORDERS_PER_SESSION = 0.072  # đơn/phiên kỳ vọng, dùng cho các ngày chưa có tracking
ROUTINE = {"cleanser": ("face_cleansing", "moisturizer"), "serum": ("sunscreen",)}
ROUTINE_RATE = {"cleanser": 0.35, "serum": 0.25}


@functools.lru_cache(maxsize=None)
def _skin_fit(skin_key: str, loai_da: str) -> float:
    p = normalize_text(loai_da)
    if "moi loai" in p or "thuong" in p:
        return 1.0
    if not p or "unknown" in p:
        return 0.7
    oily, dry, combo, sens, acne = "dau" in p, "kho" in p, "hon hop" in p, "nhay cam" in p, "mun" in p
    if skin_key == "oily":
        return 1.0 if oily else 0.9 if acne else 0.5 if (sens or combo) else 0.25
    if skin_key == "dry":
        return 1.0 if dry else 0.6 if sens else 0.25
    if skin_key == "combo":
        return 1.0 if combo else 0.6 if (oily or dry) else 0.5
    if skin_key == "sensitive":
        return 1.0 if sens else 0.6 if dry else 0.3
    return 0.6  # normal: sản phẩm dành riêng cho loại da khác


def skin_fit(skin_key: str, loai_da: object) -> float:
    """Độ hợp giữa loại da persona và nhãn loai_da của sản phẩm (>= 0.6 coi là hợp)."""
    return _skin_fit(skin_key, str(loai_da or ""))


def budget_fit(tier: str, price: float) -> float:
    ceiling = {name: cap for name, _, cap, _ in BUDGET_TIERS}[tier]
    if price <= ceiling:
        return 1.0
    return 0.15 if tier == "low" else 0.25


def local_dt(day: dt.date, seconds: int) -> dt.datetime:
    """Giây trong ngày (giờ VN) -> datetime UTC aware."""
    base = dt.datetime(day.year, day.month, day.day, tzinfo=VN)
    return (base + dt.timedelta(seconds=seconds)).astimezone(UTC)


def local_day(moment: dt.datetime) -> dt.date:
    return moment.astimezone(VN).date()


def _weighted(rng, items, weights):
    return rng.choices(items, weights=weights)[0]


def customer_email(full_name: str, k: int) -> str:
    """``{tên}.{họ}{số thứ tự 3 chữ số}@example.test`` không dấu, ví dụ ``lan.nguyen001@example.test`` (miền .test không gửi thư thật)."""
    parts = normalize_text(full_name).split()
    return f"{parts[-1]}.{parts[0]}{k + 1:03d}@example.test"


def generate_customers(n: int, rng) -> list[dict]:
    """n khách giả lập. Không có mật khẩu ở đây; tài khoản đăng nhập do backend/scripts/seed_pb_fixture_accounts.php tạo."""
    regions = ("south", "north", "central")
    customers = []
    for k in range(n):
        region = _weighted(rng, regions, (60, 30, 10))  # khớp tỉ lệ kho HCM / HN / DN
        female = rng.random() < 0.82
        name = " ".join((rng.choice(HO), rng.choice(DEM_NU if female else DEM_NAM), rng.choice(TEN_NU if female else TEN_NAM)))
        skin_key, skin_name, _ = _weighted(rng, SKIN_TYPES, [w for _, _, w in SKIN_TYPES])
        tier, _, _, budget = _weighted(rng, BUDGET_TIERS, [w for _, w, _, _ in BUDGET_TIERS])
        province = rng.choice(PROVINCES[region])
        created = local_dt(dt.date(2026, 1, 1) + dt.timedelta(days=rng.randint(0, 200)), rng.randint(0, 86399))
        customers.append(cfg.tag({
            "ma_kh": cfg.ID_BASE["khach_hang"] + k, "ho_ten": name, "email": customer_email(name, k),
            "so_dien_thoai": "0900" + f"{rng.randint(0, 999999):06d}",
            "dia_chi": f"{rng.randint(1, 300)} {rng.choice(STREETS)}, {province}",
            "gioi_tinh": "Nữ" if female else "Nam", "nam_sinh": rng.randint(1985, 2006), "diemtl": 0, "loaikh": "Thường",
            "ngan_sach": budget, "van_de_da": ", ".join(rng.sample(CONCERNS, 2)),
            "created_at": created, "updated_at": created,
            "fixture_region": region, "fixture_warehouse": cfg.REGION_WAREHOUSE[region],
            "fixture_persona": {"skin_type": skin_name, "skin_key": skin_key, "concerns": rng.sample(CONCERNS, rng.randint(1, 3)),
                                "budget_tier": tier, "favorite_brands": []},
        }))
    return customers


def _priced_pool(products, exclude, category):
    return sorted((r for r in products if product_key(r) not in exclude and _category_matches(r, category)
                   and (number(r.get("gia_ban")) or 0) > 0), key=lambda r: product_key(r))


def pick_engineered(products, rng, exclude=frozenset()) -> dict[str, list[str]]:
    """Sản phẩm có số liệu bán hàng thiết kế sẵn; các nhóm không giao nhau.

    Kem chống nắng: growth (6) / decline (3) / views_no_orders (6) / cart (10).
    Sữa rửa mặt: revenue_low_conversion (11, trong đó 3 sản phẩm giá cao "whales"). Hai nhóm "xem nhiều chưa mua" và
    "doanh thu cao, chuyển đổi thấp" không thể nằm cùng một danh mục: sản phẩm >= 100 phiên xem mà chuyển đổi 0 kéo phân vị
    25% của chuyển đổi về 0 nên cả 3 sản phẩm giá cao đều không bao giờ lọt.
    """
    empty = {k: [] for k in ("growth", "decline", "views_no_orders", "revenue_low_conversion", "whales", "cart")}
    sun = _priced_pool(products, exclude, "sunscreen")
    clean = _priced_pool(products, exclude, "cleanser")
    if len(sun) < 40 or len(clean) < 30:
        return empty
    rng.shuffle(sun)
    take = lambda n: [product_key(sun.pop()) for _ in range(n)]  # noqa: E731
    groups = {"growth": take(6), "decline": take(3), "views_no_orders": take(6), "cart": take(10)}
    by_price = sorted(clean, key=lambda r: (-(number(r["gia_ban"]) or 0), product_key(r)))
    whales, rest = by_price[:3], by_price[3:]
    median = sorted(number(r["gia_ban"]) for r in clean)[len(clean) // 2]
    normals = rng.sample([r for r in rest if (number(r["gia_ban"]) or 0) <= median], 8)
    groups["whales"] = [product_key(r) for r in whales]
    groups["revenue_low_conversion"] = groups["whales"] + [product_key(r) for r in normals]
    return groups


def engineered_ids(engineered: dict) -> set[str]:
    return {pid for ids in engineered.values() for pid in ids}


class _Commerce:
    def __init__(self, products, customers, today, rng, engineered, exclude_sales, with_dirty):
        self.today, self.rng, self.with_dirty = today, rng, with_dirty
        self.customers = customers
        self.engineered = engineered or {}
        self.excluded = set(exclude_sales)
        self.reserved = engineered_ids(self.engineered)
        self.rows = {product_key(r): r for r in products}
        self.token = f"{rng.getrandbits(64):016x}"
        self.counter = itertools.count()
        self.events: dict[str, dict] = {}
        self.specs: list[dict] = []
        self.midnight_today = local_dt(today, 0)
        self.price = {pid: int(number(r.get("gia_ban")) or 0) for pid, r in self.rows.items()}
        self.view_pids = [pid for pid in sorted(self.rows) if pid not in self.reserved and self.price[pid] > 0]
        self.category = {pid: classify_product(self.rows[pid]) for pid in self.view_pids}
        self.by_category = defaultdict(list)
        for i, pid in enumerate(self.view_pids):
            self.by_category[self.category[pid]].append(i)
        self.facts = [(pid, popularity_prior(self.rows[pid]), str(self.rows[pid].get("loai_da") or ""),
                       self.rows[pid].get("ma_thuong_hieu")) for pid in self.view_pids]
        self._assign_favorites()
        self.weights = {c["ma_kh"]: self._weights(c) for c in customers}
        self.flash_day = self._pick_flash_day()
        self.flash_discount = rng.uniform(0.10, 0.20)

    # ---- persona -> trọng số mua ----------------------------------------------------
    def _assign_favorites(self):
        brands = Counter(self.rows[pid].get("ma_thuong_hieu") for pid in self.view_pids if self.rows[pid].get("ma_thuong_hieu") is not None)
        ids, counts = list(brands), list(brands.values())
        for c in self.customers:
            if ids:
                c["fixture_persona"]["favorite_brands"] = sorted({self.rng.choices(ids, weights=counts)[0] for _ in range(self.rng.randint(1, 2))})

    def _weights(self, customer) -> list[float]:
        persona = customer["fixture_persona"]
        favorites = set(persona["favorite_brands"])
        skin_key, tier = persona["skin_key"], persona["budget_tier"]
        cumulative = []
        total = 0.0
        for pid, prior, loai_da, brand in self.facts:
            total += (prior * _skin_fit(skin_key, loai_da) * budget_fit(tier, self.price[pid])
                      * (1.8 if brand in favorites else 1.0) * (0.05 if pid in self.excluded else 1.0))  # hết hàng: ít được xem
            cumulative.append(total)
        return cumulative

    def _pick_flash_day(self) -> dt.date:
        days = [self.today - dt.timedelta(days=n) for n in range(10, 36)]
        weekdays = [d for d in days if d.weekday() < 5]
        return self.rng.choice(weekdays)

    # ---- tiện ích -------------------------------------------------------------------
    def sid(self, *parts) -> str:
        return hashlib.sha256(f"{self.token}|{'|'.join(map(str, parts))}|{next(self.counter)}".encode()).hexdigest()

    def event(self, kind: str, sid: str, pid: str, when: dt.datetime):
        key = hashlib.sha256(f"{kind}|{sid}|{pid}|{local_day(when).isoformat()}".encode()).hexdigest()
        self.events.setdefault(key, cfg.tag({"event_id": key, "type": kind, "product_id": pid, "session_hash": sid,
                                            "event_time": when, "schema_version": 1}))

    def day_multiplier(self, day: dt.date) -> float:
        m = 1.25 if day.weekday() >= 5 else 1.0
        return m * (1.4 if day.day in (1, 2, 3, 4, 5, 15) else 1.0)

    def start_seconds(self) -> int:
        hour = _weighted(self.rng, range(24), HOUR_WEIGHTS)
        seconds = hour * 3600 + self.rng.randint(0, 3599)
        return min(seconds, 86400 - 3600)  # phiên (kể cả đặt hàng) không tràn sang ngày sau

    def pick(self, customer, k: int = 1) -> list[str]:
        cumulative = self.weights[customer["ma_kh"]]
        return [self.view_pids[i] for i in self.rng.choices(range(len(self.view_pids)), cum_weights=cumulative, k=k)]

    def partner(self, customer, categories) -> str | None:
        candidates = [i for c in categories for i in self.by_category.get(c, ())]
        if not candidates:
            return None
        cumulative = self.weights[customer["ma_kh"]]
        weights = [cumulative[i] - (cumulative[i - 1] if i else 0.0) for i in candidates]
        pid = self.view_pids[self.rng.choices(candidates, weights=weights)[0]]
        return None if pid in self.excluded else pid

    def quantity(self, pid: str) -> int:
        if self.category.get(pid) == "sheet_mask":
            return self.rng.randint(3, 10)
        return _weighted(self.rng, (1, 2, 3), (75, 20, 5))

    # ---- đơn hàng ---------------------------------------------------------------------
    def add_order(self, when: dt.datetime, customer, lines, *, sid=None, force_completed_on: dt.date | None = None):
        """``lines``: [(pid, qty)]. Trạng thái theo tuổi đơn; ``force_completed_on`` dùng cho đơn thiết kế sẵn."""
        rng = self.rng
        day = local_day(when)
        age = (self.today - day).days
        flash = day == self.flash_day
        priced = []
        for pid, qty in lines:
            unit = self.price[pid]
            if flash:
                unit = max(1000, int(round(unit * (1 - self.flash_discount) / 1000)) * 1000)
            priced.append((pid, qty, unit))
        status = "completed"
        completed_at = None
        if force_completed_on is not None:
            completed_at = local_dt(force_completed_on, rng.randint(9 * 3600, 20 * 3600))
        else:
            roll = rng.random()
            if roll < 0.07:
                status = "cancelled"
            else:
                done = min(when + dt.timedelta(days=rng.randint(1, 4), hours=rng.randint(0, 6)), self.midnight_today - dt.timedelta(seconds=60))
                if done > when and (age >= 5 or rng.random() < 0.6):
                    completed_at = done
                else:
                    status = ("shipping" if rng.random() < 0.5 else "confirmed") if age >= 3 else ("confirmed" if rng.random() < 0.5 else "pending")
        if completed_at is not None and completed_at <= when:
            completed_at = when + dt.timedelta(hours=3)
        method = _weighted(rng, ("cod", "bank_transfer_qr"), (70, 30))
        subtotal = sum(q * u for _, q, u in priced)
        discount = 0
        if rng.random() < 0.10:
            discount = min(subtotal, 50_000, int(subtotal * rng.uniform(0.05, 0.10) // 1000 * 1000))
        points = 0
        if rng.random() < 0.08:
            points = min(max(0, subtotal - discount), rng.randrange(10_000, 30_001, 1000))
        total = max(0, subtotal - discount - points) + 30_000
        paid = status == "completed" or (method == "bank_transfer_qr" and status in {"confirmed", "shipping"})
        spec = {
            "when": when, "customer": customer, "lines": priced, "sid": sid, "status": status, "method": method,
            "completed_at": completed_at if status == "completed" else None, "subtotal": subtotal, "discount": discount,
            "points": points, "total": total, "paid": paid,
            "waiting_transfer": method == "bank_transfer_qr" and status == "pending", "seq": len(self.specs),
        }
        self.specs.append(spec)
        return spec

    # ---- phiên xem / thêm giỏ -----------------------------------------------------------
    def run_sessions(self, day: dt.date):
        rng = self.rng
        sessions = int(rng.randint(*SESSIONS_PER_DAY) * self.day_multiplier(day))
        cart_rate = rng.uniform(0.08, 0.15)
        order_rate = ORDER_PER_SESSION_WITH_CART * (2.5 if day == self.flash_day else 1.0)
        for _ in range(sessions):
            customer = rng.choice(self.customers)
            sid = self.sid("s", day)
            start = self.start_seconds()
            views = list(dict.fromkeys(self.pick(customer, _weighted(rng, (1, 2, 3, 4, 5, 6), (30, 25, 18, 12, 9, 6)))))
            clock = start
            carted = []
            for pid in views:
                self.event("product_view", sid, pid, local_dt(day, clock))
                if pid not in self.excluded and rng.random() < cart_rate:
                    self.event("add_to_cart", sid, pid, local_dt(day, clock + rng.randint(10, 120)))
                    carted.append(pid)
                clock += rng.randint(20, 180)
            if not carted or rng.random() >= order_rate:
                continue
            lines = {pid: self.quantity(pid) for pid in carted[:4]}
            for pid in list(lines):
                rate = ROUTINE_RATE.get(self.category.get(pid))
                if rate and len(lines) < 4 and rng.random() < rate:
                    extra = self.partner(customer, ROUTINE[self.category[pid]])
                    if extra and extra not in lines:
                        # Khách phải xem và bỏ giỏ món kèm trước khi đặt.
                        self.event("product_view", sid, extra, local_dt(day, clock))
                        self.event("add_to_cart", sid, extra, local_dt(day, clock + rng.randint(10, 60)))
                        lines[extra] = self.quantity(extra)
                        clock += rng.randint(30, 120)
            order_at = local_dt(day, clock + rng.randint(60, 900))
            self.add_order(order_at, customer, list(lines.items()), sid=sid if rng.random() < 0.85 else None)

    def run_direct_orders(self, day: dt.date):
        rng = self.rng
        count = int(rng.randint(*SESSIONS_PER_DAY) * DIRECT_ORDERS_PER_SESSION * self.day_multiplier(day) * (2.5 if day == self.flash_day else 1.0))
        for _ in range(count):
            customer = rng.choice(self.customers)
            pids = dict.fromkeys(self.pick(customer, _weighted(rng, (1, 2, 3, 4), (45, 30, 17, 8))))
            lines = [(pid, self.quantity(pid)) for pid in pids if pid not in self.excluded][:4]
            if not lines:
                continue
            for pid, _ in list(lines):
                rate = ROUTINE_RATE.get(self.category.get(pid))
                if rate and len(lines) < 4 and rng.random() < rate:
                    extra = self.partner(customer, ROUTINE[self.category[pid]])
                    if extra and extra not in dict(lines):
                        lines.append((extra, self.quantity(extra)))
            self.add_order(local_dt(day, self.start_seconds()), customer, lines)

    def run_repurchases(self):
        """Khách mua kem chống nắng ở đầu kỳ sẽ mua lại sau 40-70 ngày."""
        first: dict[int, tuple] = {}
        for spec in sorted(self.specs, key=lambda s: s["when"]):
            if spec["status"] == "cancelled":
                continue
            for pid, _, _ in spec["lines"]:
                if self.category.get(pid) == "sunscreen" and spec["customer"]["ma_kh"] not in first:
                    first[spec["customer"]["ma_kh"]] = (spec["when"], pid, spec["customer"])
        for when, pid, customer in first.values():
            again = when + dt.timedelta(days=self.rng.randint(40, 70))
            if again < self.midnight_today - dt.timedelta(hours=1) and self.rng.random() < 0.6 and pid not in self.excluded:
                self.add_order(again, customer, [(pid, 1)])

    # ---- ca bán hàng có số liệu thiết kế sẵn -----------------------------------------------
    def view_only_sessions(self, pid: str, count: int, window_days: int, *, carts: int = 0):
        rng = self.rng
        cart_flags = set(rng.sample(range(count), min(carts, count)))
        for n in range(count):
            day = self.today - dt.timedelta(days=rng.randint(1, window_days))
            sid = self.sid("e", pid, day)
            when = local_dt(day, self.start_seconds())
            self.event("product_view", sid, pid, when)
            if n in cart_flags:
                self.event("add_to_cart", sid, pid, when + dt.timedelta(seconds=rng.randint(10, 120)))

    def converted_sessions(self, pid: str, viewed: int, converted: int):
        """viewed phiên xem sản phẩm (không thêm giỏ), trong đó ``converted`` phiên đặt đúng sản phẩm đó."""
        rng = self.rng
        buyers = set(rng.sample(range(viewed), converted))
        for n in range(viewed):
            day = self.today - dt.timedelta(days=rng.randint(3, 29 if n in buyers else 30))
            sid = self.sid("e", pid, day)
            start = self.start_seconds()
            self.event("product_view", sid, pid, local_dt(day, start))
            if n in buyers:
                self.event("add_to_cart", sid, pid, local_dt(day, start + 30))
                done = day + dt.timedelta(days=rng.randint(1, 2))
                self.add_order(local_dt(day, start + rng.randint(120, 900)), rng.choice(self.customers),
                               [(pid, rng.randint(1, 2))], sid=sid, force_completed_on=min(done, self.today - dt.timedelta(days=1)))

    def completed_units(self, pid: str, units: int, first_day: dt.date, last_day: dt.date):
        rng = self.rng
        for _ in range(units):
            completed_on = first_day + dt.timedelta(days=rng.randint(0, (last_day - first_day).days))
            placed = completed_on - dt.timedelta(days=rng.randint(1, 3))
            self.add_order(local_dt(placed, rng.randint(9 * 3600, 20 * 3600)), rng.choice(self.customers), [(pid, 1)],
                           force_completed_on=completed_on)

    def run_engineered(self):
        rng, e, today = self.rng, self.engineered, self.today
        cur_first, cur_last = today - dt.timedelta(days=7), today - dt.timedelta(days=1)
        prev_first, prev_last = today - dt.timedelta(days=14), today - dt.timedelta(days=8)
        for pid in e.get("growth", ()):
            prev = rng.randint(4, 7)
            self.completed_units(pid, prev, prev_first, prev_last)
            self.completed_units(pid, math.ceil(prev * rng.uniform(1.9, 2.6)) + 1, cur_first, cur_last)
        for pid in e.get("decline", ()):
            prev = rng.randint(8, 12)
            self.completed_units(pid, prev, prev_first, prev_last)
            self.completed_units(pid, rng.randint(1, prev // 2), cur_first, cur_last)
        for pid in e.get("views_no_orders", ()):
            self.view_only_sessions(pid, rng.randint(120, 260), 30, carts=rng.randint(0, 25))
        for pid in e.get("cart", ()):
            carts = rng.randint(25, 80)
            self.view_only_sessions(pid, carts + rng.randint(0, 40), 30, carts=carts)
        whales = set(e.get("whales", ()))
        for pid in e.get("revenue_low_conversion", ()):
            if pid in whales:
                viewed = rng.randint(400, 480)
                converted = max(2, int(viewed * rng.uniform(0.02, 0.04)))
            else:
                viewed = rng.randint(100, 130)
                converted = max(8, int(viewed * rng.uniform(0.08, 0.12)))
            self.converted_sessions(pid, viewed, converted)

    def topup_conversions(self):
        """Sữa rửa mặt tự nhiên có >= 90 phiên xem trong 30 ngày cũng chuyển đổi 8-12% (nhóm "còn lại" của ca doanh thu cao,
        chuyển đổi thấp); nếu không, chúng làm phân vị 25% của chuyển đổi về 0 và 3 sản phẩm giá cao không bao giờ lọt."""
        rng, today = self.rng, self.today
        start, end = local_dt(today - dt.timedelta(days=30), 0), self.midnight_today
        viewers: dict[str, dict[str, dt.datetime]] = defaultdict(dict)
        for event in self.events.values():
            if event["type"] == "product_view" and start <= event["event_time"] < end:
                seen = viewers[event["product_id"]]
                seen[event["session_hash"]] = min(event["event_time"], seen.get(event["session_hash"], event["event_time"]))
        converted: dict[str, set] = defaultdict(set)
        for spec in self.specs:
            if spec["sid"] and spec["status"] != "cancelled":
                for pid, _, _ in spec["lines"]:
                    converted[pid].add(spec["sid"])
        latest = local_dt(today - dt.timedelta(days=2), 0)
        for pid in sorted(viewers):
            row = self.rows.get(pid)
            if row is None or pid in self.reserved or pid in self.excluded or not _category_matches(row, "cleanser"):
                continue
            views = len(viewers[pid])
            if views < 90:
                continue
            need = math.ceil(views * rng.uniform(0.08, 0.12)) - len(converted[pid])
            pool = sorted(sid for sid, seen in viewers[pid].items() if sid not in converted[pid] and seen < latest)
            for sid in rng.sample(pool, max(0, min(need, len(pool)))):
                seen = viewers[pid][sid]
                done = min(local_day(seen) + dt.timedelta(days=rng.randint(1, 2)), today - dt.timedelta(days=1))
                self.add_order(seen + dt.timedelta(seconds=rng.randint(120, 900)), rng.choice(self.customers),
                               [(pid, rng.randint(1, 2))], sid=sid, force_completed_on=done)

    # ---- hoàn thiện tài liệu ------------------------------------------------------------
    def finish(self) -> dict:
        rng = self.rng
        orders, lines, sales, returns = [], [], defaultdict(list), []
        line_id = cfg.ID_BASE["chi_tiet_hoa_don"]
        for number_, spec in enumerate(sorted(self.specs, key=lambda s: (s["when"], s["seq"]))):
            customer, when, method = spec["customer"], spec["when"], spec["method"]
            code = cfg.ID_BASE["hoa_don"] + number_
            status_text = {"completed": "Hoàn thành", "cancelled": "Đã hủy", "shipping": "Đang giao",
                           "confirmed": "Đã xác nhận", "pending": "Chờ xử lý"}[spec["status"]]
            payment = ("Đã thanh toán" if spec["paid"] else "Chờ chuyển khoản" if spec["waiting_transfer"] else "Chưa thanh toán")
            done = spec["completed_at"]
            order = cfg.tag({
                "ma_hoa_don": code, "ma_kh": customer["ma_kh"], "ten_nguoi_nhan": customer["ho_ten"],
                "sdt_nguoi_nhan": customer["so_dien_thoai"], "dia_chi_giao_hang": customer["dia_chi"],
                "phi_van_chuyen": 30_000, "tam_tinh": spec["subtotal"], "so_tien_giam": spec["discount"],
                "ma_giam_gia": "SKINSYNTAX10" if spec["discount"] else None, "ma_voucher": None, "tong_tien": spec["total"],
                "hinh_thuc_thanh_toan": method, "payment_method": method.upper(),
                "payment_status": "paid" if spec["paid"] else "pending", "transaction_id": None,
                "payment_time": when + dt.timedelta(minutes=5) if (spec["paid"] and method == "bank_transfer_qr") else None,
                "status_thanh_toan": payment, "diem_cong": spec["total"] // 11_000 if done else 0, "da_tich_diem": bool(done),
                "diem_su_dung": spec["points"] // 1000, "tien_giam_diem": spec["points"], "da_hoan_diem": False,
                "trang_thai": status_text, "trang_thai_normalized": spec["status"], "payment_expires_at": None,
                "da_tru_ton_kho": True, "da_hoan_ton_kho": spec["status"] == "cancelled", "da_hoan_kho": spec["status"] == "cancelled",
                "ngay_dat": when, "ly_do_huy": "Khách đổi ý" if spec["status"] == "cancelled" else None,
                "analytics_sid": spec["sid"], "updated_at": done or when, "fixture_warehouse": customer["fixture_warehouse"],
            })
            if done:
                order["ngay_hoan_thanh"] = done
                order["thoi_gian_hoan_thanh"] = done
            orders.append(order)
            for pid, qty, unit in spec["lines"]:
                lines.append(cfg.tag({
                    "id": line_id, "ma_hoa_don": code, "ma_san_pham": pid, "so_luong": qty, "don_gia": unit,
                    "status_thanh_toan": payment, "hinh_thuc_thanh_toan": method, "created_at": when}))
                line_id += 1
                if spec["status"] != "cancelled":
                    sales[pid].append((when, customer["fixture_warehouse"], qty))
            if self.with_dirty and done and rng.random() < 0.02 / 0.93:
                pid, qty, _ = spec["lines"][0]
                back = done + dt.timedelta(days=1)
                if back < self.midnight_today:
                    returns.append({"product_id": pid, "occurred_at": back, "quantity": qty, "ma_hoa_don": code,
                                    "ngay_dat": when, "warehouse_id": customer["fixture_warehouse"]})
        return {"events": sorted(self.events.values(), key=lambda e: (e["event_time"], e["event_id"])), "orders": orders,
                "lines": lines, "sales_by_product": dict(sales), "returns": returns,
                "stats": {"flash_day": self.flash_day.isoformat(), "flash_discount": round(self.flash_discount, 3),
                          "sessions": len({e["session_hash"] for e in self.events.values()})}}


def generate_commerce(products, customers, today: dt.date, rng, engineered: dict | None = None,
                      exclude_sales=frozenset(), with_dirty: bool = False) -> dict:
    """{"events", "orders", "lines", "sales_by_product", "returns", "stats"}. Sản phẩm trong ``engineered`` không bao giờ
    xuất hiện trong luồng mua tự nhiên; số liệu của chúng được dựng riêng để mỗi ca bán hàng đo được."""
    c = _Commerce(products, customers, today, rng, engineered, exclude_sales, with_dirty)
    for n in range(cfg.ORDER_DAYS, 0, -1):
        day = today - dt.timedelta(days=n)
        if n > cfg.SESSION_DAYS:
            c.run_direct_orders(day)
        else:
            c.run_sessions(day)
    c.run_repurchases()
    c.run_engineered()
    c.topup_conversions()
    return c.finish()
