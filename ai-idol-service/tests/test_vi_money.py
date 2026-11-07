import os
import sys
import unittest
from datetime import datetime, timezone


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

from core import vi_money


def ts(iso):
    return int(datetime.fromisoformat(iso.replace("Z", "+00:00")).astimezone(timezone.utc).timestamp())


class NumberWordsTests(unittest.TestCase):
    def test_spec_examples(self):
        cases = {299000: "hai trăm chín mươi chín nghìn", 105000: "một trăm linh năm nghìn",
                 215000: "hai trăm mười lăm nghìn", 21000: "hai mươi mốt nghìn",
                 1250000: "một triệu hai trăm năm mươi nghìn", 1005000: "một triệu không trăm linh năm nghìn",
                 249500: "hai trăm bốn mươi chín nghìn năm trăm", 0: "không", 10: "mười", 15: "mười lăm",
                 25: "hai mươi lăm", 1000000: "một triệu"}
        for n, words in cases.items():
            self.assertEqual(vi_money.number_words(n), words, n)

    def test_money_and_percent(self):
        self.assertEqual(vi_money.money_words(249000), "hai trăm bốn mươi chín nghìn đồng")
        self.assertEqual(vi_money.percent_words(17), "giảm mười bảy phần trăm")

    def test_deadline_end_of_day(self):  # 2026-10-17 23:59 giờ VN
        self.assertEqual(vi_money.deadline_words(ts("2026-10-17T16:59:00Z")), "đến hết ngày mười bảy tháng mười")

    def test_deadline_hour_minute_and_early_day(self):
        self.assertEqual(vi_money.deadline_words(ts("2026-04-05T13:00:00Z")), "đến hai mươi giờ ngày mùng năm tháng tư")
        self.assertEqual(vi_money.deadline_words(ts("2026-10-20T02:30:00Z")), "đến chín giờ ba mươi phút ngày hai mươi tháng mười")

    def test_price_phrases(self):
        pricing = {"regular_price": 299000, "market_price": 350000,
                   "flash": {"flash_price": 249000, "discount_percent": 17, "end_ts": ts("2026-10-17T16:59:00Z")}}
        self.assertEqual(vi_money.price_phrases(pricing), {
            "regular": "hai trăm chín mươi chín nghìn đồng", "market": "ba trăm năm mươi nghìn đồng",
            "flash": "hai trăm bốn mươi chín nghìn đồng", "discount": "giảm mười bảy phần trăm",
            "deadline": "đến hết ngày mười bảy tháng mười"})
        self.assertEqual(vi_money.price_phrases({"regular_price": 299000, "market_price": None, "flash": None}),
                         {"regular": "hai trăm chín mươi chín nghìn đồng"})
        self.assertEqual(vi_money.price_phrases(None), {})


if __name__ == "__main__":
    unittest.main()
