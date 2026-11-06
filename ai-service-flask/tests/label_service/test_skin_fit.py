import pytest

from label_service.skin_fit import Profile, evaluate, normalize_profile

T1 = [("water", 1), ("glycolic_acid", 3), ("salicylic_acid", 6), ("fragrance", 20)]
S1 = [("water", 1), ("niacinamide", 2), ("panthenol", 3)]
R1 = [("water", 1), ("retinol", 5)]
K1 = [("water", 1), ("alcohol_denat", 2), ("glycerin", 4)]


def P(skin=None, sens=False, *concerns):
    return Profile(skin, sens, frozenset(concerns))


def ids(findings):
    return {f.rule_id for f in findings}


# plan section 8.3 personas
@pytest.mark.parametrize("persona, profile, product, verdict, good, caution, avoid", [
    ("K03 oily", P("dau"), T1, "good", {"oily_bha"}, set(), set()),
    ("K03 oily+sensitive", P("dau", True), T1, "caution", {"oily_bha"},
     {"sensitive_strong_acids", "sensitive_fragrance"}, set()),
    ("K05 oily acne", P("dau", False, "mun"), T1, "good", {"oily_bha"}, set(), set()),
    ("K06 dry", P("kho"), K1, "avoid", {"dry_barrier"}, set(), {"dry_drying_alcohol_top"}),
    ("K07 sensitive", P("thuong", True), T1, "caution", set(),
     {"sensitive_strong_acids", "sensitive_fragrance"}, set()),
    ("K08 combo pigment", P("hon_hop", False, "tham"), S1, "good", {"pigment_brighten"}, set(), set()),
])
def test_persona_matrix(persona, profile, product, verdict, good, caution, avoid):
    result = evaluate(product, profile)
    assert result.verdict == verdict, persona
    assert ids(result.good) == good
    assert ids(result.caution) == caution
    assert ids(result.avoid) == avoid


def test_k07_sensitive_flags_acid_and_fragrance_keys():
    result = evaluate(T1, P("thuong", True))
    assert result.offending_keys == {"glycolic_acid", "salicylic_acid", "fragrance"}


def test_k06_caution_duplicating_an_avoid_is_dropped():
    result = evaluate(K1, P("kho", True))
    assert result.verdict == "avoid" and not result.caution
    assert result.offending_keys == {"alcohol_denat"}


def test_k10_low_recognition_blocks_good():
    result = evaluate(T1, P("dau"), recognized_ratio=0.5)
    assert result.verdict == "neutral" and ids(result.good) == {"oily_bha"}
    assert evaluate(T1, P("dau"), recognized_ratio=0.6).verdict == "good"


def test_low_recognition_does_not_soften_caution():
    assert evaluate(T1, P("thuong", True), recognized_ratio=0.2).verdict == "caution"


def test_unknown_profile_has_no_findings():
    result = evaluate(T1, P())
    assert result.verdict == "unknown" and not (result.good or result.caution or result.avoid)


def test_no_matching_rule_is_neutral():
    assert evaluate(R1, P("thuong")).verdict == "neutral"


def test_rank_beyond_rank_max_is_ignored():
    assert evaluate([("water", 1), ("alcohol_denat", 8)], P("kho")).verdict == "neutral"
    assert evaluate([("water", 1), ("alcohol_denat", 5)], P("kho")).verdict == "caution"
    assert evaluate([("water", 1), ("alcohol_denat", 3)], P("kho")).verdict == "avoid"


def test_acid_rank_limit_per_key_and_none_rank():
    sensitive = P(None, True)
    assert evaluate([("lactic_acid", 11)], sensitive).verdict == "neutral"   # rank_limits lactic_acid: 10
    assert evaluate([("lactic_acid", 10)], sensitive).verdict == "caution"
    assert evaluate([("lactic_acid", None)], sensitive).verdict == "caution"


