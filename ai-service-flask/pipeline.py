# -*- coding: utf-8 -*-
"""
pipeline.py — Main RAG orchestrator for the SkinSyntaxVN chatbot.
"""
from __future__ import annotations

import logging
logger = logging.getLogger(__name__)

import hashlib
import json
import os
import re
import time
from typing import Optional, Generator

from llm_pool import (
    get_llms, get_classifier_llm,
    build_str_chain, build_json_chain,
)
from prompts import (
    analyze_and_parse_prompt,
    product_prompt, knowledge_prompt, general_prompt, comparison_prompt,
)
from retrieval import (
    get_hybrid_pipeline,
    build_filter, format_search_results, docs_to_products,
    MockDocument,
)
from schemas import PhanTichYeuCau, dict_to_yc
from hybrid_search import RankedDocument
from session_cache import get_history, get_last_products, get_session_state, save_turn, update_session_state

_WEB_CACHE: dict[str, tuple[dict, float]] = {}
_WEB_CACHE_TTL = 900


def _extract_budget_vnd(text: str) -> int | None:
    """
    Trích xuất ngân sách tối đa (VNĐ) từ câu hỏi.
    Hỗ trợ: "dưới 800k", "tổng 1 triệu", "không quá 500.000", "1.5tr"
    """
    t = text.lower()
    m = re.search(r'(\d+(?:[.,]\d+)?)\s*(?:triệu|tr\b)', t)
    if m:
        return int(float(m.group(1).replace(',', '.')) * 1_000_000)
    m = re.search(r'(\d+(?:[.,]\d{3})?)\s*(?:k\b|\.000|đồng|vnđ|vnd)', t)
    if m:
        raw = m.group(1).replace('.', '').replace(',', '')
        val = int(raw)
        return val * 1000 if val < 10000 else val  # 800k → 800000, 800000 → 800000
    m = re.search(r'(\d{3,4})k\b', t)
    if m:
        return int(m.group(1)) * 1000
    return None


def _doc_price(doc) -> float:
    try:
        return float(doc.metadata.get("gia_ban", 0) or 0)
    except (ValueError, TypeError):
        return 0.0


_MORNING_SKIP_CATS = {"Tẩy Trang Mặt"}
_ESSENTIAL_ROUTINE_CATS = ("Sữa Rửa Mặt", "Chống Nắng Da Mặt")
_OPTIONAL_ROUTINE_CATS = (
    "Kem / Gel / Dầu Dưỡng",
    "Serum / Tinh Chất",
    "Toner / Nước Cân Bằng Da",
    "Tẩy Trang Mặt",
)


def _is_morning_routine(message: str) -> bool:
    m = message.lower()
    if any(k in m for k in ("buổi tối", "buoi toi", "tối", " evening")):
        return False
    return any(k in m for k in ("buổi sáng", "buoi sang", "sáng", "morning"))


def _affordable_docs(docs: list, budget: int) -> list:
    """Chỉ giữ sản phẩm có giá xác định và ≤ ngân sách."""
    if not budget:
        return docs
    return [d for d in docs if 0 < _doc_price(d) <= budget]


def _fit_docs_to_budget(
    docs: list,
    budget: int,
    *,
    is_routine: bool,
    message: str = "",
) -> list:
    if not budget or not docs:
        return docs

    docs = _affordable_docs(docs, budget)
    if not docs:
        logger.debug(f"[BUDGET] No products at or below {budget:,} VNĐ")
        return []

    if is_routine:
        skip = _MORNING_SKIP_CATS if _is_morning_routine(message) else set()
        by_cat: dict[str, list] = {}
        for d in docs:
            cat = str(d.metadata.get("loai_san_pham", "") or "_other")
            if cat in skip:
                continue
            by_cat.setdefault(cat, []).append(d)
        for items in by_cat.values():
            items.sort(key=_doc_price)

        selected: list = []
        total = 0.0

        def _try_add(cat: str) -> None:
            nonlocal total
            items = by_cat.get(cat)
            if not items:
                return
            d = items[0]
            price = _doc_price(d)
            if price <= 0 or total + price <= budget:
                selected.append(d)
                total += max(price, 0)

        for cat in _ESSENTIAL_ROUTINE_CATS:
            _try_add(cat)
        for cat in _OPTIONAL_ROUTINE_CATS:
            _try_add(cat)
        for cat in by_cat:
            if cat not in _ESSENTIAL_ROUTINE_CATS and cat not in _OPTIONAL_ROUTINE_CATS:
                _try_add(cat)

        if selected:
            logger.debug(
                f"[BUDGET] Routine fit: {len(selected)} items, total≈{total:,.0f} / {budget:,}"
            )
            return selected

    sorted_docs = sorted(docs, key=_doc_price)
    selected, total = [], 0.0
    for d in sorted_docs:
        price = _doc_price(d)
        if price <= 0:
            selected.append(d)
            continue
        if total + price <= budget:
            selected.append(d)
            total += price

    if selected:
        logger.debug(
            f"[BUDGET] Picked {len(selected)} items, total≈{total:,.0f} / {budget:,}"
        )
        return selected

    logger.debug(f"[BUDGET] Nothing fits within {budget:,} VNĐ after filtering")
    return []


def _query_web(query: str, max_results: int = 2) -> dict:
    tavily_key = os.getenv("TAVILY_API_KEY", "").strip()
    if not tavily_key:
        return {}

    cache_key = hashlib.md5(query.strip().lower().encode()).hexdigest()
    cached = _WEB_CACHE.get(cache_key)
    if cached:
        result, ts = cached
        if time.monotonic() - ts < _WEB_CACHE_TTL:
            logger.debug(f"[WEB] Cache hit: '{query[:50]}'")
            return result

    try:
        try:
            from langchain_tavily import TavilySearch
        except Exception:
            try:
                from langchain_community.tools.tavily_search import TavilySearchResults as TavilySearch
            except Exception:
                TavilySearch = None

        if TavilySearch is None:
            return {}

        result = TavilySearch(max_results=max_results).invoke({"query": query})
        _WEB_CACHE[cache_key] = (result, time.monotonic())
        return result
    except Exception as e:
        logger.warning(f"[WEB] Tavily failed: {e}")
        return {}


def _format_web_results(result: dict) -> str:
    items = result.get("results", []) if isinstance(result, dict) else []
    if not items:
        return "Không có dữ liệu web phù hợp."
    blocks = []
    for i, item in enumerate(items, 1):
        content = item.get("content", "")
        snippet = content[:350] + "…" if len(content) > 350 else content
        blocks.append(f"Nguồn {i}: {item.get('title', '')}\n{snippet}\n{item.get('url', '')}")
    return "\n\n".join(blocks)



_CHITCHAT_EXACT = {
    "chào", "hello", "hi", "hey", "alo", "ờ", "ok", "okay",
    "cảm ơn", "cám ơn", "cảm ơn bạn", "cảm ơn nha", "cám ơn nha",
    "tạm biệt", "bye", "goodbye", "hẹn gặp lại",
    "haha", "hihi", "hehe",
}

_CHITCHAT_PREFIXES = (
    "chào", "hello", "hi ", "hey ", "alo",
    "cảm ơn", "cám ơn", "cảm on", "cam on",
    "tạm biệt", "tam biet", "bye",
    "ráng lên", "cố lên", "chúc mừng",
)


def _is_pure_chitchat(message: str) -> bool:
    q = message.strip().lower()
    if len(q) > 60:
        return False
    if q in _CHITCHAT_EXACT:
        return True
    return any(q.startswith(p) for p in _CHITCHAT_PREFIXES)


def _default_chitchat_reply(message: str) -> str:
    q = message.lower()
    if any(k in q for k in ["cảm ơn", "cám ơn"]):
        return "Không có gì bạn ơi! Nếu cần tư vấn thêm về sản phẩm, mình luôn sẵn sàng nhé."
    if any(k in q for k in ["tạm biệt", "bye"]):
        return "Tạm biệt bạn nhé! Chúc bạn có làn da khỏe đẹp!"
    return "Chào bạn! Mình là trợ lý AI của SkinSyntaxVN. Bạn muốn tìm sản phẩm gì hôm nay ạ?"


def _pick_variant(templates: list[str], message: str, msg_data: dict | None, namespace: str) -> str:
    from session_cache import next_rotating_index
    idx = next_rotating_index(msg_data, namespace, len(templates))
    return templates[idx]


