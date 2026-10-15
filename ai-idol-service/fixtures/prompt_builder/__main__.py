"""CLI: python -m fixtures.prompt_builder <lệnh> [--seed N] [--dry-run] [--with-dirty] [...]

Chỉ ``plan`` và ``verify`` là đọc. Các lệnh ghi (apply, archive-legacy, restore-categories, restore-legacy, reset)
cần ``--yes`` (hoặc ``--dry-run`` để xem trước) và không bao giờ in URI/secret.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import sys
from zoneinfo import ZoneInfo

from config import TIMEZONE

from . import config as cfg
from . import store
from .report import render_table, summarize, write_report

WRITE_COMMANDS = ("apply", "archive-legacy", "restore-categories", "restore-legacy", "reset", "refresh-emails")
COMMANDS = ("plan", *WRITE_COMMANDS, "verify")


def parse_args(argv=None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(prog="python -m fixtures.prompt_builder", description=__doc__.splitlines()[0])
    parser.add_argument("command", choices=COMMANDS)
    parser.add_argument("--seed", type=int, default=cfg.DEFAULT_SEED)
    parser.add_argument("--dry-run", action="store_true", help="không ghi gì; chỉ báo số lượng sẽ ghi")
    parser.add_argument("--with-dirty", action="store_true", help="thêm hoàn trả (~2%% đơn hoàn thành) để thử dữ liệu bẩn")
    parser.add_argument("--sync-catalog-stock", action="store_true", help="đặt san_pham.so_luong_ton_kho = tồn khả dụng của lô (có backup)")
    parser.add_argument("--sync-catalog-sold", action="store_true", help="cộng số bán fixture vào san_pham.so_luong_da_ban (có backup)")
    parser.add_argument("--as-of", help="YYYY-MM-DD (mặc định: hôm nay theo giờ VN)")
    parser.add_argument("--out", default=".runtime/pb-fixture", help="thư mục báo cáo verify")
    parser.add_argument("--yes", action="store_true", help="xác nhận cho lệnh ghi thật")
    return parser.parse_args(argv)


def _today(args) -> dt.date:
    return dt.date.fromisoformat(args.as_of) if args.as_of else dt.datetime.now(ZoneInfo(TIMEZONE)).date()


def _say(value) -> None:
    print(json.dumps(value, ensure_ascii=False, indent=2, default=str))


def build_for(db, args):
    """Dataset trong bộ nhớ từ catalog đang có trong DB (cộng sản phẩm sẽ khôi phục nếu chưa khôi phục)."""
    from .dataset import build_dataset
    live = store.load_products(db)
    restored = store.simulate_restore(db, args.seed, live)
    products = live + restored
    return build_dataset(products, _today(args), args.seed, with_dirty=args.with_dirty), products, len(restored)


def cmd_plan(db, args) -> int:
    from .cases import run_offline
    from .dataset import snapshot_docs
    data, products, restored = build_for(db, args)
    today = _today(args)
    results = run_offline(data, products, today, snapshot_docs(data, products, today))
    manifest = data["manifest"]
    _say({"as_of": today.isoformat(), "seed": args.seed, "products_in_catalog": len(products), "products_to_restore": restored,
          "documents_to_create": manifest["counts"] | {"pb_fixture_manifest": 1}, "anchors": manifest["anchors"],
          "scope_batch_code": manifest["scope_batch_code"], "shortfalls": manifest["shortfalls"],
          "flash_day": manifest["flash_day"], "summary": summarize(results)})
    print(render_table(results))
    return 0 if summarize(results)["fail"] == 0 else 1


def cmd_verify(db, args) -> int:
    results, manifest = store.verify_online(db)
    md, js = write_report(results, args.out, manifest["as_of"], manifest.get("counts"))
    _say({"summary": summarize(results), "report": [str(md), str(js)]})
    print(render_table(results))
    return 0 if summarize(results)["fail"] == 0 else 1


def main(argv=None) -> int:
    args = parse_args(argv)
    if args.command in WRITE_COMMANDS and not (args.dry_run or args.yes):
        print(f"'{args.command}' ghi vào DB: chạy với --dry-run để xem trước, hoặc --yes để ghi thật.", file=sys.stderr)
        return 2
    from core.state_machine import get_db
    db = get_db()
    try:
        if args.command == "plan":
            return cmd_plan(db, args)
        if args.command == "verify":
            return cmd_verify(db, args)
        if args.command == "archive-legacy":
            _say(store.archive_legacy(db, dry_run=args.dry_run))
        elif args.command == "restore-legacy":
            _say(store.restore_legacy(db, dry_run=args.dry_run))
        elif args.command == "restore-categories":
            _say(store.restore_categories(db, args.seed, dry_run=args.dry_run))
        elif args.command == "reset":
            _say(store.reset(db, dry_run=args.dry_run))
        elif args.command == "refresh-emails":
            manifest = db.pb_fixture_manifest.find_one({"_id": cfg.DATASET_TAG})
            if manifest is None:
                raise store.PreflightError("Chưa nạp dataset; không có khách nào để cập nhật.")
            args.as_of = args.as_of or manifest["as_of"]  # khách sinh lại phải đúng ngày đã nạp
            data, _, _ = build_for(db, args)
            _say(store.refresh_customer_emails(db, data, dry_run=args.dry_run))
        elif args.command == "apply":
            data, _, _ = build_for(db, args)
            _say(store.apply_dataset(db, data, dry_run=args.dry_run, sync_stock=args.sync_catalog_stock, sync_sold=args.sync_catalog_sold))
    except store.PreflightError as exc:
        print(f"Từ chối: {exc}", file=sys.stderr)
        return 3
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
