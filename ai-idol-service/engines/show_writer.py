"""LLM drafts for the live clip library: product clips, frame clips and the routine recap.

Every call is small: the provider keeps only ~8000 prompt chars and a short completion cap, so product clips
make one call per angle and frame clips make four calls. Product facts shrink first; rules never do.
"""
import logging
import re

from config import GROQ_MAX_PROMPT_CHARS
from core.show_plan import bundle_total, bundle_total_phrase
from core.show_types import ClipDraft
from core.text_budget import clean_prompt_text
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
    "(\"chỉ còn\", \"sắp hết\", \"nhanh tay\", \"duy nhất\", \"chốt đơn\") trừ khi thông tin giá có dòng flash sale."
)

# Never allowed, flash sale or not.
_HONESTY_ABSOLUTE = (
    "Không bịa số người xem, người mua hay đơn hàng (\"500 bà đã chốt\", \"vừa có người mua\", "
    "\"ba trăm người đang xem\"), không bịa suất, quà tặng, \"mở khóa\" hay điều kiện kiểu \"đủ 5.000 tim\", "
    "không nói \"hết live là hết giá\"; luật này áp dụng cả khi có flash sale."
)
# Allowed only with a flash sale line.
_HONESTY_FLASH = (
    "Chỉ được nói \"voucher\", \"trợ giá\", giảm giá hay hạn chót của ưu đãi khi thông tin giá có dòng flash sale."
)
MAX_CONFLICTS = 5
CONFLICT_CHARS = 160

_NO_NUMBERS = "KHÔNG nói \"món số N\" hay bất kỳ số thứ tự sản phẩm nào."

_SELF_CONTAINED = (
    "Mỗi clip phải tự hiểu được khi nghe riêng lẻ: không nói \"như Syna vừa nói\", \"như lúc nãy\", "
    "\"ở trên\", \"tiếp theo phần trước\"."
)

_PRODUCT_SECTION_TABLE = """\
BẢNG CÁC PHẦN (đúng thứ tự, mỗi phần một khóa riêng):
- lead_in [Lead-in]: dẫn vào bước routine bằng TÊN BƯỚC ("Sạch rồi thì tới bước cấp cứu nền da nè..."). Không nhắc tên sản phẩm khác, không nói số thứ tự.
- pain_point [Pain Point]: bắt bệnh, thói quen sai hoặc nỗi khổ cụ thể, có câu hỏi tu từ kiểu "Có bà nào... không?".
- why [Why]: ví von đời thường giải thích vì sao.
- solution [Solution]: thành phần và công dụng lấy từ thông tin sản phẩm thật.
- feel [Feel]: cảm giác khi dùng, CHỈ theo mô tả hoặc cách dùng trong thông tin sản phẩm; nếu không nhắc thì viết ngắn hoặc nói về cách dùng.
- how_to_use [How to Use]: cách dùng lấy từ thông tin sản phẩm.
- price [Price]: đọc đúng nguyên văn cụm giá chuẩn trong các dòng giá; nếu cụm giá có chữ "nghìn" thì ngay sau đó đệm "..., tức là <X> cành thôi quý dị!" với <X> là phần số trước chữ "nghìn" của chính cụm đó (không có chữ "nghìn" thì không đệm). Ưu đãi chỉ khi có dòng flash sale.
- cta [CTA]: giục đặt mua: "bấm giỏ hàng, chọn món số {number}...", mời comment hoặc thả tim."""


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


def _call(llm, system: str, user: str, schema: dict, name: str, max_tokens: int | None = None) -> dict:
    llm = llm or LLMProvider()
    result = llm.invoke_json_schema(system, user, schema=schema, schema_name=name,
                                    max_completion_tokens=max_tokens)
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


SHORT_RATIO = 0.85  # a draft under 85% of its kind's minimum words is asked again once


def _spoken_words(text) -> int:
    """Whitespace word count of the spoken text; [Label] header lines are not spoken."""
    if not isinstance(text, str):
        return 0
    lines = [ln for ln in text.splitlines() if not re.fullmatch(r"\s*\[[^\]]+\]\s*", ln)]
    return len(" ".join(lines).split())


