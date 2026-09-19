"""Offline acceptance suite for the consultation boundary.

It intentionally uses a fake catalog and generator: the point is to prove the
business rules independent of network keys, MongoDB and a particular model.
Run with: python -m unittest discover -s ai-service-flask/tests -p 'test_*.py'
"""
from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from chatbot_service.consultation.planning import QueryPlan
from chatbot_service.consultation.runtime import RulePlanner
from chatbot_service.consultation.service import ConsultationService
from chatbot_service.consultation.context import product_metadata
from chatbot_service.consultation.money import parse_budget
from chatbot_service.consultation.ingredients import extract_exclusions


class Doc:
    def __init__(self, pid, **metadata):
        self.id = "product_" + pid
        self.metadata = {"id": pid, "ingredients_complete": True, **metadata}
        self.page_content = self.metadata.get("mo_ta", "")


class Catalog:
    def __init__(self):
        self.docs = [
            Doc("clean-1", ten_san_pham="Gel rửa mặt dịu nhẹ", thuong_hieu="CeraVe", gia_ban=220000, loai_san_pham="Sữa Rửa Mặt", loai_da="Da dầu/Hỗn hợp dầu", thanh_phan_chinh="ceramide niacinamide"),
            Doc("moist-1", ten_san_pham="Kem dưỡng phục hồi", thuong_hieu="Simple", gia_ban=280000, loai_san_pham="Kem / Gel / Dầu Dưỡng", loai_da="Da nhạy cảm", thanh_phan_chinh="ceramide glycerin"),
            Doc("spf-1", ten_san_pham="Kem chống nắng nhẹ mặt", thuong_hieu="Skin1004", gia_ban=360000, loai_san_pham="Chống Nắng Da Mặt", loai_da="Da dầu/Hỗn hợp dầu", thanh_phan_chinh="centella"),
            Doc("retinol-1", ten_san_pham="Retinol serum", thuong_hieu="Test", gia_ban=350000, loai_san_pham="Serum / Tinh Chất", loai_da="Da dầu/Hỗn hợp dầu", thanh_phan_chinh="retinol", ingredients_complete=True),
            Doc("unknown-1", ten_san_pham="Serum thiếu INCI", thuong_hieu="Test", gia_ban=150000, loai_san_pham="Serum / Tinh Chất", loai_da="Da dầu/Hỗn hợp dầu", thanh_phan_chinh="" , ingredients_complete=False),
        ]

    def document(self, metadata):
        d = Doc(metadata["id"], **metadata)
        return d

    def search(self, query, filters=None, limit=10):
        results = self.docs
        if filters:
            results = [d for d in results if all(d.metadata.get(k) == v for k, v in filters.items())]
        return results[:limit]


class FakePlanner:
    def plan(self, message, history, current_product_id=None):
        return RulePlanner().plan(message, history, current_product_id)


class EvidenceGenerator:
    def __init__(self):
        self.calls = []

    def __call__(self, instruction, evidence_text):
        data = json.loads(evidence_text)
        self.calls.append(data)
        budget = data["context"].get("budget_vnd")
        catalog = data.get("catalog", [])
        if budget is None:
            answer = "Mình chưa có ngân sách được bạn nêu, nên chưa dùng ngân sách để lọc."
        else:
            answer = f"Ngân sách được dùng là {budget:,} VNĐ."
        if catalog:
            answer += " Dữ liệu tham chiếu chỉ gồm sản phẩm trong danh sách."
        return answer


def make_service():
    gen = EvidenceGenerator()
    return ConsultationService(FakePlanner(), Catalog(), gen, lambda _: []), gen


