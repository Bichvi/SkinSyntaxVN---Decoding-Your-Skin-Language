from __future__ import annotations

from dataclasses import asdict, dataclass
from difflib import SequenceMatcher
import re
from typing import Any

from agents.intent_parser import normalize_text


# These are hard constraints, not ranking hints.  If a request contains one of
# these terms, a product that does not contain the term in its searchable facts
# must never be selected merely because a generic word (for example ``1`` in
# "1 số sản phẩm") matches its product id.
ACTIVE_ALIASES: dict[str, tuple[str, ...]] = {
    "retinol": ("retinol", "retinal", "retinoid", "retinol24", "retinyl palmitate"),
    "centella": ("centella asiatica", "centella", "rau ma", "gotu kola", "cica"),
    "niacinamide": ("niacinamide", "nicotinamide", "vitamin b3"),
    "vitamin c": ("vitamin c", "ascorbic", "ascorbyl"),
}

POPULARITY_TERMS = (
    "ban chay",
    "ban chay nhat",
    "best seller",
    "bestseller",
    "pho bien",
    "duoc ua chuong",
)


# Words that frame a livestream command but never identify a product.  They
# must not earn fuzzy-match credit ("an toan", "ma" ...) against product names.
_TOKEN_STOPWORDS = frozenset({
    "ma", "id", "sp", "san", "pham", "gioi", "thieu", "tao", "livestream", "live",
    "an", "toan", "toi", "mai", "luc", "gio", "cho", "va", "cua", "phut", "noi",
    "bo", "xem", "truoc", "phat",
})
MAX_CANDIDATES = 5


def _id_alias(value: Any) -> str:
    """Canonical text for an identifier: 93, 93.0 and "93" are the same id."""
    if value is None or isinstance(value, bool):
        return ""
    if isinstance(value, float) and value.is_integer():
        value = int(value)
    text = str(value).strip()
    if re.fullmatch(r"\d+\.0+", text):
        text = text.split(".", 1)[0]
    return text


def _as_number(value: Any) -> float:
    try:
        return max(0.0, float(str(value or "").replace(",", "").strip()))
    except (TypeError, ValueError):
        return 0.0


@dataclass(frozen=True)
class ProductMatch:
    product_id: str
    name: str
    score: float
    reason: str

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def normalize_catalog_product(raw: dict[str, Any]) -> dict[str, Any]:
    # Primary id stays ma_san_pham; id/_id are lookup aliases only.
    product_id = ""
    for key in ("ma_san_pham", "product_id", "id", "_id"):
        product_id = _id_alias(raw.get(key))
        if product_id:
            break
    aliases: list[str] = []
    for candidate in (
        product_id,
        *(raw.get(key) for key in ("ma_san_pham", "product_id", "id", "_id")),
        *(raw.get("aliases") or []),
    ):
        alias = _id_alias(candidate)
        if alias and alias not in aliases:
            aliases.append(alias)
    name = str(raw.get("ten_san_pham") or raw.get("name") or "").strip()
    brand = str(raw.get("ten_thuong_hieu") or raw.get("thuong_hieu") or raw.get("brand") or "").strip()
    ingredients = " ".join(
        str(raw.get(key) or "").strip()
        for key in ("thanh_phan_chinh", "thanh_phan_clean", "thanh_phan_day_du", "ingredients")
    ).strip()
    category = " ".join(
        str(raw.get(key) or "").strip()
        for key in ("loai_san_pham", "danh_muc_day_du", "category")
    ).strip()
    description = " ".join(
        str(raw.get(key) or "").strip()
        for key in ("mo_ta", "description", "cong_dung")
    ).strip()
    return {
        "id": product_id,
        "aliases": aliases,
        "name": name,
        "brand": brand,
        "ingredients": ingredients,
        "category": category,
        "description": description,
        "sales_count": _as_number(next((raw[k] for k in ("so_luong_ban", "so_luong_da_ban", "luot_ban", "sales_count") if raw.get(k) is not None), None)),
        "rating": _as_number(raw.get("diem_danh_gia") or raw.get("rating")),
        "review_count": _as_number(raw.get("so_luong_danh_gia") or raw.get("review_count")),
    }


