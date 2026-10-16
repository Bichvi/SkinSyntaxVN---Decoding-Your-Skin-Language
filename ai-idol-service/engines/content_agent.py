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
from core.narration import style_instruction
from core.purpose import LEGACY_PURPOSE, LIVE_AND_PRODUCT, PRODUCT_VIDEO, PRODUCT_VIDEO_LENGTH_MODE
from core.script_text import sanitize_external_retailer_mentions
from engines.script_validator import _PRICE_RULES, price_data_lines


def _safe_source(value, limit: int):
    """Keep imported retailer names out of the generation context."""
    return sanitize_external_retailer_mentions(clip_text(value, limit))


def _narration_style_line(config: dict) -> str:
    """The chosen narration styles as one prompt line, or "" when the admin picked none."""
    return style_instruction(config.get("narration_styles") or [])


def _style_line_after_length(config: dict) -> str:
    """The style line prefixed with a newline, to follow the length line; "" keeps the prompt as it was."""
    line = _narration_style_line(config)
    return f"\n{line}" if line else ""


def price_lines(pricing: dict | None) -> str:
    """The DATA price lines (the exact phrases a page script may speak); one source with the validator."""
    return price_data_lines({"pricing": pricing})


def _script_council_payload(snapshot: dict, knowledge: str, content_mode: str, config: dict,
                            purpose: str = LEGACY_PURPOSE) -> dict:
    evergreen = purpose == PRODUCT_VIDEO
    page_purpose = purpose in (PRODUCT_VIDEO, LIVE_AND_PRODUCT)
    source_price = {"price": "", "market_price": "", "price_lines": price_lines(snapshot.get("pricing"))} if page_purpose else {
        "price": snapshot.get("price"),
        "market_price": snapshot.get("market_price", snapshot.get("price")),
    }
    return {
        "source": {
            "name": sanitize_external_retailer_mentions(snapshot.get("name")),
            "brand": sanitize_external_retailer_mentions(snapshot.get("brand")),
            "category": sanitize_external_retailer_mentions(snapshot.get("category")),
            "origin": sanitize_external_retailer_mentions(snapshot.get("origin")),
            # Page scripts get backend-made price phrases, never raw numbers they could reformat.
            **source_price,
            "ingredients": _safe_source(snapshot.get("ingredients"), 2000 if evergreen else 1000),
            "description": _safe_source(snapshot.get("description"), 1300),
            "usage": _safe_source(snapshot.get("usage"), 650),
            "skin_type": snapshot.get("skin_type"),
            "knowledge": (_safe_source(knowledge, 2200 if evergreen else 1000)
                          if knowledge else "Không có tài liệu bổ sung."),
            "content_mode": content_mode,
            "length_mode": config.get("length_mode") or (PRODUCT_VIDEO_LENGTH_MODE if evergreen else "Short (30-60s)"),
            "purpose": purpose,
            "narration_styles": _narration_style_line(config),
        }
    }


