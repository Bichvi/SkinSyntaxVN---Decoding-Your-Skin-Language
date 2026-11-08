"""Skin-fit verdict for a scanned ingredient list. Pure: no IO beyond reading the YAML rule files."""

from __future__ import annotations

import re
import unicodedata
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any, Mapping, Sequence

import yaml

from graph_sync.conflicts import CONFLICT_RULES_PATH, load_rule_set
from graph_sync.ingredients import load_aliases
from graph_sync.normalize import DATA_DIR

RULES_PATH = DATA_DIR / "skin_fit_rules.yaml"
GROUPS_PATH = DATA_DIR / "ingredient_groups.yaml"

SKIN_TYPES = frozenset({"dau", "kho", "hon_hop", "thuong"})
CONCERNS = frozenset({"mun", "tham", "lao_hoa", "lo_chan_long", "kho_rap", "do_kich_ung"})
VERDICTS = ("good", "caution", "avoid")
LOW_RECOGNITION_RATIO = 0.6

# Graph concern key (SearchSkinProfile) -> label-scan concern.
_CONCERN_MAP = {
    "mun": "mun", "tham_mun": "tham", "nam_tan_nhang": "tham", "lo_chan_long": "lo_chan_long",
    "kho_da": "kho_rap", "kich_ung": "do_kich_ung", "lao_hoa": "lao_hoa",
    "tham": "tham", "kho_rap": "kho_rap", "do_kich_ung": "do_kich_ung",
}


# ---------------------------------------------------------------- profile


@dataclass(frozen=True)
class Profile:
    skin_type: str | None
    sensitive: bool
    concerns: frozenset[str]

    @property
    def known(self) -> bool:
        return bool(self.skin_type or self.sensitive or self.concerns)


def fold(text: Any) -> str:
    """Lower-case, accent-free text with separators turned into single spaces."""

    decomposed = unicodedata.normalize("NFKD", str(text or "")).replace("đ", "d").replace("Đ", "D")
    plain = "".join(c for c in decomposed if not unicodedata.combining(c)).lower()
    return re.sub(r"[\s_\-]+", " ", plain).strip()


def _skin_from_text(raw: Any) -> tuple[str | None, bool, bool]:
    """Return (skin_type, sensitive, acne_skin) read from a graph key or free Vietnamese text."""

    text = fold(raw)
    if not text:
        return None, False, False
    sensitive = "nhay cam" in text
    if "hon hop" in text:
        return "hon_hop", sensitive, False
    if re.search(r"\bmun\b", text):
        return "dau", sensitive, True
    if "moi loai" in text or "thuong" in text:
        return "thuong", sensitive, False
    if re.search(r"\bdau\b", text):
        return "dau", sensitive, False
    if re.search(r"\bkho\b", text):
        return "kho", sensitive, False
    return None, sensitive, False


def normalize_profile(raw: Mapping | None, override: Mapping | None = None) -> Profile:
    raw = raw or {}
    skin_type: str | None = None
    sensitive = False
    concerns: set[str] = set()
    for source in (raw.get("skin_type"), raw.get("loai_da_name")):
        found, sens, acne = _skin_from_text(source)
        sensitive = sensitive or sens
        if acne:
            concerns.add("mun")
        if skin_type is None:
            skin_type = found
    raw_concerns = raw.get("concerns") or []
    if isinstance(raw_concerns, str):
        raw_concerns = [raw_concerns]
    wants_oily = False
    for item in raw_concerns:
        key = fold(item).replace(" ", "_")
        if key == "dau_thua":
            wants_oily = True
        elif key in _CONCERN_MAP:
            concerns.add(_CONCERN_MAP[key])
    if wants_oily and skin_type is None:
        skin_type = "dau"
    if override is not None:
        if "skin_type" in override:
            wanted = override["skin_type"]
            skin_type = wanted if wanted in SKIN_TYPES else None
        if "sensitive" in override:
            sensitive = bool(override["sensitive"])
    return Profile(skin_type, sensitive, frozenset(concerns))


# ---------------------------------------------------------------- rules


