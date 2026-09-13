# -*- coding: utf-8 -*-
"""
structured_recommendation.py — Shared LangChain Core Structured Recommendation Service.

Implements deterministic product recommendation, intent & filter extraction, hard constraint validation,
routine budget checking, candidate debug tracing, and post-rerank final validation.
"""
from __future__ import annotations

import logging
import re
import time
import unicodedata
from typing import Any, Dict, List, Optional, Tuple

logger = logging.getLogger(__name__)

from retrieval import get_hybrid_pipeline, build_filter
from recommendation.mongo_source import get_database, normalize_product, visible_filter
from pipeline import _rule_based_parse
from schemas import PhanTichYeuCau, SYNONYMS_SP, ALLOWED_LOAI_SP

# Category Keyword Synonym Dictionary for Hard Constraint Verification
CATEGORY_KEYWORDS: Dict[str, List[str]] = {
    "Serum / Tinh Chất": ["serum", "tinh chất", "tinh chat", "ampoule", "essence"],
    "Chống Nắng Da Mặt": ["chống nắng", "chong nang", "kcn", "sunscreen", "sunblock"],
    "Mặt Nạ Giấy": ["mặt nạ", "mat na", "mask"],
    "Mặt Nạ Rửa": ["mặt nạ rửa", "mat na rua", "clay mask"],
    "Mặt Nạ Ngủ": ["mặt nạ ngủ", "mat na ngu", "sleeping mask"],
    "Sữa Rửa Mặt": ["sữa rửa mặt", "sua rua mat", "srm", "cleanser", "rửa mặt"],
    "Tẩy Trang Mặt": ["tẩy trang", "tay trang", "cleansing water", "cleansing oil", "micellar"],
    "Toner / Nước Cân Bằng Da": ["toner", "nước cân bằng", "nuoc can bang", "nước hoa hồng"],
    "Kem / Gel / Dầu Dưỡng": ["kem dưỡng", "kem duong", "gel dưỡng", "gel duong", "cream"],
    "Lotion / Sữa Dưỡng": ["lotion", "sữa dưỡng", "sua duong"],
    "Tẩy Tế Bào Chết Da Mặt": ["tẩy tế bào chết", "tay te bao chet", "peel", "scrub", "exfoliator"],
    "Hỗ Trợ Trị Mụn": ["trị mụn", "tri mun", "chấm mụn", "spot treatment"],
    "Son Dưỡng Môi": ["son dưỡng", "son duong", "lip balm"],
    "Xịt Khoáng": ["xịt khoáng", "xit khoang", "mist"],
}

# Excluded Keywords to prevent category cross-contamination (e.g. mask or deodorant containing 'tinh chất')
EXCLUDED_KEYWORDS_BY_CAT: Dict[str, List[str]] = {
    "Serum / Tinh Chất": ["mặt nạ", "mat na", "lăn", "lan", "tẩy trang", "tay trang", "sữa rửa mặt", "sua rua mat", "son", "dưỡng tóc", "duong toc"],
    "Kem / Gel / Dầu Dưỡng": ["sữa rửa mặt", "sua rua mat", "tẩy trang", "tay trang", "mặt nạ", "mat na"],
    "Sữa Rửa Mặt": ["tẩy trang", "tay trang", "mặt nạ", "mat na", "kem dưỡng", "serum"],
    "Tẩy Trang Mặt": ["sữa rửa mặt", "sua rua mat", "mặt nạ", "mat na", "kem dưỡng"],
    "Chống Nắng Da Mặt": ["mặt nạ", "mat na", "sữa rửa mặt", "sua rua mat"],
    "Toner / Nước Cân Bằng Da": ["mặt nạ", "mat na", "sữa rửa mặt", "sua rua mat", "kem dưỡng"],
}


def _norm(text: Any) -> str:
    """Normalize text for Vietnamese keyword matching."""
    text = unicodedata.normalize("NFKD", str(text or "").lower())
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    return re.sub(r"\s+", " ", text).strip()