OFF_TOPIC_SUGGESTIONS = (
    "Da dầu mụn nên dùng srm gì?",
    "Gợi ý routine buổi sáng cho da dầu",
    "Serum vitamin C giá dưới 300k",
    "Kem chống nắng cho da nhạy cảm",
)


_OFF_TOPIC_PERSONAL = (
    "Haha, câu này hơi ngoài chuyên môn của mình rồi — mình chỉ giỏi chuyện chăm da và mỹ phẩm thôi. Bạn kể thêm về làn da đang gặp vấn đề gì, mình tư vấn liền nhé?",
    "Nghe vui đấy! Nhưng mình là trợ lý skincare SkinSyntaxVN, chuyện tình cảm thì hơi quá sức mình rồi. Quay lại chăm da nha — da bạn đang dầu, khô hay mụn?",
    "Mình không phải tư vấn tình yêu, mà là tư vấn routine đấy bạn ơi. Bạn muốn mình gợi ý sản phẩm hay xây chu trình sáng/tối không?",
)

_OFF_TOPIC_TECH = (
    "Điện thoại thì shop mình chưa bán, nhưng serum, kcn, srm thì mình tư vấn cực kỳ nhiệt tình! Bạn đang tìm loại sản phẩm skincare nào?",
    "iPhone thì mình không rành, còn ingredient list trên mặt nạ thì mình đọc được ngay. Bạn cần gợi ý gì cho làn da không?",
    "Món đó nằm ngoài danh mục SkinSyntaxVN rồi bạn ơi. Nếu bạn muốn mua mỹ phẩm chính hãng, mình gợi ý theo loại da và ngân sách nhé!",
)

_OFF_TOPIC_FINANCE = (
    "Giá vàng, coin… mình không theo dõi được đâu bạn ơi. Còn giá serum, kcn trong shop thì mình báo chuẩn lắm — bạn muốn tìm mức giá nào?",
    "Chuyện đầu tư thì quá sức mình rồi! Mình chỉ đầu tư thời gian để tìm sản phẩm phù hợp làn da thôi. Bạn cần gợi ý gì không?",
)

_OFF_TOPIC_SPORTS = (
    "Bóng đá thì mình không comment được, còn da đang 'thua' vì mụn thì mình cứu được! Bạn kể tình trạng da mình nghe?",
    "Thời tiết thì mình không dự báo được, nhưng routine mùa nóng/mùa lạnh thì mình có thể gợi ý ngay. Da bạn đang cần hỗ trợ gì?",
)

_OFF_TOPIC_GENERAL = (
    "Câu này hơi lạc đề so với chuyên môn mình rồi — mình chỉ hỗ trợ skincare và đơn hàng SkinSyntaxVN thôi. Bạn muốn tư vấn sản phẩm hay routine không?",
    "Mình hiểu bạn đang trò chuyện vui, nhưng mình được thiết kế để tư vấn mỹ phẩm thôi nhé. Thử hỏi kiểu \"da dầu mụn nên dùng srm gì\" xem!",
    "Oops, hơi ngoài phạm vi mình rồi! Nếu bạn cần gợi ý chăm sóc da, chọn serum, kcn hay xây routine — cứ hỏi mình nha.",
    "SkinSyntaxVN tập trung vào làn da thôi bạn ơi, còn câu này thì mình chưa đủ data để trả lời. Quay sang chuyện da đi — mình sẵn sàng!",
    "Mình không biết câu này lắm, nhưng biết khá nhiều về retinol, BHA và kem chống nắng đấy. Bạn cần tư vấn gì về da không?",
)


def _off_topic_category(message: str) -> str:
    msg = message.strip().lower()
    if any(k in msg for k in ("người yêu", "nguoi yeu", "bạn trai", "ban trai", "bạn gái", "ban gai",
                               "crush", "tình yêu", "tinh yeu", "kết hôn", "ket hon", " yêu ", "yeu a")):
        return "personal"
    if any(k in msg for k in ("iphone", "điện thoại", "dien thoai", "laptop", "macbook", "ipad",
                               "xe máy", "xe may", "ô tô", "o to", "quần áo", "quan ao")):
        return "tech"
    if any(k in msg for k in ("giá vàng", "gia vang", "bitcoin", "crypto", "cổ phiếu", "co phieu", "chứng khoán")):
        return "finance"
    if any(k in msg for k in ("bóng đá", "bong da", "thời tiết", "thoi tiet", "world cup")):
        return "sports"
    return "general"


_OFF_TOPIC_POOLS: dict[str, tuple[str, ...]] = {
    "personal": _OFF_TOPIC_PERSONAL,
    "tech":     _OFF_TOPIC_TECH,
    "finance":  _OFF_TOPIC_FINANCE,
    "sports":   _OFF_TOPIC_SPORTS,
    "general":  _OFF_TOPIC_GENERAL,
}


def _off_topic_reply(message: str, msg_data: dict | None = None) -> str:
    cat = _off_topic_category(message)
    pool = _OFF_TOPIC_POOLS[cat]
    return _pick_variant(list(pool), message, msg_data, f"off_topic:{cat}")


_SKINCARE_SHOP_SIGNALS = (
    "sữa rửa", "sua rua", "srm", "rửa mặt", "tẩy trang", "tay trang",
    "toner", "nước hoa hồng", "nuoc hoa hong", "serum", "tinh chất", "tinh chat",
    "kem dưỡng", "kem duong", "chống nắng", "chong nang", "kcn", "sunscreen",
    "routine", "chu trình", "chu trinh", "mỹ phẩm", "my pham", "skincare",
    "chăm da", "cham da", "dưỡng da", "duong da", "thành phần", "thanh phan",
    "hoạt chất", "hoat chat", "sản phẩm", "san pham", "gợi ý", "goi y", "tư vấn", "tu van",
    "da dầu", "da dau", "da khô", "da kho", "da mụn", "da mun", "da nhạy cảm",
    "mụn", "mun", "thâm", "tham", "retinol", "niacinamide", "bha", "aha",
    "vitamin c", "hyaluronic", "ceramide", "peptide", "so sánh", "so sanh",
    "ship", "giao hàng", "giao hang", "thanh toán", "thanh toan", "đổi trả", "doi tra",
    "freeship", "voucher", "đơn hàng", "don hang", "skinsyntax",
    "xuất xứ", "xuat xu", "an toàn", "an toan", "kiếm", "kiem", "tìm sp", "tim sp",
    "sp khác", "san pham khac", "sản phẩm khác", "cái khác", "cai khac",
    "mẫn đỏ", "man do", "mẩn đỏ", "man do", "ngứa", "ngua", "sạm", "sam", "nám", "nam",
    "đỏ da", "do da", "kích ứng", "kich ung", "dị ứng", "di ung", "viêm da", "viem da",
    "lão hóa", "lao hoa", "nhăn", "nhan", "xỉn màu", "xin mau", "tối màu", "toi mau",
    "lỗ chân lông", "lo chan long", "bong tróc", "bong troc",
    "kem ", " kem", "dưới ", "duoi ", "ngân sách", "ngan sach",
    "triệu", "trieu", " giá ", " gia ", "200k", "300k", "500k",
)

_OFF_TOPIC_SIGNALS = (
    "người yêu", "nguoi yeu", "bạn trai", "ban trai", "bạn gái", "ban gai",
    "crush", "tình yêu", "tinh yeu", "kết hôn", "ket hon", "yêu ", " yeu ",
    "giá vàng", "gia vang", "bóng đá", "bong da", "thời tiết", "thoi tiet",
    "bitcoin", "crypto", "cổ phiếu", "co phieu",
)

