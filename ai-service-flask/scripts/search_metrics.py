"""Smart-search metrics report (Bước 08). Mongo read-only.

    python scripts/search_metrics.py --days 7 [--out report/search-metrics]

Test traffic is excluded everywhere (``u03verify*`` probes, ``is_test_account`` customers; the product-page /
cart blocks ``similar:*`` / ``cart:analyze`` are reported in their own table, not as search sections).
Prints: zero-result rate (search log ``result_count``), fallback CTR and add-to-cart rate per section
(``search_events``), chip clicks, top-20 zero-result queries; writes 3 CSV files to ``--out``
(default ``<repo>/report/search-metrics``). ``--zero-result-report --days 7 --min-count 2`` (U06) writes
``zero_results_<date>.csv`` with reviewable synonym suggestions instead. ``--experiment <id> [--days 14]`` (U12)
prints CTR / add-to-cart rate per A/B variant with Wilson 95% intervals and writes ``experiment_<id>_<date>.csv``.
MONGO_URI/MONGO_DB_NAME come from the environment, or
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
        if key in {"MONGO_URI", "MONGO_DB_NAME", "MONGO_DB", "SEARCH_USER_HASH_SALT"} and not os.getenv(key):
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


def _zero_result_report(args, mongo_client_cls, uri: str, db_name: str) -> int:
    """U06 part B: zero-result queries -> CSV with reviewable suggestions (never edits any YAML)."""

    from search_service import zero_results
    from search_service.dictionary import EntityDictionary
    from search_service.intent_parser import parse

    try:
        client = mongo_client_cls(uri, serverSelectionTimeoutMS=15000, appname="search_metrics")
        db = client[db_name]
        collected = zero_results.collect_zero_queries(db, args.days, args.min_count)
    except Exception as exc:  # noqa: BLE001 - CLI boundary; never print the URI
        print(f"ERROR: Mongo unavailable ({type(exc).__name__})", file=sys.stderr)
        return 1

    snapshot = None
    try:
        from search_service.dictionary import get_default_dictionary

        snapshot = get_default_dictionary().snapshot()
    except Exception:  # noqa: BLE001 - Neo4j/Mongo dictionary optional: YAML synonyms still work
        snapshot = EntityDictionary(driver=None, mongo_db=None).snapshot()
    probe = None
    if not args.no_semantic:
        try:
            from search_service.branches import CatalogVectorstore

            store = CatalogVectorstore().get()
            if store is not None:
                probe = zero_results.category_probe_from_vectorstore(store, snapshot)
        except Exception as exc:  # noqa: BLE001
            print(f"semantic probe disabled ({type(exc).__name__})", file=sys.stderr)
    rows = zero_results.analyze_all(collected.queries, snapshot, parse, probe)
    out = Path(args.out) / f"zero_results_{datetime.now():%Y%m%d}.csv"
    zero_results.write_report(out, rows)
    kinds: dict[str, int] = {}
    for row in rows:
        kinds[row["suggestion_type"]] = kinds.get(row["suggestion_type"], 0) + 1
    print(f"zero-result report: last {args.days} day(s), min-count {args.min_count}, dictionary={snapshot.source}, "
          f"semantic={'on' if probe else 'off'}")
    print("  log rows: " + ", ".join(f"{k}={v}" for k, v in collected.stats.items()))
    print("  suggestions: " + (", ".join(f"{k}={v}" for k, v in sorted(kinds.items())) or "none"))
    print(f"CSV: {out}  (fill `approve`=y, then run scripts/apply_synonym_suggestions.py --file <csv>)")
    return 0


def _pct(value) -> str:
    return "-" if value is None else f"{value * 100:.2f}%"


def _ci(interval) -> str:
    return "-" if not interval else f"{interval[0] * 100:.2f}%..{interval[1] * 100:.2f}%"


def _experiment_report(args, mongo_client_cls, uri: str, db_name: str) -> int:
    """U12 part B: per-variant results of one experiment (read-only)."""

    from search_service.metrics import collect_experiment

    try:
        client = mongo_client_cls(uri, serverSelectionTimeoutMS=15000, appname="search_metrics")
        data = collect_experiment(client[db_name], args.experiment, args.days)
    except Exception as exc:  # noqa: BLE001 - CLI boundary; never print the URI
        print(f"ERROR: Mongo unavailable ({type(exc).__name__})", file=sys.stderr)
        return 1
    print(f"== Experiment {data['experiment']} - last {args.days} day(s) since {data['since']:%Y-%m-%d %H:%M} UTC")
    print(_table(["variant", "impressions", "clicks", "ctr", "ctr 95% CI (Wilson)", "add_to_cart", "atc_rate",
                  "atc 95% CI (Wilson)", "sessions", "enough sample"],
                 [[r["variant"], r["impressions"], r["clicks"], _pct(r["ctr"]), _ci(r["ctr_ci95"]), r["add_to_cart"],
                   _pct(r["atc_rate"]), _ci(r["atc_ci95"]), r["sessions"], "yes" if r["enough_sample"] else "NO"]
                  for r in data["variants"]]) if data["variants"] else "(no events for this experiment)")
    for comp in data["comparisons"]:
        p_ctr = "-" if comp["ctr_p_value"] is None else f"{comp['ctr_p_value']:.4f}"
        p_atc = "-" if comp["atc_p_value"] is None else f"{comp['atc_p_value']:.4f}"
        print(f"\n{comp['variant']} vs control: CTR diff {_pct(comp['ctr_diff'])} (p={p_ctr}), ATC diff {_pct(comp['atc_diff'])} (p={p_atc})")
    print("\n-- Excluded test traffic: " + ", ".join(f"{k}={v}" for k, v in sorted(data["excluded"].items())))
    print(f"\n>> {data['recommendation']}")
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    path = out / f"experiment_{args.experiment}_{datetime.now():%Y%m%d}.csv"
    with open(path, "w", newline="", encoding="utf-8") as fh:
        w = csv.writer(fh)
        w.writerow(["variant", "impressions", "clicks", "ctr", "ctr_lo", "ctr_hi", "add_to_cart", "atc_rate",
                    "atc_lo", "atc_hi", "sessions", "enough_sample"])
        for r in data["variants"]:
            lo, hi = r["ctr_ci95"] or (None, None)
            alo, ahi = r["atc_ci95"] or (None, None)
            w.writerow([r["variant"], r["impressions"], r["clicks"], r["ctr"], lo, hi, r["add_to_cart"],
                        r["atc_rate"], alo, ahi, r["sessions"], r["enough_sample"]])
    print(f"CSV: {path}")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Smart search metrics (read-only)")
    parser.add_argument("--days", type=int, default=7)
    default_out = REPO_ROOT / "report" / "search-metrics" if (REPO_ROOT / "report").is_dir() else Path("report/search-metrics")
    parser.add_argument("--out", default=str(default_out))
    parser.add_argument("--zero-result-report", action="store_true",
                        help="U06: write zero_results_<date>.csv with synonym suggestions (needs review) and exit")
    parser.add_argument("--min-count", type=int, default=2, help="--zero-result-report: minimum searches per query")
    parser.add_argument("--no-semantic", action="store_true",
                        help="--zero-result-report: skip the Chroma category probe")
    parser.add_argument("--experiment", default="", metavar="ID",
                        help="U12: report one A/B experiment (CTR / add-to-cart per variant, Wilson 95%% CI)")
    args = parser.parse_args(argv)
    if not 1 <= args.days <= 365:
        print("--days must be 1..365", file=sys.stderr)
        return 2
    if args.min_count < 1:
        print("--min-count must be >= 1", file=sys.stderr)
        return 2

    _load_env()
    from pymongo import MongoClient

    uri = os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017")
    db_name = os.getenv("MONGO_DB_NAME", os.getenv("MONGO_DB", "skinsyntax"))
    if args.experiment:
        return _experiment_report(args, MongoClient, uri, db_name)
    if args.zero_result_report:
        return _zero_result_report(args, MongoClient, uri, db_name)
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
    blocks = data["blocks"]["sections"]
    if blocks:
        print("\n-- Product-page / cart blocks (similar, routine, conflict)")
        print(_table(["section", "impressions", "clicks", "add_to_cart", "ctr", "atc_rate"],
                     [[r["section"], r["impressions"], r["clicks"], r["add_to_cart"], r["ctr"], r["atc_rate"]] for r in blocks]))
    print("\n-- Excluded test traffic: " + ", ".join(f"{k}={v}" for k, v in data["excluded"].items()))
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
        for kind, n in data["excluded"].items():
            w.writerow([f"excluded_{kind}", n])
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
