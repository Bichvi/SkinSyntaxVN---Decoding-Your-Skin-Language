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


# Sections from which a combined livestream/product script stops being product-page copy.
PAGE_CUT_LABELS = frozenset({"cta", "closing"})

# Folded Vietnamese number vocabulary, including the words core.vi_money emits.
_SPELLED_NUMBER = (
    r"(?:khong|mot|hai|ba|bon|tu|nam|lam|sau|bay|tam|chin|muoi|tram|chuc|linh|le|"
    r"nghin|trieu|ty)"
)
_MONEY_PATTERNS = (
    re.compile(r"\d[\d.,]*\s*(?:k|nghin|ngan|trieu|dong|vnd|d)\b"),
    re.compile(r"\b(?:nghin|ngan|trieu|tram|chuc|muoi)\s+dong\b"),
    # TTS rules make the model spell prices out: "ba trăm năm mươi nghìn".
    re.compile(rf"\b{_SPELLED_NUMBER}\s+(?:nghin|ngan|trieu)\b"),
    re.compile(r"\bvnd\s*\d"),
    re.compile(r"\bgiam\s+(?:gia\s+)?\d+\s*%"),
    # Only a percent in discount context is a price claim; "Niacinamide mười phần trăm" is not.
    re.compile(rf"\bgiam\s+(?:gia\s+)?(?:\d+(?:[.,]\d+)?|{_SPELLED_NUMBER}(?:\s+{_SPELLED_NUMBER})*)\s*(?:%|phan\s+tram\b)"),
)
_ALWAYS_BANNED = re.compile(
    r"\b(?:hom nay|toi nay|ngay mai|live ?stream|chot don|chi con|duy nhat|voucher|freeship|ma giam)\b"
)
_FLASH_ONLY = re.compile(r"\b(?:flash sale|sale|khuyen mai|uu dai|giam gia|co han|ket thuc|sap het)\b")
_MAGNITUDE = r"(?:tram|nghin|trieu|ty|muoi|linh)"
_PHRASE_MARK = "\x00"
# A removed valid phrase glued to more number words is a different amount, not the valid one.
_EXTENDED_AMOUNT = re.compile(
    rf"\b{_MAGNITUDE}\s+{_PHRASE_MARK}|{_PHRASE_MARK}\s*(?:{_SPELLED_NUMBER}\s+)*{_MAGNITUDE}\b"
)


def _fold(text: str) -> str:
    import unicodedata
    lowered = str(text or "").lower().replace("đ", "d").replace("₫", " dong")
    return "".join(ch for ch in unicodedata.normalize("NFD", lowered) if unicodedata.category(ch) != "Mn")


def _fold_speech(text: str) -> str:
    """Folded text with the "ngàn" spelling of thousand mapped onto "nghìn"."""
    return re.sub(r"\bngan\b", "nghin", _fold(text))


def _remove_valid_phrases(folded: str, phrases: dict[str, str]) -> str:
    """Replace each backend-made phrase with a marker; money phrases may omit the trailing "đồng"."""
    patterns = []
    for phrase in phrases.values():
        core = _fold_speech(phrase).strip()
        optional_unit = ""
        if core.endswith(" dong"):
            core, optional_unit = core[: -len(" dong")], r"(?:\s+dong)?"
        patterns.append(rf"(?<!\w){re.escape(core)}{optional_unit}(?!\w)")
    for pattern in sorted(patterns, key=len, reverse=True):
        folded = re.sub(pattern, _PHRASE_MARK, folded)
    return folded


def find_price_violations(text: str, pricing: dict | None) -> list[str]:
    """Vietnamese error messages for price talk that is not an exact backend phrase; [] when valid."""
    from core.vi_money import price_phrases

    phrases = price_phrases(pricing)
    remaining = _remove_valid_phrases(_fold_speech(text), phrases)
    errors: list[str] = []
    mentions = [m.group(0) for pattern in _MONEY_PATTERNS for m in pattern.finditer(remaining)]
    mentions += [m.group(0) for m in _EXTENDED_AMOUNT.finditer(remaining)]
    if mentions:
        if phrases:
            errors.append("Con số giá không khớp dữ liệu, chỉ dùng đúng các cụm: " + "; ".join(phrases.values()))
        else:
            errors.append("Con số giá không khớp dữ liệu, sản phẩm không có dữ liệu giá nên không được nói "
                          "bất kỳ con số tiền hoặc phần trăm nào")
    banned = [m.group(0) for m in _ALWAYS_BANNED.finditer(remaining)]
    if banned:
        errors.append("Không được dùng các từ: " + ", ".join(dict.fromkeys(banned)))
    if not (pricing or {}).get("flash") and _FLASH_ONLY.search(remaining):
        errors.append("Không có chương trình ưu đãi đang chạy, không được nói ưu đãi, khuyến mãi, sale, giảm giá hay có hạn")
    return errors


def clean_script_for_speech(script: str) -> str:
    return "\n\n".join(split_speech_sections(script))
