import re


# Product descriptions may contain retailer names from imported listings, but
# those names must never be spoken as the seller in a SkinSyntax livestream.
_EXTERNAL_RETAILER_REPLACEMENTS = (
    (re.compile(r"\btiktok\s+shop\b", re.IGNORECASE), "SkinSyntax"),
    (re.compile(r"\bhasaki(?:\.vn)?\b", re.IGNORECASE), "SkinSyntax"),
    (re.compile(r"\bwatsons?(?:\.vn)?\b", re.IGNORECASE), "SkinSyntax"),
    (re.compile(r"\bguardian(?:\.vn)?\b", re.IGNORECASE), "SkinSyntax"),
    (re.compile(r"\bshopee(?:\.vn)?\b", re.IGNORECASE), "SkinSyntax"),
    (re.compile(r"\blazada(?:\.vn)?\b", re.IGNORECASE), "SkinSyntax"),
    (re.compile(r"\btiki(?:\.vn)?\b", re.IGNORECASE), "SkinSyntax"),
    (re.compile(r"\bsendo(?:\.vn)?\b", re.IGNORECASE), "SkinSyntax"),
    (re.compile(r"\bchiaki(?:\.vn)?\b", re.IGNORECASE), "SkinSyntax"),
)


def sanitize_external_retailer_mentions(value: str) -> str:
    """Replace third-party retailer names before text reaches TTS or captions."""
    cleaned = str(value or "")
    for pattern, replacement in _EXTERNAL_RETAILER_REPLACEMENTS:
        cleaned = pattern.sub(replacement, cleaned)
    return cleaned


def find_external_retailer_mentions(value: str) -> list[str]:
    """Return matches for validation/logging without exposing them to TTS."""
    text = str(value or "")
    matches = []
    for pattern, _ in _EXTERNAL_RETAILER_REPLACEMENTS:
        matches.extend(match.group(0) for match in pattern.finditer(text))
    return matches


SECTION_NAMES = (
    "opening|hook|product intro(?:duction)?|key benefits?|how to use|"
    "price(?: & promotion)?|cta|closing"
)
SECTION_LINE = re.compile(
    rf"^\s*(?:#+\s*)?(?:\*{{1,3}}|_{{1,3}})?\s*(?:{SECTION_NAMES})"
    rf"\s*(?:\*{{1,3}}|_{{1,3}})?\s*:?[ \t]*$",
    re.IGNORECASE,
)
INLINE_SECTION = re.compile(
    rf"^\s*(?:#+\s*)?(?:\*{{1,3}}|_{{1,3}})?\s*(?:{SECTION_NAMES})"
    rf"\s*(?:\*{{1,3}}|_{{1,3}})?\s*:\s*",
    re.IGNORECASE,
)


def _clean_line(line: str) -> str:
    line = INLINE_SECTION.sub("", line.strip())
    if SECTION_LINE.match(line):
        return ""
    line = re.sub(r"^\s*(?:[-*•]+|\d+[.)])\s*", "", line)
    line = re.sub(
        r"\[\s*(?:" + SECTION_NAMES + r")\s*\]\s*:?",
        "",
        line,
        flags=re.IGNORECASE,
    )
    line = line.replace("**", "").replace("__", "").replace("`", "")
    return re.sub(r"\s+", " ", line).strip()


def split_speech_sections(script: str) -> list[str]:
    """Remove authoring labels and keep natural paragraph/section boundaries."""
    normalized = str(script or "").replace("\r\n", "\n").replace("\r", "\n")
    sections = []
    current = []
    for raw_line in normalized.split("\n"):
        if SECTION_LINE.match(raw_line):
            if current:
                sections.append(" ".join(current).strip())
                current = []
            continue
        cleaned = _clean_line(raw_line)
        if cleaned:
            current.append(cleaned)
        elif current:
            sections.append(" ".join(current).strip())
            current = []
    if current:
        sections.append(" ".join(current).strip())

    result = []
    for section in sections:
        words = section.split()
        while len(words) > 110:
            result.append(" ".join(words[:110]))
            words = words[110:]
        if words:
            result.append(" ".join(words))
    return result or ["Nội dung đang được cập nhật."]


def clean_script_for_speech(script: str) -> str:
    return "\n\n".join(split_speech_sections(script))