_FOLLOWUP_SIGNALS = (
    "cái đó", "cai do", "cái này", "cai nay", "cái kia", "cai kia",
    "em này", "em nay", "san pham nay", "sản phẩm này",
    "dùng sao", "dung sao", "giá bao nhiêu", "gia bao nhieu", "còn hàng", "con hang",
    "mua được không", "mua duoc khong",
    "trong số", "trong so", "trong đó", "trong do", "trong list", "trong danh sách",
    "ở trên", "o tren", "vừa rồi", "vua roi", "vừa nêu", "vua neu", "vừa gợi ý", "vua goi y",
    "chọn 1", "chon 1", "chọn một", "chon mot", "chọn giúp", "chon giup", "chọn giùm",
    "1 loại", "mot loai", "một loại", "một thôi", "mot thoi", "1 thôi",
    "loại nào", "loai nao", "cái nào", "cai nao", "sp nào", "nên lấy", "nen lay",
    "nên chọn", "nen chon", "nên mua", "nen mua", "tốt hơn", "tot hon", "rẻ hơn", "re hon",
    "cái khác", "cai khac", "sp khác", "san pham khac", "sản phẩm khác",
    "kiếm cái", "kiem cai", "tìm sp", "tim sp", "tìm cái", "tim cai", "gợi ý khác", "goi y khac",
    "đổi sang", "doi sang", "thay cái", "thay cai", "khác đi", "khac di",
    "xuất xứ", "xuat xu", "nó của", "no cua", "quốc gia", "quoc gia",
)

_BOT_ASKED_PROFILE_SIGNALS = (
    "loại da", "loai da", "tình trạng", "tinh trang", "vấn đề da", "van de da",
    "triệu chứng", "trieu chung", "mô tả thêm", "mo ta them",
    "cho mình biết", "cho minh biet", "bạn gặp", "ban gap", "bạn đang", "ban dang",
    "cần thêm thông tin", "can them thong tin", "routine phù hợp",
)

_PROFILE_ANSWER_PREFIXES = (
    "dạ", "da ", "vâng", "vang", "tôi ", "toi ", "mình ", "minh ", "em ", "con ",
)


def _is_profile_answer_followup(message: str, history_str: str) -> bool:
    if not history_str.strip():
        return False
    last_bot = ""
    for line in reversed(history_str.split("\n")):
        if line.startswith("SkinSyntax AI:"):
            last_bot = line.lower()
            break
    if not last_bot or not any(s in last_bot for s in _BOT_ASKED_PROFILE_SIGNALS):
        return False
    msg = message.strip().lower()
    if any(msg.startswith(p) for p in _PROFILE_ANSWER_PREFIXES):
        return True
    if any(s in msg for s in _SKINCARE_SHOP_SIGNALS):
        return True
    if _rule_based_parse(message):
        return True
    return False


def _is_alternative_product_request(message: str) -> bool:
    msg = message.strip().lower()
    alt_signals = (
        "cái khác", "cai khac", "sp khác", "san pham khac", "sản phẩm khác",
        "loại khác", "loai khac", "thứ khác", "thu khac", "mẫu khác", "mau khac",
        "kiếm cái", "kiem cai", "tìm sp", "tim sp", "tìm cái", "tim cai",
        "tìm loại", "tim loai", "tìm thứ", "tim thu",
        "gợi ý khác", "goi y khac", "gợi ý loại khác", "goi y loai khac",
        "đổi sang", "doi sang", "thay cái", "thay cai", "thay loại", "thay loai",
        "khác đi", "khac di", "loại nào khác", "loai nao khac",
        "còn loại nào", "con loai nao", "còn gì khác", "con gi khac",
        "không lấy", "khong lay", "bỏ cái này", "bo cai nay",
        "không mua cái", "khong mua cai", "không thích cái", "khong thich cai",
        "chưa an tâm", "chua an tam", "chưa ưng", "chua ung",
    )
    return any(s in msg for s in alt_signals)


def _is_contextual_followup(message: str, history_str: str) -> bool:
    if not history_str.strip():
        return False
    if _is_profile_answer_followup(message, history_str):
        return True
    msg = message.strip().lower()
    if any(s in msg for s in _FOLLOWUP_SIGNALS):
        return True
    if len(msg) <= 120:
        hints = (
            "chọn", "chon", "lấy", "lay", "gỡ", "go ", "bỏ", "bo ",
            "rẻ", "re ", "đắt", "dat ", "nào", "nao", "thôi", "thoi",
            "thay", "đổi", "doi ", "còn", "con ", "lại", "lai ",
            "khác", "khac", "kiếm", "kiem", "tìm", "tim ",
        )
        if any(h in msg for h in hints):
            return True
    return False


def _is_skincare_or_shop_query(message: str, history_str: str = "") -> bool:
    msg = message.strip().lower()
    if not msg:
        return False
    if _is_pure_chitchat(message):
        return True
    if _is_contextual_followup(message, history_str):
        return True
    if any(s in msg for s in _OFF_TOPIC_SIGNALS):
        return False
    if any(s in msg for s in _SKINCARE_SHOP_SIGNALS):
        return True
    if _rule_based_parse(message) or _is_comparison_query(message):
        return True
    return False


def _doc_id(doc) -> str:
    if hasattr(doc, "id") and doc.id:
        return str(doc.id).replace("product_", "").strip()
    return str(doc.metadata.get("id", "") or "").strip()


def _exclude_docs_by_ids(docs: list, exclude_ids: set[str]) -> list:
    if not exclude_ids:
        return docs
    out = []
    for d in docs:
        pid = _doc_id(d)
        if pid and pid in exclude_ids:
            continue
        out.append(d)
    return out


def _followup_filter_docs(message: str, docs: list) -> list:
    if not docs:
        return docs
    msg = message.strip().lower()
    if any(x in msg for x in ("rẻ nhất", "re nhat", "rẻ hơn", "re hon", "giá thấp", "gia thap", "rẻ ", " re ")):
        return sorted(docs, key=_doc_price)[:1]
    if any(x in msg for x in ("đắt nhất", "dat nhat", "đắt hơn", "dat hon", "giá cao", "gia cao")):
        return sorted(docs, key=_doc_price, reverse=True)[:1]
    if any(x in msg for x in ("chọn 1", "chon 1", "1 loại", "1 loai", "một loại", "mot loai", "chọn một", "chon mot")):
        return docs[:1]
    return docs


def _cached_products_to_docs(products: list[dict]) -> list:
    docs = []
    for p in products:
        pid = str(p.get("id", "") or "")
        name = str(p.get("name", "") or "")
        if not name:
            continue
        docs.append(MockDocument(
            page_content=name,
            metadata={
                "id":           pid,
                "ten_san_pham": name,
                "thuong_hieu":  p.get("brand", ""),
                "gia_ban":      p.get("price", 0),
                "thanh_phan_chinh": p.get("summary", ""),
            },
            id=f"product_{pid}" if pid else None,
        ))
    return docs


def _canned_reply_ctx(
    message: str,
    msg_data: dict | None = None,
    *,
    off_topic: bool = False,
) -> dict:
    label = "OFF_TOPIC" if off_topic else "CHITCHAT"
    if off_topic:
        reply = _off_topic_reply(message, msg_data)
        suggestions = list(OFF_TOPIC_SUGGESTIONS)
    else:
        reply = _default_chitchat_reply(message)
        suggestions = []
    return {
        "is_chitchat":      not off_topic,
        "is_off_topic":     off_topic,
        "skip_llm":         True,
        "canned_reply":     reply,
        "suggestions":      suggestions,
        "message":          message,
        "msg_data":         msg_data,
        "chat_history_str": "",
        "llms":             [],
        "intent":           label,
        "active_prompt":    general_prompt,
        "prompt_vars":      {},
        "final_docs":       [],
        "cart_conflicts":   [],
        "analysis":         {"intent": label},
    }



_COMPARISON_PATTERNS = [
    r"so\s*sánh.{1,40}(với|và|vs)",
    r"(và|vs|hay).{1,40}cái nào (tốt|phù hợp|nên dùng|tốt hơn|tốt hơn không)",
    r"(chọn|dùng|mua).{1,50}(hay|hoặc).{1,50}(nào|tốt hơn|được không)",
    r"khác nhau (thế nào|như thế nào|gì|không)",
    r"(nên|thì) (chọn|mua|dùng).{1,40}hay",
    r"(tốt hơn|kém hơn|khác gì|hơn nhau)",
]


def _is_comparison_query(message: str) -> bool:
    q = message.lower()
    return any(re.search(p, q) for p in _COMPARISON_PATTERNS)


_ORDER_KEYWORDS = (
    "dat hang", "đặt hàng", "dat don", "đặt đơn",
    "mua ngay", "them vao gio", "thêm vào giỏ", "cho toi", "cho mình",
    "lay giup", "lấy giúp", "order", "checkout", "thanh toan", "thanh toán",
)


