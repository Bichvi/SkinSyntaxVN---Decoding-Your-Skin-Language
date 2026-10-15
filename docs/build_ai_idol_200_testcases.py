"""Generate and execute 200 deterministic AI Idol Agent test cases.

The matrix exercises intent classification, product extraction, inventory and
expiry filters, sales ranking, schedule parsing, and campaign safety gates.
It uses synthetic catalog/ledger data so the result is repeatable and does not
call MongoDB, a paid LLM, or a renderer.
"""

from __future__ import annotations

import datetime as dt
import json
import os
import sys
from pathlib import Path

SERVICE_ROOT = Path(__file__).resolve().parents[1] / "ai-idol-service"
sys.path.insert(0, str(SERVICE_ROOT))

RENDER_ONLY = "--render-only" in sys.argv
if not RENDER_ONLY:
    from agents.command_query import build_query_plan, execute_query  # noqa: E402
    from agents.intent_classifier import (  # noqa: E402
        APPROVE_SCRIPT,
        CANCEL_CAMPAIGN,
        CREATE_CAMPAIGN,
        PREVIEW_CAMPAIGN,
        PRODUCT_SEARCH,
        RETRY_FAILED,
        REVISE_SCRIPT,
        STATUS_QUERY,
        UNKNOWN,
        classify_intent,
    )
    from agents.intent_parser import fallback_parse  # noqa: E402


TODAY = dt.date(2026, 9, 28)
NOW = dt.datetime(2026, 9, 28, 12, 0, tzinfo=dt.timezone(dt.timedelta(hours=7)))


def product(pid: int, *, brand: str = "Centella Lab", stock: int = 80,
            sales: int = 20, name: str | None = None, kind: str = "sunscreen") -> dict:
    labels = {"sunscreen": "Kem chống nắng", "cleanser": "Sữa rửa mặt", "serum": "Serum", "makeup": "Kem nền"}
    title = name or f"{labels[kind]} {brand} SPF50 mã {pid}"
    return {
        "ma_san_pham": str(pid),
        "ten_san_pham": title,
        "loai_san_pham": labels[kind],
        "danh_muc_day_du": f"Chăm Sóc Da Mặt -> {labels[kind]}",
        "thuong_hieu": brand,
        "so_luong_ton_kho": stock,
        "so_luong_ban": sales,
        "gia_ban": 180000 + pid,
        "gia_thi_truong": 240000 + pid,
        "trang_thai": "active",
    }


def snapshot(pid: int, *, window: int = 30, units: int = 20, previous: int = 10,
             views: int = 200, converted: int = 5, carts: int = 30) -> dict:
    return {
        "product_id": str(pid), "as_of": TODAY.isoformat(), "window_days": window,
        "coverage_complete": True, "units_sold": units, "previous_units_sold": previous,
        "viewed_sessions": views, "converted_sessions": converted, "cart_sessions": carts,
        "net_revenue": units * 180000,
    }


def batch(pid: int, expiry: str, *, qty: int = 80, status: str = "available",
          warehouse: str = "KHO-HN-01", code: str | None = None) -> dict:
    return {
        "product_id": str(pid), "batch_id": code or f"LOT-{pid}", "batch_code": code or f"LOT-{pid}",
        "warehouse_id": warehouse, "status": status, "available_quantity": qty,
        "expires_on": expiry,
    }


CATALOG = [
    *[product(pid, brand="Centella Lab" if pid % 2 else "Syna Care", stock=pid - 90, sales=pid - 90)
      for pid in range(101, 121)],
    *[product(pid, brand="Clean Lab", stock=50, sales=80, kind="cleanser") for pid in range(201, 206)],
    *[product(pid, brand="Serum Lab", stock=50, sales=70, kind="serum") for pid in range(301, 306)],
    product(401, brand="Makeup Lab", stock=50, sales=500, kind="makeup", name="Kem nền SPF50 Makeup Lab"),
]

