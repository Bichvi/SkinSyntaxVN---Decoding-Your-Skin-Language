# -*- coding: utf-8 -*-
"""LangChain tool-calling agent for multi-step skincare queries."""
from __future__ import annotations

import logging
logger = logging.getLogger(__name__)

import os
import hashlib
import time
from typing import Optional

from langchain.agents import create_tool_calling_agent, AgentExecutor
from langchain.tools import tool
from langchain_core.prompts import ChatPromptTemplate, MessagesPlaceholder
from langchain_core.messages import HumanMessage, AIMessage, BaseMessage



_CONFLICT_TABLE: dict[frozenset, str] = {
    frozenset(["vitamin c", "retinol"]): (
        "[CAUTION] KHÔNG nên dùng cùng buổi. Vitamin C buổi sáng (ổn định hơn khi không có ánh nắng), "
        "retinol buổi tối. Dùng cùng lúc giảm hiệu quả cả 2 và có thể gây kích ứng đỏ rát."
    ),
    frozenset(["vitamin c", "niacinamide"]): (
        "[OK] Có thể dùng chung an toàn. Các nghiên cứu gần đây cho thấy kết hợp này "
        "không gây vấn đề ở nồng độ thông thường trong mỹ phẩm. Có thể dùng cùng sáng hoặc tối."
    ),
    frozenset(["bha", "retinol"]): (
        "[CAUTION] Không nên dùng cùng buổi — quá tải tẩy da chết, dễ kích ứng, bong tróc. "
        "Gợi ý: BHA tối thứ 2/4/6, retinol tối thứ 3/5/7 xen kẽ."
    ),
    frozenset(["aha", "retinol"]): (
        "[CAUTION] Không nên dùng cùng lúc — tương tự BHA+retinol. Xen kẽ các đêm khác nhau trong tuần."
    ),
    frozenset(["aha", "bha"]): (
        "[CAUTION] Thận trọng nếu da nhạy cảm. Kết hợp hiệu quả cho da quen nhưng dễ kích ứng người mới. "
        "Dùng tối 2-3 lần/tuần, không mỗi đêm."
    ),
    frozenset(["benzoyl peroxide", "retinol"]): (
        "[AVOID] Tuyệt đối không dùng cùng lúc. Benzoyl peroxide vô hiệu hóa hoàn toàn retinol."
    ),
    frozenset(["benzoyl peroxide", "vitamin c"]): (
        "[AVOID] Không nên — benzoyl peroxide oxy hóa vitamin C, làm mất tác dụng hoàn toàn."
    ),
    frozenset(["retinol", "spf"]): (
        "[OK] Hoàn toàn ổn và ĐƯỢC KHUYẾN KHÍCH — retinol tối, SPF sáng. "
        "Retinol làm da nhạy sáng nên SPF ban ngày là bắt buộc khi dùng retinol."
    ),
    frozenset(["vitamin c", "spf"]): (
        "[OK] Kết hợp hoàn hảo — vitamin C tăng cường hiệu quả chống nắng của SPF."
    ),
    frozenset(["niacinamide", "retinol"]): (
        "[OK] Kết hợp tốt — niacinamide giúp giảm kích ứng (đỏ, bong tróc) do retinol gây ra. "
        "Dùng cùng buổi tối được."
    ),
    frozenset(["hyaluronic acid", "retinol"]): (
        "[OK] Rất tốt — hyaluronic acid dưỡng ẩm sâu, giúp đệm kích ứng của retinol. "
        "Nên dùng hyaluronic trước retinol trong bước layering."
    ),
    frozenset(["peptide", "aha"]): (
        "[CAUTION] Không nên dùng cùng lúc — AHA (axit) có thể phá vỡ cấu trúc peptide. "
        "AHA tối, peptide sáng hoặc khác buổi."
    ),
    frozenset(["peptide", "vitamin c"]): (
        "[CAUTION] Thận trọng — môi trường axit của vitamin C có thể giảm hiệu quả peptide. "
        "Nên cách nhau ít nhất 30 phút hoặc dùng khác buổi."
    ),
    frozenset(["ceramide", "retinol"]): (
        "[OK] Kết hợp hoàn hảo — ceramide phục hồi hàng rào da bị yếu đi do retinol. "
        "Nên dùng ceramide sau retinol để khóa ẩm và phục hồi."
    ),
    frozenset(["spf", "niacinamide"]): (
        "[OK] Rất tốt — niacinamide làm dịu và sáng da, SPF bảo vệ. Dùng cùng buổi sáng được."
    ),
}