def _feedback_line(short: list) -> str:
    parts = "; ".join(f"{label} có {count} chữ, cần {words[0]}-{words[1]} chữ" for label, count, words in short)
    return f"Bản trước quá ngắn: {parts}. Viết lại đầy đủ, giữ nguyên mọi quy tắc."


def _task_feedback(feedback: str) -> str:
    return f"\n{feedback}" if feedback else ""


def _is_short(count: int, words: tuple[int, int]) -> bool:
    return count < SHORT_RATIO * words[0]


def _call_sized(llm, build, schema: dict, name: str, max_tokens: int, measure) -> dict:
    """One LLM call; if a draft is far under its word range, re-ask once with feedback and keep the longer answer.

    build(feedback) -> (system, user); measure(data) -> [(label, spoken_words, (min, max))].
    A short clip is better than no show, so length never raises.
    """
    system, user = build("")
    data = _call(llm, system, user, schema, name, max_tokens)
    measured = measure(data)
    short = [item for item in measured if _is_short(item[1], item[2])]
    if not short:
        return data
    system, user = build(_feedback_line(short))
    try:
        retry = _call(llm, system, user, schema, name, max_tokens)
    except Exception as exc:  # the first answer is still usable
        log.warning("[SHOW-WRITER] %s: retry for length failed (%s); keeping the short draft", name, exc)
        return data
    retry_measured = measure(retry)
    still_short = [item for item in retry_measured if _is_short(item[1], item[2])]
    if still_short:
        log.warning("[SHOW-WRITER] %s still short after retry: %s", name,
                    ", ".join(f"{label}={count}/{words[0]}" for label, count, words in still_short))
    return retry if sum(i[1] for i in retry_measured) > sum(i[1] for i in measured) else data


def _system(extra: str) -> str:
    return f"{PERSONA_RULES_VI}\n{extra}"


def _word_range(words: tuple[int, int]) -> str:
    return f"{words[0]}-{words[1]} chữ"


def _tokens(*word_maxes: int) -> int:
    """Completion budget: about 2 tokens per word, 25% headroom, plus JSON overhead."""
    return int(sum(word_maxes) * 2.5) + 150


PROMPT_BUDGET = GROQ_MAX_PROMPT_CHARS - 200

_FACT_LADDER = ((1200, 1600, 800), (700, 900, 400), (300, 300, 200), (120, 120, 80), (0, 0, 0))
# Last value: answer/question clip for real customer questions; only the final rung shrinks them.
_MULTI_FACT_LADDER = ((300, 300, 150, 400), (150, 150, 80, 400), (60, 60, 40, 400), (0, 0, 0, 400), (0, 0, 0, 150))
_RECAP_LADDER = ((300,), (120,), (0,))


def prompt_len(system: str, user: str) -> int:
    return len(clean_prompt_text(system)) + len(clean_prompt_text(user))


def _fit_user(system: str, ladder, make_user) -> str:
    """First rung whose system+user fits the provider budget; only product facts shrink, never the rules."""
    user = ""
    for limits in ladder:
        user = make_user(*limits)
        if prompt_len(system, user) <= PROMPT_BUDGET:
            return user
    log.warning("[SHOW-WRITER] Prompt is %d chars, over the %d budget even with no product facts",
                prompt_len(system, user), PROMPT_BUDGET)
    return user


def _conflict_lines(conflicts, empty: str) -> str:
    """At most MAX_CONFLICTS warnings, each clipped, so a long list never pushes the rules out of the prompt."""
    messages = [str(c.get("message_vi") or "")[:CONFLICT_CHARS] for c in list(conflicts or [])[:MAX_CONFLICTS]]
    return "\n".join(f"- {m}" for m in messages) or empty


# ---------------------------------------------------------------- product clips

