"""Mongo -> Neo4j graph sync CLI.

python -m graph_sync --full                  # load all + deactivate missing + similarity
python -m graph_sync --since 2026-10-01T00:00:00Z [--no-similarity]
python -m graph_sync --dry-run               # no Neo4j writes, report only
python -m graph_sync --similarity-only
python -m graph_sync --conflicts-only [--dry-run]   # only (Ingredient)-[:CONFLICTS_WITH]->(Ingredient) from conflict_rules.yaml; no Mongo
python -m graph_sync --ids 1496,462 [--no-similarity] [--dry-run]   # U11: just these products (+ local SIMILAR_TO)
python -m graph_sync --derive co_purchase --days 90 [--dry-run]   # CO_PURCHASED from orders

Exit codes: 0 ok · 1 error · 2 bad arguments / --since unsupported · 3 another sync holds the lock.
Mongo is read-only. Logs never contain connection strings, passwords or personal data.
"""

from __future__ import annotations

import argparse
import logging
import sys
import time
from contextlib import contextmanager
from typing import Any, Iterator

from shared.redis_kit.client import make_client
from shared.redis_kit.lock import LockHeld, RedisLock

from . import extract
from .config import Settings, load_settings
from .ingredients import load_aliases
from .normalize import display_names
from .report import coverage, planned_counts, print_report
from .rules import conflict_pairs, helps_edges
from .similarity import compute_similar, features_from_row
from .transform import apply_brand_names, canonical_brand_names, to_graph_row

log = logging.getLogger("graph_sync")

EXIT_OK, EXIT_ERROR, EXIT_USAGE, EXIT_LOCKED = 0, 1, 2, 3


@contextmanager
def redis_lock(settings: Settings) -> Iterator[None]:
    """Single-flight lock ``graph_sync:lock`` (legacy key kept) via ``shared.redis_kit.RedisLock``.

    Held by another run -> ``LockHeld``; no Redis -> warn and continue without a lock (as before).
    """

    if not settings.redis_url:
        log.warning("REDIS_URL not set; continuing without lock")
        yield
        return
    client = make_client(settings.redis_url, socket_timeout=2.0, connect_timeout=2.0)
    with RedisLock(settings.lock_key, ttl_ms=settings.lock_ttl * 1000, client=client, fail_open=True):
        yield


def _transform(docs: list[dict[str, Any]], lookups: Any) -> list[dict[str, Any]]:
    rows = [row for row in (to_graph_row(doc, lookups) for doc in docs) if row is not None]
    return rows


def _categories(rows: list[dict[str, Any]]) -> tuple[list[dict[str, Any]], list[dict[str, str]]]:
    """Distinct categories (danh_muc names win over path-derived ones) and CHILD_OF edges."""

    cats: dict[str, dict[str, Any]] = {}
    for row in sorted(rows, key=lambda r: 0 if r["category_source"] == "danh_muc" else 1):
        for cat in row["categories"]:
            cats.setdefault(cat["key"], {"key": cat["key"], "name": cat["name"], "level": cat["level"]})
    edges = sorted({(e["child"], e["parent"]) for r in rows for e in r["category_edges"]})
    return sorted(cats.values(), key=lambda c: (c["level"], c["key"])), [{"child": c, "parent": p} for c, p in edges]


def _helps_rows() -> list[dict[str, str]]:
    names = display_names("concerns")
    return [{**h, "name": names.get(h["concern"], h["concern"])} for h in helps_edges()]


def _similar(rows: list[dict[str, Any]], settings: Settings) -> tuple[list[dict[str, Any]], list[str]]:
    active = [features_from_row(r) for r in rows if r["active"]]
    edges = compute_similar(active, top_k=settings.similar_top_k, min_score=settings.similar_min_score)
    return edges, [p.id for p in active]


def _driver(settings: Settings) -> Any:
    from neo4j import GraphDatabase

    if not settings.neo4j_password:
        raise RuntimeError("NEO4J_PASSWORD is not set")
    driver = GraphDatabase.driver(settings.neo4j_uri, auth=(settings.neo4j_user, settings.neo4j_password))
    driver.verify_connectivity()
    return driver