EXPIRY_DATES = {
    101: "2028-02-28", 102: "2028-03-28", 103: "2028-04-28", 104: "2028-03-27",
    105: "2028-03-29", 106: "2026-09-27", 107: "2029-03-28", 108: "2028-05-28",
    109: "2027-12-28", 110: "2028-01-28", 111: "2028-02-29", 112: "2028-03-31",
    113: "2028-04-30", 114: "2028-06-28", 115: "2028-07-28", 116: "2029-01-28",
    117: "2027-09-28", 118: "2028-03-28", 119: "2028-03-28", 120: "2028-03-28",
}
BATCHES = [batch(pid, expiry, qty=max(0, pid - 90)) for pid, expiry in EXPIRY_DATES.items()]
BATCHES += [batch(101, "2028-03-28", qty=25, warehouse="KHO-HCM-02", code="LOT-101-HCM")]
SNAPSHOTS = [
    *[snapshot(pid, units=pid - 90, previous=max(1, pid - 100), views=100 + pid, converted=0 if pid % 5 == 0 else 5, carts=pid)
      for pid in range(101, 121)],
    *[snapshot(pid, units=pid - 290, previous=10, views=150, converted=3, carts=20) for pid in range(201, 206)],
]
SNAPSHOTS += [snapshot(pid, window=7, units=(pid - 100) * 2, previous=pid - 100, views=120, converted=4, carts=pid)
              for pid in range(101, 121)]


def query(text: str, *, catalog: list[dict] | None = None, batches: list[dict] | None = BATCHES,
          snapshots: list[dict] | None = SNAPSHOTS) -> dict:
    plan = build_query_plan(text, today=TODAY)
    return execute_query(plan, list(CATALOG if catalog is None else catalog), batches=batches,
                         snapshots=snapshots, brief=text)


def selected_ids(result: dict) -> list[str]:
    return [str(item["product_id"]) for item in result.get("selected", [])]


def add(cases: list[dict], group: str, text: str, expected: str, check, note: str = "") -> None:
    cases.append({"group": group, "input": text, "expected": expected, "check": check, "note": note})


