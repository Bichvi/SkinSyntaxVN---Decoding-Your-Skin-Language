"""Deterministic Vietnamese search-query intent parser (Bước 04, no LLM).

``parse(q, dictionary) -> Intent``: clean -> normalise (accent-free, lower) -> extract price
-> longest-match-first n-gram lookup -> fuzzy brand (rapidfuzz) -> stopwords -> confidence.
Must stay fast (< 5ms with a loaded dictionary) because every recommend request calls it.
"""

from __future__ import annotations

import logging
import re
from threading import Lock
from typing import Any

from graph_sync.normalize import strip_accents

from . import config
from .dictionary import STOPWORDS, DictionarySnapshot, EntityDictionary
from .schemas import Intent, PriceRange

try:  # optional at import time so the module never breaks the API if the wheel is missing
    from rapidfuzz import fuzz, process
except Exception:  # pragma: no cover - exercised only without rapidfuzz installed
    fuzz = process = None

logger = logging.getLogger(__name__)

NEGATIONS = frozenset({"khong", "no", "non"})
NEGATION_SUFFIXES = frozenset({"free"})
# U10 negation scope: a cue followed by at most MAX_NEG_FILLERS filler words still negates the entity
# ("không chứa cồn", "không kỵ retinol", "không xung đột với BHA"). Lists continue the scope
# ("không chứa cồn và paraben"). ``CONFLICT_FILLERS`` turn an exclusion into "avoid conflicts with X".
NEG_CUES = NEGATIONS | frozenset({"ko", "tranh", "tru"})   # "loại" alone is not a cue ("loại retinol nào")
NEG_FILLERS = frozenset({"chua", "co", "dung", "them", "gay", "bi", "ky", "xung", "dot", "voi", "cung", "chung",
                         "la", "can", "phai", "lam", "chat", "thanh", "phan", "khac", "duoc"})
CONFLICT_FILLERS = frozenset({"ky", "xung", "dot", "khac"})
LIST_JOINERS = frozenset({"va", "hoac", "hay", "voi", "cung", "ma"})
MAX_NEG_FILLERS = 3
NEGATABLE_TYPES = frozenset({"ingredient", "concern", "benefit"})
FUZZY_MIN_LEN = 4
FUZZY_RATIO_GUARD = 8  # plain ratio must be >= cutoff - guard (blocks WRatio partial hits)

TYPE_FIELDS = {
    "brand": "brands",
    "category": "categories",
    "ingredient": "ingredients",
    "skin_type": "skin_types",
    "concern": "concerns",
    "benefit": "benefits",
}

_CONTROL = re.compile(r"[\x00-\x1f\x7f-\x9f]")
_DISALLOWED = re.compile(r"[^a-z0-9\s\-%.,<>]")
_TOKEN = re.compile(r"[a-z0-9]+")
_ALNUM = re.compile(r"[a-z0-9]")

_NUM = r"(?<![a-z0-9.,])(\d+(?:[.,]\d+)*)"
_UNIT = r"(?:\s*(k|nghin|ngan|trieu|tr|m|vnd|dong|d)(\d)?)?"
_END = r"(?![a-z0-9%])"
_KW = r"(?<![a-z])"
PRICE_PATTERNS = (
    ("range", re.compile(
        rf"{_KW}(?:(?:khoang|tu)\s*)?{_NUM}{_UNIT}\s*(?:-|~|den|toi)\s*{_NUM}{_UNIT}{_END}")),
    ("max", re.compile(
        rf"{_KW}(?:duoi|<=?|toi da|max|khong qua|it hon|nho hon|re hon)\s*{_NUM}{_UNIT}{_END}")),
    ("min", re.compile(rf"{_KW}(?:tren|>=?|tu|it nhat|min)\s*{_NUM}{_UNIT}{_END}")),
)
_THOUSANDS = re.compile(r"\d{1,3}(?:[.,]\d{3})+")
_MULTIPLIER = {"k": 1_000, "nghin": 1_000, "ngan": 1_000, "tr": 1_000_000, "trieu": 1_000_000,
               "m": 1_000_000, "vnd": 1, "dong": 1, "d": 1}
MAX_PRICE = 1_000_000_000


