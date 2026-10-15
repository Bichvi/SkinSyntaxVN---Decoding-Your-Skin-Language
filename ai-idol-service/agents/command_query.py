"""Read-only, auditable business queries. Missing measurements never become zero."""
from __future__ import annotations

import calendar
import datetime as dt
import math
import re
from collections import Counter
from typing import Any
from zoneinfo import ZoneInfo

from agents.intent_parser import _extract_explicit_ids, normalize_text
from config import TIMEZONE

CATEGORY_ALIASES = {
    "sunscreen": ("kem chong nang", "sua chong nang", "gel chong nang", "xit chong nang", "sunscreen"),
    "cleanser": ("sua rua mat", "gel rua mat"),
    "serum": ("serum", "tinh chat"),
    "foundation": ("kem nen", "phan nen", "foundation", "cushion", "bb cream"),
}
CATEGORY_LABELS = {
    "sunscreen": "Kem chống nắng",
    "cleanser": "Sữa rửa mặt",
    "serum": "Serum",
    "foundation": "Kem nền / trang điểm nền",
}
# Prompt Builder taxonomy -> verified catalog path segments (normalized).
# (brief aliases, catalog evidence). Evidence is matched ONLY against
# loai_san_pham / danh_muc_day_du / category, never names or descriptions.
CATEGORY_PATHS = {
    "face": (("san pham cham soc da mat",), ("cham soc da mat",)),
    "face_cleansing": (("san pham lam sach da",), ("cham soc da mat -> lam sach da",)),
    "makeup_remover": (("nuoc tay trang", "tay trang mat"), ("lam sach da -> tay trang mat",)),
    "exfoliant": (("tay te bao chet",), ("tay te bao chet da mat",)),
    "hydration": (("san pham duong am",), ("cham soc da mat -> duong am",)),
    "moisturizer": (("kem duong am",), ("kem / gel / dau duong",)),
    "mask": (("mat na",), ("cham soc da mat -> mat na",)),
    "sheet_mask": (("mat na giay",), ("mat na giay",)),
    "wash_off_mask": (("mat na rua",), ("mat na rua",)),
    "eye_care": (("san pham cham soc mat",), ("cham soc da mat -> duong mat",)),
    "eye_cream": (("kem mat", "kem duong mat"), ("kem duong mat",)),
    "body_sunscreen": (("kem chong nang body", "chong nang co the"), ("chong nang co the",)),
    "makeup": (("san pham trang diem",), ("trang diem ->",)),
    "makeup_base": (("san pham trang diem nen",), ("kem nen", "phan nen", "phan nuoc cushion", "bb / cc cream", "che khuyet diem", "kem lot")),
    "concealer": (("che khuyet diem",), ("che khuyet diem",)),
    "lip_makeup": (("san pham trang diem moi",), ("trang diem -> trang diem moi",)),
    "lipstick": (("son moi",), ("son thoi", "son kem")),
    "lip_gloss": (("son bong",), ("son bong",)),
    "body": (("san pham cham soc co the",), ("cham soc co the",)),
    "body_wash": (("sua tam", "san pham lam sach co the"), ("cham soc co the -> sua tam", "cham soc co the -> xa phong")),
    "body_lotion": (("san pham duong the", "kem duong the"), ("cham soc co the -> duong the",)),
    "hair": (("san pham cham soc toc",), ("cham soc toc",)),
    "hair_wash": (("san pham lam sach toc",), ("dau goi va dau xa",)),
    "shampoo": (("dau goi",), ("dau goi va dau xa -> dau goi",)),
    "hair_care": (("san pham duong toc",), ("cham soc toc va da dau -> duong toc", "dau goi va dau xa -> dau xa")),
    "conditioner": (("dau xa",), ("dau goi va dau xa -> dau xa",)),
    "hair_mask": (("kem u toc",), ("mat na / kem u toc",)),
}
CATEGORY_LABELS.update({
    "face": "Chăm sóc da mặt", "face_cleansing": "Làm sạch da", "makeup_remover": "Tẩy trang",
    "exfoliant": "Tẩy tế bào chết", "hydration": "Dưỡng ẩm & phục hồi", "moisturizer": "Kem dưỡng ẩm",
    "mask": "Mặt nạ", "sheet_mask": "Mặt nạ giấy", "wash_off_mask": "Mặt nạ rửa",
    "eye_care": "Chăm sóc mắt", "eye_cream": "Kem mắt", "body_sunscreen": "Chống nắng cơ thể",
    "makeup": "Trang điểm", "makeup_base": "Trang điểm nền", "concealer": "Che khuyết điểm",
    "lip_makeup": "Trang điểm môi", "lipstick": "Son môi", "lip_gloss": "Son bóng",
    "body": "Chăm sóc cơ thể", "body_wash": "Làm sạch cơ thể / sữa tắm", "body_lotion": "Dưỡng thể",
    "hair": "Chăm sóc tóc", "hair_wash": "Làm sạch tóc", "shampoo": "Dầu gội",
    "hair_care": "Dưỡng tóc", "conditioner": "Dầu xả", "hair_mask": "Kem ủ tóc",
})
UNSUPPORTED_CATEGORY_TOKENS = ("kem duong", "son moi", "toner", "mat na", "dau goi", "phuc hoi hang rao")
NO_CONDITION_QUESTION = ("Hãy chọn ít nhất một điều kiện lọc: danh mục, thương hiệu, tồn kho, hạn dùng, "
                         "kho/lô hoặc chỉ số bán hàng. Phong cách dẫn chuyện chỉ dùng khi tạo livestream.")
