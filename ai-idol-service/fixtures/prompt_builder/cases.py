"""Ma trận case của Prompt Builder và kiểm chứng offline bằng engine thật.

``compose_brief`` là bản port của ``buildAgentPrompt()`` (frontend/public/assets/js/ai-idol-studio.js) cùng các
``data-fragment`` của frontend/views/admin/ai_idol/index.php. Mỗi case chạy ``build_query_plan`` -> ``execute_query``
trên dữ liệu sinh trong bộ nhớ, rồi đối chiếu với một oracle độc lập (duyệt lô/đơn trực tiếp, không gọi lại engine).
"""
from __future__ import annotations

import datetime as dt
from collections import Counter, defaultdict
from dataclasses import dataclass
from typing import Callable

from agents.command_query import (
    SALES_FIELDS, STOCK_FIELDS, add_months, build_query_plan, execute_query, first_number, local_date, number,
)
from agents.intent_parser import normalize_text
from agents.query_sources import load_query_sources

from . import config as cfg
from .batches import vn_midnight

# ---- Cây danh mục của Builder (agentCategoryOptions) ------------------------------------------------


@dataclass(frozen=True)
class Node:
    label: str
    evidence: tuple[str, ...]           # chuỗi chuẩn hoá phải xuất hiện trong đường dẫn danh mục (+ tên sản phẩm)
    phrase: str = ""                    # cụm đưa vào prompt (cấp 2/3)
    ingredients: tuple[str, ...] = ()   # ràng buộc thành phần (serum retinol / niacinamide / vitamin C)


