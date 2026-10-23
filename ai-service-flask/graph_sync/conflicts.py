"""Active-ingredient conflict rules (U09): YAML -> ingredient-key pairs, plus the pure evaluation used by
the cart analysis. No database access.

``data/graph/conflict_rules.yaml`` is the reviewed source. ``rules.conflict_pairs()`` merges it with the
older ``CONFLICT_RULES`` of ``recommendation_service/knowledge/ingredient_rules.py`` (kept compatible).
"""

from __future__ import annotations

from dataclasses import dataclass, field
from functools import lru_cache
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence

import yaml

from .normalize import DATA_DIR

CONFLICT_RULES_PATH = DATA_DIR / "conflict_rules.yaml"
SEVERITIES = ("low", "medium", "high")          # ascending
TIMINGS = ("same_routine", "same_session", "ok_alternate_days")
EVIDENCE_LEVELS = ("limited", "moderate", "strong")
NO_RANK_LIMIT = 10_000


class RuleError(ValueError):
    """The YAML is malformed (unknown group/key/severity ...)."""


@dataclass(frozen=True)
class ConflictRule:
    id: str
    a: tuple[str, ...]            # expanded ingredient keys
    b: tuple[str, ...]
    severity: str
    timing: str
    advice_vi: str
    source: str
    evidence: str = "moderate"


@dataclass(frozen=True)
class RuleSet:
    rules: tuple[ConflictRule, ...]
    rank_limits: Mapping[str, int] = field(default_factory=dict)
    ignore_categories: frozenset[str] = frozenset()
    rinse_off_categories: frozenset[str] = frozenset()

    def rank_limit(self, key: str) -> int:
        return int(self.rank_limits.get(key, NO_RANK_LIMIT))

    def keys(self) -> set[str]:
        out: set[str] = set()
        for rule in self.rules:
            out.update(rule.a)
            out.update(rule.b)
        return out


@dataclass(frozen=True)
class Pair:
    """One unordered ingredient pair (a < b) with the strongest rule that produced it."""

    a: str
    b: str
    rule_id: str
    severity: str
    timing: str
    advice_vi: str
    evidence: str


def _expand(entries: Iterable[Any], groups: Mapping[str, Sequence[str]], where: str) -> tuple[str, ...]:
    out: list[str] = []
    for entry in entries or []:
        name = str(entry)
        for key in groups.get(name, [name]):
            if key not in out:
                out.append(key)
    if not out:
        raise RuleError(f"{where}: empty ingredient list")
    return tuple(out)


@lru_cache(maxsize=4)
def _load_raw(path: str) -> dict[str, Any]:
    with open(path, encoding="utf-8") as handle:
        return yaml.safe_load(handle) or {}


def load_rule_set(path: Path | str | None = None) -> RuleSet:
    raw = _load_raw(str(path or CONFLICT_RULES_PATH))
    groups = {str(k): [str(x) for x in (v or [])] for k, v in (raw.get("groups") or {}).items()}
    rules: list[ConflictRule] = []
    seen: set[str] = set()
    for item in raw.get("rules") or []:
        rid = str(item.get("id") or "").strip()
        if not rid or rid in seen:
            raise RuleError(f"duplicate or missing rule id: {rid!r}")
        seen.add(rid)
        severity, timing = str(item.get("severity")), str(item.get("timing"))
        if severity not in SEVERITIES:
            raise RuleError(f"{rid}: bad severity {severity!r}")
        if timing not in TIMINGS:
            raise RuleError(f"{rid}: bad timing {timing!r}")
        evidence = str(item.get("evidence") or "moderate")
        if evidence not in EVIDENCE_LEVELS:
            raise RuleError(f"{rid}: bad evidence {evidence!r}")
        for needed in ("advice_vi", "source"):
            if not str(item.get(needed) or "").strip():
                raise RuleError(f"{rid}: {needed} is required")
        rules.append(ConflictRule(
            id=rid, a=_expand(item.get("a"), groups, f"{rid}.a"), b=_expand(item.get("b"), groups, f"{rid}.b"),
            severity=severity, timing=timing, advice_vi=str(item["advice_vi"]).strip(),
            source=str(item["source"]).strip(), evidence=evidence,
        ))
    return RuleSet(
        rules=tuple(rules),
        rank_limits={str(k): int(v) for k, v in (raw.get("rank_limits") or {}).items()},
        ignore_categories=frozenset(str(x) for x in (raw.get("ignore_categories") or [])),
        rinse_off_categories=frozenset(str(x) for x in (raw.get("rinse_off_categories") or [])),
    )


def expand_pairs(rule_set: RuleSet | None = None) -> list[Pair]:
    """Every unordered ingredient pair, strongest severity wins (ties: the rule listed first)."""

    rule_set = rule_set or load_rule_set()
    best: dict[tuple[str, str], Pair] = {}
    for rule in rule_set.rules:
        for x in rule.a:
            for y in rule.b:
                if x == y:
                    continue
                a, b = sorted((x, y))
                pair = Pair(a, b, rule.id, rule.severity, rule.timing, rule.advice_vi, rule.evidence)
                old = best.get((a, b))
                if old is None or SEVERITIES.index(pair.severity) > SEVERITIES.index(old.severity):
                    best[(a, b)] = pair
    return [best[k] for k in sorted(best)]


def validate_keys(rule_set: RuleSet, known_keys: Iterable[str]) -> set[str]:
    """Keys used by the rules that are not in ``known_keys`` (ingredient_aliases.yaml)."""

    return rule_set.keys() - set(known_keys)


# ---------------------------------------------------------------- pure evaluation (no Neo4j)


def lower_severity(severity: str) -> str | None:
    """One level down; ``None`` = dropped (low has nowhere to go)."""

    index = SEVERITIES.index(severity)
    return SEVERITIES[index - 1] if index > 0 else None


def counts_as_active(key: str, rank: int | None, rule_set: RuleSet) -> bool:
    return rank is None or int(rank) <= rule_set.rank_limit(key)


def evaluate_pair(
    ingredients_a: Iterable[tuple[str, int | None]],
    ingredients_b: Iterable[tuple[str, int | None]],
    rule_set: RuleSet,
    pairs: Mapping[tuple[str, str], Pair] | None = None,
) -> list[tuple[Pair, str, str]]:
    """Conflicts between two products given their ``(ingredient key, rank)`` lists.

    Returns ``(pair, key_in_a, key_in_b)`` triples, strongest first. Ingredients above their rank limit
    are ignored. Used by the Neo4j-down fallback and by the suggestion filter; the Neo4j path produces
    the same result through ``CONFLICTS_WITH`` edges.
    """

    table = pairs if pairs is not None else {(p.a, p.b): p for p in expand_pairs(rule_set)}
    left = [(k, r) for k, r in ingredients_a if counts_as_active(k, r, rule_set)]
    right = [(k, r) for k, r in ingredients_b if counts_as_active(k, r, rule_set)]
    hits: list[tuple[Pair, str, str]] = []
    seen: set[tuple[str, str]] = set()
    for ka, _ in left:
        for kb, _ in right:
            if ka == kb:
                continue
            pair = table.get(tuple(sorted((ka, kb))))  # type: ignore[arg-type]
            if pair is None or (pair.a, pair.b) in seen:
                continue
            seen.add((pair.a, pair.b))
            hits.append((pair, ka, kb))
    hits.sort(key=lambda h: (-SEVERITIES.index(h[0].severity), h[0].a, h[0].b))
    return hits