def _is_order_query(message: str) -> bool:
    q = message.lower()
    if any(k in q for k in _ORDER_KEYWORDS):
        return True
    return bool(re.search(r"\b(\d+)\s*(chai|tuyp|hop|hộp|cái|cai|sp)\b", q))


def _normalize_product_label(name: str) -> str:
    return re.sub(r"\s+", " ", (name or "").strip().lower())


def _match_product_by_fragment(fragment: str, products: list) -> dict | None:
    frag = _normalize_product_label(fragment)
    if len(frag) < 2:
        return None
    best: dict | None = None
    best_score = 0
    for p in products:
        if not isinstance(p, dict):
            continue
        pname = _normalize_product_label(str(p.get("name") or p.get("ten_san_pham") or ""))
        if not pname:
            continue
        if frag in pname or pname in frag:
            score = len(frag)
            if score > best_score:
                best_score = score
                best = p
    return best


def _extract_cart_actions(message: str, products: list) -> list:
    if not products:
        return []

    q = message.lower()
    actions: list[dict] = []
    seen: set[str] = set()

    def _push(product: dict, qty: int) -> None:
        pid = str(product.get("id") or product.get("ma_san_pham") or "").strip()
        if not pid or pid in seen:
            return
        seen.add(pid)
        actions.append({
            "product_id": pid,
            "qty": max(1, min(99, int(qty))),
            "name": str(product.get("name") or product.get("ten_san_pham") or ""),
        })

    bulk_keys = (
        "tat ca", "tất cả", "san pham tren", "sản phẩm trên",
        "cac sp", "các sp", "nhung sp", "những sp", "goi y tren", "gợi ý trên",
    )
    if any(k in q for k in bulk_keys):
        default_qty = 1
        m = re.search(r"(\d+)\s*(san pham|sản phẩm|sp|mat hang|mặt hàng)", q)
        if m:
            default_qty = max(1, min(99, int(m.group(1))))
        for p in products[:8]:
            _push(p, default_qty)
        return actions

    patterns = [
        r"(\d+)\s*(?:chai|tuyp|hop|hộp|cái|cai|sp|san pham|sản phẩm)?\s*([^,\n\+;]+?)(?:\s+và|\s+va|\s*,|\s*\+|\s*$)",
        r"(\d+)\s*x\s*([^,\n\+;]+?)(?:\s+và|\s+va|\s*,|\s*\+|\s*$)",
    ]
    for pattern in patterns:
        for m in re.finditer(pattern, q):
            qty = max(1, min(99, int(m.group(1))))
            fragment = (m.group(2) or "").strip()
            if len(fragment) < 2:
                continue
            matched = _match_product_by_fragment(fragment, products)
            if matched:
                _push(matched, qty)

    if not actions and _is_order_query(message):
        for p in products[:4]:
            _push(p, 1)

    return actions


def _resolve_order_context(message: str, msg_data: dict | None, final_docs: list) -> tuple[list, str | None]:
    products = docs_to_products(final_docs) or []
    extra: list = []
    if isinstance(msg_data, dict):
        for key in ("retrieved_products", "products"):
            raw = msg_data.get(key)
            if isinstance(raw, list):
                extra.extend(raw)
        for item in msg_data.get("cart_items") or []:
            if isinstance(item, dict) and item.get("id"):
                extra.append(item)

    merged: list[dict] = []
    seen_ids: set[str] = set()
    for p in products + extra:
        if not isinstance(p, dict):
            continue
        pid = str(p.get("id") or p.get("ma_san_pham") or "").strip()
        if not pid or pid in seen_ids:
            continue
        seen_ids.add(pid)
        merged.append({
            "id": pid,
            "name": p.get("name") or p.get("ten_san_pham") or "",
        })

    cart_actions = _extract_cart_actions(message, merged) if _is_order_query(message) else []
    intent = "ORDER" if cart_actions else None
    return cart_actions, intent



def _rule_based_parse(message: str) -> Optional[PhanTichYeuCau]:
    msg = message.lower()

    loai_da = None
    if any(k in msg for k in ["da dầu", "da dau", "hỗn hợp dầu", "nhờn", "nhon", "siêu dầu"]):
        loai_da = "Da dầu/Hỗn hợp dầu"
    elif any(k in msg for k in ["nhạy cảm", "nhay cam", "dễ kích ứng", "kích ứng", "kich ung", "mỏng yếu"]):
        loai_da = "Da nhạy cảm"
    elif any(k in msg for k in ["da khô", "da kho", "hỗn hợp khô", "bong tróc", "nứt nẻ"]):
        loai_da = "Da khô/Hỗn hợp khô"
    elif any(k in msg for k in ["da mụn", "da mun", "mụn bọc", "mụn ẩn", "mụn viêm"]):
        loai_da = "Da mụn"
    elif any(k in msg for k in ["da thường", "da thuong", "mọi loại da"]):
        loai_da = "Da thường/Mọi loại da"

    loai_sp = None
    for kws, cat in [
        (["sữa rửa mặt", "sua rua mat", "srm", "rửa mặt"],           "Sữa Rửa Mặt"),
        (["tẩy trang", "tay trang", "nước tẩy trang", "dầu tẩy trang"], "Tẩy Trang Mặt"),
        (["toner", "nước cân bằng", "nước hoa hồng"],                  "Toner / Nước Cân Bằng Da"),
        (["serum", "tinh chất", "tinh chat", "ampoule", "essence"],    "Serum / Tinh Chất"),
        (["kem dưỡng", "kem duong", "gel dưỡng"],                      "Kem / Gel / Dầu Dưỡng"),
        (["lotion", "sữa dưỡng", "sua duong"],                         "Lotion / Sữa Dưỡng"),
        (["chống nắng", "chong nang", "kcn", "sunscreen", "sunblock"], "Chống Nắng Da Mặt"),
        (["tẩy tế bào chết", "tay te bao chet", "peel"],               "Tẩy Tế Bào Chết Da Mặt"),
        (["mặt nạ ngủ", "mat na ngu"],                                  "Mặt Nạ Ngủ"),
        (["mặt nạ", "mat na"],                                          "Mặt Nạ Giấy"),
        (["trị mụn", "tri mun", "chấm mụn", "kem mụn"],               "Hỗ Trợ Trị Mụn"),
        (["xịt khoáng", "xit khoang"],                                  "Xịt Khoáng"),
        (["dưỡng thể", "duong the", "body lotion", "kem body"],        "Dưỡng Thể"),
        (["sữa tắm", "sua tam"],                                        "Sữa Tắm"),
        (["dầu gội", "dau goi"],                                        "Dầu Gội"),
        (["dầu xả", "dau xa"],                                          "Dầu Xả"),
        (["son dưỡng", "son duong"],                                    "Son Dưỡng Môi"),
    ]:
        if any(k in msg for k in kws):
            loai_sp = cat
            break

    is_routine = any(k in msg for k in [
        "routine", "chu trình", "chu trinh", "các bước", "combo",
        "trọn bộ", "sáng tối", "sang toi",
    ])

    tinh_trang: list[str] = []
    for kws, cond in [
        (["mụn", "mun"],            "mụn"),
        (["thâm", "tham"],          "thâm"),
        (["nhăn", "nhan", "lão hóa", "lao hoa"], "nhăn"),
        (["kích ứng", "kich ung", "mẩn đỏ", "mẫn đỏ", "man do"], "đỏ kích ứng"),
        (["ngứa", "ngua"],          "đỏ kích ứng"),
        (["bong tróc", "bong troc", "khô ráp", "kho rap"], "bong tróc"),
        (["lỗ chân lông", "lo chan long", "lcl"], "lỗ chân lông to"),
        (["sạm", "sam", "xỉn màu", "tối màu", "toi mau", "nám", "nam"], "sạm màu"),
    ]:
        if any(k in msg for k in kws):
            tinh_trang.append(cond)

    if not loai_da and any(k in msg for k in ["mẫn đỏ", "mẩn đỏ", "man do", "ngứa", "ngua", "kích ứng", "kich ung", "dị ứng", "di ung"]):
        loai_da = "Da nhạy cảm"

    if not loai_sp and re.search(r"\bkem\b", msg):
        loai_sp = "Kem / Gel / Dầu Dưỡng"

    budget = _extract_budget_vnd(message)

    so_luong = 3
    nums = re.findall(r"(?<!\d)([1-5])(?!\d)", msg)
    if nums:
        so_luong = int(nums[0])

    if loai_da or loai_sp or is_routine or tinh_trang or budget:
        yc = PhanTichYeuCau(
            loai_da=loai_da,
            loai_san_pham=loai_sp,
            tinh_trang_da=tinh_trang or None,
            so_luong_goi_y=so_luong,
            tu_khoa_ngu_nghia=message,
            is_routine=is_routine,
        )
        if budget:
            yc.gia_cu_the = f"dưới {budget:,} VNĐ"
        return yc
    return None