def _normalize_ingredient(name: str) -> str:
    SYNONYMS = {
        "vit c": "vitamin c", "vitc": "vitamin c", "vitamin c": "vitamin c",
        "retinoid": "retinol", "tretinoin": "retinol",
        "salicylic acid": "bha", "salicylic": "bha",
        "glycolic acid": "aha", "lactic acid": "aha",
        "niacinamide": "niacinamide", "b3": "niacinamide",
        "ha": "hyaluronic acid", "hyaluronic": "hyaluronic acid",
        "bp": "benzoyl peroxide", "bpo": "benzoyl peroxide",
        "spf": "spf", "sunscreen": "spf", "chống nắng": "spf",
    }
    n = name.strip().lower()
    return SYNONYMS.get(n, n)


_WEB_CACHE: dict[str, tuple[dict, float]] = {}
_WEB_CACHE_TTL = 900


def _query_web_cached(query: str) -> str:
    tavily_key = os.getenv("TAVILY_API_KEY", "").strip()
    if not tavily_key:
        return "Không có Tavily API key — bỏ qua web search."

    cache_key = hashlib.md5(query.strip().lower().encode()).hexdigest()
    cached = _WEB_CACHE.get(cache_key)
    if cached:
        result, ts = cached
        if time.monotonic() - ts < _WEB_CACHE_TTL:
            items = result.get("results", [])
            if items:
                return "\n".join(
                    f"- {i.get('title','')}: {i.get('content','')[:200]}"
                    for i in items[:3]
                )
    try:
        from langchain_tavily import TavilySearch
        result = TavilySearch(max_results=3).invoke({"query": query})
        _WEB_CACHE[cache_key] = (result, time.monotonic())
        items = result.get("results", []) if isinstance(result, dict) else []
        if not items:
            return "Không tìm thấy thông tin từ web."
        return "\n".join(
            f"- {i.get('title','')}: {i.get('content','')[:200]}"
            for i in items[:3]
        )
    except Exception as e:
        return f"Web search thất bại: {e}"



@tool
def tim_san_pham(yeu_cau: str) -> str:
    """
    Tìm sản phẩm mỹ phẩm từ database SkinSyntaxVN theo yêu cầu.

    Dùng khi: khách muốn tìm, mua, hoặc được tư vấn sản phẩm cụ thể.

    Input: mô tả yêu cầu đầy đủ bằng tiếng Việt, bao gồm:
      - Loại sản phẩm cần tìm (sữa rửa mặt, serum, kcn...)
      - Loại da (da dầu, da khô, da nhạy cảm...)
      - Tình trạng da cần cải thiện (mụn, thâm, nhăn...)
      - Mức giá nếu có (dưới 200k, 200-500k, trên 500k)
      - Thành phần mong muốn nếu có (vitamin C, BHA, retinol...)

    Output: danh sách 3-4 sản phẩm phù hợp nhất với thông tin chi tiết.

    Ví dụ input:
      "sữa rửa mặt cho da dầu mụn giá tầm trung dưới 300k"
      "serum vitamin C sáng da cho da thường"
      "kem chống nắng cho da nhạy cảm không cồn"
    """
    try:
        from retrieval import get_hybrid_pipeline, format_search_results, MockDocument
        pipeline_obj = get_hybrid_pipeline()
        ranked, _ = pipeline_obj.search(
            query=yeu_cau,
            k_total=8,
            top_n=4,
            filters=None,
            use_reranker=True,
        )
        docs = [
            MockDocument(page_content=r.content, metadata=r.metadata, id=r.doc_id)
            for r in ranked
        ]
        if not docs:
            return "Không tìm thấy sản phẩm phù hợp với yêu cầu này."
        return format_search_results(docs)
    except Exception as e:
        return f"Lỗi tìm kiếm sản phẩm: {e}"