def clean(raw: Any) -> str:
    """Step 1: cap length and drop control characters (accents kept)."""

    if not isinstance(raw, str):
        return ""
    return _CONTROL.sub(" ", raw[: config.MAX_QUERY_LEN]).strip()


def normalize(text: str) -> str:
    """Step 2: accent-free, lower, only ``[a-z0-9 -%.,<>]``, single spaces."""

    folded = strip_accents(text).lower()
    return " ".join(_DISALLOWED.sub(" ", folded).split())


def _amount(number: str, unit: str | None, extra: str | None) -> int | None:
    if _THOUSANDS.fullmatch(number) and unit not in {"tr", "trieu", "m"}:
        value = float(re.sub(r"[.,]", "", number))
    else:
        try:
            value = float(number.replace(",", "."))
        except ValueError:
            return None
    if unit is None:
        value *= 1_000 if value < 10_000 else 1
    else:
        mult = _MULTIPLIER[unit]
        if extra and mult == 1_000_000 and float(value).is_integer():
            value += int(extra) / 10  # "1tr5" = 1.5 triệu
        value *= mult
    amount = int(round(value))
    return amount if 0 < amount <= MAX_PRICE else None


def extract_price(text: str) -> tuple[PriceRange | None, str, int]:
    """Step 3: returns (price, text without price phrases, alnum chars consumed)."""

    low: int | None = None
    high: int | None = None
    consumed = 0
    for kind, pattern in PRICE_PATTERNS:
        while True:
            match = pattern.search(text)
            if not match:
                break
            if kind == "range":
                n1, u1, x1, n2, u2, x2 = match.groups()
                a = _amount(n1, u1 or u2, x1 if u1 else None)
                b = _amount(n2, u2, x2)
                if a is not None and b is not None:
                    a, b = min(a, b), max(a, b)
                    low = a if low is None else low
                    high = b if high is None else high
            else:
                value = _amount(*match.groups())
                if value is not None:
                    if kind == "max" and high is None:
                        high = value
                    elif kind == "min" and low is None:
                        low = value
            consumed += len(_ALNUM.findall(match.group(0)))
            text = text[: match.start()] + " " + text[match.end():]
    if low is not None and high is not None and low > high:
        low, high = high, low
    price = PriceRange(low, high) if (low is not None or high is not None) else None
    return price, text, consumed


def _match(tokens: list[str], snap: DictionarySnapshot) -> list[tuple[int, int, str, str]]:
    """Step 4: global longest-match-first; returns (start, length, type, key) sorted by start."""

    used = [False] * len(tokens)
    found: list[tuple[int, int, str, str]] = []
    surface = snap.surface
    for n in range(min(snap.max_ngram, len(tokens)), 0, -1):
        for i in range(len(tokens) - n + 1):
            if any(used[i:i + n]):
                continue
            hit = surface.get(tuple(tokens[i:i + n]))
            if hit is None:
                continue
            found.append((i, n, hit[0], hit[1]))
            for j in range(i, i + n):
                used[j] = True
    found.sort()
    return found


def negation_scope(tokens: list[str], start: int, entity_pos: set[int],
                   previous: tuple[int, bool] | None = None) -> tuple[bool, bool]:
    """Is the entity at ``tokens[start]`` negated, and is it a "do not conflict with" (kỵ) phrase?

    ``previous`` = (end index, conflict flag) of the last negated entity, so "không chứa cồn và paraben"
    negates both. Returns ``(negated, conflict)``.
    """

    if previous is not None:
        gap = tokens[previous[0]:start]
        if len(gap) <= 2 and all(t in LIST_JOINERS for t in gap):
            return True, previous[1]
    for back in range(1, MAX_NEG_FILLERS + 2):
        idx = start - back
        if idx < 0 or idx in entity_pos:
            return False, False
        if tokens[idx] in NEG_CUES:
            between = tokens[idx + 1:start]
            if all(t in NEG_FILLERS for t in between):
                return True, any(t in CONFLICT_FILLERS for t in between)
            return False, False
        if tokens[idx] not in NEG_FILLERS:
            return False, False
    return False, False


