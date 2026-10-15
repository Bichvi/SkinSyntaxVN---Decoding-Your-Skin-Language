"""Seed or remove product-page video fixtures for local preview and E2E.

Runs inside the ai-idol image (needs core.state_machine.get_db):
  docker run --rm --network <compose-net> -v <repo>/scripts/e2e:/seed -v <repo>/.env:/app/.env:ro \
      --entrypoint python skinsyntax-ai-idol:local /seed/seed-product-video-fixture.py --apply
Only documents carrying ``e2e_fixture: true`` are ever written or deleted.

``--live`` additionally inserts ONE LIVE campaign for product 5689 (collection ``ai_idol_campaigns``) so the
product page shows the LIVE mini player; ``--cleanup`` removes it again.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import sys

sys.path.insert(0, "/app")

from bson import ObjectId  # noqa: E402

from core.state_machine import get_db  # noqa: E402


BASE = "http://localhost:8080/e2e-media"
NOW = dt.datetime.now(dt.timezone.utc)


def sample(product_id: str, n: int, *, purpose: str, fmt: str, duration: float, name: str, days_ago: int, aired: bool) -> dict:
    when = NOW - dt.timedelta(days=days_ago)
    return {
        "product_id": product_id, "purpose": purpose, "format": fmt, "duration": duration,
        "campaign_name": name, "campaign_id": None, "created_at": when,
        "aired_at": when if aired else None,
        "media": {"video_url": f"{BASE}/sample-{product_id}-{n}.mp4", "poster_url": f"{BASE}/sample-{product_id}-{n}.jpg"},
        "product_page_ready_at": when, "e2e_fixture": True,
    }


def real_copy(item: dict) -> dict:
    aired = dt.datetime.fromisoformat(item["aired"]) if item.get("aired") else None
    when = aired or NOW - dt.timedelta(days=1)
    return {
        "product_id": item["product_id"], "purpose": "live_and_product", "product_page_backfill": True,
        "format": item.get("format") or "16:9", "duration": item.get("duration"),
        "campaign_name": item.get("campaign") or "", "campaign_id": item.get("campaign_id"),
        "created_at": when, "aired_at": aired,
        "media": {"video_url": f"{BASE}/real-{item['id']}.mp4", "poster_url": f"{BASE}/real-{item['id']}.jpg"},
        "product_page_ready_at": when, "source_video_id": item["id"], "e2e_fixture": True,
    }


def fixtures(real_videos: list[dict]) -> list[dict]:
    docs = [
        sample("5689", 1, purpose="product_video", fmt="9:16", duration=12, name="Video mẫu · Niacinamide cho người mới", days_ago=0, aired=False),
        sample("5689", 2, purpose="live_and_product", fmt="9:16", duration=10, name="Video mẫu · Routine da dầu", days_ago=2, aired=True),
        sample("5689", 3, purpose="product_video", fmt="16:9", duration=8, name="Video mẫu · Giải mã thành phần (khung ngang)", days_ago=5, aired=False),
    ]
    return docs + [real_copy(item) for item in real_videos]


def live_campaign() -> dict:
    return {
        "status": "LIVE", "purpose": "live_and_product", "name": "E2E live",
        "product_ids": ["5689"], "platforms": ["internal"],
        "created_at": NOW, "updated_at": NOW, "e2e_fixture": True,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--apply", action="store_true", help="insert fixtures (default: dry run)")
    parser.add_argument("--cleanup", action="store_true", help="delete every e2e_fixture video and campaign")
    parser.add_argument("--live", action="store_true", help="also insert one LIVE e2e campaign for product 5689 (with --apply)")
    parser.add_argument("--real", default="/seed/real-videos.json", help="JSON list of real videos copied to e2e-media")
    args = parser.parse_args()
    db = get_db()
    if args.cleanup:
        result = db.ai_idol_videos.delete_many({"e2e_fixture": True})
        print(f"deleted {result.deleted_count} fixture video(s)")
        campaigns = db.ai_idol_campaigns.delete_many({"e2e_fixture": True})
        print(f"deleted {campaigns.deleted_count} fixture campaign(s)")
        return 0
    try:
        with open(args.real, encoding="utf-8") as handle:
            real_videos = json.load(handle)
    except FileNotFoundError:
        real_videos = []
    docs = fixtures(real_videos)
    for doc in docs:
        print(f"{'insert' if args.apply else 'would insert'} product={doc['product_id']} {doc['media']['video_url']}")
    if args.apply:
        db.ai_idol_videos.delete_many({"e2e_fixture": True})
        for doc in docs:
            doc["_id"] = ObjectId()
        db.ai_idol_videos.insert_many(docs)
    if args.live:
        campaign = live_campaign()
        print(f"{'insert' if args.apply else 'would insert'} LIVE campaign {campaign['name']!r} product_ids={campaign['product_ids']}")
        if args.apply:
            db.ai_idol_campaigns.delete_many({"e2e_fixture": True})
            campaign["_id"] = ObjectId()
            db.ai_idol_campaigns.insert_one(campaign)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