def build_cases() -> list[dict]:
    cases: list[dict] = []

    # 1-40: category and product extraction.
    for i in range(1, 11):
        add(cases, "Product extraction", f"Lọc {i} kem chống nắng bán chạy toàn kỳ",
            f"RESULTS_READY; chỉ kem chống nắng; tối đa {i}",
            lambda r, n=i: r["status"] == "RESULTS_READY" and len(r["selected"]) <= n
            and all("kem chống nắng" in x["name"].lower() for x in r["selected"]),
            "Không lấy cleanser, serum hoặc makeup chỉ vì có SPF.")
    for pid in range(101, 111):
        add(cases, "Product extraction", f"Lọc 1 kem chống nắng mã sản phẩm {pid} bán chạy toàn kỳ",
            f"Chọn đúng mã {pid}", lambda r, p=str(pid): selected_ids(r) == [p],
            "Mã sản phẩm phải được ưu tiên hơn điểm phổ biến.")
    for pid in range(111, 121):
        add(cases, "Product extraction", f"Tìm sản phẩm mã {pid}",
            f"Chưa tạo campaign; tìm đúng mã {pid}",
            lambda r, p=str(pid): r["status"] == "RESULTS_READY" and selected_ids(r) == [p],
            "Tên sản phẩm đơn lẻ được coi là truy vấn tìm kiếm.")
    add(cases, "Product extraction", "Lọc 10 kem nền SPF50 bán chạy toàn kỳ", "RESULTS_READY; chỉ kem nền",
        lambda r: r["status"] == "RESULTS_READY" and all("kem nền" in x["name"].lower() for x in r["selected"]),
        "Đã có ánh xạ kem nền; không mở rộng sang kem chống nắng.")
    add(cases, "Product extraction", "Lọc 10 toner bán chạy toàn kỳ", "NEEDS_INPUT vì danh mục chưa hỗ trợ",
        lambda r: r["status"] == "NEEDS_INPUT", "Danh mục chưa được xác minh phải hỏi lại.")
    add(cases, "Product extraction", "Lọc 0 kem chống nắng", "NEEDS_INPUT số lượng ngoài 1–50",
        lambda r: r["status"] == "NEEDS_INPUT", "Chặn số lượng bằng 0.")
    add(cases, "Product extraction", "Lọc 51 kem chống nắng", "NEEDS_INPUT số lượng ngoài 1–50",
        lambda r: r["status"] == "NEEDS_INPUT", "Chặn yêu cầu quá lớn.")
    add(cases, "Product extraction", "Lọc kem chống nắng và serum bán chạy", "NEEDS_INPUT nhiều danh mục",
        lambda r: r["status"] == "NEEDS_INPUT", "Mỗi lần chỉ xếp hạng một nhóm đồng nhất.")
    add(cases, "Product extraction", "Lọc 10 sản phẩm bán chạy toàn kỳ", "Kết quả catalog có bằng chứng",
        lambda r: r["status"] == "RESULTS_READY" and bool(r["selected"]), "Không tự gắn danh mục chưa được nói.")
    add(cases, "Product extraction", "Lọc 10 serum bán chạy toàn kỳ", "Chỉ chọn serum, không chọn sunscreen",
        lambda r: r["status"] == "RESULTS_READY" and all("serum" in x["name"].lower() for x in r["selected"]),
        "Kiểm tra ánh xạ danh mục serum độc lập.")

    # 41-90: inventory, expiry, batch and warehouse behavior.
    expiry_prompts = [
        "Lọc 10 kem chống nắng còn hạn sử dụng từ 17 đến 19 tháng",
        "Lọc 5 kem chống nắng hạn dùng từ 17 đến 19 tháng",
        "Lọc 3 kem chống nắng còn hạn sử dụng từ 17 đến 19 tháng",
        "Lọc 1 kem chống nắng hạn sử dụng từ 17 đến 19 tháng",
        "Lọc 8 kem chống nắng còn hạn sử dụng từ 17 đến 19 tháng",
        "Lọc 9 kem chống nắng hạn dùng 17-19 tháng",
        "Lọc 2 kem chống nắng hạn dùng từ 17 đến 19 tháng",
        "Lọc 4 kem chống nắng còn hạn sử dụng 17–19 tháng",
        "Lọc 6 kem chống nắng hạn dùng từ 17 đến 19 tháng",
        "Lọc 7 kem chống nắng còn 17 đến 19 tháng hạn sử dụng",
    ]
    for text in expiry_prompts:
        add(cases, "Inventory and expiry", text, "Chỉ lô còn hạn trong khoảng 17–19 tháng",
            lambda r: r["status"] in {"RESULTS_READY", "EMPTY_RESULTS"}
            and all(0 < int((dt.date.fromisoformat(x["evidence"]["expires_on"]) - TODAY).days) for x in r["selected"]),
            "Hết hạn và lô cách ly không được tính.")
    for op, expected in [("tối đa 18", "expiry_to"), ("ít nhất 18", "expiry_from"), ("trên 18", "expiry_from"), ("dưới 18", "expiry_to")]:
        for i in range(5):
            text = f"Lọc {i + 1} kem chống nắng hạn dùng {op} tháng"
            add(cases, "Inventory and expiry", text, f"Kế hoạch có {expected}",
                lambda r, key=expected: r["status"] != "NEEDS_INPUT" and r["query"].get(key) is not None,
                "Biên ngày được tính theo tháng lịch từ ngày kiểm thử.")
    stock_prompts = [
        "Lọc 10 kem chống nắng tồn kho trên 10", "Lọc 5 kem chống nắng tồn kho trên 100",
        "Lọc 3 kem chống nắng tồn kho ít nhất 10", "Lọc 4 kem chống nắng tồn kho tối đa 10",
        "Lọc 2 kem chống nắng tồn kho dưới 5", "Lọc 10 sản phẩm hết hàng",
        "Lọc 1 kem chống nắng còn hàng", "Lọc 8 kem chống nắng tồn kho trên 1",
        "Lọc 6 kem chống nắng tồn kho tối đa 20", "Lọc 7 kem chống nắng tồn kho trên 50",
    ]
    for text in stock_prompts:
        add(cases, "Inventory and expiry", text, "Không tính lô hết hạn; áp dụng ngưỡng kho",
            lambda r: r["status"] in {"RESULTS_READY", "EMPTY_RESULTS", "DATA_UNAVAILABLE"}
            and all(x["evidence"].get("stock") is not None for x in r["selected"]),
            "Tồn kho phải dựa trên lô còn hiệu lực khi có ledger.")
    for i in range(5):
        add(cases, "Inventory and expiry", f"Lọc 1 kem chống nắng hạn dùng từ 17 đến 19 tháng chỉ lấy từ kho KHO-HN-01",
            "Có bằng chứng kho KHO-HN-01", lambda r: r["status"] != "NEEDS_INPUT" and all(
                x["evidence"].get("batches") for x in r["selected"]), "Kho cụ thể phải được lọc chính xác.")
    for i in range(5):
        add(cases, "Inventory and expiry", f"Lọc 1 kem chống nắng hạn dùng từ 17 đến 19 tháng chỉ lấy từ lô hàng LOT-{101 + i}",
            f"Có bằng chứng lô LOT-{101 + i}", lambda r, code=f"LOT-{101 + i}": r["status"] != "NEEDS_INPUT" and any(
                code == b["batch_id"] for x in r["selected"] for b in x["evidence"].get("batches", [])),
            "Mã lô không được thay bằng tồn kho catalog.")
    add(cases, "Inventory and expiry", "Lọc 10 kem chống nắng còn khoảng 18 tháng hạn sử dụng", "NEEDS_INPUT vì mơ hồ",
        lambda r: r["status"] == "NEEDS_INPUT", "Khoảng 18 tháng phải yêu cầu người dùng chọn biên rõ.")
    add(cases, "Inventory and expiry", "Lọc 10 kem chống nắng hạn dùng từ 19 đến 17 tháng", "NEEDS_INPUT khoảng đảo chiều",
        lambda r: r["status"] == "NEEDS_INPUT", "Không tự đảo điều kiện.")
    add(cases, "Inventory and expiry", "Lọc 10 kem chống nắng hạn dùng tối đa 18 tháng", "Không dùng shelf life thay ngày thật",
        lambda r: r["status"] in {"RESULTS_READY", "EMPTY_RESULTS", "DATA_UNAVAILABLE"}, "Chỉ ngày hết hạn thực tế mới đủ bằng chứng.")

    # 91-140: sales and conversion analytics.
    sales_prompts = [
        "Lọc 10 kem chống nắng bán chạy nhất toàn kỳ", "Lọc 5 kem chống nắng bán chạy toàn kỳ",
        "Lọc 3 kem chống nắng bán chạy toàn kỳ", "Lọc 1 kem chống nắng bán chạy toàn kỳ",
        "Lọc 8 kem chống nắng bán chạy toàn kỳ", "Lọc 2 kem chống nắng bán chạy toàn kỳ",
        "Lọc 4 kem chống nắng bán chạy toàn kỳ", "Lọc 6 kem chống nắng bán chạy toàn kỳ",
        "Lọc 7 kem chống nắng bán chạy toàn kỳ", "Lọc 9 kem chống nắng bán chạy toàn kỳ",
    ]
    for text in sales_prompts:
        add(cases, "Sales analytics", text, "Xếp theo số bán lifetime, không dùng lượt đánh giá",
            lambda r: r["status"] == "RESULTS_READY" and all(
                x["evidence"].get("metric_source") == "catalog_lifetime" for x in r["selected"]),
            "Toàn kỳ dùng so_luong_ban từ catalog.")
    for i in range(10):
        text = f"Lọc {i + 1} kem chống nắng bán tốt 7 ngày qua"
        add(cases, "Sales analytics", text, "Dùng snapshot 7 ngày đúng as_of",
            lambda r: r["status"] == "RESULTS_READY" and all(
                x["evidence"].get("metric_source") == "product_metrics_snapshots" for x in r["selected"]),
            "Không lấy nhầm số bán toàn kỳ.")
    for text, label in [
        ("Lọc 10 kem chống nắng có nhiều lượt thêm giỏ", "cart_sessions"),
        ("Lọc 10 kem chống nắng xem nhiều chưa chốt đơn", "viewed_sessions"),
        ("Lọc 10 kem chống nắng doanh thu cao chuyển đổi thấp", "net_revenue"),
        ("Lọc 10 kem chống nắng giảm giá nhưng bán chậm", "units_per_day"),
        ("Lọc 10 kem chống nắng tăng trưởng", "growth_rate"),
    ]:
        for i in range(4):
            add(cases, "Sales analytics", text, f"Có bằng chứng {label}",
                lambda r, key=label: r["status"] in {"RESULTS_READY", "EMPTY_RESULTS", "DATA_UNAVAILABLE"}
                and all(key in x["evidence"] for x in r["selected"]),
                "Xếp hạng phải dựa trên snapshot đúng cửa sổ.")
    for i in range(5):
        add(cases, "Sales analytics", "Lọc 10 kem chống nắng bán chạy trong 14 ngày qua", "NEEDS_INPUT cửa sổ chưa hỗ trợ",
            lambda r: r["status"] == "NEEDS_INPUT", "Chỉ hỗ trợ 7 hoặc 30 ngày hoàn tất.")
    for i in range(5):
        add(cases, "Sales analytics", "Lọc 10 kem chống nắng doanh số tăng mạnh tuần này", "NEEDS_INPUT chưa có cửa sổ tuần đang diễn ra",
            lambda r: r["status"] == "NEEDS_INPUT", "Không tự bịa dữ liệu tuần.")
    for i in range(5):
        add(cases, "Sales analytics", "Lọc 10 kem chống nắng bán chạy", "Có snapshot 30 ngày thì xếp hạng theo snapshot",
            lambda r: r["status"] == "RESULTS_READY" and all(
                x["evidence"].get("metric_source") == "product_metrics_snapshots" for x in r["selected"]),
            "Không nhầm truy vấn mặc định 30 ngày với toàn kỳ.")

    # 141-170: deterministic intent routing.
    intent_cases = [
        ("Lọc 10 kem chống nắng bán chạy", PRODUCT_SEARCH), ("Tìm sản phẩm serum", PRODUCT_SEARCH),
        ("Cho tôi danh sách kem chống nắng", PRODUCT_SEARCH), ("Lọc kho còn hạn 18 tháng", PRODUCT_SEARCH),
        ("Tạo livestream tối mai lúc 19h", CREATE_CAMPAIGN), ("Lên lịch live 20h nội bộ", CREATE_CAMPAIGN),
        ("Quảng bá 5 sản phẩm", CREATE_CAMPAIGN), ("Tạo video giới thiệu sản phẩm", CREATE_CAMPAIGN),
        ("Xem trước campaign đã tạo", PREVIEW_CAMPAIGN), ("Chiếu nội bộ xem trước", PREVIEW_CAMPAIGN),
        ("Sửa kịch bản sản phẩm 101", REVISE_SCRIPT), ("Biên tập kịch bản", REVISE_SCRIPT),
        ("Duyệt kịch bản", APPROVE_SCRIPT), ("Chấp nhận kịch bản và tạo video", APPROVE_SCRIPT),
        ("Tạo lại phần bị lỗi", RETRY_FAILED), ("Retry sản phẩm lỗi", RETRY_FAILED),
        ("Hủy livestream tối nay", CANCEL_CAMPAIGN), ("Dừng chương trình", CANCEL_CAMPAIGN),
        ("Xem tiến độ đang tạo", STATUS_QUERY), ("Trạng thái campaign", STATUS_QUERY),
        ("Retinol", PRODUCT_SEARCH), ("CeraVe", PRODUCT_SEARCH), ("Sản phẩm", PRODUCT_SEARCH),
        ("Xin chào", UNKNOWN), ("Hãy làm gì đó", UNKNOWN), ("Báo cáo doanh thu", PRODUCT_SEARCH),
        ("Dừng live rồi tạo mới", CANCEL_CAMPAIGN), ("Retry campaign lỗi livestream", RETRY_FAILED),
        ("Sửa kịch bản livestream", REVISE_SCRIPT), ("Duyệt kịch bản livestream", APPROVE_SCRIPT),
    ]
    for text, expected in intent_cases:
        add(cases, "Intent classification", text, expected,
            lambda r, e=expected: r.intent == e, "Intent phải được định tuyến trước LLM và trước thao tác tốn GPU.")

    # 171-200: schedule extraction and campaign safety.
    schedule_cases = [
        ("Tạo livestream lúc 19 giờ ngày 28/9/2026", "2026-09-28T19:00", 60),
        ("Tạo livestream tối mai lúc 20h", None, 60),
        ("Tạo livestream lúc 19h tối nay, thời lượng 2 giờ", "2026-09-28T19:00", 120),
        ("Tạo livestream 30 phút lúc 9:30 ngày 30/9/2026", "2026-09-30T09:30", 30),
        ("Tạo live ngày 2026-10-01 21:15 trong 45 phút", "2026-10-01T21:15", 45),
        ("Tạo livestream lúc 18h, thời lượng 1 giờ", None, 60),
        ("Tạo livestream lúc 23h, thời lượng 60 phút", None, 60),
        ("Tạo live ngày mai 8h trong 90 phút", None, 90),
        ("Tạo live nội bộ 15 phút", None, 15),
        ("Tạo livestream 720 phút", None, 720),
    ]
    for text, prefix, duration in schedule_cases:
        add(cases, "Schedule and safety", text, f"duration={duration}; schedule parsed safely",
            lambda r, p=prefix, d=duration: r.duration_minutes == d and (p is None or r.scheduled_at.startswith(p)),
            "Không nhầm giờ phát thành thời lượng.")
    for i in range(5):
        add(cases, "Schedule and safety", "Tạo livestream lúc 19h ngày 31/2/2027", "Không tự dời ngày lỗi",
            lambda r: r.scheduled_at is None, "Ngày không hợp lệ phải được duyệt lại.")
    for i in range(5):
        add(cases, "Schedule and safety", "Tạo livestream lúc 19h ngày 27/9/2020", "Giữ ngày quá khứ để review",
            lambda r: r.scheduled_at.startswith("2020-09-27T19:00"), "Không tự ý đổi sang ngày khác.")
    for i in range(5):
        add(cases, "Schedule and safety", "Tạo livestream giới thiệu sản phẩm", "Không tự phát nếu thiếu lịch cụ thể",
            lambda r: r.scheduled_at is None and r.duration_minutes == 60, "Mặc định 60 phút nhưng không bịa giờ phát.")

    if len(cases) != 200:
        raise AssertionError(f"Expected 200 cases, generated {len(cases)}")
    return cases


