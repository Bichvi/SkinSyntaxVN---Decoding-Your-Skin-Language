"""LLM drafts for the live clip library: product clips, frame clips and the routine recap."""
import logging

from core.show_plan import bundle_total, bundle_total_phrase
from core.show_types import ClipDraft
from engines.content_agent import _product_source_lines, price_lines
from engines.syna_persona import PERSONA_RULES_VI, SHOP_FACTS_VI
from providers.llm_provider import LLMProvider

log = logging.getLogger(__name__)

SECTION_LABELS = ("Lead-in", "Pain Point", "Why", "Solution", "Feel", "How to Use", "Price", "CTA")
SECTION_KEYS = ("lead_in", "pain_point", "why", "solution", "feel", "how_to_use", "price", "cta")
INTERLUDE_TYPES = ("cta", "about_shop", "fun")
FAQ_COUNT = 4

# Word budgets: about 4 words per second of the clip length.
PRODUCT_WORDS = (400, 560)
INTERLUDE_WORDS = (40, 80)
OPENING_WORDS = (240, 480)
TRANSITION_WORDS = (80, 120)
FAQ_WORDS = (80, 160)
TIP_WORDS = (240, 360)
RECAP_WORDS = (240, 360)
CLOSING_WORDS = (80, 120)

_TEASER = "Rồi, món tiếp theo cũng đỉnh không kém nè!"

_NO_TIME_WORDS = (
    "Cấm các cụm \"hôm nay\", \"tối nay\", \"ngay bây giờ\" và mọi từ khan hiếm hay hạn chót "
    "(\"chỉ còn\", \"sắp hết\", \"nhanh tay\", \"duy nhất\") trừ khi dữ liệu giá có dòng flash sale."
)

_SELF_CONTAINED = (
    "Mỗi clip phải tự hiểu được khi nghe riêng lẻ: không nói \"như Syna vừa nói\", \"như lúc nãy\", "
    "\"ở trên\", \"tiếp theo phần trước\"."
)

_PRODUCT_SECTION_TABLE = """\
BẢNG CÁC PHẦN (đúng thứ tự, mỗi phần một khóa riêng):
- lead_in [Lead-in]: dẫn vào bước routine bằng TÊN BƯỚC ("Sạch rồi thì tới bước cấp cứu nền da nè..."). Không nhắc tên sản phẩm khác, không nói số thứ tự.
- pain_point [Pain Point]: bắt bệnh, thói quen sai hoặc nỗi khổ cụ thể, có câu hỏi tu từ kiểu "Có bà nào... không?".
- why [Why]: ví von đời thường giải thích vì sao.
- solution [Solution]: thành phần và công dụng lấy từ dữ liệu thật.
- feel [Feel]: cảm giác khi dùng, CHỈ theo mô tả hoặc cách dùng trong dữ liệu; nếu dữ liệu không nhắc thì viết ngắn hoặc nói về cách dùng.
- how_to_use [How to Use]: cách dùng lấy từ dữ liệu.
- price [Price]: đọc đúng nguyên văn cụm giá chuẩn trong các dòng giá, ngay sau đó đệm "..., tức là <X> cành thôi quý dị!" với <X> là phần số trước chữ "nghìn" của chính cụm đó. Ưu đãi chỉ khi có dòng flash sale.
- cta [CTA]: giục chốt đơn: "bấm giỏ hàng, chọn món số {number}...", mời comment hoặc thả tim."""


def _schema(properties: dict) -> dict:
    return {
        "type": "object",
        "properties": properties,
        "required": list(properties),
        "additionalProperties": False,
    }


_TEXT = {"type": "string"}


def _text_list(count: int) -> dict:
    return {"type": "array", "items": _TEXT, "minItems": count, "maxItems": count}


def _call(llm, system: str, user: str, schema: dict, name: str) -> dict:
    llm = llm or LLMProvider()
    result = llm.invoke_json_schema(system, user, schema=schema, schema_name=name)
    if not isinstance(result, dict) or not result:
        raise ValueError(f"LLM trả về JSON không hợp lệ cho {name}")
    return result


