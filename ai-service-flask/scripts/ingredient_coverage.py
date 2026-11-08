"""Ingredient-mapping coverage (U06). Mongo read-only; no Neo4j writes.

    python scripts/ingredient_coverage.py [--top 300] [--out unmapped.csv] [--json]

Prints the share of ingredient refs mapped to INCI, the share of active products with at least
one ``role=active`` ingredient and the top-N unmapped names. Reads MONGO_URI/MONGO_DB_NAME from the
environment (run inside the ``skinsyntax-ai`` container). Used before/after editing
``data/graph/ingredient_aliases.yaml`` and as input of ``suggest_ingredient_aliases.py``.
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
from collections import Counter
from pathlib import Path
from typing import Any

SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

from graph_sync import extract  # noqa: E402
from graph_sync.config import load_settings  # noqa: E402
from graph_sync.transform import to_graph_row  # noqa: E402


def compute(rows: list[dict[str, Any]]) -> dict[str, Any]:
    """Pure: coverage numbers + full unmapped counter over visible rows."""

    active = [r for r in rows if r["active"]]
    unmapped: Counter[str] = Counter()       # key (slug) -> refs
    spellings: dict[str, Counter[str]] = {}
    roles: Counter[str] = Counter()
    mapped = total = with_active = with_any = 0
    for row in active:
        has_active = False
        for ing in row["ingredients"]:
            total += 1
            if ing["inci"] is None:
                unmapped[ing["key"]] += 1
                spellings.setdefault(ing["key"], Counter())[ing["name"]] += 1
            else:
                mapped += 1
                roles[ing["role"] or "none"] += 1
                has_active = has_active or ing["role"] == "active"
        with_active += 1 if has_active else 0
        with_any += 1 if row["ingredients"] else 0
    pct = lambda a, b: round(100.0 * a / b, 2) if b else 0.0  # noqa: E731
    return {
        "products_active": len(active),
        "refs_total": total,
        "refs_mapped": mapped,
        "pct_refs_mapped": pct(mapped, total),
        "distinct_unmapped": len(unmapped),
        "pct_products_with_active": pct(with_active, len(active)),
        "pct_products_with_ingredients": pct(with_any, len(active)),
        "mapped_roles": dict(roles),
        "unmapped": unmapped,
        "spellings": spellings,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Ingredient mapping coverage (read-only)")
    parser.add_argument("--top", type=int, default=300)
    parser.add_argument("--out", help="write the top-N unmapped names to this CSV (key,name,count)")
    parser.add_argument("--json", action="store_true", help="print the summary as JSON")
    args = parser.parse_args(argv)

    settings = load_settings()
    db = extract.connect(settings.mongo_uri, settings.mongo_db)
    lookups = extract.load_lookups(db)
    docs = extract.extract_products(db, batch_size=settings.batch_size)
    rows = [r for r in (to_graph_row(d, lookups) for d in docs) if r is not None]
    stats = compute(rows)
    unmapped: Counter[str] = stats.pop("unmapped")
    spellings: dict[str, Counter[str]] = stats.pop("spellings")
    best = {key: spellings[key].most_common(1)[0][0] for key in unmapped}
    top = [(best[k], n) for k, n in unmapped.most_common(args.top)]
    covered = sum(n for _, n in top)
    stats["top_n"] = args.top
    stats["top_n_refs"] = covered
    stats["top_n_pct_of_all_refs"] = round(100.0 * covered / stats["refs_total"], 2) if stats["refs_total"] else 0.0
    if args.out:
        with open(args.out, "w", newline="", encoding="utf-8") as fh:
            writer = csv.writer(fh)
            writer.writerow(["key", "name", "count"])
            writer.writerows((k, best[k], n) for k, n in unmapped.most_common())
    if args.json:
        print(json.dumps(stats, ensure_ascii=False, indent=2))
    else:
        for key, value in stats.items():
            print(f"{key}: {value}")
        print(f"-- top {args.top} unmapped (count, name)")
        for name, count in top:
            print(f"{count:5d}  {name}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
