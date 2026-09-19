"""Pure consultation context rules."""
from __future__ import annotations

import re
from .ingredients import normalized_text, usable_ingredients, normalize_exclusions


def text_list(value) -> list[str]:
    if isinstance(value, str):
        value = re.split(r"[,;\n]", value)
    return [str(v).strip() for v in (value or []) if str(v).strip()] if isinstance(value, (list, tuple)) else []


def normalize_history(history, current_message=None) -> list[dict]:
    result = []
    for item in history if isinstance(history, list) else []:
        if isinstance(item, dict):
            role = item.get("role", item.get("sender", ""))
            content = item.get("content", item.get("text", ""))
        elif isinstance(item, str) and ":" in item:
            role, content = item.split(":", 1)
        else:
            continue
        role = str(role).strip().lower()
        if role in ("ai", "assistant", "bot", "skinsyntax ai"):
            role = "assistant"
        elif role in ("user", "khach", "khách", "khách hàng"):
            role = "user"
        else:
            continue
        if isinstance(content, str) and content.strip():
            result.append({"role": role, "content": content.strip()})
    # Legacy widgets include the just-submitted message in history as well.
    if current_message and result and result[-1] == {"role": "user", "content": current_message.strip()}:
        result.pop()
    return result


def normalize_profile(profile) -> dict:
    p = dict(profile) if isinstance(profile, dict) else {}
    p["skin_type"] = p.get("skin_type") or p.get("loai_da") or ""
    p["concerns"] = text_list(p.get("concerns") or p.get("skin_issues") or p.get("tinh_trang_da") or p.get("van_de_da"))
    p["avoid_ingredients"] = normalize_exclusions(text_list(p.get("avoid_ingredients") or p.get("thanh_phan_can_tranh") or p.get("thanh_phan_tranh")))
    p["sensitivity"] = p.get("sensitivity") or p.get("muc_do_nhay_cam") or ""
    if "budget" not in p:
        p["budget"] = p.get("ngan_sach")
    p["budget_mode"] = p.get("budget_mode") or ("bounded" if p.get("budget") else "unknown")
    return p


def asks_for_other_person(message: str) -> bool:
    return bool(re.search(r"\b(?:cho|của)\s+(?:mẹ|ba|bố|chị|anh|em gái|em trai|vợ|chồng|con|bạn gái|bạn trai|bạn mình)\b", message, re.I))


def product_metadata(item: dict) -> dict:
    """Preserve complete facts when converting the PHP product DTO."""
    # The PHP/Mongo catalog has three real ingredient columns. Older DTOs use
    # thanh_phan_chinh/thanh_phan_day_du, so accept every alias at this boundary.
    raw_full = (
        item.get("thanh_phan_full")
        or item.get("thanh_phan_day_du")
        or item.get("inci")
    )
    raw_clean = item.get("thanh_phan_sach") or item.get("thanh_phan_chinh") or item.get("ingredients") or item.get("thanh_phan") or item.get("summary")
    full = usable_ingredients(raw_full)
    ingredients = raw_clean or full or ""
    texts = [full, ingredients, item.get('mo_ta') or item.get('description') or '',
             item.get('hdsd') or item.get('usage_instructions') or item.get('huong_dan_su_dung') or '']
    ambiguous = item.get('variant_unverified') is True or any(
        re.search(r'(?:^|\s)[2-9]\.\s+(?:Kem|Nước|Serum|Toner|Sữa|Gel|Mặt nạ|Dưỡng|Tinh chất|Dung dịch|Combo)\b', str(t), re.I)
        or len(re.findall(r'phiên bản\s+\w+', str(t), re.I)) > 1 for t in texts)
    # Product ID/name/price remain usable. Never assign a merged page's formula,
    # benefits or usage to a particular variant without a verified mapping.
    if ambiguous:
        full, ingredients = '', ''
    status = str(item.get('stock_status') or item.get('trang_thai') or item.get('status') or '').lower()
    if status in ('inactive', 'hidden', 'tam_an', 'disabled', 'off', '0'):
        status = 'hidden'
    for key in ('so_luong_ton', 'ton_kho', 'stock', 'quantity'):
        if item.get(key) is not None:
            try:
                if float(item[key]) <= 0:
                    status = 'out_of_stock'
            except (ValueError, TypeError):
                pass
    return {
        "id": str(item.get("ma_san_pham") or item.get("id") or item.get("product_id") or "").removeprefix('product_'),
        "ten_san_pham": item.get("ten_san_pham") or item.get("name") or "",
        "thuong_hieu": item.get("thuong_hieu") or item.get("brand") or "",
        "gia_ban": item.get("gia_ban", item.get("price", 0)),
        "loai_da": item.get("loai_da") or item.get("skin_type") or "",
        "loai_san_pham": item.get("loai_san_pham") or item.get("category") or "",
        "xuat_xu_thuong_hieu": item.get("xuat_xu_thuong_hieu") or item.get("xuat_xu") or "",
        "link_hinh_anh": item.get("link_hinh_anh") or item.get("image_url") or "",
        "thanh_phan_chinh": ingredients,
        "thanh_phan_day_du": full,
        "thanh_phan_full": item.get("thanh_phan_full") or item.get("thanh_phan_day_du") or "",
        "thanh_phan_sach": item.get("thanh_phan_sach") or "",
        "thanh_phan": item.get("thanh_phan") or "",
        "ingredients_complete": not ambiguous and (bool(full) or item.get("ingredients_complete") is True),
        "variant_unverified": bool(ambiguous),
        "huong_dan_su_dung": '' if ambiguous else (item.get("usage_instructions") or item.get("huong_dan_su_dung") or item.get('hdsd') or ""),
        "mo_ta": '' if ambiguous else (item.get("mo_ta") or item.get("description") or ""),
        "source_url": item.get("source_url") or "",
        "stock_status": status,
    }
