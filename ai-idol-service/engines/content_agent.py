import requests

from providers.llm_provider import LLMProvider
from config import (
    INTERNAL_TOKEN,
    SCRIPT_COUNCIL_ENABLED,
    SCRIPT_COUNCIL_TIMEOUT,
    SCRIPT_COUNCIL_URL,
    logger,
)
from core.text_budget import clip_text
from core.script_text import sanitize_external_retailer_mentions


def _safe_source(value, limit: int):
    """Keep imported retailer names out of the generation context."""
    return sanitize_external_retailer_mentions(clip_text(value, limit))


def _script_council_payload(snapshot: dict, knowledge: str, content_mode: str, config: dict) -> dict:
    return {
        "source": {
            "name": sanitize_external_retailer_mentions(snapshot.get("name")),
            "brand": sanitize_external_retailer_mentions(snapshot.get("brand")),
            "category": sanitize_external_retailer_mentions(snapshot.get("category")),
            "origin": sanitize_external_retailer_mentions(snapshot.get("origin")),
            "price": snapshot.get("price"),
            "market_price": snapshot.get("market_price", snapshot.get("price")),
            "ingredients": _safe_source(snapshot.get("ingredients"), 1000),
            "description": _safe_source(snapshot.get("description"), 1300),
            "usage": _safe_source(snapshot.get("usage"), 650),
            "skin_type": snapshot.get("skin_type"),
            "knowledge": _safe_source(knowledge, 1000) if knowledge else "Không có tài liệu bổ sung.",
            "content_mode": content_mode,
            "length_mode": config.get("length_mode", "Short (30-60s)"),
        }
    }


def _generate_with_script_council(snapshot: dict, knowledge: str, content_mode: str, config: dict) -> str:
    headers = {"Content-Type": "application/json"}
    if INTERNAL_TOKEN:
        headers["Authorization"] = f"Bearer {INTERNAL_TOKEN}"
    response = requests.post(
        f"{SCRIPT_COUNCIL_URL}/api/script-council",
        json=_script_council_payload(snapshot, knowledge, content_mode, config),
        headers=headers,
        timeout=SCRIPT_COUNCIL_TIMEOUT,
    )
    response.raise_for_status()
    data = response.json()
    if not isinstance(data, dict):
        raise RuntimeError("Script Council returned invalid JSON")
    script = str(data.get("script") or "").strip()
    if not data.get("ok") or len(script) < 80:
        raise RuntimeError(str(data.get("error") or "Script Council returned invalid output"))
    return sanitize_external_retailer_mentions(script)

