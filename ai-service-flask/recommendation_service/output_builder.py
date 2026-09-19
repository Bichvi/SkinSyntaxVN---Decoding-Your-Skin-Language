"""Build the stable XAI routine response from selected factual products."""

from __future__ import annotations

from typing import Any

from .schemas import (
    AlternativeProduct,
    Combo,
    ConflictWarning,
    ProfileSummary,
    RecommendResponse,
    RecommendedProduct,
    RoutineStep,
    UserProfile,
)


def _match_score(product: dict[str, Any]) -> float:
    value = product.get("match_score")
    if value not in (None, ""):
        return round(max(0.0, min(100.0, float(value))), 2)
    return round(max(0.0, min(100.0, float(product.get("rrf_score") or 0.0) * 1000)), 2)


class OutputBuilder:
    """Translate pipeline internals into customer-safe, schema-versioned JSON."""

    @staticmethod
    def _why(product: dict[str, Any], profile: UserProfile, step: dict[str, Any]) -> str:
        ingredients = [str(item) for item in product.get("key_ingredients", []) if str(item).strip()]
        active = ", ".join(ingredients[:3])
        concerns = ", ".join(profile.concerns[:2])
        if step.get("category") == "sunscreen":
            return "Chống nắng giúp giảm tác động UV và hỗ trợ hạn chế thâm sạm; hãy thoa đủ lượng và thoa lại khi cần."
        if step.get("category") == "cleanser":
            return "Công thức làm sạch dịu giúp loại bỏ dầu và bụi mà vẫn phù hợp để bắt đầu routine."
        if active and concerns:
            return f"{active} là các thành phần nổi bật liên quan đến {concerns}; nên bắt đầu từ từ nếu da nhạy cảm."
        if active:
            return f"Thành phần nổi bật {active} phù hợp với mục tiêu của bước {step.get('step_name', 'chăm sóc da').lower()}."
        return "Sản phẩm được chọn từ dữ liệu catalog theo loại bước, hồ sơ da và các ràng buộc an toàn."

    @staticmethod
    def _product(product: dict[str, Any]) -> RecommendedProduct:
        return RecommendedProduct(
            product_id=str(product.get("id") or product.get("product_id") or ""),
            name=str(product.get("name") or product.get("ten_san_pham") or ""),
            price=int(product.get("price") or product.get("gia_ban") or 0),
            image=str(product.get("image") or product.get("image_url") or ""),
            match_score=_match_score(product),
            key_ingredients=[str(item) for item in product.get("key_ingredients", [])][:8],
            avoid_flags=[str(item) for item in product.get("avoid_flags", [])],
        )

    def _steps(
        self,
        profile: UserProfile,
        plan: list[dict[str, Any]],
        selected: list[dict[str, Any] | None],
        candidates: list[list[dict[str, Any]]],
    ) -> list[RoutineStep]:
        output: list[RoutineStep] = []
        for step, chosen, step_candidates in zip(plan, selected, candidates):
            alternatives = [
                AlternativeProduct(
                    product_id=str(item.get("id") or ""),
                    name=str(item.get("name") or item.get("ten_san_pham") or ""),
                    price=int(item.get("price") or item.get("gia_ban") or 0),
                    match_score=_match_score(item),
                )
                for item in step_candidates
                if chosen is None or str(item.get("id")) != str(chosen.get("id"))
            ][:2]
            output.append(
                RoutineStep(
                    step_name=str(step.get("step_name") or ""),
                    step_order=int(step.get("step_order") or 0),
                    recommended_product=self._product(chosen) if chosen else None,
                    alternatives=alternatives,
                    why_this_product=self._why(chosen, profile, step) if chosen else "Chưa tìm thấy sản phẩm đủ điều kiện cho bước này.",
                )
            )
        return output

    def build(
        self,
        profile: UserProfile,
        plan: Any,
        selected: dict[str, list[dict[str, Any] | None]],
        candidates: dict[str, list[list[dict[str, Any]]]],
        conflict_warnings: list[dict[str, Any]] | None = None,
        rejected: list[dict[str, str]] | None = None,
        latency_ms: float | None = None,
    ) -> RecommendResponse:
        """Build AM/PM steps, combo totals, warnings, and safety notes."""

        chosen = [item for session in ("am", "pm") for item in selected.get(session, []) if item]
        total = sum(int(item.get("price") or item.get("gia_ban") or 0) for item in chosen)
        regular_total = sum(int(item.get("market_price") or 0) for item in chosen)
        discount = (1 - total / regular_total) * 100 if regular_total > total > 0 else 0.0
        warnings = [ConflictWarning(**warning) for warning in (conflict_warnings or [])]
        avoided = ", ".join(profile.avoid_ingredients[:4])
        safety = "Hard filter đã loại sản phẩm chứa thành phần cần tránh."
        if avoided:
            safety += f" Danh sách tránh: {avoided}."
        if profile.sensitive_level:
            safety += " Da nhạy cảm nên patch-test và tăng tần suất treatment từ từ."
        if rejected and not chosen:
            safety += " Hiện chưa có đủ sản phẩm vượt qua toàn bộ bộ lọc; hãy nới ngân sách hoặc kiểm tra dữ liệu thành phần."

        return RecommendResponse(
            schema_version="1.0",
            routine_type="personalized",
            profile_summary=ProfileSummary(
                skin_type=profile.skin_type,
                concerns=profile.concerns,
                avoided=profile.avoid_ingredients,
            ),
            am_routine=self._steps(profile, plan.am, selected.get("am", []), candidates.get("am", [])),
            pm_routine=self._steps(profile, plan.pm, selected.get("pm", []), candidates.get("pm", [])),
            combo=Combo(
                total_price=total,
                discount_percent=round(max(0.0, min(100.0, discount)), 2),
                product_ids=[str(item.get("id")) for item in chosen],
            ),
            conflict_warnings=warnings,
            safety_notes=safety,
            latency_ms=round(latency_ms, 2) if latency_ms is not None else None,
        )
