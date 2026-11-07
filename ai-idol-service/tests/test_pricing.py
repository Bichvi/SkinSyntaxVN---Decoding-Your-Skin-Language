import datetime as dt
import unittest
from unittest.mock import patch

from tests.fakes import Collection, FakeDb

from core.pricing import (
    claim_from_pricing,
    current_pricing,
    price_claim_state,
    resolve_pricing,
)

UTC = dt.timezone.utc
NOW = dt.datetime(2026, 10, 16, 3, 0, tzinfo=UTC)
END_B = dt.datetime(2026, 10, 17, 16, 59, tzinfo=UTC)
PRODUCT = {"ma_san_pham": "1024", "gia_ban": 299000, "gia_thi_truong": 350000}


def campaign(**over):
    doc = {
        "ma_campaign": 12,
        "ten_campaign": "Sale 16/10",
        "is_active": True,
        "enabled": True,
        "start_at": dt.datetime(2026, 10, 16, 0, 0, tzinfo=UTC),
        "end_at": END_B,
        "items": [{"ma_san_pham": "1024", "flash_price": 249000}],
    }
    doc.update(over)
    return doc


def make_db(*campaigns, product=None):
    return FakeDb(
        flash_sale_campaigns=Collection(list(campaigns)),
        san_pham=Collection([dict(product or PRODUCT)]),
    )


class ResolvePricingTests(unittest.TestCase):
    def resolve(self, *campaigns, product=None, now=NOW, **kw):
        return resolve_pricing("1024", product or dict(PRODUCT), now, db=make_db(*campaigns), **kw)

    def test_case_a_no_campaign(self):
        p = self.resolve()
        self.assertEqual(p["regular_price"], 299000)
        self.assertEqual(p["market_price"], 350000)
        self.assertIsNone(p["flash"])
        self.assertEqual(p["captured_ts"], int(NOW.timestamp()))

    def test_case_b_active_flash(self):
        p = self.resolve(campaign())
        self.assertEqual(p["flash"], {
            "campaign_id": 12, "campaign_name": "Sale 16/10", "flash_price": 249000,
            "discount_percent": 17, "end_ts": int(END_B.timestamp()),
        })

    def test_case_c_disabled(self):
        self.assertIsNone(self.resolve(campaign(is_active=False, enabled=False))["flash"])

    def test_case_d_under_two_hours(self):
        c = campaign(end_at=dt.datetime(2026, 10, 16, 4, 30, tzinfo=UTC))
        self.assertIsNone(self.resolve(c)["flash"])
        self.assertIsNotNone(self.resolve(c, min_remaining=0)["flash"])

    def test_case_e_flash_not_lower(self):
        c = campaign(items=[{"ma_san_pham": "1024", "flash_price": 299000}])
        self.assertIsNone(self.resolve(c)["flash"])

    def test_case_f_other_product(self):
        c = campaign(items=[{"ma_san_pham": "2048", "flash_price": 249000}])
        self.assertIsNone(self.resolve(c)["flash"])

    def test_int_product_id_in_items_matches(self):
        c = campaign(items=[{"ma_san_pham": 1024, "flash_price": 249000}])
        self.assertEqual(self.resolve(c)["flash"]["flash_price"], 249000)

    def test_naive_datetimes_treated_as_utc(self):
        c = campaign(start_at=dt.datetime(2026, 10, 16, 0, 0))
        self.assertIsNotNone(self.resolve(c)["flash"])

    def test_not_started_yet(self):
        c = campaign(start_at=dt.datetime(2026, 10, 16, 5, 0, tzinfo=UTC))
        self.assertIsNone(self.resolve(c)["flash"])

    def test_discount_rounds_half_up_like_php(self):
        p = self.resolve(campaign(items=[{"ma_san_pham": "1024", "flash_price": 175000}]),
                         product={"gia_ban": 200000})
        self.assertEqual(p["flash"]["discount_percent"], 13)

    def test_null_flash_price_falls_back_to_gia_flash(self):
        c = campaign(items=[{"ma_san_pham": " 1024 ", "flash_price": None, "gia_flash": 249000}])
        self.assertEqual(self.resolve(c)["flash"]["flash_price"], 249000)

    def test_newest_start_wins_not_lowest_price(self):
        newer = campaign(ma_campaign=13, start_at=dt.datetime(2026, 10, 16, 1, 0, tzinfo=UTC),
                         items=[{"product_id": "1024", "gia_flash": 279000}])
        self.assertEqual(self.resolve(campaign(), newer)["flash"]["campaign_id"], 13)

    def test_newer_campaign_without_valid_price_falls_to_older(self):
        newer = campaign(ma_campaign=13, start_at=dt.datetime(2026, 10, 16, 1, 0, tzinfo=UTC),
                         items=[{"ma_san_pham": "1024", "flash_price": 299000}])
        self.assertEqual(self.resolve(campaign(), newer)["flash"]["campaign_id"], 12)

    def test_min_remaining_applies_to_chosen_campaign_only(self):
        newer = campaign(ma_campaign=13, start_at=dt.datetime(2026, 10, 16, 1, 0, tzinfo=UTC),
                         end_at=dt.datetime(2026, 10, 16, 4, 0, tzinfo=UTC))
        self.assertIsNone(self.resolve(campaign(), newer)["flash"])
        self.assertEqual(self.resolve(campaign(), newer, min_remaining=0)["flash"]["campaign_id"], 13)

    def test_campaign_with_neither_flag_matches_php(self):
        c = campaign()
        del c["is_active"], c["enabled"]
        self.assertEqual(self.resolve(c)["flash"]["flash_price"], 249000)

    def test_is_active_false_excluded_even_if_enabled(self):
        self.assertIsNone(self.resolve(campaign(is_active=False, enabled=True))["flash"])

    def test_decimal_string_prices_truncate_like_php(self):
        c = campaign(items=[{"ma_san_pham": "1024", "flash_price": "249000.0"}])
        p = self.resolve(c, product={"ma_san_pham": "1024", "gia_ban": "299000.0", "gia_thi_truong": "350000.9"})
        self.assertEqual(p["regular_price"], 299000)
        self.assertEqual(p["market_price"], 350000)
        self.assertEqual(p["flash"]["flash_price"], 249000)

    def test_zero_prices_give_no_pricing(self):
        p = self.resolve(product={"gia_ban": 0, "gia_thi_truong": 350000})
        self.assertEqual(p["regular_price"], 350000)
        self.assertIsNone(p["market_price"])
        self.assertIsNone(self.resolve(product={"gia_ban": 0, "gia_thi_truong": 0}))

    def test_market_not_above_regular_is_none(self):
        self.assertIsNone(self.resolve(product={"gia_ban": 299000, "gia_thi_truong": 299000})["market_price"])
        self.assertIsNone(self.resolve(product={"gia_ban": 299000, "gia_thi_truong": 100000})["market_price"])