def build_product_prompts(snapshot: dict, item: dict, plan: dict, angle: str = "A",
                          other: dict | None = None, feedback: str = "") -> tuple[str, str]:
    system = _system(
        "Bạn viết kịch bản đọc cho clip sản phẩm trong một buổi live bán mỹ phẩm. Chỉ dùng thông tin sản phẩm "
        "được cung cấp, không bịa thành phần, công dụng, giá hay ưu đãi. Trả về JSON đúng schema."
    )
    conflicts = [c for c in (plan.get("conflicts") or [])
                 if str(item.get("product_id")) in {str(c.get("a")), str(c.get("b"))}]
    conflict_lines = _conflict_lines(conflicts, "- Không có cảnh báo kết hợp.")
    number = item.get("number")
    avoid = ""
    if angle == "A":
        focus = "Góc A: xoáy vào nỗi đau chính và thành phần."
    else:
        focus = "Góc B: xoáy vào hợp với ai, dùng ở bước nào, lưu ý kết hợp với sản phẩm khác (xem cảnh báo kết hợp)."
        if other:
            avoid = (f"\nGóc A đã dùng câu bắt bệnh \"{str(other.get('pain_point'))[:200]}\" và ví von "
                     f"\"{str(other.get('why'))[:200]}\"; góc B phải khác rõ cả hai.")

    task_feedback = _task_feedback(feedback)

    def make_user(ing, desc, usage):
        facts = _product_source_lines(snapshot, with_price=True, ingredients=ing, description=desc, usage=usage)
        return f"""\
THÔNG TIN SẢN PHẨM:
{facts}
- Bước routine: {item.get('step_label')}
- Chủ đề buổi: {plan.get('theme_vi') or 'chăm da từng bước'}
CẢNH BÁO KẾT HỢP (chỉ dùng cho góc B):
{conflict_lines}

NHIỆM VỤ: viết góc {angle} cho sản phẩm này, đủ 8 phần, tổng {_word_range(PRODUCT_WORDS)} (đọc 100-140 giây).
{focus}{avoid}{task_feedback}

{_PRODUCT_SECTION_TABLE.format(number=number)}

LUẬT:
- Các phần từ lead_in đến how_to_use phải "dùng lâu dài được": KHÔNG giá, KHÔNG nhắc "live", "livestream", "hôm nay", "comment", "bình luận", "thả tim".
- "món số {number}" CHỈ được nói trong cta, không nói ở phần khác.
- {_NO_TIME_WORDS}
- {_HONESTY_ABSOLUTE}
- {_HONESTY_FLASH}
- {_SELF_CONTAINED}
- Không chào hỏi ("xin chào", "chào cả nhà") và không tạm biệt: đây không phải mở màn hay kết thúc.
- Câu ngắn, dễ đọc thành tiếng; không dùng ký hiệu, emoji hay gạch đầu dòng."""

    return system, _fit_user(system, _FACT_LADDER, make_user)


def _angle_schema() -> dict:
    return _schema({key: _TEXT for key in SECTION_KEYS})


def _join_sections(angle, where: str) -> str:
    if not isinstance(angle, dict):
        raise ValueError(f"Thiếu góc: {where}")
    blocks = [f"[{label}]\n{_need_text(angle.get(key), f'{where}/{key}')}"
              for label, key in zip(SECTION_LABELS, SECTION_KEYS)]
    return "\n\n".join(blocks)


def _measure_product(data, angle: str) -> list:
    if not isinstance(data, dict):
        return []
    return [(f"góc {angle}", sum(_spoken_words(data.get(key)) for key in SECTION_KEYS), PRODUCT_WORDS)]


def write_product_clips(snapshot: dict, item: dict, plan: dict, *, llm=None) -> list[ClipDraft]:
    raw_id = item.get("product_id") or snapshot.get("id")
    if raw_id in (None, ""):
        raise ValueError("Thiếu product_id cho clip sản phẩm")
    product_id = str(raw_id)
    llm = llm or LLMProvider()
    drafts = []
    other = None
    for angle in ("A", "B"):
        data = _call_sized(
            llm, lambda fb, angle=angle, other=other: build_product_prompts(snapshot, item, plan, angle, other, fb),
            _angle_schema(), f"product_clip_{angle.lower()}", _tokens(PRODUCT_WORDS[1]),
            lambda d, angle=angle: _measure_product(d, angle))
        script = _join_sections(data, f"angle_{angle}")
        drafts.append(ClipDraft(kind="product", script=script, product_id=product_id, angle=angle))
        other = data
    return drafts


# ---------------------------------------------------------------- frame clips

def _lineup_steps(plan: dict) -> str:
    return ", ".join(str(p.get("step_label")) for p in plan.get("products", [])) or "chăm da từng bước"


