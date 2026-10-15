"""Bảng kết quả case và báo cáo verify (.md + .json)."""
from __future__ import annotations

import json
from collections import Counter
from pathlib import Path

from .cases import verdict


def summarize(results: list[dict]) -> dict:
    counts = Counter(verdict(r) for r in results)
    return {"total": len(results), "pass": counts["PASS"], "fail": counts["FAIL"], "known_gap": counts["KNOWN_GAP"]}


def render_table(results: list[dict]) -> str:
    rows = ["| Case | Trạng thái | Chọn/Khớp | Đủ dữ liệu | Kết quả |", "|---|---|---|---|---|"]
    for r in results:
        note = "" if not r["failures"] else " — " + ("; ".join(r["failures"])[:140])
        rows.append(f"| {r['case']} | {r['status']} | {r['selected']}/{r['matched']} | {'có' if r['complete'] else 'không'} | {verdict(r)}{note} |")
    return "\n".join(rows)


def render_report(results: list[dict], as_of: str, counts: dict | None = None) -> str:
    s = summarize(results)
    lines = [f"# Báo cáo pb-fixture-v1 — as_of {as_of}", "",
             f"Tổng {s['total']} case: PASS {s['pass']}, FAIL {s['fail']}, KNOWN_GAP {s['known_gap']}.", ""]
    if counts:
        lines += ["## Số document", "", *[f"- {name}: {n}" for name, n in counts.items()], ""]
    gaps = {r["case"]: r["known_gap"] for r in results if r["known_gap"] and r["failures"]}
    if gaps:
        lines += ["## Giới hạn của engine (không sửa trong bộ dữ liệu)", "", *[f"- {name}: {reason}" for name, reason in gaps.items()], ""]
    lines += ["## Chi tiết", "", render_table(results), ""]
    return "\n".join(lines)


def write_report(results: list[dict], out_dir: str | Path, as_of: str, counts: dict | None = None) -> tuple[Path, Path]:
    out = Path(out_dir)
    out.mkdir(parents=True, exist_ok=True)
    md, js = out / f"report-{as_of}.md", out / f"report-{as_of}.json"
    md.write_text(render_report(results, as_of, counts), encoding="utf-8")
    js.write_text(json.dumps({"as_of": as_of, "summary": summarize(results), "results": results}, ensure_ascii=False, indent=2, default=str),
                  encoding="utf-8")
    return md, js
