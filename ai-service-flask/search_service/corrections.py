"""Query corrections for "Có phải bạn muốn tìm..." (U05). Pure functions, no I/O.

``find_corrections(q, snapshot)`` reuses the intent parser (``trace``) to learn which words were
*fuzzy* brand hits (a typo, e.g. ``toriden`` -> ``Torriden``) and which were *synonym* abbreviations
(``srm`` -> ``sữa rửa mặt``, an expansion, not an error). ``corrected_q`` keeps the user's other words
untouched (accents, casing) and only swaps the recognised pieces for their display names.
"""

from __future__ import annotations

import re
from typing import Any

from .dictionary import DictionarySnapshot
from .intent_parser import _TOKEN, clean, normalize, parse
from .schemas import Intent

FUZZY = "fuzzy"
SYNONYM = "synonym"
MAX_CORRECTIONS = 6
MAX_ABBREVIATION_LEN = 4
_VOWELS = re.compile(r"[aeiouy]")
# Entity types whose abbreviations are expanded (brands are handled by the fuzzy path).
_EXPANDABLE_TYPES = frozenset({"category", "skin_type", "concern", "benefit"})


def _word_tokens(word: str) -> tuple[str, ...]:
    return tuple(_TOKEN.findall(normalize(word)))


def _is_abbreviation(tokens: list[str]) -> bool:
    """Clear abbreviations only: one short token without vowels (``srm``, ``kcn``, ``lcl``)."""

    return len(tokens) == 1 and 2 <= len(tokens[0]) <= MAX_ABBREVIATION_LEN and not _VOWELS.search(tokens[0])


def _expansion(name: str) -> str:
    """Display name -> plain lowercase phrase (``Toner / Nước Cân Bằng Da`` keeps its first form)."""

    first = name.split("/")[0].strip()
    return first.lower()


def find_corrections(q: Any, dictionary: DictionarySnapshot, intent: Intent | None = None,
                     trace: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    """Return ``{"corrected_q": str, "corrections": [{"from", "to", "type"}]}``.

    Pass ``trace`` (the list given to :func:`parse`) together with ``intent`` to avoid parsing twice.
    ``corrected_q == clean(q)`` when nothing was recognised as a correction.
    """

    raw = clean(q)
    if trace is None or intent is None:
        trace = []
        intent = parse(raw, dictionary, trace=trace)
    corrections: list[dict[str, str]] = []
    replacements: list[tuple[tuple[str, ...], str]] = []  # (normalized word tokens, replacement text)
    for event in sorted(trace, key=lambda e: e.get("pos", 0)):
        if len(corrections) >= MAX_CORRECTIONS:
            break
        if event["kind"] == "fuzzy":
            target = dictionary.display_name("brand", event["key"])
            source = (event["token"],)
            kind = FUZZY
        elif event["kind"] == "match" and event["type"] in _EXPANDABLE_TYPES and _is_abbreviation(event["tokens"]):
            name = dictionary.display_name(event["type"], event["key"])
            target = _expansion(name)
            source = tuple(event["tokens"])
            kind = SYNONYM
        else:
            continue
        if not target or normalize(target) == " ".join(source):
            continue  # already the display form
        corrections.append({"from": " ".join(source), "to": target, "type": kind})
        replacements.append((source, target))

    words = raw.split()
    out: list[str] = []
    pending = list(replacements)
    for word in words:
        tokens = _word_tokens(word)
        for i, (source, target) in enumerate(pending):
            if tokens == source:
                out.append(target)
                del pending[i]
                break
        else:
            out.append(word)
    # Show the user's original spelling in ``from`` (accents/casing), not the folded token.
    originals = {_word_tokens(w): w for w in words}
    for item in corrections:
        item["from"] = originals.get(tuple(item["from"].split()), item["from"])
    return {"corrected_q": " ".join(out), "corrections": corrections}
