"""Human-curated product tags (U07) layered on top of the rule-inferred ones. Pure (no I/O).

Mongo ``san_pham`` carries optional curated fields (written by the admin form or by an approved
LLM suggestion): ``cong_dung_keys`` (benefits), ``van_de_da_keys`` (concerns), ``loai_da_keys``
(skin types), ``tag_source`` (``manual`` | ``llm_approved``).

Priority per group: ``manual`` > ``llm_approved`` > rule. A curated list REPLACES the rule-inferred
list of that group when it contains at least one valid key; an empty/invalid list falls back to the
rules (so an admin who only ticks benefits does not wipe the inferred concerns). Skin types are
additive: the product's own ``ma_loai_da`` entry (``source=data``) is kept and curated extras are
appended.
"""

from __future__ import annotations

from typing import Any, Iterable, Mapping, Sequence

from .rules import Tags

# Highest priority first.
CURATED_SOURCES: tuple[str, ...] = ("manual", "llm_approved")
RULE_SOURCE = "rule"
INFERRED = "inferred"

# Tag group -> Mongo field on san_pham.
FIELD_BY_GROUP: dict[str, str] = {
    "benefits": "cong_dung_keys",
    "concerns": "van_de_da_keys",
    "skin_types": "loai_da_keys",
}
# Fields the ETL has to read in addition to the legacy projection.
CURATION_FIELDS: tuple[str, ...] = (*FIELD_BY_GROUP.values(), "tag_source", "tag_updated_at")


def source_priority(source: str | None) -> int:
    """Lower is stronger: manual 0, llm_approved 1, anything else (rule) 2."""

    try:
        return CURATED_SOURCES.index(str(source))
    except ValueError:
        return len(CURATED_SOURCES)


def curated_source(doc: Mapping[str, Any]) -> str | None:
    """The doc's ``tag_source`` when it is a known curated source, else None."""

    value = str(doc.get("tag_source") or "").strip().lower()
    return value if value in CURATED_SOURCES else None


def clean_keys(raw: Any, valid: Iterable[str] | None, limit: int | None = None) -> list[str]:
    """Order-preserving, de-duplicated list of strings that are in ``valid`` (``None`` = accept all)."""

    if isinstance(raw, str):
        raw = [raw]
    if not isinstance(raw, (list, tuple)):
        return []
    allowed = None if valid is None else set(valid)
    out: list[str] = []
    for item in raw:
        key = str(item or "").strip()
        if not key or key in out or (allowed is not None and key not in allowed):
            continue
        out.append(key)
        if limit is not None and len(out) >= limit:
            break
    return out


def resolve_tags(
    inferred: Tags,
    doc: Mapping[str, Any],
    valid: Mapping[str, Iterable[str]],
) -> dict[str, Any]:
    """Merge rule-inferred ``Tags`` with the doc's curated fields.

    Returns ``{"benefits": [{"key","source"}], "concerns": [...], "skin_types": [...],
    "tag_source": "manual"|"llm_approved"|"rule"}``. Every entry carries the source of THAT key
    (``inferred`` for rule tags, ``data``/``inferred`` for skin types as before, or the curated source).
    """

    source = curated_source(doc)
    valid_sets = {group: set(valid.get(group, ())) for group in FIELD_BY_GROUP}
    used_curated = False

    def pick(group: str, rule_keys: Sequence[str]) -> list[dict[str, str]]:
        nonlocal used_curated
        if source is not None:
            keys = clean_keys(doc.get(FIELD_BY_GROUP[group]), valid_sets[group])
            if keys:
                used_curated = True
                return [{"key": k, "source": source} for k in keys]
        return [{"key": k, "source": INFERRED} for k in rule_keys]

    benefits = pick("benefits", inferred.benefits)
    concerns = pick("concerns", inferred.concerns)

    own_source = inferred.skin_type_source or INFERRED
    skin: list[dict[str, str]] = [{"key": k, "source": own_source} for k in inferred.skin_types]
    if source is not None:
        extras = clean_keys(doc.get(FIELD_BY_GROUP["skin_types"]), valid_sets["skin_types"])
        if extras:
            used_curated = True
            if own_source == "data":
                have = {s["key"] for s in skin}
                skin += [{"key": k, "source": source} for k in extras if k not in have]
            else:   # rule-inferred skin types are replaced by the curated ones
                skin = [{"key": k, "source": source} for k in extras]

    return {
        "benefits": benefits,
        "concerns": concerns,
        "skin_types": skin,
        "tag_source": source if used_curated and source else RULE_SOURCE,
    }
