#!/usr/bin/env python
"""U10 evaluation of the Syna graph tools.

Tool layer (no LLM): for every case in ``tests/chatbot_graph/eval_cases.yaml`` build the plan, run the tool through the
production wiring (LazyGraph + Mongo facts), then verify the result with Cypher/Mongo queries written here (independent
of the tool's own query). Prints constraint-level and case-level pass rates; exit code 1 below ``--target``.

    docker exec -w /app skinsyntax-ai python scripts/eval_graph_tools.py [--out report.json]
    docker exec -w /app skinsyntax-ai python scripts/eval_graph_tools.py --with-llm   # + 5 real /api/chat questions (costs tokens)

The container is the intended place: it has NEO4J_* / MONGO_* and the warmed search_service.
"""
from __future__ import annotations

import argparse
import json
import os
import statistics
import sys
import time
import urllib.request
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
for path in (ROOT, ROOT / "chatbot_service"):
    if str(path) not in sys.path:
        sys.path.insert(0, str(path))

import yaml  # noqa: E402

CASES_PATH = ROOT / "tests" / "chatbot_graph" / "eval_cases.yaml"
# gpt-4o-mini list prices (USD per 1M tokens); tokens are estimated from characters (Vietnamese ~3 chars/token).
PRICE_IN, PRICE_OUT, CHARS_PER_TOKEN = 0.15, 0.60, 3.0


class Verifier:
    """Independent checks against Neo4j and Mongo (the tool's own Cypher is not reused)."""

    def __init__(self) -> None:
        from neo4j import GraphDatabase

        from graph_sync.conflicts import load_rule_set
        from search_service import config
        from search_service.catalog import get_mongo_db

        self.driver = GraphDatabase.driver(config.NEO4J_URI, auth=(config.NEO4J_USER, config.NEO4J_PASSWORD))
        self.rules = load_rule_set()
        self.mongo = get_mongo_db()

    def close(self) -> None:
        self.driver.close()

    def rows(self, cypher: str, **params: Any) -> list[dict[str, Any]]:
        with self.driver.session() as session:
            return [dict(r) for r in session.run(cypher, **params)]

    # -- per product -------------------------------------------------------------------------------------------
    def in_category(self, pid: str, category: str) -> bool:
        return bool(self.rows(
            "MATCH (p:Product {id:$id})-[:IN_CATEGORY]->(c:Category) "
            "MATCH path = (c)-[:CHILD_OF*0..3]->(t:Category {key:$cat}) RETURN count(path) AS n", id=pid, cat=category)[0]["n"])

    def skin_types(self, pid: str) -> set[str]:
        return {r["k"] for r in self.rows(
            "MATCH (:Product {id:$id})-[:SUITABLE_FOR]->(s:SkinType) RETURN s.key AS k", id=pid)}

    def ingredient_keys(self, pid: str) -> dict[str, int]:
        return {r["k"]: r["rank"] for r in self.rows(
            "MATCH (:Product {id:$id})-[c:CONTAINS]->(i:Ingredient) RETURN i.key AS k, c.rank AS rank", id=pid)}

    def brand(self, pid: str) -> str | None:
        rows = self.rows("MATCH (:Product {id:$id})-[:OF_BRAND]->(b:Brand) RETURN b.key AS k", id=pid)
        return rows[0]["k"] if rows else None

    def conflicting_with(self, key: str) -> set[str]:
        """Ingredient keys joined to ``key`` by a medium/high CONFLICTS_WITH edge (either direction)."""

        return {r["k"] for r in self.rows(
            "MATCH (:Ingredient {key:$key})-[c:CONFLICTS_WITH]-(o:Ingredient) "
            "WHERE c.severity IN ['medium','high'] RETURN DISTINCT o.key AS k", key=key)}

    def price(self, pid: str) -> int | None:
        if self.mongo is None:
            return None
        variants: list[Any] = [pid] + ([int(pid)] if pid.isdigit() else [])
        doc = self.mongo["san_pham"].find_one({"ma_san_pham": {"$in": variants}}, {"_id": 0, "gia_ban": 1})
        return int(doc["gia_ban"]) if doc and doc.get("gia_ban") is not None else None

    def similar_edge(self, a: str, b: str) -> bool:
        return bool(self.rows("MATCH (:Product {id:$a})-[s:SIMILAR_TO]-(:Product {id:$b}) RETURN count(s) AS n", a=a, b=b)[0]["n"])

    def truth_count(self, brand: str, category: str, exclude_keys: list[str]) -> int:
        return self.rows(
            "MATCH (p:Product {active:true})-[:OF_BRAND]->(:Brand {key:$brand}) "
            "MATCH (p)-[:IN_CATEGORY]->(c:Category) MATCH path = (c)-[:CHILD_OF*0..3]->(:Category {key:$cat}) "
            "WITH DISTINCT p WHERE p.in_stock <> false "
            "AND NOT exists { (p)-[:CONTAINS]->(i:Ingredient) WHERE i.key IN $ex } "
            "AND (size($ex) = 0 OR size([(p)-[:CONTAINS]->(x:Ingredient) | x]) >= 3) "
            "RETURN count(p) AS n", brand=brand, cat=category, ex=exclude_keys)[0]["n"]

    def explain_truth(self, pid: str, keys: list[str]) -> bool:
        return bool(self.rows("MATCH (:Product {id:$id})-[:CONTAINS]->(i:Ingredient) WHERE i.key IN $keys RETURN count(i) AS n",
                              id=pid, keys=keys)[0]["n"])


