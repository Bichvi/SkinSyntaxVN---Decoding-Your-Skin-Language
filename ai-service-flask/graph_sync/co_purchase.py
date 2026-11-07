"""Derive ``(:Product)-[:CO_PURCHASED {count, updated_at}]->(:Product)`` from orders (Bước 08).

python -m graph_sync --derive co_purchase --days 90 [--dry-run]

Mongo is read-only: ``hoa_don`` (``ngay_dat`` within ``days``, completed status) joined with
``chi_tiet_hoa_don{ma_hoa_don, ma_san_pham}``. A product counts once per order; orders with more
than ``max_items`` distinct products are skipped (bulk/fixture baskets say nothing about taste).
Pairs with ``count >= min_count`` are kept; every product keeps its top ``top_k`` partners, and an
edge is written in each direction the partner made the top-k (so it is normally two-way).
All old CO_PURCHASED edges are deleted first, then edges are written in batches of 500.
"""

from __future__ import annotations

import itertools
import logging
from collections import Counter, defaultdict
from datetime import datetime, timedelta
from typing import Any, Iterable, Sequence

from .extract import with_retry

log = logging.getLogger("graph_sync.co_purchase")

# "Successful" orders = completed (accent and ASCII variants seen in Mongo, Bước 08 probe).
SUCCESS_STATUSES = ("Hoàn thành", "Hoan thanh")
MAX_ITEMS = 20
MIN_COUNT = 2
TOP_K = 20
ORDER_ID_CHUNK = 5000

DELETE_ALL = "MATCH (:Product)-[r:CO_PURCHASED]->(:Product) DELETE r"
UPSERT = """
UNWIND $edges AS e
MATCH (a:Product {id: e.source}), (b:Product {id: e.target})
MERGE (a)-[r:CO_PURCHASED]->(b)
SET r.count = e.count, r.updated_at = $updated_at
"""
COUNT_EDGES = "MATCH (:Product)-[r:CO_PURCHASED]->(:Product) RETURN count(r) AS n"


def count_pairs(orders: Iterable[Iterable[Any]], max_items: int = MAX_ITEMS) -> tuple[Counter, dict[str, int]]:
    """Count unordered product pairs per order. Returns ``(pairs, stats)``; pair keys are sorted tuples."""

    pairs: Counter = Counter()
    stats = {"orders": 0, "orders_used": 0, "orders_skipped_large": 0, "orders_single": 0}
    for items in orders:
        stats["orders"] += 1
        products = sorted({str(p).strip() for p in items if p is not None and str(p).strip()})
        if len(products) > max_items:
            stats["orders_skipped_large"] += 1
            continue
        if len(products) < 2:
            stats["orders_single"] += 1
            continue
        stats["orders_used"] += 1
        for a, b in itertools.combinations(products, 2):
            pairs[(a, b)] += 1
    return pairs, stats


def top_edges(pairs: Counter, min_count: int = MIN_COUNT, top_k: int = TOP_K) -> list[dict[str, Any]]:
    """Directed edges: for each product, its ``top_k`` partners by count (ties: smaller id first)."""

    partners: dict[str, list[tuple[int, str]]] = defaultdict(list)
    for (a, b), n in pairs.items():
        if n < min_count:
            continue
        partners[a].append((n, b))
        partners[b].append((n, a))
    edges: list[dict[str, Any]] = []
    for source in sorted(partners):
        ranked = sorted(partners[source], key=lambda item: (-item[0], item[1]))[:top_k]
        edges.extend({"source": source, "target": target, "count": int(n)} for n, target in ranked)
    return edges


def extract_orders(db: Any, days: int, now: datetime | None = None,
                   statuses: Sequence[str] = SUCCESS_STATUSES) -> list[list[str]]:
    """Read-only: product ids per successful order placed in the last ``days`` days."""

    since = (now or datetime.now()) - timedelta(days=days)

    def _orders() -> list[Any]:
        return [d["ma_hoa_don"] for d in db["hoa_don"].find(
            {"ngay_dat": {"$gte": since}, "trang_thai": {"$in": list(statuses)}},
            {"_id": 0, "ma_hoa_don": 1},
        ) if d.get("ma_hoa_don") is not None]

    order_ids = with_retry(_orders, "mongo orders")
    baskets: dict[Any, list[str]] = defaultdict(list)
    for start in range(0, len(order_ids), ORDER_ID_CHUNK):
        chunk = order_ids[start:start + ORDER_ID_CHUNK]

        def _lines(chunk: list[Any] = chunk) -> list[dict[str, Any]]:
            return list(db["chi_tiet_hoa_don"].find(
                {"ma_hoa_don": {"$in": chunk}}, {"_id": 0, "ma_hoa_don": 1, "ma_san_pham": 1}))

        for line in with_retry(_lines, "mongo order lines"):
            if line.get("ma_san_pham") is not None:
                baskets[line["ma_hoa_don"]].append(str(line["ma_san_pham"]))
    return list(baskets.values())


def write_edges(driver: Any, edges: Sequence[dict[str, Any]], batch_size: int = 500,
                now: datetime | None = None, database: str | None = None) -> int:
    """Delete every CO_PURCHASED edge, then MERGE ``edges`` in batches. Returns edges in Neo4j after."""

    updated_at = (now or datetime.utcnow()).replace(microsecond=0).isoformat() + "Z"

    def _run(cypher: str, **params: Any) -> list[dict[str, Any]]:
        def work(tx: Any) -> list[dict[str, Any]]:
            return [dict(r) for r in tx.run(cypher, **params)]

        with driver.session(database=database) as session:
            return session.execute_write(work)

    _run(DELETE_ALL)
    for start in range(0, len(edges), batch_size):
        _run(UPSERT, edges=list(edges[start:start + batch_size]), updated_at=updated_at)
    rows = _run(COUNT_EDGES)
    return int(rows[0]["n"]) if rows else 0