def generate_content_and_script(snapshot: dict, knowledge: str, content_mode: str, config: dict) -> str:
    """Generate a cohesive marketing script for an AI Idol using the LLM provider pool."""
    if config.get("agent_managed") and SCRIPT_COUNCIL_ENABLED:
        try:
            logger.info("[CONTENT-AGENT] Asking the isolated CrewAI Script Council for a draft")
            return _generate_with_script_council(snapshot, knowledge, content_mode, config)
        except Exception as exc:
            # The media job remains recoverable even if the optional council is restarting
            # or Groq throttles a multi-agent request.
            logger.warning("[CONTENT-AGENT] Script Council unavailable, using single-agent fallback: %s", exc)

    logger.info("[CONTENT-AGENT] Crafting script draft using LLM...")
    
    # 1. Instantiate LLM pool
    llm = LLMProvider()
    
    # 2. Build instructions for KOL conversational selling style and phonetic spelling rules
    system_prompt = """
Bạn là một AI Idol/KOL bán hàng chuyên nghiệp, trẻ trung, ấm áp và cực kỳ thân thiện trong lĩnh vực chăm sóc da (skincare). Nhiệm vụ của bạn là viết một kịch bản bán hàng bằng tiếng Việt để đọc thu âm (Text-To-Speech).

QUY TẮC PHÁT ÂM TTS (RẤT QUAN TRỌNG):
- Không dùng viết tắt, ký hiệu, hoặc số (ví dụ: viết "phần trăm" thay vì "%", "mililít" thay vì "ml", "năm trăm nghìn đồng" thay vì "500.000đ").
- Hoạt chất tiếng Anh viết phiên âm dễ đọc tiếng Việt hoặc viết tách rời chữ cái bằng gạch nối (ví dụ: viết "A H A" hoặc "A-H-A" thay vì "AHA", "B H A" thay vì "BHA", "Re-ti-nol" thay vì "Retinol", "Ni-a-ci-na-mi" thay vì "Niacinamide").
- Không viết các ký hiệu toán học như "+", "-", "x", viết bằng chữ: "cộng", "trừ", "nhân".

CẤU TRÚC KỊCH BẢN BẮT BUỘC (Gồm 8 phần liên tục, không ghi tiêu đề phần vào văn bản đọc):
1. Opening: Chào mừng nồng nhiệt người xem.
2. Hook: Đưa ra vấn đề về da thường gặp để lôi kéo sự chú ý.
3. Product Introduction: Giới thiệu sản phẩm, thương hiệu, xuất xứ.
4. Key Benefits: Các công dụng tuyệt vời dựa TRỰC TIẾP trên dữ liệu sản phẩm.
5. How to Use: Hướng dẫn thoa/dùng chuẩn khoa học.
6. Price & Promotion: Công bố giá bán và ưu đãi từ dữ liệu cung cấp.
7. CTA: Kêu gọi chốt đơn mua hàng nhanh chóng (ví dụ: nhấn vào giỏ hàng bên dưới).
8. Closing: Chào tạm biệt hẹn gặp lại.

TUYỆT ĐỐI CẤM:
- KHÔNG tự bịa ra công dụng chữa trị dứt điểm (ví dụ: "trị tận gốc", "hết mụn 100%", "trắng sáng sau 1 đêm").
- KHÔNG đưa bất kỳ câu hỏi tương tác comment ảo hay nội dung Q&A nào vào kịch bản. Kịch bản là một phân đoạn nói đơn thoại liền mạch của AI Idol.
- Không thêm các ký tự đặc biệt hoặc emoji vào lời thoại (vì TTS không đọc được và gây lỗi âm thanh).
- Chỉ mời người xem mua tại SkinSyntax. Tuyệt đối không nhắc sàn thương mại điện tử hoặc nhà bán lẻ bên ngoài, kể cả khi mô tả sản phẩm có chứa tên nguồn nhập.
"""

    # 3. Format product snapshot and retrieved RAG context
    user_prompt = f"""
Hãy viết kịch bản livestream giới thiệu sản phẩm sau đây:

THÔNG TIN SẢN PHẨM:
- Tên sản phẩm: {sanitize_external_retailer_mentions(snapshot.get('name'))}
- Thương hiệu: {sanitize_external_retailer_mentions(snapshot.get('brand'))}
- Loại sản phẩm: {sanitize_external_retailer_mentions(snapshot.get('category'))}
- Xuất xứ: {sanitize_external_retailer_mentions(snapshot.get('origin'))}
- Giá bán: {snapshot.get('price')} đồng
- Giá thị trường: {snapshot.get('market_price', snapshot.get('price'))} đồng
- Thành phần: {_safe_source(snapshot.get('ingredients'), 1200)}
- Mô tả công dụng: {_safe_source(snapshot.get('description'), 1600)}
- Cách dùng: {_safe_source(snapshot.get('usage'), 800)}
- Phù hợp loại da: {snapshot.get('skin_type')}

TÀI LIỆU KIẾN THỨC BỔ TRỢ (RAG):
{_safe_source(knowledge, 1400) if knowledge else "Không có tài liệu bổ sung."}

Cấu hình bổ sung:
- Chế độ Content: {content_mode}
- Độ dài mong muốn: {config.get('length_mode', 'Short (30-60s)')}

Hãy viết kịch bản hội thoại hoàn chỉnh bằng tiếng Việt, viết trơn bằng văn bản thông thường (không chứa các thẻ Markdown dạng ** đậm hoặc tiêu đề, không chứa dấu gạch đầu dòng, chỉ có các đoạn văn xuôi liền mạch để giọng đọc trôi chảy).
"""

    # 4. Invoke LLM
    script = llm.invoke(system_prompt, user_prompt)
    logger.info("[CONTENT-AGENT] Script draft generated successfully.")
    return sanitize_external_retailer_mentions(script)