def _history_raw_to_str(history_raw) -> str:
    if not history_raw:
        return ""
    if isinstance(history_raw, str):
        return history_raw.strip()
    if not isinstance(history_raw, list):
        return ""
    lines: list[str] = []
    for h in history_raw[-10:]:
        if not isinstance(h, dict):
            lines.append(str(h))
            continue
        if "role" in h:
            role = str(h.get("role", "user")).lower()
            sender = "Khách hàng" if role == "user" else "SkinSyntax AI"
            text = str(h.get("content") or h.get("text") or "").strip()
        else:
            sender = "Khách hàng" if h.get("sender") == "user" else "SkinSyntax AI"
            text = str(h.get("text") or "").strip()
        if text:
            lines.append(f"{sender}: {text}")
    return "\n".join(lines)


def _session_state_to_context(state: dict) -> list[str]:
    parts: list[str] = []
    if state.get("loai_da"):
        parts.append(f"- Loại da (đã biết từ hội thoại): {state['loai_da']}")
    if state.get("budget_vnd"):
        parts.append(f"- Ngân sách (đã nêu): {int(state['budget_vnd']):,} VNĐ")
    if state.get("is_routine"):
        parts.append("- Khách đang xây/tư vấn routine nhiều bước")
    if state.get("last_intent"):
        parts.append(f"- Chủ đề lượt trước: {state['last_intent']}")
    if state.get("last_rewritten"):
        parts.append(f"- Câu hỏi đã làm rõ gần nhất: {state['last_rewritten']}")
    return parts



def _extract_json(text: str) -> dict | None:
    text = text.strip()
    m = re.search(r"```(?:json)?\s*(\{.*?\})\s*```", text, re.DOTALL)
    text = m.group(1) if m else text
    m2 = re.search(r"\{.*\}", text, re.DOTALL)
    text = m2.group(0) if m2 else text
    try:
        return json.loads(text)
    except Exception:
        return None


def _analyze_and_parse(
    message: str,
    history_str: str,
    llm,
) -> tuple[str, str, str | None, bool, PhanTichYeuCau]:
    # Path A: fast-path (rule-based — skip LLM when câu hỏi đủ rõ)
    yc_fast = _rule_based_parse(message)
    if yc_fast and (not history_str or not _is_contextual_followup(message, history_str)):
        logger.debug(f"[ANALYZE]  Fast-path: da={yc_fast.loai_da} | sp={yc_fast.loai_san_pham}")
        return message, "PRODUCT_INQUIRY", None, False, yc_fast

    if not history_str and _is_comparison_query(message):
        logger.debug("[ANALYZE]  Comparison fast-path")
        return message, "PRODUCT_COMPARISON", None, False, PhanTichYeuCau(
            tu_khoa_ngu_nghia=message, so_luong_goi_y=6
        )

    # Path B: combined LLM call
    if llm:
        history_section = f"Lịch sử trò chuyện gần nhất:\n{history_str}\n\n" if history_str else ""
        json_chain = build_json_chain(analyze_and_parse_prompt, [llm])

        if json_chain:
            try:
                data = json_chain.invoke({"history_section": history_section, "message": message})
                if isinstance(data, dict):
                    rewritten   = (data.get("rewritten_query") or message).strip().strip('"').strip("'")
                    intent      = data.get("intent", "PRODUCT_INQUIRY")
                    ingredient  = data.get("ingredient")
                    is_chitchat = bool(data.get("is_chitchat", False))

                    valid_intents = {
                        "PRODUCT_INQUIRY", "PRODUCT_COMPARISON",
                        "COSMETIC_KNOWLEDGE_OUT_OF_DB",
                        "CHITCHAT", "GENERAL_CONVERSATION",
                    }
                    if intent not in valid_intents:
                        intent = "PRODUCT_INQUIRY"
                    if not ingredient or str(ingredient).lower() in ("null", "none", ""):
                        ingredient = None

                    if rewritten != message:
                        logger.debug(f"[ANALYZE] Rewrite: '{message[:55]}' → '{rewritten[:55]}'")
                    logger.debug(f"[ANALYZE] intent={intent} | ingredient={ingredient}")

                    if is_chitchat or intent == "CHITCHAT":
                        return rewritten, "CHITCHAT", ingredient, True, PhanTichYeuCau(tu_khoa_ngu_nghia=rewritten)

                    if intent == "PRODUCT_COMPARISON":
                        return rewritten, "PRODUCT_COMPARISON", ingredient, False, PhanTichYeuCau(
                            tu_khoa_ngu_nghia=data.get("tu_khoa_ngu_nghia") or rewritten,
                            so_luong_goi_y=6,
                        )

                    yc = dict_to_yc({
                        "loai_da":              data.get("loai_da"),
                        "loai_san_pham":        data.get("loai_san_pham"),
                        "muc_gia":              data.get("muc_gia"),
                        "tinh_trang_da":        data.get("tinh_trang_da"),
                        "thanh_phan_yeu_cau":   data.get("thanh_phan_yeu_cau"),
                        "thanh_phan_can_tranh": data.get("thanh_phan_can_tranh"),
                        "thuong_hieu":          data.get("thuong_hieu"),
                        "buoi_dung":            data.get("buoi_dung"),
                        "so_luong_goi_y":       data.get("so_luong_goi_y", 3),
                        "tu_khoa_ngu_nghia":    data.get("tu_khoa_ngu_nghia") or rewritten,
                        "is_routine":           bool(data.get("is_routine", False)),
                    })
                    logger.debug(f"[PARSE]   da={yc.loai_da} | sp={yc.loai_san_pham} | routine={yc.is_routine}")
                    return rewritten, intent, ingredient, False, yc

            except Exception as e:
                logger.warning(f"[ANALYZE] Combined LLM failed: {e}. Rule-based fallback.")

    # Path C: fallback
    yc_fb = _rule_based_parse(message)
    if yc_fb:
        return message, "PRODUCT_INQUIRY", None, False, yc_fb
    if not _is_skincare_or_shop_query(message, history_str):
        return message, "CHITCHAT", None, True, PhanTichYeuCau(tu_khoa_ngu_nghia=message)
    return message, "PRODUCT_INQUIRY", None, False, PhanTichYeuCau(tu_khoa_ngu_nghia=message)



def _build_product_reply(
    message: str,
    docs: list,
    budget: int | None,
    yc: PhanTichYeuCau,
) -> str:
    lines: list[str] = []
    for doc in docs[:3]:
        name = str(doc.metadata.get("ten_san_pham", "") or "").strip()
        if not name:
            continue
        price = int(_doc_price(doc))
        line = f"• {name}"
        if price > 0:
            line += f" — {price:,}đ"
        lines.append(line)

    if not lines:
        if budget:
            return (
                f"Mình chưa thấy sản phẩm phù hợp trong ngân sách dưới {budget:,}đ. "
                "Bạn thử nêu thêm loại da hoặc tăng ngân sách một chút nhé."
            )
        return "Mình chưa tìm thấy sản phẩm phù hợp. Bạn mô tả thêm loại da hoặc nhu cầu nhé."

    if budget:
        intro = f"Với ngân sách dưới {budget:,}đ, mình gợi ý:"
    elif yc.loai_san_pham:
        intro = f"Về {yc.loai_san_pham.lower()}, mình gợi ý:"
    else:
        intro = "Dựa trên câu hỏi của bạn, mình gợi ý:"

    return intro + "\n" + "\n".join(lines) + "\n\nBạn xem chi tiết và thêm giỏ bên dưới nhé."


