"""Label-scan ("chụp bảng thành phần") business report: scans, conflicts caught, alternatives -> cart -> orders, LLM cost.
Mongo read-only.

    python scripts/label_scan_metrics.py --days 30 [--margin 0.3] [--out report/label-scan-metrics]

Price assumptions (USD per 1M tokens) are NOT verified; override with LABEL_PRICE_<PROVIDER>_IN_USD_PER_M /
LABEL_PRICE_<PROVIDER>_OUT_USD_PER_M and the exchange rate with LABEL_USD_VND (default 26000).
MONGO_URI/MONGO_DB_NAME come from the environment, or from the repo ``.env`` when unset (never printed).
"""

from __future__ import annotations

import argparse
import csv
import os
import sys
from datetime import datetime
from pathlib import Path

HERE = Path(__file__).resolve()
SERVICE_ROOT = HERE.parents[1]          # ai-service-flask
REPO_ROOT = HERE.parents[2]
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

from label_service.metrics import collect, prices_from_env, usd_vnd_from_env  # noqa: E402

_ENV_KEYS = {"MONGO_URI", "MONGO_DB_NAME", "MONGO_DB", "SEARCH_USER_HASH_SALT", "LABEL_USD_VND"}


def _load_env() -> None:
    if os.getenv("MONGO_URI"):
        return
    env_file = REPO_ROOT / ".env"
    if not env_file.is_file():
        return
    for line in env_file.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if (key in _ENV_KEYS or key.startswith("LABEL_PRICE_")) and not os.getenv(key):
            os.environ[key] = value.strip().strip('"').strip("'")


def _pct(value) -> str:
    return "-" if value is None else f"{value * 100:.1f}%"


def _vnd(value) -> str:
    return "-" if value is None else f"{value:,.0f} đ"


def _num(value, digits: int = 2) -> str:
    return "-" if value is None else f"{value:,.{digits}f}"


