"""Deterministic tag inference (benefits / concerns / skin types) and conflict pairs. Pure."""

from __future__ import annotations

import importlib.util
import sys
from dataclasses import dataclass, field
from functools import lru_cache
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence

import yaml

from .normalize import DATA_DIR

RULES_PATH = DATA_DIR / "ingredient_rules.yaml"
CONFLICT_SOURCE = Path(__file__).resolve().parents[1] / "recommendation_service" / "knowledge" / "ingredient_rules.py"
TOP_N_FOR_TAGS = 15
TAG_GROUPS = ("benefits", "concerns", "skin_types")


@dataclass(frozen=True)
class TagRule:
    benefits: tuple[str, ...] = ()
    concerns: tuple[str, ...] = ()
    skin_types: tuple[str, ...] = ()


@dataclass(frozen=True)
class ConflictPair:
    a: str           # smaller ingredient key
    b: str
    severity: str    # high | medium | low (legacy rules: "same_session")
    reason: str      # advice text (kept for readers of the old edge property)
    timing: str = "same_session"
    advice_vi: str = ""
    rule_id: str = ""
    evidence: str = ""


@dataclass
class Tags:
    benefits: list[str] = field(default_factory=list)
    concerns: list[str] = field(default_factory=list)
    skin_types: list[str] = field(default_factory=list)
    skin_type_source: str | None = None     # "data" | "inferred" | None

    def as_dict(self) -> dict[str, Any]:
        return {
            "benefits": self.benefits,
            "concerns": self.concerns,
            "skin_types": self.skin_types,
            "skin_type_source": self.skin_type_source,
        }


@lru_cache(maxsize=4)
def _load_rules_file(path: str) -> dict[str, Any]:
    with open(path, encoding="utf-8") as handle:
        return yaml.safe_load(handle) or {}


@lru_cache(maxsize=4)
def load_rules(path: Path | str | None = None) -> dict[str, TagRule]:
    raw = _load_rules_file(str(path or RULES_PATH)).get("rules") or {}
    return {
        str(key): TagRule(**{group: tuple((spec or {}).get(group) or ()) for group in TAG_GROUPS})
        for key, spec in raw.items()
    }


@lru_cache(maxsize=4)
def load_conflict_groups(path: Path | str | None = None) -> dict[str, list[str]]:
    raw = _load_rules_file(str(path or RULES_PATH)).get("conflict_groups") or {}
    return {str(k): [str(x) for x in (v or [])] for k, v in raw.items()}


def infer_tags(
    ingredient_keys: Sequence[str],
    product: Mapping[str, Any] | None = None,
    rules: Mapping[str, TagRule] | None = None,
    top_n: int = TOP_N_FOR_TAGS,
) -> Tags:
    """Existing fields first (``product['skin_types']``), rules on the first ``top_n`` ingredients.

    Benefits/concerns do not exist in Mongo (0% coverage) so they always come from rules.
    Skin types come from rules only when the product has none of its own.
    Output lists keep first-seen order (ingredient rank order) and are de-duplicated.
    """

    table = load_rules() if rules is None else rules
    product = product or {}
    tags = Tags()
    inferred_skin: list[str] = []
    for key in list(ingredient_keys)[:top_n]:
        rule = table.get(key)
        if rule is None:
            continue
        _extend(tags.benefits, rule.benefits)
        _extend(tags.concerns, rule.concerns)
        _extend(inferred_skin, rule.skin_types)
    own_skin = [s for s in (product.get("skin_types") or []) if s]
    if own_skin:
        _extend(tags.skin_types, own_skin)
        tags.skin_type_source = "data"
    elif inferred_skin:
        tags.skin_types = inferred_skin
        tags.skin_type_source = "inferred"
    return tags


def _extend(target: list[str], values: Iterable[str]) -> None:
    for value in values:
        if value not in target:
            target.append(value)


def helps_edges(rules: Mapping[str, TagRule] | None = None) -> list[dict[str, str]]:
    """``(Ingredient)-[:HELPS]->(Concern)`` rows from rule concerns."""

    table = load_rules() if rules is None else rules
    return [
        {"ingredient": key, "concern": concern}
        for key, rule in sorted(table.items())
        for concern in rule.concerns
    ]


def _load_conflict_source() -> Any:
    """Import knowledge/ingredient_rules.py by path (the package __init__ pulls heavy deps)."""

    spec = importlib.util.spec_from_file_location("_rs_ingredient_rules", CONFLICT_SOURCE)
    if spec is None or spec.loader is None:
        raise ImportError(f"cannot load {CONFLICT_SOURCE}")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module          # dataclasses need the module registered
    spec.loader.exec_module(module)
    return module


def conflict_pairs(
    source_rules: Iterable[Any] | None = None,
    groups: Mapping[str, Sequence[str]] | None = None,
    severity: str = "same_session",
) -> list[ConflictPair]:
    """Ingredient-key conflict pairs, undirected (a < b).

    With explicit ``source_rules``/``groups`` (tests, old callers): the legacy expansion of
    ``CONFLICT_RULES`` only, ``severity`` copied as before. With no arguments (the ETL): the legacy rules
    (``recommendation_service/knowledge/ingredient_rules.py``, kept compatible) merged with the reviewed
    ``data/graph/conflict_rules.yaml`` (U09); the YAML wins on a shared pair. Legacy-only pairs would get
    ``severity=medium``; today the YAML contains all of them, so none are left.
    """

    explicit = source_rules is not None or groups is not None
    if source_rules is None:
        source_rules = _load_conflict_source().CONFLICT_RULES
    groups = load_conflict_groups() if groups is None else groups
    pairs: dict[tuple[str, str], ConflictPair] = {}
    for rule in source_rules:
        for left in groups.get(rule.first, []):
            for right in groups.get(rule.second, []):
                if left == right:
                    continue
                a, b = sorted((left, right))
                if explicit:
                    pairs.setdefault((a, b), ConflictPair(a, b, severity, str(rule.resolution)))
                else:
                    pairs.setdefault((a, b), ConflictPair(
                        a, b, "medium", str(rule.resolution), "same_session", str(rule.resolution),
                        f"legacy_{rule.first}_{rule.second}".replace(" ", "_"), "moderate"))
    if not explicit:
        from .conflicts import expand_pairs

        for pair in expand_pairs():
            pairs[(pair.a, pair.b)] = ConflictPair(
                pair.a, pair.b, pair.severity, pair.advice_vi, pair.timing, pair.advice_vi, pair.rule_id, pair.evidence)
    return [pairs[k] for k in sorted(pairs)]
