"""Intent parser (Bước 04): deterministic Vietnamese query -> entity keys + price."""

from __future__ import annotations

import json
import time

import pytest

from search_service.dictionary import EntityDictionary
from search_service.intent_parser import parse
from search_service.schemas import Intent

BRANDS = [
    {"key": "torriden", "name": "Torriden"},
    {"key": "cerave", "name": "CeraVe"},
    {"key": "la_roche_posay", "name": "La Roche-Posay"},
    {"key": "some_by_mi", "name": "Some By Mi"},
    {"key": "cocoon", "name": "Cocoon"},
    {"key": "paula_s_choice", "name": "Paula's Choice"},
]
INGREDIENTS = [
    {"key": "niacinamide", "name": "Niacinamide", "inci": "Niacinamide"},
    {"key": "kem", "name": "Kẽm", "inci": None},              # noisy unmapped 1-word -> ignored
    {"key": "nam", "name": "nám", "inci": None},              # noisy unmapped 1-word -> ignored
    {"key": "chiet_xuat_hoa_sen", "name": "Chiết xuất hoa sen", "inci": None},
    {"key": "chong_oxy_hoa_tren_da", "name": "chống oxy hóa trên da", "inci": None},  # ETL phrase
    {"key": "torriden_dive_in", "name": "Torriden Dive In", "inci": None},          # contains brand
]


class FakeDriver:
    def __init__(self, fail: bool = False) -> None:
        self.fail = fail
        self.calls = 0

    def execute_query(self, query, *args, **kwargs):
        self.calls += 1
        if self.fail:
            raise RuntimeError("neo4j down")
        if ":Brand" in query:
            return BRANDS, None, ["key", "name"]
        if ":Ingredient" in query:
            return INGREDIENTS, None, ["key", "name", "inci"]
        return [], None, ["key", "name"]


@pytest.fixture(scope="module")
def dictionary() -> EntityDictionary:
    d = EntityDictionary(driver=FakeDriver(), mongo_db=None, ttl_seconds=600)
    d.snapshot()
    return d


def p(q: str, dictionary: EntityDictionary) -> Intent:
    return parse(q, dictionary)


# ------------------------------------------------------------------ brief table


def test_brand_and_category(dictionary):
    it = p("torriden sữa rửa mặt", dictionary)
    assert it.brands == ["torriden"]
    assert it.categories == ["sua_rua_mat"]
    assert it.confidence >= 0.9
    assert it.normalized == "torriden sua rua mat"


def test_word_order_irrelevant(dictionary):
    it = p("sua rua mat torriden", dictionary)
    assert (it.brands, it.categories) == (["torriden"], ["sua_rua_mat"])


def test_fuzzy_brand_and_abbreviation(dictionary):
    it = p("toriden srm", dictionary)
    assert it.brands == ["torriden"]
    assert it.categories == ["sua_rua_mat"]
    assert it.residual_terms == []


def test_uppercase_brand(dictionary):
    assert p("TORRIDEN", dictionary).brands == ["torriden"]


def test_skin_type_and_concern(dictionary):
    it = p("srm cho da dầu mụn", dictionary)
    assert it.categories == ["sua_rua_mat"]
    assert it.skin_types == ["da_dau"]
    assert it.concerns == ["mun"]
    assert it.residual_terms == []  # "cho" is a stopword


def test_negation_and_max_price(dictionary):
    it = p("toner không cồn dưới 300k", dictionary)
    assert it.categories == ["toner"]
    assert it.price is not None and it.price.max_vnd == 300000 and it.price.min_vnd is None
    # "không cồn" must not become ingredient alcohol_denat (it is an exclusion)
    assert "alcohol_denat" not in it.ingredients
    assert "khong" in it.residual_terms and "con" in it.residual_terms


def test_serum_vitamin_c(dictionary):
    it = p("serum vitamin c", dictionary)
    assert it.categories == ["serum"]
    assert it.ingredients == ["ascorbic_acid"]
    assert it.confidence == 1.0


def test_sunscreen_sensitive_skin(dictionary):
    it = p("kem chống nắng da nhạy cảm", dictionary)
    assert it.categories == ["kem_chong_nang"]
    assert it.skin_types == ["da_nhay_cam"]


def test_price_range_with_unit_on_second(dictionary):
    it = p("200-500 nghìn", dictionary)
    assert it.price.to_dict() == {"min_vnd": 200000, "max_vnd": 500000}
    assert it.confidence == 1.0


def test_price_from_million(dictionary):
    it = p("từ 1tr", dictionary)
    assert it.price.min_vnd == 1000000 and it.price.max_vnd is None


