import pytest

from home_service import config
from home_service.schemas import HomeRequest, validate_home_request
from search_service.schemas import ValidationError


def test_minimal_payload_defaults():
    req = validate_home_request({"sections": ["concern"]})
    assert isinstance(req, HomeRequest)
    assert req.concern == "tham_mun"
    assert req.limits == {"seen": 4, "skin": 4, "concern": 8}
    assert req.seen_ids == ()
    assert req.skin_type is None


def test_concern_defaults_to_first_profile_concern():
    req = validate_home_request({
        "sections": ["concern"],
        "skin_profile": {"skin_type": "da_dau", "concerns": ["mun", "kho_da"]},
    })
    assert req.concern == "mun"
    assert req.skin_type == "da_dau"


def test_explicit_concern_overrides_profile():
    req = validate_home_request({
        "sections": ["concern"], "concern": "lao_hoa",
        "skin_profile": {"concerns": ["mun"]},
    })
    assert req.concern == "lao_hoa"


@pytest.mark.parametrize("sections", [["foo"], []])
def test_unknown_section_rejected(sections):
    with pytest.raises(ValidationError) as exc:
        validate_home_request({"sections": sections})
    assert exc.value.field == "sections"


def test_duplicate_sections_deduplicated():
    req = validate_home_request({"sections": ["skin", "seen", "skin"]})
    assert req.sections == ("skin", "seen")


def test_invalid_ids_dropped():
    req = validate_home_request({
        "sections": ["seen"],
        "seen_ids": ["12", "abc", "", "9" * 13, "12", "34"],
    })
    assert req.seen_ids == ("12", "34")


def test_list_caps():
    req = validate_home_request({
        "sections": ["seen"],
        "seen_ids": [str(i) for i in range(1, 8)],
        "cart_ids": [str(i) for i in range(1, 61)],
        "exclude_ids": [str(i) for i in range(1, 151)],
    })
    assert len(req.seen_ids) == 5
    assert len(req.cart_ids) == 50
    assert len(req.exclude_ids) == 100


def test_bad_concern_rejected():
    with pytest.raises(ValidationError) as exc:
        validate_home_request({"sections": ["concern"], "concern": "xyz"})
    assert exc.value.field == "concern"


def test_unknown_skin_type_ignored_and_concerns_filtered():
    req = validate_home_request({
        "sections": ["skin"],
        "skin_profile": {"skin_type": "weird", "concerns": ["nope", "mun"]},
    })
    assert req.skin_type is None
    assert req.concerns == ("mun",)


def test_limits_clamped():
    req = validate_home_request({"sections": ["concern"], "limits": {"concern": 50, "seen": 0}})
    assert req.limits["concern"] == 12
    assert req.limits["seen"] == 4
    assert req.limits["skin"] == 4


@pytest.mark.parametrize("payload", [None, [], "x"])
def test_payload_not_dict(payload):
    with pytest.raises(ValidationError) as exc:
        validate_home_request(payload)
    assert exc.value.field == "body"


def test_config_constants():
    assert config.SECTION_KEYS == ("seen", "skin", "concern")
    assert len(config.CONCERN_KEYS) == 9
    assert len(config.SKIN_TYPE_KEYS) == 5
