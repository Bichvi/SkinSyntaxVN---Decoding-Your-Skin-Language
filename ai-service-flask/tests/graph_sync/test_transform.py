from datetime import datetime

from graph_sync.ingredients import AliasEntry
from graph_sync.rules import TagRule
from graph_sync.transform import (
    CategoryInfo, Lookups, canonical_brand_names, in_stock, is_visible, to_graph_row,
)

LOOKUPS = Lookups(
    brands={"24": "Torriden", "25": "TORRIDEN "},
    categories={
        "1001": CategoryInfo("Chăm Sóc Da Mặt", 1, None),
        "2002": CategoryInfo("Làm Sạch Da", 2, "1001"),
        "1": CategoryInfo("Sữa Rửa Mặt", 3, "2002"),
    },
    skin_types={"4": "Unknown", "5": "Da khô/Hỗn hợp khô", "1": "Da dầu/Hỗn hợp dầu"},
    origins={"3": "Hàn Quốc"},
)
ALIASES = {
    "water": AliasEntry("water", "Water", None, "base"),
    "sodium_hyaluronate": AliasEntry("sodium_hyaluronate", "Sodium Hyaluronate", None, "active"),
    "salicylic_acid": AliasEntry("salicylic_acid", "Salicylic Acid", "BHA", "active"),
}
RULES = {
    "sodium_hyaluronate": TagRule(("cap_am",), ("kho_da",), ("da_kho",)),
    "salicylic_acid": TagRule(("lam_sach_sau",), ("mun",), ("da_dau",)),
}


def doc(**overrides):
    base = {
        "ma_san_pham": 1496, "ten_san_pham": "Sữa Rửa Mặt Torriden", "gia_ban": 194000,
        "gia_thi_truong": 330000, "so_luong_da_ban": 7, "diem_danh_gia": 5.0,
        "ma_thuong_hieu": 24, "ma_danh_muc": 1, "ma_loai_da": 5, "ma_xuat_xu": 3,
        "danh_muc_day_du": "Sức Khỏe - Làm Đẹp -> Chăm Sóc Da Mặt -> Làm Sạch Da -> Sữa Rửa Mặt",
        "thanh_phan_full": "Water, Sodium Hyaluronate, Salicylic Acid 2%",
        "thanh_phan_sach": "nước",
        "trang_thai": "active", "trang_thai_kho": "con_hang", "so_luong_ton_kho": 300,
        "updated_at": datetime(2026, 5, 28, 17, 4, 13),
    }
    base.update(overrides)
    return base


def convert(d):
    return to_graph_row(d, LOOKUPS, aliases=ALIASES, rules=RULES)


def test_full_row_shape():
    row = convert(doc())
    assert row["id"] == "1496" and row["active"] is True
    assert row["props"] == {
        "name": "Sữa Rửa Mặt Torriden", "price": 194000, "original_price": 330000, "sold": 7,
        "rating": 5.0, "in_stock": True, "updated_at": "2026-05-28T17:04:13Z",
    }
    assert row["brand"] == [{"key": "torriden", "name": "Torriden"}]
    assert [c["key"] for c in row["categories"]] == ["cham_soc_da_mat", "lam_sach_da", "sua_rua_mat"]
    assert [c["level"] for c in row["categories"]] == [1, 2, 3]
    assert row["leaf_category"] == "sua_rua_mat" and row["category_source"] == "danh_muc"
    assert row["category_edges"] == [
        {"child": "lam_sach_da", "parent": "cham_soc_da_mat"},
        {"child": "sua_rua_mat", "parent": "lam_sach_da"},
    ]
    assert [(i["key"], i["rank"]) for i in row["ingredients"]] == [
        ("water", 1), ("sodium_hyaluronate", 2), ("salicylic_acid", 3)]
    assert row["ingredient_source"] == "full"
    assert row["skin_types"] == [{"key": "da_kho", "name": "Da khô", "source": "data"}]
    assert [b["key"] for b in row["benefits"]] == ["cap_am", "lam_sach_sau"]
    assert [c["key"] for c in row["concerns"]] == []     # cleanser category: no inferred concerns
    assert row["origin"] == [{"key": "han_quoc", "name": "Hàn Quốc"}]


