"""Low-cost intent classification for the AI Idol Agent.

The classifier deliberately runs before the LLM parser.  It is deterministic,
cheap, and acts as a safety gate so a request that merely asks for products or
the status of a campaign can never create a new campaign accidentally.
"""

from __future__ import annotations

from dataclasses import asdict, dataclass, field
import re
from typing import Any

from agents.intent_parser import normalize_text


CREATE_CAMPAIGN = "CREATE_CAMPAIGN"
PRODUCT_SEARCH = "PRODUCT_SEARCH"
PREVIEW_CAMPAIGN = "PREVIEW_CAMPAIGN"
REVISE_SCRIPT = "REVISE_SCRIPT"
APPROVE_SCRIPT = "APPROVE_SCRIPT"
RETRY_FAILED = "RETRY_FAILED"
CANCEL_CAMPAIGN = "CANCEL_CAMPAIGN"
STATUS_QUERY = "STATUS_QUERY"
UNKNOWN = "UNKNOWN"


INTENT_LABELS = {
    CREATE_CAMPAIGN,
    PRODUCT_SEARCH,
    PREVIEW_CAMPAIGN,
    REVISE_SCRIPT,
    APPROVE_SCRIPT,
    RETRY_FAILED,
    CANCEL_CAMPAIGN,
    STATUS_QUERY,
    UNKNOWN,
}


@dataclass(frozen=True)
class IntentClassification:
    intent: str
    confidence: float
    reason: str
    entities: dict[str, Any] = field(default_factory=dict)
    requires_confirmation: bool = False
    next_step: str = ""

    def to_dict(self) -> dict[str, Any]:
        return asdict(self)


def _has_any(text: str, phrases: tuple[str, ...]) -> bool:
    return any(phrase in text for phrase in phrases)


def _entities(text: str) -> dict[str, Any]:
    return {
        "has_schedule": bool(re.search(r"\b(?:luc\s*)?\d{1,2}\s*(?:h|gio|phut)\b", text))
        or "lich phat" in text
        or "phat luc" in text,
        "has_product_reference": "san pham" in text
        or bool(re.search(r"\b(?:serum|kem|sua rua mat|toner|retinol|rau ma|vichy|cerave)\b", text)),
        "has_broadcast_channel": _has_any(text, ("youtube", "facebook", "noi bo", "livestream", "live stream")),
        "has_existing_campaign_reference": _has_any(
            text, ("chuong trinh", "campaign", "phien live", "san pham bi loi", "run agent")
        ),
    }


def classify_intent(brief_text: str) -> IntentClassification:
    """Classify one AI Idol command without spending an LLM request.

    Precedence matters: retry/cancel/edit commands must not be mistaken for a
    new campaign just because they contain words such as ``livestream``.
    """

    text = normalize_text(brief_text)
    entities = _entities(text)

    if _has_any(text, ("huy", "dung", "xoa lich", "xoa chuong trinh", "cancel")):
        return IntentClassification(
            CANCEL_CAMPAIGN, 0.98, "Có từ khóa hủy hoặc dừng chương trình.", entities,
            True, "Chọn chương trình cần hủy; chưa tạo chương trình mới.",
        )

    if _has_any(text, ("thu lai", "retry", "tao lai", "render lai", "chay lai", "bi loi")):
        return IntentClassification(
            RETRY_FAILED, 0.97, "Có từ khóa thử lại hoặc xử lý phần bị lỗi.", entities,
            True, "Chọn sản phẩm hoặc campaign lỗi rồi bấm Tạo lại phần bị lỗi.",
        )

    if _has_any(text, ("sua kich ban", "chinh kich ban", "sua loi thoai", "bien tap kich ban")):
        return IntentClassification(
            REVISE_SCRIPT, 0.97, "Yêu cầu chỉnh sửa nội dung kịch bản.", entities,
            True, "Mở bản nháp, chỉnh sửa rồi gửi duyệt lại.",
        )

    if _has_any(text, ("duyet kich ban", "chap nhan kich ban", "phe duyet", "dong y tao video")):
        return IntentClassification(
            APPROVE_SCRIPT, 0.97, "Yêu cầu duyệt hoặc chấp nhận kịch bản.", entities,
            True, "Mở kịch bản tương ứng và bấm Chấp nhận & tạo video.",
        )

    if _has_any(text, ("trang thai", "tien do", "dang tao", "dang phat", "xem tien do")):
        return IntentClassification(
            STATUS_QUERY, 0.96, "Yêu cầu xem trạng thái hoặc tiến độ.", entities,
            True, "Mở danh sách Agent hoặc chương trình để xem tiến độ.",
        )

    # A preview request for an existing campaign is not permission to create
    # a fresh campaign.  Creation cues below intentionally take precedence for
    # phrases such as "lên lịch ... chiếu nội bộ xem trước".
    create_cues = (
        "tao chuong trinh", "tao campaign", "tao livestream", "tao live",
        "livestream", "live stream", "len song", "phat luc", "lich phat",
        "quang ba", "tao video", "len lich", "gioi thieu san pham",
    )
    has_create_cue = _has_any(text, create_cues)
    if has_create_cue:
        return IntentClassification(
            CREATE_CAMPAIGN, 0.94, "Có hành động tạo/lên lịch/phát hoặc quảng bá livestream.", entities,
            False, "Tiếp tục trích xuất sản phẩm, kiểm tra chính sách rồi lập campaign.",
        )

    if _has_any(text, ("xem truoc", "preview", "xem video", "chieu noi bo")):
        return IntentClassification(
            PREVIEW_CAMPAIGN, 0.93, "Yêu cầu xem trước nội dung đã có.", entities,
            True, "Chọn campaign đã tạo để mở trình phát xem trước.",
        )

    if _has_any(text, ("tim san pham", "liet ke san pham", "goi y san pham", "cho toi san pham", "san pham nao")):
        return IntentClassification(
            PRODUCT_SEARCH, 0.88, "Yêu cầu tìm hoặc liệt kê sản phẩm, chưa có lệnh phát sóng.", entities,
            True, "Hiển thị sản phẩm phù hợp; chưa tạo kịch bản hay video.",
        )

    # A bare ingredient or product name is safer as a search than as a costly
    # render.  Unknown prose remains gated for manual clarification.
    if entities["has_product_reference"]:
        return IntentClassification(
            PRODUCT_SEARCH, 0.72, "Có nhắc sản phẩm nhưng chưa nói hành động livestream.", entities,
            True, "Hỏi người dùng muốn tìm sản phẩm hay tạo campaign.",
        )

    return IntentClassification(
        UNKNOWN, 0.20, "Chưa nhận diện được hành động AI Idol rõ ràng.", entities,
        True, "Yêu cầu người dùng nói rõ: tạo live, xem trước, sửa, duyệt, retry hoặc hủy.",
    )


__all__ = [
    "APPROVE_SCRIPT", "CANCEL_CAMPAIGN", "CREATE_CAMPAIGN", "IntentClassification",
    "INTENT_LABELS", "PREVIEW_CAMPAIGN", "PRODUCT_SEARCH", "RETRY_FAILED",
    "REVISE_SCRIPT", "STATUS_QUERY", "UNKNOWN", "classify_intent",
]