def run(args: argparse.Namespace, settings: Settings) -> int:
    timings: dict[str, float] = {}
    started = time.perf_counter()
    mode = "similarity-only" if args.similarity_only else ("since " + args.since if args.since else "full")
    title = mode + (" (dry-run)" if args.dry_run else "")

    since = None
    if args.since:
        try:
            since = extract.parse_since(args.since)
        except ValueError:
            log.error("--since must be ISO8601, e.g. 2026-10-01T00:00:00Z")
            return EXIT_USAGE

    t = time.perf_counter()
    db = extract.connect(settings.mongo_uri, settings.mongo_db)
    if since is not None:
        try:
            extract.ensure_since_supported(db)
        except extract.SinceUnsupported as exc:
            log.error("%s", exc)
            return EXIT_USAGE
    lookups = extract.load_lookups(db)
    docs = extract.extract_products(db, since=since, batch_size=settings.batch_size)
    timings["extract"] = time.perf_counter() - t
    log.info("extracted %d product documents", len(docs))

    t = time.perf_counter()
    rows = _transform(docs, lookups)
    full_catalog = since is None
    if full_catalog:
        apply_brand_names(rows, canonical_brand_names(rows))
    timings["transform"] = time.perf_counter() - t

    want_similarity = args.similarity_only or not args.no_similarity
    sim_rows = rows
    if want_similarity and not full_catalog:
        t = time.perf_counter()
        sim_rows = _transform(extract.extract_products(db, batch_size=settings.batch_size), lookups)
        timings["extract_for_similarity"] = time.perf_counter() - t
    edges: list[dict[str, Any]] = []
    source_ids: list[str] = []
    if want_similarity:
        t = time.perf_counter()
        edges, source_ids = _similar(sim_rows, settings)
        timings["similarity"] = time.perf_counter() - t

    pairs = conflict_pairs()
    helps = _helps_rows()
    cov = coverage(rows)
    extra: dict[str, Any] = {"similar_edges": len(edges) if want_similarity else "skipped",
                             "conflict_pairs": len(pairs)}

    if args.dry_run:
        counts = planned_counts(rows, helps, len(pairs), len(edges) if want_similarity else None)
        timings["total"] = time.perf_counter() - started
        print_report(title, cov, counts, timings, extra)
        return EXIT_OK

    driver = _driver(settings)
    try:
        from .load import GraphLoader

        loader = GraphLoader(driver, batch_size=settings.batch_size)
        if not args.similarity_only:
            t = time.perf_counter()
            cats, cat_edges = _categories(rows)
            loader.upsert_categories(cats, cat_edges, replace_edges=full_catalog)
            loaded = loader.upsert_all(rows, overwrite_names=full_catalog)
            extra["products_upserted"] = loaded
            if full_catalog:
                visible_ids = [r["id"] for r in rows if r["active"]]
                extra["deactivated_missing"] = loader.deactivate_missing(visible_ids)
            names = {e.key: e.inci for e in load_aliases().values()}
            loader.load_conflicts(pairs, names)
            loader.load_helps(helps)
            if full_catalog:
                extra["pruned_orphan_ingredients"] = loader.prune_orphan_ingredients()
            timings["load"] = time.perf_counter() - t
        if want_similarity:
            t = time.perf_counter()
            extra["similar_written"] = loader.write_similar(edges, source_ids)
            timings["write_similarity"] = time.perf_counter() - t
        counts = loader.stats()
    finally:
        driver.close()
    timings["total"] = time.perf_counter() - started
    print_report(title, None if args.similarity_only else cov, counts, timings, extra)
    return EXIT_OK


def run_ids(args: argparse.Namespace, settings: Settings) -> int:
    from .incremental import run_ids as _run_ids

    return _run_ids(args, settings)


def run_conflicts_only(args: argparse.Namespace, settings: Settings) -> int:
    """``--conflicts-only`` (U09): rewrite just the CONFLICTS_WITH edges. A small targeted write (~60 edges,
    no product or SIMILAR_TO traffic - the Neo4j container sits near its memory limit); idempotent; Mongo is not read."""

    from .conflicts import load_rule_set, validate_keys

    started = time.perf_counter()
    pairs = conflict_pairs()
    aliases = load_aliases()
    names = {e.key: e.inci for e in aliases.values()}
    unknown = validate_keys(load_rule_set(), aliases.keys())
    if unknown:
        log.warning("conflict rules use ingredient keys missing from ingredient_aliases.yaml: %s", sorted(unknown))
    by_severity: dict[str, int] = {}
    for pair in pairs:
        by_severity[pair.severity] = by_severity.get(pair.severity, 0) + 1
    summary: dict[str, Any] = {"rules": len(load_rule_set().rules), "conflict_pairs": len(pairs),
                               "by_severity": dict(sorted(by_severity.items()))}
    if not args.dry_run:
        driver = _driver(settings)
        try:
            from .load import GraphLoader

            loader = GraphLoader(driver, batch_size=settings.batch_size)
            loader.load_conflicts(pairs, names)
            summary["conflicts_in_neo4j"] = loader.count_conflicts()
        finally:
            driver.close()
    summary["seconds"] = round(time.perf_counter() - started, 2)
    print("== graph_sync conflicts-only" + (" (dry-run)" if args.dry_run else ""))
    for key, value in summary.items():
        print(f"  {key}: {value}")
    return EXIT_OK