def preg_split_ingredients(text: str) -> List[str]:
    """Split ingredient string by common delimiters."""
    return re.split(r"[,;|\n\r]+", text)


def _extract_ingredients(product: dict) -> List[str]:
    """Extract ingredient list from product fields."""
    raw = str(product.get("thanh_phan_chinh") or product.get("thanh_phan_day_du") or product.get("thanh_phan") or "").strip()
    if not raw:
        return []
    parts = preg_split_ingredients(raw)
    return [p.strip() for p in parts if p.strip()]


def _extract_price_range(query: str) -> Tuple[Optional[int], Optional[int]]:
    """
    Extract (min_price, max_price) from Vietnamese natural query.
    Examples:
      - 'toner từ 100k đến 300k' -> (100000, 300000)
      - 'serum dưới 100k' -> (None, 100000)
      - 'kem dưỡng trên 200k' -> (200000, None)
    """
    q_norm = _norm(query)
    if not q_norm:
        return None, None

    # 1. Range "từ X đến Y" or "X - Y"
    m_range = re.search(
        r"(?:tu\s+)?(\d+(?:\.\d+)?)\s*(k|trieu|tr|000)?\s*(?:den|-|tơi)\s*(\d+(?:\.\d+)?)\s*(k|trieu|tr|000)?",
        q_norm,
    )
    if m_range:
        v1_raw, u1_raw, v2_raw, u2_raw = m_range.groups()
        unit1 = u1_raw or u2_raw or "k"
        unit2 = u2_raw or u1_raw or "k"

        val1 = float(v1_raw)
        val2 = float(v2_raw)

        m1 = 1000000 if unit1 in ("trieu", "tr") else 1000
        m2 = 1000000 if unit2 in ("trieu", "tr") else 1000

        if val1 < 1000 and m1 == 1000:
            val1 *= 1000
        if val2 < 1000 and m2 == 1000:
            val2 *= 1000

        return int(min(val1, val2)), int(max(val1, val2))

    # 2. "duoi X" / "< X" / "tôi đa X"
    m_under = re.search(r"(?:duoi|<|toi da|khoang)\s*(\d+(?:\.\d+)?)\s*(k|trieu|tr|000)?", q_norm)
    if m_under:
        val_raw, unit = m_under.groups()
        val = float(val_raw)
        u = unit or "k"
        if u in ("trieu", "tr"):
            val *= 1000000
        elif u in ("k", "000") or val < 1000:
            val *= 1000
        return None, int(val)

    # 3. "tren X" / "> X" / "tôi thieu X"
    m_over = re.search(r"(?:tren|>|toi thieu)\s*(\d+(?:\.\d+)?)\s*(k|trieu|tr|000)?", q_norm)
    if m_over:
        val_raw, unit = m_over.groups()
        val = float(val_raw)
        u = unit or "k"
        if u in ("trieu", "tr"):
            val *= 1000000
        elif u in ("k", "000") or val < 1000:
            val *= 1000
        return int(val), None

    return None, None