def _frame_system() -> str:
    return _system(
        "Bạn viết các clip khung (mở màn, chuyển cảnh, đoạn nghỉ, hỏi đáp, mẹo da, kết thúc) cho một buổi live bán "
        "mỹ phẩm của Syna. Chỉ dùng thông tin được cung cấp. Trả về JSON đúng schema."
    )


def _theme_hint(theme: str) -> str:
    return theme or "(chưa có, hãy đặt một chủ đề ngắn gọn cho buổi, ghi vào theme_vi)"


def _frame_context(plan: dict, theme: str) -> str:
    conflicts = _conflict_lines(plan.get("conflicts"), "- Không có.")
    return (f"CHỦ ĐỀ BUỔI: {_theme_hint(theme)}\n"
            f"CÁC BƯỚC ROUTINE CỦA BUỔI (theo thứ tự): {_lineup_steps(plan)}\n"
            f"CẢNH BÁO KẾT HỢP:\n{conflicts}")


def _frame_rules(*, greet: bool) -> str:
    lines = [
        "Không clip nào nêu số tiền hay cụm giá.",
        "Được chào hỏi trong các clip của yêu cầu này." if greet else "KHÔNG chào hỏi.",
        "KHÔNG tạm biệt (trừ closing nếu có yêu cầu viết closing).",
        _NO_NUMBERS, _SELF_CONTAINED, _NO_TIME_WORDS, _HONESTY_ABSOLUTE, _HONESTY_FLASH,
        "Văn nói tự nhiên, câu ngắn; không ký hiệu, emoji hay gạch đầu dòng.",
    ]
    return "LUẬT CHUNG:\n" + "\n".join(f"- {line}" for line in lines)


def build_opening_prompts(plan: dict, theme: str, feedback: str = "") -> tuple[str, str]:
    theme_line = "" if theme else "\nĐiền thêm theme_vi: chủ đề buổi, một câu ngắn."
    user = f"""\
{_frame_context(plan, theme)}

NHIỆM VỤ: viết opening và 2 transitions.{theme_line}{_task_feedback(feedback)}
1. opening (1 clip, {_word_range(OPENING_WORDS)}, 60-120 giây): chào, kiểm tra tín hiệu ("nghe rõ giọng Syna chưa"), miếng hài "AI không có da mà đi bán mỹ phẩm", nêu chủ đề và các BƯỚC routine của buổi (chỉ tên bước, KHÔNG đọc tên sản phẩm), mời ở lại và thả tim.
2. transitions (2 clip khác nhau, mỗi clip {_word_range(TRANSITION_WORDS)}, 20-30 giây): chào người mới vào phòng, nhắc chủ đề, "Syna điểm lại từ bước đầu nhé".

{_frame_rules(greet=True)}"""
    return _frame_system(), user


def build_interlude_prompts(plan: dict, theme: str, feedback: str = "") -> tuple[str, str]:
    user = f"""\
{_frame_context(plan, theme)}

NHIỆM VỤ: viết 6 interludes, mỗi clip {_word_range(INTERLUDE_WORDS)} (10-20 giây): 2 clip kiểu cta, 2 clip kiểu about_shop, 2 clip kiểu fun.{_task_feedback(feedback)}
- cta: nhắc bấm giỏ hàng, xem lại món vừa nói (không nêu tên món, không nêu số).
- about_shop: nói MỘT điều trong danh sách này, không thêm cam kết nào khác: {'; '.join(SHOP_FACTS_VI)}.
- fun: câu đùa hoặc câu hỏi vui về chăm da, không bịa sự thật.
- MỖI đoạn kết bằng đúng một câu nhá chung: "{_TEASER}". KHÔNG nêu tên hay bước của món sau, vì đoạn nghỉ có thể đứng ở nhiều chỗ.

{_frame_rules(greet=False)}"""
    return _frame_system(), user


def _faq_block(faqs: list[dict], snapshots: dict[str, dict], cap: int = 400) -> str:
    lines = []
    for index, faq in enumerate(faqs[:FAQ_COUNT], 1):
        name = str((snapshots.get(str(faq.get("product_id"))) or {}).get("name", ""))[:80]
        lines.append(f"{index}. product_id={faq.get('product_id')} ({name})\n"
                     f"   Câu hỏi: {str(faq.get('question'))[:min(300, cap)]}\n"
                     f"   Trả lời gốc: {str(faq.get('answer'))[:cap]}")
    return "\n".join(lines) or "(chưa có câu hỏi thật nào)"