CASES = [
    # guest: no profile, no hidden budget
    ("guest", "hi", {}, None),
    ("guest", "tìm sản phẩm cho da dầu", {}, None),
    ("guest", "cho tôi 2 chai retinol đáng mua cho da dầu", {}, None),
    ("guest", "niacinamide là gì", {}, None),
    ("guest", "routine sáng tối gồm những bước nào", {}, None),
    ("guest", "giá vàng hôm nay", {}, None),
    ("guest", "tìm sữa rửa mặt dưới 300k", {}, 300000),
    ("guest", "tìm serum 1,5 triệu", {}, 1500000),
    ("guest", "tìm kem dưỡng không chứa retinol", {}, None),
    ("guest", "tìm kem chống nắng không cồn", {}, None),
    ("guest", "tôi muốn mua sản phẩm cho mẹ da khô", {}, None),
    ("guest", "tìm sản phẩm 300–500k", {}, 500000),
    ("guest", "ngân sách không giới hạn, tìm serum", {}, None),
    ("guest", "tìm routine 350k", {}, 350000),
    ("guest", "tìm sản phẩm cho da nhạy cảm", {}, None),
    ("guest", "tôi dị ứng hương liệu", {}, None),
    ("guest", "tìm sản phẩm có vitamin c", {}, None),
    ("guest", "có nên dùng BHA không", {}, None),
    ("guest", "sản phẩm này có cồn không", {"current_product_id": "retinol-1"}, None),
    ("guest", "cảm ơn", {}, None),
    # logged in without profile
    ("no_profile", "tìm sản phẩm cho da dầu", {"customer_profile": {}}, None),
    ("no_profile", "tìm sữa rửa mặt dưới 300k", {"customer_profile": {}}, 300000),
    ("no_profile", "routine da dầu mụn", {"customer_profile": {}}, None),
    ("no_profile", "retinol dùng sao cho an toàn", {"customer_profile": {}}, None),
    ("no_profile", "tìm serum", {"customer_profile": {}}, None),
    ("no_profile", "tìm kem dưỡng không chứa retinol", {"customer_profile": {}}, None),
    ("no_profile", "tìm sản phẩm cho mẹ da khô", {"customer_profile": {}}, None),
    ("no_profile", "kiểm tra giỏ hàng", {"customer_profile": {}, "cart_conflicts": []}, None),
    ("no_profile", "tìm routine 350k", {"customer_profile": {}}, 350000),
    ("no_profile", "tìm routine", {"customer_profile": {}}, None),
    ("no_profile", "tìm 2 serum", {"customer_profile": {}}, None),
    ("no_profile", "tìm sản phẩm 500.000đ", {"customer_profile": {}}, 500000),
    ("no_profile", "tìm sản phẩm hơn 1 triệu", {"customer_profile": {}}, None),
    ("no_profile", "chào bạn", {"customer_profile": {}}, None),
    ("no_profile", "BHA là gì", {"customer_profile": {}}, None),
    ("no_profile", "tôi da khô", {"customer_profile": {}}, None),
    ("no_profile", "tránh cồn giúp tôi", {"customer_profile": {}}, None),
    ("no_profile", "tìm CeraVe", {"customer_profile": {}}, None),
    ("no_profile", "tìm kem dưỡng", {"customer_profile": {}}, None),
    ("no_profile", "tôi chưa biết da mình thuộc loại nào", {"customer_profile": {}}, None),
    # logged in with profile
    ("profile", "tìm sản phẩm phù hợp", {"customer_profile": {"skin_type": "Da dầu/Hỗn hợp dầu", "budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "tìm serum", {"customer_profile": {"skin_type": "Da dầu/Hỗn hợp dầu", "budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "tìm kem dưỡng", {"customer_profile": {"skin_type": "Da nhạy cảm", "avoid_ingredients": ["cồn"], "budget": 500000, "budget_mode": "bounded"}}, 500000),
    ("profile", "tìm sản phẩm cho mẹ da khô", {"customer_profile": {"skin_type": "Da dầu/Hỗn hợp dầu", "budget": 350000, "budget_mode": "bounded"}}, None),
    ("profile", "tìm sản phẩm", {"customer_profile": {"skin_type": "Da dầu/Hỗn hợp dầu", "budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "tìm routine", {"customer_profile": {"skin_type": "Da dầu/Hỗn hợp dầu", "budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "tìm sữa rửa mặt", {"customer_profile": {"skin_type": "Da dầu/Hỗn hợp dầu", "budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "tìm serum dưới 200k", {"customer_profile": {"budget": 350000, "budget_mode": "bounded"}}, 200000),
    ("profile", "ngân sách không giới hạn, tìm routine", {"customer_profile": {"budget": 350000, "budget_mode": "bounded"}}, None),
    ("profile", "tìm sản phẩm", {"customer_profile": {"budget": None, "budget_mode": "unknown"}}, None),
    ("profile", "tìm sản phẩm", {"customer_profile": {"budget": 0, "budget_mode": "unknown"}}, None),
    ("profile", "tìm sản phẩm", {"customer_profile": {"budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "tìm sản phẩm", {"customer_profile": {"budget": 1000000, "budget_mode": "bounded"}}, 1000000),
    ("profile", "tìm sản phẩm cho da khô", {"customer_profile": {"skin_type": "Da dầu/Hỗn hợp dầu", "budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "tìm sản phẩm không hương liệu", {"customer_profile": {"budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "tìm sản phẩm không chứa retinol", {"customer_profile": {"budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "tìm sản phẩm cho da nhạy cảm", {"customer_profile": {"budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "giải thích hồ sơ của tôi", {"customer_profile": {"skin_type": "Da dầu/Hỗn hợp dầu", "budget": 350000, "budget_mode": "bounded"}}, 350000),
    ("profile", "kiểm tra giỏ hàng", {"customer_profile": {"budget": 350000, "budget_mode": "bounded"}, "cart_conflicts": [{"product_a": "A", "product_b": "B"}]}, 350000),
    # A social acknowledgement no longer invokes a model or filters products.
    ("profile", "cảm ơn", {"customer_profile": {"budget": 350000, "budget_mode": "bounded"}}, None),
    # product and constraint details
    ("product", "sản phẩm này có retinol không", {"current_product_id": "retinol-1", "retrieved_products": [{"id": "retinol-1", "name": "Retinol serum", "price": 350000, "category": "Serum / Tinh Chất", "ingredients": "retinol", "ingredients_complete": True}]}, None),
    ("product", "cái này giá bao nhiêu", {"current_product_id": "retinol-1", "retrieved_products": [{"id": "retinol-1", "name": "Retinol serum", "price": 350000, "category": "Serum / Tinh Chất", "ingredients": "retinol", "ingredients_complete": True}]}, None),
    ("product", "cách dùng sản phẩm này", {"current_product_id": "retinol-1", "retrieved_products": [{"id": "retinol-1", "name": "Retinol serum", "price": 350000, "category": "Serum / Tinh Chất", "ingredients": "retinol", "ingredients_complete": True}]}, None),
    ("product", "sản phẩm này có hợp da dầu không", {"current_product_id": "retinol-1", "retrieved_products": [{"id": "retinol-1", "name": "Retinol serum", "price": 350000, "category": "Serum / Tinh Chất", "ingredients": "retinol", "ingredients_complete": True}]}, None),
    ("product", "sản phẩm này có cồn không", {"current_product_id": "retinol-1", "retrieved_products": [{"id": "retinol-1", "name": "Retinol serum", "price": 350000, "category": "Serum / Tinh Chất", "ingredients": "retinol", "ingredients_complete": True}]}, None),
    ("product", "sản phẩm này so với CeraVe", {"current_product_id": "clean-1", "retrieved_products": [{"id": "clean-1", "name": "Gel rửa mặt", "price": 220000, "category": "Sữa Rửa Mặt", "ingredients": "ceramide", "ingredients_complete": True}]}, None),
    ("product", "tôi muốn sản phẩm không chứa retinol", {"current_product_id": "retinol-1", "retrieved_products": [{"id": "retinol-1", "name": "Retinol serum", "price": 350000, "category": "Serum / Tinh Chất", "ingredients": "retinol", "ingredients_complete": True}]}, None),
    ("product", "sản phẩm này dùng sáng hay tối", {"current_product_id": "retinol-1", "retrieved_products": [{"id": "retinol-1", "name": "Retinol serum", "price": 350000, "category": "Serum / Tinh Chất", "ingredients": "retinol", "ingredients_complete": True}]}, None),
    ("product", "cho tôi link sản phẩm này", {"current_product_id": "retinol-1", "retrieved_products": [{"id": "retinol-1", "name": "Retinol serum", "price": 350000, "category": "Serum / Tinh Chất", "ingredients": "retinol", "ingredients_complete": True}]}, None),
    ("product", "sản phẩm này có đủ bảng thành phần không", {"current_product_id": "unknown-1", "retrieved_products": [{"id": "unknown-1", "name": "Serum thiếu INCI", "price": 150000, "category": "Serum / Tinh Chất", "ingredients": "", "ingredients_complete": False}]}, None),
    ("product", "đề xuất retinol dưới 300k", {"retrieved_products": [], "current_product_id": ""}, 300000),
    ("product", "đề xuất retinol 350k", {"retrieved_products": [], "current_product_id": ""}, 350000),
    ("product", "đề xuất serum 1.200.000đ", {"retrieved_products": [], "current_product_id": ""}, 1200000),
    ("product", "tìm sản phẩm với vitamin c", {}, None),
    ("product", "tìm sản phẩm tránh alcohol", {}, None),
    ("product", "tìm sản phẩm tránh benzyl alcohol", {}, None),
    ("product", "tìm sản phẩm có BHA", {}, None),
    ("product", "tìm sản phẩm da dầu mụn", {}, None),
    ("product", "tìm 3 sản phẩm", {}, None),
    ("product", "tìm theo thương hiệu CeraVe", {}, None),
    ("product", "tìm sản phẩm hết hàng", {}, None),
    ("product", "routine 350k", {}, 350000),
    ("product", "routine dưới 300k", {}, 300000),
    ("product", "routine không giới hạn", {}, None),
    ("product", "routine da dầu dưới 1 triệu", {}, 1000000),
    ("product", "routine cho mẹ da khô 500k", {}, 500000),
    ("product", "tìm sản phẩm 1,5 triệu", {}, 1500000),
    ("product", "tìm sản phẩm 1.5tr", {}, 1500000),
    ("product", "tìm sản phẩm dưới 500.000đ", {}, 500000),
    ("edge", "tìm serum tầm 500k", {}, 500000),
    ("edge", "tìm serum khoảng 500 nghìn", {}, 500000),
    ("edge", "tìm serum không quá 500k", {}, 500000),
    ("edge", "tìm serum từ 300 đến 500k", {}, 500000),
    ("edge", "tìm serum trên 500k", {}, None),
    ("edge", "tìm serum tối thiểu 500k", {}, None),
    ("edge", "tìm serum 500.000 đồng", {}, 500000),
    ("edge", "tìm serum 2 triệu", {}, 2000000),
    ("edge", "tìm serum 2tr", {}, 2000000),
    ("edge", "tìm serum 500k, không hương liệu", {}, 500000),
    ("edge", "tìm routine cho bạn mình da khô", {"customer_profile": {"skin_type": "Da dầu/Hỗn hợp dầu", "budget": 350000, "budget_mode": "bounded"}}, None),
]


class ConsultationAcceptanceTest(unittest.TestCase):
    def test_exactly_100_cases(self):
        self.assertEqual(len(CASES), 100)

    def test_budget_parser_regressions(self):
        self.assertIsNone(parse_budget("cho tôi 2 chai retinol đáng mua cho da dầu"))
        self.assertEqual(parse_budget("ngân sách 1,5 triệu"), 1500000)
        self.assertEqual(parse_budget("dưới 300k"), 300000)
        self.assertIsNone(parse_budget("trên 1 triệu"))

    def test_ingredient_exclusion_regressions(self):
        self.assertIn("retinol", extract_exclusions("kem dưỡng không chứa retinol"))
        self.assertIn("cồn", extract_exclusions("serum tránh cồn"))

    def test_bm25_does_not_score_missing_terms(self):
        from chatbot_service.hybrid_search import BM25Search
        index = BM25Search([
            {"id": "has", "content": "retinol retinol", "metadata": {}},
            {"id": "missing", "content": "kem dưỡng", "metadata": {}},
        ])
        self.assertEqual(index._calculate_bm25(["retinol"], "missing"), 0.0)
        self.assertEqual(index.search("retinol")[0][0], "has")

    def test_each_real_case(self):
        failures = []
        for index, (state, message, data, expected_budget) in enumerate(CASES, 1):
            service, generator = make_service()
            result = service.respond(message, data)
            context = generator.calls[-1]["context"] if generator.calls else {}
            actual = context.get("budget_vnd")
            if actual != expected_budget:
                failures.append(f"#{index} [{state}] {message!r}: expected budget {expected_budget!r}, got {actual!r}")
            if expected_budget is None and "ngân sách hiện tại là 350.000" in result["answer"].lower():
                failures.append(f"#{index}: hallucinated 350k budget")
            if not result.get("ok") or not result.get("answer"):
                failures.append(f"#{index}: empty/failed response")
        self.assertFalse(failures, "\n".join(failures))

    def test_flask_guest_endpoint_uses_the_same_contract(self):
        # Route-level smoke test: the PHP BFF sends a flat message in this
        # shape. No MongoDB or external model is needed for the assertion.
        from chatbot_service.chatbot_flask import app
        service, _ = make_service()
        # The production route currently delegates to the legacy-compatible
        # xu_ly_cau_hoi adapter; inject the pure service at that boundary.
        with patch("chatbot_service.chatbot_flask.xu_ly_cau_hoi",
                   side_effect=lambda message, data=None: service.respond(message, data)):
            response = app.test_client().post(
                "/api/chat/auto",
                json={"message": "cho tôi 2 chai retinol đáng mua cho da dầu"},
            )
        self.assertEqual(response.status_code, 200)
        payload = response.get_json()
        self.assertTrue(payload["ok"])
        # A real catalog price may be 350k; only an invented user budget is wrong.
        self.assertNotIn("ngân sách hiện tại là 350.000", payload["answer"].lower())
        self.assertNotIn("ngân sách của bạn là 350.000", payload["answer"].lower())


if __name__ == "__main__":
    unittest.main(verbosity=2)