SEARCH_VERB_PATTERN = re.compile(r"^(?:tim|loc|liet ke)\b")
SCOPE_CODE = r"([a-z0-9](?:[a-z0-9._-]*[a-z0-9])?)"
BRAND_PATTERN = re.compile(
    r"(?<!moi )(?<!nhieu )(?<!cac )thuong hieu\s+(?!toi da\b)(.+?)"
    r"(?=\s+(?:con|ton|ban|de|trong|voi|luc|va|dang|uu tien|moi|theo|tao|chi lay|duoc)\b|\s*[,;]|\.(?:\s|$)|$)"
)
PER_BRAND_PATTERN = re.compile(r"moi thuong hieu\s*(?:toi da|chi|khong qua)?\s*(\d+)")
STOCK_BOUND_PATTERN = r"(?:ton kho|ton)\s*(?:tren|hon|it nhat|tu|duoi|it hon|toi da)\s*\d+\b"
COUNT_EXCLUDE_PATTERN = re.compile(
    r"(?:ton kho|ton)\s*(?:tren|hon|it nhat|tu|duoi|it hon|toi da)\s*\d+\s*(?:san pham|sp)?"
    r"|moi thuong hieu\s*(?:toi da|chi|khong qua)?\s*\d+\s*(?:san pham|sp)?"
)
# "Lọc N <danh mục bất kỳ>" như Prompt Builder ghép; số theo sau là kỳ thời gian thì không phải số lượng.
LEADING_COUNT_PATTERN = re.compile(r"(?:loc|tim|liet ke)\s+(?:ra\s+)?(?:top\s+)?(\d+)\s+(?!(?:ngay|thang|tuan|nam|gio|phut)\b)[a-z]")
STOCK_FIELDS = ("so_luong_ton_kho", "so_luong_ton", "ton_kho", "stock", "quantity", "stock_quantity")
SALES_FIELDS = ("so_luong_ban", "so_luong_da_ban", "luot_ban", "sales_count")
METRIC_LABELS = {
    "expiry": "Hạn dùng gần nhất trước", "stock_desc": "Tồn kho nhiều nhất trước",
    "stock_asc": "Tồn kho ít nhất trước", "units": "Số lượng bán giảm dần",
    "revenue_low_conversion": "Doanh thu cao, chuyển đổi thấp",
    "views_no_orders": "Xem nhiều, chưa có phiên mua", "cart": "Số phiên thêm giỏ giảm dần",
    "discount_slow": "Đang giảm giá, tốc độ bán thấp", "growth": "Tăng trưởng số lượng bán",
    "catalog": "Mã sản phẩm tăng dần",
}


def number(value: Any) -> float | None:
    if isinstance(value, bool) or value is None:
        return None
    try:
        parsed = float(value)
        return parsed if math.isfinite(parsed) and parsed >= 0 else None
    except (TypeError, ValueError):
        return None


def first_number(row: dict, fields: tuple[str, ...]) -> float | None:
    for field in fields:
        if field in row and row[field] not in (None, ""):
            return number(row[field])
    return None


def local_date(value: Any) -> dt.date | None:
    if isinstance(value, dt.datetime):
        return value.replace(tzinfo=value.tzinfo or dt.timezone.utc).astimezone(ZoneInfo(TIMEZONE)).date()
    if isinstance(value, dt.date):
        return value
    if isinstance(value, str):
        try:
            return dt.date.fromisoformat(value)  # exact dates only, never shelf-life prose
        except ValueError:
            return None
    return None