def group_keys(name: str) -> list[str]:
    from chatbot_service import graph_tools as gt

    groups = gt.load_groups()
    if name in groups.exclusion:
        return list(groups.exclusion[name].keys)
    trigger = groups.group_for_trigger(name)
    return list(trigger.keys) if trigger else [name]


def subset(expected: Any, actual: Any) -> bool:
    """Dict/list subset comparison for the ``route.args`` expectation."""

    if isinstance(expected, dict):
        return isinstance(actual, dict) and all(k in actual and subset(v, actual[k]) for k, v in expected.items())
    if isinstance(expected, list):
        return isinstance(actual, list) and all(item in actual for item in expected)
    return expected == actual


class Scoreboard:
    def __init__(self) -> None:
        self.checks = 0
        self.passed = 0
        self.failures: list[str] = []

    def check(self, ok: bool, label: str) -> None:
        self.checks += 1
        self.passed += bool(ok)
        if not ok:
            self.failures.append(label)


def verify_case(case: dict[str, Any], plan: Any, result: dict[str, Any], v: Verifier, board: Scoreboard,
                tag: str) -> None:
    expect = case.get("expect") or {}
    items = result.get("products") or result.get("items") or result.get("pairs") or []
    count = len(items)
    if "min_count" in expect:
        board.check(count >= expect["min_count"], f"{tag}: count {count} < {expect['min_count']}")
    if "max_count" in expect:
        board.check(count <= expect["max_count"], f"{tag}: count {count} > {expect['max_count']}")
    products = result.get("products") or []
    for item in products:
        pid = str(item["id"])
        label = f"{tag}: product {pid}"
        if "category" in expect:
            board.check(v.in_category(pid, expect["category"]), f"{label} not in category {expect['category']}")
        if "skin" in expect:
            skins = v.skin_types(pid)
            exact = expect["skin"] in skins
            universal_ok = item.get("skin") == "universal" and "moi_loai_da" in skins
            board.check(exact or universal_ok, f"{label} not SUITABLE_FOR {expect['skin']}")
        if expect.get("excludes"):
            have = v.ingredient_keys(pid)
            for name in expect["excludes"]:
                keys = set(group_keys(name))
                board.check(not (keys & set(have)) and len(have) >= 3, f"{label} contains/unreadable for {name}")
        if expect.get("contains_any"):
            board.check(bool(set(expect["contains_any"]) & set(v.ingredient_keys(pid))), f"{label} lacks required ingredient")
        if expect.get("no_conflict_with"):
            have = v.ingredient_keys(pid)
            for key in expect["no_conflict_with"]:
                bad = {k for k in v.conflicting_with(key) & set(have) if have[k] <= v.rules.rank_limit(k)}
                board.check(not bad, f"{label} conflicts with {key} through {sorted(bad)}")
        if "brand" in expect:
            board.check(v.brand(pid) == expect["brand"], f"{label} brand != {expect['brand']}")
        if "price_max" in expect or "price_min" in expect:
            price = v.price(pid)
            lo, hi = expect.get("price_min", 0), expect.get("price_max", 10**12)
            board.check(price is not None and lo <= price <= hi, f"{label} price {price} outside [{lo},{hi}]")
            board.check(price == item.get("price"), f"{label} shown price {item.get('price')} != Mongo {price}")
    if "truth_exists" in expect:
        t = expect["truth_exists"]
        ex = [k for name in t.get("excludes", []) for k in group_keys(name)]
        truth = v.truth_count(t["brand"], t["category"], ex)
        board.check((truth > 0) == (count > 0), f"{tag}: result count {count} but graph has {truth} matching in-stock products")
    if result.get("tool") == "check_conflicts":
        pairs = result.get("pairs") or []
        if "severity" in expect:
            board.check(any(p["severity"] == expect["severity"] for p in pairs), f"{tag}: no {expect['severity']} pair")
        if "timing" in expect:
            board.check(any(p["timing"] == expect["timing"] for p in pairs), f"{tag}: no {expect['timing']} timing")
        for fragment in expect.get("advice_contains", []):
            board.check(any(fragment in p.get("advice", "") for p in pairs), f"{tag}: advice lacks '{fragment}'")
        if "consult_expert" in expect:
            board.check(bool(result.get("consult_expert")) == expect["consult_expert"], f"{tag}: consult_expert mismatch")
        # agreement with the graph: every ingredient pair the rules call conflicting must exist as CONFLICTS_WITH
        keys = case["route"]["args"]["ingredient_keys"]
        edges = v.rows("MATCH (a:Ingredient)-[c:CONFLICTS_WITH]-(b:Ingredient) WHERE a.key IN $k AND b.key IN $k AND a.key < b.key "
                       "RETURN count(c) AS n", k=keys)[0]["n"]
        board.check((edges > 0) == bool(pairs), f"{tag}: rules say {len(pairs)} pair(s) but graph has {edges} edge(s)")
    if "probe" in expect:
        pid = str(case["route"]["args"]["product_id"])
        for name, source in expect["probe"].items():
            truth = v.explain_truth(pid, group_keys(name))
            shown = next((p["present"] for p in result.get("probe", []) if p.get("readable")), None)
            board.check(shown is not None and shown == truth, f"{tag}: probe {name} shown {shown}, graph says {truth}")
    if expect.get("skin_types_match_graph"):
        pid = str(case["route"]["args"]["product_id"])
        names = {s["name"] for s in result.get("skin_types", [])}
        board.check(len(names) == len(v.skin_types(pid)), f"{tag}: skin types shown {names} != graph {v.skin_types(pid)}")
    if expect.get("similar_edges"):
        source = str(case["route"]["args"]["product_id"])
        for item in result.get("items", []):
            board.check(v.similar_edge(source, str(item["id"])) or result.get("degraded"),
                        f"{tag}: {item['id']} has no SIMILAR_TO edge with {source}")