_SUN = ("kem chong nang", "sua chong nang", "gel chong nang", "xit chong nang", "sunscreen")
_SERUM = ("serum", "tinh chat")
NODES: dict[tuple[str, ...], Node] = {
    ("face",): Node("Chăm Sóc Da Mặt", ("cham soc da mat",)),
    ("face", "cleansing"): Node("Làm Sạch Da", ("cham soc da mat -> lam sach da",), "sản phẩm làm sạch da"),
    ("face", "cleansing", "cleanser"): Node("", ("sua rua mat", "gel rua mat"), "sữa rửa mặt"),
    ("face", "cleansing", "remover"): Node("", ("lam sach da -> tay trang mat",), "nước tẩy trang"),
    ("face", "cleansing", "exfoliant"): Node("", ("tay te bao chet da mat",), "tẩy tế bào chết"),
    ("face", "hydration"): Node("Dưỡng Ẩm & Phục Hồi", ("cham soc da mat -> duong am",), "sản phẩm dưỡng ẩm và phục hồi da"),
    ("face", "hydration", "moisturizer"): Node("", ("kem / gel / dau duong",), "kem dưỡng ẩm"),
    ("face", "serum"): Node("Serum / Đặc Trị", _SERUM, "serum"),
    ("face", "serum", "retinol"): Node("", _SERUM, "serum retinol", ("retinol", "retinal", "retinoid", "retinyl palmitate")),
    ("face", "serum", "niacinamide"): Node("", _SERUM, "serum niacinamide", ("niacinamide", "nicotinamide", "vitamin b3")),
    ("face", "serum", "vitaminC"): Node("", _SERUM, "serum vitamin C", ("vitamin c", "ascorbic", "ascorbyl")),
    ("face", "sunscreen"): Node("Chống Nắng", _SUN, "kem chống nắng"),
    ("face", "sunscreen", "face_sunscreen"): Node("", _SUN, "kem chống nắng"),
    ("face", "sunscreen", "body_sunscreen"): Node("", ("chong nang co the",), "kem chống nắng body"),
    ("face", "mask"): Node("Mặt Nạ", ("cham soc da mat -> mat na",), "mặt nạ chăm sóc da"),
    ("face", "mask", "sheet"): Node("", ("mat na giay",), "mặt nạ giấy"),
    ("face", "mask", "wash_off"): Node("", ("mat na rua",), "mặt nạ rửa"),
    ("face", "eye"): Node("Chăm Sóc Mắt", ("cham soc da mat -> duong mat",), "sản phẩm chăm sóc mắt"),
    ("face", "eye", "eye_cream"): Node("", ("kem duong mat",), "kem mắt"),
    ("makeup",): Node("Trang Điểm", ("trang diem ->",)),
    ("makeup", "base"): Node("Nền & Che Khuyết Điểm", ("kem nen", "phan nen", "phan nuoc cushion", "bb / cc cream", "che khuyet diem", "kem lot"), "sản phẩm trang điểm nền"),
    ("makeup", "base", "foundation"): Node("", ("kem nen", "phan nen", "foundation", "cushion", "bb cream"), "kem nền"),
    ("makeup", "base", "concealer"): Node("", ("che khuyet diem",), "kem che khuyết điểm"),
    ("makeup", "lip"): Node("Môi", ("trang diem -> trang diem moi",), "sản phẩm trang điểm môi"),
    ("makeup", "lip", "lipstick"): Node("", ("son thoi", "son kem"), "son môi"),
    ("makeup", "lip", "lip_gloss"): Node("", ("son bong",), "son bóng"),
    ("body",): Node("Chăm Sóc Cơ Thể", ("cham soc co the",)),
    ("body", "body_cleanse"): Node("Làm sạch cơ thể", ("cham soc co the -> sua tam", "cham soc co the -> xa phong"), "sữa tắm và sản phẩm làm sạch cơ thể"),
    ("body", "body_cleanse", "shower"): Node("", ("cham soc co the -> sua tam", "cham soc co the -> xa phong"), "sữa tắm"),
    ("body", "body_moisture"): Node("Dưỡng thể", ("cham soc co the -> duong the",), "sản phẩm dưỡng thể"),
    ("body", "body_moisture", "lotion"): Node("", ("cham soc co the -> duong the",), "kem dưỡng thể"),
    ("hair",): Node("Chăm Sóc Tóc", ("cham soc toc",)),
    ("hair", "hair_cleanse"): Node("Làm sạch tóc", ("dau goi va dau xa",), "sản phẩm làm sạch tóc"),
    ("hair", "hair_cleanse", "shampoo"): Node("", ("dau goi va dau xa -> dau goi",), "dầu gội"),
    ("hair", "hair_care"): Node("Dưỡng & phục hồi tóc", ("cham soc toc va da dau -> duong toc", "dau goi va dau xa -> dau xa"), "sản phẩm dưỡng tóc"),
    ("hair", "hair_care", "conditioner"): Node("", ("dau goi va dau xa -> dau xa",), "dầu xả"),
    ("hair", "hair_care", "treatment"): Node("", ("mat na / kem u toc",), "kem ủ tóc"),
}
FOCUS_PATHS = {
    "sunscreen": ("face", "sunscreen", "face_sunscreen"), "cleanser": ("face", "cleansing", "cleanser"),
    "serum": ("face", "serum"), "sheet_mask": ("face", "mask", "sheet"), "shampoo": ("hair", "hair_cleanse", "shampoo"),
}
FRAGMENTS = {
    "expiry": {"": "", "expiry_17_19": "còn hạn sử dụng từ 17 đến 19 tháng", "expiry_max_18": "còn hạn sử dụng tối đa 18 tháng"},
    "stock": {"": "", "stock_high": "tồn kho trên 100 sản phẩm", "stock_low": "tồn kho tối đa 10 sản phẩm, còn hàng",
              "out_of_stock": "đã hết hàng"},
    "sales": {
        "": "", "best_30": "bán chạy nhất trong 30 ngày qua", "best_7": "bán tốt trong 7 ngày qua",
        "best_all": "bán chạy nhất toàn kỳ",
        "revenue_low_conversion": "doanh thu cao nhưng tỷ lệ chuyển đổi thấp trong 30 ngày qua",
        "views_no_orders": "được xem nhiều nhưng chưa chốt đơn trong 30 ngày qua",
        "cart": "có nhiều lượt thêm giỏ trong 30 ngày qua", "discount_slow": "đang giảm giá nhưng bán chậm trong 30 ngày qua",
        "growth_7": "có doanh số tăng mạnh trong 7 ngày qua so với 7 ngày trước đó",
    },
}
AGENT_BRIEF_MIN_LENGTH = 12


def _path(sel: dict) -> tuple[str, ...]:
    value = sel.get("category") or ()
    return tuple(value.split("/")) if isinstance(value, str) else tuple(value)


def category_phrase(path: tuple[str, ...]) -> str:
    if len(path) >= 2:
        return NODES[path[:3]].phrase if len(path) >= 3 else NODES[path[:2]].phrase
    if len(path) == 1:
        return "sản phẩm " + NODES[path].label.lower()
    return "sản phẩm"


