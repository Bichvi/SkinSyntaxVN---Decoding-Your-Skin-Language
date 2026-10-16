import re
from providers.llm_provider import LLMProvider
from config import logger
from core.text_budget import clip_text
from core.purpose import LEGACY_PURPOSE, LIVE_AND_PRODUCT, PRODUCT_VIDEO
from core.script_text import (
    PAGE_CUT_LABELS, find_external_retailer_mentions, find_price_violations, sanitize_external_retailer_mentions,
    split_labeled_sections,
)
from core.vi_money import price_phrases

# Forbidden marketing claims for cosmetic products in Vietnam
FORBIDDEN_PATTERNS = [
    r"100\s*%",
    r"trị khỏi hoàn toàn",
    r"trị dứt điểm",
    r"xóa sạch nám",
    r"tận gốc",
    r"cam kết khỏi",
    r"sau 3 ngày",
    r"sau 2 ngày",
    r"sau 1 đêm",
    r"sau một đêm",
    r"dứt điểm hoàn toàn",
    r"\bđiều trị\b",
    r"\bchuyên trị\b",
    r"\bchữa bệnh\b",
    r"\bdiệt (nấm|virus|vi khuẩn)\b",
    r"\bgiảm (ngay|liền|tức thì)\b",
]

def check_blacklist(script: str) -> str:
    """Check script against local regex blacklisted marketing claims."""
    if find_external_retailer_mentions(script):
        return "Kịch bản chứa tên nhà bán lẻ ngoài hệ thống; chỉ được nhắc SkinSyntax."
    for pattern in FORBIDDEN_PATTERNS:
        if re.search(pattern, script, re.IGNORECASE):
            return f"Vi phạm từ khóa cấm hoặc thổi phồng hiệu quả: '{pattern}'"
    return ""

def price_data_lines(snapshot: dict) -> str:
    """DATA-block price lines made of the exact phrases the script may speak."""
    phrases = price_phrases(snapshot.get("pricing"))
    if not phrases:
        return "- Giá: không có dữ liệu, không nhắc giá."
    lines = []
    if "regular" in phrases:
        lines.append(f"- Giá bán tại SkinSyntax: {phrases['regular']}")
    if "market" in phrases:
        lines.append(f"- Giá tham khảo thị trường: {phrases['market']}")
    if "flash" in phrases:
        flash_parts = [phrases[key] for key in ("flash", "discount", "deadline") if key in phrases]
        lines.append("- Flash sale: " + ", ".join(flash_parts))
    return "\n".join(lines)


def _is_page_purpose(purpose: str) -> bool:
    return purpose in (PRODUCT_VIDEO, LIVE_AND_PRODUCT)


def validate_script_via_llm(script: str, snapshot: dict, purpose: str = LEGACY_PURPOSE) -> tuple[bool, str]:
    """Use LLM as an auditor to check for hallucinated properties or overclaims."""
    llm = LLMProvider()
    
    system_prompt = """
Bạn là một kiểm duyệt viên quảng cáo mỹ phẩm và dược mỹ phẩm nghiêm ngặt của Bộ Y tế. Nhiệm vụ của bạn là rà soát xem kịch bản giới thiệu sản phẩm dưới đây có chứa các thông tin quảng cáo quá đà, thổi phồng sai sự thật, hoặc tự bịa ra công dụng hoạt chất không có trong dữ liệu sản phẩm gốc hay không.

Hãy rà soát theo các tiêu chí:
1. Có tự ý thổi phồng hiệu quả điều trị (như cam kết trị khỏi hoàn toàn, trị mụn tận gốc) không?
2. Có tuyên bố hiệu quả thời gian nhanh bất hợp lý không?
3. Có nói sai hoạt chất, sai giá tiền, sai xuất xứ so với thông tin sản phẩm gốc không? Các cụm giá, giá tham khảo thị trường và flash sale ghi trong thông tin sản phẩm gốc là đúng, không đánh FAIL.

Nếu có vi phạm, hãy trả về kết quả định dạng:
FAIL: [Mô tả chi tiết các điểm vi phạm cần chỉnh sửa]

Nếu hoàn toàn không vi phạm, hãy trả về kết quả định dạng:
PASS

Tên nơi bán bắt buộc là SkinSyntax. Tên sàn hoặc nhà bán lẻ bên ngoài không được xuất hiện trong lời thoại.
"""

    price_line = (price_data_lines(snapshot) if _is_page_purpose(purpose)
                  else f"- Giá bán: {snapshot.get('price')} đồng")
    user_prompt = f"""
THÔNG TIN SẢN PHẨM GỐC:
- Tên sản phẩm: {snapshot.get('name')}
- Thương hiệu: {snapshot.get('brand')}
{price_line}
- Thành phần: {clip_text(snapshot.get('ingredients'), 900)}
- Công dụng thật: {clip_text(snapshot.get('description'), 1200)}
- Cách dùng: {clip_text(snapshot.get('usage'), 650)}

KỊCH BẢN CẦN KIỂM DUYỆT:
\"\"\"
{clip_text(script, 6000)}
\"\"\"

Hãy kiểm tra kỹ và đưa ra phán quyết PASS hoặc FAIL kèm lý do chi tiết.
"""

    try:
        response = llm.invoke(system_prompt, user_prompt)
        res = response.strip()
        if res.startswith("PASS"):
            return True, ""
        elif res.startswith("FAIL"):
            return False, res.replace("FAIL:", "").strip()
        else:
            if "fail" in res.lower() or "vi phạm" in res.lower():
                return False, res
            return True, ""
    except Exception as e:
        # Advertising safety must fail closed. An unavailable auditor is not approval.
        logger.error(f"[SCRIPT-VALIDATOR] LLM validation unavailable: {e}")
        return False, f"Không thể chạy bộ kiểm duyệt LLM: {e}"