def test_ingredient_only(dictionary):
    assert p("niacinamide", dictionary).ingredients == ["niacinamide"]


def test_mask_and_benefit(dictionary):
    it = p("mặt nạ cấp ẩm", dictionary)
    assert it.categories == ["mat_na"]
    assert it.benefits == ["cap_am"]


@pytest.mark.parametrize("q", ["", "   ", "\t\n"])
def test_empty(q, dictionary):
    it = p(q, dictionary)
    assert it.is_empty and it.residual_terms == [] and it.confidence == 0.0


def test_symbols_only(dictionary):
    it = p("@@##$$", dictionary)
    assert it.is_empty and it.residual_terms == [] and it.confidence == 0.0


def test_cypher_injection_is_plain_residual(dictionary):
    it = p("'; MATCH (n) DETACH DELETE n //", dictionary)
    assert it.is_empty
    assert it.residual_terms == ["match", "n", "detach", "delete", "n"]
    assert it.confidence == 0.0


def test_long_input_is_truncated_and_fast(dictionary):
    q = ("torriden sữa rửa mặt " * 60)[:1000]
    start = time.perf_counter()
    it = p(q, dictionary)
    took = (time.perf_counter() - start) * 1000
    assert len(it.raw) <= 200
    assert it.brands == ["torriden"]
    assert took < 20


def test_longest_match_wins(dictionary):
    it = p("sữa rửa mặt", dictionary)
    assert it.categories == ["sua_rua_mat"]
    assert (it.brands, it.ingredients, it.skin_types, it.concerns, it.benefits) == ([], [], [], [], [])
    assert it.residual_terms == []


def test_unknown_word(dictionary):
    it = p("xyzabc", dictionary)
    assert it.residual_terms == ["xyzabc"] and it.confidence == 0.0 and it.is_empty


# ------------------------------------------------------- real-history queries (audit/handoff)


def test_history_srm_da_dau(dictionary):
    it = p("srm cho da dầu", dictionary)
    assert (it.categories, it.skin_types) == (["sua_rua_mat"], ["da_dau"])


def test_history_product_code_is_residual(dictionary):
    it = p("1496", dictionary)
    assert it.price is None and it.residual_terms == ["1496"]


def test_history_brand_only(dictionary):
    it = p("Torriden", dictionary)
    assert it.brands == ["torriden"] and it.confidence == 1.0


def test_moisturizer_dry_skin(dictionary):
    it = p("kem dưỡng ẩm cho da khô", dictionary)
    assert it.categories == ["kem_duong"]
    assert it.skin_types == ["da_kho"]


def test_duong_am_maps_to_category(dictionary):
    assert p("dưỡng ẩm", dictionary).categories == ["duong_am"]
    assert p("chống nắng", dictionary).categories == ["kem_chong_nang"]


def test_multiword_brand_and_compact_form(dictionary):
    assert p("some by mi serum", dictionary).brands == ["some_by_mi"]
    assert p("somebymi", dictionary).brands == ["some_by_mi"]
    assert p("la roche posay", dictionary).brands == ["la_roche_posay"]
    assert p("paula's choice bha", dictionary).brands == ["paula_s_choice"]


def test_bha_acne(dictionary):
    it = p("bha trị mụn", dictionary)
    assert it.ingredients == ["salicylic_acid"]
    assert it.concerns == ["mun"]


def test_toner_rose_water_alias(dictionary):
    it = p("nước hoa hồng cho da nhạy cảm", dictionary)
    assert it.categories == ["toner"]
    assert it.skin_types == ["da_nhay_cam"]


def test_makeup_remover(dictionary):
    assert p("tẩy trang cocoon", dictionary).to_dict()["categories"] == ["tay_trang"]
    assert p("tẩy trang cocoon", dictionary).brands == ["cocoon"]


def test_sunscreen_price_and_skin(dictionary):
    it = p("kem chống nắng dưới 200k cho da dầu", dictionary)
    assert it.categories == ["kem_chong_nang"]
    assert it.skin_types == ["da_dau"]
    assert it.price.max_vnd == 200000


# ------------------------------------------------------------------ price edge cases


@pytest.mark.parametrize(
    "q,expected",
    [
        ("dưới 1tr5", (None, 1500000)),
        ("< 500k", (None, 500000)),
        ("tren 300 nghin", (300000, None)),
        ("tối đa 250.000đ", (None, 250000)),
        ("từ 100k đến 300k", (100000, 300000)),
        ("khoảng 200k-400k", (200000, 400000)),
        ("dưới 300", (None, 300000)),  # no unit and < 10000 -> thousands
        ("500k - 200k", (200000, 500000)),  # swapped
    ],
)
def test_price_forms(q, expected, dictionary):
    it = p(q, dictionary)
    assert it.price is not None
    assert (it.price.min_vnd, it.price.max_vnd) == expected


