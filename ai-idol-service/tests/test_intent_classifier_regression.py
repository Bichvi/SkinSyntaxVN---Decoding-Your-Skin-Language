"""Regression tests for AI Idol Agent intent collisions."""

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))

from agents.intent_classifier import (  # noqa: E402
    CANCEL_CAMPAIGN,
    CREATE_CAMPAIGN,
    PRODUCT_SEARCH,
    classify_intent,
)


class IntentCollisionRegressionTests(unittest.TestCase):
    def test_usage_word_never_means_cancel(self):
        searches = [
            "Tìm kem nền còn hạn sử dụng từ 17 đến 19 tháng.",
            "Lọc kem chống nắng còn hạn sử dụng từ 17 đến 19 tháng.",
            "Lọc 10 serum có thời hạn sử dụng dưới 18 tháng.",
            "Tìm sản phẩm có hướng dẫn sử dụng.",
            "Lọc kho theo ngày sử dụng và hạn dùng.",
        ]
        for text in searches:
            with self.subTest(text=text):
                self.assertEqual(classify_intent(text).intent, PRODUCT_SEARCH)

    def test_explicit_stop_commands_still_cancel(self):
        commands = [
            "Hủy campaign.",
            "Cancel campaign.",
            "Xóa lịch livestream.",
            "Dừng livestream ngay.",
            "Dừng live.",
            "Hãy dừng phát chương trình.",
        ]
        for text in commands:
            with self.subTest(text=text):
                self.assertEqual(classify_intent(text).intent, CANCEL_CAMPAIGN)

    def test_create_command_with_expiry_remains_create(self):
        result = classify_intent(
            "Lọc kem nền còn hạn sử dụng từ 17 đến 19 tháng rồi tạo livestream lúc 19h."
        )
        self.assertEqual(result.intent, CREATE_CAMPAIGN)


if __name__ == "__main__":
    unittest.main()