@tool
def kiem_tra_xung_dot(hoat_chat_a: str, hoat_chat_b: str) -> str:
    """
    Kiểm tra 2 hoạt chất mỹ phẩm có kết hợp được với nhau không.

    Dùng khi: khách hỏi về việc dùng chung 2 sản phẩm/hoạt chất,
              hoặc muốn biết thứ tự/cách kết hợp an toàn.

    Input:
      hoat_chat_a: tên hoạt chất thứ nhất (ví dụ: "retinol", "vitamin C")
      hoat_chat_b: tên hoạt chất thứ hai (ví dụ: "niacinamide", "BHA")

    Output: đánh giá khả năng tương thích và hướng dẫn cụ thể.

    Ví dụ:
      hoat_chat_a="retinol", hoat_chat_b="vitamin C"
      hoat_chat_a="BHA", hoat_chat_b="niacinamide"
    """
    a = _normalize_ingredient(hoat_chat_a)
    b = _normalize_ingredient(hoat_chat_b)
    key = frozenset([a, b])

    if result := _CONFLICT_TABLE.get(key):
        return f"**{hoat_chat_a.title()} + {hoat_chat_b.title()}**: {result}"

    web = _query_web_cached(
        f"có nên dùng {hoat_chat_a} cùng {hoat_chat_b} không skincare tiếng việt"
    )
    return (
        f"**{hoat_chat_a.title()} + {hoat_chat_b.title()}**: "
        f"Chưa có dữ liệu cụ thể trong hệ thống. "
        f"Thông tin từ web:\n{web}"
    )



@tool
def tra_cuu_kien_thuc(ten_hoat_chat_hoac_chu_de: str) -> str:
    """
    Tra cứu kiến thức chuyên sâu về hoạt chất mỹ phẩm hoặc chủ đề da liễu.

    Dùng khi: khách hỏi về định nghĩa, công dụng, cơ chế tác động,
              cách dùng, lưu ý của một hoạt chất hoặc khái niệm da liễu.

    Input: tên hoạt chất hoặc chủ đề cần tra cứu (tiếng Việt hoặc tiếng Anh).

    Output: thông tin khoa học, dễ hiểu về hoạt chất/chủ đề đó.

    Ví dụ input:
      "retinol"
      "BHA salicylic acid"
      "da dầu hỗn hợp là gì"
      "cách layering skincare đúng"
    """
    query = f"{ten_hoat_chat_hoac_chu_de} skincare công dụng cách dùng tiếng việt"
    web_result = _query_web_cached(query)

    if "Không có Tavily" in web_result or "thất bại" in web_result:
        try:
            from retrieval import get_hybrid_pipeline, format_search_results, MockDocument
            pipeline_obj = get_hybrid_pipeline()
            ranked, _ = pipeline_obj.search(
                query=ten_hoat_chat_hoac_chu_de,
                k_total=4,
                top_n=3,
                filters=None,
                use_reranker=False,
            )
            if ranked:
                docs = [
                    MockDocument(page_content=r.content, metadata=r.metadata, id=r.doc_id)
                    for r in ranked
                ]
                return f"Sản phẩm liên quan đến '{ten_hoat_chat_hoac_chu_de}':\n{format_search_results(docs)}"
        except Exception:
            pass
        return f"Không tìm được thông tin về '{ten_hoat_chat_hoac_chu_de}'."

    return web_result