def _generate_with_script_council(snapshot: dict, knowledge: str, content_mode: str, config: dict,
                                  purpose: str = LEGACY_PURPOSE) -> str:
    headers = {"Content-Type": "application/json"}
    if INTERNAL_TOKEN:
        headers["Authorization"] = f"Bearer {INTERNAL_TOKEN}"
    response = requests.post(
        f"{SCRIPT_COUNCIL_URL}/api/script-council",
        json=_script_council_payload(snapshot, knowledge, content_mode, config, purpose),
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

_TTS_RULES = """QUY TẮC PHÁT ÂM TTS (RẤT QUAN TRỌNG):
- Không dùng viết tắt, ký hiệu, hoặc số (ví dụ: viết "phần trăm" thay vì "%", "mililít" thay vì "ml", "năm trăm nghìn đồng" thay vì "500.000đ").
- Tên hoạt chất viết bình thường như trong dữ liệu (ví dụ: Niacinamide, Retinol, AHA, BHA), không viết tách từng chữ cái hay gạch nối. Ưu tiên nêu 2 đến 4 hoạt chất chính, không đọc dài danh sách thành phần.
- Không viết các ký hiệu toán học như "+", "-", "x", viết bằng chữ: "cộng", "trừ", "nhân".
"""

_FORBIDDEN_RULES = """TUYỆT ĐỐI CẤM:
- KHÔNG tự bịa ra công dụng chữa trị dứt điểm (ví dụ: "trị tận gốc", "hết mụn 100%", "trắng sáng sau 1 đêm").
- KHÔNG đưa bất kỳ câu hỏi tương tác comment ảo hay nội dung Q&A nào vào kịch bản. Kịch bản là một phân đoạn nói đơn thoại liền mạch của AI Idol.
- Không thêm các ký tự đặc biệt hoặc emoji vào lời thoại (vì TTS không đọc được và gây lỗi âm thanh).
- Chỉ mời người xem mua tại SkinSyntax. Tuyệt đối không nhắc sàn thương mại điện tử hoặc nhà bán lẻ bên ngoài, kể cả khi mô tả sản phẩm có chứa tên nguồn nhập.
"""

_SYNA_RULE = (
    "Syna là AI Idol, không bao giờ nói đã dùng, đã thử, da mình hay bất kỳ trải nghiệm cá nhân nào; "
    "hãy dùng cách nói như 'Theo thông tin sản phẩm...', 'Điểm đáng chú ý...'."
)

_HEADED_OUTPUT_RULE = (
    "Mỗi phần bắt đầu bằng MỘT DÒNG RIÊNG chỉ chứa tiêu đề trong ngoặc vuông, đúng y như danh sách "
    "(ví dụ: [Hook]). Ngoài các dòng tiêu đề đó, chỉ viết văn xuôi liền mạch: không Markdown, "
    "không in đậm, không gạch đầu dòng. Hệ thống tự bỏ các dòng tiêu đề trước khi đọc."
)

_PRODUCT_VIDEO_SYSTEM = f"""
Bạn là Syna, AI Idol skincare của SkinSyntax: trẻ trung, cuốn hút, nói nhanh và có nhịp như creator đang bắt trend, nhưng luôn chính xác như chuyên gia. Nhiệm vụ: viết kịch bản VIDEO GIỚI THIỆU SẢN PHẨM DÙNG LÂU DÀI bằng tiếng Việt để đọc thu âm (Text-To-Speech). Video này sẽ nằm trên trang sản phẩm nhiều tháng, nên chỉ được nói giá bằng đúng các cụm giá cho sẵn trong dữ liệu.

{_TTS_RULES}
CẤU TRÚC BẮT BUỘC (7 phần, đúng thứ tự, tổng độ dài để đọc khoảng 90 đến 150 giây):
[Hook] Một hai câu mở trong 3 giây đầu: gây tò mò hoặc chạm đúng nỗi đau da đang được quan tâm. Giọng trẻ, bắt trend. Không bịa số liệu.
[Problem] Ai đang gặp vấn đề này và vì sao bị. Ngắn gọn.
[Product Intro] Thương hiệu, xuất xứ, loại sản phẩm. Ngắn gọn.
[Specs] Dung tích, kết cấu, loại da phù hợp, nồng độ hoạt chất. CHỈ lấy từ dữ liệu sản phẩm; thông tin nào không có thì bỏ qua, không đoán.
[Ingredients] PHẦN DÀI NHẤT, khoảng 35 đến 40 phần trăm kịch bản: 2 đến 4 hoạt chất chính là gì, tác dụng thế nào, vì sao kết hợp với nhau tốt. Lồng một đến hai sự thật thú vị lấy từ tài liệu kiến thức bổ trợ.
[How to Use] Dùng ở bước nào trong routine, sáng hay tối, lưu ý khi kết hợp với hoạt chất khác.
[Wrap-up] Tóm ba ý chính và mời người xem bấm vào sản phẩm để xem giá mới nhất và đặt hàng.

{_HEADED_OUTPUT_RULE}

GIỌNG ĐIỆU: câu ngắn, nhịp nhanh, có câu hỏi tu từ, có điểm nhấn bất ngờ. "Giật gân" chỉ ở CÁCH NÓI; mọi con số, hoạt chất và công dụng phải khớp dữ liệu sản phẩm.

QUY TẮC GIÁ: có thể nhắc giá tự nhiên ở bất kỳ phần nào (kể cả [Hook]), nhưng CHỈ bằng đúng các cụm giá cho sẵn trong dữ liệu. Không tự tính, không tự bịa giá, mức giảm hay sự khan hiếm (chỉ hôm nay, sắp hết...) nếu dữ liệu không có. {_PRICE_RULES}

{_SYNA_RULE}

{_FORBIDDEN_RULES}"""

_LIVE_AND_PRODUCT_SYSTEM = f"""
Bạn là một AI Idol/KOL bán hàng chuyên nghiệp, trẻ trung, ấm áp và cực kỳ thân thiện trong lĩnh vực chăm sóc da (skincare). Nhiệm vụ của bạn là viết một kịch bản bán hàng bằng tiếng Việt để đọc thu âm (Text-To-Speech).

{_TTS_RULES}
CẤU TRÚC KỊCH BẢN BẮT BUỘC (8 phần, đúng thứ tự):
[Opening] Chào người xem một cách trung tính, dùng được cả khi xem lại sau này.
[Hook] Đưa ra vấn đề về da thường gặp để lôi kéo sự chú ý.
[Product Intro] Giới thiệu sản phẩm, thương hiệu, xuất xứ.
[Key Benefits] Các công dụng dựa TRỰC TIẾP trên dữ liệu sản phẩm.
[How to Use] Hướng dẫn thoa/dùng chuẩn khoa học.
[Price] Công bố giá bán và ưu đãi từ dữ liệu cung cấp.
[CTA] Kêu gọi chốt đơn mua hàng nhanh chóng (ví dụ: nhấn vào giỏ hàng bên dưới).
[Closing] Chào tạm biệt hẹn gặp lại.

{_HEADED_OUTPUT_RULE}

QUAN TRỌNG: có thể nhắc giá tự nhiên ở bất kỳ phần nào (kể cả [Hook]), nhưng CHỈ bằng đúng các cụm giá cho sẵn trong dữ liệu. Không tự tính, không tự bịa giá, mức giảm hay sự khan hiếm (chỉ hôm nay, sắp hết...) nếu dữ liệu không có. {_PRICE_RULES}

{_SYNA_RULE}

{_FORBIDDEN_RULES}"""


def _product_source_lines(snapshot: dict, *, with_price: bool, ingredients: int, description: int, usage: int) -> str:
    lines = [
        f"- Tên sản phẩm: {sanitize_external_retailer_mentions(snapshot.get('name'))}",
        f"- Thương hiệu: {sanitize_external_retailer_mentions(snapshot.get('brand'))}",
        f"- Loại sản phẩm: {sanitize_external_retailer_mentions(snapshot.get('category'))}",
        f"- Xuất xứ: {sanitize_external_retailer_mentions(snapshot.get('origin'))}",
    ]
    if with_price:
        lines.append(price_lines(snapshot.get("pricing")))
    lines += [
        f"- Thành phần: {_safe_source(snapshot.get('ingredients'), ingredients)}",
        f"- Mô tả công dụng: {_safe_source(snapshot.get('description'), description)}",
        f"- Cách dùng: {_safe_source(snapshot.get('usage'), usage)}",
        f"- Phù hợp loại da: {snapshot.get('skin_type')}",
    ]
    return "\n".join(lines)


def build_script_prompts(snapshot: dict, knowledge: str, content_mode: str, config: dict,
                         purpose: str = LEGACY_PURPOSE) -> tuple[str, str]:
    """System and user prompts for one product script in the given broadcast purpose."""
    if purpose == PRODUCT_VIDEO:
        user_prompt = f"""
Hãy viết kịch bản video giới thiệu sản phẩm dùng lâu dài cho sản phẩm sau đây:

THÔNG TIN SẢN PHẨM:
{_product_source_lines(snapshot, with_price=True, ingredients=2000, description=1600, usage=800)}

TÀI LIỆU KIẾN THỨC BỔ TRỢ (RAG):
{_safe_source(knowledge, 2200) if knowledge else "Không có tài liệu bổ sung."}

Cấu hình bổ sung:
- Chế độ Content: {content_mode}
- Độ dài mong muốn: {config.get('length_mode') or PRODUCT_VIDEO_LENGTH_MODE}{_style_line_after_length(config)}

Viết đủ 7 phần theo đúng định dạng tiêu đề trong ngoặc vuông.
"""
        return _PRODUCT_VIDEO_SYSTEM, user_prompt
    if purpose == LIVE_AND_PRODUCT:
        user_prompt = f"""
Hãy viết kịch bản livestream giới thiệu sản phẩm sau đây:

THÔNG TIN SẢN PHẨM:
{_product_source_lines(snapshot, with_price=True, ingredients=1200, description=1600, usage=800)}

TÀI LIỆU KIẾN THỨC BỔ TRỢ (RAG):
{_safe_source(knowledge, 1400) if knowledge else "Không có tài liệu bổ sung."}

Cấu hình bổ sung:
- Chế độ Content: {content_mode}
- Độ dài mong muốn: {config.get('length_mode', 'Short (30-60s)')}{_style_line_after_length(config)}

Viết đủ 8 phần theo đúng định dạng tiêu đề trong ngoặc vuông.
"""
        return _LIVE_AND_PRODUCT_SYSTEM, user_prompt
    return _live_only_prompts(snapshot, knowledge, content_mode, config)


def generate_content_and_script(snapshot: dict, knowledge: str, content_mode: str, config: dict,
                                purpose: str = LEGACY_PURPOSE) -> str:
    """Generate a cohesive marketing script for an AI Idol using the LLM provider pool."""
    if config.get("agent_managed") and SCRIPT_COUNCIL_ENABLED:
        try:
            logger.info("[CONTENT-AGENT] Asking the isolated CrewAI Script Council for a draft")
            return _generate_with_script_council(snapshot, knowledge, content_mode, config, purpose)
        except Exception as exc:
            # The media job remains recoverable even if the optional council is restarting
            # or Groq throttles a multi-agent request.
            logger.warning("[CONTENT-AGENT] Script Council unavailable, using single-agent fallback: %s", exc)

    logger.info("[CONTENT-AGENT] Crafting script draft using LLM...")
    llm = LLMProvider()
    system_prompt, user_prompt = build_script_prompts(snapshot, knowledge, content_mode, config, purpose)
    script = llm.invoke(system_prompt, user_prompt)
    logger.info("[CONTENT-AGENT] Script draft generated successfully.")
    return sanitize_external_retailer_mentions(script)


def _live_only_prompts(snapshot: dict, knowledge: str, content_mode: str, config: dict) -> tuple[str, str]:
    # Unchanged legacy livestream prompt (pinned by tests/test_script_purpose.py fingerprint).
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

    # Product snapshot and retrieved RAG context
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
- Độ dài mong muốn: {config.get('length_mode', 'Short (30-60s)')}{_style_line_after_length(config)}

Hãy viết kịch bản hội thoại hoàn chỉnh bằng tiếng Việt, viết trơn bằng văn bản thông thường (không chứa các thẻ Markdown dạng ** đậm hoặc tiêu đề, không chứa dấu gạch đầu dòng, chỉ có các đoạn văn xuôi liền mạch để giọng đọc trôi chảy).
"""
    return system_prompt, user_prompt
