"""Live campaigns are created as clip libraries and prepared in phase A (writer and HTTP patched)."""
import datetime as dt
import os
import sys
import unittest
from unittest.mock import patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from tests.fakes import FakeDb

import core.campaigns as campaigns
import core.state_machine as state_machine
from core.show_types import ClipDraft

PRICING = {"regular_price": 200000, "flash": None}


def snapshot_for(product_id):
    return {"id": str(product_id), "name": f"Sản phẩm {product_id}", "brand": "Beplain",
            "category": "serum", "pricing": dict(PRICING)}


def fake_plan(snapshots, **kwargs):
    products = [{"product_id": s["id"], "number": i, "step_key": "serum", "step_label": "Serum"}
                for i, s in enumerate(snapshots, start=1)]
    return {"products": products, "conflicts": [], "theme_vi": "", "routine_source": "keyword"}


def fake_product_clips(snapshot, item, plan, **kwargs):
    return [ClipDraft(kind="product", script=f"Clip {angle} {item['product_id']}",
                      product_id=item["product_id"], angle=angle) for angle in ("A", "B")]


def fake_frame_clips(plan, snapshots, faqs, **kwargs):
    pid = plan["products"][0]["product_id"]
    drafts = [ClipDraft(kind="opening", script="Chào mọi người")]
    drafts += [ClipDraft(kind="transition", script=f"Chuyển {i}", variant=i) for i in range(2)]
    drafts += [ClipDraft(kind="interlude", script=f"Nghỉ {t}{i}", interlude_type=t, variant=i)
               for t in ("cta", "about_shop", "fun") for i in range(2)]
    drafts += [ClipDraft(kind="faq", script=f"Hỏi {i}", product_id=pid, variant=i) for i in range(4)]
    drafts += [ClipDraft(kind="tip", script=f"Mẹo {i}", variant=i) for i in range(2)]
    drafts += [ClipDraft(kind="closing", script="Tạm biệt")]
    return "Routine da dầu", drafts


class Harness(unittest.TestCase):
    def setUp(self):
        self.db = FakeDb()
        for target in (campaigns, state_machine):
            patcher = patch.object(target, "get_db", return_value=self.db)
            patcher.start()
            self.addCleanup(patcher.stop)

    def create(self, n_products=3, minutes_ahead=200, purpose="live_and_product"):
        when = (dt.datetime.now(dt.timezone.utc) + dt.timedelta(minutes=minutes_ahead)).isoformat()
        return campaigns.create_campaign({
            "product_ids": [str(i) for i in range(1, n_products + 1)], "purpose": purpose,
            "scheduled_at": when,
        })

    def campaign(self):
        return self.db.ai_idol_campaigns.docs[0]


class CreateTests(Harness):
    def test_live_campaign_rejects_seven_products(self):
        with self.assertRaises(ValueError) as ctx:
            self.create(n_products=7)
        self.assertIn("tối đa 6", str(ctx.exception))
        self.assertEqual(self.db.ai_idol_campaigns.docs, [])

    def test_live_campaign_lead_time_uses_library_estimate(self):
        with self.assertRaises(ValueError) as ctx:
            self.create(n_products=3, minutes_ahead=90)
        self.assertIn("98", str(ctx.exception))
        _, created = self.create(n_products=3, minutes_ahead=100)
        self.assertTrue(created)

    def test_live_campaign_gets_show_format_and_plan(self):
        self.create()
        doc = self.campaign()
        self.assertEqual(doc["show_format"], "clip_library_v1")
        self.assertEqual(set(doc["show_plan"]), {"products", "conflicts", "theme_vi", "routine_source"})
        self.assertEqual(self.db.ai_idol_segments.docs, [])  # prepare creates them

    def test_product_video_unchanged(self):
        campaigns.create_campaign({"product_ids": ["1", "2"], "purpose": "product_video"})
        doc = self.campaign()
        self.assertNotIn("show_format", doc)
        self.assertNotIn("show_plan", doc)
        self.assertEqual(doc["progress"]["total"], 2)
        segments = self.db.ai_idol_segments.docs
        self.assertEqual(len(segments), 2)
        self.assertTrue(all("kind" not in s for s in segments))


class PrepareTests(Harness):
    def prepare(self):
        campaign_id, _ = self.create()
        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for), \
                patch.object(campaigns.show_plan, "build_show_plan", side_effect=fake_plan), \
                patch.object(campaigns.show_writer, "write_product_clips", side_effect=fake_product_clips), \
                patch.object(campaigns.show_writer, "write_frame_clips", side_effect=fake_frame_clips), \
                patch.object(campaigns.show_writer, "load_answered_faqs", return_value=[]):
            return campaign_id, campaigns.prepare_campaign(campaign_id)

    def test_prepare_creates_phase_a_segments_and_jobs(self):
        campaign_id, job_ids = self.prepare()
        segments = self.db.ai_idol_segments.docs
        jobs = self.db.ai_idol_jobs.docs
        self.assertEqual(len(segments), 22)
        self.assertEqual(len(job_ids), 22)
        self.assertEqual(sum(1 for s in segments if s["kind"] == "product"), 6)
        self.assertEqual([s["position"] for s in segments], list(range(22)))
        self.assertTrue(all(s["phase"] == "A" and s["status"] == "GENERATING" and s["script_text"] for s in segments))
        self.assertTrue(all(j["prewritten_script"] is True for j in jobs))
        self.assertTrue(all(j["purpose"] == "live_and_product" for j in jobs))
        for job in jobs:
            self.assertEqual(job["artifacts"]["prewritten_draft"] is not None, True)
            if job["clip_kind"] in ("product", "faq"):
                self.assertEqual(job["product_snapshot"]["id"], job["product_id"])
            else:
                self.assertIsNone(job["product_id"])
                snap = job["product_snapshot"]
                self.assertEqual(snap["name"], "Routine da dầu")
                self.assertEqual(snap["brand"], "SkinSyntax")
                self.assertEqual(len(snap["products"]), 3)
                self.assertEqual(snap["bundle_total"], 600000)
                self.assertIsNone(snap["pricing"])
        doc = self.campaign()
        self.assertEqual(doc["status"], "GENERATING")
        self.assertEqual(doc["progress"]["total"], 22)
        self.assertEqual(doc["show_plan"]["theme_vi"], "Routine da dầu")

    def test_prepare_is_idempotent(self):
        campaign_id, first = self.prepare()
        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for), \
                patch.object(campaigns.show_writer, "write_product_clips") as writer:
            second = campaigns.prepare_campaign(campaign_id)
        writer.assert_not_called()
        self.assertEqual(len(self.db.ai_idol_segments.docs), 22)
        self.assertEqual(len(self.db.ai_idol_jobs.docs), 22)
        self.assertEqual(sorted(second), sorted(first))

    def test_writer_error_needs_attention(self):
        campaign_id, _ = self.create()
        with patch.object(campaigns, "collect_product_and_snapshot", side_effect=snapshot_for), \
                patch.object(campaigns.show_plan, "build_show_plan", side_effect=fake_plan), \
                patch.object(campaigns.show_writer, "write_product_clips", side_effect=ValueError("hỏng")):
            result = campaigns.prepare_campaign(campaign_id)
        self.assertEqual(result, [])
        doc = self.campaign()
        self.assertEqual(doc["status"], "NEEDS_ATTENTION")
        self.assertEqual(doc["error_message"], "Không viết được kịch bản buổi live: hỏng")
        self.assertEqual(self.db.ai_idol_segments.docs, [])


if __name__ == "__main__":
    unittest.main()
