"""U09: data/graph/conflict_rules.yaml - integrity, expansion, compatibility with the old CONFLICT_RULES."""

import pytest

from graph_sync.conflicts import (RuleError, RuleSet, evaluate_pair, expand_pairs, load_rule_set, lower_severity,
                                  validate_keys)
from graph_sync.ingredients import load_aliases
from graph_sync.rules import conflict_pairs

RULES = load_rule_set()


def test_every_rule_has_source_advice_and_valid_keys():
    assert not validate_keys(RULES, load_aliases().keys())
    for rule in RULES.rules:
        assert rule.source and rule.advice_vi and rule.id
        assert rule.severity in ("low", "medium", "high")
    assert len({r.id for r in RULES.rules}) == len(RULES.rules)


def test_at_least_thirty_ingredient_pairs():
    pairs = expand_pairs(RULES)
    assert len(pairs) >= 30
    assert all(p.a < p.b for p in pairs)
    assert len({(p.a, p.b) for p in pairs}) == len(pairs)


def test_old_conflict_rules_are_still_covered():
    from graph_sync.rules import _load_conflict_source, load_conflict_groups

    source = _load_conflict_source().CONFLICT_RULES
    groups = load_conflict_groups()
    old = {tuple(sorted((x, y))) for r in source for x in groups[r.first] for y in groups[r.second] if x != y}
    new = {(p.a, p.b) for p in expand_pairs(RULES)}
    assert old and old <= new
    merged = {(p.a, p.b): p for p in conflict_pairs()}
    assert old <= set(merged) and all(p.severity in ("low", "medium", "high") for p in merged.values())
    assert merged[("retinol", "salicylic_acid")].severity == "high" and merged[("retinol", "salicylic_acid")].rule_id


def test_well_known_pairs_and_non_pairs():
    table = {(p.a, p.b): p for p in expand_pairs(RULES)}
    assert table[("glycolic_acid", "retinol")].severity == "high"
    assert table[("benzoyl_peroxide", "retinol")].severity == "medium"
    assert ("adapalene", "benzoyl_peroxide") not in table          # approved fixed combination
    assert ("ascorbic_acid", "niacinamide") not in table           # myth, deliberately absent
    assert ("niacinamide", "retinol") not in table
    assert ("retinol", "retinyl_palmitate") not in table


def test_strongest_severity_wins_when_two_rules_share_a_pair(tmp_path):
    path = tmp_path / "r.yaml"
    path.write_text(
        "rules:\n"
        "  - {id: weak, a: [x], b: [y], severity: low, timing: same_session, advice_vi: a, source: s}\n"
        "  - {id: strong, a: [y], b: [x], severity: high, timing: same_routine, advice_vi: b, source: s}\n",
        encoding="utf-8")
    pairs = expand_pairs(load_rule_set(path))
    assert [(p.a, p.b, p.rule_id, p.severity) for p in pairs] == [("x", "y", "strong", "high")]


@pytest.mark.parametrize("body", [
    "rules:\n  - {id: r, a: [x], b: [y], severity: nope, timing: same_session, advice_vi: a, source: s}\n",
    "rules:\n  - {id: r, a: [x], b: [y], severity: low, timing: whenever, advice_vi: a, source: s}\n",
    "rules:\n  - {id: r, a: [x], b: [y], severity: low, timing: same_session, advice_vi: a}\n",
    "rules:\n  - {id: r, a: [], b: [y], severity: low, timing: same_session, advice_vi: a, source: s}\n",
])
def test_malformed_rules_are_rejected(tmp_path, body):
    path = tmp_path / "bad.yaml"
    path.write_text(body, encoding="utf-8")
    with pytest.raises(RuleError):
        load_rule_set(path)


def test_rank_limit_drops_ph_adjuster_hits():
    # lactic acid at rank 16 (a pH adjuster) must not conflict with retinol; at rank 3 it does.
    assert evaluate_pair([("retinol", 20)], [("lactic_acid", 16)], RULES) == []
    hits = evaluate_pair([("retinol", 20)], [("lactic_acid", 3)], RULES)
    assert [(h[0].rule_id, h[1], h[2]) for h in hits] == [("retinoid_aha", "retinol", "lactic_acid")]


def test_evaluate_pair_ignores_same_ingredient_and_orders_by_severity():
    hits = evaluate_pair([("retinol", 5), ("ascorbic_acid", 2)], [("salicylic_acid", 4), ("retinol", 9)], RULES)
    assert [h[0].severity for h in hits] == sorted([h[0].severity for h in hits], key=lambda s: -("low", "medium", "high").index(s))
    assert hits[0][0].severity == "high"
    assert all(h[1] != h[2] for h in hits)


def test_lower_severity():
    assert (lower_severity("high"), lower_severity("medium"), lower_severity("low")) == ("medium", "low", None)
    assert isinstance(RULES, RuleSet)
