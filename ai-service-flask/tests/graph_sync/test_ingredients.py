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


# ---------------------------------------------------------------- U06: messy exports ----------

import yaml

from graph_sync.ingredients import ALIASES_PATH, build_compact_index, resolve_alias
from graph_sync.normalize import slugify

MIXED = (
    "Aqua, Glycerin, Edta 2. Mặt Nạ Colorkey Luminous B5 Facial Mask Hydrating Dưỡng Ẩm Sáng Da 25ml "
    "Thành phần chính:\n• Provitamin B5 và Niacinamide: Tái tạo hàng rào bảo vệ làn da.\n"
    "• Kẽm: có khả năng kháng viêm Thành phần chi tiết: Water, Niacinamide, Panthenol & Ectoin"
)


def test_marketing_block_uses_detail_list_not_bullet_heads_or_product_title():
    names = clean_fragments(MIXED)
    assert names == ["Aqua", "Glycerin", "Edta", "Water", "Niacinamide", "Panthenol", "Ectoin"]


def test_bullet_body_that_is_an_inci_list_is_parsed():
    body = ", ".join(["Water", "Glycerin", "Niacinamide", "Panthenol", "Allantoin", "Carbomer",
                      "Xanthan Gum", "Sodium Hyaluronate"])
    raw = f"• Obagi Soothing Retinol: {body}"
    assert clean_fragments(raw)[:3] == ["Water", "Glycerin", "Niacinamide"]


def test_hyphen_turned_comma_in_sach_text_is_rejoined():
    raw = "water, peg, 18 methyl ether dimethyl silane, bis, peg, 18 methyl ether, polyglyceryl, 2 triisostearate"
    assert clean_fragments(raw) == [
        "water", "peg-18 methyl ether dimethyl silane", "bis-peg-18 methyl ether", "polyglyceryl-2 triisostearate",
    ]
    # a number after an ordinary ingredient is NOT merged
    assert clean_fragments("glycerin, 5 niacinamide") == ["glycerin", "5 niacinamide"]


def test_extra_separators_and_decimals():
    assert clean_fragments("Panthenol & Ectoin, Tranexamic Acid . Butylene Glycol, Dimethicone. Isohexadecane") == [
        "Panthenol", "Ectoin", "Tranexamic Acid", "Butylene Glycol", "Dimethicone", "Isohexadecane",
    ]
    assert clean_fragments("Folic Acid 0.01, Carbomer 0,01, Poloxamer 184") == ["Folic Acid", "Carbomer", "Poloxamer 184"]
    assert clean_fragments("Đà Nẵng 25g Aqua, Hồng: Water") == ["Aqua", "Water"]


def test_junk_fragments_and_prose_are_dropped():
    raw = "Water, Copolymer, Hydrogenated, bảng thành phần, được làm từ cotton, Niacinamide"
    assert keys(parse_ingredients(raw, ALIASES)) == ["water", "niacinamide"]


def test_descriptive_bullet_heads_dropped_but_ingredient_like_heads_kept():
    raw = "• Niacinamide: làm sáng\n• mịn màng: cho da\n• Chiết xuất rau má: làm dịu\n• Kẽm: kháng viêm"
    refs = parse_ingredients(raw, ALIASES)
    assert [r.name for r in refs] == ["Niacinamide", "Chiết xuất rau má", "Kẽm"]


def test_cyrillic_lookalikes_are_replaced_inside_latin_words_only():
    assert clean_fragments("Тосорheryl Acetate, Centella Extracт, Сера") == ["Tocopheryl Acetate", "Centella Extract", "Сера"]


def test_resolve_alias_fallbacks():
    table = load_aliases()
    assert resolve_alias("Phenoxy ethanol", table).key == "phenoxyethanol"
    assert resolve_alias("1.2-Hexanediol", table).key == "1_2_hexanediol"
    assert resolve_alias("Triethanolamine 2", table).key == "triethanolamine"
    assert resolve_alias("Totally Unknown Thing", table) is None
    assert resolve_alias("HA", {"ha": AliasEntry("hyaluronic_acid", "Hyaluronic Acid", None, "active")}) is not None


def test_compact_index_drops_ambiguous_forms():
    a = AliasEntry("a", "Alpha", None, None)
    b = AliasEntry("b", "Beta", None, None)
    index = build_compact_index({"ab_cd": a, "a_bcd": a, "abc_d": b, "xyz_123": b})
    assert "abcd" not in index and index["xyz123"] is b


def test_alias_file_integrity():
    raw = yaml.safe_load(open(ALIASES_PATH, encoding="utf-8"))["ingredients"]
    allowed = {"active", "uv_filter", "humectant", "emollient", "base", "preservative", "fragrance",
               "colorant", "thickener", "emulsifier"}
    owner: dict[str, str] = {}
    clashes: list[str] = []
    for key, spec in raw.items():
        spec = spec or {}
        assert spec.get("role") in allowed, (key, spec.get("role"))
        for variant in [key, spec.get("inci") or key] + list(spec.get("aliases") or []):
            slug = slugify(variant)
            if slug and owner.setdefault(slug, slugify(key)) != slugify(key):
                clashes.append(f"{variant!r}: {owner[slug]} vs {slugify(key)}")
    assert not clashes, clashes
    assert len(raw) > 700
