import pytest

from graph_sync.normalize import (
    SYNONYM_GROUPS, display_names, group_synonyms, load_synonyms, normalize_key, slugify, strip_accents,
)


@pytest.mark.parametrize("raw, expected", [
    ("Sữa Rửa Mặt", "sua_rua_mat"),
    ("  TORRIDEN ", "torriden"),
    ("Đường / ĐỎ", "duong_do"),
    ("Toner / Nước Cân Bằng Da", "toner_nuoc_can_bang_da"),
    ("a--b__c", "a_b_c"),
    ("", ""),
    (None, ""),
])
def test_slugify(raw, expected):
    assert slugify(raw) == expected


def test_strip_accents_handles_d_bar():
    assert strip_accents("Đặc trị đốm") == "Dac tri dom"


@pytest.mark.parametrize("raw, key", [
    ("SRM", "sua_rua_mat"),
    ("cleanser", "sua_rua_mat"),
    ("gel rửa mặt", "sua_rua_mat"),
    ("Sữa Rửa Mặt", "sua_rua_mat"),
    ("Serum / Tinh Chất", "serum"),
    ("Toner / Nước Cân Bằng Da", "toner"),
    ("Chống Nắng Da Mặt", "kem_chong_nang"),
    ("Da dầu/Hỗn hợp dầu", "da_dau"),
    ("Da thường/Mọi loại da", "moi_loai_da"),
    ("Da khô / Hỗn hợp thiên khô", "da_kho"),
    ("cấp ẩm", "cap_am"),
    ("Dưỡng Ẩm", "duong_am"),
    ("mụn", "mun"),
])
def test_normalize_key_applies_synonyms(raw, key):
    assert normalize_key(raw) == key


def test_normalize_key_without_synonym_returns_slug():
    assert normalize_key("Some New Thing!") == "some_new_thing"


def test_normalize_key_custom_table():
    assert normalize_key("SRM", {}) == "srm"
    assert normalize_key("x y", {"x_y": "z"}) == "z"


def test_no_variant_is_shared_between_groups():
    owners: dict[str, tuple[str, str]] = {}
    for group in SYNONYM_GROUPS:
        for slug, canonical in group_synonyms(group).items():
            if slug in owners and owners[slug] != (group, canonical):
                pytest.fail(f"{slug!r} maps to {owners[slug]} and {(group, canonical)}")
            owners[slug] = (group, canonical)


def test_no_variant_maps_to_two_keys_within_group():
    for group, specs in [(g, None) for g in SYNONYM_GROUPS]:
        from graph_sync.normalize import load_synonym_groups

        seen: dict[str, str] = {}
        for key, spec in load_synonym_groups()[group].items():
            for variant in [key, (spec or {}).get("name", "")] + list((spec or {}).get("aliases") or []):
                slug = slugify(variant)
                if not slug:
                    continue
                assert seen.get(slug, key) == key, f"{group}: {slug!r} in {seen[slug]} and {key}"
                seen[slug] = key


def test_merged_table_and_display_names():
    merged = load_synonyms()
    assert merged["srm"] == "sua_rua_mat"
    assert display_names("skin_types")["da_nhay_cam"] == "Da nhạy cảm"