def _prepare_pipeline(message: str, msg_data: dict | None) -> dict:
    skin_type          : str | None = None
    avoid_ingredients  : list       = []
    skin_issues        : list       = []
    cart_items         : list       = []
    cart_conflicts     : list       = []
    retrieved_sql      : list       = []
    chat_history_str   : str        = ""
    current_product_id : str | None = None

    if msg_data:
        profile           = msg_data.get("customer_profile", {}) or {}
        skin_type         = profile.get("skin_type") or profile.get("loai_da")
        avoid_ingredients = profile.get("avoid_ingredients") or profile.get("thanh_phan_can_tranh") or []
        skin_issues       = profile.get("skin_issues")        or profile.get("tinh_trang_da")       or []
        cart_items        = msg_data.get("cart_items",        []) or []
        cart_conflicts    = msg_data.get("cart_conflicts",    []) or []
        retrieved_sql     = msg_data.get("retrieved_products",[]) or []
        current_product_id = msg_data.get("current_product_id")

        history_raw = msg_data.get("conversation_history", "")
        chat_history_str = _history_raw_to_str(history_raw)

    if not chat_history_str:
        cached = get_history(msg_data)
        if cached:
            chat_history_str = _history_raw_to_str(cached)
            logger.debug(f"[SESSION] Loaded {len(cached)//2} turns from server cache")
    elif msg_data:
        logger.debug("[SESSION] Using conversation_history from client payload")

    if _is_pure_chitchat(message) and not _is_contextual_followup(message, chat_history_str):
        logger.debug(f"[ROUTER] Chitchat fast-exit: '{message[:50]}'")
        ctx = _canned_reply_ctx(message, msg_data)
        ctx["chat_history_str"] = chat_history_str
        return ctx

    if not _is_skincare_or_shop_query(message, chat_history_str):
        logger.debug(f"[ROUTER] Off-topic blocked: '{message[:50]}'")
        ctx = _canned_reply_ctx(message, msg_data, off_topic=True)
        ctx["chat_history_str"] = chat_history_str
        return ctx

    llms           = get_llms()
    pipeline_obj   = get_hybrid_pipeline()
    classifier_llm = get_classifier_llm()

    ctx_parts: list[str] = []
    ctx_parts.extend(_session_state_to_context(get_session_state(msg_data)))
    if skin_type:
        ctx_parts.append(f"- Loại da: {skin_type}")
    if skin_issues:
        ctx_parts.append(f"- Tình trạng da: {', '.join(skin_issues)}")
    if avoid_ingredients:
        ctx_parts.append(f"- Thành phần cần tránh: {', '.join(avoid_ingredients)}")
    if current_product_id and retrieved_sql:
        p = retrieved_sql[0] if isinstance(retrieved_sql[0], dict) else {}
        p_name = p.get("name") or p.get("ten_san_pham", "")
        if p_name:
            ctx_parts.append(
                f"- KHÁCH ĐANG XEM: {p_name} (ID: {current_product_id}).\n"
                f"  Khi khách hỏi 'sản phẩm này', 'em này' → hiểu là {p_name}."
            )
    if cart_items:
        names = [i.get("name", str(i)) if isinstance(i, dict) else str(i) for i in cart_items]
        ctx_parts.append(f"- Giỏ hàng: {', '.join(names)}")
    if cart_conflicts:
        ctx_parts.append(f"- XUNG ĐỘT HOẠT CHẤT: {', '.join(cart_conflicts)}")

    if _is_contextual_followup(message, chat_history_str):
        prior = get_last_products(msg_data)
        if prior:
            if _is_alternative_product_request(message):
                names = ", ".join(str(p.get("name", "")) for p in prior[:3] if p.get("name"))
                ctx_parts.append(
                    f"- KHÁCH MUỐN SẢN PHẨM KHÁC (không dùng lại: {names}). "
                    "Gợi ý sản phẩm cùng loại nhưng khác mẫu; ưu tiên có thông tin xuất xứ nếu khách hỏi."
                )
            else:
                names = ", ".join(
                    str(p.get("name", "")) for p in prior[:6] if p.get("name")
                )
                ctx_parts.append(
                    f"- HỘI THOẠI TIẾP THEO: khách đang nói về sản phẩm vừa gợi ý ({names}). "
                    "Trả lời dựa trên đúng danh sách đó; nếu khách muốn chọn/lọc/so sánh thì "
                    "chỉ chọn trong list này."
                )

    # Extract budget constraint from original message
    budget = _extract_budget_vnd(message)
    if budget:
        ctx_parts.append(
            f"- NGÂN SÁCH TỐI ĐA: {budget:,} VNĐ — bắt buộc chọn sản phẩm sao cho TỔNG giá ≤ {budget:,} VNĐ."
        )

    rich_context = "\n".join(ctx_parts) or "Không có thông tin hồ sơ bổ sung."

    rewritten, intent, ingredient, is_chitchat, yc = _analyze_and_parse(
        message, chat_history_str, classifier_llm
    )

    if is_chitchat:
        logger.debug(f"[ROUTER] Chitchat (LLM): '{message[:50]}'")
        ctx = _canned_reply_ctx(message, msg_data)
        ctx["chat_history_str"] = chat_history_str
        return ctx

    # Chỉ ưu tiên hồ sơ đã lưu khi pipeline chưa trích được loại da từ câu hỏi.
    if skin_type and skin_type not in ("Unknown", None):
        if not yc.loai_da or yc.loai_da == "Unknown":
            yc.loai_da = skin_type

    logger.debug(f"[PIPELINE] '{rewritten[:60]}' | {intent}")
    logger.debug(f"[PARSE]    da={yc.loai_da} | sp={yc.loai_san_pham} | routine={yc.is_routine}")

    k    = min(max(int(yc.so_luong_goi_y or 3), 3), 10)
    docs : list = []
    web_results_text = ""

    def _hybrid_search(filter_dict, top_n: int, query: str | None = None, *, use_reranker: bool = True) -> list[MockDocument]:
        ranked, _ = pipeline_obj.search(
            query=query or rewritten,
            k_total=max(top_n * 2, 6),
            top_n=top_n,
            filters=filter_dict,
            use_reranker=use_reranker,
        )
        return [
            MockDocument(page_content=rd.content, metadata=rd.metadata, id=rd.doc_id)
            for rd in ranked
        ]

    is_followup = _is_contextual_followup(message, chat_history_str)
    is_alt = _is_alternative_product_request(message)

    prior_products = (
        get_last_products(msg_data)
        if is_followup and not is_alt
        else []
    )

    if prior_products:
        docs = _followup_filter_docs(message, _cached_products_to_docs(prior_products))
        logger.debug(f"[SESSION] Follow-up: reuse {len(docs)} products from prior turn")

    elif is_alt and chat_history_str.strip():
        prior = get_last_products(msg_data)
        exclude_ids = {str(p.get("id", "")) for p in prior if p.get("id")}

        sess = get_session_state(msg_data)
        effective_loai_sp = yc.loai_san_pham or sess.get("last_loai_san_pham")
        effective_loai_da = (
            yc.loai_da if yc.loai_da and yc.loai_da != "Unknown" else
            skin_type or sess.get("loai_da")
        )

        search_q = rewritten if rewritten != message else (
            f"sản phẩm cho da {effective_loai_da or 'nhạy cảm'} {effective_loai_sp or ''}"
        ).strip()
        msg_l = message.lower()
        if any(x in msg_l for x in ("xuất xứ", "xuat xu", "quốc gia", "quoc gia", "origin")):
            search_q = f"{search_q} xuất xứ rõ ràng"

        conds = []
        if effective_loai_da and effective_loai_da != "Unknown":
            conds.append({"loai_da": {"$eq": effective_loai_da}})
        if effective_loai_sp:
            conds.append({"loai_san_pham": {"$eq": effective_loai_sp}})
        if len(conds) == 2:
            bo_loc = {"$and": conds}
        elif conds:
            bo_loc = conds[0]
        else:
            bo_loc = None

        fetch_n = k + len(exclude_ids) + 4
        if bo_loc:
            docs = _hybrid_search(bo_loc, top_n=fetch_n, query=search_q)
        else:
            docs = _hybrid_search(None, top_n=fetch_n, query=search_q)

        docs = _exclude_docs_by_ids(docs, exclude_ids)[:k]
        logger.debug(
            f"[SESSION] Alt search: {len(docs)} docs, excluded IDs={exclude_ids}, "
            f"loai_sp={effective_loai_sp}, loai_da={effective_loai_da}"
        )

    elif intent == "PRODUCT_COMPARISON":
        docs = _hybrid_search(None, top_n=6, query=rewritten)
        logger.debug(f"[SEARCH] Comparison mode: {len(docs)} docs")

    elif intent == "GENERAL_CONVERSATION":
        web_results_text = _format_web_results(_query_web(rewritten))
        docs = []

    elif intent == "COSMETIC_KNOWLEDGE_OUT_OF_DB":
        web_results_text = _format_web_results(_query_web(rewritten))
        search_term = ingredient or rewritten
        docs = _hybrid_search(None, top_n=3, query=search_term, use_reranker=False)
        if not docs:
            docs = _hybrid_search(None, top_n=3, query="sản phẩm nổi bật bán chạy", use_reranker=False)

    else:  # PRODUCT_INQUIRY
        if yc.is_routine:
            routine_cats = [
                "Tẩy Trang Mặt", "Sữa Rửa Mặt", "Toner / Nước Cân Bằng Da",
                "Serum / Tinh Chất", "Kem / Gel / Dầu Dưỡng", "Chống Nắng Da Mặt",
            ]
            if _is_morning_routine(message):
                routine_cats = [c for c in routine_cats if c not in _MORNING_SKIP_CATS]
            per_cat = 12 if budget else 1
            for cat_name in routine_cats:
                conds = []
                if yc.loai_da and yc.loai_da != "Unknown":
                    conds.append({"loai_da": {"$eq": yc.loai_da}})
                conds.append({"loai_san_pham": {"$eq": cat_name}})
                bo_loc = conds[0] if len(conds) == 1 else {"$and": conds}
                step = _hybrid_search(bo_loc, top_n=per_cat)
                if not step:
                    step = _hybrid_search({"loai_san_pham": {"$eq": cat_name}}, top_n=per_cat)
                docs.extend(step)
        else:
            fast_search = k <= 5 and not yc.is_routine
            bo_loc = build_filter(yc)
            if bo_loc:
                docs = _hybrid_search(bo_loc, top_n=k, use_reranker=not fast_search)
                logger.debug(f"[SEARCH] Stage 1 (full filter): {len(docs)}")
            if not docs and yc.loai_san_pham:
                docs = _hybrid_search({"loai_san_pham": {"$eq": yc.loai_san_pham}}, top_n=k, use_reranker=not fast_search)
                logger.debug(f"[SEARCH] Stage 2 (category): {len(docs)}")
            if not docs and yc.loai_da and yc.loai_da != "Unknown":
                docs = _hybrid_search({"loai_da": {"$eq": yc.loai_da}}, top_n=k, use_reranker=not fast_search)
                logger.debug(f"[SEARCH] Stage 3 (skin type): {len(docs)}")
            if not docs:
                docs = _hybrid_search(None, top_n=k, use_reranker=not fast_search)
                logger.debug(f"[SEARCH] Stage 4 (semantic only): {len(docs)}")

            if budget and docs:
                affordable = _affordable_docs(docs, budget)
                if affordable:
                    docs = affordable
                else:
                    fetch_n = max(k * 5, 20)
                    wider = _hybrid_search(build_filter(yc), top_n=fetch_n, use_reranker=False) if build_filter(yc) else []
                    if not wider and yc.loai_san_pham:
                        wider = _hybrid_search(
                            {"loai_san_pham": {"$eq": yc.loai_san_pham}},
                            top_n=fetch_n,
                            use_reranker=False,
                        )
                    if not wider:
                        wider = _hybrid_search(None, top_n=fetch_n, use_reranker=False)
                    docs = _affordable_docs(wider, budget)
                    logger.debug(f"[BUDGET] Wide search: {len(docs)} items ≤ {budget:,} VNĐ")

    seen: set[tuple] = set()
    merged: list[MockDocument] = []

    for item in retrieved_sql:
        if not isinstance(item, dict):
            continue
        p_id   = str(item.get("id") or item.get("product_id") or "")
        p_name = item.get("ten_san_pham") or item.get("name") or "Sản phẩm gợi ý"
        key = (p_id, p_name)
        if key not in seen:
            seen.add(key)
            content = f"{p_name} {item.get('thuong_hieu','')} {item.get('thanh_phan_chinh','')} {item.get('mo_ta','')}"
            merged.append(MockDocument(
                page_content=content,
                id=f"product_{p_id}" if p_id else "",
                metadata={
                    "ten_san_pham":          p_name,
                    "thuong_hieu":           item.get("thuong_hieu") or item.get("brand", ""),
                    "gia_ban":               item.get("gia_ban")     or item.get("price", 0),
                    "loai_da":               item.get("loai_da")     or item.get("skin_type", "Unknown"),
                    "loai_san_pham":         item.get("loai_san_pham") or item.get("category", "Unknown"),
                    "xuat_xu_thuong_hieu":   item.get("xuat_xu_thuong_hieu") or item.get("xuat_xu", "Unknown"),
                    "link_hinh_anh":         item.get("link_hinh_anh") or item.get("image_url", ""),
                    "thanh_phan_chinh":      item.get("thanh_phan_chinh") or item.get("thanh_phan") or item.get("summary", ""),
                    "mo_ta":                 item.get("mo_ta") or item.get("description", ""),
                    "id":                    p_id,
                },
            ))

    for doc in docs:
        p_id   = str(doc.id.replace("product_", "") if doc.id else doc.metadata.get("id", ""))
        p_name = doc.metadata.get("ten_san_pham", "")
        key = (p_id, p_name)
        if key not in seen:
            seen.add(key)
            merged.append(doc)

    current_doc = None
    others: list[MockDocument] = []
    if current_product_id:
        curr_str = str(current_product_id).strip()
        for doc in merged:
            pid = str(doc.id.replace("product_", "") if doc.id else doc.metadata.get("id", "")).strip()
            if pid == curr_str:
                current_doc = doc
            else:
                others.append(doc)
        if not current_doc:
            others = merged
    else:
        others = merged

    if others and yc.is_routine and pipeline_obj and hasattr(pipeline_obj, "reranker") and pipeline_obj.reranker:
        ranked_inputs = [
            RankedDocument(
                doc_id=d.id or f"product_{d.metadata.get('id','')}",
                content=d.page_content, metadata=d.metadata,
            )
            for d in others
        ]
        try:
            reranked = pipeline_obj.reranker.rerank(rewritten, ranked_inputs, top_n=len(ranked_inputs))
            others = [MockDocument(page_content=r.content, metadata=r.metadata, id=r.doc_id) for r in reranked]
        except Exception as e:
            logger.warning(f"[RERANK] Error: {e}")

    final_docs: list[MockDocument] = []
    if current_doc:
        final_docs.append(current_doc)
    final_docs.extend(others)

    if intent == "PRODUCT_COMPARISON":
        final_docs = final_docs[:6]
    elif not yc.is_routine:
        final_docs = final_docs[: int(yc.so_luong_goi_y or 3)]

    if budget:
        final_docs = _fit_docs_to_budget(
            final_docs,
            budget,
            is_routine=yc.is_routine,
            message=message,
        )

    logger.debug(f"[FINAL] {len(final_docs)} docs | intent={intent}")
    for d in final_docs[:4]:
        logger.debug(f"  · {d.metadata.get('ten_san_pham','?')}")

    search_str   = format_search_results(final_docs)
    history_disp = chat_history_str or "Không có lịch sử trò chuyện trước đó."

    if intent == "PRODUCT_COMPARISON":
        active_prompt = comparison_prompt
        prompt_vars = {
            "history":        history_disp,
            "rich_context":   rich_context,
            "search_results": search_str,
            "user_question":  rewritten,
        }
    elif intent == "GENERAL_CONVERSATION":
        active_prompt = general_prompt
        prompt_vars = {
            "history":        history_disp,
            "web_results":    web_results_text or "Không có dữ liệu bổ sung.",
            "search_results": search_str,
            "user_question":  message,
        }
    elif intent == "COSMETIC_KNOWLEDGE_OUT_OF_DB":
        active_prompt = knowledge_prompt
        prompt_vars = {
            "history":        history_disp,
            "web_results":    web_results_text or "Không có dữ liệu bổ sung.",
            "search_results": search_str,
            "user_question":  message,
        }
    else:  # PRODUCT_INQUIRY
        active_prompt = product_prompt
        prompt_vars = {
            "history":        history_disp,
            "rich_context":   rich_context,
            "search_results": search_str,
            "user_question":  rewritten,
        }

    return {
        "is_chitchat":     False,
        "message":         message,
        "msg_data":        msg_data,
        "chat_history_str": chat_history_str,
        "llms":            llms,
        "intent":          intent,
        "active_prompt":   active_prompt,
        "prompt_vars":     prompt_vars,
        "final_docs":      final_docs,
        "cart_conflicts":  cart_conflicts,
        "yc":              yc,
        "analysis": {
            "loai_da":              yc.loai_da,
            "loai_san_pham":        yc.loai_san_pham,
            "muc_gia":              yc.muc_gia,
            "ngan_sach":            budget,
            "is_routine":           yc.is_routine,
            "intent":               intent,
            "tinh_trang_da":        yc.tinh_trang_da,
            "thanh_phan_yeu_cau":   yc.thanh_phan_yeu_cau,
            "thanh_phan_can_tranh": yc.thanh_phan_can_tranh or avoid_ingredients,
        },
        "rewritten": rewritten,
        **(
            {
                "skip_llm": True,
                "canned_reply": _build_product_reply(message, final_docs, budget, yc),
            }
            if intent == "PRODUCT_INQUIRY" and not yc.is_routine
            else {}
        ),
    }