def _product_facts(plan: dict, snapshots: dict[str, dict], ing: int, desc: int, usage: int) -> str:
    blocks = []
    for item in plan.get("products", []):
        snap = snapshots.get(str(item.get("product_id"))) or {}
        if desc <= 0:
            body = f"- {str(snap.get('name'))[:80]} ({str(snap.get('brand'))[:40]})"
        else:
            body = _product_source_lines(snap, with_price=False, ingredients=ing, description=desc, usage=usage)
        blocks.append(f"[product_id={item.get('product_id')}] bước {item.get('step_label')}\n{body}")
    return "\n\n".join(blocks)


def build_faq_prompts(plan: dict, snapshots: dict[str, dict], faqs: list[dict], theme: str,
                      feedback: str = "") -> tuple[str, str]:
    system = _frame_system()
    missing = FAQ_COUNT - len(faqs[:FAQ_COUNT])
    task_feedback = _task_feedback(feedback)

    def make_user(ing, desc, usage, cap):
        return f"""\
{_frame_context(plan, theme)}

SẢN PHẨM TRONG BUỔI (không có giá, vì clip khung không nói giá):
{_product_facts(plan, snapshots, ing, desc, usage)}

CÂU HỎI THẬT TỪ KHÁCH (đã được shop trả lời):
{_faq_block(faqs, snapshots, cap)}

NHIỆM VỤ: viết {FAQ_COUNT} faqs, mỗi clip {_word_range(FAQ_WORDS)} (20-40 giây), mỗi clip gắn product_id lấy đúng từ danh sách sản phẩm ở trên.
- Với mỗi câu hỏi thật ở trên: Syna đọc theo kiểu "có bạn hỏi..." và diễn đạt lại câu trả lời gốc, KHÔNG thêm ý.
- Còn thiếu {missing} clip: tự sinh câu hỏi và trả lời từ thông tin sản phẩm và cảnh báo kết hợp.{task_feedback}

{_frame_rules(greet=False)}"""

    return system, _fit_user(system, _MULTI_FACT_LADDER, make_user)


def build_tips_closing_prompts(plan: dict, theme: str, feedback: str = "") -> tuple[str, str]:
    user = f"""\
{_frame_context(plan, theme)}

NHIỆM VỤ: viết 2 tips và 1 closing.{_task_feedback(feedback)}
1. tips (2 clip, mỗi clip {_word_range(TIP_WORDS)}, 60-90 giây): kiến thức da theo chủ đề, giọng Syna. KHÔNG bán hàng, KHÔNG tên sản phẩm hay thương hiệu, KHÔNG giá, KHÔNG chào, KHÔNG tạm biệt.
2. closing (1 clip, {_word_range(CLOSING_WORDS)}, 20-30 giây): cảm ơn, nhắc kiểm tra giỏ hàng đã bấm đặt chưa, tạm biệt, hẹn phiên sau. Không nêu số giá. Không nói "hết live là hết giá". Chỉ closing được tạm biệt.

{_frame_rules(greet=False)}"""
    return _frame_system(), user


def build_frame_prompts(plan: dict, snapshots: dict[str, dict], faqs: list[dict]) -> list[tuple[str, str]]:
    """All four frame prompts (for inspection and size tests)."""
    theme = str(plan.get("theme_vi") or "").strip()
    return [build_opening_prompts(plan, theme), build_interlude_prompts(plan, theme),
            build_faq_prompts(plan, snapshots, faqs, theme), build_tips_closing_prompts(plan, theme)]


def _items(value) -> list:
    return value if isinstance(value, list) else []


def _measure_opening(data) -> list:
    if not isinstance(data, dict):
        return []
    out = [("opening", _spoken_words(data.get("opening")), OPENING_WORDS)]
    out += [(f"transition {i + 1}", _spoken_words(t), TRANSITION_WORDS)
            for i, t in enumerate(_items(data.get("transitions")))]
    return out