def add_months(day: dt.date, months: int) -> dt.date:
    year, month = divmod(day.year * 12 + day.month - 1 + months, 12)
    return day.replace(year=year, month=month + 1, day=min(day.day, calendar.monthrange(year, month + 1)[1]))


def detect_categories(text: str) -> list[str]:
    """Categories named in normalized text; the most specific phrase wins."""
    hits: dict[str, str] = {}
    for key, aliases in [*CATEGORY_ALIASES.items(), *((k, v[0]) for k, v in CATEGORY_PATHS.items())]:
        found = [a for a in aliases if re.search(rf"(?<![a-z0-9]){re.escape(a)}(?![a-z0-9])", text)]
        if found:
            hits[key] = max(found, key=len)
    return [key for key, alias in hits.items() if not any(o != alias and alias in o for o in hits.values())]


def catalog_search_requested(text: str) -> bool:
    """A read-only catalog search such as "Tìm sữa rửa mặt"; never a create command by itself."""
    text = normalize_text(text)
    return bool(SEARCH_VERB_PATTERN.match(text)) and bool(
        detect_categories(text) or re.search(r"\b(?:san pham|sp)\b", text)
        or any(x in text for x in UNSUPPORTED_CATEGORY_TOKENS))


def _raw_code(brief: str, token: str) -> str:
    """Warehouse/lot code exactly as typed (case kept); trailing punctuation is never part of it."""
    match = re.search(rf"(?<![A-Za-z0-9._-]){re.escape(token)}(?![A-Za-z0-9_-])", brief, re.IGNORECASE)
    return match.group(0) if match else token


def business_query_requested(text: str) -> bool:
    text = normalize_text(text)
    return any(token in text for token in (
        "loc ", "ton kho", "het hang", "han su dung", "han dung", "het han", "ban chay",
        "ban tot", "doanh thu", "doanh so", "chuyen doi", "xem nhieu", "duoc xem",
        "luot xem", "them gio", "ban cham", "tang manh", "tang truong",
        "chi lay tu kho", "chi lay o kho", "ma kho", "lo hang", "ma lo",
        "con du hang",
    ))