def validate_recommendation(product: dict, constraints: dict) -> Tuple[bool, Optional[str]]:
    """
    Deterministically validate if product satisfies all extracted hard constraints.
    Returns (is_valid: bool, rejection_reason: Optional[str]).
    """
    prod_name = str(product.get("ten_san_pham") or "").strip()
    prod_cat = str(product.get("loai_san_pham") or product.get("danh_muc_day_du") or "").strip()
    price = int(product.get("gia_ban") or 0)
    prod_main_ing = str(product.get("thanh_phan_chinh") or "").strip()
    prod_full_ing = str(product.get("thanh_phan_day_du") or product.get("thanh_phan") or "").strip()

    norm_all = _norm(f"{prod_name} {prod_cat}")

    # 1. Category Constraint
    req_cat = constraints.get("category")
    if req_cat:
        ex_keywords = EXCLUDED_KEYWORDS_BY_CAT.get(req_cat) or []
        for ex_kw in ex_keywords:
            if _norm(ex_kw) in _norm(prod_name) or _norm(ex_kw) in _norm(prod_cat):
                return False, f"category mismatch (excluded keyword '{ex_kw}'): expected {req_cat}, actual {prod_cat or prod_name}"

        cat_match = False
        if _norm(req_cat) in _norm(prod_cat):
            cat_match = True
        else:
            keywords = CATEGORY_KEYWORDS.get(req_cat) or [req_cat.lower()]
            for kw in keywords:
                if _norm(kw) in norm_all:
                    cat_match = True
                    break
        if not cat_match:
            return False, f"category mismatch: expected {req_cat}, actual {prod_cat or prod_name}"

    # 2. Max Price Constraint
    max_price = constraints.get("max_price")
    if max_price and max_price > 0 and price > 0:
        if price > max_price:
            return False, f"max_price exceeded: price {price:,.0f}đ > max_price {max_price:,.0f}đ"

    # 3. Min Price Constraint
    min_price = constraints.get("min_price")
    if min_price and min_price > 0 and price > 0:
        if price < min_price:
            return False, f"min_price undercut: price {price:,.0f}đ < min_price {min_price:,.0f}đ"

    # 4. Strict Avoid Ingredients Constraint
    avoid_ingredients = constraints.get("avoid_ingredients") or []
    if avoid_ingredients and (prod_main_ing or prod_full_ing):
        ing_norm = _norm(f"{prod_main_ing} {prod_full_ing}")
        for bad_ing in avoid_ingredients:
            bad_norm = _norm(bad_ing)
            if bad_norm and bad_norm in ing_norm:
                return False, f"avoid ingredient matched: product contains {bad_ing}"

    return True, None


def validate_routine_recommendation(products: List[dict], max_price: Optional[int]) -> Tuple[bool, Optional[str]]:
    """
    Validate if sum of prices for full recommended routine <= max_price constraint.
    Returns (is_valid: bool, rejection_reason: Optional[str]).
    """
    if not max_price or max_price <= 0:
        return True, None

    total_price = sum(int(p.get("gia_ban") or 0) for p in products)
    if total_price > max_price:
        return False, f"Routine total price {total_price:,.0f}đ exceeds budget constraint {max_price:,.0f}đ"

    return True, None


def parse_query_constraints(query: str, profile: dict) -> dict:
    """Extract intent and hard/soft constraints from user query and profile."""
    yc = _rule_based_parse(query) if query else None

    user_skin = str(profile.get("skin_type") or "").strip()
    user_concerns = profile.get("concerns") or []
    if isinstance(user_concerns, str):
        user_concerns = [c.strip() for c in user_concerns.split(",") if c.strip()]
    user_avoid = profile.get("avoid_ingredients") or []
    if isinstance(user_avoid, str):
        user_avoid = [a.strip() for a in user_avoid.split(",") if a.strip()]

    profile_budget = profile.get("budget")
    try:
        profile_budget_val = int(float(str(profile_budget).replace(".", "").replace(",", ""))) if profile_budget else None
    except Exception:
        profile_budget_val = None

    q_min_price, q_max_price = _extract_price_range(query) if query else (None, None)
    max_price = q_max_price or profile_budget_val
    min_price = q_min_price

    category = yc.loai_san_pham if yc and yc.loai_san_pham else None
    skin_type = (yc.loai_da if yc and yc.loai_da else None) or user_skin
    concerns = (yc.tinh_trang_da if yc and yc.tinh_trang_da else None) or user_concerns

    is_routine = bool(yc and yc.is_routine) or any(kw in _norm(query) for kw in ["routine", "chu trinh", "cac buoc", "combo"])

    intent = "PRODUCT_INQUIRY"
    if is_routine:
        intent = "ROUTINE"
    elif any(kw in _norm(query) for kw in ["thanh phan", "co chua", "hoat chat"]):
        intent = "INGREDIENT_INQUIRY"
    elif any(kw in _norm(query) for kw in ["so sanh", "khac nhau"]):
        intent = "PRODUCT_COMPARISON"

    return {
        "intent": intent,
        "category": category,
        "max_price": max_price,
        "min_price": min_price,
        "skin_type": skin_type,
        "concerns": concerns,
        "avoid_ingredients": user_avoid,
        "is_routine": is_routine,
        "yc": yc,
    }