def run_tool_layer(cases: list[dict[str, Any]], target: float) -> dict[str, Any]:
    from chatbot_service import graph_router as gr

    runtime = gr.get_runtime()
    if runtime is None:
        sys.exit("graph runtime unavailable (Neo4j disabled or down): run inside the ai container")
    verifier = Verifier()
    constraint_board, case_pass, rows, latencies = Scoreboard(), 0, [], []
    try:
        for case in cases:
            tag = case["id"]
            board = Scoreboard()
            started = time.perf_counter()
            ctx = runtime.context(case.get("current"))
            plan = gr.build_plan(case["q"], ctx)
            route = case.get("route")
            result: dict[str, Any] = {}
            error = ""
            if route is None:
                board.check(plan is None, f"{tag}: should not be routed but got {plan and plan.tool}")
            else:
                board.check(plan is not None and plan.tool == route["tool"], f"{tag}: routed to {plan and plan.tool}, expected {route['tool']}")
                board.check(plan is not None and subset(route.get("args", {}), plan.args), f"{tag}: plan args {plan and plan.args}")
                if plan is not None and plan.tool == route["tool"]:
                    try:
                        result = runtime.tools.call(plan.tool, plan.args)
                        latencies.append((time.perf_counter() - started) * 1000)
                        verify_case(case, plan, result, verifier, board, tag)
                    except Exception as exc:  # a failing tool is a failed case, not a crashed run
                        error = f"{type(exc).__name__}: {exc}"
                        board.check(False, f"{tag}: tool error {error}")
            ok = board.passed == board.checks
            case_pass += ok
            constraint_board.checks += board.checks
            constraint_board.passed += board.passed
            constraint_board.failures += board.failures
            rows.append({"id": tag, "ok": ok, "checks": board.checks, "passed": board.passed,
                         "count": len(result.get("products") or result.get("items") or result.get("pairs") or []),
                         "tool_ms": round(latencies[-1]) if latencies and route else None, "failures": board.failures})
            print(f"[{'PASS' if ok else 'FAIL'}] {tag:<48} checks {board.passed}/{board.checks}"
                  f"  n={rows[-1]['count']}" + (f"  {error}" if error else ""))
            for failure in board.failures:
                print(f"        - {failure}")
    finally:
        verifier.close()
    pct = 100.0 * constraint_board.passed / max(constraint_board.checks, 1)
    summary = {
        "cases": len(cases), "cases_passed": case_pass, "case_pass_pct": round(100.0 * case_pass / len(cases), 1),
        "checks": constraint_board.checks, "checks_passed": constraint_board.passed, "constraint_pass_pct": round(pct, 2),
        "tool_ms_p50": round(statistics.median(latencies)) if latencies else None,
        "tool_ms_p95": round(sorted(latencies)[max(0, int(len(latencies) * 0.95) - 1)]) if latencies else None,
        "tool_ms_max": round(max(latencies)) if latencies else None, "target_pct": target, "rows": rows,
    }
    print(f"\nconstraints: {constraint_board.passed}/{constraint_board.checks} = {pct:.2f}%   "
          f"cases: {case_pass}/{len(cases)}   tool+facts ms p50/p95/max: {summary['tool_ms_p50']}/{summary['tool_ms_p95']}/{summary['tool_ms_max']}")
    return summary


