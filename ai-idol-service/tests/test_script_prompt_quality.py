import os
import sys
import unittest


SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import engines.content_agent as content_agent
from config import GROQ_MAX_PROMPT_CHARS
from core.text_budget import clean_prompt_text, clean_text, fit_message_pair

PRODUCT_VIDEO_HEADERS = ["[Hook]", "[Problem]", "[Product Intro]", "[Specs]", "[Ingredients]", "[How to Use]", "[Wrap-up]"]
LIVE_HEADERS = ["[Opening]", "[Hook]", "[Product Intro]", "[Key Benefits]", "[How to Use]", "[Price]", "[CTA]", "[Closing]"]
PRICING = {"regular_price": 299000, "market_price": 350000}
DESCRIPTION = "Mo ta cong dung rat dai cua san pham dung de kiem tra viec cat. " * 80


def big_snapshot():
    return {
        "name": "Serum A", "brand": "B", "category": "Serum", "origin": "VN", "pricing": PRICING,
        "ingredients": "Niacinamide, Zinc PCA, Panthenol, " * 120,
        "description": DESCRIPTION[:5000],
        "usage": "Sáng tối " * 100, "skin_type": "Da dầu",
    }


class FitMessagePairTests(unittest.TestCase):
    def test_newlines_survive_under_budget(self):
        system, user = fit_message_pair("a\n\n\n\nb", "[Hook]\nxin   chao\r\nbye", 1000)
        self.assertEqual(system, "a\n\nb")
        self.assertEqual(user, "[Hook]\nxin chao\nbye")

    def test_newlines_survive_over_budget(self):
        system = "dòng hệ thống\n" * 300
        user = "dòng người dùng\n" * 600
        out_system, out_user = fit_message_pair(system, user, 3000)
        self.assertLessEqual(len(out_system) + len(out_user), 3000)
        self.assertIn("\n", out_system)
        self.assertIn("\n", out_user)

    def test_clean_text_still_collapses_newlines(self):
        self.assertEqual(clean_text("a\n\nb"), "a b")


class BuildPromptBudgetTests(unittest.TestCase):
    def prompts(self, purpose):
        return content_agent.build_script_prompts(big_snapshot(), "Kiến thức bổ trợ dài. " * 300, "Product Intro", {}, purpose)

    def check(self, purpose):
        system, user = self.prompts(purpose)
        total = len(clean_prompt_text(system)) + len(clean_prompt_text(user))
        self.assertLessEqual(total, GROQ_MAX_PROMPT_CHARS - 200)
        out_system, out_user = fit_message_pair(system, user, GROQ_MAX_PROMPT_CHARS)
        self.assertNotIn("[đã rút gọn]", out_system)
        self.assertIn(DESCRIPTION[:50].strip(), out_user)

    def test_product_video_fits_budget_without_midcut_facts(self):
        self.check("product_video")

    def test_live_and_product_fits_budget_without_midcut_facts(self):
        self.check("live_and_product")


class SystemPromptSampleTests(unittest.TestCase):
    def test_product_video_system(self):
        system = content_agent._PRODUCT_VIDEO_SYSTEM
        self.assertIn("ĐOẠN MẪU GIỌNG VĂN", system)
        self.assertIn("[Hook]\n", system)
        for header in PRODUCT_VIDEO_HEADERS:
            self.assertIn(header, system)

    def test_live_and_product_system(self):
        system = content_agent._LIVE_AND_PRODUCT_SYSTEM
        self.assertIn("ĐOẠN MẪU GIỌNG VĂN", system)
        self.assertIn("[Hook]\n", system)
        for header in LIVE_HEADERS:
            self.assertIn(header, system)


if __name__ == "__main__":
    unittest.main()