def test_unknown_skin_type_is_inferred():
    row = convert(doc(ma_loai_da=4))
    assert [(s["key"], s["source"]) for s in row["skin_types"]] == [("da_kho", "inferred"), ("da_dau", "inferred")]


def test_orphan_category_splits_full_path():
    row = convert(doc(ma_danh_muc=22, danh_muc_day_du="Sức Khỏe - Làm Đẹp -> Chăm Sóc Cơ Thể -> Dưỡng Thể"))
    assert [(c["key"], c["level"]) for c in row["categories"]] == [("cham_soc_co_the", 1), ("duong_the", 2)]
    assert row["leaf_category"] == "duong_the" and row["category_source"] == "path"


def test_missing_full_falls_back_to_sach_and_missing_everything():
    row = convert(doc(thanh_phan_full=None, thanh_phan_sach="salicylic acid, nước hoa"))
    assert row["ingredient_source"] == "sach" and row["ingredients"][0]["key"] == "salicylic_acid"
    empty = convert(doc(thanh_phan_full="None", thanh_phan_sach=None, ma_thuong_hieu=999,
                        ma_danh_muc=None, danh_muc_day_du=None, ma_xuat_xu=None))
    assert empty["ingredients"] == [] and empty["ingredient_source"] is None
    assert empty["brand"] == [] and empty["categories"] == [] and empty["leaf_category"] is None
    assert empty["origin"] == []


def test_id_types_and_missing_id():
    assert convert(doc(ma_san_pham=12.0))["id"] == "12"
    assert convert(doc(ma_san_pham="0077"))["id"] == "0077"
    assert convert(doc(ma_san_pham=None)) is None


def test_visibility_and_stock_mirror_php():
    assert is_visible({"trang_thai": "active"}) and is_visible({})
    assert not is_visible({"trang_thai": "tam_an"}) and not is_visible({"trang_thai": "0"})
    assert convert(doc(trang_thai="hidden"))["active"] is False
    assert in_stock({"so_luong_ton_kho": 3}) and in_stock({})
    assert not in_stock({"so_luong_ton_kho": 0})
    assert not in_stock({"trang_thai_kho": " HET_HANG ", "so_luong_ton_kho": 5})


def test_brand_display_name_most_common():
    rows = [convert(doc(ma_thuong_hieu=25)), convert(doc()), convert(doc(ma_san_pham=2))]
    names = canonical_brand_names(rows)
    assert names == {"torriden": "Torriden"}


def test_raw_thanh_phan_with_marker_beats_damaged_sach():
    row = convert(doc(
        thanh_phan_full=None,
        thanh_phan="Hyaluronic Acid, Vitamin C. Bảng thành phần: Water, Sodium Hyaluronate, Salicylic Acid",
        thanh_phan_sach="peg, 18 methyl ether dimethyl silane",
    ))
    assert row["ingredient_source"] == "raw"
    assert [i["key"] for i in row["ingredients"]][:3] == ["water", "sodium_hyaluronate", "salicylic_acid"]
    plain = convert(doc(thanh_phan_full=None, thanh_phan="Water, Salicylic Acid", thanh_phan_sach="salicylic acid"))
    assert plain["ingredient_source"] == "sach"


def test_sunscreen_category_gets_no_inferred_concerns_but_keeps_benefits():
    lookups = Lookups(
        brands=LOOKUPS.brands, skin_types=LOOKUPS.skin_types, origins=LOOKUPS.origins,
        categories={"9": CategoryInfo("Kem Chống Nắng", 3, None)},
    )
    row = to_graph_row(doc(ma_danh_muc=9), lookups, aliases=ALIASES, rules=RULES)
    assert [c["key"] for c in row["categories"]] == ["kem_chong_nang"]
    assert row["concerns"] == [] and [b["key"] for b in row["benefits"]]
