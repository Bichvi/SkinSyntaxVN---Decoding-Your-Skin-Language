from types import SimpleNamespace

from graph_sync.ingredients import load_aliases
from graph_sync.normalize import group_synonyms
from graph_sync.rules import TagRule, conflict_pairs, helps_edges, infer_tags, load_rules

RULES = {
    "hyaluronic_acid": TagRule(("cap_am",), ("kho_da",), ("da_kho", "moi_loai_da")),
    "salicylic_acid": TagRule(("lam_sach_sau",), ("mun", "lo_chan_long"), ("da_dau",)),
    "niacinamide": TagRule(("sang_da",), ("tham_mun", "dau_thua"), ()),
}


def test_infer_from_rules_when_no_skin_type():
    tags = infer_tags(["water", "salicylic_acid", "niacinamide", "hyaluronic_acid"], {}, RULES)
    assert tags.benefits == ["lam_sach_sau", "sang_da", "cap_am"]
    assert tags.concerns == ["mun", "lo_chan_long", "tham_mun", "dau_thua", "kho_da"]
    assert tags.skin_types == ["da_dau", "da_kho", "moi_loai_da"]
    assert tags.skin_type_source == "inferred"


def test_existing_skin_type_wins():
    tags = infer_tags(["salicylic_acid"], {"skin_types": ["da_nhay_cam"]}, RULES)
    assert tags.skin_types == ["da_nhay_cam"] and tags.skin_type_source == "data"
    assert tags.benefits == ["lam_sach_sau"]


def test_only_top_15_considered():
    keys = [f"filler_{i}" for i in range(15)] + ["niacinamide"]
    tags = infer_tags(keys, {}, RULES)
    assert tags.benefits == [] and tags.skin_type_source is None


def test_rule_file_tags_exist_in_synonyms_and_aliases():
    rules = load_rules()
    assert len(rules) >= 40
    alias_keys = {entry.key for entry in load_aliases().values()}
    groups = {
        "benefits": set(group_synonyms("benefits").values()),
        "concerns": set(group_synonyms("concerns").values()),
        "skin_types": set(group_synonyms("skin_types").values()),
    }
    for key, rule in rules.items():
        assert key in alias_keys, f"rule {key} has no entry in ingredient_aliases.yaml"
        for group in groups:
            unknown = set(getattr(rule, group)) - groups[group]
            assert not unknown, f"{key}.{group}: {unknown}"


def test_helps_edges():
    edges = helps_edges(RULES)
    assert {"ingredient": "salicylic_acid", "concern": "mun"} in edges
    assert len(edges) == 5


def test_conflict_pairs_expand_groups_and_are_undirected():
    rules = [SimpleNamespace(first="retinol", second="aha", resolution="tach buoi")]
    groups = {"retinol": ["retinol", "retinal"], "aha": ["glycolic_acid"]}
    pairs = conflict_pairs(rules, groups)
    assert [(p.a, p.b) for p in pairs] == [("glycolic_acid", "retinal"), ("glycolic_acid", "retinol")]
    assert pairs[0].reason == "tach buoi" and pairs[0].severity == "same_session"


def test_conflict_pairs_from_real_source():
    pairs = conflict_pairs()
    keys = {(p.a, p.b) for p in pairs}
    assert ("retinol", "salicylic_acid") in keys
    assert ("benzoyl_peroxide", "retinol") in keys
    assert all(p.a < p.b for p in pairs)