def test_occlusive_oil_and_vitamin_c_family():
    assert ids(evaluate([("cocos_nucifera_oil", 4)], P("dau")).caution) == {"oily_heavy_occlusive"}
    assert evaluate([("petrolatum", 4)], P("dau")).verdict == "neutral"
    good = evaluate([("sodium_ascorbyl_phosphate", 3)], P(None, False, "tham")).good
    assert ids(good) == {"pigment_brighten"}


def test_to_dict_shape_and_display_names():
    profile = P("dau", True, "mun")
    data = evaluate(T1, profile).to_dict(profile, {"glycolic_acid": "Glycolic Acid"})
    assert data["verdict"] == "caution"
    assert data["profile"] == {"skin_type": "dau", "sensitive": True, "concerns": ["mun"]}
    acid = next(f for f in data["caution"] if f["rule_id"] == "sensitive_strong_acids")
    assert acid["ingredients"] == ["Glycolic Acid", "salicylic_acid"]
    assert set(acid) == {"rule_id", "reason_vi", "ingredients"}


@pytest.mark.parametrize("raw, skin, sens, concerns", [
    ({"skin_type": "da_dau"}, "dau", False, set()),
    ({"skin_type": "da_kho"}, "kho", False, set()),
    ({"skin_type": "da_mun"}, "dau", False, {"mun"}),
    ({"skin_type": "da_nhay_cam"}, None, True, set()),
    ({"skin_type": "moi_loai_da"}, "thuong", False, set()),
    ({"loai_da_name": "Da hỗn hợp"}, "hon_hop", False, set()),
    ({"loai_da_name": "Da thường"}, "thuong", False, set()),
    ({"loai_da_name": "Da nhạy cảm"}, None, True, set()),
    ({"loai_da_name": "da nhay cam"}, None, True, set()),
    ({"loai_da_name": "DA DẦU"}, "dau", False, set()),
    ({"loai_da_name": "Chưa xác định"}, None, False, set()),
    ({"skin_type": "da_dau", "loai_da_name": "Da nhạy cảm"}, "dau", True, set()),
    ({"concerns": ["tham_mun", "nam_tan_nhang"]}, None, False, {"tham"}),
    ({"concerns": ["mun", "lo_chan_long", "kho_da", "kich_ung", "lao_hoa", "xin_mau"]}, None, False,
     {"mun", "lo_chan_long", "kho_rap", "do_kich_ung", "lao_hoa"}),
    ({"concerns": ["dau_thua"]}, "dau", False, set()),
    ({"skin_type": "da_kho", "concerns": ["dau_thua"]}, "kho", False, set()),
])
def test_normalize_profile(raw, skin, sens, concerns):
    profile = normalize_profile(raw)
    assert (profile.skin_type, profile.sensitive, set(profile.concerns)) == (skin, sens, concerns)


def test_normalize_empty_is_unknown():
    for raw in (None, {}, {"skin_type": None, "concerns": []}):
        assert not normalize_profile(raw).known


def test_override_replaces_skin_type_and_sensitive_keeps_concerns():
    raw = {"skin_type": "da_kho", "loai_da_name": "Da nhạy cảm", "concerns": ["mun"]}
    profile = normalize_profile(raw, {"skin_type": "dau", "sensitive": False})
    assert (profile.skin_type, profile.sensitive, set(profile.concerns)) == ("dau", False, {"mun"})
    cleared = normalize_profile(raw, {"skin_type": None, "sensitive": False})
    assert cleared.skin_type is None and not cleared.sensitive
    guest = normalize_profile(None, {"skin_type": "dau", "sensitive": True})
    assert guest.known and guest.skin_type == "dau" and guest.sensitive


def test_partial_override_changes_only_given_keys():
    raw = {"skin_type": "da_kho", "loai_da_name": "Da nhạy cảm", "concerns": ["mun"]}
    base = normalize_profile(raw)
    assert normalize_profile(raw, {}) == base
    only_sensitive = normalize_profile(raw, {"sensitive": False})
    assert (only_sensitive.skin_type, only_sensitive.sensitive) == ("kho", False)
    only_type = normalize_profile(raw, {"skin_type": "dau"})
    assert (only_type.skin_type, only_type.sensitive) == ("dau", True)
