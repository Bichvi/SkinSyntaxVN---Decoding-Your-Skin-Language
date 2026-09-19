"""Pure consultation ingredients rules."""
from __future__ import annotations

import re
import unicodedata
INGREDIENT_ALIASES = {
    "retinol": ("retinol",), "retinal": ("retinal", "retinaldehyde"), "retinoid": ("retinoid", "retinol", "retinal", "tretinoin", "adapalene"),
    "bha": ("bha", "salicylic acid"), "aha": ("aha", "glycolic acid", "lactic acid", "mandelic acid"),
    "hương liệu": ("hương liệu", "fragrance", "parfum", "perfume"),
    "cồn": ("cồn", "con kho", "alcohol", "alcohol denat", "ethanol", "sd alcohol"),
    "vitamin c": ("vitamin c", "ascorbic acid", "ascorbyl", "ethyl ascorbic acid"),
}


def usable_ingredients(value):
    text = str(value or '').strip()
    return '' if normalized_text(text) in ('', 'unknown', 'n/a', 'none', 'null', 'chưa có', 'không có thông tin') else text


def normalize_exclusions(values):
    result = []
    for value in values:
        name = normalized_text(value).strip()
        if name in ('', 'none', 'không có', 'khong co', 'không có / không quan tâm'):
            continue
        if name in ('fragrance/parfum', 'fragrance / parfum', 'fragrance', 'parfum', 'perfume'):
            name = 'hương liệu'
        if name in ('cồn khô', 'con kho', 'ethanol', 'alcohol', 'alcohol denat'):
            name = 'cồn'
        if name not in result:
            result.append(name)
    return result




def normalized_text(text) -> str:
    return unicodedata.normalize("NFC", str(text or "")).casefold()


def has_ingredient(text, name) -> bool:
    haystack = normalized_text(text)
    name = normalize_exclusions([name])
    if not name:
        return False
    name = name[0]
    for alias in INGREDIENT_ALIASES.get(normalized_text(name), (normalized_text(name),)):
        # Fatty alcohols are not ethanol; do not match their suffix as alcohol.
        if alias == "alcohol":
            haystack = re.sub(r"\b(?:cetyl|cetearyl|stearyl|behenyl|benzyl)\s+alcohol\b", "", haystack)
        if re.search(r"(?<!\w)" + re.escape(alias) + r"(?!\w)", haystack):
            return True
    return False


_REVOCATION = r'(?:bỏ|bo|gỡ|go)\s+(?:điều kiện|dieu kien|yêu cầu|yeu cau)\s+([^,;.!?]+)'


def revoked_exclusions(message: str) -> list[str]:
    values = []
    for clause in re.findall(_REVOCATION, message, re.I):
        values += extract_exclusions(clause)
    return normalize_exclusions(values)


def update_exclusions(values, message):
    removed = revoked_exclusions(message)
    return normalize_exclusions([v for v in normalize_exclusions(values) if v not in removed] + extract_exclusions(message))


def extract_exclusions(message: str) -> list[str]:
    # A retracted negative condition is not a new negative condition.
    message = re.sub(_REVOCATION, '', message, flags=re.I)
    explicit = re.findall(r"(?:không\s+(?:muốn\s+)?(?:chứa|có|dùng)\s+|khong\s+(?:chua|co|dung)\s+|tránh\s+|dị ứng\s+(?:với\s+)?)([^.!?;]+)", message, re.I)
    bare = re.findall(r"(?:không|khong)\s+([^.!?;]+)", message, re.I)
    values = []
    known = list(INGREDIENT_ALIASES) + ["niacinamide", "benzoyl peroxide", "tretinoin", "adapalene", "peptide", "ceramide", "lanolin", "fragrance", "parfum", "alcohol"]
    for clause in explicit + bare:
        # A monetary constraint is not an ingredient exclusion, even if an
        # active is named elsewhere later in the sentence.
        if re.match(r"(?:giới hạn|gioi han|quá|qua)\b", clause, re.I):
            continue
        clause = re.split(r'\s+(?:(?:nhưng|nhung|và|va)\s+)?(?:có|co|chứa|chua)\s+', clause, maxsplit=1, flags=re.I)[0]
        found = [name for name in known if has_ingredient(clause, name)
                 and (name != 'retinoid' or re.search(r'\bretinoids?\b', clause, re.I))]
        values.extend(found)
        if not found and clause in explicit:
            from .request_rules import explicit_category
            from .money import parse_budget
            values.extend(name.strip() for name in re.split(r",|\bvà\b|\bhoặc\b", clause)
                          if name.strip() and explicit_category(name) is None and parse_budget(name) is None)
    return normalize_exclusions(values)