@dataclass(frozen=True)
class Matcher:
    keys: frozenset[str]
    rank_max: int | None = None
    key_limits: Mapping[str, int] | None = None   # per-key limits (conflict_rules.yaml rank_limits)

    def hit(self, key: str, rank: int | None) -> bool:
        if key not in self.keys:
            return False
        limit = self.rank_max
        if self.key_limits is not None:
            limit = self.key_limits.get(key)
        return rank is None or limit is None or rank <= limit


@dataclass(frozen=True)
class Rule:
    id: str
    skin_types: frozenset[str]
    sensitive: bool
    concerns: frozenset[str]
    matchers: tuple[Matcher, ...]
    verdict: str
    reason_vi: str
    sources: tuple[str, ...]

    def applies(self, profile: Profile) -> bool:
        return bool(
            (profile.skin_type and profile.skin_type in self.skin_types)
            or (self.sensitive and profile.sensitive)
            or (self.concerns & profile.concerns)
        )


@dataclass(frozen=True)
class SkinFitRules:
    rules: tuple[Rule, ...]


@dataclass(frozen=True)
class Finding:
    rule_id: str
    reason_vi: str
    keys: tuple[str, ...]


@dataclass(frozen=True)
class SkinFit:
    verdict: str
    good: tuple[Finding, ...]
    caution: tuple[Finding, ...]
    avoid: tuple[Finding, ...]
    offending_keys: frozenset[str]

    def to_dict(self, profile: Profile, display: Mapping[str, str] | None = None) -> dict:
        display = display or {}

        def render(findings: tuple[Finding, ...]) -> list[dict]:
            return [{"rule_id": f.rule_id, "reason_vi": f.reason_vi,
                     "ingredients": [display.get(k, k) for k in f.keys]} for f in findings]

        return {
            "verdict": self.verdict,
            "profile": {"skin_type": profile.skin_type, "sensitive": profile.sensitive,
                        "concerns": sorted(profile.concerns)},
            "good": render(self.good),
            "caution": render(self.caution),
            "avoid": render(self.avoid),
        }


def _yaml(path: Path | str) -> dict[str, Any]:
    with open(path, encoding="utf-8") as handle:
        return yaml.safe_load(handle) or {}


def group_table() -> dict[str, tuple[str, ...]]:
    """Group name -> ingredient keys: conflict_rules.yaml ``groups`` plus ingredient_groups.yaml ``exclusion_groups``.

    Exclusion groups expand keys/roles/key_contains/key_suffix against ingredient_aliases.yaml (see its header).
    """

    entries = {e.key: e for e in load_aliases().values()}
    table: dict[str, tuple[str, ...]] = {}
    for name, keys in (_yaml(CONFLICT_RULES_PATH).get("groups") or {}).items():
        table[str(name)] = tuple(str(k) for k in keys or [])
    for name, spec in (_yaml(GROUPS_PATH).get("exclusion_groups") or {}).items():
        spec = spec or {}
        roles = set(spec.get("roles") or [])
        contains = [str(x) for x in spec.get("key_contains") or []]
        suffixes = [str(x) for x in spec.get("key_suffix") or []]
        keys = [str(k) for k in spec.get("keys") or []]
        for key, entry in entries.items():
            if entry.role in roles or any(c in key for c in contains) or any(key.endswith(s) for s in suffixes):
                keys.append(key)
        table[str(name)] = tuple(dict.fromkeys(keys))
    return table


def family_table() -> dict[str, tuple[str, ...]]:
    raw = _yaml(GROUPS_PATH).get("include_families") or {}
    return {str(k): tuple(str(x) for x in v or []) for k, v in raw.items()}