def check_purpose_rules(script: str, snapshot: dict, purpose: str) -> list[str]:
    """Deterministic rules for scripts whose words end up on product pages."""
    if purpose not in (PRODUCT_VIDEO, LIVE_AND_PRODUCT):
        return []
    sections = split_labeled_sections(script)
    if purpose == LIVE_AND_PRODUCT:
        if not any(label in PAGE_CUT_LABELS for label, _ in sections):
            # Unrecognised headers: keep the livestream alive; the product-page clip is
            # withheld downstream (missing_price_marker) instead of failing the whole show.
            return []
        page_copy = []
        for label, text in sections:
            if label in PAGE_CUT_LABELS:
                break
            page_copy.append(text)
        scope = " ".join(page_copy)
    else:
        scope = " ".join(text for _, text in sections)
    errors = find_price_violations(scope, snapshot.get("pricing"))
    if (purpose == PRODUCT_VIDEO and str(snapshot.get("ingredients") or "").strip()
            and not any(label == "ingredients" and text.strip() for label, text in sections)):
        errors.append("Thiếu phần [Ingredients] dù sản phẩm có dữ liệu thành phần")
    return errors


_PRICE_RULES = (
    "Chỉ được nói giá bằng đúng nguyên văn các cụm giá trong phần thông tin sản phẩm, không tự đổi số, không viết số hay ký hiệu tiền. "
    "Không có dòng Flash sale thì không được dùng các từ ưu đãi, khuyến mãi, sale, giảm, có hạn. "
    "Không bao giờ nói hôm nay, tối nay, ngày mai, livestream, chốt đơn, chỉ còn, duy nhất, voucher, mã giảm. "
    "Gọi giá thị trường là giá tham khảo thị trường, không gọi là giá gốc."
)


def _revision_structure(purpose: str) -> str:
    if purpose == PRODUCT_VIDEO:
        return ("CẤU TRÚC KỊCH BẢN BẮT BUỘC: Gồm 7 phần, mỗi phần mở đầu bằng một dòng tiêu đề riêng: "
                "[Hook], [Problem], [Product Intro], [Specs], [Ingredients], [How to Use], [Wrap-up]. "
                "Giá chỉ được nói ở [Product Intro] hoặc [Wrap-up]; [Wrap-up] mời bấm vào sản phẩm để xem giá mới nhất và đặt hàng. "
                + _PRICE_RULES)
    if purpose == LIVE_AND_PRODUCT:
        return ("CẤU TRÚC KỊCH BẢN BẮT BUỘC: Gồm 8 phần, mỗi phần mở đầu bằng một dòng tiêu đề riêng: "
                "[Opening], [Hook], [Product Intro], [Key Benefits], [How to Use], [Price], [CTA], [Closing]. "
                "Phần [Price] dùng đúng các cụm giá; ưu đãi riêng của livestream chỉ được nói ở [CTA]. "
                + _PRICE_RULES)
    return "CẤU TRÚC KỊCH BẢN BẮT BUỘC: Gồm 8 phần (Opening, Hook, Product Intro, Key Benefits, How to Use, Price, CTA, Closing)."