def compose_brief(sel: dict) -> str:
    """Port của buildAgentPrompt(): cùng thứ tự mảnh ghép, cùng dấu câu."""
    out_of_stock = sel.get("stock") == "out_of_stock"
    fragments = [FRAGMENTS["expiry"][sel.get("expiry", "")], FRAGMENTS["stock"][sel.get("stock", "")]]
    fragments = [f for f in fragments if f]
    sales = FRAGMENTS["sales"][sel.get("sales", "")]
    if sales:
        fragments.append(sales)
    mode = sel.get("warehouse_mode", "")
    if mode:
        code = (sel.get("scope_code") or "").strip() or ("[chưa nhập mã lô]" if mode == "batch" else "[chưa nhập mã kho]")
        fragments.append(f"{'chỉ lấy từ lô hàng' if mode == 'batch' else 'chỉ lấy từ kho'} {code}")
    brand = (sel.get("brand") or "").strip()
    if brand:
        fragments.append(f"ưu tiên nhiều sản phẩm của thương hiệu {brand}")
    if sel.get("brand_cap"):
        fragments.append("mỗi thương hiệu tối đa 2 sản phẩm")
    if sel.get("enough_stock") and not out_of_stock:  # ô "đủ hàng" bị vô hiệu khi chọn "Đã hết hàng"
        fragments.append("ưu tiên sản phẩm còn đủ hàng (đủ bán khi live)")
    quantity = str(sel.get("quantity") or "")
    prompt = f"{f'Lọc {quantity}' if quantity else 'Tìm'} {category_phrase(_path(sel))}"
    if fragments:
        prompt += " " + ", ".join(fragments)
    live = sel.get("live")
    if live:
        prompt += f", tạo livestream{' ' + live['at'] if live.get('at') else ''}, thời lượng {live['duration']} phút, {live['channel']}"
    if sel.get("styles"):
        prompt += ", theo phong cách " + ", ".join(sel["styles"])
    prompt += "."
    if len(prompt) < AGENT_BRIEF_MIN_LENGTH and prompt.startswith("Tìm "):
        prompt = "Tìm sản phẩm " + prompt[4:]
    return prompt


# ---- Ngữ cảnh dữ liệu ---------------------------------------------------------------------------------


class Ctx:
    """Dữ liệu sinh ra + catalog, kèm các chỉ mục để oracle duyệt trực tiếp."""

    def __init__(self, dataset: dict, catalog: list[dict], today: dt.date, snapshots: list[dict]):
        self.today, self.dataset, self.catalog, self.snapshots = today, dataset, catalog, snapshots
        self.manifest = dataset["manifest"]
        self.batches, self.orders = dataset["inventory_batches"], dataset["hoa_don"]
        self.events = dataset["product_events"]
        self.lots: dict[str, list[dict]] = defaultdict(list)
        for batch in self.batches:
            self.lots[str(batch["product_id"])].append(batch)
        self.lines_by_order: dict[int, list[dict]] = defaultdict(list)
        for line in dataset["chi_tiet_hoa_don"]:
            self.lines_by_order[line["ma_hoa_don"]].append(line)
        self._tables: dict[int, dict] = {}
        self.rows = [r for r in catalog if _pid(r) and str(r.get("ten_san_pham") or r.get("name") or "").strip()
                     and normalize_text(r.get("trang_thai", r.get("status", ""))) not in {"inactive", "hidden", "disabled", "0", "an", "ngung ban"}]

    def sales_table(self, window: int) -> dict:
        if window not in self._tables:
            self._tables[window] = _sales_table(self, window)
        return self._tables[window]


def make_ctx(dataset: dict, catalog: list[dict], today: dt.date, snapshots: list[dict] | None = None) -> Ctx:
    if snapshots is None:
        from .dataset import snapshot_docs
        snapshots = snapshot_docs(dataset, catalog, today)
    return Ctx(dataset, catalog, today, snapshots)


def _pid(row: dict) -> str:
    return str(row.get("ma_san_pham") or row.get("product_id") or row.get("id") or row.get("_id") or "")


def _brand(row: dict) -> str:
    return str(row.get("ten_thuong_hieu") or row.get("thuong_hieu") or row.get("brand") or "").strip()


def _text(row: dict) -> str:
    return normalize_text(" ".join(str(row.get(k) or "") for k in ("loai_san_pham", "danh_muc_day_du", "category", "ten_san_pham", "name")))


def _members(ctx: Ctx, path: tuple[str, ...]) -> list[dict]:
    if not path:
        return list(ctx.rows)
    node = NODES[path]
    rows = []
    for row in ctx.rows:
        text = _text(row)
        if node.evidence is _SUN and any(x in text for x in ("kem nen", "cushion", "bb cream", "son moi")):
            continue
        if not any(e in text for e in node.evidence):
            continue
        if node.ingredients:
            facts = normalize_text(" ".join(str(row.get(k) or "") for k in (
                "ten_san_pham", "ten_thuong_hieu", "thuong_hieu", "thanh_phan_chinh", "thanh_phan_clean", "thanh_phan_day_du", "loai_san_pham", "danh_muc_day_du")))
            if not any(a in facts for a in node.ingredients):
                continue
        rows.append(row)
    return rows


