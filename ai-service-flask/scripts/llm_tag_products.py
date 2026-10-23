"""LLM tag suggestions for products -> Mongo ``tag_suggestions`` (status pending). U07 part B.

    MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-ai python scripts/llm_tag_products.py \
        --limit 5 --only-missing --dry-run                    # call the LLM, print, write NOTHING
    ... --limit 30 --only-missing --sample random --seed 7 --out /tmp/pilot.csv     # pilot: pending suggestions
    ... --ids 1496,712 --dry-run                              # explicit products

Safety
- Mongo writes: ONLY ``tag_suggestions`` (+ its indexes), and only without ``--dry-run``. ``san_pham`` is read-only here.
- Suggestions are never approved by this script: a person approves them in ``index.php?r=admin_tag_review``.
- ``--limit N`` is required; running the whole catalogue needs ``--all --confirm-full`` (ask the owner first).
- The model is the project's existing pool (``chatbot_service.llm_pool.get_llms``); keys are never printed.
- Only product facts (name, category, first 30 ingredients, description <= 800 chars) are sent.
"""

from __future__ import annotations

import argparse
import csv
import logging
import sys
from pathlib import Path
from typing import Any

SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

log = logging.getLogger("llm_tag_products")

COLLECTION = "tag_suggestions"
HIDDEN = ["inactive", "hidden", "tam_an", "taman", "disabled", "off", "0"]
EXTRA_FIELDS = {"mo_ta": 1}
# gpt-4o-mini list price per 1M tokens (override with --price-in/--price-out); only used for the estimate.
DEFAULT_PRICE_IN, DEFAULT_PRICE_OUT = 0.15, 0.60


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    p.add_argument("--limit", type=int, help="how many products to label (required unless --all)")
    p.add_argument("--all", action="store_true", help="whole catalogue (needs --confirm-full)")
    p.add_argument("--confirm-full", action="store_true", help="owner approved a full-catalogue run")
    p.add_argument("--only-missing", action="store_true",
                   help="skip products with curated tags or an existing pending/approved suggestion")
    p.add_argument("--ids", help="comma-separated ma_san_pham list (overrides sampling)")
    p.add_argument("--sample", choices=("first", "random"), default="random")
    p.add_argument("--seed", type=int, default=7)
    p.add_argument("--dry-run", action="store_true", help="call the LLM and print; write nothing")
    p.add_argument("--rpm", type=float, default=30.0, help="max LLM calls per minute (0 = unlimited)")
    p.add_argument("--max-attempts", type=int, default=3)
    p.add_argument("--out", help="write the quality table as CSV")
    p.add_argument("--price-in", type=float, default=DEFAULT_PRICE_IN)
    p.add_argument("--price-out", type=float, default=DEFAULT_PRICE_OUT)
    args = p.parse_args(argv)
    if args.all and not args.confirm_full:
        p.error("--all needs --confirm-full (the full run must be approved by the owner)")
    if not args.all and not args.limit and not args.ids:
        p.error("--limit N (or --ids) is required")
    if args.limit is not None and args.limit < 1:
        p.error("--limit must be >= 1")
    return args


def _id_variants(raw: str) -> list[Any]:
    out: list[Any] = [raw]
    if raw.lstrip("-").isdigit():
        out.append(int(raw))
    return out


def select_candidates(db: Any, args: argparse.Namespace) -> list[Any]:
    """Visible products with an ingredient list; ``--only-missing`` also drops curated/suggested ones."""

    from graph_sync.extract import with_retry

    query: dict[str, Any] = {
        "trang_thai": {"$nin": HIDDEN},
        "$or": [{"thanh_phan_full": {"$nin": [None, ""]}}, {"thanh_phan_sach": {"$nin": [None, ""]}}],
    }
    if args.only_missing:
        query["tag_source"] = {"$nin": ["manual", "llm_approved"]}
    ids = [d["ma_san_pham"] for d in with_retry(
        lambda: list(db["san_pham"].find(query, {"_id": 0, "ma_san_pham": 1})), "mongo candidates")
        if d.get("ma_san_pham") not in (None, "")]
    if args.only_missing:
        taken = {str(d["ma_san_pham"]) for d in with_retry(
            lambda: list(db[COLLECTION].find({"status": {"$in": ["pending", "approved"]}}, {"_id": 0, "ma_san_pham": 1})),
            "mongo suggestions")}
        ids = [i for i in ids if str(i) not in taken]
    return ids