def evaluate_product_fit(product: dict, profile: dict) -> dict:
    """Evaluate product against user profile and format response metadata."""
    reasons: List[str] = []
    warnings: List[str] = []
    matched_concerns: List[str] = []
    matched_ingredients: List[str] = []

    user_skin_type = str(profile.get("skin_type") or "").strip()
    user_concerns = profile.get("concerns") or []
    if isinstance(user_concerns, str):
        user_concerns = [c.strip() for c in user_concerns.split(",") if c.strip()]

    avoid_ingredients = profile.get("avoid_ingredients") or []
    if isinstance(avoid_ingredients, str):
        avoid_ingredients = [a.strip() for a in avoid_ingredients.split(",") if a.strip()]
    avoid_ingredients = [a for a in avoid_ingredients if _norm(a) not in ("khong co", "khong co / khong quan tam", "none", "")]

    budget = profile.get("budget")
    try:
        budget_val = int(float(str(budget).replace(".", "").replace(",", ""))) if budget else None
    except Exception:
        budget_val = None

    prod_name = str(product.get("ten_san_pham") or "").strip()
    prod_skin = str(product.get("loai_da") or "").strip()
    prod_main_ing = str(product.get("thanh_phan_chinh") or "").strip()
    prod_full_ing = str(product.get("thanh_phan_day_du") or product.get("thanh_phan") or "").strip()
    price = int(product.get("gia_ban") or 0)

    combined_ing_norm = _norm(f"{prod_main_ing} {prod_full_ing}")
    combined_all_norm = _norm(f"{prod_name} {prod_skin} {prod_main_ing} {prod_full_ing} {product.get('mo_ta') or ''}")

    has_ingredient_data = bool(prod_main_ing or prod_full_ing)
    if not has_ingredient_data:
        warnings.append("Chưa có đủ dữ liệu thành phần chi tiết để đánh giá mức độ kích ứng")
        data_confidence = "low"
        safety_text = "insufficient_data"
    else:
        data_confidence = "high"
        safety_text = "Không phát hiện thành phần loại trừ trong danh sách đã chọn."

    avoid_hit = False
    if has_ingredient_data and avoid_ingredients:
        for bad_ing in avoid_ingredients:
            bad_norm = _norm(bad_ing)
            if bad_norm and bad_norm in combined_ing_norm:
                avoid_hit = True
                warnings.append(f"Có chứa {bad_ing} (thành phần bạn chọn tránh)")
                safety_text = f"Cảnh báo: Sản phẩm có chứa {bad_ing} thuộc danh sách cần tránh."

    if budget_val and budget_val > 0 and price > 0:
        if price <= budget_val:
            reasons.append(f"Nằm trong ngân sách bạn thiết lập ({price:,.0f}đ)")
        else:
            reasons.append(f"Giá {price:,.0f}đ tiệm cận ngân sách ({budget_val:,.0f}đ)")

    if user_skin_type:
        user_skin_norm = _norm(user_skin_type)
        if user_skin_norm and (user_skin_norm in _norm(prod_skin) or user_skin_norm in combined_all_norm):
            reasons.append(f"Phù hợp với loại da {user_skin_type}")
        elif "moi loai da" in _norm(prod_skin) or "tat ca loai da" in _norm(prod_skin):
            reasons.append("Phù hợp cho mọi loại da")

    if user_concerns:
        for concern in user_concerns:
            c_norm = _norm(concern)
            if c_norm and (c_norm in combined_all_norm or c_norm in combined_ing_norm):
                matched_concerns.append(concern)
        if matched_concerns:
            reasons.append(f"Hỗ trợ cải thiện {', '.join(matched_concerns[:2])}")

    extracted = _extract_ingredients(product)
    matched_ingredients = extracted[:4]

    if not has_ingredient_data:
        fit_status = "chua_du_du_lieu"
        match_label = "Chưa đủ dữ liệu"
    elif avoid_hit or len(warnings) > 0:
        fit_status = "co_the_can_nhac"
        match_label = "Có thể cân nhắc"
    else:
        fit_status = "phu_hop"
        match_label = "Phù hợp"

    if not reasons:
        reasons.append("Sản phẩm thuộc nhóm gợi ý phù hợp với thông tin tìm kiếm")

    return {
        "product_id": str(product.get("ma_san_pham") or product.get("id") or ""),
        "ten_san_pham": prod_name,
        "thuong_hieu": str(product.get("ten_thuong_hieu") or product.get("thuong_hieu") or "SkinSyntax"),
        "gia_ban": price,
        "gia_thi_truong": int(product.get("gia_thi_truong") or 0),
        "link_hinh_anh": str(product.get("link_hinh_anh") or product.get("image_url") or ""),
        "loai_da": prod_skin if prod_skin else "Phù hợp đa số loại da",
        "thanh_phan_chinh": prod_main_ing if prod_main_ing else "Chưa có thông tin thành phần",
        "fit_status": fit_status,
        "match_label": match_label,
        "score": None,
        "match_percent": None,
        "reasons": list(dict.fromkeys(reasons))[:3],
        "warnings": list(dict.fromkeys(warnings))[:2],
        "matched_concerns": matched_concerns,
        "matched_ingredients": matched_ingredients,
        "safety_text": safety_text,
        "data_confidence": data_confidence,
        "reason": reasons[0] if reasons else "",
        "llm_explanation": None,
    }