# ---- Oracle: tồn kho / hạn dùng / phạm vi -------------------------------------------------------------


def _derive(sel: dict, today: dt.date) -> dict:
    d = {"limit": int(sel.get("quantity") or 10), "expiry": False, "lo": None, "hi": None, "stock_min": None, "stock_max": None,
         "rank": "catalog", "wh": None, "batch": None, "brand": normalize_text(sel.get("brand") or "") or None,
         "cap": 2 if sel.get("brand_cap") else None, "sales": sel.get("sales") or ""}
    stock = sel.get("stock", "")
    if stock == "out_of_stock":
        d.update(stock_max=0, rank="stock_asc")
    else:
        if sel.get("expiry") or stock == "stock_low" or sel.get("enough_stock") or stock == "stock_high":
            d.update(stock_min=1, rank="stock_desc")
        if stock == "stock_high":
            d.update(stock_min=101)
        if stock == "stock_low":
            d.update(stock_max=10, rank="stock_asc")
    if sel.get("expiry") == "expiry_17_19":
        d.update(expiry=True, lo=add_months(today, 17), hi=add_months(today, 19), rank="expiry")
    elif sel.get("expiry") == "expiry_max_18":
        d.update(expiry=True, lo=today, hi=add_months(today, 18), rank="expiry")
    if sel.get("warehouse_mode") == "warehouse":
        d["wh"] = normalize_text(sel.get("scope_code") or "")
    elif sel.get("warehouse_mode") == "batch":
        d["batch"] = normalize_text(sel.get("scope_code") or "")
    return d


def _qty(batch: dict) -> int:
    return int(number(batch.get("available_quantity")) or 0)


def _in_scope(batch: dict, d: dict) -> bool:
    if d["wh"] and normalize_text(batch.get("warehouse_id")) != d["wh"]:
        return False
    return not d["batch"] or normalize_text(batch.get("batch_code")) == d["batch"]


def _inventory_rows(ctx: Ctx, members: list[dict], d: dict) -> tuple[list[dict], Counter]:
    today, missing, rows = ctx.today, Counter(), []
    for row in members:
        pid = _pid(row)
        lots = ctx.lots.get(pid)
        entry = {"product_id": pid, "brand": _brand(row), "stock": None, "expires_on": None}
        scoped = [b for b in (lots or []) if _in_scope(b, d)]
        if d["expiry"]:
            if lots is None:
                missing["expiry_or_batch_quantity"] += 1
                continue
            eligible = [b for b in scoped if b["status"] == "available" and _qty(b) > 0 and local_date(b["expires_on"]) > today
                        and d["lo"] <= local_date(b["expires_on"]) <= d["hi"]]
            if not eligible:
                continue
            entry["stock"] = sum(_qty(b) for b in eligible)
            entry["expires_on"] = min(local_date(b["expires_on"]) for b in eligible).isoformat()
        else:
            if d["wh"] or d["batch"]:
                if lots is None:
                    missing["expiry_or_batch_quantity"] += 1
                    continue
                if not scoped:
                    continue
            if lots is not None:
                entry["stock"] = sum(_qty(b) for b in scoped if b["status"] == "available" and local_date(b["expires_on"]) > today)
            else:
                entry["stock"] = first_number(row, STOCK_FIELDS)
        if d["stock_min"] is not None or d["stock_max"] is not None or d["rank"].startswith("stock"):
            if entry["stock"] is None:
                missing["stock"] += 1
                continue
            if d["stock_min"] is not None and entry["stock"] < d["stock_min"]:
                continue
            if d["stock_max"] is not None and entry["stock"] > d["stock_max"]:
                continue
        rows.append(entry)
    return rows, missing


def _rank(rows: list[dict], d: dict) -> list[dict]:
    rank = d["rank"]
    if rank == "expiry":
        rows.sort(key=lambda r: (r["expires_on"], r["product_id"]))
    elif rank.startswith("stock"):
        rows.sort(key=lambda r: ((r["stock"] or 0) * (-1 if rank == "stock_desc" else 1), r["product_id"]))
    else:
        rows.sort(key=lambda r: r["product_id"])
    return rows