def fetch_docs(db: Any, ids: list[Any]) -> list[dict[str, Any]]:
    from graph_sync.extract import PRODUCT_PROJECTION, with_retry

    projection = {**PRODUCT_PROJECTION, **EXTRA_FIELDS, "danh_muc_day_du": 1}
    docs = with_retry(lambda: list(db["san_pham"].find({"ma_san_pham": {"$in": ids}}, projection)), "mongo products")
    order = {str(i): n for n, i in enumerate(ids)}
    return sorted(docs, key=lambda d: order.get(str(d.get("ma_san_pham")), 10 ** 9))


def ensure_indexes(db: Any) -> None:
    coll = db[COLLECTION]
    coll.create_index([("status", 1), ("created_at", -1)], name="status_created")
    coll.create_index([("ma_san_pham", 1), ("status", 1)], name="product_status")


def _fmt(keys: list[str]) -> str:
    return ",".join(keys) if keys else "-"


def print_table(rows: list[dict[str, Any]]) -> None:
    print()
    print(f"{'ID':>5} | {'Tên sản phẩm':<30} | {'Công dụng (LLM)':<34} | {'Công dụng (rule)':<30} | "
          f"{'Vấn đề (LLM)':<22} | {'Vấn đề (rule)':<22} | {'Loại da (LLM)':<18} | Khác")
    print("-" * 190)
    for r in rows:
        print(f"{str(r['id']):>5} | {r['name'][:30]:<30} | {_fmt(r['llm']['benefits'])[:34]:<34} | "
              f"{_fmt(r['rule']['benefits'])[:30]:<30} | {_fmt(r['llm']['concerns'])[:22]:<22} | "
              f"{_fmt(r['rule']['concerns'])[:22]:<22} | {_fmt(r['llm']['skin_types'])[:18]:<18} | "
              f"{'KHÁC' if r['differs'] else 'trùng'}{'  !' + r['error'] if r['error'] else ''}")