def _need_text(value, where: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise ValueError(f"Thiếu nội dung: {where}")
    return value.strip()


def _need_list(value, count: int, where: str) -> list[str]:
    if not isinstance(value, list) or len(value) != count:
        raise ValueError(f"Cần đúng {count} đoạn: {where}")
    return [_need_text(item, where) for item in value]


def _system(extra: str) -> str:
    return f"{PERSONA_RULES_VI}\n{extra}"


def _word_range(words: tuple[int, int]) -> str:
    return f"{words[0]}-{words[1]} chữ"


# ---------------------------------------------------------------- product clips

def build_product_prompts(snapshot: dict, item: dict, plan: dict) -> tuple[str, str]:
    system = _system(
        "Bạn viết kịch bản đọc cho clip sản phẩm trong một buổi live bán mỹ phẩm. Chỉ dùng thông tin trong dữ liệu "
        "sản phẩm được cung cấp, không bịa thành phần, công dụng, giá hay ưu đãi. Trả về JSON đúng schema."
    )
    conflicts = [c for c in (plan.get("conflicts") or [])
                 if str(item.get("product_id")) in {str(c.get("a")), str(c.get("b"))}]
    conflict_lines = "\n".join(f"- {c.get('message_vi')}" for c in conflicts) or "- Không có cảnh báo kết hợp."
    number = item.get("number")
    user = f"""\
DỮ LIỆU SẢN PHẨM:
{_product_source_lines(snapshot, with_price=True, ingredients=1200, description=1600, usage=800)}
- Bước routine: {item.get('step_label')}
- Chủ đề buổi: {plan.get('theme_vi') or 'chăm da từng bước'}
CẢNH BÁO KẾT HỢP (chỉ dùng cho góc B):
{conflict_lines}

NHIỆM VỤ: viết 2 góc A và B cho CÙNG sản phẩm này, mỗi góc đủ 8 phần, tổng mỗi góc {_word_range(PRODUCT_WORDS)} (đọc 100-140 giây).
- Góc A xoáy vào nỗi đau chính và thành phần.
- Góc B xoáy vào hợp với ai, dùng ở bước nào, lưu ý kết hợp với sản phẩm khác.
- Hai góc phải khác nhau rõ: khác ví von, khác câu bắt bệnh.

{_PRODUCT_SECTION_TABLE.format(number=number)}

LUẬT:
- Các phần từ lead_in đến how_to_use phải "dùng lâu dài được": KHÔNG giá, KHÔNG nhắc "live", "livestream", "hôm nay", "comment", "bình luận", "thả tim".
- "món số {number}" CHỈ được nói trong cta, không nói ở phần khác.
- {_NO_TIME_WORDS}
- {_SELF_CONTAINED}
- Không chào hỏi ("xin chào", "chào cả nhà") và không tạm biệt: đây không phải mở màn hay kết thúc.
- Câu ngắn, dễ đọc thành tiếng; không dùng ký hiệu, emoji hay gạch đầu dòng."""
    return system, user


def _product_schema() -> dict:
    angle = _schema({key: _TEXT for key in SECTION_KEYS})
    return _schema({"angle_a": angle, "angle_b": angle})


def _join_sections(angle, where: str) -> str:
    if not isinstance(angle, dict):
        raise ValueError(f"Thiếu góc: {where}")
    blocks = [f"[{label}]\n{_need_text(angle.get(key), f'{where}/{key}')}"
              for label, key in zip(SECTION_LABELS, SECTION_KEYS)]
    return "\n\n".join(blocks)


def write_product_clips(snapshot: dict, item: dict, plan: dict, *, llm=None) -> list[ClipDraft]:
    system, user = build_product_prompts(snapshot, item, plan)
    data = _call(llm, system, user, _product_schema(), "product_clips")
    product_id = str(item.get("product_id") or snapshot.get("id"))
    return [
        ClipDraft(kind="product", script=_join_sections(data.get(key), key), product_id=product_id, angle=angle)
        for key, angle in (("angle_a", "A"), ("angle_b", "B"))
    ]


# ---------------------------------------------------------------- frame clips

def _lineup_steps(plan: dict) -> str:
    return ", ".join(str(p.get("step_label")) for p in plan.get("products", [])) or "chăm da từng bước"


def _faq_block(faqs: list[dict], snapshots: dict[str, dict]) -> str:
    lines = []
    for index, faq in enumerate(faqs[:FAQ_COUNT], 1):
        name = (snapshots.get(str(faq.get("product_id"))) or {}).get("name", "")
        lines.append(f"{index}. product_id={faq.get('product_id')} ({name})\n   Câu hỏi: {faq.get('question')}\n"
                     f"   Trả lời gốc: {faq.get('answer')}")
    return "\n".join(lines) or "(chưa có câu hỏi thật nào)"


def _product_facts(plan: dict, snapshots: dict[str, dict]) -> str:
    blocks = []
    for item in plan.get("products", []):
        snap = snapshots.get(str(item.get("product_id"))) or {}
        blocks.append(f"[product_id={item.get('product_id')}] bước {item.get('step_label')}\n"
                      f"{_product_source_lines(snap, with_price=False, ingredients=500, description=500, usage=300)}")
    return "\n\n".join(blocks)


def build_frame_prompts(plan: dict, snapshots: dict[str, dict], faqs: list[dict]) -> tuple[str, str]:
    system = _system(
        "Bạn viết các clip khung (mở màn, chuyển cảnh, đoạn nghỉ, hỏi đáp, mẹo da, kết thúc) cho một buổi live bán "
        "mỹ phẩm của Syna. Chỉ dùng thông tin được cung cấp. Trả về JSON đúng schema."
    )
    missing = FAQ_COUNT - len(faqs[:FAQ_COUNT])
    conflicts = "\n".join(f"- {c.get('message_vi')}" for c in plan.get("conflicts") or []) or "- Không có."
    theme = plan.get("theme_vi") or "(chưa có, hãy đặt một chủ đề ngắn gọn cho buổi, ghi vào theme_vi)"
    user = f"""\
CHỦ ĐỀ BUỔI: {theme}
CÁC BƯỚC ROUTINE CỦA BUỔI (theo thứ tự): {_lineup_steps(plan)}

SẢN PHẨM TRONG BUỔI (không có giá, vì clip khung không nói giá):
{_product_facts(plan, snapshots)}

CẢNH BÁO KẾT HỢP:
{conflicts}

CÂU HỎI THẬT TỪ KHÁCH (đã được shop trả lời):
{_faq_block(faqs, snapshots)}

NHIỆM VỤ: viết đủ các clip sau và điền theme_vi (chủ đề buổi, một câu ngắn).
1. opening (1 clip, {_word_range(OPENING_WORDS)}, 60-120 giây): chào, kiểm tra tín hiệu ("nghe rõ giọng Syna chưa"), miếng hài "AI không có da mà đi bán mỹ phẩm", nêu chủ đề và các BƯỚC routine của buổi (chỉ tên bước, KHÔNG đọc tên sản phẩm), mời ở lại và thả tim.
2. transitions (2 clip, mỗi clip {_word_range(TRANSITION_WORDS)}, 20-30 giây): chào người mới vào phòng, nhắc chủ đề, "Syna điểm lại từ bước đầu nhé". Hai clip khác nhau.
3. interludes (6 clip, mỗi clip {_word_range(INTERLUDE_WORDS)}, 10-20 giây): 2 clip kiểu cta, 2 clip kiểu about_shop, 2 clip kiểu fun.
   - cta: nhắc bấm giỏ hàng, xem lại món vừa nói (không nêu tên món).
   - about_shop: nói MỘT điều trong danh sách này, không thêm cam kết nào khác: {'; '.join(SHOP_FACTS_VI)}.
   - fun: câu đùa hoặc câu hỏi vui về chăm da, không bịa sự thật.
   - MỖI đoạn kết bằng đúng một câu nhá chung: "{_TEASER}". KHÔNG nêu tên hay bước của món sau, vì đoạn nghỉ có thể đứng ở nhiều chỗ.
4. faqs ({FAQ_COUNT} clip, mỗi clip {_word_range(FAQ_WORDS)}, 20-40 giây), mỗi clip gắn product_id lấy từ danh sách sản phẩm ở trên:
   - Với mỗi câu hỏi thật ở trên: Syna đọc theo kiểu "có bạn hỏi..." và diễn đạt lại câu trả lời gốc, KHÔNG thêm ý.
   - Còn thiếu {missing} clip: tự sinh câu hỏi và trả lời từ dữ liệu sản phẩm và cảnh báo kết hợp.
5. tips (2 clip, mỗi clip {_word_range(TIP_WORDS)}, 60-90 giây): kiến thức da theo chủ đề, giọng Syna. KHÔNG bán hàng, KHÔNG tên sản phẩm hay thương hiệu, KHÔNG giá.
6. closing (1 clip, {_word_range(CLOSING_WORDS)}, 20-30 giây): cảm ơn, nhắc kiểm tra giỏ hàng đã bấm đặt chưa, tạm biệt, hẹn phiên sau. Không nêu số giá. Không nói "hết live là hết giá".

LUẬT CHUNG:
- Không clip nào nêu số tiền hay cụm giá.
- Chỉ opening và transitions được chào hỏi. Chỉ closing được tạm biệt. Các clip khác không chào, không tạm biệt.
- {_SELF_CONTAINED}
- {_NO_TIME_WORDS}
- Văn nói tự nhiên, câu ngắn; không ký hiệu, emoji hay gạch đầu dòng."""
    return system, user


def _frame_schema() -> dict:
    interludes = _schema({kind: _text_list(2) for kind in INTERLUDE_TYPES})
    faq = _schema({"product_id": _TEXT, "text": _TEXT})
    return _schema({
        "theme_vi": _TEXT,
        "opening": _TEXT,
        "transitions": _text_list(2),
        "interludes": interludes,
        "faqs": {"type": "array", "items": faq, "minItems": FAQ_COUNT, "maxItems": FAQ_COUNT},
        "tips": _text_list(2),
        "closing": _TEXT,
    })


def write_frame_clips(plan: dict, snapshots: dict[str, dict], faqs: list[dict], *,
                      llm=None) -> tuple[str, list[ClipDraft]]:
    system, user = build_frame_prompts(plan, snapshots, faqs)
    data = _call(llm, system, user, _frame_schema(), "frame_clips")
    theme = str(data.get("theme_vi") or plan.get("theme_vi") or "").strip()
    if not theme:
        raise ValueError("Thiếu chủ đề buổi (theme_vi)")

    drafts = [ClipDraft(kind="opening", script=_need_text(data.get("opening"), "opening"))]
    for index, text in enumerate(_need_list(data.get("transitions"), 2, "transitions")):
        drafts.append(ClipDraft(kind="transition", script=text, variant=index))
    interludes = data.get("interludes")
    if not isinstance(interludes, dict):
        raise ValueError("Thiếu đoạn nghỉ (interludes)")
    for kind in INTERLUDE_TYPES:
        for index, text in enumerate(_need_list(interludes.get(kind), 2, f"interludes/{kind}")):
            drafts.append(ClipDraft(kind="interlude", script=text, interlude_type=kind, variant=index))

    faq_items = data.get("faqs")
    if not isinstance(faq_items, list) or len(faq_items) != FAQ_COUNT:
        raise ValueError(f"Cần đúng {FAQ_COUNT} clip hỏi đáp")
    known = [str(p.get("product_id")) for p in plan.get("products", [])]
    for index, entry in enumerate(faq_items):
        if not isinstance(entry, dict):
            raise ValueError("Clip hỏi đáp không hợp lệ")
        product_id = str(entry.get("product_id") or "")
        if product_id not in known:
            product_id = known[0] if known else None
        drafts.append(ClipDraft(kind="faq", script=_need_text(entry.get("text"), "faqs"),
                                product_id=product_id, variant=index))

    for index, text in enumerate(_need_list(data.get("tips"), 2, "tips")):
        drafts.append(ClipDraft(kind="tip", script=text, variant=index))
    drafts.append(ClipDraft(kind="closing", script=_need_text(data.get("closing"), "closing")))
    return theme, drafts


# ---------------------------------------------------------------- routine recap

def _recap_lines(plan: dict, snapshots: dict[str, dict]) -> str:
    blocks = []
    for item in plan.get("products", []):
        snap = snapshots.get(str(item.get("product_id"))) or {}
        blocks.append(
            f"- món số {item.get('number')}: {item.get('step_label')} | {snap.get('name')} ({snap.get('brand')})\n"
            f"  Công dụng: {str(snap.get('description') or '')[:300]}\n"
            f"{price_lines(snap.get('pricing'))}"
        )
    return "\n".join(blocks)


def recap_total_phrase(plan: dict, snapshots: dict[str, dict]) -> str:
    pricings = [(snapshots.get(str(p.get("product_id"))) or {}).get("pricing") for p in plan.get("products", [])]
    return bundle_total_phrase(bundle_total(pricings))


def build_recap_prompts(plan: dict, snapshots: dict[str, dict]) -> tuple[str, str]:
    system = _system(
        "Bạn viết clip tóm tắt routine và giá cuối buổi live của Syna. Chỉ dùng các món, số thứ tự và giá được "
        "cung cấp. Trả về JSON đúng schema."
    )
    total = recap_total_phrase(plan, snapshots)
    conflicts = "\n".join(f"- {c.get('message_vi')}" for c in plan.get("conflicts") or []) or "- Không có."
    user = f"""\
CHỦ ĐỀ BUỔI: {plan.get('theme_vi') or 'chăm da từng bước'}

CÁC MÓN CÒN LẠI (giữ nguyên số thứ tự, theo thứ tự dùng):
{_recap_lines(plan, snapshots)}

CẢNH BÁO XUNG ĐỘT:
{conflicts}

CỤM BẮT BUỘC ĐỌC NGUYÊN VĂN (không đổi chữ, không tự cộng lại): "{total}"

NHIỆM VỤ: viết MỘT clip tóm tắt routine, {_word_range(RECAP_WORDS)} (60-90 giây).
- Điểm lại từng món theo số ("món số N"), nêu bước và MỘT câu công dụng, kèm giá đọc đúng nguyên văn cụm giá chuẩn trong các dòng giá; sau cụm chuẩn có thể đệm "tức là <X> cành" với <X> là phần số trước chữ "nghìn".
- Chỉ nhắc đúng các số đã liệt kê, không thêm món khác, không đánh số lại.
- Đọc tổng cả bộ bằng cụm bắt buộc ở trên.
- Nhắc thứ tự dùng sáng/tối và cảnh báo xung đột nếu có. Kết bằng giục bấm giỏ hàng.
- Không chào hỏi, không tạm biệt.
- {_NO_TIME_WORDS}
- {_SELF_CONTAINED}
- Văn nói tự nhiên; không ký hiệu, emoji hay gạch đầu dòng."""
    return system, user


def write_routine_recap(plan: dict, snapshots: dict[str, dict], *, llm=None) -> ClipDraft:
    system, user = build_recap_prompts(plan, snapshots)
    data = _call(llm, system, user, _schema({"text": _TEXT}), "routine_recap")
    return ClipDraft(kind="routine_recap", script=_need_text(data.get("text"), "routine_recap"))


# ---------------------------------------------------------------- real questions

def load_answered_faqs(db, product_ids: list[str], limit: int = FAQ_COUNT) -> list[dict]:
    """Answered customer questions, newest first. ma_san_pham may be stored as str or int."""
    ids: list = []
    for pid in product_ids:
        ids.append(str(pid))
        if str(pid).isdigit():
            ids.append(int(pid))
    if not ids:
        return []
    rows = db.hoi_dap_san_pham.find({
        "ma_san_pham": {"$in": ids},
        "tra_loi.noi_dung": {"$exists": True, "$ne": ""},
    }).sort("ngay_hoi", -1)
    result = []
    for row in rows:
        answer = str((row.get("tra_loi") or {}).get("noi_dung") or "").strip()
        question = str(row.get("cau_hoi") or "").strip()
        if not answer or not question or row.get("trang_thai", "hien_thi") != "hien_thi":
            continue
        result.append({"product_id": str(row.get("ma_san_pham")), "question": question, "answer": answer})
        if len(result) >= limit:
            break
    return result