def build_query_plan(brief: str, *, today: dt.date | None = None) -> dict:
    today = today or dt.datetime.now(ZoneInfo(TIMEZONE)).date()
    text = normalize_text(brief)
    categories = detect_categories(text)
    count_text = COUNT_EXCLUDE_PATTERN.sub(" ", text)
    count = (re.search(r"\b(?:top\s+|loc\s+(?:ra\s+)?)?(\d+)\s*(?:san pham|sp|kem|sua|gel|serum)\b", count_text)
             or LEADING_COUNT_PATTERN.match(count_text))
    limit = int(count.group(1)) if count else 10
    questions: list[str] = []
    if not 1 <= limit <= 50:
        questions.append("Số lượng cần nằm trong khoảng 1–50 sản phẩm.")
    if len(categories) > 1:
        questions.append("Hãy lọc một danh mục mỗi lần để xếp hạng cùng nhóm sản phẩm.")
    if not categories and any(x in text for x in UNSUPPORTED_CATEGORY_TOKENS):
        questions.append("Danh mục này chưa có ánh xạ xác minh trong catalog; hãy chọn danh mục có sẵn trong Prompt Builder.")
    plan = {
        "version": 1, "category": categories[0] if len(categories) == 1 else None,
        "limit": min(50, max(1, limit)), "limit_explicit": bool(count), "as_of": today.isoformat(),
        "stock_min": None, "stock_max": None, "expiry_from": None, "expiry_to": None,
        "expiry_required": False, "rank_by": "catalog", "window_days": 30,
        "warehouse_id": None, "batch_id": None,
        "brand": None, "brand_mode": None, "per_brand_max": None,
        "questions": questions, "assumptions": [],
    }
    warehouse = re.search(r"(?:chi lay (?:tu|o) kho|kho cu the|ma kho)\s*[:#-]?\s*" + SCOPE_CODE, text)
    batch = re.search(r"(?:chi lay tu lo hang|lo hang|ma lo)\s*[:#-]?\s*" + SCOPE_CODE, text)
    if warehouse:
        plan["warehouse_id"] = _raw_code(brief, warehouse.group(1))
    elif any(x in text for x in ("chi lay tu kho", "chi lay o kho", "kho cu the", "ma kho")):
        questions.append("Hãy nhập rõ mã kho, ví dụ: chỉ lấy từ kho KHO-HN-01.")
    if batch:
        plan["batch_id"] = _raw_code(brief, batch.group(1))
    elif any(x in text for x in ("chi lay tu lo hang", "ma lo")):
        questions.append("Hãy nhập rõ mã lô, ví dụ: chỉ lấy từ lô hàng LOT-2026-09-A.")
    brand = BRAND_PATTERN.search(text)
    if brand:
        plan["brand"] = brand.group(1).strip()
        plan["brand_mode"] = "prefer" if re.search(r"uu tien[^,.;]*$", text[:brand.start()]) else "only"
    per_brand = PER_BRAND_PATTERN.search(text)
    if per_brand:
        if 1 <= int(per_brand.group(1)) <= 50:
            plan["per_brand_max"] = int(per_brand.group(1))
        else:
            questions.append("Giới hạn mỗi thương hiệu cần nằm trong khoảng 1–50 sản phẩm.")
    if "het hang" in text and "sap het hang" not in text:
        plan["stock_max"] = 0
        plan["rank_by"] = "stock_asc"
    elif any(x in text for x in ("ton kho", "con hang", "con du hang", "han su dung", "han dung", "het han")):
        plan["stock_min"] = 1
        plan["rank_by"] = "stock_desc"
    if "con du hang" in text:
        plan["assumptions"].append("“Còn đủ hàng” được hiểu là tồn kho khả dụng từ 1; chưa có ngưỡng riêng cho livestream.")
    stock = re.search(r"(?:ton kho|ton)\s*(?:tren|hon|it nhat|tu|duoi|it hon|toi da)\s*(\d+)\b", text)
    if stock:
        n = int(stock.group(1))
        is_lower = any(x in stock.group(0) for x in ("duoi", "it hon", "toi da"))
        inclusive = "toi da" in stock.group(0) or "it nhat" in stock.group(0) or " tu " in stock.group(0)
        plan["stock_max" if is_lower else "stock_min"] = n if inclusive else n + (-1 if is_lower else 1)
        plan["rank_by"] = "stock_asc" if is_lower else "stock_desc"
    elif "ton kho thap" in text or "sap het hang" in text:
        questions.append("Tồn kho thấp là còn tối đa bao nhiêu sản phẩm? Ví dụ: tồn kho tối đa 10.")
    stock_bounds = re.findall(STOCK_BOUND_PATTERN, text)
    out_of_stock = "het hang" in text and "sap het hang" not in text
    if len(stock_bounds) > 1 or (out_of_stock and (stock_bounds or "con hang" in text or "con du hang" in text)):
        questions.append("Điều kiện tồn kho đang mâu thuẫn hoặc có nhiều ngưỡng; hãy chọn một: hết hàng, tồn kho tối đa N hoặc tồn kho trên N.")

    if re.search(r"\b(?:han su dung|han dung|het han)\b", text):
        plan.update(expiry_required=True, rank_by="expiry", expiry_from=today.isoformat())
        interval = re.search(r"(?:tu\s+)?(\d+)\s*(?:thang\s*)?(?:den|[-–])\s*(\d+)\s*thang", text)
        bound = re.search(r"(?:con\s+)?(toi da|khong qua|duoi|it nhat|tren)\s*(\d+)\s*thang", text)
        if (interval and bound) or len(re.findall(r"(?:toi da|khong qua|duoi|it nhat|tren)\s*\d+\s*thang", text)) > 1:
            questions.append("Mỗi lần chỉ chọn một điều kiện hạn dùng: từ A đến B tháng, hoặc tối đa/ít nhất N tháng.")
        if interval:
            lo, hi = map(int, interval.groups())
            if 0 <= lo <= hi <= 120:
                plan.update(expiry_from=add_months(today, lo).isoformat(), expiry_to=add_months(today, hi).isoformat())
            else:
                questions.append("Khoảng hạn còn lại cần tăng dần và nằm trong 0–120 tháng.")
        elif bound and int(bound.group(2)) <= 120:
            op, months = bound.group(1), int(bound.group(2))
            date = add_months(today, months)
            if op in {"tren", "it nhat"}:
                plan["expiry_from"] = (date + dt.timedelta(days=op == "tren")).isoformat()
            else:
                plan["expiry_to"] = (date - dt.timedelta(days=op == "duoi")).isoformat()
        else:
            questions.append("Hạn còn lại cần rõ khoảng: tối đa 18 tháng, ít nhất 18 tháng, hoặc từ 17 đến 19 tháng. 'Khoảng 18 tháng' chưa đủ rõ.")

    strategies = [
        ("revenue_low_conversion", "chuyen doi" in text and ("doanh thu" in text or "doanh so" in text)),
        ("views_no_orders", any(x in text for x in ("xem nhieu", "duoc xem", "luot xem"))),
        ("cart", "them gio" in text), ("discount_slow", "giam gia" in text and "ban cham" in text),
        ("growth", "tang manh" in text or "tang truong" in text),
        ("units", "ban chay" in text or "ban tot" in text),
    ]
    selected_strategies = [key for key, hit in strategies if hit]
    if len(selected_strategies) > 1:
        questions.append("Hãy chọn một tiêu chí xếp hạng bán hàng mỗi lần.")
    if selected_strategies:
        windows = set(re.findall(r"\b(7|30)\s*ngay\b", text))
        if "toan ky" in text or "tu truoc den nay" in text:
            windows.add("all")
        if len(windows) > 1:
            questions.append("Mỗi lần chỉ chọn một kỳ thống kê bán hàng: 7 ngày, 30 ngày hoặc toàn kỳ.")
    if selected_strategies:
        plan["rank_by"] = selected_strategies[0]
        if plan["rank_by"] == "views_no_orders" and not any(x in text for x in ("chua chot", "chua mua", "chua co don")):
            questions.append("Mẫu hiện hỗ trợ xem nhiều nhưng chưa mua. Hãy nói rõ có cần điều kiện chưa chốt đơn hay không.")
    elif "ban cham" in text or "chuyen doi" in text or "doanh thu" in text or "doanh so" in text:
        questions.append("Tiêu chí chưa được hỗ trợ rõ. Chọn một mẫu phân tích bán hàng bên dưới.")
    if re.search(r"\b7 ngay\b", text):
        plan["window_days"] = 7
    elif "toan ky" in text or "tu truoc den nay" in text:
        plan["window_days"] = None
        if plan["rank_by"] not in {"units", "catalog", "expiry", "stock_desc", "stock_asc"}:
            questions.append("Tiêu chí này cần cửa sổ 7 hoặc 30 ngày để đối chiếu số liệu.")
    elif "tuan nay" in text:
        questions.append("Chưa có thống kê tuần đang diễn ra. Bạn có thể chọn 7 ngày hoàn tất gần nhất và so với 7 ngày trước đó.")
    else:
        period = re.search(r"\b(\d+)\s*ngay\s*(?:qua|gan|gan nhat)", text)
        if period and int(period.group(1)) != 30:
            questions.append("Hiện hỗ trợ thống kê 7 hoặc 30 ngày hoàn tất gần nhất.")
    if plan["rank_by"] in {"units", "revenue_low_conversion", "views_no_orders", "cart", "discount_slow", "growth"}:
        period_label = f"{plan['window_days']} ngày hoàn tất trước {today:%d/%m/%Y}" if plan["window_days"] else "toàn kỳ theo số bán trong catalog (không suy ra 30 ngày)"
        plan["assumptions"].append("Kỳ thống kê: " + period_label)
    if not categories and not re.search(r"\b(?:san pham|sp)\b", text):
        questions.append("Chưa xác định được nhóm sản phẩm; hãy ghi rõ danh mục hoặc 'sản phẩm'.")
    if re.search(r"\b(?:khong|ngoai tru|tru)\b", text.replace("khong qua", "").replace("chua chot", "")):
        questions.append("Lệnh có phủ định/loại trừ chưa được hỗ trợ. Chọn mẫu lọc hoặc tách rõ điều kiện trước khi chạy.")
    # Unsupported qualifiers must not silently broaden a structured query.
    if any(x in text for x in ("da dau", "da nhay cam", "loai tru", "khong lay", "tru thuong hieu")):
        questions.append("Bộ lọc loại da/loại trừ chưa có ánh xạ xác minh trong truy vấn này; hãy dùng bộ lọc sản phẩm trước.")
    from agents.product_resolver import _requested_constraints
    has_condition = bool(
        count or categories or plan["brand"] or plan["per_brand_max"] or plan["warehouse_id"] or plan["batch_id"]
        or plan["expiry_required"] or plan["stock_min"] is not None or plan["stock_max"] is not None
        or plan["rank_by"] != "catalog" or _extract_explicit_ids(text) or _requested_constraints([text])
    )
    if not has_condition and not questions:
        questions.append(NO_CONDITION_QUESTION)
    if "phong cach" in text:
        plan["assumptions"].append("Phong cách dẫn chuyện chỉ dùng khi tạo kịch bản livestream; không ảnh hưởng bộ lọc.")
    plan["summary"] = [
        f"Số lượng: tối đa {plan['limit']} sản phẩm",
        "Danh mục: " + CATEGORY_LABELS.get(plan["category"], "Tất cả sản phẩm"),
        "Xếp hạng: " + METRIC_LABELS[plan["rank_by"]],
    ]
    for label, key in (("Tồn kho từ", "stock_min"), ("Tồn kho tối đa", "stock_max")):
        if plan[key] is not None:
            plan["summary"].append(f"{label}: {plan[key]}")
    if plan["expiry_required"]:
        plan["summary"].append(f"Hạn dùng từ {plan['expiry_from']} đến {plan['expiry_to'] or 'không giới hạn'}; loại lô hết hạn/cách ly")
    if plan["warehouse_id"]:
        plan["summary"].append("Kho: " + plan["warehouse_id"])
    if plan["batch_id"]:
        plan["summary"].append("Lô: " + plan["batch_id"])
    if plan["brand"]:
        plan["summary"].append(("Ưu tiên thương hiệu: " if plan["brand_mode"] == "prefer" else "Thương hiệu: ") + plan["brand"])
    if plan["per_brand_max"]:
        plan["summary"].append(f"Mỗi thương hiệu tối đa {plan['per_brand_max']} sản phẩm")
    return plan