def run_with_llm(cases: list[dict[str, Any]], chat_url: str) -> list[dict[str, Any]]:
    """Five real questions through the chat endpoint. Costs tokens - keep it at 5."""

    picked = [c for c in cases if c.get("e2e")][:5]
    out = []
    total_in = total_out = 0.0
    for case in picked:
        body: dict[str, Any] = {"customer_question": case["q"]}
        if case.get("current"):
            body["current_product_id"] = case["current"]
        payload = json.dumps({"message": json.dumps(body, ensure_ascii=False)}, ensure_ascii=False).encode("utf-8")
        request = urllib.request.Request(chat_url, data=payload, headers={"Content-Type": "application/json; charset=utf-8"})
        started = time.perf_counter()
        with urllib.request.urlopen(request, timeout=90) as response:
            data = json.loads(response.read().decode("utf-8"))
        took = time.perf_counter() - started
        tool = data.get("graph_tool") or {}
        tin, tout = tool.get("prompt_chars", 0) / CHARS_PER_TOKEN, tool.get("answer_chars", 0) / CHARS_PER_TOKEN
        total_in, total_out = total_in + tin, total_out + tout
        out.append({"id": case["id"], "q": case["q"], "pipeline_mode": data.get("pipeline_mode"), "tool": tool.get("name"),
                    "answer_source": tool.get("answer_source"), "products": [p.get("id") for p in data.get("products", [])],
                    "seconds": round(took, 2), "est_tokens_in": round(tin), "est_tokens_out": round(tout),
                    "answer": (data.get("answer") or "")[:600]})
        print(f"\n[{case['id']}] {out[-1]['pipeline_mode']} tool={out[-1]['tool']} via={out[-1]['answer_source']} "
              f"{out[-1]['seconds']}s\n{out[-1]['answer']}")
    cost = total_in / 1e6 * PRICE_IN + total_out / 1e6 * PRICE_OUT
    print(f"\nestimated LLM usage for {len(out)} questions: ~{round(total_in)} in / ~{round(total_out)} out tokens ~ ${cost:.4f}")
    return out


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--cases", default=str(CASES_PATH))
    parser.add_argument("--target", type=float, default=95.0, help="required constraint pass rate in percent")
    parser.add_argument("--out", help="write the JSON report here")
    parser.add_argument("--with-llm", action="store_true", help="also send the 5 e2e questions through --chat-url (costs tokens)")
    parser.add_argument("--chat-url", default=os.getenv("EVAL_CHAT_URL", "http://127.0.0.1:5001/api/chat"))
    args = parser.parse_args()
    cases = yaml.safe_load(Path(args.cases).read_text(encoding="utf-8"))["cases"]
    summary = run_tool_layer(cases, args.target)
    if args.with_llm:
        summary["llm"] = run_with_llm(cases, args.chat_url)
    if args.out:
        Path(args.out).write_text(json.dumps(summary, ensure_ascii=False, indent=2), encoding="utf-8")
    return 0 if summary["constraint_pass_pct"] >= args.target else 1


if __name__ == "__main__":
    raise SystemExit(main())
