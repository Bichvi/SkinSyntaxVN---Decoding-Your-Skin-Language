"""Smart-search metrics report (Bước 08). Mongo read-only.

    python scripts/search_metrics.py --days 7 [--out report/search-metrics]

Prints: zero-result rate (search log ``result_count``), fallback CTR and add-to-cart rate per section
(``search_events``), chip clicks, top-20 zero-result queries; writes 3 CSV files to ``--out``
(default ``<repo>/report/search-metrics``). MONGO_URI/MONGO_DB_NAME come from the environment, or
from the repo ``.env`` when unset (never printed).
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

from search_service.metrics import collect  # noqa: E402


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
        if key in {"MONGO_URI", "MONGO_DB_NAME", "MONGO_DB"} and not os.getenv(key):
            os.environ[key] = value.strip().strip('"').strip("'")


def _fmt(value) -> str:
    if value is None:
        return "-"
    if isinstance(value, float):
        return f"{value * 100:.1f}%"
    return str(value)


def _table(headers: list[str], rows: list[list]) -> str:
    cells = [headers] + [[_fmt(c) for c in row] for row in rows]
    widths = [max(len(str(r[i])) for r in cells) for i in range(len(headers))]
    lines = [" | ".join(str(c).ljust(w) for c, w in zip(row, widths)) for row in cells]
    lines.insert(1, "-+-".join("-" * w for w in widths))
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Smart search metrics (read-only)")
    parser.add_argument("--days", type=int, default=7)
    default_out = REPO_ROOT / "report" / "search-metrics" if (REPO_ROOT / "report").is_dir() else Path("report/search-metrics")
    parser.add_argument("--out", default=str(default_out))
    args = parser.parse_args(argv)
    if not 1 <= args.days <= 365:
        print("--days must be 1..365", file=sys.stderr)
        return 2

    _load_env()
    from pymongo import MongoClient

    uri = os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017")
    db_name = os.getenv("MONGO_DB_NAME", os.getenv("MONGO_DB", "skinsyntax"))
    try:
        client = MongoClient(uri, serverSelectionTimeoutMS=15000, appname="search_metrics")
        data = collect(client[db_name], args.days)
    except Exception as exc:  # noqa: BLE001 - CLI boundary; never print the URI
        print(f"ERROR: Mongo unavailable ({type(exc).__name__})", file=sys.stderr)
        return 1

    zero, events = data["zero"], data["events"]
    print(f"== Smart search metrics — last {args.days} day(s) since {data['since']:%Y-%m-%d %H:%M} UTC")
    print(_table(["metric", "value"], [
        ["searches (committed, non-live)", zero["searches"]],
        ["  with result_count", zero["searches_with_result_count"]],
        ["  zero-result", zero["zero_result"]],
        ["zero-result rate", zero["zero_result_rate"]],
        ["live-filter partials skipped", zero["live_partial_skipped"]],
        ["sessions with fallback events", events["sessions"]],
    ]))
    print("\n-- Fallback by section (CTR = click/impression, ATC = add_to_cart/impression)")
    print(_table(["section", "impressions", "clicks", "add_to_cart", "ctr", "atc_rate"],
                 [[r["section"], r["impressions"], r["clicks"], r["add_to_cart"], r["ctr"], r["atc_rate"]]
                  for r in events["sections"]]))
    print("\n-- Chip clicks: " + (", ".join(f"{k}={v}" for k, v in events["chip_clicks"].items()) or "none"))
    print("\n-- Top 20 zero-result queries (q_norm, count)")
    print(_table(["q_norm", "count"], [[q, n] for q, n in zero["top_zero_queries"]]) if zero["top_zero_queries"] else "(none)")

    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    stamp = f"{datetime.now():%Y%m%d}_{args.days}d"
    with open(out / f"summary_{stamp}.csv", "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["metric", "value"])
        for key in ("searches", "searches_with_result_count", "zero_result", "zero_result_rate", "live_partial_skipped"):
            w.writerow([key, zero[key]])
        w.writerow(["fallback_sessions", events["sessions"]])
        for kind, n in events["chip_clicks"].items():
            w.writerow([f"chip_click_{kind}", n])
    with open(out / f"sections_{stamp}.csv", "w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=["section", "impressions", "clicks", "add_to_cart", "ctr", "atc_rate"])
        w.writeheader()
        w.writerows(events["sections"])
    with open(out / f"zero_queries_{stamp}.csv", "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["q_norm", "count"])
        w.writerows(zero["top_zero_queries"])
    print(f"\nCSV: {out}/{{summary,sections,zero_queries}}_{stamp}.csv")
    return 0


if __name__ == "__main__":
    sys.exit(main())