def _brand_policy(rows: list[dict], d: dict, missing: Counter) -> list[dict]:
    if d["brand"]:
        rows.sort(key=lambda r: normalize_text(r["brand"]) != d["brand"])  # sort ổn định: thương hiệu ưu tiên lên trước
    if d["cap"]:
        seen, kept = Counter(), []
        for row in rows:
            key = normalize_text(row["brand"])
            if not key:
                missing["brand"] += 1
            elif seen[key] < d["cap"]:
                seen[key] += 1
                kept.append(row)
        rows = kept
    return rows


# ---- Oracle: bán hàng (đọc thẳng đơn, dòng đơn, sự kiện) ------------------------------------------------


def _is_revenue(order: dict) -> bool:
    return order.get("trang_thai") == "Hoàn thành" and (
        order.get("hinh_thuc_thanh_toan") != "bank_transfer_qr" or order.get("status_thanh_toan") == "Đã thanh toán")


def _sales_table(ctx: Ctx, window: int) -> dict:
    today = ctx.today
    cur_a, cur_b = vn_midnight(today - dt.timedelta(days=window)), vn_midnight(today)
    prev_a = vn_midnight(today - dt.timedelta(days=2 * window))
    units, previous, revenue = Counter(), Counter(), defaultdict(float)
    for order in ctx.orders:
        if not _is_revenue(order):
            continue
        done = order.get("ngay_hoan_thanh")
        if done is None:
            continue
        lines = ctx.lines_by_order[order["ma_hoa_don"]]
        gross = sum(l["so_luong"] * l["don_gia"] for l in lines)
        factor = 0 if gross == 0 else min(1, max(0, ((order.get("tong_tien") or 0) - (order.get("phi_van_chuyen") or 0)) / gross))
        for line in lines:
            pid = str(line["ma_san_pham"])
            if cur_a <= done < cur_b:
                units[pid] += line["so_luong"]
                revenue[pid] += line["so_luong"] * line["don_gia"] * factor
            elif prev_a <= done < cur_a:
                previous[pid] += line["so_luong"]
    views, carts = defaultdict(set), defaultdict(set)
    first_view: dict[tuple[str, str], dt.datetime] = {}
    for event in ctx.events:
        if not cur_a <= event["event_time"] < cur_b:
            continue
        pid, sid = event["product_id"], event["session_hash"]
        if event["type"] == "product_view":
            views[pid].add(sid)
            first_view[(pid, sid)] = min(event["event_time"], first_view.get((pid, sid), event["event_time"]))
        else:
            carts[pid].add(sid)
    converted = defaultdict(set)
    for order in ctx.orders:
        sid = order.get("analytics_sid")
        if not sid or order.get("trang_thai") == "Đã hủy" or not order.get("ngay_dat") or not cur_a <= order["ngay_dat"] < cur_b:
            continue
        for line in ctx.lines_by_order[order["ma_hoa_don"]]:
            pid = str(line["ma_san_pham"])
            seen = first_view.get((pid, sid))
            if seen is not None and order["ngay_dat"] >= seen:
                converted[pid].add(sid)
    return {"units": units, "previous": previous, "revenue": {k: int(round(v)) for k, v in revenue.items()},
            "views": {k: len(v) for k, v in views.items()}, "carts": {k: len(v) for k, v in carts.items()},
            "converted": {k: len(v) for k, v in converted.items()}}


def _percentile(values: list[float], fraction: float) -> float:
    ordered = sorted(values)
    position = (len(ordered) - 1) * fraction
    lo = int(position)
    return ordered[lo] + (ordered[min(lo + 1, len(ordered) - 1)] - ordered[lo]) * (position - lo)


