"""Key normalisation shared by the graph ETL and the search intent parser (Bước 04).

Rule (docs/neo4j/schema.md): lower -> strip Vietnamese accents (đ -> d) ->
``[^a-z0-9]+`` -> ``_`` -> trim ``_`` -> synonym lookup (``data/graph/synonyms.yaml``).
"""

from __future__ import annotations

import re
import unicodedata
from functools import lru_cache
from pathlib import Path
from typing import Any, Mapping

import yaml

DATA_DIR = Path(__file__).resolve().parents[1] / "data" / "graph"
SYNONYMS_PATH = DATA_DIR / "synonyms.yaml"
SYNONYM_GROUPS = ("categories", "skin_types", "concerns", "benefits")

_NON_ALNUM = re.compile(r"[^a-z0-9]+")


def strip_accents(text: str) -> str:
    """Remove Vietnamese/Latin diacritics; ``đ``/``Đ`` become ``d``/``D``."""

    text = str(text or "").replace("đ", "d").replace("Đ", "D")
    decomposed = unicodedata.normalize("NFKD", text)
    return "".join(ch for ch in decomposed if not unicodedata.combining(ch))


def slugify(text: Any) -> str:
    """Steps 1-5 of the key rule (no synonym lookup)."""

    folded = strip_accents(str(text or "")).lower()
    return _NON_ALNUM.sub("_", folded).strip("_")


def normalize_key(text: Any, synonyms: Mapping[str, str] | None = None) -> str:
    """Full key rule. ``synonyms`` maps slug variant -> canonical key (default: all groups)."""

    slug = slugify(text)
    table = load_synonyms() if synonyms is None else synonyms
    return table.get(slug, slug)


@lru_cache(maxsize=4)
def _load_yaml(path: str) -> dict[str, Any]:
    with open(path, encoding="utf-8") as handle:
        return yaml.safe_load(handle) or {}


def load_synonym_groups(path: Path | str | None = None) -> dict[str, dict[str, dict[str, Any]]]:
    """Raw groups: ``{group: {canonical_key: {name, aliases}}}``."""

    data = _load_yaml(str(path or SYNONYMS_PATH))
    return {group: dict(data.get(group) or {}) for group in SYNONYM_GROUPS}


@lru_cache(maxsize=32)
def group_synonyms(group: str, path: Path | str | None = None) -> dict[str, str]:
    """Variant slug -> canonical key for one group (canonical maps to itself)."""

    table: dict[str, str] = {}
    for key, spec in load_synonym_groups(path).get(group, {}).items():
        canonical = slugify(key)
        table[canonical] = canonical
        spec = spec or {}
        for variant in [spec.get("name", "")] + list(spec.get("aliases") or []):
            slug = slugify(variant)
            if slug:
                table.setdefault(slug, canonical)
    return table


@lru_cache(maxsize=4)
def load_synonyms(path: Path | str | None = None) -> dict[str, str]:
    """Variant slug -> canonical key across all groups (first group wins on clashes)."""

    merged: dict[str, str] = {}
    for group in SYNONYM_GROUPS:
        for slug, canonical in group_synonyms(group, path).items():
            merged.setdefault(slug, canonical)
    return merged


@lru_cache(maxsize=32)
def display_names(group: str, path: Path | str | None = None) -> dict[str, str]:
    """Canonical key -> display name for one group."""

    return {
        slugify(key): str((spec or {}).get("name") or key)
        for key, spec in load_synonym_groups(path).get(group, {}).items()
    }