def _matcher(rule_id: str, item: Mapping, known: Mapping, groups: Mapping, families: Mapping,
             rank_limits: Mapping[str, int]) -> Matcher:
    selectors = [s for s in ("keys", "group", "family") if s in item]
    if len(selectors) != 1:
        raise ValueError(f"{rule_id}: match item needs exactly one of keys/group/family")
    kind = selectors[0]
    if kind == "keys":
        keys = tuple(str(k) for k in item["keys"])
    elif kind == "group":
        if item["group"] not in groups:
            raise ValueError(f"{rule_id}: unknown group {item['group']!r}")
        keys = groups[item["group"]]
    else:
        if item["family"] not in families:
            raise ValueError(f"{rule_id}: unknown family {item['family']!r}")
        keys = families[item["family"]]
    unknown = [k for k in keys if k not in known]
    if not keys or unknown:
        raise ValueError(f"{rule_id}: unknown or empty ingredient keys {unknown}")
    rank_max = item.get("rank_max")
    if rank_max is not None and (not isinstance(rank_max, int) or isinstance(rank_max, bool) or rank_max < 1):
        raise ValueError(f"{rule_id}: bad rank_max {rank_max!r}")
    limits = dict(rank_limits) if item.get("rank_limits") else None
    return Matcher(frozenset(keys), rank_max, limits)


@lru_cache(maxsize=4)
def _load(path: str) -> SkinFitRules:
    raw = _yaml(path)
    known = {e.key: e for e in load_aliases().values()}
    groups, families = group_table(), family_table()
    rank_limits = load_rule_set().rank_limits
    rules: list[Rule] = []
    seen: set[str] = set()
    for item in raw.get("rules") or []:
        rid = str(item.get("id") or "").strip()
        if not rid or rid in seen:
            raise ValueError(f"duplicate or missing rule id: {rid!r}")
        seen.add(rid)
        when = item.get("when") or {}
        skin_types = frozenset(str(x) for x in when.get("skin_type") or [])
        concerns = frozenset(str(x) for x in when.get("concerns") or [])
        if not skin_types <= SKIN_TYPES or not concerns <= CONCERNS:
            raise ValueError(f"{rid}: unknown skin_type/concern in 'when'")
        if not (skin_types or concerns or when.get("sensitive")):
            raise ValueError(f"{rid}: empty 'when'")
        verdict = str(item.get("verdict"))
        if verdict not in VERDICTS:
            raise ValueError(f"{rid}: bad verdict {verdict!r}")
        raw_source = item.get("source") or []
        sources = tuple(str(u) for u in (raw_source if isinstance(raw_source, list) else [raw_source]))
        reason = str(item.get("reason_vi") or "").strip()
        if not sources or not all(u.startswith("https://") for u in sources) or not reason:
            raise ValueError(f"{rid}: source (https) and reason_vi are required")
        match = item.get("match")
        items = match if isinstance(match, list) else [match]
        matchers = tuple(_matcher(rid, m or {}, known, groups, families, rank_limits) for m in items)
        rules.append(Rule(rid, skin_types, bool(when.get("sensitive")), concerns, matchers, verdict, reason, sources))
    return SkinFitRules(tuple(rules))


def load_rules(path: Path | str | None = None) -> SkinFitRules:
    return _load(str(path or RULES_PATH))


# ---------------------------------------------------------------- evaluation


def evaluate(ingredients: Sequence[tuple[str, int | None]], profile: Profile,
             rules: SkinFitRules | None = None, recognized_ratio: float = 1.0) -> SkinFit:
    if not profile.known:
        return SkinFit("unknown", (), (), (), frozenset())
    rules = rules or load_rules()
    seen: dict[str, int | None] = {}
    for key, rank in ingredients:
        seen.setdefault(key, rank)
    buckets: dict[str, list[Finding]] = {v: [] for v in VERDICTS}
    for rule in rules.rules:
        if not rule.applies(profile):
            continue
        hits = tuple(k for k, r in seen.items() if any(m.hit(k, r) for m in rule.matchers))
        if hits:
            buckets[rule.verdict].append(Finding(rule.id, rule.reason_vi, hits))
    # A caution fully covered by an avoid finding would only repeat the same warning.
    avoided = {k for f in buckets["avoid"] for k in f.keys}
    caution = tuple(f for f in buckets["caution"] if not set(f.keys) <= avoided)
    good, avoid = tuple(buckets["good"]), tuple(buckets["avoid"])
    offending = frozenset(k for f in caution + avoid for k in f.keys)
    verdict = "avoid" if avoid else "caution" if caution else "good" if good else "neutral"
    if verdict == "good" and recognized_ratio < LOW_RECOGNITION_RATIO:
        verdict = "neutral"
    return SkinFit(verdict, good, caution, avoid, offending)