def _sales_rows(ctx: Ctx, members: list[dict], sales: str) -> tuple[list[dict], Counter, list[str]]:
    """Trả (hàng đã xếp hạng, missing, cảnh báo) cho đúng một tiêu chí bán hàng."""
    missing: Counter = Counter()
    window = {"best_7": 7, "growth_7": 7}.get(sales, None if sales == "best_all" else 30)
    rows: list[dict] = []
    if sales == "best_all":
        for row in members:
            sold = first_number(row, SALES_FIELDS)
            if sold is None:
                missing["lifetime_sales"] += 1
            elif sold > 0:
                rows.append({"product_id": _pid(row), "brand": _brand(row), "units": sold})
        rows.sort(key=lambda r: (-r["units"], r["product_id"]))
        return rows, missing, []
    t = ctx.sales_table(window)
    for row in members:
        pid = _pid(row)
        u, prev, rev, v, c, cart = t["units"][pid], t["previous"][pid], t["revenue"].get(pid, 0), t["views"].get(pid, 0), t["converted"].get(pid, 0), t["carts"].get(pid, 0)
        entry = {"product_id": pid, "brand": _brand(row), "units": u, "revenue": rev, "views": v, "converted": c, "carts": cart}
        if sales in {"best_30", "best_7"}:
            if u > 0:
                rows.append(entry)
        elif sales == "views_no_orders":
            if v >= 100 and c == 0:
                rows.append(entry)
        elif sales == "cart":
            rows.append(entry)
        elif sales == "revenue_low_conversion":
            if v >= 100:
                rows.append({**entry, "conversion": c / v})
        elif sales == "growth_7":
            if prev == 0:
                missing["growth_baseline"] += 1  # engine coi sản phẩm không bán được kỳ trước là thiếu mốc so sánh
            elif u > prev:
                rows.append({**entry, "growth": (u - prev) / prev})
        elif sales == "discount_slow":
            price, original = number(row.get("gia_ban")), number(row.get("gia_thi_truong"))
            if price is None or original is None:
                missing["price"] += 1
            elif original > price:
                rows.append({**entry, "per_day": u / window})
    warnings: list[str] = []
    if sales in {"revenue_low_conversion", "discount_slow"}:
        if len(rows) < 5:
            missing["comparison_sample"] += 1
            return [], missing, ["sample"]
        if sales == "revenue_low_conversion":
            top, low = _percentile([r["revenue"] for r in rows], .75), _percentile([r["conversion"] for r in rows], .25)
            rows = [r for r in rows if r["revenue"] >= top and r["conversion"] <= low]
        else:
            slow = _percentile([r["per_day"] for r in rows], .25)
            rows = [r for r in rows if r["per_day"] <= slow]
    key = {"best_30": lambda r: (-r["units"], r["product_id"]), "best_7": lambda r: (-r["units"], r["product_id"]),
           "views_no_orders": lambda r: (-r["views"], r["product_id"]), "cart": lambda r: (-r["carts"], r["product_id"]),
           "revenue_low_conversion": lambda r: (-r["revenue"], r["product_id"]), "growth_7": lambda r: (-r["growth"], r["product_id"]),
           "discount_slow": lambda r: (r["per_day"], r["product_id"])}[sales]
    rows.sort(key=key)
    return rows, missing, warnings


def expected_result(sel: dict, ctx: Ctx) -> dict:
    """Kết quả mong đợi theo oracle: {"ids", "matched", "status", "complete", "rows"}."""
    d = _derive(sel, ctx.today)
    members = _members(ctx, _path(sel))
    if d["sales"]:
        rows, missing, _ = _sales_rows(ctx, members, d["sales"])
        if d["stock_min"] is not None or d["stock_max"] is not None or d["wh"] or d["batch"] or d["expiry"]:
            raise ValueError("oracle bán hàng không kết hợp với điều kiện kho trong ma trận này")
    else:
        rows, missing = _inventory_rows(ctx, members, d)
        rows = _rank(rows, d)
    rows = _brand_policy(rows, d, missing)
    status = "RESULTS_READY" if rows else "DATA_UNAVAILABLE" if missing else "EMPTY_RESULTS"
    return {"ids": [r["product_id"] for r in rows[:d["limit"]]], "matched": len(rows), "status": status,
            "complete": not missing, "rows": rows, "limit": d["limit"]}


def check_against_oracle(result: dict, ctx: Ctx, sel: dict) -> list[str]:
    want, failures = expected_result(sel, ctx), []
    got = [item["product_id"] for item in result["selected"]]
    if result["status"] != want["status"]:
        failures.append(f"status {result['status']} != oracle {want['status']}")
    if got != want["ids"]:
        failures.append(f"selected {got[:6]}... != oracle {want['ids'][:6]}...")
    if result["matched_count"] != want["matched"]:
        failures.append(f"matched_count {result['matched_count']} != oracle {want['matched']}")
    if bool(result["complete"]) != want["complete"]:
        failures.append(f"complete {result['complete']} != oracle {want['complete']}")
    failures.extend(_evidence_failures(result, ctx, sel))
    return failures


