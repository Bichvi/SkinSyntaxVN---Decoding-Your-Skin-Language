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


class AliasTable(dict):
    """``dict[slug, AliasEntry]`` plus a lazily built "compact" index (slug without ``_``).

    The compact index maps spacing/punctuation variants ("Phenoxy ethanol", "1.2-hexanediol",
    "1,2-hexaned iol") onto the same entry. A compact form claimed by two *different* entries is
    ambiguous and is left out, so the lookup can never pick the wrong ingredient.
    """

    _compact: dict[str, AliasEntry] | None = None

    @property
    def compact(self) -> dict[str, AliasEntry]:
        if self._compact is None:
            self._compact = build_compact_index(self)
        return self._compact


def build_compact_index(table: Mapping[str, AliasEntry]) -> dict[str, AliasEntry]:
    seen: dict[str, AliasEntry] = {}
    ambiguous: set[str] = set()
    for slug, entry in table.items():
        compact = slug.replace("_", "")
        if not compact:
            continue
        other = seen.get(compact)
        if other is None:
            seen[compact] = entry
        elif other.key != entry.key:
            ambiguous.add(compact)
    return {c: e for c, e in seen.items() if c not in ambiguous}


@lru_cache(maxsize=4)
def load_aliases(path: Path | str | None = None) -> AliasTable:
    """Variant slug -> AliasEntry. The canonical key and INCI name map to themselves."""

    table = AliasTable()
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
# Marketing exports mix a descriptive block ("Thành phần chính: • Name: effect") with the real
# INCI list ("Thành phần chi tiết: Water, ..."); several products can be concatenated ("2. Name ...").
_MARKER = re.compile(
    r"(?P<detail>(?:thành phần (?:chi tiết|đầy đủ|toàn bộ)|full ingredients?|ingredients?)\s*:)"
    r"|(?P<main>thành phần (?:chính|nổi bật|hoạt chất)[^:\n]{0,40}:)",
    re.I,
)
_NUMBERED = re.compile(r"(?:^|\s)\d{1,2}\.\s+(?=\S)")
_PARENS = re.compile(r"\([^()]*\)|\[[^\[\]]*\]|\{[^{}]*\}")
_PERCENT = re.compile(r"\d+(?:[.,]\d+)?\s*%")
_TRAILING_DECIMAL = re.compile(r"\s+\d+[.,]\d+$")
_DIGIT_COMMA = re.compile(r"(?<=\d),(?=\d)")
_SPLIT = re.compile(r"[,;\n]|\s/|/\s|\s&\s|\s+và\s+|\s\.\s|\.\s+(?=[^\W_])")
# "Đà Nẵng 25g Aqua": product-title remnant glued to the first ingredient -> keep what follows the size.
_SIZE_PREFIX = re.compile(r"^.*?\b\d+(?:[.,]\d+)?\s?(?:g|gr|ml)\b\s+(?=\S)", re.I)
# Fragments that are never an ingredient on their own (halves of names broken by the export).
_JUNK_FRAGMENTS = frozenset({
    "copolymer", "crosspolymer", "green", "hydrogenated", "peg", "ppg", "glycol", "hexylene", "leaf",
    "stem_extract", "caprylic", "glycereth", "polyglyceryl", "phosphate", "triglyceride", "acrylates",
    "wax", "oil", "extract", "acid", "bis", "none", "caprylic_capric", "hydrogenated_poly",
})
_JUNK_PREFIXES = ("thanh_phan", "bang_thanh_phan")
# Vietnamese function words: an unmapped fragment containing one is a sentence, not a name.
_VI_SENTENCE_WORDS = frozenset({
    "và", "cho", "của", "được", "giúp", "có", "trên", "để", "với", "các", "những", "này", "làm", "hình",
    "đem", "đến", "giảm", "tăng", "chống", "bám", "ngừa", "điều", "tiết", "cải", "thiện", "từ",
})
# Unmapped Vietnamese bullet heads are kept only when they look like an ingredient name.
_VI_INGREDIENT_START = (
    "chiet_xuat", "tinh_chat", "tinh_dau", "dau_", "bo_", "nuoc_", "bot_", "sap_", "axit", "acid",
    "vitamin", "dich_", "men_", "ke", "dat_set", "than_", "mat_ong", "sua_",
)
_STRIP = " \t.*:·•-–—+\"'`"
_COMMA_TOKEN = "\u0000"
# ``thanh_phan_sach`` turns hyphens into commas ("peg, 18 methyl ether", "bis, peg, 18 ..."):
# a piece that starts with a number and follows one of these name stems is the rest of that name.
_NAME_STEM = re.compile(
    r"(?:^|[\s/-])(?:peg|ppg|polyglyceryl|ceteareth|steareth|laureth|oleth|ceteth|deceth|trideceth|"
    r"isoceteth|pareth|buteth|glycereth|nylon|polyquaternium|c\d{1,2})$",
    re.I,
)
_NUMBER_LEAD = re.compile(r"^\d{1,3}(?:/\d{1,3})?\s+[A-Za-z]")
_NON_ASCII = re.compile(r"[^\x00-\x7f]")


