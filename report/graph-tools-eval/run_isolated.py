"""Run the U10 graph-tool eval one case at a time (read-only: Neo4j + Mongo reads, no LLM).

attempt 1 = production config (600 ms deadline), after the same warm_up() gunicorn runs at worker start.
If attempt 1 hits a tool error, wait for the 10 s breaker to close, then attempt 2 measures correctness.
"""
import io
import json
import sys
import time
from contextlib import redirect_stdout

sys.path[:0] = ["/app", "/app/chatbot_service", "/app/scripts"]
from search_service.service import warm_up  # noqa: E402

t0 = time.perf_counter()
warm_up()
print(f"warm_up {time.perf_counter() - t0:.1f}s", flush=True)

import yaml  # noqa: E402
import eval_graph_tools as e  # noqa: E402

cases = yaml.safe_load(e.CASES_PATH.read_text(encoding="utf-8"))["cases"]
rows = []
for case in cases:
    attempts = []
    for attempt in (1, 2):
        buf = io.StringIO()
        with redirect_stdout(buf):
            s = e.run_tool_layer([case], 95.0)
        r = s["rows"][0]
        tool_error = any("tool error" in f for f in r["failures"])
        attempts.append({"attempt": attempt, "ok": r["ok"], "checks": r["checks"], "passed": r["passed"],
                         "count": r["count"], "tool_ms": r["tool_ms"], "tool_error": tool_error,
                         "failures": r["failures"]})
        if not tool_error:
            break
        time.sleep(11)  # let the 10 s breaker close so the next run is independent
    first, last = attempts[0], attempts[-1]
    rows.append({"id": case["id"], "tool": (case.get("route") or {}).get("tool"), "attempts": attempts})
    print(f"{case['id']:<52} a1={'PASS' if first['ok'] else ('TIMEOUT' if first['tool_error'] else 'FAIL')}"
          f" {first['passed']}/{first['checks']} ms={first['tool_ms']}"
          + (f" | a2={'PASS' if last['ok'] else 'FAIL'} {last['passed']}/{last['checks']} ms={last['tool_ms']}"
             if len(attempts) > 1 else ""), flush=True)
    for f in last["failures"]:
        print("     -", f, flush=True)


def agg(pick):
    chosen = [pick(r["attempts"]) for r in rows]
    return {"cases_pass": sum(a["ok"] for a in chosen), "cases": len(chosen),
            "checks_pass": sum(a["passed"] for a in chosen), "checks": sum(a["checks"] for a in chosen),
            "tool_errors": sum(a["tool_error"] for a in chosen)}


summary = {"first_attempt": agg(lambda a: a[0]), "final": agg(lambda a: a[-1]),
           "first_attempt_tool_ms": sorted(a[0]["tool_ms"] for a in (r["attempts"] for r in rows) if a[0]["tool_ms"] is not None),
           "rows": rows}
print(json.dumps({k: v for k, v in summary.items() if k != "rows"}, ensure_ascii=False))
with open("/tmp/graph-eval-isolated.json", "w", encoding="utf-8") as fh:
    json.dump(summary, fh, ensure_ascii=False, indent=2)
