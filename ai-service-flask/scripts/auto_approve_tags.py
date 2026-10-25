"""Auto-approve only the "confident" LLM tag suggestions in ``tag_suggestions`` (U07 follow-up).

    MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-ai python scripts/auto_approve_tags.py            # dry-run (default)
    ... python scripts/auto_approve_tags.py --apply [--limit N] [--event-chunk 300 --chunk-sleep 45]
    ... python scripts/auto_approve_tags.py --undo --apply      # revert everything approved by auto_policy_v1

Policy (a suggestion is confident iff ALL hold; everything else stays ``pending`` for a human):
  (a) no dropped / unknown keys (``dropped`` empty and every suggested key is in the canonical vocabulary),
  (b) non-empty evidence,
  (c) >= 1 suggested benefit also in the rule-inferred benefits stored on the suggestion,
  (d) the product exists, is visible and its ``tag_source`` is not ``manual``.

Approval mirrors ``TagSuggestion::approve`` + ``AdminTagController::reviewAction`` (PHP) exactly:
  san_pham   $set cong_dung_keys / van_de_da_keys / loai_da_keys / tag_source="llm_approved" /
             tag_updated_at / tag_updated_by   (``updated_at`` untouched)
  tag_suggestions  $set status=approved, reviewed_by, reviewed_at, approved, edited=false
  Redis stream catalog:changes:v1  {type:product_updated, ma_san_pham, reason:tags, source:llm_approved, ts}
Extras (for undo only): ``auto_policy`` and ``auto_prev`` (the product's previous tag fields) on the suggestion.

Writes are limited to those fields. Default is dry-run; idempotent (only ``status == pending`` is read and the
suggestion update is filtered on ``status == pending``). Atlas hiccups are retried via graph_sync.extract.with_retry.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import os
import sys
import time
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence

SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

POLICY = "auto_policy_v1"
COLLECTION = "tag_suggestions"
STREAM = "catalog:changes:v1"
STREAM_MAXLEN = 10000                       # same as PHP TagCuration::STREAM_MAXLEN
HIDDEN = {"inactive", "hidden", "tam_an", "taman", "disabled", "off", "0"}   # same list as llm_tag_products.py
GROUPS = ("benefits", "concerns", "skin_types")
FIELD_BY_GROUP = {"benefits": "cong_dung_keys", "concerns": "van_de_da_keys", "skin_types": "loai_da_keys"}
TAG_FIELDS = (*FIELD_BY_GROUP.values(), "tag_source", "tag_updated_at", "tag_updated_by")
SOURCE_LLM = "llm_approved"

# Exclusion reason codes
R_DROPPED = "dropped_keys"
R_INVALID = "invalid_keys"
R_NO_EVIDENCE = "no_evidence"
R_NO_OVERLAP = "no_benefit_overlap"
R_NO_PRODUCT = "no_product"
R_MANUAL = "manual_tags"
R_HIDDEN = "hidden_product"


def actor_hash(identity: str = POLICY, salt: str | None = None) -> str:
    """Same format as PHP ``TagCuration::actorHash``: ``adm_`` + first 16 hex of HMAC-SHA256."""

    if salt is None:
        salt = os.environ.get("SEARCH_USER_HASH_SALT", "")
    key = salt if salt else "ssv-tag-actor"
    digest = hmac.new(key.encode(), identity.strip().lower().encode(), hashlib.sha256).hexdigest()
    return "adm_" + digest[:16]


def _keys(value: Any) -> list[str]:
    return [str(k).strip() for k in value if str(k).strip()] if isinstance(value, (list, tuple)) else []


def clean_selection(suggested: Mapping[str, Any], vocab: Mapping[str, Mapping[str, str]]) -> tuple[dict[str, list[str]], list[str]]:
    """(valid keys per group, de-duplicated, order kept; rejected keys) - like ``TagCatalog::validate``."""

    out: dict[str, list[str]] = {}
    rejected: list[str] = []
    for group in GROUPS:
        valid: list[str] = []
        for key in _keys((suggested or {}).get(group)):
            if key in valid:
                continue
            (valid if key in vocab.get(group, {}) else rejected).append(key)
        out[group] = valid
    return out, rejected


def is_visible(product: Mapping[str, Any]) -> bool:
    return str(product.get("trang_thai") or "").strip().lower() not in HIDDEN


def evaluate(suggestion: Mapping[str, Any], product: Mapping[str, Any] | None,
             vocab: Mapping[str, Mapping[str, str]]) -> tuple[bool, list[str], dict[str, list[str]]]:
    """Policy check. Returns (confident, exclusion reasons, keys that would be approved)."""

    reasons: list[str] = []
    selection, rejected = clean_selection(suggestion.get("suggested") or {}, vocab)
    if _keys(suggestion.get("dropped")):
        reasons.append(R_DROPPED)
    if rejected:
        reasons.append(R_INVALID)
    evidence = suggestion.get("evidence")
    if not any(str(e).strip() for e in (evidence if isinstance(evidence, (list, tuple)) else [])):
        reasons.append(R_NO_EVIDENCE)
    rule_benefits = set(_keys((suggestion.get("rule") or {}).get("benefits")))
    if not rule_benefits.intersection(selection["benefits"]):
        reasons.append(R_NO_OVERLAP)
    if product is None:
        reasons.append(R_NO_PRODUCT)
    else:
        if str(product.get("tag_source") or "") == "manual":
            reasons.append(R_MANUAL)
        if not is_visible(product):
            reasons.append(R_HIDDEN)
    return (not reasons), reasons, selection


def change_event(product_id: Any, now_ms: int | None = None) -> dict[str, str]:
    return {"type": "product_updated", "ma_san_pham": str(product_id), "reason": "tags", "source": SOURCE_LLM,
            "ts": str(now_ms if now_ms is not None else int(time.time() * 1000))}


def product_tag_set(selection: Mapping[str, Sequence[str]], actor: str, now: Any) -> dict[str, Any]:
    out: dict[str, Any] = {FIELD_BY_GROUP[g]: list(selection.get(g, [])) for g in GROUPS}
    out["tag_source"] = SOURCE_LLM
    out["tag_updated_at"] = now
    out["tag_updated_by"] = actor
    return out


def chunked(items: Sequence[Any], size: int) -> Iterable[Sequence[Any]]:
    size = max(1, size)
    for i in range(0, len(items), size):
        yield items[i:i + size]


def parse_args(argv: list[str] | None = None) -> argparse.Namespace:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    p.add_argument("--apply", action="store_true", help="write to Mongo/Redis (default: dry-run)")
    p.add_argument("--limit", type=int, help="approve at most N confident suggestions")
    p.add_argument("--undo", action="store_true", help=f"revert suggestions approved by {POLICY} (needs --apply to write)")
    p.add_argument("--event-chunk", type=int, default=300, help="publish events per N products (default 300)")
    p.add_argument("--chunk-sleep", type=float, default=0.0, help="seconds to wait between chunks so the worker can drain")
    p.add_argument("--samples", type=int, default=10)
    args = p.parse_args(argv)
    if args.limit is not None and args.limit < 1:
        p.error("--limit must be >= 1")
    return args


# ---- Mongo / Redis plumbing ---------------------------------------------------------------------------------

def _fetch_products(db: Any, ids: list[Any], with_retry: Any) -> dict[str, dict[str, Any]]:
    proj = {"_id": 0, "ma_san_pham": 1, "ten_san_pham": 1, "trang_thai": 1, **{f: 1 for f in TAG_FIELDS}}
    found: dict[str, dict[str, Any]] = {}
    for part in chunked(ids, 500):
        for d in with_retry(lambda part=part: list(db["san_pham"].find({"ma_san_pham": {"$in": list(part)}}, proj)),
                            "mongo products"):
            found[str(d.get("ma_san_pham"))] = d
    return found


def _publish(ids: Sequence[Any]) -> int:
    from shared.redis_kit import client as rclient, streams

    redis = rclient.get_redis()
    if redis is None:
        print("CẢNH BÁO: Redis không sẵn sàng - không phát được sự kiện catalog.", file=sys.stderr)
        return 0
    return streams.publish_many(STREAM, [change_event(i) for i in ids], maxlen=STREAM_MAXLEN, client=redis)


def _print_summary(total: int, confident: list[dict[str, Any]], excluded: Counter, primary: Counter, samples: int) -> None:
    print(f"Tổng pending: {total}")
    print(f"Tự tin (đủ a-d): {len(confident)} | giữ pending: {total - len(confident)}")
    print("Lý do loại (một gợi ý có thể dính nhiều lý do):")
    for reason, n in excluded.most_common():
        print(f"  {reason:<22} {n}")
    print("Lý do loại đầu tiên (tách rời, cộng lại = số bị loại):")
    for reason, n in primary.most_common():
        print(f"  {reason:<22} {n}")
    print(f"{samples} mẫu tự tin (id | tên | công dụng / vấn đề / loại da):")
    for item in confident[:samples]:
        sel = item["selection"]
        print(f"  {item['id']} | {item['name'][:40]} | {','.join(sel['benefits']) or '-'} / "
              f"{','.join(sel['concerns']) or '-'} / {','.join(sel['skin_types']) or '-'}")


def run_approve(db: Any, args: argparse.Namespace, vocab: Mapping[str, Mapping[str, str]]) -> int:
    from pymongo import UpdateOne
    from graph_sync.extract import with_retry

    pending = with_retry(lambda: list(db[COLLECTION].find({"status": "pending"})), "mongo pending suggestions")
    products = _fetch_products(db, [s.get("ma_san_pham") for s in pending], with_retry)
    confident: list[dict[str, Any]] = []
    excluded: Counter = Counter()
    primary: Counter = Counter()
    for s in pending:
        product = products.get(str(s.get("ma_san_pham")))
        ok, reasons, selection = evaluate(s, product, vocab)
        if ok:
            confident.append({"id": s.get("ma_san_pham"), "name": str((product or {}).get("ten_san_pham") or ""),
                              "selection": selection, "suggestion": s, "product": product})
        else:
            excluded.update(reasons)
            primary[reasons[0]] += 1
    _print_summary(len(pending), confident, excluded, primary, args.samples)

    todo = confident[: args.limit] if args.limit else confident
    if not args.apply:
        print(f"\nDRY-RUN: không ghi gì. Sẽ duyệt {len(todo)} gợi ý khi chạy với --apply.")
        return 0

    actor = actor_hash()
    done = 0
    for part in chunked(todo, args.event_chunk):
        now = datetime.now(timezone.utc)
        prod_ops, sugg_ops = [], []
        for item in part:
            s, product = item["suggestion"], item["product"]
            prev = {f: product[f] for f in TAG_FIELDS if f in product}
            prod_ops.append(UpdateOne({"ma_san_pham": s["ma_san_pham"]},
                                      {"$set": product_tag_set(item["selection"], actor, now)}))
            sugg_ops.append(UpdateOne({"_id": s["_id"], "status": "pending"}, {"$set": {
                "status": "approved", "reviewed_by": actor, "reviewed_at": now, "approved": item["selection"],
                "edited": False, "auto_policy": POLICY, "auto_prev": prev}}))
        with_retry(lambda: db["san_pham"].bulk_write(prod_ops, ordered=False), "mongo write san_pham")
        with_retry(lambda: db[COLLECTION].bulk_write(sugg_ops, ordered=False), "mongo write suggestions")
        published = _publish([i["id"] for i in part])
        done += len(part)
        print(f"  đã duyệt {done}/{len(todo)} | sự kiện {published}/{len(part)}", flush=True)
        if args.chunk_sleep > 0 and done < len(todo):
            time.sleep(args.chunk_sleep)
    print(f"\nAPPLY xong: duyệt {done} gợi ý bởi {actor} ({POLICY}).")
    return 0


def run_undo(db: Any, args: argparse.Namespace) -> int:
    from pymongo import UpdateOne
    from graph_sync.extract import with_retry

    rows = with_retry(lambda: list(db[COLLECTION].find({"status": "approved", "auto_policy": POLICY})), "mongo auto-approved")
    todo = rows[: args.limit] if args.limit else rows
    print(f"{len(rows)} gợi ý đã duyệt bởi {POLICY}; sẽ hoàn tác {len(todo)}.")
    if not args.apply:
        print("DRY-RUN: không ghi gì.")
        return 0
    for part in chunked(todo, args.event_chunk):
        prod_ops, sugg_ops = [], []
        for s in part:
            prev = s.get("auto_prev") or {}
            unset = {f: "" for f in TAG_FIELDS if f not in prev}
            update: dict[str, Any] = {}
            if prev:
                update["$set"] = dict(prev)
            if unset:
                update["$unset"] = unset
            # only touch products still tagged by this policy (a later manual edit wins)
            prod_ops.append(UpdateOne({"ma_san_pham": s["ma_san_pham"], "tag_updated_by": s.get("reviewed_by"),
                                       "tag_source": SOURCE_LLM}, update))
            sugg_ops.append(UpdateOne({"_id": s["_id"], "auto_policy": POLICY}, {
                "$set": {"status": "pending", "reviewed_by": None, "reviewed_at": None},
                "$unset": {"approved": "", "edited": "", "auto_policy": "", "auto_prev": ""}}))
        with_retry(lambda: db["san_pham"].bulk_write(prod_ops, ordered=False), "mongo undo san_pham")
        with_retry(lambda: db[COLLECTION].bulk_write(sugg_ops, ordered=False), "mongo undo suggestions")
        _publish([s["ma_san_pham"] for s in part])
        if args.chunk_sleep > 0:
            time.sleep(args.chunk_sleep)
    print("UNDO xong.")
    return 0


def main(argv: list[str] | None = None) -> int:
    try:
        sys.stdout.reconfigure(encoding="utf-8")        # type: ignore[attr-defined]
    except Exception:
        pass
    args = parse_args(argv)
    from graph_sync.config import load_settings
    from graph_sync.extract import connect
    from tagging.labeler import load_vocabulary

    settings = load_settings()
    db = connect(settings.mongo_uri, settings.mongo_db)
    return run_undo(db, args) if args.undo else run_approve(db, args, load_vocabulary())


if __name__ == "__main__":
    sys.exit(main())