def _product_id(row: dict) -> str:
    return str(row.get("ma_san_pham") or row.get("product_id") or row.get("id") or row.get("_id") or "")


def _category_matches(row: dict, category: str | None) -> bool:
    if category is None:
        return True
    if category in CATEGORY_PATHS:
        fields = normalize_text(" ".join(str(row.get(k) or "") for k in ("loai_san_pham", "danh_muc_day_du", "category")))
        return any(x in fields for x in CATEGORY_PATHS[category][1])
    # SPF, descriptions and ingredients are deliberately not category evidence.
    text = normalize_text(" ".join(str(row.get(k) or "") for k in ("loai_san_pham", "danh_muc_day_du", "category", "ten_san_pham", "name")))
    if category == "sunscreen" and any(x in text for x in ("kem nen", "cushion", "bb cream", "son moi")):
        return False
    return any(x in text for x in CATEGORY_ALIASES[category])


def _category_id(row: dict) -> int | None:
    """ma_danh_muc as an int; san_pham stores it as a number or a numeric string."""
    value = number(row.get("ma_danh_muc"))
    return int(value) if value is not None and value == int(value) else None


def _percentile(values: list[float], fraction: float) -> float:
    ordered = sorted(values)
    index = (len(ordered) - 1) * fraction
    lo = int(index)
    return ordered[lo] + (ordered[min(lo + 1, len(ordered) - 1)] - ordered[lo]) * (index - lo)