def _fuzzy_brand(token: str, snap: DictionarySnapshot) -> str | None:
    """Step 5: rapidfuzz WRatio over compact brand forms, guarded by plain ratio."""

    if process is None or not snap.brand_forms:
        return None
    best = process.extractOne(token, snap.brand_forms, scorer=fuzz.WRatio,
                              score_cutoff=config.FUZZY_CUTOFF)
    if best is None:
        return None
    form = best[0]
    if fuzz.ratio(token, form) < config.FUZZY_CUTOFF - FUZZY_RATIO_GUARD:
        return None
    return snap.brand_form_keys.get(form)


_yaml_only: EntityDictionary | None = None
_yaml_lock = Lock()


def _snapshot(dictionary: Any) -> DictionarySnapshot:
    global _yaml_only
    if isinstance(dictionary, DictionarySnapshot):
        return dictionary
    if dictionary is not None:
        try:
            return dictionary.snapshot()
        except Exception as exc:
            logger.warning("intent parser: dictionary failed: %s", type(exc).__name__)
    with _yaml_lock:
        if _yaml_only is None:
            _yaml_only = EntityDictionary(driver=None, mongo_db=None)
    return _yaml_only.snapshot()


def parse(
    q: Any,
    dictionary: EntityDictionary | DictionarySnapshot | None = None,
    *,
    trace: list[dict[str, Any]] | None = None,
) -> Intent:
    """Parse ``q`` into an :class:`Intent`. Never raises on user input.

    ``trace`` (optional, U05): when a list is passed it receives one dict per recognised piece, in
    token order - ``{"kind": "match", "tokens": [...], "type": str, "key": str}`` for dictionary hits and
    ``{"kind": "fuzzy", "token": str, "key": str}`` for fuzzy brand hits - so callers can tell a typo
    correction from an exact entity. It does not change the returned Intent.
    """

    raw = clean(q)
    normalized = normalize(raw)
    if not normalized:
        return Intent(raw=raw, normalized="")

    snap = _snapshot(dictionary)
    price, rest, price_chars = extract_price(normalized)
    tokens = _TOKEN.findall(rest)
    matches = _match(tokens, snap)

    fields: dict[str, list[str]] = {name: [] for name in TYPE_FIELDS.values()}
    excluded: list[str] = []
    avoid_conflict: list[str] = []
    entity_pos: set[int] = set()
    last_negated: tuple[int, bool] | None = None
    for start, length, entity_type, key in matches:
        after = tokens[start + length] if start + length < len(tokens) else None
        negated, conflict = False, False
        if entity_type in NEGATABLE_TYPES:
            negated, conflict = negation_scope(tokens, start, entity_pos, last_negated)
            if not negated and after in NEGATION_SUFFIXES:
                negated = True
        if negated:
            # exclusion ("không cồn", "không kỵ retinol", "alcohol free"): tokens stay residual; U10 also records the key
            last_negated = (start + length, conflict)
            if entity_type == "ingredient":
                target = avoid_conflict if conflict else excluded
                if key not in target:
                    target.append(key)
            continue
        bucket = fields[TYPE_FIELDS[entity_type]]
        if key not in bucket:
            bucket.append(key)
        entity_pos.update(range(start, start + length))
        if trace is not None:
            trace.append({"kind": "match", "tokens": tokens[start:start + length],
                          "type": entity_type, "key": key, "pos": start})

    residual: list[str] = []
    entity_chars = price_chars
    residual_chars = 0
    for idx, token in enumerate(tokens):
        if idx in entity_pos:
            entity_chars += len(token)
            continue
        if token in STOPWORDS:
            continue
        if len(token) >= FUZZY_MIN_LEN and not token.isdigit() and token not in NEGATIONS:
            brand = _fuzzy_brand(token, snap)
            if brand:
                if brand not in fields["brands"]:
                    fields["brands"].append(brand)
                entity_chars += len(token)
                if trace is not None:
                    trace.append({"kind": "fuzzy", "token": token, "key": brand, "pos": idx})
                continue
        residual.append(token)
        residual_chars += len(token)

    total = entity_chars + residual_chars
    confidence = round(entity_chars / total, 2) if total else 0.0
    return Intent(
        raw=raw,
        normalized=normalized,
        price=price,
        residual_terms=residual,
        confidence=confidence,
        exclude_ingredients=excluded,
        avoid_conflict_with=avoid_conflict,
        **fields,
    )
