from types import SimpleNamespace

import pytest

import structured_recommendation as service


def product(pid, skin, **extra):
    return {"id": str(pid), "ma_san_pham": pid, "ten_san_pham": "Serum dưỡng da",
            "loai_da": skin, "loai_san_pham": "Serum / Tinh Chất", "gia_ban": 150000,
            "thanh_phan_day_du": "Glycerin", **extra}


@pytest.mark.parametrize(("skin", "matching", "incompatible"), [
    ("da dầu", "Da dầu/Hỗn hợp dầu", "Da khô/Hỗn hợp khô"),
    ("da khô", "Da khô/Hỗn hợp khô", "Da dầu/Hỗn hợp dầu"),
    ("da nhạy cảm", "Da nhạy cảm", "Da mụn"),
    ("da mụn", "Da dầu mụn", "Da dầu"),
    ("da thường", "Da thường", "Da khô"),
    ("da hỗn hợp", "Da hỗn hợp", "Da hỗn hợp thiên dầu"),
])
def test_skin_contract(skin, matching, incompatible):
    rules = {"skin_type": skin}
    assert service.validate_recommendation(product(1, matching), rules) == (True, None)
    assert service.validate_recommendation(product(2, "Da thường/Mọi loại da"), rules) == (True, None)
    assert not service.validate_recommendation(product(3, incompatible), rules)[0]
    assert not service.validate_recommendation(product(4, ""), rules)[0]


def test_exact_mongo_candidates_outrank_universal_and_do_not_fill_with_wrong_skin(monkeypatch):
    universal = product(1, "Mọi loại da")
    wrong = product(2, "Da khô/Hỗn hợp khô")
    exact = product(3, "Da dầu/Hỗn hợp dầu")
    avoid = {**product(4, "Da dầu"), "thanh_phan_day_du": "Retinol"}
    over_budget = {**product(5, "Da dầu"), "gia_ban": 900000}
    calls = []

    def find(query, **kwargs):
        calls.append(query)
        if not kwargs:
            return [universal, wrong]
        return [exact, avoid, over_budget] if "oily" in str(query) else [universal]

    db = SimpleNamespace(command=lambda _cmd: None, san_pham=SimpleNamespace(find=find))
    pipeline = SimpleNamespace(search=lambda **_: ([SimpleNamespace(doc_id="product_1"), SimpleNamespace(doc_id="2")], None))
    monkeypatch.setattr(service, "get_database", lambda **_: db)
    monkeypatch.setattr(service, "get_hybrid_pipeline", lambda: pipeline)
    monkeypatch.setattr(service, "normalize_product", lambda row, _db: row)
    result = service.generate_structured_recommendation({
        "user_profile": {"skin_type": "da dầu", "budget": 250000, "avoid_ingredients": ["retinol"],
                         "concerns": [{"label": "Glycerin"}, "dưỡng da"]},
    }, limit=4)
    assert [p["product_id"] for p in result["products"]] == ["3", "1"]
    assert "product_1" not in str(calls[0]) and "'1', '2', 1, 2" in str(calls[0])
    assert result["extracted_filters"]["concerns"] == ["dưỡng da"]
    reasons = [row["reason"] for row in result["debug_trace"]["rejected_candidates"]]
    assert any("skin type mismatch" in reason for reason in reasons)
    assert any("avoid ingredient" in reason for reason in reasons)
    assert any("max_price" in reason for reason in reasons)
