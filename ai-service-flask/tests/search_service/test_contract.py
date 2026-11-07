import json
from pathlib import Path

import pytest

from search_service.ranker import Candidate
from search_service.schemas import validate_request
from search_service.service import SearchRecommendService

jsonschema = pytest.importorskip("jsonschema")

SCHEMA = json.loads(
    (Path(__file__).resolve().parents[2] / "search_service" / "contract" / "recommend_response.schema.json")
    .read_text(encoding="utf-8")
)


def test_service_output_matches_contract():
    def sem(req, intent):
        return [Candidate("1496", 0.7, "semantic_match", "semantic")]

    def alt(req, intent):
        return [Candidate("102", 3, "shared_ingredients", "category_alt", ("Hyaluronic Acid", "Panthenol"))]

    def bad(req, intent):
        raise RuntimeError("x")

    out = SearchRecommendService({"semantic": sem, "category_alt": alt, "same_brand": bad}).recommend(
        validate_request({"q": "torriden sữa rửa mặt"}))
    jsonschema.validate(out, SCHEMA)
    assert out["degraded"] is True


def test_contract_example_with_intent_valid():
    example = {
        "mode": "fallback", "degraded": False, "degraded_branches": [], "took_ms": 214,
        "intent": {"brands": ["torriden"], "categories": ["sua_rua_mat"], "skin_types": [], "concerns": [],
                   "confidence": 0.95},
        "sections": [{"key": "category_alt", "items": [{
            "product_id": "102", "score": 0.83, "reason_code": "shared_ingredients",
            "reason_params": ["Hyaluronic Acid", "Panthenol"]}]}],
    }
    jsonschema.validate(example, SCHEMA)


def test_contract_rejects_unknown_section_and_empty_section():
    base = {"mode": "fallback", "degraded": False, "degraded_branches": [], "took_ms": 1, "intent": None}
    item = {"product_id": "1", "score": 1, "reason_code": "trending", "reason_params": []}
    with pytest.raises(jsonschema.ValidationError):
        jsonschema.validate({**base, "sections": [{"key": "other", "items": [item]}]}, SCHEMA)
    with pytest.raises(jsonschema.ValidationError):
        jsonschema.validate({**base, "sections": [{"key": "trending", "items": []}]}, SCHEMA)


def test_mode_b_parsed_intent_matches_contract(monkeypatch):
    """Bước 05B: the real parser output (+ display names) satisfies the v1 contract."""

    from search_service import dictionary, service
    from search_service.dictionary import EntityDictionary

    monkeypatch.setattr(dictionary, "get_default_dictionary", lambda: EntityDictionary(driver=None, mongo_db=None))

    def alt(req, intent):
        return [Candidate("587", 3, "shared_ingredients", "category_alt", ("Sodium Hyaluronate",))]

    svc = SearchRecommendService({"category_alt": alt}, intent_parser=service.parse_intent)
    for q in ("sữa rửa mặt cho da dầu dưới 300k", "xyzabc"):
        out = svc.recommend(validate_request({"q": q}))
        jsonschema.validate(out, SCHEMA)
        assert isinstance(out["intent"], dict) and "category_names" in out["intent"]