class CurrentPricingTests(unittest.TestCase):
    def test_reads_product_and_ignores_two_hour_floor(self):
        c = campaign(end_at=dt.datetime(2026, 10, 16, 4, 30, tzinfo=UTC))
        p = current_pricing("1024", NOW, db=make_db(c))
        self.assertEqual(p["flash"]["flash_price"], 249000)

    def test_int_id_product_and_missing_product(self):
        db = make_db(product={"ma_san_pham": 1024, "gia_ban": 299000})
        self.assertEqual(current_pricing("1024", NOW, db=db)["regular_price"], 299000)
        self.assertIsNone(current_pricing("999", NOW, db=db))


def claim_b():
    return claim_from_pricing("1024", resolve_pricing("1024", dict(PRODUCT), NOW, db=make_db(campaign())))


class PriceClaimStateTests(unittest.TestCase):
    def state(self, product=None, *campaigns, now=NOW, claim=None):
        claim = claim if claim is not None else claim_b()
        cur = current_pricing("1024", now, db=make_db(*campaigns, product=product))
        return price_claim_state(claim, cur, int(now.timestamp()))

    def test_claim_shape(self):
        self.assertEqual(claim_b(), {
            "product_id": "1024", "regular_price": 299000, "market_price": 350000,
            "flash": {"campaign_id": 12, "flash_price": 249000, "end_ts": int(END_B.timestamp())},
            "valid_until_ts": int(END_B.timestamp()),
        })
        self.assertIsNone(claim_from_pricing("1024", None))

    def test_unchanged_ok(self):
        self.assertIsNone(self.state(None, campaign()))

    def test_regular_changed(self):
        self.assertEqual(self.state({**PRODUCT, "gia_ban": 279000}, campaign()), "regular_changed")

    def test_market_changed(self):
        self.assertEqual(self.state({**PRODUCT, "gia_thi_truong": 360000}, campaign()), "market_changed")

    def test_flash_ended(self):
        later = dt.datetime(2026, 10, 17, 17, 0, tzinfo=UTC)
        self.assertEqual(self.state(None, campaign(), now=later), "flash_ended")

    def test_campaign_disabled(self):
        self.assertEqual(self.state(None, campaign(is_active=False, enabled=False)), "flash_changed")

    def test_flash_price_changed(self):
        c = campaign(items=[{"ma_san_pham": "1024", "flash_price": 239000}])
        self.assertEqual(self.state(None, c), "flash_changed")

    def test_claim_without_flash_then_flash_starts_ok(self):
        claim_a = claim_from_pricing("1024", resolve_pricing("1024", dict(PRODUCT), NOW, db=make_db()))
        self.assertIsNone(self.state(None, campaign(), claim=claim_a))

    def test_claim_none_is_ok(self):
        self.assertIsNone(price_claim_state(None, None, 0))

    def test_current_none_with_claim(self):
        self.assertEqual(price_claim_state(claim_b(), None, int(NOW.timestamp())), "regular_changed")


class CollectorTests(unittest.TestCase):
    def test_collector_attaches_pricing(self):
        from engines import product_collector
        db = make_db(campaign())
        with patch.object(product_collector, "get_db", return_value=db), \
             patch("core.state_machine.get_db", return_value=db), \
             patch.object(product_collector, "utcnow", return_value=NOW):
            snap = product_collector.collect_product_and_snapshot("1024")
        self.assertEqual(snap["pricing"]["flash"]["flash_price"], 249000)

    def test_collector_pricing_failure_is_none(self):
        from engines import product_collector
        db = make_db()
        with patch.object(product_collector, "get_db", return_value=db), \
             patch.object(product_collector, "resolve_pricing", side_effect=RuntimeError("x")):
            snap = product_collector.collect_product_and_snapshot("1024")
        self.assertIsNone(snap["pricing"])


if __name__ == "__main__":
    unittest.main()