@tool
def xem_chi_tiet_san_pham(ten_hoac_id_san_pham: str) -> str:
    """
    Xem thông tin đầy đủ về một sản phẩm cụ thể theo tên hoặc product ID.

    Dùng khi: khách hỏi về một sản phẩm cụ thể mà đã biết tên hoặc ID,
              ví dụ "sản phẩm này có phù hợp với da mình không?"
              hoặc sau khi đã tìm được sản phẩm và muốn biết thêm chi tiết.

    Input: tên sản phẩm (ví dụ: "La Roche-Posay Effaclar") hoặc product ID.

    Output: thông tin chi tiết: thành phần, loại da phù hợp, giá, cách dùng.

    Ví dụ input:
      "La Roche-Posay Effaclar Gel"
      "product_1234"
      "The Ordinary Niacinamide 10%"
    """
    try:
        from retrieval import get_vectorstore, get_hybrid_pipeline, format_search_results, MockDocument
        vs = get_vectorstore()

        pid = ten_hoac_id_san_pham.strip()
        if not pid.startswith("product_"):
            pid = f"product_{pid}"
        try:
            result = vs._collection.get(ids=[pid])
            if result and result.get("ids"):
                doc = MockDocument(
                    page_content=result["documents"][0] if result.get("documents") else "",
                    metadata=result["metadatas"][0] if result.get("metadatas") else {},
                    id=result["ids"][0],
                )
                return format_search_results([doc])
        except Exception:
            pass

        pipeline_obj = get_hybrid_pipeline()
        ranked, _ = pipeline_obj.search(
            query=ten_hoac_id_san_pham,
            k_total=4,
            top_n=2,
            filters=None,
            use_reranker=True,
        )
        if not ranked:
            return f"Không tìm thấy sản phẩm '{ten_hoac_id_san_pham}' trong hệ thống."
        docs = [
            MockDocument(page_content=r.content, metadata=r.metadata, id=r.doc_id)
            for r in ranked
        ]
        return format_search_results(docs)
    except Exception as e:
        return f"Lỗi khi lấy thông tin sản phẩm: {e}"



AGENT_TOOLS = [tim_san_pham, kiem_tra_xung_dot, tra_cuu_kien_thuc, xem_chi_tiet_san_pham]



_AGENT_SYSTEM = """\
Bạn là trợ lý AI tư vấn mỹ phẩm chuyên nghiệp của SkinSyntaxVN, có khả năng sử dụng công cụ để tra cứu thông tin chính xác.

━━━ TOOLS VÀ KHI NÀO DÙNG ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
· tim_san_pham           → khi cần tìm, gợi ý sản phẩm theo yêu cầu
· kiem_tra_xung_dot      → khi khách hỏi 2 hoạt chất/sản phẩm có dùng chung được không
· tra_cuu_kien_thuc      → khi cần thông tin về hoạt chất, kiến thức da liễu
· xem_chi_tiet_san_pham  → khi cần thông tin đầy đủ về 1 sản phẩm cụ thể

━━━ NGUYÊN TẮC SỬ DỤNG TOOLS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
· LUÔN dùng tool để lấy dữ liệu thực tế — KHÔNG tự bịa tên sản phẩm, giá, thành phần
· Với query phức tạp → gọi nhiều tools theo thứ tự logic
· Sau khi có đủ thông tin → tổng hợp thành câu trả lời hoàn chỉnh
· Nếu tool trả về rỗng → thông báo lịch sự và gợi ý cách khác

━━━ PHONG CÁCH ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
· Xưng "mình", gọi khách là "bạn"
· Thân thiện, chuyên môn, không dùng quá 2 emoji
· Tên sản phẩm phải là link Markdown NGUYÊN VĂN từ kết quả tool
· KHÔNG đề cập giảm giá nếu không có dữ liệu thực
· KHÔNG đưa ra chẩn đoán y tế thay bác sĩ

━━━ NGÂN SÁCH ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
· Nếu khách nêu ngân sách tổng → TỔNG giá các sản phẩm gợi ý PHẢI ≤ ngân sách đó
· Ưu tiên sản phẩm/combo rẻ hơn; bỏ bước không bắt buộc (toner) nếu cần
· Cuối câu trả lời ghi: "Tổng chi phí ước tính: X VNĐ (trong ngân sách Y VNĐ)"\
"""

