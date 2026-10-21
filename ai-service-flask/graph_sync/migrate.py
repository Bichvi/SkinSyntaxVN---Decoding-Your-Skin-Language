"""Apply migrations/neo4j/*.cypher in filename order.

Usage: python -m graph_sync.migrate [--dir PATH]
Env: NEO4J_URI (default bolt://localhost:7687), NEO4J_USER (neo4j), NEO4J_PASSWORD.
Migrations must be idempotent (IF NOT EXISTS); they are re-run every time.
"""
from __future__ import annotations

import argparse
import logging
import os
import re
import sys
from pathlib import Path

from neo4j import GraphDatabase

log = logging.getLogger("graph_sync.migrate")


def _default_dir() -> Path:
    env_dir = os.getenv("NEO4J_MIGRATIONS_DIR")
    if env_dir:
        return Path(env_dir)
    here = Path(__file__).resolve()
    for parent in here.parents:
        candidate = parent / "migrations" / "neo4j"
        if candidate.is_dir():
            return candidate
    return here.parents[2] / "migrations" / "neo4j"


def split_statements(text: str) -> list[str]:
    """Strip // line comments and split on ';' (migrations contain no string literals with ';')."""
    lines = [re.sub(r"^\s*//.*$", "", line) for line in text.splitlines()]
    return [s.strip() for s in "\n".join(lines).split(";") if s.strip()]


def run(migrations_dir: Path) -> int:
    files = sorted(migrations_dir.glob("*.cypher"))
    if not files:
        log.error("no migration files in %s", migrations_dir)
        return 1
    uri = os.getenv("NEO4J_URI", "bolt://localhost:7687")
    user = os.getenv("NEO4J_USER", "neo4j")
    password = os.getenv("NEO4J_PASSWORD", "")
    if not password:
        log.error("NEO4J_PASSWORD is not set")
        return 1
    with GraphDatabase.driver(uri, auth=(user, password)) as driver:
        driver.verify_connectivity()
        with driver.session() as session:
            for path in files:
                statements = split_statements(path.read_text(encoding="utf-8"))
                log.info("applying %s (%d statements)", path.name, len(statements))
                for stmt in statements:
                    session.run(stmt).consume()
    log.info("done: %d file(s) applied to %s", len(files), uri)
    return 0


def main(argv: list[str] | None = None) -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dir", type=Path, default=None)
    args = parser.parse_args(argv)
    try:
        return run(args.dir or _default_dir())
    except Exception as exc:  # noqa: BLE001 - CLI boundary
        log.error("migration failed: %s", exc.__class__.__name__ + ": " + str(exc))
        return 1


if __name__ == "__main__":
    sys.exit(main())