def run_derive(args: argparse.Namespace, settings: Settings) -> int:
    """``--derive co_purchase``: orders (read-only) -> CO_PURCHASED edges (delete + rewrite)."""

    from . import co_purchase

    if args.days < 1 or args.days > 3650:
        log.error("--days must be between 1 and 3650")
        return EXIT_USAGE
    started = time.perf_counter()
    db = extract.connect(settings.mongo_uri, settings.mongo_db)
    baskets = co_purchase.extract_orders(db, args.days)
    pairs, stats = co_purchase.count_pairs(baskets)
    edges = co_purchase.top_edges(pairs)
    kept_pairs = sum(1 for n in pairs.values() if n >= co_purchase.MIN_COUNT)
    summary: dict[str, Any] = {**stats, "pairs_total": len(pairs), "pairs_count_ge_min": kept_pairs,
                               "edges_planned": len(edges),
                               "products_with_edges": len({e["source"] for e in edges})}
    if not args.dry_run:
        driver = _driver(settings)
        try:
            summary["edges_in_neo4j"] = co_purchase.write_edges(driver, edges, batch_size=settings.batch_size)
        finally:
            driver.close()
    summary["seconds"] = round(time.perf_counter() - started, 1)
    print(f"== graph_sync derive co_purchase --days {args.days}" + (" (dry-run)" if args.dry_run else ""))
    for key, value in summary.items():
        print(f"  {key}: {value}")
    return EXIT_OK


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="python -m graph_sync", description="MongoDB -> Neo4j graph sync")
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument("--full", action="store_true", help="load whole catalog, deactivate missing, similarity")
    mode.add_argument("--since", metavar="ISO8601", help="load products with updated_at >= since")
    mode.add_argument("--ids", metavar="ID,ID", help="sync only these ma_san_pham (event driven, local similarity)")
    mode.add_argument("--similarity-only", action="store_true", help="recompute SIMILAR_TO only")
    mode.add_argument("--derive", choices=["co_purchase"], help="derive relationships from orders")
    mode.add_argument("--conflicts-only", action="store_true",
                      help="rewrite only CONFLICTS_WITH from data/graph/conflict_rules.yaml (no Mongo, small write)")
    parser.add_argument("--days", type=int, default=90, help="order window for --derive (default 90)")
    parser.add_argument("--dry-run", action="store_true", help="no Neo4j writes; print report")
    parser.add_argument("--no-similarity", action="store_true", help="skip SIMILAR_TO (with --since/--full)")
    return parser


def main(argv: list[str] | None = None) -> int:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
    logging.getLogger("neo4j").setLevel(logging.WARNING)
    args = build_parser().parse_args(argv)
    if not (args.full or args.since or args.ids or args.similarity_only or args.derive or args.conflicts_only or args.dry_run):
        build_parser().print_usage(sys.stderr)
        return EXIT_USAGE
    settings = load_settings()
    job = run_derive if args.derive else (run_conflicts_only if args.conflicts_only else (run_ids if args.ids else run))
    try:
        if args.dry_run:
            return job(args, settings)
        with redis_lock(settings):
            return job(args, settings)
    except LockHeld:
        log.error("another graph sync holds the redis lock 'graph_sync:lock'; exiting")
        return EXIT_LOCKED
    except Exception as exc:  # noqa: BLE001 - CLI boundary, message only (no URIs)
        message = str(exc)[:300]
        if any(marker in message.lower() for marker in ("mongodb", "@", "bolt://", "neo4j://", "password")):
            message = "(details hidden: may contain connection info)"
        log.error("graph sync failed: %s: %s", type(exc).__name__, message)
        return EXIT_ERROR


if __name__ == "__main__":
    sys.exit(main())