def _evidence_failures(result: dict, ctx: Ctx, sel: dict) -> list[str]:
    """Bằng chứng từng dòng kết quả phải khớp dữ liệu gốc: lô trong khoảng, đúng trạng thái, đúng kho."""
    d, failures = _derive(sel, ctx.today), []
    for item in result["selected"]:
        evidence = item["evidence"]
        for batch in evidence.get("batches", []):
            raw = [b for b in ctx.lots[item["product_id"]] if b["batch_code"] == batch["batch_id"]]
            if not raw:
                failures.append(f"{item['product_id']}: lô {batch['batch_id']} không có trong sổ lô")
                continue
            lot = next((b for b in raw if _in_scope(b, d)), None)
            if lot is None:
                failures.append(f"{item['product_id']}: lô {batch['batch_id']} nằm ngoài phạm vi kho/lô đã chọn")
            elif lot["status"] != "available":
                failures.append(f"{item['product_id']}: lô {batch['batch_id']} có status {lot['status']}")
            elif not d["lo"] <= local_date(lot["expires_on"]) <= d["hi"] or local_date(lot["expires_on"]).isoformat() != batch["expires_on"]:
                failures.append(f"{item['product_id']}: HSD lô {batch['batch_id']} {batch['expires_on']} ngoài khoảng")
    return failures


# ---- Case ---------------------------------------------------------------------------------------------


@dataclass
class Case:
    name: str
    sel: dict
    expect_status: str
    min_selected: int
    check: Callable[[dict, dict], list[str]]
    group: str = ""
    expect_complete: bool | None = True
    known_gap: str | None = None


def _oracle_check(sel: dict):
    return lambda result, ctx: check_against_oracle(result, ctx, sel)


def _category_check(path: tuple[str, ...]):
    node = NODES[path]

    def check(result: dict, ctx: Ctx) -> list[str]:
        by_id = {_pid(r): r for r in ctx.rows}
        failures = []
        for item in result["selected"]:
            text = _text(by_id[item["product_id"]])
            if not any(e in text for e in node.evidence):
                failures.append(f"{item['product_id']} không thuộc nhánh '{node.phrase or node.label}'")
        return failures

    return check


def _case(name, group, sel, status="RESULTS_READY", min_selected=1, check=None, **kw) -> Case:
    return Case(name, sel, status, min_selected, check or _oracle_check(sel), group, **kw)


# Ca lỗi do engine (không phải dữ liệu): tên case -> lý do. Rỗng sau khi sửa đếm số lượng "Lọc N <danh mục>" (14/10/2026).
KNOWN_GAPS: dict[str, str] = {}


def build_cases(ctx: Ctx) -> list[Case]:
    cases: list[Case] = []
    # 1) Mọi nhánh của Builder với "Tìm" thuần: phải ra ít nhất 1 sản phẩm thuộc đúng nhánh.
    for path in NODES:
        sel = {"category": path}
        cases.append(_case("category:" + "/".join(path), "category", sel, check=_category_check(path)))
    # 2) 5 danh mục trọng tâm x (3 hạn dùng x 4 tồn kho).
    for category, path in FOCUS_PATHS.items():
        for expiry in ("", "expiry_17_19", "expiry_max_18"):
            for stock in ("", "stock_high", "stock_low", "out_of_stock"):
                if not expiry and not stock:
                    continue
                contradiction = bool(expiry) and stock == "out_of_stock"
                sel = {"category": path, "expiry": expiry, "stock": stock}
                cases.append(_case(f"inventory:{category}:{expiry or 'any'}:{stock or 'any'}", "inventory", sel,
                                   "EMPTY_RESULTS" if contradiction else "RESULTS_READY",
                                   0 if contradiction else (3 if expiry == "expiry_17_19" and stock in {"stock_high", "stock_low"} and category == "sunscreen" else 1)))
    # 3) Phạm vi kho / lô.
    for category, path in FOCUS_PATHS.items():
        for label, extra in (("17_19", {"expiry": "expiry_17_19"}), ("max18", {"expiry": "expiry_max_18"}),
                             ("stock_high", {"stock": "stock_high"}), ("stock_low", {"stock": "stock_low"})):
            sel = {"category": path, **extra, "warehouse_mode": "warehouse", "scope_code": cfg.SCOPE_WAREHOUSE}
            cases.append(_case(f"scope:{category}:{cfg.SCOPE_WAREHOUSE}:{label}", "scope", sel, min_selected=3))
    code = ctx.manifest.get("scope_batch_code")
    if code:
        for extra, label in (({"expiry": "expiry_17_19"}, "17_19"), ({}, "any")):
            sel = {"category": FOCUS_PATHS["sunscreen"], **extra, "warehouse_mode": "batch", "scope_code": code}
            cases.append(_case(f"scope:sunscreen:batch:{label}", "scope", sel))
    # 4) Tám tiêu chí bán hàng x kem chống nắng.
    for sales in FRAGMENTS["sales"]:
        if sales:
            # "Doanh thu cao, chuyển đổi thấp" chạy trên sữa rửa mặt: xem pick_engineered. Growth luôn thiếu mốc so sánh với
            # sản phẩm chưa bán kỳ trước nên engine báo complete=False (đúng ngữ nghĩa).
            category = "cleanser" if sales == "revenue_low_conversion" else "sunscreen"
            cases.append(_case(f"sales:{category}:{sales}", "sales", {"category": FOCUS_PATHS[category], "sales": sales},
                               expect_complete=False if sales == "growth_7" else True))
    # 5) Thương hiệu, giới hạn mỗi thương hiệu, số lượng, "đủ hàng".
    brand_counts = Counter((cat, normalize_text(_brand(r))) for cat, path in FOCUS_PATHS.items() for r in _members(ctx, path))
    for category, path in FOCUS_PATHS.items():
        for brand in cfg.FOCUS_BRANDS:
            if brand_counts[(category, normalize_text(brand))] >= 5:
                cases.append(_case(f"brand:{category}:{brand}", "brand", {"category": path, "brand": brand}, min_selected=5))
        cases.append(_case(f"brand_cap:{category}", "brand", {"category": path, "brand_cap": True}, min_selected=3))
        cases.append(_case(f"enough_stock:{category}", "brand", {"category": path, "enough_stock": True}))
        for quantity in ("1", "3", "5", "10"):
            sel = {"category": path, "quantity": quantity}
            cases.append(_case(f"quantity:{category}:{quantity}", "quantity", sel, min_selected=int(quantity)))
    sun = FOCUS_PATHS["sunscreen"]
    for brand in cfg.FOCUS_BRANDS:
        if brand_counts[("sunscreen", normalize_text(brand))] >= 5:
            sel = {"category": sun, "expiry": "expiry_17_19", "brand": brand, "brand_cap": True, "quantity": "5"}
            cases.append(_case(f"combo:sunscreen:17_19:{brand}:cap:top5", "combo", sel, min_selected=1))
    for case in cases:
        case.known_gap = KNOWN_GAPS.get(case.name)
    return cases


