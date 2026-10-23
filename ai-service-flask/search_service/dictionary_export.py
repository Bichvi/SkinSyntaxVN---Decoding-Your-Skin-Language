"""Export of the public search dictionary for PHP autocomplete (U04).

PHP cannot read Neo4j/YAML, so brands and categories (+ accent-free synonyms) are exported as a
static JSON file (``backend/app/data/search_dictionary.json``). Only public names/keys are exported:
no ids, no counts, no personal data. The pure functions here take an ``EntityDictionary`` snapshot, so
tests need no database.
"""

from __future__ import annotations

import contextlib
import json
import os
import re
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from graph_sync.normalize import strip_accents

from .dictionary import DictionarySnapshot

SCHEMA_VERSION = 1
_SPACES = re.compile(r"\s+")


def fold(text: Any) -> str:
    """Lower-case, accent-free, single-spaced (same rule as PHP ``SearchSuggestService::fold``)."""

    return _SPACES.sub(" ", strip_accents(str(text or "")).lower()).strip()


def build_export(snapshot: DictionarySnapshot, *, generated_at: str | None = None) -> dict[str, Any]:
    """``{version, generated_at, source, brands[], categories[]}`` from a dictionary snapshot.

    brand:    ``{key, name, fold}``
    category: ``{key, name, fold, aliases[]}`` - aliases are accent-free synonyms (excluding ``fold``).
    Lists are sorted so the file only changes when the data changes (except ``generated_at``).
    """

    brands: list[dict[str, str]] = []
    categories: dict[str, dict[str, Any]] = {}
    for (entity_type, key), name in snapshot.names.items():
        display = str(name or "").strip()
        if not key or not display:
            continue
        if entity_type == "brand":
            brands.append({"key": key, "name": display, "fold": fold(display)})
        elif entity_type == "category":
            categories[key] = {"key": key, "name": display, "fold": fold(display), "aliases": []}

    for tokens, (entity_type, key) in snapshot.surface.items():
        if entity_type != "category" or key not in categories:
            continue
        alias = " ".join(tokens)
        entry = categories[key]
        if alias and alias != entry["fold"] and alias not in entry["aliases"]:
            entry["aliases"].append(alias)

    category_list = sorted(categories.values(), key=lambda c: (c["fold"], c["key"]))
    for entry in category_list:
        entry["aliases"].sort()
    brands.sort(key=lambda b: (b["fold"], b["key"]))
    return {
        "version": SCHEMA_VERSION,
        "generated_at": generated_at or datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "source": snapshot.source,
        "brands": brands,
        "categories": category_list,
    }


def write_export(payload: dict[str, Any], path: str | os.PathLike[str]) -> Path:
    """Atomic UTF-8 write (temp file + replace) so PHP never reads a half-written file."""

    target = Path(path)
    target.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp_name = tempfile.mkstemp(prefix=target.name + ".", suffix=".tmp", dir=str(target.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            json.dump(payload, handle, ensure_ascii=False, indent=1)
            handle.write("\n")
        os.replace(tmp_name, target)
    except Exception:
        with contextlib.suppress(OSError):
            os.unlink(tmp_name)
        raise
    return target