def _bullet_lines(text: str) -> list[tuple[str, str]]:
    """``• Name: description`` lines -> ``(Name, description)`` (descriptive marketing format)."""

    out: list[tuple[str, str]] = []
    for line in text.split("\n"):
        if not _BULLET.match(line):
            continue
        head, _, body = _BULLET.sub("", line).partition(":")
        out.append((head, body))
    return out


def _looks_like_inci_list(body: str) -> bool:
    """A bullet body that is itself a long ASCII comma list (not a Vietnamese sentence)."""

    pieces = [p.strip() for p in body.split(",") if p.strip()]
    if len(pieces) < 8:
        return False
    short_ascii = sum(1 for p in pieces if len(p.split()) <= 5 and not _NON_ASCII.search(p))
    return short_ascii / len(pieces) >= 0.7


def _split_marketing(text: str) -> tuple[list[str], list[str]]:
    """Split a mixed export into (ingredient-list texts, descriptive bullet heads)."""

    matches = list(_MARKER.finditer(text))
    if not matches and "•" not in text:
        return [text], []
    segments: list[tuple[str, str]] = []
    kind = "ingredients"
    cursor = 0
    for m in matches:
        segments.append((kind, text[cursor:m.start()]))
        kind = "ingredients" if m.group("detail") else "marketing"
        cursor = m.end()
    segments.append((kind, text[cursor:]))

    lists: list[str] = []
    heads: list[str] = []
    for idx, (seg_kind, seg) in enumerate(segments):
        next_is_main = idx + 1 < len(segments) and segments[idx + 1][0] == "marketing"
        if seg_kind == "ingredients" and "•" not in seg:
            if next_is_main:                    # trailing "2. Product title ..." belongs to the next block
                numbered = list(_NUMBERED.finditer(seg))
                if numbered:
                    seg = seg[:numbered[-1].start()]
                elif "," not in seg:
                    seg = ""
            lists.append(seg)
            continue
        for head, body in _bullet_lines(seg):
            if _looks_like_inci_list(body):
                lists.append(body)
            else:
                heads.append(head)
    return lists, heads


def _merge_split_names(pieces: list[str]) -> list[str]:
    """Re-join "peg" + "18 methyl ether ..." and "bis" + "peg" pieces split by hyphen -> comma."""

    out: list[str] = []
    for piece in pieces:
        stripped = piece.strip()
        if out:
            prev = out[-1].strip()
            if (_NUMBER_LEAD.match(stripped) and _NAME_STEM.search(prev)) or prev.lower() == "bis":
                out[-1] = f"{prev}-{stripped}"
                continue
        out.append(piece)
    return out


# Cyrillic look-alikes pasted into Latin INCI words ("Тосорheryl Acetate", "Extracт").
_CYRILLIC_TO_LATIN = str.maketrans({
    "а": "a", "с": "c", "е": "e", "о": "o", "р": "p", "х": "x", "у": "y", "і": "i", "ѕ": "s", "ј": "j",
    "т": "t", "н": "h", "к": "k", "м": "m", "А": "A", "В": "B", "Е": "E", "К": "K", "М": "M", "Н": "H",
    "О": "O", "Р": "P", "С": "C", "Т": "T", "Х": "X", "У": "Y",
})
_CYRILLIC = re.compile(r"[Ѐ-ӿ]")
_LATIN = re.compile(r"[A-Za-z]")


def _fix_mixed_script(piece: str) -> str:
    """Replace Cyrillic look-alikes, but only inside words that also contain Latin letters."""

    if not _CYRILLIC.search(piece):
        return piece
    words = [w.translate(_CYRILLIC_TO_LATIN) if _CYRILLIC.search(w) and _LATIN.search(w) else w
             for w in piece.split(" ")]
    return " ".join(words)


def _after_title_colon(piece: str) -> str:
    """"Hồng: Water" / "Bảng thành phần: Water" (title + first ingredient) -> the ASCII part after ':'."""

    head, sep, tail = piece.rpartition(":")
    if sep and _NON_ASCII.search(head) and tail.strip() and not _NON_ASCII.search(tail):
        return tail.strip(_STRIP).strip()
    return piece