def _searchable_text(product: dict[str, Any]) -> str:
    return normalize_text(
        " ".join(
            str(product.get(key) or "")
            for key in ("id", "name", "brand", "ingredients", "category", "description")
        )
    )


def _constraint_text(product: dict[str, Any]) -> str:
    """Return trusted product facts for hard ingredient/category constraints.

    Free-form descriptions can contain retailer copy or related ingredients
    that are not actually present in the formula.  They remain useful for
    fuzzy ranking, but must not make a product satisfy an ingredient filter.
    """
    return normalize_text(
        " ".join(
            str(product.get(key) or "")
            for key in ("name", "brand", "ingredients", "category")
        )
    )


def _requested_constraints(texts: list[str]) -> list[str]:
    normalized = normalize_text(" ".join(str(item or "") for item in texts))
    return [
        canonical
        for canonical, aliases in ACTIVE_ALIASES.items()
        if any(alias in normalized for alias in aliases)
    ]


def _matches_constraints(product: dict[str, Any], constraints: list[str]) -> bool:
    if not constraints:
        return True
    haystack = _constraint_text(product)
    return all(any(alias in haystack for alias in ACTIVE_ALIASES[term]) for term in constraints)


def _constraint_name_hits(product: dict[str, Any], constraints: list[str]) -> int:
    name_brand = normalize_text(
        " ".join(str(product.get(key) or "") for key in ("name", "brand"))
    )
    return sum(
        1
        for term in constraints
        if any(alias in name_brand for alias in ACTIVE_ALIASES[term])
    )


def _popularity_requested(text: str) -> bool:
    normalized = normalize_text(text)
    return any(term in normalized for term in POPULARITY_TERMS)


def _requested_limit(text: str) -> int:
    normalized = normalize_text(text)
    if re.search(r"\b(?:1 so|mot so|mot vai|vai|nhieu)\b", normalized):
        return 3
    match = re.search(r"\b(\d{1,2})\s*(?:san pham|sp)\b", normalized)
    if match:
        return max(1, min(int(match.group(1)), 10))
    return 1


def _score(query: str, product: dict[str, Any], constraints: list[str] | None = None) -> float:
    normalized_query = normalize_text(query)
    haystack = _searchable_text(product)
    name = normalize_text(str(product.get("name") or ""))
    if not normalized_query or not haystack:
        return 0.0
    if name and name in normalized_query:
        return 0.98
    query_tokens = {token for token in normalized_query.split() if len(token) > 1 and not token.isdigit()}
    searchable_tokens = {token for token in haystack.split() if len(token) > 1 and not token.isdigit()}
    # Command words ("ma", "an toan"...) may not earn overlap credit.
    overlap = len((query_tokens - _TOKEN_STOPWORDS) & searchable_tokens) / max(1, len(query_tokens))
    sequence = SequenceMatcher(None, normalized_query, name).ratio() if name else 0.0
    score = max(overlap, sequence * 0.85)
    if constraints:
        matched = sum(
            1
            for term in constraints
            if any(alias in haystack for alias in ACTIVE_ALIASES[term])
        )
        if matched:
            # Prefer a product whose name/brand explicitly advertises the
            # requested active, while still allowing an ingredient-only match.
            name_hits = _constraint_name_hits(product, constraints)
            if name_hits:
                score = max(score, 0.98)
            else:
                score = max(score, 0.93 + 0.04 * matched / len(constraints))
    return round(min(score, 0.98), 4)


