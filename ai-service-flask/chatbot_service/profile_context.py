"""Chuẩn hóa hồ sơ da của khách (thuần, không side-effect)."""
from typing import Any, Dict, List, Optional

_PLACEHOLDERS = {"không có / không quan tâm", "không có", "chưa xác định"}


def _as_list(value: Any) -> List[str]:
    if value is None:
        return []
    if isinstance(value, str):
        items = value.split(",")
    elif isinstance(value, (list, tuple, set)):
        items = list(value)
    else:
        items = [value]
    out: List[str] = []
    for it in items:
        s = str(it).strip() if it is not None else ""
        if s and s.lower() not in _PLACEHOLDERS:
            out.append(s)
    return out


def _first_list(profile: dict, *keys: str) -> List[str]:
    for k in keys:
        vals = _as_list(profile.get(k))
        if vals:
            return vals
    return []


def normalize_profile(profile: Any) -> Dict[str, Any]:
    if not isinstance(profile, dict):
        profile = {}

    # skin_type / goal là chuỗi đơn: giữ nguyên nếu có dấu phẩy trong giá trị gốc
    def _single(*keys: str) -> Optional[str]:
        for k in keys:
            v = profile.get(k)
            if isinstance(v, str) and v.strip() and v.strip().lower() not in _PLACEHOLDERS:
                return v.strip()
        vals = _first_list(profile, *keys)
        return ", ".join(vals) if vals else None
    return {
        "skin_type": _single("skin_type", "loai_da"),
        "skin_issues": _first_list(profile, "skin_issues", "tinh_trang_da", "concerns"),
        "avoid_ingredients": _first_list(profile, "avoid_ingredients", "thanh_phan_can_tranh"),
        "goal": _single("goal", "muc_tieu_cham_soc"),
    }


def profile_context_lines(normalized: Dict[str, Any]) -> List[str]:
    lines: List[str] = []
    if normalized.get("skin_type"):
        lines.append(f"- Loại da của khách: {normalized['skin_type']}")
    if normalized.get("skin_issues"):
        lines.append(f"- Tình trạng da: {', '.join(normalized['skin_issues'])}")
    if normalized.get("avoid_ingredients"):
        lines.append(f"- Thành phần cần tránh: {', '.join(normalized['avoid_ingredients'])}")
    if normalized.get("goal"):
        lines.append(f"- Mục tiêu chăm sóc: {normalized['goal']}")
    return lines
