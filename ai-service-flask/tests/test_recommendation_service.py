"""Pure recommendation-engine regression tests with no external services."""

from __future__ import annotations

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from recommendation_service.hybrid_search import HybridHit
from recommendation_service.pipeline import RecommendationPipeline
from recommendation_service.reranker import Reranker
from recommendation_service.schemas import RecommendRequest
from recommendation_service.safety_filter import SafetyFilter


class FakeRepository:
    def __init__(self, products):
        self.products = products

    def list_visible(self):
        return list(self.products)

    def get_user_context(self, user_id, email=""):
        return {}

    def get_history(self, user_id, email=""):
        return []


class FakeSearch:
    def __init__(self, products):
        self.repository = FakeRepository(products)

    def search(self, query, limit=24, user_id=""):
        return [HybridHit(product=dict(product), rrf_score=1 / (60 + i)) for i, product in enumerate(self.repository.products)]


class FakeReranker:
    def rerank(self, query, candidates, limit=8):
        return [{**product, "rerank_score": 1.0 / (index + 1), "match_score": 90 - index} for index, product in enumerate(candidates[:limit])]


def product(product_id, name, category, price, ingredients=""):
    return {
        "id": product_id,
        "name": name,
        "ten_san_pham": name,
        "category": category,
        "loai_san_pham": category,
        "price": price,
        "gia_ban": price,
        "ingredients": ingredients,
        "thanh_phan_chinh": ingredients,
        "ingredients_full": ingredients,
        "thanh_phan_day_du": ingredients,
        "stock_status": "in_stock",
        "key_ingredients": [part for part in ingredients.split(",") if part],
    }


def test_avoid_ingredients_are_hard_filtered():
    profile = type("Profile", (), {"avoid_ingredients": ["fragrance"]})()
    decision = SafetyFilter().filter_candidates(
        [product("1", "Toner", "toner", 100000, "Aqua, Fragrance")], profile, "toner"
    )
    assert decision.candidates == []
    assert decision.rejected[0]["reason"].startswith("avoid_ingredients:")


def test_pipeline_returns_versioned_am_pm_schema_and_total_budget():
    catalog = [
        product("c", "Gel rửa mặt dịu nhẹ", "Sữa rửa mặt", 80000, "Aqua, Glycerin"),
        product("m", "Kem dưỡng phục hồi", "Kem dưỡng", 120000, "Ceramide, Panthenol"),
        product("s", "Kem chống nắng SPF50", "Chống nắng", 150000, "Zinc Oxide"),
        product("t", "Serum niacinamide", "Serum", 130000, "Niacinamide"),
    ]
    pipeline = RecommendationPipeline(search=FakeSearch(catalog), reranker=FakeReranker())
    response = pipeline.recommend(RecommendRequest.from_payload({"user_id": "7", "skin_type": "da dầu", "budget": 600000}))
    data = response.to_dict()
    assert data["ok"] is True
    assert data["schema_version"] == "1.0"
    assert data["am_routine"] and data["pm_routine"]
    assert data["combo"]["total_price"] <= 600000


def test_request_aliases_are_normalized():
    request = RecommendRequest.from_payload({"session_user_id": 12, "concerns": "mụn, thâm", "sensitivity": "cao", "budget": "500.000đ"})
    assert request.user_id == "12"
    assert request.skin_concerns == ["mụn", "thâm"]
    assert request.sensitive_level == "cao"
    assert request.budget == 500000