_AGENT_HUMAN = """\
{rich_context}
Câu hỏi: {input}\
"""


def _build_agent_prompt() -> ChatPromptTemplate:
    return ChatPromptTemplate.from_messages([
        ("system", _AGENT_SYSTEM),
        MessagesPlaceholder("chat_history", optional=True),
        ("human", _AGENT_HUMAN),
        MessagesPlaceholder("agent_scratchpad"),
    ])



def _to_lc_messages(history_raw) -> list[BaseMessage]:
    if not isinstance(history_raw, list):
        return []
    messages: list[BaseMessage] = []
    for h in history_raw[-8:]:
        if not isinstance(h, dict):
            continue
        text = h.get("text", "").strip()
        if not text:
            continue
        if h.get("sender") == "user":
            messages.append(HumanMessage(content=text))
        else:
            messages.append(AIMessage(content=text))
    return messages



def _get_tool_calling_llm():
    from llm_pool import get_llms
    llms = get_llms()
    if not llms:
        return None
    for llm in llms:
        model = getattr(llm, "model", getattr(llm, "model_name", "")).lower()
        if model.startswith("gpt-"):
            return llm
    return llms[0]




def run_agent(message: str, msg_data: dict | None = None) -> dict:
    from session_cache import get_history, save_turn

    skin_type         = None
    avoid_ingredients: list = []
    cart_conflicts   : list = []
    history_raw             = []

    if msg_data:
        profile          = msg_data.get("customer_profile", {}) or {}
        skin_type        = profile.get("skin_type") or profile.get("loai_da")
        avoid_ingredients = profile.get("avoid_ingredients") or []
        cart_conflicts   = msg_data.get("cart_conflicts", []) or []
        history_raw      = msg_data.get("conversation_history", []) or []

    if not history_raw:
        history_raw = get_history(msg_data) or []

    ctx_parts: list[str] = []
    if skin_type:
        ctx_parts.append(f"Loại da khách: {skin_type}")
    if avoid_ingredients:
        ctx_parts.append(f"Thành phần cần tránh: {', '.join(avoid_ingredients)}")
    if cart_conflicts:
        ctx_parts.append(f"Xung đột hoạt chất trong giỏ: {', '.join(cart_conflicts)}")
    from pipeline import _extract_budget_vnd
    budget = _extract_budget_vnd(message)
    if budget:
        ctx_parts.append(
            f"NGÂN SÁCH TỐI ĐA: {budget:,} VNĐ — tổng giá gợi ý không được vượt quá mức này."
        )
    rich_context = (
        f"[Thông tin khách hàng]\n" + "\n".join(ctx_parts) + "\n\n"
        if ctx_parts else ""
    )

    chat_history = _to_lc_messages(history_raw)

    llm = _get_tool_calling_llm()
    if not llm:
        raise RuntimeError("Không có LLM nào khả dụng cho agent.")

    prompt = _build_agent_prompt()
    agent  = create_tool_calling_agent(llm, AGENT_TOOLS, prompt)
    executor = AgentExecutor(
        agent=agent,
        tools=AGENT_TOOLS,
        verbose=False,
        max_iterations=6,
        max_execution_time=45,
        handle_parsing_errors=True,
        return_intermediate_steps=False,
    )

    model_name = getattr(llm, "model", getattr(llm, "model_name", "?"))
    logger.debug(f"[AGENT] Starting with {model_name} | {len(AGENT_TOOLS)} tools | history={len(chat_history)}")

    result = executor.invoke({
        "input":        message,
        "rich_context": rich_context,
        "chat_history": chat_history,
    })

    answer = str(result.get("output", "")).strip()
    if not answer:
        answer = "Xin lỗi, mình chưa tìm được thông tin phù hợp. Bạn thử hỏi lại theo cách khác nhé."

    logger.debug(f"[AGENT] Done — answer length: {len(answer)}")

    save_turn(msg_data, message, answer)

    return {
        "ok":       True,
        "answer":   answer,
        "products": [],
        "conflicts": cart_conflicts,
        "analysis": {"mode": "agent"},
    }