def validate_and_revise_script(script: str, snapshot: dict, knowledge: str, content_mode: str, config: dict,
                               purpose: str = LEGACY_PURPOSE) -> tuple[bool, str, str]:
    """Run validation check and trigger revision loop up to 3 times if violations are found."""
    current_script = sanitize_external_retailer_mentions(script)
    llm = LLMProvider()

    for attempt in range(1, 4):
        logger.info(f"[SCRIPT-VALIDATOR] Validation attempt {attempt}/3...")

        blacklist_err = check_blacklist(current_script) or "; ".join(check_purpose_rules(current_script, snapshot, purpose))
        if blacklist_err:
            logger.warning(f"[SCRIPT-VALIDATOR] Blacklist hit: {blacklist_err}")
            is_pass, feedback = False, blacklist_err
        else:
            is_pass, feedback = validate_script_via_llm(current_script, snapshot, purpose)
            
        if is_pass:
            logger.info("[SCRIPT-VALIDATOR] Script passed validation checks!")
            return True, current_script, ""
            
        logger.warning(f"[SCRIPT-VALIDATOR] Script failed validation. Feedback: {feedback}")
        
        # Revision Step: Ask LLM to rewrite script correcting the violations
        if attempt < 3:
            logger.info(f"[SCRIPT-VALIDATOR] Requesting LLM to revise script (Attempt {attempt+1})...")
            system_prompt = """
Bạn là một AI Idol/KOL bán hàng chuyên nghiệp. Bản thảo kịch bản trước đó của bạn đã bị từ chối do vi phạm quy chế quảng cáo mỹ phẩm. Nhiệm vụ của bạn là viết lại một kịch bản mới hoàn toàn tuân thủ, khắc phục toàn bộ các điểm vi phạm được chỉ ra trong phản hồi lỗi.

QUY TẮC PHÁT ÂM TTS (RẤT QUAN TRỌNG):
- Không dùng viết tắt, ký hiệu, hoặc số (ví dụ: viết "phần trăm" thay vì "%", "mililít" thay vì "ml", "năm trăm nghìn đồng" thay vì "500.000đ").
- Hoạt chất tiếng Anh viết phiên âm dễ đọc tiếng Việt hoặc viết tách rời chữ cái bằng gạch nối (ví dụ: viết "A H A" hoặc "A-H-A" thay vì "AHA", "B H A" thay vì "BHA", "Re-ti-nol" thay vì "Retinol", "Ni-a-ci-na-mi" thay vì "Niacinamide").
- Không viết các ký hiệu toán học như "+", "-", "x", viết bằng chữ: "cộng", "trừ", "nhân".

""" + _revision_structure(purpose) + "\n"
            headed = purpose in (PRODUCT_VIDEO, LIVE_AND_PRODUCT)
            price_line = (price_data_lines(snapshot) if headed
                          else f"- Giá bán: {snapshot.get('price')} đồng") + "\n"
            keep = ("giữ nguyên các dòng tiêu đề trong ngoặc vuông" if headed
                    else "giữ cấu trúc 8 phần liền mạch")
            user_prompt = f"""
BẢN THẢO BỊ LỖI TRƯỚC ĐÓ:
\"\"\"
{clip_text(current_script, 6000 if headed else 3200)}
\"\"\"

BÁO CÁO VI PHẠM TỪ VALIDATOR:
- Lỗi cần sửa: {feedback}

Không nhắc bất kỳ sàn thương mại điện tử hoặc nhà bán lẻ ngoài SkinSyntax.

THÔNG TIN SẢN PHẨM GỐC:
- Tên sản phẩm: {snapshot.get('name')}
- Thương hiệu: {snapshot.get('brand')}
{price_line}- Thành phần: {clip_text(snapshot.get('ingredients'), 900)}
- Công dụng thật: {clip_text(snapshot.get('description'), 1200)}
- Cách dùng: {clip_text(snapshot.get('usage'), 650)}

Hãy chỉnh sửa viết lại kịch bản bán hàng bằng tiếng Việt một cách tự nhiên, loại bỏ hoàn toàn các tuyên bố vi phạm trên, {keep} và không chứa các ký tự đặc biệt hay emoji.
"""
            try:
                current_script = sanitize_external_retailer_mentions(llm.invoke(system_prompt, user_prompt))
            except Exception as e:
                logger.error(f"[SCRIPT-VALIDATOR] LLM revision failed: {e}")
                return False, current_script, f"LLM revision crashed: {e}"
                
    return False, current_script, feedback