def _measure_interludes(data) -> list:
    block = data.get("interludes") if isinstance(data, dict) else None
    if not isinstance(block, dict):
        return []
    return [(f"interlude {kind} {i + 1}", _spoken_words(t), INTERLUDE_WORDS)
            for kind in INTERLUDE_TYPES for i, t in enumerate(_items(block.get(kind)))]


def _measure_faqs(data) -> list:
    items = _items(data.get("faqs")) if isinstance(data, dict) else []
    return [(f"faq {i + 1}", _spoken_words(e.get("text")), FAQ_WORDS)
            for i, e in enumerate(items) if isinstance(e, dict)]


def _measure_tips_closing(data) -> list:
    if not isinstance(data, dict):
        return []
    out = [(f"tip {i + 1}", _spoken_words(t), TIP_WORDS) for i, t in enumerate(_items(data.get("tips")))]
    out.append(("closing", _spoken_words(data.get("closing")), CLOSING_WORDS))
    return out


def write_frame_clips(plan: dict, snapshots: dict[str, dict], faqs: list[dict], *,
                      llm=None) -> tuple[str, list[ClipDraft]]:
    llm = llm or LLMProvider()
    theme = str(plan.get("theme_vi") or "").strip()
    drafts: list[ClipDraft] = []

    # Call 1: opening + transitions (and the theme only when the plan has none).
    props = {"opening": _TEXT, "transitions": _text_list(2)}
    if not theme:
        props = {"theme_vi": _TEXT, **props}
    data = _call_sized(llm, lambda fb: build_opening_prompts(plan, theme, fb), _schema(props), "frame_opening",
                       _tokens(OPENING_WORDS[1], 2 * TRANSITION_WORDS[1]), _measure_opening)
    if not theme:
        theme = _need_text(data.get("theme_vi"), "theme_vi")
    drafts.append(ClipDraft(kind="opening", script=_need_text(data.get("opening"), "opening")))
    for index, text in enumerate(_need_list(data.get("transitions"), 2, "transitions")):
        drafts.append(ClipDraft(kind="transition", script=text, variant=index))

    # Call 2: interludes.
    interludes_schema = _schema({kind: _text_list(2) for kind in INTERLUDE_TYPES})
    data = _call_sized(llm, lambda fb: build_interlude_prompts(plan, theme, fb),
                       _schema({"interludes": interludes_schema}), "frame_interludes",
                       _tokens(6 * INTERLUDE_WORDS[1]), _measure_interludes)
    interludes = data.get("interludes")
    if not isinstance(interludes, dict):
        raise ValueError("Thiếu đoạn nghỉ (interludes)")
    for kind in INTERLUDE_TYPES:
        for index, text in enumerate(_need_list(interludes.get(kind), 2, f"interludes/{kind}")):
            drafts.append(ClipDraft(kind="interlude", script=text, interlude_type=kind, variant=index))

    # Call 3: FAQ clips, each tied to a real plan product (never re-tagged).
    known = [str(p.get("product_id")) for p in plan.get("products", [])]
    faq_schema = _schema({"product_id": {"type": "string", "enum": known}, "text": _TEXT})
    faqs_schema = {"type": "array", "items": faq_schema, "minItems": FAQ_COUNT, "maxItems": FAQ_COUNT}
    data = _call_sized(llm, lambda fb: build_faq_prompts(plan, snapshots, faqs, theme, fb),
                       _schema({"faqs": faqs_schema}), "frame_faqs", _tokens(FAQ_COUNT * FAQ_WORDS[1]),
                       _measure_faqs)
    faq_items = data.get("faqs")
    if not isinstance(faq_items, list) or len(faq_items) != FAQ_COUNT:
        raise ValueError(f"Cần đúng {FAQ_COUNT} clip hỏi đáp")
    for index, entry in enumerate(faq_items):
        if not isinstance(entry, dict):
            raise ValueError("Clip hỏi đáp không hợp lệ")
        product_id = str(entry.get("product_id") or "")
        if product_id not in known:
            raise ValueError(f"Clip hỏi đáp có product_id không thuộc buổi: {product_id}")
        drafts.append(ClipDraft(kind="faq", script=_need_text(entry.get("text"), "faqs"),
                                product_id=product_id, variant=index))

    # Call 4: tips + closing.
    data = _call_sized(llm, lambda fb: build_tips_closing_prompts(plan, theme, fb),
                       _schema({"tips": _text_list(2), "closing": _TEXT}), "frame_tips_closing",
                       _tokens(2 * TIP_WORDS[1], CLOSING_WORDS[1]), _measure_tips_closing)
    for index, text in enumerate(_need_list(data.get("tips"), 2, "tips")):
        drafts.append(ClipDraft(kind="tip", script=text, variant=index))
    drafts.append(ClipDraft(kind="closing", script=_need_text(data.get("closing"), "closing")))
    return theme, drafts