@pytest.mark.parametrize("q", ["kem chống nắng spf50", "serum 30ml", "niacinamide 10%", "serum 5-10%"])
def test_no_false_price(q, dictionary):
    assert p(q, dictionary).price is None


def test_noisy_unmapped_ingredients_ignored(dictionary):
    it = p("kem", dictionary)  # "Kẽm" unmapped 1-word must not hijack "kem"
    assert it.ingredients == []
    it = p("chiết xuất hoa sen", dictionary)
    # U06 aliased the Vietnamese name to its INCI entry, so the canonical key now wins over the raw ETL key
    assert it.ingredients == ["nelumbo_nucifera_flower_extract"]


def test_descriptive_unmapped_phrases_do_not_steal_matches(dictionary):
    it = p("chống oxy hóa trên da", dictionary)
    assert it.ingredients == [] and it.benefits == ["chong_oxy_hoa"]
    it = p("torriden dive in", dictionary)
    assert it.ingredients == [] and it.brands == ["torriden"]


def test_no_fuzzy_false_positive_on_common_words(dictionary):
    it = p("loại nào tốt nhất giá rẻ", dictionary)
    assert it.is_empty and it.residual_terms == [] and it.confidence == 0.0
    assert p("mask", dictionary).brands == []


def test_to_dict_is_json_serialisable(dictionary):
    data = p("toner không cồn dưới 300k", dictionary).to_dict()
    json.dumps(data)
    assert set(data) == {
        "raw", "normalized", "brands", "categories", "ingredients", "skin_types",
        "concerns", "benefits", "price", "residual_terms", "confidence",
        "exclude_ingredients", "avoid_conflict_with",     # U10 (additive)
    }


def test_parse_without_dictionary_object_never_raises():
    it = parse("torriden srm", None)
    assert it.categories == ["sua_rua_mat"]  # YAML synonyms still work


def test_performance_1000_parses(dictionary):
    queries = ["torriden sữa rửa mặt", "toriden srm", "toner không cồn dưới 300k", "xyzabc",
               "kem chống nắng da nhạy cảm"]
    start = time.perf_counter()
    for i in range(1000):
        parse(queries[i % len(queries)], dictionary)
    assert time.perf_counter() - start < 2.0


def test_synonyms_added_in_step_04(dictionary):
    assert p("cerave face wash", dictionary).categories == ["sua_rua_mat"]
    assert p("kem dưỡng đêm", dictionary).categories == ["kem_duong"]
    assert p("lót nền", dictionary).categories == ["kem_lot"]
    assert p("serum b5", dictionary).ingredients == ["panthenol"]


# ------------------------------------------------------------------ U10: negation scope


def test_exclusion_with_filler_words_is_recorded_not_included(dictionary):
    it = p("sữa rửa mặt không chứa cồn dưới 300k", dictionary)
    assert it.categories == ["sua_rua_mat"]
    assert it.ingredients == [] and it.exclude_ingredients == ["alcohol_denat"]
    assert it.avoid_conflict_with == []
    assert it.price is not None and it.price.max_vnd == 300000


def test_not_conflict_with_goes_to_avoid_conflict_with(dictionary):
    it = p("serum nào hợp da dầu, không kỵ retinol", dictionary)
    assert it.categories == ["serum"] and it.skin_types == ["da_dau"]
    assert it.ingredients == [] and it.exclude_ingredients == []
    assert it.avoid_conflict_with == ["retinol"]
    it = p("serum không xung đột với BHA", dictionary)
    assert it.avoid_conflict_with == ["salicylic_acid"] and it.ingredients == []


def test_negation_list_continues_over_joiners(dictionary):
    it = p("kem dưỡng không chứa cồn và hương liệu", dictionary)
    assert it.ingredients == []
    assert set(it.exclude_ingredients) == {"alcohol_denat", "fragrance"}


def test_positive_and_negative_ingredients_in_one_query(dictionary):
    it = p("serum có niacinamide không chứa cồn", dictionary)
    assert it.ingredients == ["niacinamide"] and it.exclude_ingredients == ["alcohol_denat"]


def test_together_phrase_is_not_a_negation(dictionary):
    it = p("retinol dùng chung với BHA được không", dictionary)
    assert it.ingredients == ["retinol", "salicylic_acid"]
    assert it.exclude_ingredients == [] and it.avoid_conflict_with == []


def test_alcohol_free_suffix_still_negates(dictionary):
    it = p("toner alcohol free", dictionary)
    assert it.exclude_ingredients == ["alcohol_denat"] and it.ingredients == []
