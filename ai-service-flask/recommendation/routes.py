from __future__ import annotations

from flask import Blueprint, jsonify, request

from .indexer import build_recommendation_index
from .service import service
from services.llamaindex_recommend_service import llamaindex_recommend_service


recommendation_bp = Blueprint("recommendation", __name__)

# Các endpoint API cho hệ thống recommendation, bao gồm gợi ý cho khách, gợi ý dựa trên hồ sơ người dùng, 
# gợi ý tương thích với PHP, gợi ý cá nhân hóa sử dụng LlamaIndex và
def _params_from_request() -> dict:
    args = request.args
    params = {
        "keyword": args.get("keyword", ""),
        "price_min": args.get("price_min", type=int),
        "price_max": args.get("price_max", type=int),
        "category": args.get("category", ""),
        "brand": args.get("brand", ""),
        "skin_type": args.get("skin_type", ""),
        "concerns": args.get("concerns", ""),
        "stock_status": args.get("stock_status", "in_stock"),
        "sort": args.get("sort", "popular"),
    }
    return params

# Các endpoint API cho hệ thống recommendation,
@recommendation_bp.get("/api/recommend/guest")
def guest_recommendation():
    params = _params_from_request()
    result = service.search(params.get("keyword", ""), params, request.args.get("limit", default=12, type=int))
    return jsonify(result)


@recommendation_bp.get("/api/recommend/profile/<user_id>")
def profile_recommendation(user_id: str):
    query, params, has_consent = service.profile_query(user_id)
    if not has_consent:
        params = _params_from_request() | params
        result = service.search("", params, request.args.get("limit", default=12, type=int))
        result["consent"] = "missing"
        result["summary"] = "User chưa consent nên hệ thống chỉ trả guest recommendation, không dùng dữ liệu cá nhân."
        return jsonify(result)

    result = service.search(query, params | {"stock_status": "in_stock"}, request.args.get("limit", default=12, type=int))
    result["consent"] = "granted"
    return jsonify(result)


@recommendation_bp.post("/api/recommend/langchain-rag")
def php_profile_compatibility():
    # Backward-compatible endpoint for current PHP xulygoiy(), now backed by LlamaIndex hybrid retrieval.
    payload = request.get_json(force=True, silent=True) or {}
    profile = payload.get("user_profile") or {}
    query = str(payload.get("query_text") or payload.get("user_query") or "").strip()
    if not query:
        parts = ["Recommend skincare products"]
        if profile.get("skin_type"):
            parts.append(f"for {profile.get('skin_type')} skin")
        if profile.get("concerns"):
            parts.append("concerns: " + ", ".join(map(str, profile.get("concerns") or [])))
        if profile.get("budget"):
            parts.append(f"budget under {profile.get('budget')} VND")
        query = ", ".join(parts)

    params = {
        "skin_type": profile.get("skin_type") or None,
        "concerns": ", ".join(map(str, profile.get("concerns") or [])) if profile.get("concerns") else None,
        "price_max": int(profile.get("budget") or 0) or None,
        "stock_status": "in_stock",
    }
    result = service.search(query, params, 12)
    return jsonify(result)


@recommendation_bp.post("/api/recommend/llamaindex")
def llamaindex_personalized_recommendation():
    payload = request.get_json(force=True, silent=True) or {}
    try:
        result = llamaindex_recommend_service.recommend(
            user_id=payload.get("user_id") or payload.get("session_user_id"),
            email=str(payload.get("email") or "").strip(),
        )
        return jsonify(result)
    except Exception as exc:
        print(f"[RECOMMEND][LLAMAINDEX] {exc}")
        message = str(exc).strip() or "Hiện chưa thể tạo gợi ý cá nhân hóa. Vui lòng thử lại sau."
        if "Recommendation index chưa được build" not in message:
            message = "Hiện chưa thể tạo gợi ý cá nhân hóa. Vui lòng thử lại sau."
        return jsonify({
            "ok": False,
            "message": message,
        }), 503

@recommendation_bp.post("/api/recommend/index")
def rebuild_index():
    limit = request.args.get("limit", type=int)
    return jsonify(build_recommendation_index(limit=limit))