# ---------------------------------------------------------------- routine recap

def _recap_lines(plan: dict, snapshots: dict[str, dict], desc: int) -> str:
    blocks = []
    for item in plan.get("products", []):
        snap = snapshots.get(str(item.get("product_id"))) or {}
        use = f"\n  Công dụng: {str(snap.get('description') or '')[:desc]}" if desc > 0 else ""
        blocks.append(
            f"- món số {item.get('number')}: {item.get('step_label')} | {str(snap.get('name'))[:80]} "
            f"({str(snap.get('brand'))[:40]}){use}\n{price_lines(snap.get('pricing'))}"
        )
    return "\n".join(blocks)


def recap_total_phrase(plan: dict, snapshots: dict[str, dict]) -> str:
    pricings = [(snapshots.get(str(p.get("product_id"))) or {}).get("pricing") for p in plan.get("products", [])]
    return bundle_total_phrase(bundle_total(pricings))


def build_recap_prompts(plan: dict, snapshots: dict[str, dict], feedback: str = "") -> tuple[str, str]:
    system = _system(
        "Bạn viết clip tóm tắt routine và giá cuối buổi live của Syna. Chỉ dùng các món, số thứ tự và giá được "
        "cung cấp. Trả về JSON đúng schema."
    )
    total = recap_total_phrase(plan, snapshots)
    conflicts = _conflict_lines(plan.get("conflicts"), "- Không có.")
    task_feedback = _task_feedback(feedback)

    def make_user(desc):
        return f"""\
CHỦ ĐỀ BUỔI: {plan.get('theme_vi') or 'chăm da từng bước'}

CÁC MÓN CÒN LẠI (giữ nguyên số thứ tự, theo thứ tự dùng):
{_recap_lines(plan, snapshots, desc)}

CẢNH BÁO XUNG ĐỘT:
{conflicts}

CỤM BẮT BUỘC ĐỌC NGUYÊN VĂN (không đổi chữ, không tự cộng lại): "{total}"

NHIỆM VỤ: viết MỘT clip tóm tắt routine, {_word_range(RECAP_WORDS)} (60-90 giây).{task_feedback}
- Điểm lại từng món theo số ("món số N"), nêu bước và MỘT câu công dụng, kèm giá đọc đúng nguyên văn cụm giá chuẩn trong các dòng giá; nếu cụm có chữ "nghìn" thì sau cụm chuẩn có thể đệm "tức là <X> cành" với <X> là phần số trước chữ "nghìn".
- Chỉ nhắc đúng các số đã liệt kê, không thêm món khác, không đánh số lại.
- Đọc tổng cả bộ bằng cụm bắt buộc ở trên.
- Nhắc thứ tự dùng sáng/tối và cảnh báo xung đột nếu có. Kết bằng giục bấm giỏ hàng.
- Không chào hỏi, không tạm biệt.
- {_NO_TIME_WORDS}
- {_HONESTY_ABSOLUTE}
- {_HONESTY_FLASH}
- {_SELF_CONTAINED}
- Văn nói tự nhiên; không ký hiệu, emoji hay gạch đầu dòng."""

    return system, _fit_user(system, _RECAP_LADDER, make_user)


def write_routine_recap(plan: dict, snapshots: dict[str, dict], *, llm=None) -> ClipDraft:
    data = _call_sized(
        llm, lambda fb: build_recap_prompts(plan, snapshots, fb), _schema({"text": _TEXT}), "routine_recap",
        _tokens(RECAP_WORDS[1]),
        lambda d: [("recap", _spoken_words(d.get("text")), RECAP_WORDS)] if isinstance(d, dict) else [])
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