def xu_ly_cau_hoi(message: str, msg_data: dict | None = None) -> dict:
    from response_cache import get_cached_response, store_response, should_cache_request

    if should_cache_request(message, msg_data):
        cached = get_cached_response(message, msg_data)
        if cached:
            cached = dict(cached)
            cached["_cached"] = True
            return cached

    ctx = _prepare_pipeline(message, msg_data)

    if ctx.get("skip_llm"):
        answer = ctx.get("canned_reply") or _default_chitchat_reply(message)
    else:
        str_chain = build_str_chain(ctx["active_prompt"], ctx["llms"])
        answer: str | None = None

        if str_chain:
            try:
                answer = str_chain.invoke(ctx["prompt_vars"])
                answer = (answer or "").strip() or None
            except Exception as e:
                logger.warning(f"[GENERATE] All LLMs failed: {e}")

        if not answer:
            answer = (
                _default_chitchat_reply(message)
                if ctx.get("is_chitchat")
                else (
                    "Tôi đã tìm thấy một số sản phẩm phù hợp. Bạn xem danh sách bên dưới nhé."
                    if ctx["final_docs"] else
                    "Xin lỗi, hệ thống chưa tìm thấy sản phẩm phù hợp. Vui lòng thử từ khóa khác."
                )
            )

    save_turn(
        ctx["msg_data"],
        message,
        answer,
        last_products=docs_to_products(ctx["final_docs"]) or None,
    )
    if not ctx.get("skip_llm") and ctx.get("analysis"):
        a = ctx["analysis"]
        update_session_state(
            ctx["msg_data"],
            loai_da=a.get("loai_da"),
            budget_vnd=a.get("ngan_sach"),
            last_intent=a.get("intent"),
            is_routine=a.get("is_routine"),
            last_rewritten=ctx.get("rewritten"),
            last_loai_san_pham=a.get("loai_san_pham"),
        )
    intent = ctx.get("intent") or (ctx.get("analysis") or {}).get("intent")
    cart_actions, order_intent = _resolve_order_context(message, msg_data, ctx["final_docs"])
    if order_intent:
        intent = order_intent
        if ctx.get("analysis"):
            ctx["analysis"]["intent"] = order_intent
    result = {
        "ok":          True,
        "answer":      answer,
        "products":    docs_to_products(ctx["final_docs"]),
        "conflicts":   ctx["cart_conflicts"],
        "analysis":    ctx["analysis"],
        "intent":      intent,
        "suggestions": ctx.get("suggestions") or [],
        "cart_actions": cart_actions,
    }
    store_response(message, msg_data, result)
    return result



