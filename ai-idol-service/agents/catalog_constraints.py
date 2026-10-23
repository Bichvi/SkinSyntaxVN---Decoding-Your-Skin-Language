"""Catalog attribute constraints from a free-text brief. Pure: the vocabulary comes from Mongo lookup rows.

HARD constraints filter, SOFT only re-order, CONTENT notes never filter. Missing data is counted, never guessed.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

from agents.intent_parser import normalize_text
from agents.product_resolver import ACTIVE_ALIASES

SKIN_LABELS = {"oily": "Da dầu", "dry": "Da khô", "combination": "Da hỗn hợp", "normal": "Da thường",
               "sensitive": "Da nhạy cảm", "acne": "Da mụn"}
SKIN_USER = {
    "oily": ("da dau", "da nhon", "oily"), "dry": ("da kho", "dry skin"), "combination": ("da hon hop", "combination"),
    "normal": ("da thuong", "da binh thuong"), "sensitive": ("da nhay cam", "sensitive"), "acne": ("da mun", "tri mun"),
}
# canonical -> (user aliases, aliases that must appear in a country name/prefix-free form)
COUNTRIES = {
    "han quoc": ("han quoc", "han", "korea", "south korea", "k beauty", "kbeauty"),
    "phap": ("phap", "france", "french"), "duc": ("duc", "germany", "german"),
    "nhat ban": ("nhat ban", "nhat", "japan"), "my": ("my", "usa", "united states", "america"),
    "viet nam": ("viet nam", "vietnam"), "trung quoc": ("trung quoc", "china"), "thai lan": ("thai lan", "thailand"),
    "uc": ("uc", "australia"), "anh": ("anh quoc", "uk", "england", "united kingdom"), "italy": ("italy", "italia"),
    "tay ban nha": ("tay ban nha", "spain"), "thuy si": ("thuy si", "switzerland"), "dai loan": ("dai loan", "taiwan"),
    "singapore": ("singapore",), "canada": ("canada",), "malaysia": ("malaysia",), "indonesia": ("indonesia",),
    "an do": ("an do", "india"), "new zealand": ("new zealand",), "ba lan": ("ba lan", "poland"),
}
COUNTRY_LABELS = {"han quoc": "Hàn Quốc", "phap": "Pháp", "duc": "Đức", "nhat ban": "Nhật Bản", "my": "Mỹ",
                  "viet nam": "Việt Nam", "trung quoc": "Trung Quốc", "thai lan": "Thái Lan", "uc": "Úc", "anh": "Anh"}
INGREDIENTS: dict[str, tuple[tuple[str, ...], tuple[str, ...]]] = {  # canonical -> (brief aliases, INCI/catalog aliases)
    **{k: (v, v) for k, v in ACTIVE_ALIASES.items()},
    "salicylic acid": (("salicylic", "bha", "axit salicylic"), ("salicylic",)),
    "aha": (("aha", "glycolic", "axit glycolic"), ("glycolic acid", "mandelic acid")),
    "hyaluronic acid": (("hyaluronic", "hyaluronate"), ("hyaluron",)),
    "ceramide": (("ceramide",), ("ceramide",)),
    "panthenol": (("panthenol", "vitamin b5"), ("panthenol", "vitamin b5")),
    "zinc": (("zinc",), ("zinc",)),
    "tea tree": (("tea tree", "tram tra"), ("tea tree", "melaleuca")),
    "fragrance": (("huong lieu", "nuoc hoa", "fragrance", "parfum"), ("fragrance", "parfum", "perfume")),
    "alcohol": (("alcohol", "ethanol", "con kho", r"(?<=chua )con", r"(?<=co )con", r"(?<=khong )con(?! hang| du| lai| ton| kho)"),
                ("alcohol denat", "denatured alcohol", "sd alcohol", "ethanol", "isopropyl alcohol")),
}
INGREDIENT_LABELS = {"fragrance": "hương liệu", "alcohol": "cồn khô"}
INCI_REQUIRED = {"fragrance", "alcohol"}
BRAND_STOPWORDS = frozenset({
    "nature", "skin", "beauty", "pure", "body", "care", "derma", "cell", "mask", "clean", "natural", "plus", "baby",
    "men", "women", "cream", "gel", "serum", "oil", "water", "the", "new", "one", "love", "sun", "kem", "son", "mua",
})
SOFT_MARKER = re.compile(r"\b(?:uu tien|neu co|thien ve|nen chon|giong nhu|day manh|nhu)\b(?! cau)")
HARD_MARKER = re.compile(r"\b(?:chi lay|chi|bat buoc|phai)\b")
NEGATION = re.compile(r"\b(?:khong|ngoai tru|loai tru|tru)\b(?! qua)")
NEG_BEFORE = re.compile(r"\b(?:khong|ngoai tru|loai tru|tru)\b(?! qua)[^,.;]{0,25}$")
LIST_GAP = re.compile(r"^\s*(?:,|/|&|\bva\b|\bhoac\b|\bhay\b)?\s*(?:,\s*)?$")
PRICE_UNIT = r"(k|nghin|ngan|trieu|tr|d|vnd|dong)\b"
PRICE_NUM = r"(\d[\d.,]*)"
CONTENT_PATTERNS = (
    ("tone", r"\bgiong\b"), ("emphasis", r"\b(?:nhan manh|tap trung vao|dua vao)\b"),
    ("explain", r"\b(?:giai thich|tai sao|vi sao)\b"), ("season", r"\b(?:mua dong|troi lanh|thu dong|mua he|mua nong)\b"),
)
ASSUMPTION_MILD = "“Dịu nhẹ” chưa có dữ liệu kiểm chứng trong catalog nên chỉ làm định hướng nội dung, không dùng để lọc."
ASSUMPTION_SEASON = "Mùa/thời tiết chỉ là bối cảnh nội dung, không dùng để lọc sản phẩm."
ASSUMPTION_ORIGIN = "“Hàng/của <nước>” được hiểu là xuất xứ thương hiệu."


def _n(value: object) -> str:
    text = normalize_text(value).replace("ð", "d")
    return re.sub(r"\s+", " ", re.sub(r"[’‘`´']", "", text)).strip()


def _word(token: str) -> str:
    return rf"(?<![a-z0-9]){token}(?![a-z0-9])"


def _key(value: object) -> str | None:
    try:
        return str(int(float(str(value).strip())))
    except (TypeError, ValueError):
        return None


def canonical_country(name: object) -> str:
    text = _n(name)
    text = re.sub(r"^nuoc ", "", text)
    for canonical, aliases in COUNTRIES.items():
        if text == canonical or text in aliases:
            return canonical
    return text


def skin_set(name: object) -> set[str]:
    """Canonical skin types a catalog `loai_da` label covers; empty when unknown."""
    text = _n(name)
    if not text or text == "unknown":
        return set()
    found = set()
    if "moi loai da" in text:
        found.add("all")
    if re.search(r"\bdau\b|\bnhon\b", text):
        found.add("oily")
    if re.search(r"\bkho\b", text):
        found.add("dry")
    if "hon hop" in text:
        found.add("combination")
    if "da thuong" in text or "binh thuong" in text:
        found.add("normal")
    if "nhay cam" in text:
        found.add("sensitive")
    if "mun" in text.split():
        found.add("acne")
    return found


@dataclass
class CatalogVocabulary:
    brands: dict[str, str] = field(default_factory=dict)
    origins: dict[str, str] = field(default_factory=dict)
    factories: dict[str, str] = field(default_factory=dict)
    skin_types: dict[str, str] = field(default_factory=dict)

    @classmethod
    def from_rows(cls, brands=(), origins=(), factories=(), skin_types=()) -> "CatalogVocabulary":
        def table(rows, code, name):
            out = {}
            for row in rows or []:
                key, label = _key(row.get(code)), str(row.get(name) or "").strip()
                if key is not None and label:
                    out[key] = label
            return out
        return cls(table(brands, "ma_thuong_hieu", "ten_thuong_hieu"), table(origins, "ma_xuat_xu", "ten_xuat_xu"),
                   table(factories, "ma_nsx", "ten_nsx"), table(skin_types, "ma_loai_da", "ten_loai_da"))

    def brand_index(self) -> list[tuple[str, str]]:
        index = {}
        for name in self.brands.values():
            norm = _n(name)
            if len(norm) >= 3 and norm not in BRAND_STOPWORDS and not norm.isdigit():
                index.setdefault(norm, name)
        return sorted(index.items(), key=lambda item: -len(item[0]))


def _marker_strength(text: str, start: int) -> tuple[str, bool]:
    window = re.split(r"[.;]", text[max(0, start - 80):start])[-1]
    soft = [m.end() for m in SOFT_MARKER.finditer(window)]
    hard = [m.end() for m in HARD_MARKER.finditer(window)]
    if soft and (not hard or soft[-1] > hard[-1]):
        return "SOFT", False
    return "HARD", bool(hard)


def _negated(text: str, start: int) -> int | None:
    window_start = max(0, start - 40)
    window = re.split(r"[,.;]", text[window_start:start])[-1]
    match = NEG_BEFORE.search(window)
    if not match:
        return None
    return start - len(window) + match.start()


def _group(spans: list[tuple[int, int, Any]], text: str) -> list[list[tuple[int, int, Any]]]:
    groups: list[list] = []
    for span in sorted(spans, key=lambda s: s[0]):
        if groups and LIST_GAP.match(text[groups[-1][-1][1]:span[0]]):
            groups[-1].append(span)
        else:
            groups.append([span])
    return groups


def _parse_amount(number: str, unit: str | None) -> float | None:
    digits = number.strip(".,")
    if unit is None:
        return None
    if re.fullmatch(r"\d{1,3}(?:[.,]\d{3})+", digits):
        value = float(re.sub(r"[.,]", "", digits))
    else:
        try:
            value = float(digits.replace(",", "."))
        except ValueError:
            return None
    mult = {"k": 1000, "nghin": 1000, "ngan": 1000, "trieu": 1_000_000, "tr": 1_000_000}.get(unit, 1)
    return value * mult


def _bare_countries(text: str, country_alt: str, alias_to_canonical: dict, taken: list, spans: list) -> list:
    """Autopilot: "kem dưỡng ẩm Hàn Quốc", "từ Pháp hoặc Đức" — a country with no "xuất xứ" prefix is the origin.

    Long names stand alone; short aliases (Pháp, Đức, Nhật...) need "từ" or a list link to a country already found.
    """
    found: list[tuple[int, int, str]] = []

    def free(start: int, end: int) -> bool:
        return not any(start < e and s < end for s, e in taken) and not any(start < e and s < end for s, e, _ in found)

    for m in re.finditer(rf"(?<![a-z0-9])({country_alt})(?![a-z0-9])(?! pham)", text):
        alias = m.group(1)
        if len(alias) >= 7 and free(m.start(1), m.end(1)):
            found.append((m.start(1), m.end(1), alias_to_canonical[alias]))
    for m in re.finditer(rf"(?<![a-z0-9])tu\s+({country_alt})(?![a-z0-9])(?! pham)", text):
        if free(m.start(1), m.end(1)):
            found.append((m.start(1), m.end(1), alias_to_canonical[m.group(1)]))
    for _ in range(3):  # "Pháp hoặc Đức": a short alias right after a country already found
        for start, end, _ in list(spans) + list(found):
            m = re.match(rf"\s*(?:,|/|va|hoac|hay)\s*({country_alt})(?![a-z0-9])", text[end:])
            if m and free(end + m.start(1), end + m.end(1)):
                found.append((end + m.start(1), end + m.end(1), alias_to_canonical[m.group(1)]))
    taken.extend((s, e) for s, e, _ in found)
    return found


def extract_constraints(brief: str, vocab: CatalogVocabulary, *, bare_country: bool = False) -> dict:
    """{constraints, content_notes, assumptions, conflicts, unparsed_negations}; values are JSON-safe."""
    text = _n(brief)
    found: list[dict] = []
    consumed_neg: set[int] = set()
    assumptions: list[str] = []

    def add(field_name: str, values: list[str], span_start: int, span_end: int, source: str, *, forced_op: str | None = None):
        neg = _negated(text, span_start)
        if neg is not None and bare_country and field_name != "ingredient" and any(
                re.search(_word(re.escape(a)), text[neg:span_start])
                for aliases, _ in INGREDIENTS.values() for a in aliases if not a.startswith("(?")):
            neg = None  # "không chứa hương liệu cho da nhạy cảm": the "không" belongs to the ingredient
        if neg is not None:
            consumed_neg.add(neg)
        strength, explicit = _marker_strength(text, span_start)
        if neg is not None and strength == "SOFT" and not NEG_BEFORE.search(re.split(r"[.;]", text[max(0, span_start - 80):span_start])[-1]):
            strength = "HARD"
        found.append({"field": field_name, "operator": forced_op or ("not_in" if neg is not None else "in"),
                      "value": values, "strength": "HARD" if neg is not None else strength, "explicit": explicit,
                      "source_text": text[span_start:span_end]})

    # brands: longest exact name wins, a comma/"va" list is one constraint
    spans: list[tuple[int, int, str]] = []
    for norm, name in vocab.brand_index():
        if norm not in text:
            continue
        for m in re.finditer(_word(re.escape(norm)), text):
            if not any(m.start() < e and s < m.end() for s, e, _ in spans):
                spans.append((m.start(), m.end(), name))
    for group in _group(spans, text):
        add("brand", [name for _, _, name in group], group[0][0], group[-1][1], text[group[0][0]:group[-1][1]])

    # manufacturing country first so "san xuat tai Phap" is never read as origin
    country_alt = "|".join(sorted((re.escape(a) for al in COUNTRIES.values() for a in al), key=len, reverse=True))
    taken: list[tuple[int, int]] = []
    alias_to_canonical = {a: c for c, al in COUNTRIES.items() for a in al}
    for field_name, prefix, implicit in (
        ("manufacturing_country", r"(?:san xuat (?:tai|o)|made in|noi san xuat(?: la| tai)?|gia cong tai)", False),
        ("origin_country", r"(?:xuat xu(?: tu)?|nhap khau tu|nhap tu|thuong hieu (?:cua|tu)|hang|cua)", True),
    ):
        spans2 = []
        for m in re.finditer(rf"(?<![a-z0-9]){prefix}\s+(?:nuoc\s+)?({country_alt})(?![a-z0-9])(?! pham)", text):
            if any(m.start(1) < e and s < m.end(1) for s, e in taken):
                continue
            taken.append((m.start(1), m.end(1)))
            spans2.append((m.start(), m.end(), alias_to_canonical[m.group(1)]))
            if implicit and not re.match(r"xuat xu|nhap|thuong hieu", m.group(0)) and ASSUMPTION_ORIGIN not in assumptions:
                assumptions.append(ASSUMPTION_ORIGIN)
        if bare_country and field_name == "origin_country":
            spans2.extend(_bare_countries(text, country_alt, alias_to_canonical, taken, spans2))
        for group in _group(spans2, text):
            add(field_name, [c for _, _, c in group], group[0][0], group[-1][1], text[group[0][0]:group[-1][1]])

    for canonical, aliases in SKIN_USER.items():
        for alias in aliases:
            for m in re.finditer(_word(re.escape(alias)), text):
                add("skin_type", [canonical], m.start(), m.end(), alias)
    for m in re.finditer(_word("moi loai da"), text):
        consumed_neg.add(-1)

    for canonical, (aliases, _) in INGREDIENTS.items():
        pattern = "|".join(a if a.startswith("(?") else _word(re.escape(a)) for a in aliases)
        for m in re.finditer(pattern, text):
            add("ingredient", [canonical], m.start(), m.end(), m.group(0))
            break

    # price: "duoi 300k", "toi da 300.000d", "tu 200-500k"
    pre = r"(?<![a-z0-9])"
    for m in re.finditer(pre + r"(?:tu\s+)?" + PRICE_NUM + r"\s*(?:-|den)\s*" + PRICE_NUM + r"\s*" + PRICE_UNIT, text):
        unit = m.group(3)
        lo, hi = _parse_amount(m.group(1), unit), _parse_amount(m.group(2), unit)
        if lo is not None and hi is not None and lo <= hi and "ton" not in text[max(0, m.start() - 12):m.start()]:
            found.append({"field": "price", "operator": "between", "value": [lo, hi], "strength": "HARD",
                          "explicit": False, "source_text": m.group(0)})
    if not any(c["field"] == "price" for c in found):
        for m in re.finditer(pre + r"(duoi|toi da|khong qua|nho hon|tren|it nhat|lon hon)\s+" + PRICE_NUM + r"\s*" + PRICE_UNIT, text):
            amount = _parse_amount(m.group(2), m.group(3))
            if amount is None or "ton" in text[max(0, m.start() - 12):m.start()]:
                continue
            op = "lte" if m.group(1) in {"duoi", "toi da", "khong qua", "nho hon"} else "gte"
            found.append({"field": "price", "operator": op, "value": [amount], "strength": "HARD",
                          "explicit": False, "source_text": m.group(0)})

    # merge OR-lists of the same field/operator/strength (skin, origin, factory)
    merged: list[dict] = []
    for item in found:
        twin = next((x for x in merged if x["field"] == item["field"] and x["operator"] == item["operator"]
                     and x["strength"] == item["strength"] and item["field"] in {"skin_type", "origin_country", "manufacturing_country"}), None)
        if twin:
            twin["value"] = [*twin["value"], *(v for v in item["value"] if v not in twin["value"])]
            twin["source_text"] += " + " + item["source_text"]
            twin["explicit"] = twin["explicit"] or item["explicit"]
        else:
            merged.append(item)
    if any(c["field"] == "skin_type" and len(c["value"]) > 1 and c["operator"] == "in" for c in merged):
        assumptions.append("Nhiều loại da được hiểu là phù hợp với một trong các loại đã nêu.")
    for index, item in enumerate(merged):
        item["id"] = f"{item['field']}:{item['operator']}:{'|'.join(str(v) for v in item['value'])}"

    conflicts = []
    for a in merged:
        if a["operator"] == "in" and a["strength"] == "HARD" and a["field"] != "price":
            for b in merged:
                if b["operator"] == "not_in" and b["field"] == a["field"] and set(a["value"]) & set(b["value"]):
                    both = "/".join(str(v) for v in sorted(set(a["value"]) & set(b["value"])))
                    conflicts.append(f"Yêu cầu mâu thuẫn: vừa chỉ lấy vừa loại trừ “{both}”. Hãy chọn một.")
    hard_skin = [c for c in merged if c["field"] == "skin_type" and c["operator"] == "in" and c["strength"] == "HARD" and c["explicit"]]
    if len(hard_skin) > 1 or (hard_skin and len(hard_skin[0]["value"]) > 1):
        conflicts.append("Có nhiều loại da cùng được yêu cầu “chỉ lấy”; hãy chọn một loại da.")

    notes = []
    for clause in re.split(r"[,.;]", str(brief)):
        norm = _n(clause)
        kinds = [kind for kind, pattern in CONTENT_PATTERNS if re.search(pattern, norm)]
        if "diu nhe" in norm:
            kinds.append("mild")
        if kinds and norm:
            notes.append({"kinds": kinds, "text": clause.strip()})
    kinds_all = {k for note in notes for k in note["kinds"]}
    if "mild" in kinds_all:
        assumptions.append(ASSUMPTION_MILD)
    if "season" in kinds_all:
        assumptions.append(ASSUMPTION_SEASON)

    cleaned = text.replace("khong qua", "").replace("chua chot", "")
    unparsed = [m.start() for m in NEGATION.finditer(cleaned) if m.start() not in consumed_neg and not any(
        abs(m.start() - c) <= 2 for c in consumed_neg)]
    return {"constraints": merged, "content_notes": notes, "assumptions": assumptions,
            "conflicts": list(dict.fromkeys(conflicts)), "unparsed_negations": len(unparsed)}


def summarize_constraints(constraints: list[dict], *, skip_brand: bool = False) -> list[str]:
    lines = []
    for c in constraints:
        values, neg, soft = c["value"], c["operator"] == "not_in", c["strength"] == "SOFT"
        field_name = c["field"]
        if field_name == "brand" and not skip_brand:
            label = "Loại trừ thương hiệu" if neg else "Ưu tiên thương hiệu" if soft else "Thương hiệu"
            lines.append(f"{label}: " + ", ".join(values))
        elif field_name == "skin_type":
            label = ("Ưu tiên loại da" if soft else "Loại trừ loại da" if neg else "Loại da")
            lines.append(f"{label}: " + ", ".join(SKIN_LABELS.get(v, v) for v in values))
        elif field_name in {"origin_country", "manufacturing_country"}:
            label = "Xuất xứ" if field_name == "origin_country" else "Sản xuất tại"
            lines.append(f"{'Ưu tiên ' if soft else 'Loại trừ ' if neg else ''}{label.lower() if soft or neg else label}: "
                         + ", ".join(COUNTRY_LABELS.get(v, v.title()) for v in values))
        elif field_name == "ingredient":
            names = ", ".join(INGREDIENT_LABELS.get(v, v) for v in values)
            lines.append(("Thành phần không chứa: " if neg else "Ưu tiên thành phần: " if soft else "Thành phần có: ") + names)
        elif field_name == "price":
            money = [f"{v:,.0f}".replace(",", ".") + "đ" for v in values]
            lines.append("Giá: " + (f"{money[0]} – {money[1]}" if c["operator"] == "between" else
                                    ("tối đa " if c["operator"] == "lte" else "từ ") + money[0]))
    return lines


def _row_text(row: dict, keys: tuple[str, ...]) -> str:
    return _n(" ".join(str(row.get(k) or "") for k in keys))


def _row_skin(row: dict, vocab: CatalogVocabulary | None) -> set[str]:
    name = row.get("loai_da")
    if not isinstance(name, str) or not name.strip():
        name = (vocab.skin_types.get(_key(row.get("ma_loai_da")) or "") if vocab else None) or row.get("ten_loai_da")
    return skin_set(name)


def _row_country(row: dict, vocab: CatalogVocabulary | None, name_key: str, code_key: str, table: str) -> str | None:
    name = row.get(name_key)
    if not name and vocab:
        name = getattr(vocab, table).get(_key(row.get(code_key)) or "")
    return canonical_country(name) if name else None


def _row_brand(row: dict, vocab: CatalogVocabulary | None) -> str:
    name = row.get("ten_thuong_hieu") or row.get("thuong_hieu") or row.get("brand")
    if not name and vocab:
        name = vocab.brands.get(_key(row.get("ma_thuong_hieu")) or "")
    return _n(name or "")


def _check(row: dict, c: dict, vocab: CatalogVocabulary | None) -> tuple[bool | None, dict, str | None]:
    """(matched | None when data is missing, evidence, missing key)."""
    field_name, neg, values = c["field"], c["operator"] == "not_in", c["value"]
    if field_name == "brand":
        brand = _row_brand(row, vocab)
        if not brand:
            return None, {}, "brand"
        hit = brand in {_n(v) for v in values}
        return (hit != neg), {"brand": brand} if hit != neg else {}, None
    if field_name == "skin_type":
        have = _row_skin(row, vocab)
        if not have:
            return None, {}, "skin_type"
        hit = set(values) & have
        if neg:
            return not hit, {}, None
        if hit:
            return True, {"skin_type": sorted(hit)}, None
        if "all" in have:
            return True, {"skin_type": ["all"], "skin_reason": "phù hợp mọi loại da"}, None
        return False, {}, None
    if field_name in {"origin_country", "manufacturing_country"}:
        origin = field_name == "origin_country"
        have = _row_country(row, vocab, "ten_xuat_xu" if origin else "ten_noi_san_xuat",
                            "ma_xuat_xu" if origin else "ma_noi_san_xuat", "origins" if origin else "factories")
        if not have:
            return None, {}, field_name
        hit = have in values
        return (hit != neg), {field_name: have} if hit != neg else {}, None
    if field_name == "ingredient":
        canonical = values[0]
        aliases = INGREDIENTS[canonical][1]
        if neg:
            inci = _row_text(row, ("thanh_phan_full",))
            if not inci:
                return None, {}, "full_ingredients"
            return not any(a in inci for a in aliases), {}, None
        text = _row_text(row, ("thanh_phan_full", "thanh_phan_chinh", "thanh_phan_clean", "thanh_phan_day_du",
                               "thanh_phan", "thanh_phan_sach", "ten_san_pham", "name", "ingredients"))
        hit = any(a in text for a in aliases)
        return hit, {"ingredients": [canonical]} if hit else {}, None
    if field_name == "price":
        raw = row.get("gia_ban", row.get("price"))
        try:
            price = float(raw)
        except (TypeError, ValueError):
            return None, {}, "price"
        lo, hi = {"between": (values[0], values[-1]), "lte": (None, values[0]), "gte": (values[0], None)}[c["operator"]]
        return (lo is None or price >= lo) and (hi is None or price <= hi), {}, None
    return True, {}, None


def apply_constraints(row: dict, constraints: list[dict], vocab: CatalogVocabulary | None = None) -> tuple[bool, dict, str | None]:
    """HARD constraints decide; SOFT ones only fill evidence['soft_score']/['soft_matches']."""
    evidence: dict[str, Any] = {}
    missing = None
    for c in constraints:
        if c["strength"] != "HARD":
            continue
        ok, ev, miss = _check(row, c, vocab)
        if ok is False:
            return False, evidence, None
        if ok is None:
            missing = missing or miss
            continue
        for key, value in ev.items():
            if isinstance(value, list) and isinstance(evidence.get(key), list):
                evidence[key] = [*evidence[key], *(v for v in value if v not in evidence[key])]
            else:
                evidence[key] = value
    if missing:
        return False, evidence, missing
    soft = [c["id"] for c in constraints if c["strength"] == "SOFT" and _check(row, c, vocab)[0] is True]
    if any(c["strength"] == "SOFT" for c in constraints):
        evidence.update(soft_score=len(soft), soft_matches=soft)
    return True, evidence, None
