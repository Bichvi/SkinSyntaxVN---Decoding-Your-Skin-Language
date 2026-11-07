from datetime import datetime

from graph_sync.curation import CURATED_SOURCES, clean_keys, curated_source, resolve_tags, source_priority
from graph_sync.extract import PRODUCT_PROJECTION, TAG_UPDATED_FIELD, UPDATED_FIELD, iter_products
from graph_sync.rules import TagRule, Tags
from graph_sync.transform import CategoryInfo, Lookups, to_graph_row
from graph_sync.ingredients import AliasEntry

VALID = {
    "benefits": {"cap_am", "lam_diu", "sang_da", "phuc_hoi", "kiem_dau"},
    "concerns": {"mun", "kho_da", "kich_ung"},
    "skin_types": {"da_dau", "da_kho", "da_nhay_cam", "moi_loai_da"},
}


def inferred(**kw):
    base = dict(benefits=["cap_am"], concerns=["kho_da"], skin_types=["da_kho"], skin_type_source="data")
    base.update(kw)
    return Tags(**base)


def keys(entries):
    return [(e["key"], e["source"]) for e in entries]


def test_priority_order_manual_beats_llm_beats_rule():
    assert CURATED_SOURCES == ("manual", "llm_approved")
    assert source_priority("manual") < source_priority("llm_approved") < source_priority("rule") == source_priority(None)


def test_no_tag_source_keeps_rules_untouched():
    out = resolve_tags(inferred(), {"cong_dung_keys": ["lam_diu"]}, VALID)     # field without tag_source is ignored
    assert keys(out["benefits"]) == [("cap_am", "inferred")]
    assert out["tag_source"] == "rule"
    assert keys(out["skin_types"]) == [("da_kho", "data")]


def test_unknown_tag_source_is_ignored():
    assert curated_source({"tag_source": "hacker"}) is None
    assert resolve_tags(inferred(), {"tag_source": "hacker", "cong_dung_keys": ["lam_diu"]}, VALID)["tag_source"] == "rule"


def test_manual_replaces_rule_benefits_and_stamps_source():
    doc = {"tag_source": "manual", "cong_dung_keys": ["lam_diu", "sang_da"]}
    out = resolve_tags(inferred(), doc, VALID)
    assert keys(out["benefits"]) == [("lam_diu", "manual"), ("sang_da", "manual")]
    assert keys(out["concerns"]) == [("kho_da", "inferred")]          # group without curated keys falls back to rules
    assert out["tag_source"] == "manual"


def test_llm_approved_source_is_stamped():
    out = resolve_tags(inferred(), {"tag_source": "llm_approved", "van_de_da_keys": ["mun"]}, VALID)
    assert keys(out["concerns"]) == [("mun", "llm_approved")]
    assert keys(out["benefits"]) == [("cap_am", "inferred")]
    assert out["tag_source"] == "llm_approved"


def test_unknown_and_duplicate_keys_are_dropped():
    doc = {"tag_source": "manual", "cong_dung_keys": ["lam_diu", "bay_len_troi", "lam_diu", "", None]}
    assert keys(resolve_tags(inferred(), doc, VALID)["benefits"]) == [("lam_diu", "manual")]


def test_all_invalid_keys_fall_back_to_rules_and_rule_source():
    out = resolve_tags(inferred(), {"tag_source": "manual", "cong_dung_keys": ["nope"], "van_de_da_keys": []}, VALID)
    assert keys(out["benefits"]) == [("cap_am", "inferred")]
    assert out["tag_source"] == "rule"


def test_skin_types_are_additive_to_own_data():
    out = resolve_tags(inferred(), {"tag_source": "manual", "loai_da_keys": ["da_nhay_cam", "da_kho"]}, VALID)
    assert keys(out["skin_types"]) == [("da_kho", "data"), ("da_nhay_cam", "manual")]


def test_skin_types_replace_rule_inferred_ones():
    t = inferred(skin_types=["da_dau", "da_kho"], skin_type_source="inferred")
    out = resolve_tags(t, {"tag_source": "llm_approved", "loai_da_keys": ["moi_loai_da"]}, VALID)
    assert keys(out["skin_types"]) == [("moi_loai_da", "llm_approved")]