def xu_ly_cau_hoi_stream(
    message: str,
    msg_data: dict | None = None,
) -> Generator[str, None, None]:
    """
    Phiên bản streaming của xu_ly_cau_hoi.
    Yields chuỗi SSE: "data: {json}\\n\\n"

    Event types:
      {"type":"status","message":"..."}  — trạng thái xử lý
      {"type":"token","delta":"..."}     — từng token của câu trả lời
      {"type":"done","products":[...],"analysis":{...},"conflicts":[...]}
      "data: [DONE]"                     — kết thúc stream

    Frontend nhận events này qua EventSource / fetch + ReadableStream.
    """
    def _sse(payload: dict | str) -> str:
        if isinstance(payload, str):
            return f"data: {payload}\n\n"
        return f"data: {json.dumps(payload, ensure_ascii=False)}\n\n"

    from response_cache import get_cached_response, store_response, should_cache_request

    if should_cache_request(message, msg_data):
        cached = get_cached_response(message, msg_data)
        if cached:
            yield _sse({"type": "status", "message": "Đã có câu trả lời trước đó"})
            yield _sse({"type": "token", "delta": str(cached.get("answer") or "")})
            yield _sse({
                "type": "done",
                "products": cached.get("products") or [],
                "analysis": cached.get("analysis") or {},
                "conflicts": cached.get("conflicts") or [],
                "intent": cached.get("intent") or "",
                "suggestions": cached.get("suggestions") or [],
                "cached": True,
            })
            yield "data: [DONE]\n\n"
            return

    yield _sse({"type": "status", "message": "Đang xử lý câu hỏi..."})

    ctx = _prepare_pipeline(message, msg_data)

    if ctx.get("skip_llm"):
        full_answer = ctx.get("canned_reply") or _default_chitchat_reply(message)
        yield _sse({"type": "token", "delta": full_answer})
    else:
        yield _sse({"type": "status", "message": "Đang soạn câu trả lời..."})

        str_chain = build_str_chain(ctx["active_prompt"], ctx["llms"])
        full_answer = ""

        if str_chain:
            try:
                for chunk in str_chain.stream(ctx["prompt_vars"]):
                    if chunk:
                        full_answer += chunk
                        yield _sse({"type": "token", "delta": chunk})
            except Exception as e:
                logger.warning(f"[STREAM] Streaming failed: {e}")
                try:
                    full_answer = str_chain.invoke(ctx["prompt_vars"]) or ""
                    if full_answer:
                        yield _sse({"type": "token", "delta": full_answer})
                except Exception as e2:
                    logger.warning(f"[STREAM] Fallback invoke also failed: {e2}")

        if not full_answer:
            full_answer = (
                _default_chitchat_reply(message)
                if ctx.get("is_chitchat")
                else (
                    "Tôi đã tìm thấy một số sản phẩm phù hợp. Bạn xem danh sách bên dưới nhé."
                    if ctx["final_docs"] else
                    "Xin lỗi, hệ thống chưa tìm thấy sản phẩm phù hợp."
                )
            )
            yield _sse({"type": "token", "delta": full_answer})

    save_turn(
        ctx["msg_data"],
        message,
        full_answer,
        last_products=docs_to_products(ctx["final_docs"]) or None,
    )
    if not ctx.get("skip_llm") and ctx.get("analysis"):
        a = ctx["analysis"]
        update_session_state(
            ctx["msg_data"],
            loai_da=a.get("loai_da"),
            budget_vnd=a.get("ngan_sach"),
            last_intent=a.get("intent"),
            is_routine=a.get("is_routine"),
            last_rewritten=ctx.get("rewritten"),
            last_loai_san_pham=a.get("loai_san_pham"),
        )

    cart_actions, order_intent = _resolve_order_context(message, msg_data, ctx["final_docs"])
    stream_intent = ctx.get("intent") or (ctx.get("analysis") or {}).get("intent")
    if order_intent:
        stream_intent = order_intent

    yield _sse({
        "type":        "done",
        "products":    docs_to_products(ctx["final_docs"]),
        "analysis":    ctx["analysis"],
        "conflicts":   ctx["cart_conflicts"],
        "intent":      stream_intent,
        "suggestions": ctx.get("suggestions") or [],
        "cart_actions": cart_actions,
    })
    store_response(message, msg_data, {
        "ok": True,
        "answer": full_answer,
        "products": docs_to_products(ctx["final_docs"]),
        "conflicts": ctx["cart_conflicts"],
        "analysis": ctx["analysis"],
        "intent": stream_intent,
        "suggestions": ctx.get("suggestions") or [],
        "cart_actions": cart_actions,
    })
    yield "data: [DONE]\n\n"
