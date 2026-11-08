from tagging.products import choose_ids, current_tags, estimate_cost_usd, product_input, rule_tags_from_row, strip_curation


def test_strip_curation_removes_only_tag_fields():
    doc = {"ma_san_pham": 1, "ten_san_pham": "A", "tag_source": "manual", "cong_dung_keys": ["cap_am"],
           "van_de_da_keys": [], "loai_da_keys": [], "tag_updated_at": 1}
    assert strip_curation(doc) == {"ma_san_pham": 1, "ten_san_pham": "A"}


def test_current_tags_only_when_curated():
    assert current_tags({"cong_dung_keys": ["cap_am"]}) == {"benefits": [], "concerns": [], "skin_types": []}
    assert current_tags({"tag_source": "manual", "cong_dung_keys": ["cap_am"], "loai_da_keys": ["da_dau"]}) == {
        "benefits": ["cap_am"], "concerns": [], "skin_types": ["da_dau"]}


def test_rule_tags_from_row():
    row = {"benefits": [{"key": "cap_am", "source": "inferred"}], "concerns": [], "skin_types": [{"key": "da_kho"}]}
    assert rule_tags_from_row(row) == {"benefits": ["cap_am"], "concerns": [], "skin_types": ["da_kho"]}


def test_product_input_uses_first_30_parsed_ingredients_and_category_chain():
    row = {"ingredients": [{"inci": "Water", "name": "Water"}] + [{"inci": None, "name": f"x{i}"} for i in range(40)],
           "categories": [{"name": "Chăm Sóc Da Mặt"}, {"name": "Serum"}]}
    p = product_input({"ma_san_pham": 5, "ten_san_pham": "S", "mo_ta": "mô tả"}, row)
    assert len(p.ingredients) == 30 and p.ingredients[0] == "Water" and p.category == "Chăm Sóc Da Mặt > Serum"
    assert p.description == "mô tả"


def test_product_input_falls_back_to_raw_text():
    p = product_input({"ma_san_pham": 5, "ten_san_pham": "S", "thanh_phan_full": "A, B\nC"}, {"ingredients": [], "categories": []})
    assert p.ingredients == ("A", "B", "C")


def test_choose_ids_is_deterministic_and_bounded():
    ids = list(range(1, 101))
    a = choose_ids(ids, 30, "random", seed=7)
    assert a == choose_ids(ids, 30, "random", seed=7) and len(a) == 30 and len(set(a)) == 30 and a == sorted(a)
    assert a != choose_ids(ids, 30, "random", seed=8)
    assert choose_ids(ids, 3, "first") == [1, 2, 3]
    assert choose_ids(ids, None) == ids and choose_ids([3, 1, 2], 10) == [1, 2, 3]


def test_cost_estimate():
    assert round(estimate_cost_usd({"input_tokens": 1_000_000, "output_tokens": 1_000_000}, 0.15, 0.60), 2) == 0.75