def _fragments_from(text: str) -> list[str]:
    text = _PREFIX.sub("", text)
    previous = None
    while previous != text:                              # nested brackets
        previous, text = text, _PARENS.sub(" ", text)
    text = text.replace("(", " ").replace(")", " ").replace("[", " ").replace("]", " ")
    text = _DIGIT_COMMA.sub(_COMMA_TOKEN, text)          # "1,2-Hexanediol" stays one name
    out: list[str] = []
    for piece in _merge_split_names(_SPLIT.split(text)):
        piece = piece.replace(_COMMA_TOKEN, ",")
        piece = _PERCENT.sub(" ", piece)
        piece = re.sub(r"\s+", " ", piece).strip(_STRIP).strip()
        piece = _TRAILING_DECIMAL.sub("", piece).strip(_STRIP).strip()
        piece = _SIZE_PREFIX.sub("", piece).strip(_STRIP).strip()
        piece = _fix_mixed_script(piece)
        piece = _after_title_colon(piece)
        if not piece or not re.search(r"[^\W\d_]", piece):
            continue
        if slugify(piece) in _JUNK_FRAGMENTS or slugify(piece).startswith(_JUNK_PREFIXES):
            continue
        if len(piece) > MAX_CHARS or len(piece.split()) > MAX_WORDS:
            continue
        out.append(piece)
    return out


def clean_fragments(raw: Any) -> list[str]:
    """Split raw text into cleaned candidate names (no mapping, no de-duplication)."""

    return _extract(raw)[0]


def _extract(raw: Any) -> tuple[list[str], bool]:
    """``(fragments, from_bullet_heads)``; the flag marks descriptive-heads fallback output."""

    if raw is None:
        return [], False
    if isinstance(raw, (list, tuple)):
        raw = ", ".join(str(x) for x in raw if x is not None)
    text = str(raw).replace("\r", "\n")
    text = re.sub(r"\n\s*-(?=\S)", "-", text)          # export artefact: "PEG\n-8" -> "PEG-8"
    if text.strip().lower() in {"", "none", "null", "nan"}:
        return [], False
    lists, heads = _split_marketing(text)
    fragments = [f for chunk in lists for f in _fragments_from(chunk)]
    if fragments:                                        # a real list beats descriptive bullet heads
        return fragments, False
    return [f for head in heads for f in _fragments_from(head)], True


def _is_description(piece: str, from_heads: bool) -> bool:
    """Unmapped fragment that is Vietnamese prose (claims, sentences) rather than an ingredient name."""

    if not _NON_ASCII.search(piece):
        return False
    if any(word in _VI_SENTENCE_WORDS for word in piece.lower().split()):
        return True
    return from_heads and not slugify(piece).startswith(_VI_INGREDIENT_START)


def resolve_alias(piece: str, table: Mapping[str, AliasEntry]) -> AliasEntry | None:
    """Exact slug first, then safe fallbacks: trailing number ("Triethanolamine 2"), spacing variants."""

    slug = slugify(piece)
    entry = table.get(slug)
    if entry is not None:
        return entry
    base = re.sub(r"\s+\d{1,3}\.?$", "", piece).strip()
    if base != piece:
        entry = table.get(slugify(base))
        if entry is not None:
            return entry
    compact_index = getattr(table, "compact", None)
    if compact_index is None:
        compact_index = build_compact_index(table)
    compact = slug.replace("_", "")
    return compact_index.get(compact) if len(compact) >= 5 else None


def parse_ingredients(
    raw: Any,
    aliases: Mapping[str, AliasEntry] | None = None,
    max_items: int = MAX_INGREDIENTS,
) -> list[IngredientRef]:
    """Ranked, de-duplicated refs (first occurrence wins); keeps the first ``max_items``."""

    table = load_aliases() if aliases is None else aliases
    refs: list[IngredientRef] = []
    seen: set[str] = set()
    fragments, from_heads = _extract(raw)
    for piece in fragments:
        slug = slugify(piece)
        if not slug:
            continue
        entry = resolve_alias(piece, table)
        if entry is None and _is_description(piece, from_heads):
            continue
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


def reload_vocabulary() -> None:
    """Drop every parsed YAML cache (synonyms + ingredient aliases) so edits are picked up in-process."""

    from . import normalize

    normalize.clear_caches()
    _load_alias_file.cache_clear()
    load_aliases.cache_clear()
