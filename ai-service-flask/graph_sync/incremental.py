"""Per-id incremental sync (U11): a handful of products changed -> Mongo -> Neo4j for just those ids.

Used by the ``catalog-worker`` (event driven, debounced) and by the CLI (``python -m graph_sync --ids 1,2,3``).

* Upsert the ids that exist (hidden ones too: ``Product.active=false`` is decided by ``transform``);
  ids that are gone from Mongo are deactivated.
* ``similarity="local"``: SIMILAR_TO is recomputed only for those ids + their direct neighbours (read from
  Neo4j BEFORE the upsert), so the write is a few dozen edges instead of ~28k. Edges from products that are
  not neighbours of a changed product keep their old values until the nightly ``--full`` (documented).
  ``similarity="none"`` skips it (price / stock only - they do not enter the similarity score).
* Mongo is read-only. Cypher is parameterised (see ``load.py``).
"""

from __future__ import annotations

import logging
import threading
import time
from typing import Any, Iterable

from . import extract
from .config import Settings
from .similarity import compute_similar, features_from_row
from .transform import Lookups, apply_brand_names, canonical_brand_names, to_graph_row

log = logging.getLogger("graph_sync.incremental")

SIMILARITY_MODES = ("local", "none")
MAX_IDS = 500

NEIGHBOURS = """
MATCH (p:Product)-[:SIMILAR_TO]-(n:Product)
WHERE p.id IN $ids
RETURN DISTINCT n.id AS id
"""


_db_cache: dict[str, Any] = {}
_db_lock = threading.Lock()


def shared_db(settings: Settings) -> Any:
    """One Mongo handle per worker process: a fresh ``MongoClient`` per event costs ~1s (Atlas SRV lookup + ping)
    and is the flakiest step (``ConfigurationError`` -> retries). ``reset_shared_db`` drops it after a failure."""

    with _db_lock:
        if "db" not in _db_cache:
            _db_cache["db"] = extract.connect(settings.mongo_uri, settings.mongo_db)
        return _db_cache["db"]


def reset_shared_db() -> None:
    with _db_lock:
        _db_cache.pop("db", None)


def normalize_ids(raw: Iterable[Any]) -> list[str]:
    """Distinct, trimmed, order-preserving ids (digits/letters/-/_ only, <= 40 chars)."""

    seen: dict[str, None] = {}
    for item in raw:
        text = str(item).strip()
        if text and len(text) <= 40 and all(ch.isalnum() or ch in "-_" for ch in text):
            seen.setdefault(text, None)
    return list(seen)[:MAX_IDS]


def id_variants(ids: Iterable[str]) -> list[Any]:
    """``ma_san_pham`` is Int64 in Atlas but older docs may hold strings: match both."""

    out: list[Any] = []
    for value in ids:
        out.append(value)
        if value.isdigit():
            out.append(int(value))
    return out


def fetch_docs(db: Any, ids: list[str], batch_size: int = 500) -> list[dict[str, Any]]:
    query = {"ma_san_pham": {"$in": id_variants(ids)}}
    return extract.with_retry(
        lambda: [dict(d) for d in db["san_pham"].find(query, extract.PRODUCT_PROJECTION).batch_size(batch_size)],
        "mongo extract ids",
    )


def local_similarity(all_rows: list[dict[str, Any]], focus_ids: Iterable[str], settings: Settings
                     ) -> tuple[list[dict[str, Any]], list[str]]:
    """Edges whose source is an active product in ``focus_ids`` + those source ids (pure)."""

    focus = {str(i) for i in focus_ids}
    active = [features_from_row(r) for r in all_rows if r["active"]]
    sources = sorted(p.id for p in active if p.id in focus)
    edges = compute_similar(active, top_k=settings.similar_top_k, min_score=settings.similar_min_score,
                            only_sources=sources)
    return edges, sources


def neighbour_ids(driver: Any, ids: list[str], database: str | None = None) -> list[str]:
    with driver.session(database=database) as session:
        return [str(r["id"]) for r in session.run(NEIGHBOURS, ids=ids)]


def sync_ids(ids: Iterable[Any], settings: Settings, *, similarity: str = "local", db: Any | None = None,
             driver: Any | None = None, lookups: Lookups | None = None) -> dict[str, Any]:
    """Sync ``ids`` into Neo4j. Returns a summary (counts only). ``db``/``driver`` injectable for tests."""

    from .__main__ import _categories, _driver, _transform

    if similarity not in SIMILARITY_MODES:
        raise ValueError(f"similarity must be one of {SIMILARITY_MODES}")
    wanted = normalize_ids(ids)
    summary: dict[str, Any] = {"requested": len(wanted), "found": 0, "deactivated_missing": 0,
                               "upserted": 0, "similarity": similarity, "similar_sources": 0,
                               "similar_written": 0}
    if not wanted:
        return summary
    started = time.perf_counter()
    own_driver = driver is None
    use_shared = db is None
    db = db if db is not None else shared_db(settings)
    try:
        lookups = lookups if lookups is not None else extract.load_lookups(db)
        docs = fetch_docs(db, wanted, settings.batch_size)
    except Exception:
        if use_shared:
            reset_shared_db()          # stale connection: reconnect on the retry
        raise
    rows = _transform(docs, lookups)
    found = {str(r["id"]) for r in rows}
    missing = [i for i in wanted if i not in found]
    summary["found"] = len(rows)

    driver = driver if driver is not None else _driver(settings)
    try:
        from .load import GraphLoader

        loader = GraphLoader(driver, batch_size=settings.batch_size)
        around: list[str] = []
        if similarity == "local":
            around = neighbour_ids(driver, wanted, loader.database)   # before the upsert / deactivation
        if rows:
            cats, cat_edges = _categories(rows)
            loader.upsert_categories(cats, cat_edges, replace_edges=False)
            summary["upserted"] = loader.upsert_all(rows, overwrite_names=False)
        if missing:
            summary["deactivated_missing"] = loader.deactivate_ids(missing)
        if similarity == "local":
            full_rows = _transform(extract.extract_products(db, batch_size=settings.batch_size), lookups)
            apply_brand_names(full_rows, canonical_brand_names(full_rows))
            edges, sources = local_similarity(full_rows, set(wanted) | set(around), settings)
            summary["similar_sources"] = len(sources)
            summary["similar_written"] = loader.write_similar(edges, sources)
    finally:
        if own_driver:
            driver.close()
    summary["seconds"] = round(time.perf_counter() - started, 2)
    log.info("sync_ids done: %s", summary)
    return summary


def run_ids(args: Any, settings: Settings) -> int:
    """CLI ``--ids``: prints the summary (counts only)."""

    ids = normalize_ids(str(args.ids).split(","))
    if not ids:
        log.error("--ids needs at least one valid id, e.g. --ids 1496,462")
        return 2
    mode = "none" if args.no_similarity else "local"
    if args.dry_run:
        print(f"== graph_sync --ids (dry-run): {len(ids)} id(s), similarity={mode}; nothing written")
        return 0
    summary = sync_ids(ids, settings, similarity=mode)
    print("== graph_sync --ids")
    for key, value in summary.items():
        print(f"  {key}: {value}")
    return 0