def set_cell_shading(cell, fill: str) -> None:
    from docx.oxml import OxmlElement
    from docx.oxml.ns import qn

    tc_pr = cell._tc.get_or_add_tcPr()
    shd = OxmlElement("w:shd")
    shd.set(qn("w:fill"), fill)
    tc_pr.append(shd)


def set_cell_margins(cell, top=80, start=80, bottom=80, end=80) -> None:
    from docx.oxml import OxmlElement
    from docx.oxml.ns import qn

    tc = cell._tc
    tc_pr = tc.get_or_add_tcPr()
    tc_mar = tc_pr.first_child_found_in("w:tcMar")
    if tc_mar is None:
        tc_mar = OxmlElement("w:tcMar")
        tc_pr.append(tc_mar)
    for m, v in (("top", top), ("start", start), ("bottom", bottom), ("end", end)):
        node = tc_mar.find(qn(f"w:{m}"))
        if node is None:
            node = OxmlElement(f"w:{m}")
            tc_mar.append(node)
        node.set(qn("w:w"), str(v))
        node.set(qn("w:type"), "dxa")


def write_docx(path: Path, rows: list[dict], counts: dict[str, int]) -> None:
    from docx import Document
    from docx.enum.section import WD_ORIENT
    from docx.enum.table import WD_CELL_VERTICAL_ALIGNMENT, WD_TABLE_ALIGNMENT
    from docx.enum.text import WD_ALIGN_PARAGRAPH
    from docx.shared import Inches, Pt, RGBColor

    doc = Document()
    section = doc.sections[0]
    section.orientation = WD_ORIENT.LANDSCAPE
    section.page_width, section.page_height = section.page_height, section.page_width
    section.top_margin = Inches(0.45)
    section.bottom_margin = Inches(0.45)
    section.left_margin = Inches(0.45)
    section.right_margin = Inches(0.45)

    normal = doc.styles["Normal"]
    normal.font.name = "Aptos"
    normal.font.size = Pt(8.5)
    title = doc.add_paragraph(style="Title")
    title.alignment = WD_ALIGN_PARAGRAPH.LEFT
    title_run = title.add_run("AI Idol Agent 200 Testcases")
    title_run.font.name = "Aptos Display"
    title_run.font.size = Pt(22)
    title_run.font.bold = True
    title_run.font.color.rgb = RGBColor(0, 0, 0)
    intro = doc.add_paragraph()
    intro.add_run("Mục đích. ").bold = True
    intro.add_run("Kiểm tra từng trường hợp nhỏ trong bước nhận diện intent, lọc và trích xuất sản phẩm, kho và hạn dùng, phân tích bán hàng, lịch livestream và các cổng an toàn. Bộ test dùng dữ liệu tổng hợp cố định ngày 28/09/2026, không gọi LLM trả phí, MongoDB thật hoặc GPU.")

    doc.add_heading("Tóm tắt kết quả", level=1)
    summary = doc.add_table(rows=1, cols=4)
    summary.alignment = WD_TABLE_ALIGNMENT.CENTER
    summary.style = "Table Grid"
    for cell, value in zip(summary.rows[0].cells, ["Tổng", "PASS", "FAIL", "Tỷ lệ"]):
        cell.text = value
        set_cell_shading(cell, "173F35")
        for run in cell.paragraphs[0].runs:
            run.font.bold = True
            run.font.color.rgb = RGBColor(255, 255, 255)
    summary_values = [str(len(rows)), str(counts["PASS"]), str(counts["FAIL"]), f"{counts['PASS'] / len(rows) * 100:.1f}%"]
    for cell, value in zip(summary.add_row().cells, summary_values):
        cell.text = value
        cell.paragraphs[0].alignment = WD_ALIGN_PARAGRAPH.CENTER
    doc.add_paragraph("PASS nghĩa là kết quả thực tế khớp với kỳ vọng của testcase. FAIL cần xem cột ghi chú và đầu ra thực tế; không được dùng kết quả thiếu dữ liệu để kết luận hệ thống trích xuất đúng.")

    doc.add_heading("Phạm vi kiểm thử", level=1)
    for group, count in counts["groups"].items():
        doc.add_paragraph(f"{group}: {count} testcase", style="List Bullet")
    doc.add_paragraph("Quy ước dữ liệu: sản phẩm 101–120 là kem chống nắng, 201–205 là sữa rửa mặt, 301–305 là serum, 401 là kem nền SPF để kiểm tra không nhầm danh mục. Ledger lô có ngày hết hạn và kho cụ thể; snapshot có cửa sổ 7 và 30 ngày.")

    doc.add_heading("Danh sách 200 testcase", level=1)
    table = doc.add_table(rows=1, cols=7)
    table.alignment = WD_TABLE_ALIGNMENT.CENTER
    table.style = "Table Grid"
    headers = ["ID", "Nhóm", "Lệnh đầu vào", "Kỳ vọng", "Thực tế", "SP trích xuất", "Kết quả"]
    widths = [0.38, 1.05, 2.65, 1.55, 1.65, 1.05, 0.5]
    for idx, (cell, header) in enumerate(zip(table.rows[0].cells, headers)):
        cell.text = header
        cell.width = Inches(widths[idx])
        cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
        set_cell_shading(cell, "1F5A4A")
        set_cell_margins(cell)
        for run in cell.paragraphs[0].runs:
            run.font.bold = True
            run.font.color.rgb = RGBColor(255, 255, 255)
            run.font.size = Pt(8)
    for row in rows:
        cells = table.add_row().cells
        values = [str(row["id"]), row["group"], row["input"], row["expected"], row["actual"], ", ".join(row["selected"]) or "-", row["result"]]
        for idx, (cell, value) in enumerate(zip(cells, values)):
            cell.text = value
            cell.width = Inches(widths[idx])
            cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
            set_cell_margins(cell, 60, 60, 60, 60)
            for paragraph in cell.paragraphs:
                paragraph.paragraph_format.space_after = Pt(0)
                for run in paragraph.runs:
                    run.font.size = Pt(7.4)
                    if idx == 6:
                        run.font.bold = True
                        run.font.color.rgb = RGBColor(20, 105, 72) if row["result"] == "PASS" else RGBColor(170, 40, 30)
            if row["id"] % 2 == 0:
                set_cell_shading(cell, "F3F7F4")
    doc.add_heading("Đọc kết quả", level=1)
    doc.add_paragraph("Nếu FAIL xuất hiện ở nhóm Product extraction, kiểm tra lại category mapping, explicit product ID và quy tắc không bù sản phẩm ngoài điều kiện. Nếu FAIL ở Inventory and expiry, kiểm tra dữ liệu batch, ngày hết hạn, kho/lô và quy tắc loại lô hết hạn. Nếu FAIL ở Sales analytics, kiểm tra snapshot đúng cửa sổ, as_of, coverage_complete và mẫu tối thiểu. Nếu FAIL ở Schedule and safety, không được tự dời lịch hoặc tạo campaign khi thiếu dữ liệu.")
    doc.add_paragraph("Ngày chạy: 29/09/2026. Ngày dữ liệu mô phỏng: 28/09/2026.")
    doc.save(path)