# ---- Chạy offline -----------------------------------------------------------------------------------------


class _Cursor(list):
    def limit(self, count):
        return _Cursor(self[:count])


class _Collection:
    """Collection tối thiểu đủ cho agents.query_sources.load_query_sources (lọc bằng so sánh bằng)."""

    def __init__(self, docs):
        self.docs = docs

    def find(self, query=None, projection=None):
        return _Cursor(d for d in self.docs if all(d.get(k) == v for k, v in (query or {}).items()))


class MemoryDb:
    def __init__(self, batches, snapshots):
        self.inventory_batches = _Collection(batches)
        self.product_metrics_snapshots = _Collection(snapshots)


def run_case(case: Case, ctx: Ctx, db=None) -> dict:
    """``db``: Mongo thật (verify online, đi qua đúng load_query_sources); mặc định dùng dữ liệu trong bộ nhớ."""
    brief = compose_brief(case.sel)
    plan = build_query_plan(brief, today=ctx.today)
    batches, snapshots = load_query_sources(db if db is not None else MemoryDb(ctx.batches, ctx.snapshots), plan)
    result = execute_query(plan, ctx.catalog, batches=batches, snapshots=snapshots, brief=brief)
    failures = []
    if plan["questions"]:
        failures.append("engine hỏi lại: " + "; ".join(plan["questions"]))
    if result["status"] != case.expect_status:
        failures.append(f"status {result['status']} != kỳ vọng {case.expect_status}")
    if len(result["selected"]) < case.min_selected:
        failures.append(f"chỉ có {len(result['selected'])} < {case.min_selected} sản phẩm")
    if case.expect_complete is not None and bool(result["complete"]) != case.expect_complete:
        failures.append(f"complete={result['complete']} missing={result['missing_data']}")
    if not plan["questions"]:
        failures.extend(case.check(result, ctx))
    return {"case": case.name, "group": case.group, "brief": brief, "status": result["status"],
            "selected": len(result["selected"]), "matched": result["matched_count"], "complete": result["complete"],
            "missing_data": result["missing_data"], "failures": failures, "known_gap": case.known_gap}


def run_offline(dataset: dict, catalog: list[dict], today: dt.date, snapshots: list[dict] | None = None) -> list[dict]:
    ctx = make_ctx(dataset, catalog, today, snapshots)
    return [run_case(case, ctx) for case in build_cases(ctx)]


def verdict(result: dict) -> str:
    if not result["failures"]:
        return "PASS"
    return "KNOWN_GAP" if result["known_gap"] else "FAIL"
