"""Ghép lô hàng, khách hàng, phiên xem và đơn hàng thành một dataset pb-fixture-v1 (hàm thuần, không ghi DB)."""
from __future__ import annotations

import datetime as dt
import random

from . import config as cfg
from .batches import active_products, build_inventory, pick_anchors, plan_inventory
from .commerce import engineered_ids, generate_commerce, generate_customers, pick_engineered

COLLECTIONS = ("khach_hang", "product_events", "hoa_don", "chi_tiet_hoa_don", "inventory_batches", "inventory_movements")
MANIFEST_COLLECTION = "pb_fixture_manifest"


def build_dataset(products, today: dt.date, seed: int = cfg.DEFAULT_SEED, *, with_dirty: bool = False,
                  customers: int = 160) -> dict:
    """Trả {collection: [doc]} cho 6 collection dữ liệu + ``manifest`` (một document) + ``stats``.

    Thứ tự: ví dụ A/B/C -> ca thiết kế sẵn -> hồ sơ lô -> đơn hàng (không chứa sản phẩm hết hàng) -> lô trừ FEFO theo đơn.
    """
    rng = random.Random(seed)
    rows = active_products(products)
    anchors = pick_anchors(rows, rng)
    engineered = pick_engineered(rows, rng, exclude=set(anchors.values()))
    plan = plan_inventory(rows, today, rng, reserved=engineered_ids(engineered), anchors=anchors)
    people = generate_customers(customers, rng)
    commerce = generate_commerce(rows, people, today, rng, engineered, exclude_sales=plan.no_sale | set(anchors.values()),
                                 with_dirty=with_dirty)
    batches, movements, inventory_manifest = build_inventory(plan, rng, commerce["sales_by_product"], commerce["returns"])
    tracking_started = today - dt.timedelta(days=cfg.SESSION_DAYS)
    manifest = cfg.tag({
        "_id": cfg.DATASET_TAG, "kind": "manifest", "as_of": today.isoformat(), "seed": seed, "with_dirty": with_dirty,
        "tracking_started_on": tracking_started.isoformat(), "engineered": engineered,
        "id_ranges": {"hoa_don": cfg.ID_BASE["hoa_don"], "chi_tiet_hoa_don": cfg.ID_BASE["chi_tiet_hoa_don"],
                      "khach_hang": cfg.ID_BASE["khach_hang"]},
        "flash_day": commerce["stats"]["flash_day"], **{k: v for k, v in inventory_manifest.items() if k != "as_of"},
    })
    data = {
        "khach_hang": people, "product_events": commerce["events"], "hoa_don": commerce["orders"],
        "chi_tiet_hoa_don": commerce["lines"], "inventory_batches": batches, "inventory_movements": movements,
    }
    manifest["counts"] = {name: len(docs) for name, docs in data.items()}
    data["manifest"] = manifest
    data["stats"] = {**commerce["stats"], "products": len(rows), "returns": len(commerce["returns"])}
    return data


def snapshot_docs(data: dict, products, today: dt.date) -> list[dict]:
    """Snapshot bán hàng 7/30 ngày tính bằng chính engine thật (core.product_metrics), không ghi DB."""
    from core.product_metrics import compute_snapshot_docs
    events = [{"type": e["type"], "product_id": e["product_id"], "sid": e["session_hash"], "event_time": e["event_time"]}
              for e in data["product_events"]]
    returns = [(m["product_id"], m["occurred_at"]) for m in data["inventory_movements"] if m["type"] == "return"]
    tracking = dt.date.fromisoformat(data["manifest"]["tracking_started_on"])
    return compute_snapshot_docs(
        today, catalog=active_products(products), orders=data["hoa_don"], lines=data["chi_tiet_hoa_don"], events=events,
        returns=returns, tracking_started_on=tracking, retention_days=120, today=today, run_id="pb-fixture-offline",
        built_at=dt.datetime.now(dt.timezone.utc))
