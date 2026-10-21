"""Create Mongo collection ``search_events`` + indexes (Bước 08; idempotent, user-approved).

    MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-ai python scripts/setup_search_events.py

Indexes: ``{ts:-1}`` with TTL 180 days (serves both the TTL and the time-range scans) and
``{q_norm:1, ts:-1}``. This is the only schema write; nothing else in Mongo is touched.
"""

from __future__ import annotations

import os
import sys
import time

COLLECTION = "search_events"
TTL_SECONDS = 180 * 24 * 3600


def _setup() -> None:
    from pymongo import MongoClient

    client = MongoClient(os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017"), serverSelectionTimeoutMS=15000,
                         appname="setup_search_events")
    db = client[os.getenv("MONGO_DB_NAME", os.getenv("MONGO_DB", "skinsyntax"))]
    if COLLECTION not in db.list_collection_names():
        db.create_collection(COLLECTION)
        print(f"created collection {COLLECTION}")
    coll = db[COLLECTION]
    coll.create_index([("ts", -1)], name="ts_ttl_180d", expireAfterSeconds=TTL_SECONDS)
    coll.create_index([("q_norm", 1), ("ts", -1)], name="q_norm_ts")
    for name, spec in coll.index_information().items():
        print(name, spec.get("key"), "expireAfterSeconds=" + str(spec.get("expireAfterSeconds", "-")))


def main() -> int:
    for attempt in range(1, 5):  # Atlas SRV DNS lookups fail intermittently inside docker
        try:
            _setup()
            return 0
        except Exception as exc:  # noqa: BLE001 - never print the URI/host
            print(f"attempt {attempt}/4 failed: {type(exc).__name__}", file=sys.stderr)
            time.sleep(2 * attempt)
    return 1


if __name__ == "__main__":
    sys.exit(main())