def execute_query(plan: dict, catalog: list[dict], *, batches: list[dict] | None = None,
                  snapshots: list[dict] | None = None, brief: str = "") -> dict:
    """Return selection evidence; callers decide whether to build a campaign."""
    from agents.product_resolver import _matches_constraints, _requested_constraints, normalize_catalog_product

    result = {"query": plan, "selected": [], "warnings": list(plan["questions"]),
              "missing_data": {}, "status": "NEEDS_INPUT", "complete": False,
              "matched_count": 0, "requested_count": plan["limit"], "checked_at": dt.datetime.now(dt.timezone.utc).isoformat()}
    if plan["questions"]:
        return result
    today = dt.date.fromisoformat(plan["as_of"])
    batch_map: dict[str, list] = {}
    for batch in batches or []:
        batch_map.setdefault(_product_id(batch), []).append(batch)

    def batch_in_scope(batch: dict) -> bool:
        warehouse = plan.get("warehouse_id")
        batch_id = plan.get("batch_id")
        if warehouse and normalize_text(str(batch.get("warehouse_id") or "")) != normalize_text(warehouse):
            return False
        if batch_id and normalize_text(batch_id) not in {normalize_text(str(batch.get(k) or "")) for k in ("batch_id", "batch_code")}:
            return False
        return True

    metrics: dict[str, list] = {}
    for snapshot in snapshots or []:
        if snapshot.get("window_days") == plan["window_days"] and local_date(snapshot.get("as_of")) == today:
            metrics.setdefault(_product_id(snapshot), []).append(snapshot)
    missing: Counter = Counter()
    rows = []
    category_ids = None if plan.get("category_ids") is None else set(plan["category_ids"])
    constraints = _requested_constraints([brief])
    from agents.intent_parser import _extract_explicit_ids
    explicit_ids = _extract_explicit_ids(normalize_text(brief))
    for raw in catalog:
        pid, name = _product_id(raw), str(raw.get("ten_san_pham") or raw.get("name") or "")
        status = normalize_text(raw.get("trang_thai", raw.get("status", "")))
        if not pid or not name or status in {"inactive", "hidden", "disabled", "0", "an", "ngung ban"}:
            continue
        if explicit_ids and pid not in explicit_ids:
            continue
        if not _category_matches(raw, plan["category"]):
            continue
        if category_ids is not None and _category_id(raw) not in category_ids:
            continue
        normalized = normalize_catalog_product(raw)
        if not _matches_constraints(normalized, constraints):
            continue
        if plan.get("brand") and plan.get("brand_mode") == "only" and normalize_text(normalized["brand"]) != plan["brand"]:
            continue
        evidence = {"stock": first_number(raw, STOCK_FIELDS), "price": first_number(raw, ("gia_ban", "price"))}
        if plan.get("brand") or plan.get("per_brand_max"):
            evidence["brand"] = normalized["brand"]
        matched_batches = []
        if plan["expiry_required"]:
            source_batches = batch_map.get(pid)
            if source_batches is None:
                # An expiry query is verifiable only from the batch ledger.
                # Catalog-level dates (or descriptive shelf-life text) do not
                # identify a lot, quantity, warehouse, or its status, so they
                # must never be presented as a synthetic "catalog" batch.
                missing["expiry_or_batch_quantity"] += 1
                continue
            source_batches = [batch for batch in source_batches if batch_in_scope(batch)]
            unknown = False
            for batch in source_batches:
                if batch.get("status") != "available":
                    continue
                qty, expiry = number(batch.get("available_quantity")), local_date(batch.get("expires_on"))
                batch_label = str(batch.get("batch_id") or batch.get("batch_code") or "").strip()
                if not batch_label or qty is None or expiry is None:
                    unknown = True
                    continue
                if qty <= 0 or expiry <= today:
                    continue
                if expiry < dt.date.fromisoformat(plan["expiry_from"]):
                    continue
                if plan["expiry_to"] and expiry > dt.date.fromisoformat(plan["expiry_to"]):
                    continue
                matched_batches.append({"batch_id": batch_label, "expires_on": expiry.isoformat(), "quantity": qty})
            if unknown:
                missing["expiry_or_batch_quantity"] += 1
            if not matched_batches:
                continue
            evidence.update(batches=matched_batches, eligible_quantity=sum(x["quantity"] for x in matched_batches),
                            expires_on=min(x["expires_on"] for x in matched_batches))
            stock = evidence["eligible_quantity"]
        else:
            if pid in batch_map:
                scoped_batches = [b for b in batch_map[pid] if batch_in_scope(b)]
                if (plan.get("warehouse_id") or plan.get("batch_id")) and not scoped_batches:
                    continue  # ledger proves this product is outside the requested warehouse/lot
                quantities = [number(b.get("available_quantity")) for b in scoped_batches if b.get("status") == "available" and local_date(b.get("expires_on")) and local_date(b["expires_on"]) > today]
                # A known batch ledger is authoritative; never use a legacy total instead.
                invalid = any(b.get("status") == "available" and (number(b.get("available_quantity")) is None or local_date(b.get("expires_on")) is None) for b in scoped_batches)
                evidence["stock"] = None if invalid else sum(q for q in quantities if q is not None)
                if plan.get("warehouse_id") or plan.get("batch_id"):
                    evidence["warehouse_id"] = plan.get("warehouse_id")
                    evidence["batch_id"] = plan.get("batch_id")
            elif plan.get("warehouse_id") or plan.get("batch_id"):
                missing["expiry_or_batch_quantity"] += 1
                continue
            stock = evidence["stock"]
        if plan["stock_min"] is not None or plan["stock_max"] is not None or plan["rank_by"].startswith("stock"):
            if stock is None:
                missing["stock"] += 1
                continue
            if plan["stock_min"] is not None and stock < plan["stock_min"]:
                continue
            if plan["stock_max"] is not None and stock > plan["stock_max"]:
                continue
        rank = plan["rank_by"]
        if rank in {"units", "revenue_low_conversion", "views_no_orders", "cart", "discount_slow", "growth"}:
            if rank == "units" and plan["window_days"] is None:
                units = first_number(raw, SALES_FIELDS)
                if units is None:
                    missing["lifetime_sales"] += 1
                    continue
                evidence.update(units_sold=units, metric_source="catalog_lifetime")
            else:
                versions = metrics.get(pid, [])
                snapshot = versions[0] if len(versions) == 1 else {}
                required = {"units": ("units_sold",), "cart": ("cart_sessions",),
                            "growth": ("units_sold", "previous_units_sold"),
                            "discount_slow": ("units_sold",),
                            "views_no_orders": ("viewed_sessions", "converted_sessions"),
                            "revenue_low_conversion": ("net_revenue", "viewed_sessions", "converted_sessions")}[rank]
                if snapshot.get("coverage_complete") is not True or any(number(snapshot.get(k)) is None for k in required):
                    missing["sales_snapshot"] += 1
                    continue
                evidence.update({k: number(snapshot[k]) for k in required})
                evidence["metric_source"] = "product_metrics_snapshots"
                if "viewed_sessions" in required:
                    views, buyers = evidence["viewed_sessions"], evidence["converted_sessions"]
                    if buyers > views:
                        missing["invalid_conversion_cohort"] += 1
                        continue
                    if views < 100:
                        continue
                    evidence["conversion_rate"] = buyers / views
                if rank == "views_no_orders" and evidence["converted_sessions"] != 0:
                    continue
                if rank == "growth":
                    previous = evidence["previous_units_sold"]
                    if previous == 0:
                        missing["growth_baseline"] += 1
                        continue
                    evidence["absolute_growth"] = evidence["units_sold"] - previous
                    evidence["growth_rate"] = evidence["absolute_growth"] / previous
                    if evidence["absolute_growth"] <= 0:
                        continue
                if rank == "discount_slow":
                    price, original = evidence["price"], first_number(raw, ("gia_thi_truong", "market_price"))
                    if price is None or original is None:
                        missing["price"] += 1
                        continue
                    if not original > price:
                        continue
                    evidence["discount_percent"] = round(100 * (1 - price / original), 2)
                    evidence["units_per_day"] = evidence["units_sold"] / plan["window_days"]
            if rank == "units" and evidence["units_sold"] == 0:
                continue
        rows.append({"product_id": pid, "name": name, "reason": METRIC_LABELS[rank], "evidence": evidence})
    rank = plan["rank_by"]
    if rank in {"revenue_low_conversion", "discount_slow"}:
        if len(rows) < 5:
            result["warnings"].append("Cần ít nhất 5 sản phẩm đủ số liệu trong nhóm để so sánh cao/thấp.")
            missing["comparison_sample"] += 1
            rows = []
        elif rank == "revenue_low_conversion":
            revenue = _percentile([r["evidence"]["net_revenue"] for r in rows], .75)
            conversion = _percentile([r["evidence"]["conversion_rate"] for r in rows], .25)
            rows = [r for r in rows if r["evidence"]["net_revenue"] >= revenue and r["evidence"]["conversion_rate"] <= conversion]
        else:
            slow = _percentile([r["evidence"]["units_per_day"] for r in rows], .25)
            rows = [r for r in rows if r["evidence"]["units_per_day"] <= slow]
    def sort_key(row):
        e = row["evidence"]
        keys = {"units": "units_sold", "cart": "cart_sessions", "growth": "growth_rate",
                "views_no_orders": "viewed_sessions", "revenue_low_conversion": "net_revenue"}
        if rank in keys:
            return (-e[keys[rank]], row["product_id"])
        if rank == "expiry":
            return (e["expires_on"], row["product_id"])
        if rank.startswith("stock"):
            return ((e["stock"] or 0) * (-1 if rank == "stock_desc" else 1), row["product_id"])
        if rank == "discount_slow":
            return (e["units_per_day"], row["product_id"])
        return (row["product_id"],)
    rows.sort(key=sort_key)
    if plan.get("brand") and plan.get("brand_mode") == "prefer":
        rows.sort(key=lambda row: normalize_text(row["evidence"].get("brand")) != plan["brand"])
    if plan.get("per_brand_max"):
        per_brand: Counter = Counter()
        capped = []
        for row in rows:
            brand_key = normalize_text(row["evidence"].get("brand"))
            if not brand_key:
                missing["brand"] += 1
                continue
            if per_brand[brand_key] < plan["per_brand_max"]:
                per_brand[brand_key] += 1
                capped.append(row)
        rows = capped
    result.update(selected=rows[:plan["limit"]], matched_count=len(rows), missing_data=dict(missing),
                  complete=not missing, status="RESULTS_READY" if rows else "DATA_UNAVAILABLE" if missing else "EMPTY_RESULTS")
    if missing:
        result["warnings"].append("Có sản phẩm thiếu dữ liệu xác minh; thứ hạng chỉ áp dụng trên tập đủ dữ liệu. Cần bổ sung kho/lô hạn dùng hoặc snapshot bán hàng, không thay bằng số đánh giá.")
    if len(rows) < plan["limit"]:
        result["warnings"].append(f"Tìm được {len(rows)}/{plan['limit']} sản phẩm phù hợp; không bù sản phẩm ngoài điều kiện.")
    return result