def generate_structured_recommendation(payload: dict, limit: int = 6) -> dict:
    """
    Generate structured recommendations for endpoint /api/recommend with candidate tracing & final validation layer.
    """
    t0 = time.perf_counter()
    profile = payload.get("user_profile") or payload.get("recommendation_profile") or payload.get("profile") or {}
    user_query = str(payload.get("query") or payload.get("customer_question") or payload.get("message") or "").strip()

    # Trace Log Container
    candidate_trace = {
        "query": user_query,
        "parsed_intent": None,
        "parsed_constraints": None,
        "dense_candidates_count": 0,
        "bm25_candidates_count": 0,
        "rrf_candidates_count": 0,
        "hydrated_candidates_count": 0,
        "rejected_candidates": [],
        "passed_candidates_count": 0,
        "final_validation_passed": True,
        "routine_budget_check_passed": True,
    }

    # Step 1: Parse Query & Constraints
    t_parse_start = time.perf_counter()
    constraints = parse_query_constraints(user_query, profile)
    t_parse_ms = (time.perf_counter() - t_parse_start) * 1000.0

    candidate_trace["parsed_intent"] = constraints["intent"]
    candidate_trace["parsed_constraints"] = {
        "category": constraints.get("category"),
        "min_price": constraints.get("min_price"),
        "max_price": constraints.get("max_price"),
        "skin_type": constraints.get("skin_type"),
        "concerns": constraints.get("concerns"),
        "avoid_ingredients": constraints.get("avoid_ingredients"),
        "is_routine": constraints.get("is_routine"),
    }

    req_category = constraints.get("category")
    max_price = constraints.get("max_price")
    min_price = constraints.get("min_price")
    skin_type = constraints.get("skin_type") or "da hỗn hợp"
    concerns = constraints.get("concerns") or []
    concerns_str = ", ".join(concerns) if isinstance(concerns, list) else str(concerns)

    # Build Search Query Text
    search_query_parts = []
    if user_query:
        search_query_parts.append(user_query)
    if req_category:
        search_query_parts.append(f"sản phẩm {req_category}")
    if skin_type:
        search_query_parts.append(f"phù hợp cho {skin_type}")
    if concerns_str:
        search_query_parts.append(f"cải thiện {concerns_str}")
    search_text = ". ".join(search_query_parts) + "."

    # Build Chroma DB Metadata Filter
    chroma_filter = build_filter(constraints["yc"]) if constraints.get("yc") else None

    # Step 2: Retrieve Candidates via Hybrid Search (filters=None to let Hard Constraint Layer filter deterministically)
    t_retrieval_start = time.perf_counter()
    raw_candidates = []
    try:
        pipeline = get_hybrid_pipeline()
        ranked_docs, _ = pipeline.search(
            query=search_text,
            k_total=30,
            top_n=limit * 4,
            filters=None,
            use_reranker=True,
        )
        
        candidate_trace["rrf_candidates_count"] = len(ranked_docs)

        # Fast MongoDB Hydration with ChromaDB Metadata Fallback
        try:
            db = get_database(timeout_ms=500)
            db.command("ping")
            doc_ids = [r.doc_id for r in ranked_docs if r.doc_id]
            if doc_ids:
                cursor = db.san_pham.find({"$or": [{"ma_san_pham": {"$in": doc_ids}}, {"id": {"$in": doc_ids}}]})
                by_id = {str(doc.get("ma_san_pham") or doc.get("id") or ""): normalize_product(doc, db) for doc in cursor}
                for r in ranked_docs:
                    p = by_id.get(r.doc_id)
                    if p and visible_filter({}).get("stock_status") != "hidden":
                        raw_candidates.append(p)
        except Exception as mongo_err:
            logger.warning(f"[RECOMMEND] Mongo hydration unavailable: {mongo_err}. Hydrating from ChromaDB metadata.")
            for r in ranked_docs:
                meta = dict(getattr(r, "metadata", {}) or {})
                if meta:
                    p_id = str(meta.get("ma_san_pham") or getattr(r, "doc_id", "") or getattr(r, "id", "") or "")
                    meta["ma_san_pham"] = p_id
                    meta["id"] = p_id
                    meta["gia_ban"] = int(meta.get("gia_ban") or meta.get("gia") or 0)
                    meta["ten_san_pham"] = str(meta.get("ten_san_pham") or meta.get("ten") or "Sản phẩm Dưỡng Da")
                    meta["loai_san_pham"] = str(meta.get("loai_san_pham") or meta.get("danh_muc") or "")
                    meta["link_hinh_anh"] = str(meta.get("link_hinh_anh") or meta.get("image_url") or "")
                    meta["thanh_phan_chinh"] = str(meta.get("thanh_phan_chinh") or meta.get("thanh_phan") or "")
                    meta["loai_da"] = str(meta.get("loai_da") or "")
                    raw_candidates.append(meta)
    except Exception as exc:
        logger.warning(f"[RECOMMEND] Hybrid search failed: {exc}")

    t_retrieval_ms = (time.perf_counter() - t_retrieval_start) * 1000.0
    candidate_trace["hydrated_candidates_count"] = len(raw_candidates)

    # Step 3: Pre-Rerank Hard Constraint Validation Layer
    t_val_start = time.perf_counter()
    valid_candidates = []
    seen_ids = set()

    for prod in raw_candidates:
        p_id = str(prod.get("ma_san_pham") or prod.get("id") or "")
        if not p_id or p_id in seen_ids:
            continue

        is_valid, reason = validate_recommendation(prod, constraints)
        if is_valid:
            seen_ids.add(p_id)
            valid_candidates.append(prod)
        else:
            candidate_trace["rejected_candidates"].append({
                "product_id": p_id,
                "name": str(prod.get("ten_san_pham") or ""),
                "price": int(prod.get("gia_ban") or 0),
                "category": str(prod.get("loai_san_pham") or prod.get("danh_muc_day_du") or ""),
                "reason": reason,
            })

    # MongoDB Fallback Query if Chroma search with filter returned 0 candidates
    if len(valid_candidates) < limit:
        try:
            db = get_database(timeout_ms=500)
            db.command("ping")
            mongo_query = visible_filter({})
            if max_price and max_price > 0:
                mongo_query["gia_ban"] = {"$lte": max_price}
            if min_price and min_price > 0:
                if "gia_ban" in mongo_query:
                    mongo_query["gia_ban"]["$gte"] = min_price
                else:
                    mongo_query["gia_ban"] = {"$gte": min_price}
            if req_category:
                keywords = CATEGORY_KEYWORDS.get(req_category) or [req_category]
                regex_pattern = "|".join([re.escape(kw) for kw in keywords])
                mongo_query["$or"] = [
                    {"loai_san_pham": {"$regex": regex_pattern, "$options": "i"}},
                    {"danh_muc_day_du": {"$regex": regex_pattern, "$options": "i"}},
                    {"ten_san_pham": {"$regex": regex_pattern, "$options": "i"}},
                ]

            fallback_rows = db.san_pham.find(mongo_query, limit=limit * 2)
            for r in fallback_rows:
                norm_p = normalize_product(r, db)
                p_id = str(norm_p.get("ma_san_pham") or norm_p.get("id") or "")
                if p_id and p_id not in seen_ids:
                    is_valid, reason = validate_recommendation(norm_p, constraints)
                    if is_valid:
                        seen_ids.add(p_id)
                        valid_candidates.append(norm_p)
                    else:
                        candidate_trace["rejected_candidates"].append({
                            "product_id": p_id,
                            "name": str(norm_p.get("ten_san_pham") or ""),
                            "price": int(norm_p.get("gia_ban") or 0),
                            "category": str(norm_p.get("loai_san_pham") or norm_p.get("danh_muc_day_du") or ""),
                            "reason": reason,
                        })
        except Exception as mongo_err:
            logger.warning(f"[RECOMMEND] MongoDB fallback query unavailable: {mongo_err}")

    candidate_trace["passed_candidates_count"] = len(valid_candidates)

    # Step 4: Step Selection / Routine Budget Trim
    selected_candidates = valid_candidates[:limit]

    # Routine Total Budget Validation
    if constraints.get("is_routine") and max_price and max_price > 0:
        # Trim candidates so that sum(gia_ban) <= max_price
        total_budget_prods = []
        running_sum = 0
        for prod in selected_candidates:
            p_price = int(prod.get("gia_ban") or 0)
            if running_sum + p_price <= max_price:
                total_budget_prods.append(prod)
                running_sum += p_price

        if total_budget_prods:
            selected_candidates = total_budget_prods
        else:
            candidate_trace["routine_budget_check_passed"] = False

    # Step 5: Post-Rerank FINAL VALIDATION LAYER (Fail-Safe)
    final_validated_list = []
    for prod in selected_candidates:
        is_valid, reason = validate_recommendation(prod, constraints)
        if is_valid:
            final_validated_list.append(prod)
        else:
            candidate_trace["final_validation_passed"] = False

    t_val_ms = (time.perf_counter() - t_val_start) * 1000.0

    # Step 6: Format Output Objects
    evaluated_list: List[dict] = []
    for prod in final_validated_list:
        evaluated = evaluate_product_fit(prod, profile)
        evaluated_list.append(evaluated)

    t_total_ms = (time.perf_counter() - t0) * 1000.0

    message_note = None
    if not evaluated_list:
        message_note = "Không tìm thấy sản phẩm đáp ứng đầy đủ điều kiện hiện tại."

    return {
        "ok": True,
        "source": "langchain_shared_core",
        "query": user_query,
        "message_note": message_note,
        "extracted_filters": {
            "intent": constraints["intent"],
            "category": req_category,
            "max_price": max_price,
            "min_price": min_price,
            "skin_type": constraints.get("skin_type"),
            "concerns": constraints.get("concerns"),
            "avoid_ingredients": constraints.get("avoid_ingredients"),
            "is_routine": constraints.get("is_routine"),
        },
        "profile_summary": {
            "skin_type": skin_type,
            "concerns": concerns,
            "avoid_ingredients": constraints.get("avoid_ingredients"),
            "budget": profile.get("budget"),
        },
        "timing_ms": {
            "filter_extraction_ms": round(t_parse_ms, 2),
            "retrieval_ms": round(t_retrieval_ms, 2),
            "validation_ms": round(t_val_ms, 2),
            "total_latency_ms": round(t_total_ms, 2),
        },
        "debug_trace": candidate_trace,
        "recommendations": evaluated_list,
        "products": evaluated_list,
    }
