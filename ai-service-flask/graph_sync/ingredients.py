"""Parse raw ingredient text (``thanh_phan_full`` / ``thanh_phan_sach``) into ranked refs. Pure."""

from __future__ import annotations

import re
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any, Mapping

import yaml

from .normalize import DATA_DIR, slugify

ALIASES_PATH = DATA_DIR / "ingredient_aliases.yaml"
MAX_INGREDIENTS = 30
MAX_WORDS = 6        # longer fragments are descriptive sentences, not ingredient names
MAX_CHARS = 60


@dataclass(frozen=True)
class IngredientRef:
    key: str
    inci: str | None      # canonical INCI display name; None = not in ingredient_aliases.yaml
    name: str             # display name (INCI when mapped, cleaned raw text otherwise)
    rank: int             # 1-based position after de-duplication
    name_vi: str | None = None
    role: str | None = None


@dataclass(frozen=True)
class AliasEntry:
    key: str
    inci: str
    name_vi: str | None
    role: str | None


@lru_cache(maxsize=4)
def _load_alias_file(path: str) -> dict[str, Any]:
    with open(path, encoding="utf-8") as handle:
        return yaml.safe_load(handle) or {}


@lru_cache(maxsize=4)
def load_aliases(path: Path | str | None = None) -> dict[str, AliasEntry]:
    """Variant slug -> AliasEntry. The canonical key and INCI name map to themselves."""

    table: dict[str, AliasEntry] = {}
    for key, spec in (_load_alias_file(str(path or ALIASES_PATH)).get("ingredients") or {}).items():
        spec = spec or {}
        canonical = slugify(key)
        entry = AliasEntry(canonical, str(spec.get("inci") or key), spec.get("name_vi"), spec.get("role"))
        for variant in [key, entry.inci] + list(spec.get("aliases") or []):
            slug = slugify(variant)
            if slug:
                table.setdefault(slug, entry)
    return table


_BULLET = re.compile(r"^\s*[•\-\*●▪]\s*")
_PREFIX = re.compile(
    r"^\s*(?:(?:full\s+)?ingredients?\s*:"
    r"|(?:thành phần|thanh phan)(?:\s+(?:sản phẩm|san pham|chính|đầy đủ|day du))?\s*[:\-]?)\s*",
    re.I,
)
_PARENS = re.compile(r"\([^()]*\)|\[[^\[\]]*\]|\{[^{}]*\}")
_PERCENT = re.compile(r"\d+(?:[.,]\d+)?\s*%")
_DIGIT_COMMA = re.compile(r"(?<=\d),(?=\d)")
_SPLIT = re.compile(r"[,;\n]|\s/|/\s")
_STRIP = " \t.*:·•-–—+\"'`"
_COMMA_TOKEN = "\u0000"


def _bullet_heads(text: str) -> list[str]:
    """``• Name: description`` lines -> ``Name`` (descriptive marketing format)."""

    heads: list[str] = []
    for line in text.split("\n"):
        if not _BULLET.match(line):
            continue
        body = _BULLET.sub("", line)
        heads.append(body.split(":", 1)[0])
    return heads


def clean_fragments(raw: Any) -> list[str]:
    """Split raw text into cleaned candidate names (no mapping, no de-duplication)."""

    if raw is None:
        return []
    if isinstance(raw, (list, tuple)):
        raw = ", ".join(str(x) for x in raw if x is not None)
    text = str(raw).replace("\r", "\n")
    text = re.sub(r"\n\s*-(?=\S)", "-", text)          # export artefact: "PEG\n-8" -> "PEG-8"
    if text.strip().lower() in {"", "none", "null", "nan"}:
        return []
    if "•" in text:
        text = "\n".join(_bullet_heads(text))
    text = _PREFIX.sub("", text)
    previous = None
    while previous != text:                              # nested brackets
        previous, text = text, _PARENS.sub(" ", text)
    text = text.replace("(", " ").replace(")", " ").replace("[", " ").replace("]", " ")
    text = _DIGIT_COMMA.sub(_COMMA_TOKEN, text)          # "1,2-Hexanediol" stays one name
    out: list[str] = []
    for piece in _SPLIT.split(text):
        piece = piece.replace(_COMMA_TOKEN, ",")
        piece = _PERCENT.sub(" ", piece)
        piece = re.sub(r"\s+", " ", piece).strip(_STRIP).strip()
        if not piece or not re.search(r"[^\W\d_]", piece):
            continue
        if len(piece) > MAX_CHARS or len(piece.split()) > MAX_WORDS:
            continue
        out.append(piece)
    return out


def parse_ingredients(
    raw: Any,
    aliases: Mapping[str, AliasEntry] | None = None,
    max_items: int = MAX_INGREDIENTS,
) -> list[IngredientRef]:
    """Ranked, de-duplicated refs (first occurrence wins); keeps the first ``max_items``."""

    table = load_aliases() if aliases is None else aliases
    refs: list[IngredientRef] = []
    seen: set[str] = set()
    for piece in clean_fragments(raw):
        slug = slugify(piece)
        if not slug:
            continue
        entry = table.get(slug)
        key = entry.key if entry else slug
        if key in seen:
            continue
        seen.add(key)
        if entry:
            ref = IngredientRef(key, entry.inci, entry.inci, len(refs) + 1, entry.name_vi, entry.role)
        else:
            ref = IngredientRef(key, None, piece, len(refs) + 1)
        refs.append(ref)
        if len(refs) >= max_items:
            break
    return refs