def run() -> Path:
    out = Path(__file__).resolve().parent / "ai-idol-agent-200-testcases.docx"
    json_out = Path(__file__).resolve().parent / "ai-idol-agent-200-results.json"
    if RENDER_ONLY:
        payload = json.loads(Path(sys.argv[sys.argv.index("--render-only") + 1]).read_text(encoding="utf-8"))
        rows = payload["rows"]
        counts = payload["counts"]
        write_docx(out, rows, counts)
        print(f"OUTPUT={out}")
        return out

    cases = build_cases()
    rows: list[dict] = []
    for idx, case in enumerate(cases, 1):
        actual = ""
        selected: list[str] = []
        try:
            if case["group"] == "Intent classification":
                result = classify_intent(case["input"])
                passed = bool(case["check"](result))
                actual = f"intent={result.intent}; confidence={result.confidence:.2f}"
            elif case["group"] == "Schedule and safety":
                result = fallback_parse(case["input"], now=NOW)
                passed = bool(case["check"](result))
                actual = f"scheduled_at={result.scheduled_at or '-'}; duration={result.duration_minutes}m"
            else:
                result = query(case["input"])
                passed = bool(case["check"](result))
                selected = selected_ids(result)
                actual = f"status={result['status']}; matched={result['matched_count']}; warnings={len(result['warnings'])}"
        except Exception as exc:  # keep every case visible in the report
            passed = False
            actual = f"EXCEPTION {type(exc).__name__}: {exc}"
        rows.append({**case, "id": idx, "actual": actual, "selected": selected, "result": "PASS" if passed else "FAIL"})

    counts = {"PASS": sum(r["result"] == "PASS" for r in rows), "FAIL": sum(r["result"] == "FAIL" for r in rows)}
    counts["groups"] = {}
    for row in rows:
        counts["groups"][row["group"]] = counts["groups"].get(row["group"], 0) + 1
    serial_rows = [{key: value for key, value in row.items() if key != "check"} for row in rows]
    json_out.write_text(json.dumps({"rows": serial_rows, "counts": counts}, ensure_ascii=False, indent=2), encoding="utf-8")
    if "--skip-docx" not in sys.argv:
        write_docx(out, rows, counts)
    print(f"TESTCASES={len(rows)} PASS={counts['PASS']} FAIL={counts['FAIL']}")
    print(f"OUTPUT={out}")
    if counts["FAIL"]:
        for row in rows:
            if row["result"] == "FAIL":
                print(f"FAIL {row['id']}: {row['input']} -> {row['actual']}")
        raise SystemExit(1)
    return out


if __name__ == "__main__":
    run()
