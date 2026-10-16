import json
import re
from dataclasses import dataclass, asdict, field
from providers.llm_provider import LLMProvider
from config import MAX_SCRIPT_REPAIR_ATTEMPTS, SELF_HEALING_ENABLED, logger
from core.text_budget import clip_text
from core.purpose import LEGACY_PURPOSE, LIVE_AND_PRODUCT, PRODUCT_VIDEO
from core.script_text import (
    PAGE_CUT_LABELS, find_external_retailer_mentions, find_price_issues, find_price_violations, sanitize_external_retailer_mentions,
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
    system_prompt, user_prompt = _audit_prompts(script, snapshot, purpose)
    return _run_legacy_audit(llm, system_prompt, user_prompt)


_AUDIT_SYSTEM = """
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


def _audit_prompts(script: str, snapshot: dict, purpose: str, json_mode: bool = False) -> tuple[str, str]:
    system_prompt = _AUDIT_SYSTEM
    if json_mode:
        system_prompt = _AUDIT_SYSTEM.split("Nếu có vi phạm, hãy trả về")[0] + """Trả về DUY NHẤT một mảng JSON (không giải thích thêm), mảng rỗng [] nếu không vi phạm. Mỗi phần tử có dạng:
{"section": "tên phần hoặc null", "original_text": "đoạn vi phạm", "reason": "lý do", "auto_fixable": true}

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
    return system_prompt, user_prompt


def _run_legacy_audit(llm, system_prompt: str, user_prompt: str) -> tuple[bool, str]:
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
    """Deterministic rules for scripts whose words end up on product pages (price is checked in the whole script)."""
    if purpose not in (PRODUCT_VIDEO, LIVE_AND_PRODUCT):
        return []
    sections = split_labeled_sections(script)
    if purpose == LIVE_AND_PRODUCT and not any(label in PAGE_CUT_LABELS for label, _ in sections):
        # Unrecognised headers: keep the livestream alive; the product-page clip is
        # withheld downstream (missing_price_marker) instead of failing the whole show.
        return []
    errors = find_price_violations(script, snapshot.get("pricing"))
    if _missing_ingredients(sections, snapshot, purpose):
        errors.append("Thiếu phần [Ingredients] dù sản phẩm có dữ liệu thành phần")
    return errors


def _missing_ingredients(sections, snapshot: dict, purpose: str) -> bool:
    return (purpose == PRODUCT_VIDEO and bool(str(snapshot.get("ingredients") or "").strip())
            and not any(label == "ingredients" and text.strip() for label, text in sections))


_PRICE_RULES = (
    "Chỉ được nói giá bằng đúng nguyên văn các cụm giá trong phần thông tin sản phẩm, không tự đổi số, không viết số hay ký hiệu tiền. "
    "Có thể nhắc giá ở bất kỳ phần nào. "
    "Không có dòng Flash sale thì không được dùng các từ ưu đãi, khuyến mãi, sale, giảm, có hạn. "
    "Chỉ được dùng từ tạo khan hiếm (hôm nay, chỉ còn, sắp hết, duy nhất, số lượng có hạn) khi dữ liệu có Flash sale kèm hạn kết thúc. "
    "Gọi giá thị trường là giá tham khảo thị trường, không gọi là giá gốc."
)


def _revision_structure(purpose: str) -> str:
    if purpose == PRODUCT_VIDEO:
        return ("CẤU TRÚC KỊCH BẢN BẮT BUỘC: Gồm 7 phần, mỗi phần mở đầu bằng một dòng tiêu đề riêng: "
                "[Hook], [Problem], [Product Intro], [Specs], [Ingredients], [How to Use], [Wrap-up]. "
                "[Wrap-up] mời bấm vào sản phẩm để xem giá mới nhất và đặt hàng. "
                + _PRICE_RULES)
    if purpose == LIVE_AND_PRODUCT:
        return ("CẤU TRÚC KỊCH BẢN BẮT BUỘC: Gồm 8 phần, mỗi phần mở đầu bằng một dòng tiêu đề riêng: "
                "[Opening], [Hook], [Product Intro], [Key Benefits], [How to Use], [Price], [CTA], [Closing]. "
                "Phần [Price] dùng đúng các cụm giá. "
                + _PRICE_RULES)
    return "CẤU TRÚC KỊCH BẢN BẮT BUỘC: Gồm 8 phần (Opening, Hook, Product Intro, Key Benefits, How to Use, Price, CTA, Closing)."


HARD_FACT_ERROR = "HARD_FACT_ERROR"
SOFT_QUALITY = "SOFT_QUALITY"
TECHNICAL = "TECHNICAL"


@dataclass
class ValidationIssue:
    code: str
    category: str  # HARD_FACT_ERROR | SOFT_QUALITY | TECHNICAL
    severity: str  # error | warning
    section: str | None
    original_text: str
    reason: str
    auto_fixable: bool

    def to_dict(self) -> dict:
        return asdict(self)


_PERSONAL_EXPERIENCE = re.compile(
    r"\bmình\s+(?:đã\s+)?(?:dùng|xài|thử)\b|\bda\s+mình\b|\bmình\s+thấy\b[^.!?\n]*\bsau\s+khi\s+dùng\b",
    re.IGNORECASE,
)


def _deterministic_issues(script: str, snapshot: dict, purpose: str) -> list[ValidationIssue]:
    issues: list[ValidationIssue] = []
    sections = split_labeled_sections(script)
    for raw in find_price_issues(script, snapshot.get("pricing")):
        issues.append(ValidationIssue(raw["code"], HARD_FACT_ERROR, "error", raw["section"],
                                      raw["original_text"], raw["reason"], True))
    for mention in find_external_retailer_mentions(script):
        issues.append(ValidationIssue("EXTERNAL_RETAILER", HARD_FACT_ERROR, "error", None, mention,
                                      "Kịch bản chứa tên nhà bán lẻ ngoài hệ thống; chỉ được nhắc SkinSyntax.", True))
    for pattern in FORBIDDEN_PATTERNS:
        match = re.search(pattern, script, re.IGNORECASE)
        if match:
            issues.append(ValidationIssue("BLACKLIST_CLAIM", HARD_FACT_ERROR, "error", None, match.group(0),
                                          f"Vi phạm từ khóa cấm hoặc thổi phồng hiệu quả: '{pattern}'", True))
    for label, text in sections:
        match = _PERSONAL_EXPERIENCE.search(text)
        if match:
            issues.append(ValidationIssue("PERSONAL_EXPERIENCE", SOFT_QUALITY, "error", label or None, match.group(0),
                                          "Không được kể trải nghiệm cá nhân đã dùng sản phẩm", True))
    if _missing_ingredients(sections, snapshot, purpose):
        issues.append(ValidationIssue("MISSING_SECTION", SOFT_QUALITY, "error", "ingredients", "",
                                      "Thiếu phần [Ingredients] dù sản phẩm có dữ liệu thành phần", True))
    return issues


def _parse_audit(response: str) -> list[dict]:
    text = re.sub(r"^```(?:json)?|```$", "", str(response or "").strip(), flags=re.MULTILINE).strip()
    start, end = text.find("["), text.rfind("]")
    if start < 0 or end < start:
        raise ValueError("no JSON array")
    data = json.loads(text[start:end + 1])
    if not isinstance(data, list):
        raise ValueError("not a list")
    return [item for item in data if isinstance(item, dict)]


def _llm_audit_issues(script: str, snapshot: dict, purpose: str) -> list[ValidationIssue]:
    system_prompt, user_prompt = _audit_prompts(script, snapshot, purpose, json_mode=True)
    try:
        response = LLMProvider().invoke(system_prompt, user_prompt)
    except Exception as e:
        # Advertising safety must fail closed. An unavailable auditor is not approval.
        logger.error(f"[SCRIPT-VALIDATOR] LLM validation unavailable: {e}")
        return [ValidationIssue("VALIDATOR_UNAVAILABLE", TECHNICAL, "error", None, "",
                                f"Không thể chạy bộ kiểm duyệt LLM: {e}", False)]
    try:
        items = _parse_audit(response)
    except Exception:
        return [ValidationIssue("LLM_AUDIT", SOFT_QUALITY, "error", None, "", str(response or "").strip()[:500], True)]
    return [ValidationIssue("LLM_AUDIT", SOFT_QUALITY, "error", item.get("section") or None,
                            str(item.get("original_text") or ""),
                            str(item.get("reason") or "Vi phạm quy chế quảng cáo"),
                            bool(item.get("auto_fixable", True)))
            for item in items]


def validate_script(script: str, snapshot: dict, purpose: str = LEGACY_PURPOSE) -> list[ValidationIssue]:
    """Structured validation. The script passes when no returned issue has severity == "error"."""
    issues = _deterministic_issues(script, snapshot, purpose)
    if not any(i.severity == "error" for i in issues):
        issues.extend(_llm_audit_issues(script, snapshot, purpose))
    return issues


VALIDATOR_RETRIES = 3


@dataclass
class ScriptValidationResult:
    passed: bool
    script: str
    issues: list = field(default_factory=list)  # ValidationIssue list of the last validation
    history: list = field(default_factory=list)  # [{version, passed, issues, repair?}]
    repair_attempts: int = 0
    technical_failure: bool = False

    @property
    def feedback(self) -> str:
        return "; ".join(i.reason for i in self.issues if i.severity == "error")


def _validate_with_retries(script: str, snapshot: dict, purpose: str) -> list[ValidationIssue]:
    """Technical (validator unavailable) failures are retried, never sent to repair."""
    issues = validate_script(script, snapshot, purpose)
    for _ in range(VALIDATOR_RETRIES - 1):
        if not any(i.code == "VALIDATOR_UNAVAILABLE" for i in issues):
            break
        issues = validate_script(script, snapshot, purpose)
    return issues


def validate_and_repair_script(script: str, snapshot: dict, purpose: str = LEGACY_PURPOSE,
                               max_attempts: int | None = None, on_event=None) -> ScriptValidationResult:
    """validate -> repair failing sections -> revalidate, at most `max_attempts` repairs.

    `on_event(kind, version, script)` is called with kind "validating"/"repairing" for stage reporting.
    """
    from engines.script_repair import repair_script
    limit = MAX_SCRIPT_REPAIR_ATTEMPTS if max_attempts is None else max_attempts
    current = sanitize_external_retailer_mentions(script)
    result = ScriptValidationResult(False, current)
    version = 1
    while True:
        if on_event:
            on_event("validating", version, current)
        issues = _validate_with_retries(current, snapshot, purpose)
        errors = [i for i in issues if i.severity == "error"]
        entry = {"version": version, "text": current, "passed": not errors, "issues": [i.to_dict() for i in issues]}
        result.history.append(entry)
        result.script, result.issues = current, issues
        if not errors:
            result.passed = True
            return result
        if any(i.category == TECHNICAL for i in errors):
            result.technical_failure = True
            return result
        if result.repair_attempts >= limit:
            return result
        if on_event:
            on_event("repairing", version, current)
        result.repair_attempts += 1
        try:
            repaired = repair_script(current, [i for i in errors if i.auto_fixable] or errors, snapshot, purpose)
        except Exception as e:
            logger.error(f"[SCRIPT-VALIDATOR] repair failed: {e}")
            entry["repair"] = {"error": str(e)[:300]}
            return result
        entry["repair"] = {"to_version": version + 1, "issue_codes": [i.code for i in errors]}
        current = sanitize_external_retailer_mentions(repaired)
        version += 1


def validate_and_revise_script(script: str, snapshot: dict, knowledge: str, content_mode: str, config: dict,
                               purpose: str = LEGACY_PURPOSE) -> tuple[bool, str, str]:
    """Backward-compatible tuple wrapper: (passed, script, feedback)."""
    if SELF_HEALING_ENABLED:
        result = validate_and_repair_script(script, snapshot, purpose)
        return result.passed, result.script, ("" if result.passed else result.feedback)
    return _legacy_validate_and_revise(script, snapshot, knowledge, content_mode, config, purpose)


def _legacy_validate_and_revise(script: str, snapshot: dict, knowledge: str, content_mode: str, config: dict,
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
