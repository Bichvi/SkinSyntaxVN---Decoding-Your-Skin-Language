import pytest

from search_service.schemas import ValidationError, validate_request


def field_of(payload):
    with pytest.raises(ValidationError) as exc:
        validate_request(payload)
    return exc.value.field


def test_valid_minimal_and_full():
    req = validate_request({"q": "  torriden sữa rửa mặt  "})
    assert req.q == "torriden sữa rửa mặt" and req.limit_per_section == 8
    req = validate_request({
        "q": "srm", "user_hash": "ab" * 32,
        "skin_profile": {"skin_type": "da_kho", "concerns": ["mun"]},
        "cart_ids": ["123", 45], "exclude_ids": ["a_b-1"], "limit_per_section": 20,
    })
    assert req.cart_ids == ("123", "45") and req.skin_profile["skin_type"] == "da_kho"


@pytest.mark.parametrize("payload,field", [
    ({"q": ""}, "q"),
    ({"q": "   "}, "q"),
    ({"q": "x" * 201}, "q"),
    ({}, "q"),
    ({"q": 5}, "q"),
    ({"q": "ok", "cart_ids": ["12;drop"]}, "cart_ids"),
    ({"q": "ok", "exclude_ids": ["x" * 33]}, "exclude_ids"),
    ({"q": "ok", "cart_ids": [str(i) for i in range(51)]}, "cart_ids"),
    ({"q": "ok", "cart_ids": "123"}, "cart_ids"),
    ({"q": "ok", "limit_per_section": 0}, "limit_per_section"),
    ({"q": "ok", "limit_per_section": 21}, "limit_per_section"),
    ({"q": "ok", "limit_per_section": True}, "limit_per_section"),
    ({"q": "ok", "skin_profile": "dry"}, "skin_profile"),
    ({"q": "ok", "user_hash": "not hex!"}, "user_hash"),
])
def test_invalid(payload, field):
    assert field_of(payload) == field


def test_body_not_object():
    assert field_of(["q"]) == "body"


def test_q_200_ok():
    assert len(validate_request({"q": "x" * 200}).q) == 200
