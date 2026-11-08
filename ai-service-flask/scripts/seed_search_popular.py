"""Bootstrap ``search:popular:v1`` from the historical search log (U04). Mongo is read-only; Redis is written.

    MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-ai python scripts/seed_search_popular.py [--days 90] [--dry-run]

Counts committed searches (``tuong_tac_nguoi_dung``: ``loai_tuong_tac=search``, ``metadata.result_count>0``,
not a live-filter keystroke) per normalized query. PII (phones, emails...), over-long text and test records
(``u03verify*``) are filtered by ``shared.redis_kit.popular``. Safe to re-run: it ADDS counts, so run it once.
"""

from __future__ import annotations

import argparse
import os
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path

SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))


def _counts(days: int) -> dict[str, int]:
    from pymongo import MongoClient

    client = MongoClient(os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017"), serverSelectionTimeoutMS=15000,
                         appname="seed_search_popular")
    db = client[os.getenv("MONGO_DB_NAME", os.getenv("MONGO_DB", "skinsyntax"))]
    since = datetime.now(timezone.utc) - timedelta(days=days)
    pipeline = [
        {"$match": {"loai_tuong_tac": "search", "created_at": {"$gte": since},
                    "metadata.result_count": {"$gt": 0}, "metadata.live": {"$ne": True}}},
        {"$group": {"_id": {"$toLower": "$metadata.query"}, "n": {"$sum": 1}}},
        {"$sort": {"n": -1}},
        {"$limit": 5000},
    ]
    return {str(row["_id"]): int(row["n"]) for row in db.tuong_tac_nguoi_dung.aggregate(pipeline) if row.get("_id")}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--days", type=int, default=90)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)

    from shared.redis_kit import popular

    counts = _counts(args.days)
    usable = {q: n for q, n in counts.items() if popular.normalize_query(q) is not None}
    print(f"log queries: {len(counts)}, usable after PII/length filter: {len(usable)}")
    if args.dry_run:
        return 0
    result = popular.seed(counts)
    print(result)
    return 0 if result["seeded"] or not usable else 1


if __name__ == "__main__":
    raise SystemExit(main())