def write_csv(path: str, rows: list[dict[str, Any]]) -> None:
    with open(path, "w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.writer(handle)
        writer.writerow(["ma_san_pham", "ten", "llm_benefits", "rule_benefits", "llm_concerns", "rule_concerns",
                         "llm_skin_types", "rule_skin_types", "khac_rule", "bo_key_la", "evidence", "loi"])
        for r in rows:
            writer.writerow([r["id"], r["name"], _fmt(r["llm"]["benefits"]), _fmt(r["rule"]["benefits"]),
                             _fmt(r["llm"]["concerns"]), _fmt(r["rule"]["concerns"]),
                             _fmt(r["llm"]["skin_types"]), _fmt(r["rule"]["skin_types"]),
                             "khac" if r["differs"] else "trung", " | ".join(r["dropped"]),
                             " | ".join(r["evidence"]), r["error"] or ""])


def run(args: argparse.Namespace) -> int:
    from chatbot_service.llm_pool import get_llms
    from graph_sync.config import load_settings
    from graph_sync.extract import connect, load_lookups, with_retry
    from graph_sync.ingredients import load_aliases
    from graph_sync.transform import to_graph_row
    from tagging.labeler import load_hints, build_suggestion_doc, load_vocabulary
    from tagging.products import (
        choose_ids, current_tags, estimate_cost_usd, product_input, rule_tags_from_row, strip_curation,
    )
    from tagging.runner import Labeler

    llms = get_llms()
    if not llms:
        print("Không có LLM nào được cấu hình (thiếu OPENAI_API_KEY?).", file=sys.stderr)
        return 1
    llm = llms[0]
    try:
        llm = llm.bind(response_format={"type": "json_object"})
    except Exception:            # provider without JSON mode: the parser tolerates fences/prose anyway
        pass

    settings = load_settings()
    db = connect(settings.mongo_uri, settings.mongo_db)
    lookups = load_lookups(db)
    aliases = load_aliases()
    vocab = load_vocabulary()
    labeler = Labeler(llm, vocab, hints=load_hints(), rpm=args.rpm, max_attempts=args.max_attempts)

    if args.ids:
        wanted = [v for raw in args.ids.split(",") if raw.strip() for v in _id_variants(raw.strip())]
        found = {d["ma_san_pham"] for d in db["san_pham"].find({"ma_san_pham": {"$in": wanted}}, {"ma_san_pham": 1})}
        ids = choose_ids(list(found), None)
    else:
        candidates = select_candidates(db, args)
        print(f"Ứng viên: {len(candidates)} sản phẩm" + (" (--only-missing)" if args.only_missing else ""))
        ids = choose_ids(candidates, None if args.all else args.limit, args.sample, args.seed)
    docs = fetch_docs(db, ids)
    print(f"Sẽ gắn nhãn {len(docs)} sản phẩm | dry-run={args.dry_run} | rpm={args.rpm}")

    if not args.dry_run:
        ensure_indexes(db)

    rows: list[dict[str, Any]] = []
    totals: dict[str, int] = {}
    saved = failed = 0
    model = ""
    for n, doc in enumerate(docs, start=1):
        row = to_graph_row(strip_curation(doc), lookups, aliases=aliases)
        if row is None:
            continue
        rule = rule_tags_from_row(row)
        result = labeler.label(product_input(doc, row))
        model = result.model or model
        for key, value in result.usage.items():
            totals[key] = totals.get(key, 0) + value
        parsed = result.parsed
        suggestion = build_suggestion_doc(doc.get("ma_san_pham"), parsed, rule, current_tags(doc),
                                          result.model, result.usage)
        rows.append({
            "id": doc.get("ma_san_pham"), "name": str(doc.get("ten_san_pham") or ""), "llm": parsed.tags(),
            "rule": rule, "differs": suggestion["diff"]["differs"], "dropped": parsed.dropped,
            "evidence": parsed.evidence, "error": None if parsed.ok else parsed.error,
        })
        if not parsed.ok:
            failed += 1
        elif not args.dry_run:
            # Atlas drops idle connections now and then; retry instead of killing a long run.
            with_retry(lambda: db[COLLECTION].replace_one(
                {"ma_san_pham": doc.get("ma_san_pham"), "status": "pending"}, suggestion, upsert=True),
                "mongo save suggestion")
            saved += 1
        print(f"[{n}/{len(docs)}] {doc.get('ma_san_pham')} {'OK' if parsed.ok else 'LỖI'}", flush=True)

    print_table(rows)
    ok = [r for r in rows if not r["error"]]
    differs = sum(1 for r in ok if r["differs"])
    cost = estimate_cost_usd(totals, args.price_in, args.price_out)
    print(f"\nTổng: {len(rows)} sản phẩm | thành công {len(ok)} | lỗi {failed} | khác rule {differs}/{len(ok)}"
          f" | đã ghi pending {saved} | model {model or '?'}")
    print(f"Token: {totals or 'provider không trả'} | chi phí ước tính ~${cost:.4f} "
          f"(đơn giá giả định {args.price_in}/{args.price_out} USD mỗi 1M token)")
    if args.out:
        write_csv(args.out, rows)
        print(f"Đã ghi bảng CSV: {args.out}")
    return 0 if failed == 0 else 3


def main(argv: list[str] | None = None) -> int:
    logging.basicConfig(level=logging.WARNING, format="%(levelname)s %(name)s: %(message)s")
    try:
        sys.stdout.reconfigure(encoding="utf-8")        # type: ignore[attr-defined]
    except Exception:
        pass
    return run(parse_args(argv))


if __name__ == "__main__":
    sys.exit(main())