def resolve_products(
    brief_text: str,
    explicit_product_ids: list[str],
    product_queries: list[str],
    catalog: list[dict[str, Any]],
    *,
    threshold: float = 0.62,
) -> dict[str, Any]:
    products = [normalize_catalog_product(item) for item in catalog]
    products = [item for item in products if item["id"] and item["name"]]
    by_id: dict[str, dict[str, Any]] = {}
    for item in products:
        for alias in item["aliases"]:
            by_id.setdefault(alias.lower(), item)
    for item in products:  # a primary ma_san_pham always beats another product's alias
        by_id[item["id"].lower()] = item
    selected: list[ProductMatch] = []
    unresolved_ids: list[str] = []
    unresolved_reasons: dict[str, str] = {}
    has_explicit_ids = any(str(raw_id).strip() for raw_id in explicit_product_ids)
    all_queries = [str(query) for query in product_queries if str(query).strip()] + [brief_text]
    constraints = _requested_constraints(all_queries)
    popularity_requested = _popularity_requested(brief_text)
    requested_limit = _requested_limit(brief_text)
    constraint_mismatches: list[dict[str, str]] = []

    for raw_id in explicit_product_ids:
        product = by_id.get(_id_alias(raw_id).lower())
        if product:
            if not _matches_constraints(product, constraints):
                constraint_mismatches.append({
                    "product_id": product["id"],
                    "name": product["name"],
                    "reason": f"Không đáp ứng điều kiện hoạt chất: {', '.join(constraints)}",
                })
                continue
            if product["id"] not in {item.product_id for item in selected}:
                selected.append(ProductMatch(product["id"], product["name"], 1.0, "Khớp mã sản phẩm"))
        else:
            unresolved_ids.append(str(raw_id))
            unresolved_reasons[str(raw_id)] = "NOT_FOUND"

    queries = [query for query in product_queries if str(query).strip()] or [brief_text]
    candidate_products = [item for item in products if _matches_constraints(item, constraints)]
    ranked: list[ProductMatch] = []
    for product in candidate_products:
        score = max(_score(query, product, constraints) for query in queries + [brief_text])
        if score > 0:
            reason = (
                f"Khớp hoạt chất {', '.join(constraints)}"
                if constraints
                else "Khớp tên/ngữ nghĩa lệnh"
            )
            ranked.append(ProductMatch(product["id"], product["name"], score, reason))
    ranked.sort(key=lambda item: (-item.score, item.name))

    if popularity_requested and ranked:
        product_by_id = {item["id"]: item for item in candidate_products}
        ranked.sort(
            key=lambda item: (
                -product_by_id[item.product_id].get("sales_count", 0.0),
                -item.score,
                -product_by_id[item.product_id].get("rating", 0.0),
                item.name,
            )
        )

    if constraint_mismatches:
        # An explicit id that conflicts with the requested ingredient must not
        # silently turn into a different product or create a campaign.
        selected = []

    # Product names explicitly present in the brief are safe to select together.
    # An explicit id is the user's decision: never complement it by name/fuzzy.
    normalized_brief = normalize_text(brief_text)
    for item in ([] if has_explicit_ids else ranked):
        product_name = normalize_text(item.name)
        if product_name and product_name in normalized_brief and item.product_id not in {x.product_id for x in selected}:
            selected.append(ProductMatch(item.product_id, item.name, max(item.score, 0.98), "Tên xuất hiện trong lệnh"))

    popularity_data_available = any(item.get("sales_count", 0.0) > 0 for item in candidate_products)
    if (
        not has_explicit_ids
        and not selected
        and ranked
        and not constraint_mismatches
        and (not popularity_requested or popularity_data_available)
    ):
        eligible = [item for item in ranked if item.score >= threshold]
        if eligible:
            if requested_limit > 1:
                selected.extend(eligible[:requested_limit])
            else:
                top = eligible[0]
                second_score = eligible[1].score if len(eligible) > 1 else 0.0
                if top.score - second_score >= 0.08 or top.score >= 0.9:
                    selected.append(top)

    chosen_ids = {item.product_id for item in selected}
    if has_explicit_ids:
        # Suggestions only; the user must confirm any of them explicitly.
        ambiguous = [] if not unresolved_ids and selected else [
            item.to_dict() for item in ranked if item.product_id not in chosen_ids
        ][:MAX_CANDIDATES]
    else:
        ambiguous = [] if selected else [item.to_dict() for item in ranked[:MAX_CANDIDATES]]
    return {
        "selected": [item.to_dict() for item in selected],
        "ambiguous": ambiguous,
        "unresolved_ids": unresolved_ids,
        "unresolved_reasons": unresolved_reasons,
        "catalog_size": len(products),
        "constraints": constraints,
        "popularity_requested": popularity_requested,
        "popularity_data_available": any(item.get("sales_count", 0.0) > 0 for item in candidate_products),
        "constraint_mismatches": constraint_mismatches,
    }
