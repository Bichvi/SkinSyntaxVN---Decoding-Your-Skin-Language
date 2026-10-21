from graph_sync.ingredients import AliasEntry, clean_fragments, load_aliases, parse_ingredients

ALIASES = {
    "water": AliasEntry("water", "Water", "Nước", "base"),
    "aqua": AliasEntry("water", "Water", "Nước", "base"),
    "ha": AliasEntry("hyaluronic_acid", "Hyaluronic Acid", None, "active"),
    "hyaluronic_acid": AliasEntry("hyaluronic_acid", "Hyaluronic Acid", None, "active"),
    "niacinamide": AliasEntry("niacinamide", "Niacinamide", "Vitamin B3", "active"),
}


def keys(refs):
    return [r.key for r in refs]


def test_empty_inputs():
    assert parse_ingredients(None, ALIASES) == []
    assert parse_ingredients("", ALIASES) == []
    assert parse_ingredients("None", ALIASES) == []
    assert parse_ingredients(" , ; \n", ALIASES) == []


def test_percent_parentheses_newlines_and_duplicates():
    raw = "Water (Aqua), 5% Niacinamide;\nHA 2%, Aqua, Glycerin [Nano]\nPEG\n-8"
    refs = parse_ingredients(raw, ALIASES)
    assert keys(refs) == ["water", "niacinamide", "hyaluronic_acid", "glycerin", "peg_8"]
    assert [r.rank for r in refs] == [1, 2, 3, 4, 5]
    assert refs[0].inci == "Water" and refs[0].role == "base"
    glycerin = refs[3]
    assert glycerin.inci is None and glycerin.name == "Glycerin"


def test_digit_comma_kept_and_slash_rules():
    refs = parse_ingredients("1,2-Hexanediol, Caprylic/Capric Triglyceride, Water / Niacinamide", ALIASES)
    assert keys(refs) == ["1_2_hexanediol", "caprylic_capric_triglyceride", "water", "niacinamide"]


def test_bullet_marketing_format_takes_heads():
    raw = "Thành phần sản phẩm\n• Niacinamide: làm sáng da, giảm thâm\n• Nano HA: cấp ẩm"
    assert keys(parse_ingredients(raw, ALIASES)) == ["niacinamide", "nano_ha"]


def test_prefix_and_long_sentences_dropped():
    raw = "Ingredients: Water, đây là một câu mô tả rất dài không phải thành phần mỹ phẩm nào cả, Niacinamide"
    assert keys(parse_ingredients(raw, ALIASES)) == ["water", "niacinamide"]
    assert clean_fragments("Thành phần sản phẩm Water, Glycerin") == ["Water", "Glycerin"]


def test_max_items_keeps_first_thirty():
    raw = ", ".join(f"Ingredient {i}" for i in range(1, 41))
    refs = parse_ingredients(raw, {})
    assert len(refs) == 30 and refs[-1].key == "ingredient_30" and refs[-1].rank == 30


def test_list_input_supported():
    assert keys(parse_ingredients(["Water", "Niacinamide"], ALIASES)) == ["water", "niacinamide"]


def test_real_alias_file_maps_common_names():
    table = load_aliases()
    assert table["vitamin_c"].key == "ascorbic_acid"
    assert table["aqua_water_eau"].key == "water"
    assert table["chiet_xuat_rau_ma"].key == "centella_asiatica_extract"
    assert table["t_x_a"].key == "tranexamic_acid"
    assert table["1_2_hexanediol"].inci == "1,2-Hexanediol"
