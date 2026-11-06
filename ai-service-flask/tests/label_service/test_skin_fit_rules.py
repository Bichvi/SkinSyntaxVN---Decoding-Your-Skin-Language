import pytest

from label_service import skin_fit

PLAN_IDS = {
    "sensitive_drying_alcohol", "sensitive_fragrance", "sensitive_strong_acids", "sensitive_retinoid",
    "sensitive_bpo", "dry_drying_alcohol_top", "oily_heavy_occlusive", "oily_bha", "acne_niacinamide",
    "dry_barrier", "pigment_brighten", "aging_actives", "soothing",
}


def test_all_plan_rules_present_and_ids_unique():
    ids = [r.id for r in skin_fit.load_rules().rules]
    assert len(ids) == len(set(ids))
    assert set(ids) == PLAN_IDS


def test_every_rule_has_https_source_reason_and_valid_verdict():
    for rule in skin_fit.load_rules().rules:
        assert rule.sources and all(u.startswith("https://") for u in rule.sources), rule.id
        assert rule.reason_vi.strip(), rule.id
        assert rule.verdict in skin_fit.VERDICTS, rule.id
        assert rule.matchers, rule.id


def test_every_key_resolves_to_alias_vocabulary():
    known = {e.key for e in skin_fit.load_aliases().values()}
    for rule in skin_fit.load_rules().rules:
        for matcher in rule.matchers:
            assert matcher.keys and matcher.keys <= known, rule.id


def test_group_expansion_matches_documented_sources():
    groups = skin_fit.group_table()
    assert {"alcohol_denat", "isopropyl_alcohol", "t_butyl_alcohol"} <= set(groups["alcohol"])
    assert "fragrance" in groups["fragrance"] and len(groups["fragrance"]) > 1   # role=fragrance entries expanded
    assert "salicylic_acid" in groups["exfoliating_acids"]
    assert set(skin_fit.family_table()) >= {"ascorbic_acid", "hyaluronic_acid", "ceramide"}


def test_corrected_key_lists():
    rules = {r.id: r for r in skin_fit.load_rules().rules}
    occlusive = set().union(*(m.keys for m in rules["oily_heavy_occlusive"].matchers))
    assert occlusive == {"cocos_nucifera_oil", "isopropyl_myristate", "isopropyl_palmitate", "myristyl_myristate",
                         "isopropyl_isostearate", "theobroma_cacao_seed_butter"}
    aging = set().union(*(m.keys for m in rules["aging_actives"].matchers))
    assert aging == set(skin_fit.group_table()["retinoids"])   # peptides dropped: no verified source
    pigment = set().union(*(m.keys for m in rules["pigment_brighten"].matchers))
    assert {"niacinamide", "tranexamic_acid", "ascorbic_acid"} <= pigment and "alpha_arbutin" not in pigment


def test_rank_limits_taken_from_conflict_rules():
    rule = next(r for r in skin_fit.load_rules().rules if r.id == "sensitive_strong_acids")
    limits = rule.matchers[0].key_limits
    assert limits["salicylic_acid"] == 20 and limits["glycolic_acid"] == 14


BAD_RULES = [
    "{id: x, when: {sensitive: true}, match: {keys: [no_such_key]}, verdict: good, reason_vi: a, source: 'https://x.y'}",
    "{id: x, when: {sensitive: true}, match: {group: no_such_group}, verdict: good, reason_vi: a, source: 'https://x.y'}",
    "{id: x, when: {sensitive: true}, match: {family: no_such_family}, verdict: good, reason_vi: a, source: 'https://x.y'}",
    "{id: x, when: {sensitive: true}, match: {keys: [niacinamide]}, verdict: good, reason_vi: a, source: 'http://x.y'}",
    "{id: x, when: {sensitive: true}, match: {keys: [niacinamide]}, verdict: good, reason_vi: a, source: ['https://x.y', 'ftp://x.y']}",
    "{id: x, when: {sensitive: true}, match: {keys: [niacinamide]}, verdict: great, reason_vi: a, source: 'https://x.y'}",
]


@pytest.mark.parametrize("bad", BAD_RULES)
def test_bad_rule_fails_loud(tmp_path, bad):
    path = tmp_path / "rules.yaml"
    path.write_text("version: 1\nrules:\n  - " + bad + "\n", encoding="utf-8")
    with pytest.raises(ValueError):
        skin_fit.load_rules(path)