def test_llm_cannot_widen_specific_own_skin_type_to_all():
    t = inferred(skin_types=["da_nhay_cam"], skin_type_source="data")
    out = resolve_tags(t, {"tag_source": "llm_approved", "loai_da_keys": ["da_nhay_cam", "moi_loai_da"]}, VALID)
    assert keys(out["skin_types"]) == [("da_nhay_cam", "data")]


def test_llm_other_extras_still_appended_but_universal_dropped():
    t = inferred(skin_types=["da_nhay_cam"], skin_type_source="data")
    out = resolve_tags(t, {"tag_source": "llm_approved", "loai_da_keys": ["moi_loai_da", "da_kho"]}, VALID)
    assert keys(out["skin_types"]) == [("da_nhay_cam", "data"), ("da_kho", "llm_approved")]


def test_manual_may_still_add_universal_skin_type():
    t = inferred(skin_types=["da_nhay_cam"], skin_type_source="data")
    out = resolve_tags(t, {"tag_source": "manual", "loai_da_keys": ["moi_loai_da"]}, VALID)
    assert keys(out["skin_types"]) == [("da_nhay_cam", "data"), ("moi_loai_da", "manual")]


def test_clean_keys_accepts_string_and_limits():
    assert clean_keys("cap_am", VALID["benefits"]) == ["cap_am"]
    assert clean_keys(["cap_am", "lam_diu", "sang_da"], VALID["benefits"], limit=2) == ["cap_am", "lam_diu"]
    assert clean_keys({"a": 1}, None) == []


# --- through to_graph_row (what actually reaches Neo4j) -------------------------------------------------------

LOOKUPS = Lookups(
    brands={"24": "Torriden"},
    categories={"1": CategoryInfo("Tinh Chất Serum", 1, None)},
    skin_types={"5": "Da khô/Hỗn hợp khô"},
)
ALIASES = {"sodium_hyaluronate": AliasEntry("sodium_hyaluronate", "Sodium Hyaluronate", None, "active")}
RULES = {"sodium_hyaluronate": TagRule(("cap_am",), ("kho_da",), ("da_kho",))}


def row(**extra):
    doc = {"ma_san_pham": 7, "ten_san_pham": "X", "ma_thuong_hieu": 24, "ma_danh_muc": 1, "ma_loai_da": 5,
           "thanh_phan_full": "Sodium Hyaluronate", "trang_thai": "active", "updated_at": datetime(2026, 1, 1)}
    doc.update(extra)
    return to_graph_row(doc, LOOKUPS, aliases=ALIASES, rules=RULES)


def test_row_default_sources_are_inferred_and_rule():
    r = row()
    assert r["tag_source"] == "rule"
    assert [(b["key"], b["source"]) for b in r["benefits"]] == [("cap_am", "inferred")]
    assert [(c["key"], c["source"]) for c in r["concerns"]] == [("kho_da", "inferred")]


def test_row_manual_overrides_rules_with_names_and_source():
    r = row(tag_source="manual", cong_dung_keys=["lam_diu"], van_de_da_keys=["kich_ung"])
    assert r["tag_source"] == "manual"
    assert r["benefits"] == [{"key": "lam_diu", "name": "Làm dịu", "source": "manual"}]
    assert r["concerns"] == [{"key": "kich_ung", "name": "Kích ứng / mẩn đỏ", "source": "manual"}]


def test_row_unknown_key_cannot_reach_graph():
    r = row(tag_source="manual", cong_dung_keys=["<script>"])
    assert [b["key"] for b in r["benefits"]] == ["cap_am"]


def test_projection_reads_curation_fields_and_since_uses_tag_timestamp():
    for name in ("cong_dung_keys", "van_de_da_keys", "loai_da_keys", "tag_source", "tag_updated_at"):
        assert PRODUCT_PROJECTION[name] == 1

    captured = {}

    class Coll:
        def find(self, query, projection):
            captured["query"] = query

            class C:
                def batch_size(self, n):
                    return []
            return C()

    since = datetime(2026, 10, 1)
    list(iter_products({"san_pham": Coll()}, since=since))
    assert captured["query"] == {"$or": [{UPDATED_FIELD: {"$gte": since}}, {TAG_UPDATED_FIELD: {"$gte": since}}]}
    list(iter_products({"san_pham": Coll()}))
    assert captured["query"] == {}
