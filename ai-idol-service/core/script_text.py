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
    "opening|hook|problem|product intro(?:duction)?|key benefits?|specs?|ingredients?|how to use|"
    "price(?: & promotion)?|cta|closing|wrap[- ]?up"
)
# Sections after which a livestream script talks money; product-page clips stop before them.
SALE_LABELS = frozenset({"price", "cta", "closing"})
_LABEL_CAPTURE = re.compile(rf"(?:{SECTION_NAMES})", re.IGNORECASE)
BRACKET_LINE = re.compile(rf"^\s*\[\s*({SECTION_NAMES})\s*\]\s*:?[ \t]*$", re.IGNORECASE)
LEADING_LABEL = re.compile(
    rf"^\s*(?:#+\s*)?(?:\*{{1,3}}|_{{1,3}}|\[)?\s*({SECTION_NAMES})\s*(?:\*{{1,3}}|_{{1,3}}|\])?\s*:",
    re.IGNORECASE,
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


def _canonical_label(name: str) -> str:
    key = re.sub(r"[^a-z]+", " ", name.lower()).split()
    joined = " ".join(key)
    if joined.startswith("product intro"):
        return "product_intro"
    if joined.startswith("key benefit"):
        return "key_benefits"
    if joined.startswith("price"):
        return "price"
    if joined in ("spec", "specs"):
        return "specs"
    if joined in ("ingredient", "ingredients"):
        return "ingredients"
    if joined.startswith("wrap"):
        return "wrap_up"
    return "_".join(key)


def _header_label(line: str) -> str | None:
    """Label of a line that is only a section header, else None."""
    for pattern in (SECTION_LINE, BRACKET_LINE):
        if pattern.match(line):
            match = _LABEL_CAPTURE.search(line)
            return _canonical_label(match.group(0)) if match else None
    return None


def split_labeled_sections(script: str) -> list[tuple[str, str]]:
    """Speech sections paired with the authoring label that introduced them ("" when unlabelled)."""
    normalized = str(script or "").replace("\r\n", "\n").replace("\r", "\n")
    sections: list[tuple[str, str]] = []
    current: list[str] = []
    label = ""

    def flush():
        if current:
            sections.append((label, " ".join(current).strip()))
            current.clear()

    for raw_line in normalized.split("\n"):
        header = _header_label(raw_line)
        if header is not None:
            flush()
            label = header
            continue
        leading = LEADING_LABEL.match(raw_line)
        if leading:
            flush()
            label = _canonical_label(leading.group(1))
        cleaned = _clean_line(raw_line)
        if cleaned:
            current.append(cleaned)
        else:
            flush()
    flush()

    result = []
    for section_label, section in sections:
        words = section.split()
        while len(words) > 110:
            result.append((section_label, " ".join(words[:110])))
            words = words[110:]
        if words:
            result.append((section_label, " ".join(words)))
    return result or [("", "Nội dung đang được cập nhật.")]


def split_speech_sections(script: str) -> list[str]:
    """Remove authoring labels and keep natural paragraph/section boundaries."""
    return [text for _, text in split_labeled_sections(script)]


_SPELLED_NUMBER = r"(?:mot|hai|ba|bon|tu|nam|lam|sau|bay|tam|chin|muoi|muoi|tram|chuc|linh|le)"
_PRICE_PATTERNS = (
    re.compile(r"\d[\d.,]*\s*(?:k|nghin|ngan|trieu|dong|vnd|d)\b"),
    re.compile(r"\b(?:nghin|ngan|trieu|tram|chuc|muoi)\s+dong\b"),
    # TTS rules make the model spell prices out: "ba trăm năm mươi nghìn".
    re.compile(rf"\b{_SPELLED_NUMBER}\s+(?:nghin|ngan|trieu)\b"),
    re.compile(r"\bvnd\s*\d"),
    re.compile(r"\bgiam\s+(?:gia\s+)?\d+\s*%"),
    re.compile(
        r"\b(?:giam gia|khuyen mai|uu dai|flash sale|sale|chot don|hom nay|live ?stream|"
        r"chi con|duy nhat|voucher|freeship|ma giam)\b"
    ),
)


def _fold(text: str) -> str:
    import unicodedata
    lowered = str(text or "").lower().replace("đ", "d").replace("₫", " dong")
    return "".join(ch for ch in unicodedata.normalize("NFD", lowered) if unicodedata.category(ch) != "Mn")


def find_price_mentions(text: str) -> list[str]:
    """Money and time-limited promotion phrases, accent-insensitive; [] when the text is evergreen."""
    folded = _fold(text)
    found: list[str] = []
    for pattern in _PRICE_PATTERNS:
        found.extend(match.group(0) for match in pattern.finditer(folded))
    return found


def clean_script_for_speech(script: str) -> str:
    return "\n\n".join(split_speech_sections(script))
