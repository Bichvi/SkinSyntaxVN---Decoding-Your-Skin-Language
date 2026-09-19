"""Fast safety-filter checks that do not require databases or model downloads."""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from recommendation_service.safety_filter import SafetyFilter


def test_hard_avoid_ingredient_is_removed() -> None:
    profile = type("Profile", (), {"avoid_ingredients": ["fragrance"]})()
    candidates = [{"id": "1", "name": "Toner", "ingredients": "Aqua, Fragrance"}]

    decision = SafetyFilter().filter_candidates(candidates, profile, "toner")

    assert decision.candidates == []
    assert decision.rejected[0]["reason"] == "avoid_ingredients:fragrance"


def test_makeup_with_sunscreen_claim_is_removed_from_skincare_steps() -> None:
    profile = type("Profile", (), {"avoid_ingredients": []})()
    candidates = [
        {
            "id": "937",
            "name": "Kem nền Maybelline Mịn Nhẹ Kiềm Dầu Chống Nắng #120 30ml",
            "category": "Trang điểm",
        }
    ]

    decision = SafetyFilter().filter_candidates(candidates, profile, "sunscreen")

    assert decision.candidates == []
    assert decision.rejected[0]["reason"] == "category_mismatch:non_skincare"


def test_real_sunscreen_is_kept_for_sunscreen_step() -> None:
    profile = type("Profile", (), {"avoid_ingredients": []})()
    candidates = [{"id": "spf-1", "name": "Kem chống nắng da mặt SPF 50", "category": "Chống nắng"}]

    decision = SafetyFilter().filter_candidates(candidates, profile, "sunscreen")

    assert [item["id"] for item in decision.candidates] == ["spf-1"]
