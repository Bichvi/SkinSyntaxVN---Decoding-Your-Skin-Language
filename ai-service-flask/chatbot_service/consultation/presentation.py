"""Serialize catalog facts without synthesizing prices, discounts or usage."""
import json
from urllib.parse import quote


def product_id(doc):
    return str(doc.id or doc.metadata.get("id") or doc.metadata.get("ma_san_pham") or "").removeprefix("product_")


def product_cards(docs):
    cards = []
    for doc in docs:
        m = doc.metadata
        pid = product_id(doc)
        if not pid or not m.get("ten_san_pham"):
            continue
        try:
            price = max(0, int(float(m.get("gia_ban") or 0)))
        except (ValueError, TypeError, OverflowError):
            price = 0
        cards.append({
            "id": pid, "name": m["ten_san_pham"], "brand": m.get("thuong_hieu", ""),
            "price": price, "image_url": str(m.get("link_hinh_anh") or '').split('|', 1)[0].strip(),
            "detail_url": "index.php?r=chitiet&id=" + quote(pid, safe=""),
            "summary": str(m.get("thanh_phan_chinh") or "")[:120],
        })
    return cards


def catalog_context(docs):
    facts = []
    for doc in docs:
        m = doc.metadata
        cards = product_cards([doc])
        if not cards:
            continue
        facts.append({
            **cards[0], "category": m.get("loai_san_pham"), "skin_type": m.get("loai_da"),
            "ingredients": m.get("thanh_phan_day_du") or m.get("inci") or m.get("thanh_phan_chinh"),
            "ingredients_complete": bool(m.get("thanh_phan_day_du") or m.get("inci")) or m.get("ingredients_complete") is True,
            "variant_unverified": m.get('variant_unverified', False),
            "usage_instructions": m.get("huong_dan_su_dung") or None,
            "description": str(m.get("mo_ta") or "")[:1500],
        })
    return json.dumps(facts, ensure_ascii=False)
