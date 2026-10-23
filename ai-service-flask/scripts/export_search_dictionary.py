"""Export the public search dictionary (brands + categories + synonyms) for PHP autocomplete (U04).

    python ai-service-flask/scripts/export_search_dictionary.py [--out backend/app/data/search_dictionary.json]
                                                               [--source auto|yaml] [--dry-run]

Sources (read-only): Neo4j ``Brand``/``Category`` nodes and ``data/graph/synonyms.yaml``; when Neo4j is
unreachable it falls back to Mongo ``thuong_hieu`` + YAML (``--source yaml`` skips both databases).
Connection settings come from the environment or the repo ``.env`` (NEO4J_*, MONGO_*; never printed).
On the host Neo4j defaults to ``bolt://127.0.0.1:7687``.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

HERE = Path(__file__).resolve()
SERVICE_ROOT = HERE.parents[1]          # ai-service-flask
REPO_ROOT = HERE.parents[2]
DEFAULT_OUT = REPO_ROOT / "backend" / "app" / "data" / "search_dictionary.json"
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

_ENV_KEYS = {"NEO4J_URI", "NEO4J_USER", "NEO4J_PASSWORD", "MONGO_URI", "MONGO_DB_NAME", "MONGO_DB"}


def _load_env() -> None:
    env_file = REPO_ROOT / ".env"
    if not env_file.is_file():
        return
    for line in env_file.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if key in _ENV_KEYS and not os.getenv(key):
            os.environ[key] = value.strip().strip('"').strip("'")


def _neo4j_driver():
    from neo4j import GraphDatabase

    uri = os.getenv("NEO4J_URI", "").strip() or "bolt://127.0.0.1:7687"
    driver = GraphDatabase.driver(
        uri,
        auth=(os.getenv("NEO4J_USER", "neo4j"), os.getenv("NEO4J_PASSWORD", "")),
        connection_timeout=3.0,
    )
    driver.verify_connectivity()
    return driver


def _mongo_db():
    from pymongo import MongoClient

    name = os.getenv("MONGO_DB_NAME") or os.getenv("MONGO_DB") or "skinsyntax"
    return MongoClient(os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017"), serverSelectionTimeoutMS=5000)[name]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--out", default=str(DEFAULT_OUT), help="output JSON path")
    parser.add_argument("--source", choices=("auto", "yaml"), default="auto")
    parser.add_argument("--dry-run", action="store_true", help="print counts, do not write the file")
    args = parser.parse_args(argv)

    from search_service.dictionary import EntityDictionary
    from search_service.dictionary_export import build_export, write_export

    if args.source == "auto":
        _load_env()
        dictionary = EntityDictionary(driver_provider=_neo4j_driver, mongo_provider=_mongo_db)
    else:
        dictionary = EntityDictionary()

    payload = build_export(dictionary.reload())
    summary = {
        "source": payload["source"],
        "brands": len(payload["brands"]),
        "categories": len(payload["categories"]),
        "aliases": sum(len(c["aliases"]) for c in payload["categories"]),
    }
    if args.dry_run:
        print(json.dumps(summary))
        return 0
    path = write_export(payload, args.out)
    print(json.dumps({**summary, "out": str(path)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
