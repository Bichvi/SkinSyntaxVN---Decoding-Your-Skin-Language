"""Shared fakes for the U10 tests (no Neo4j, Mongo or LLM)."""

from __future__ import annotations

from types import SimpleNamespace
from typing import Any

from chatbot_service import graph_tools as gt
from graph_sync.conflicts import load_rule_set
from search_service.dictionary import EntityDictionary

BRANDS = [{"key": "torriden", "name": "Torriden"}, {"key": "cerave", "name": "CeraVe"}]


class FakeDriver:
    def execute_query(self, query, *args, **kwargs):
        if ":Brand" in query:
            return BRANDS, None, ["key", "name"]
        return [], None, ["key", "name"]


_snapshot = None


def snapshot():
    global _snapshot
    if _snapshot is None:
        _snapshot = EntityDictionary(driver=FakeDriver(), mongo_db=None, ttl_seconds=600).snapshot()
    return _snapshot


def vocab() -> gt.Vocabulary:
    return gt.Vocabulary.from_snapshot(snapshot())


class FakeGraph:
    """Records every ``find_products`` / ``explain_product`` call and returns scripted rows."""

    def __init__(self, rows=None, rows_universal=None, explain=None, fail: Exception | None = None) -> None:
        self.rows, self.rows_universal, self.explain = rows or [], rows_universal, explain
        self.fail = fail
        self.calls: list[dict[str, Any]] = []

    def find_products(self, **kwargs):
        self.calls.append(kwargs)
        if self.fail:
            raise self.fail
        if kwargs.get("universal") and self.rows_universal is not None:
            return [dict(r) for r in self.rows_universal]
        return [dict(r) for r in self.rows]

    def explain_product(self, product_id, probe_keys=None, max_rank=15):
        self.calls.append({"explain": product_id, "probe": list(probe_keys or [])})
        if self.fail:
            raise self.fail
        return self.explain


def row(pid: str, name: str = "Serum Test", price: int = 200000, **extra: Any) -> dict[str, Any]:
    base = {"id": pid, "name": name, "price": price, "in_stock": True, "sold": 10, "skin_exact": True,
            "brand": "Hãng A", "category": "Serum / Tinh Chất", "include_hits": [], "concern_hits": [],
            "benefit_hits": [], "n_ingredients": 20}
    base.update(extra)
    return base


def make_tools(graph=None, facts=None, similar=None, product_conflicts=None) -> gt.GraphTools:
    deps = gt.ToolDeps(graph=graph or FakeGraph(), vocab=vocab(), groups=gt.load_groups(), rule_set=load_rule_set(),
                       facts=facts or (lambda ids: {}), similar=similar, product_conflicts=product_conflicts)
    return gt.GraphTools(deps)


class FakeCatalog:
    def __init__(self, products):
        self._products = products

    def products(self):
        return self._products

    def get(self, pid):
        return next((p for p in self._products if p["id"] == pid), None)


FakeLLM = SimpleNamespace
