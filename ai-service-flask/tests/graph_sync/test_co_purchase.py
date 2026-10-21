from datetime import datetime

import pytest

from graph_sync import co_purchase
from graph_sync.__main__ import EXIT_USAGE, build_parser, main


def test_count_pairs_dedupes_products_within_order():
    pairs, stats = co_purchase.count_pairs([["1", "2", "2"], [2, 1], ["3"]])
    assert pairs == {("1", "2"): 2}
    assert stats == {"orders": 3, "orders_used": 2, "orders_skipped_large": 0, "orders_single": 1}


def test_count_pairs_skips_orders_over_max_items():
    big = [str(i) for i in range(21)]
    pairs, stats = co_purchase.count_pairs([big, ["a", "b"]], max_items=20)
    assert stats["orders_skipped_large"] == 1 and pairs == {("a", "b"): 1}
    exactly = [str(i) for i in range(20)]
    pairs20, stats20 = co_purchase.count_pairs([exactly], max_items=20)
    assert stats20["orders_used"] == 1 and len(pairs20) == 190


def test_top_edges_threshold_and_both_directions():
    pairs, _ = co_purchase.count_pairs([["1", "2"], ["1", "2"], ["1", "3"]])
    edges = co_purchase.top_edges(pairs, min_count=2)
    assert edges == [{"source": "1", "target": "2", "count": 2}, {"source": "2", "target": "1", "count": 2}]


def test_top_edges_keeps_top_k_per_product_by_count_then_id():
    orders = []
    for partner, n in (("b", 5), ("c", 3), ("d", 3), ("e", 2)):
        orders += [["a", partner]] * n
    pairs, _ = co_purchase.count_pairs(orders)
    edges = [e for e in co_purchase.top_edges(pairs, min_count=2, top_k=2) if e["source"] == "a"]
    assert [(e["target"], e["count"]) for e in edges] == [("b", 5), ("c", 3)]
    # The partner side still keeps its own top-k (a is b's only partner).
    assert {"source": "b", "target": "a", "count": 5} in co_purchase.top_edges(pairs, min_count=2, top_k=2)


class FakeCursorCollection:
    def __init__(self, docs):
        self.docs = docs
        self.queries = []

    def find(self, query, projection=None):
        self.queries.append(query)
        if "ma_hoa_don" in query and "$in" in query["ma_hoa_don"]:
            wanted = set(query["ma_hoa_don"]["$in"])
            return [d for d in self.docs if d["ma_hoa_don"] in wanted]
        return list(self.docs)


def test_extract_orders_reads_success_window_and_groups_lines():
    hoa_don = FakeCursorCollection([{"ma_hoa_don": 1}, {"ma_hoa_don": 2}])
    lines = FakeCursorCollection([
        {"ma_hoa_don": 1, "ma_san_pham": 10}, {"ma_hoa_don": 1, "ma_san_pham": 11},
        {"ma_hoa_don": 2, "ma_san_pham": 10}, {"ma_hoa_don": 3, "ma_san_pham": 99},
    ])
    out = co_purchase.extract_orders({"hoa_don": hoa_don, "chi_tiet_hoa_don": lines}, 90,
                                     now=datetime(2026, 10, 21))
    assert sorted(out) == [["10"], ["10", "11"]]
    query = hoa_don.queries[0]
    assert query["ngay_dat"]["$gte"] == datetime(2026, 7, 23)
    assert query["trang_thai"]["$in"] == list(co_purchase.SUCCESS_STATUSES)


class FakeTx:
    def __init__(self, log):
        self.log = log

    def run(self, cypher, **params):
        self.log.append((cypher, params))
        return [{"n": 4}] if "count(r)" in cypher else []


class FakeSession:
    def __init__(self, log):
        self.log = log

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False

    def execute_write(self, work):
        return work(FakeTx(self.log))


class FakeDriver:
    def __init__(self):
        self.log = []

    def session(self, database=None):
        return FakeSession(self.log)


def test_write_edges_deletes_first_then_batches_parameterised():
    driver = FakeDriver()
    edges = [{"source": str(i), "target": str(i + 1), "count": 2} for i in range(5)]
    n = co_purchase.write_edges(driver, edges, batch_size=2, now=datetime(2026, 10, 21, 1, 2, 3))
    cyphers = [c for c, _ in driver.log]
    assert cyphers[0] == co_purchase.DELETE_ALL
    upserts = [p for c, p in driver.log if c == co_purchase.UPSERT]
    assert [len(p["edges"]) for p in upserts] == [2, 2, 1]
    assert upserts[0]["updated_at"] == "2026-10-21T01:02:03Z"
    assert n == 4


def test_cli_derive_args():
    args = build_parser().parse_args(["--derive", "co_purchase", "--days", "30"])
    assert args.derive == "co_purchase" and args.days == 30
    with pytest.raises(SystemExit):
        build_parser().parse_args(["--derive", "co_purchase", "--full"])
    with pytest.raises(SystemExit):
        build_parser().parse_args(["--derive", "other"])
    assert main(["--derive", "co_purchase", "--days", "0", "--dry-run"]) == EXIT_USAGE