def _table(headers: list[str], rows: list[list]) -> str:
    cells = [headers] + [[str(c) for c in row] for row in rows]
    widths = [max(len(r[i]) for r in cells) for i in range(len(headers))]
    lines = [" | ".join(c.ljust(w) for c, w in zip(row, widths)) for row in cells]
    lines.insert(1, "-+-".join("-" * w for w in widths))
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Label scan business metrics (read-only)")
    parser.add_argument("--days", type=int, default=30)
    parser.add_argument("--margin", type=float, default=0.3, help="gross margin on attributed revenue (default 0.3)")
    default_out = REPO_ROOT / "report" / "label-scan-metrics" if (REPO_ROOT / "report").is_dir() else Path("report/label-scan-metrics")
    parser.add_argument("--out", default=str(default_out))
    args = parser.parse_args(argv)
    if not 1 <= args.days <= 365:
        print("--days must be 1..365", file=sys.stderr)
        return 2
    if not 0 < args.margin <= 1:
        print("--margin must be in (0, 1]", file=sys.stderr)
        return 2

    _load_env()
    from pymongo import MongoClient

    uri = os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017")
    db_name = os.getenv("MONGO_DB_NAME", os.getenv("MONGO_DB", "skinsyntax"))
    prices, usd_vnd = prices_from_env(), usd_vnd_from_env()
    try:
        client = MongoClient(uri, serverSelectionTimeoutMS=15000, appname="label_scan_metrics")
        data = collect(client[db_name], args.days, prices, usd_vnd=usd_vnd, margin=args.margin)
    except Exception as exc:  # noqa: BLE001 - CLI boundary; never print the URI
        print(f"ERROR: Mongo unavailable ({type(exc).__name__})", file=sys.stderr)
        return 1

    sc, co, od, dv = data["scan"], data["cost"], data["orders"], data["derived"]
    print(f"== Báo cáo chụp bảng thành phần — {args.days} ngày gần nhất, từ {data['since']:%Y-%m-%d %H:%M} UTC")
    print(_table(["chỉ số", "giá trị"], [
        ["số lần chụp (tổng)", sc["scans"]],
        ["  bằng ảnh / bằng chữ", f"{sc['inputs']['photo']} / {sc['inputs']['text']}"],
        ["  phiên khác nhau", sc["sessions"]],
        ["lần chụp có xung đột", sc["with_conflict"]],
        ["  trong đó xung đột mức cao", sc["with_high_conflict"]],
        ["lần chụp có đề xuất thay thế", sc["with_alternatives"]],
        ["phiên thấy đề xuất thay thế", sc["alt_sessions"]],
        ["  phiên thêm vào giỏ SP thay thế", sc["atc_alt_sessions"]],
        ["  tỉ lệ thêm giỏ", _pct(dv["atc_rate"])],
    ]))
    print("\n-- Kết quả đọc ảnh/chữ")
    print(_table(["outcome", "số lần"], [[k, v] for k, v in sc["outcomes"].items()]))
    print("\n-- Phân bố độ phù hợp da")
    print(_table(["skin_verdict", "số lần"], [[k, v] for k, v in sc["skin_verdicts"].items()]) if sc["skin_verdicts"] else "(không có)")
    print("\n-- Thêm vào giỏ từ kết quả chụp")
    print(_table(["loại", "sự kiện", "phiên"], [[k, v["count"], v["sessions"]] for k, v in sc["atc"].items()]))

    print("\n-- Đơn hàng (đơn hủy, đơn trước lần chụp đầu và tài khoản test đã loại)")
    print(_table(["chỉ số", "giá trị"], [
        ["đơn có SP thêm từ kết quả chụp (attributed)", od["attributed"]["orders"]],
        ["  dòng chi tiết", od["attributed"]["lines"]],
        ["  doanh thu attributed", _vnd(od["attributed"]["revenue"])],
        ["đơn của phiên có chụp (assisted)", od["assisted"]["orders"]],
        ["  doanh thu assisted (tạm tính, cận trên)", _vnd(od["assisted"]["revenue"])],
        ["đã loại: hủy / trước lần chụp / tài khoản test",
         f"{od['excluded']['cancelled']} / {od['excluded']['before_first_scan']} / {od['excluded']['test_account']}"],
    ]))

    print(f"\n-- Chi phí LLM (GIÁ GIẢ ĐỊNH, chưa kiểm chứng; tỉ giá {usd_vnd:,.0f} VND/USD)")
    print(_table(["provider", "giá in $/1M", "giá out $/1M", "lượt gọi", "lỗi", "prompt tok", "completion tok", "USD"],
                 [[p, _num(prices[p]["in"]), _num(prices[p]["out"]), t["calls"], t["failed"], t["prompt"],
                   t["completion"], _num(t["cost_usd"], 4)] for p, t in co["by_provider"].items()]))
    print(f"Tổng chi phí: {_num(co['cost_usd'], 4)} USD = {_vnd(co['cost_vnd'])}")

    print(f"\n-- Hiệu quả (biên lợi nhuận giả định {args.margin:.0%})")
    print(_table(["chỉ số", "giá trị"], [
        ["chi phí / lần chụp", _vnd(dv["cost_per_scan_vnd"])],
        ["chi phí / lần chụp ảnh", _vnd(dv["cost_per_photo_scan_vnd"])],
        ["lãi gộp attributed", _vnd(dv["attributed_gross_profit_vnd"])],
        ["ROI (lãi gộp / chi phí)", _num(dv["roi"])],
        ["hòa vốn: số lần chụp / 1 đơn attributed", _num(dv["breakeven_scans_per_order"], 0)],
    ]))
    if dv["roi"] is None:
        print("(ROI không xác định: chi phí LLM = 0 hoặc chưa có dữ liệu)")
    print(f"\n-- Loại tài khoản test: {data['excluded']['event_test_account']} sự kiện")

    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    path = out / f"summary_{datetime.now():%Y%m%d}_{args.days}d.csv"
    with open(path, "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["metric", "value"])
        w.writerow(["scans", sc["scans"]])
        w.writerow(["scans_photo", sc["inputs"]["photo"]])
        w.writerow(["scans_text", sc["inputs"]["text"]])
        w.writerow(["sessions", sc["sessions"]])
        for k, v in sc["outcomes"].items():
            w.writerow([f"outcome_{k}", v])
        w.writerow(["scans_with_conflict", sc["with_conflict"]])
        w.writerow(["scans_with_high_conflict", sc["with_high_conflict"]])
        w.writerow(["scans_with_alternatives", sc["with_alternatives"]])
        w.writerow(["alt_sessions", sc["alt_sessions"]])
        w.writerow(["atc_alt_sessions", sc["atc_alt_sessions"]])
        w.writerow(["atc_rate", dv["atc_rate"]])
        for k, v in sc["atc"].items():
            w.writerow([f"atc_{k}_events", v["count"]])
            w.writerow([f"atc_{k}_sessions", v["sessions"]])
        w.writerow(["attributed_orders", od["attributed"]["orders"]])
        w.writerow(["attributed_lines", od["attributed"]["lines"]])
        w.writerow(["attributed_revenue_vnd", od["attributed"]["revenue"]])
        w.writerow(["assisted_orders", od["assisted"]["orders"]])
        w.writerow(["assisted_revenue_vnd", od["assisted"]["revenue"]])
        for p, t in co["by_provider"].items():
            w.writerow([f"{p}_prompt_tokens", t["prompt"]])
            w.writerow([f"{p}_completion_tokens", t["completion"]])
            w.writerow([f"{p}_cost_usd", t["cost_usd"]])
        w.writerow(["cost_usd", co["cost_usd"]])
        w.writerow(["cost_vnd", co["cost_vnd"]])
        w.writerow(["usd_vnd", usd_vnd])
        w.writerow(["margin_assumed", args.margin])
        w.writerow(["prices_are_assumptions", "yes"])
        w.writerow(["cost_per_scan_vnd", dv["cost_per_scan_vnd"]])
        w.writerow(["attributed_gross_profit_vnd", dv["attributed_gross_profit_vnd"]])
        w.writerow(["roi", dv["roi"]])
        w.writerow(["breakeven_scans_per_order", dv["breakeven_scans_per_order"]])
    print(f"\nCSV: {path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
