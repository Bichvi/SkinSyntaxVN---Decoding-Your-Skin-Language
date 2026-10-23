# -*- coding: utf-8 -*-
"""
graph_router.py (U10) - decide when a customer question is answered from the Neo4j graph tools, and how.

Routing is DETERMINISTIC (no LLM): the project's LLM pool mixes OpenAI, Gemini, Groq, Zhipu and OpenRouter small
models whose tool-calling support differs, so function calling would be unreliable and cost an extra call per
question. The search ``intent_parser`` extracts the entities and the negation scope; keyword markers decide which
tool. The LLM only phrases the already-computed result (and a template answer is used if it fails or invents a
product). Anything that does not match returns ``None`` and the original RAG pipeline runs unchanged.

Pure planning code (``build_plan``) takes the vocabulary/snapshot by argument; the default runtime is wired lazily
from ``search_service`` so unit tests never touch Neo4j or Mongo.
"""
from __future__ import annotations

import json
import logging
import os
import re
import threading
import time
from dataclasses import dataclass
from typing import Any, Callable, Mapping, Sequence

from chatbot_service import graph_tools as gt
from chatbot_service.graph_tools import ToolError, Vocabulary

logger = logging.getLogger("syna_graph")

ENV_FLAG = "SYNA_GRAPH_TOOLS_ENABLED"
PIPELINE_MODE = "Graph tools"


def enabled() -> bool:
    """Feature flag: false in code, true in docker-compose."""

    return os.getenv(ENV_FLAG, "").strip().lower() in {"1", "true", "yes", "on"}


# ---------------------------------------------------------------- planning (pure)

ROUTINE_RE = re.compile(r"\b(routine|rountine|chu trinh|quy trinh|cac buoc|buoi sang|buoi toi|sang toi|combo|tron bo|bo duong)\b")
KNOWLEDGE_RE = re.compile(r"\b(la gi|tac dung|cong dung|co che|cach dung|cach su dung|huong dan|dung khi nao|bao lau|"
                          r"thu tu|lam sao|the nao|tai sao|vi sao|review|danh gia|nen mua hay)\b")
STORE_RE = re.compile(r"\b(giao hang|van chuyen|doi tra|bao hanh|thanh toan|khuyen mai|ma giam gia|voucher|freeship|"
                      r"chinh sach|dat hang|don hang|tai khoan|dang nhap|dang ky|lien he|dia chi|hoa don)\b")
COMPARE_RE = re.compile(r"\b(so sanh|khac nhau|hay la)\b")
CONFLICT_RE = re.compile(r"\b(dung chung|dung cung|ket hop|xung dot|ky nhau|dung dong thoi|cung luc|cung nhau|phoi|"
                         r"di chung|di cung|dung truoc|dung sau|ky voi|boi chung|bo chung|ghep)\b")
COMPATIBLE_RE = re.compile(r"\b(dung chung|dung cung|dung duoc voi|di voi|di cung|ket hop voi|phoi voi|ghep voi|dung kem|hop de dung voi)\b")
SIMILAR_RE = re.compile(r"\b(tuong tu|giong nhu|giong voi|thay the|tuong duong|dupe|san pham khac)\b")
THIS_RE = re.compile(r"\b(san pham nay|sp nay|em nay|cai nay|loai nay|chai nay|hu nay|tuyp nay)\b")
PRODUCT_WORD_RE = re.compile(r"\b(san pham|sp|loai nao|cai nao|chai nao|hang nao|nhung loai|mon nao)\b")
SURVEY_RE = re.compile(r"^(loai da|van de da|do nhay cam|ngan sach)\s*:")

#: Residual words that carry no condition ("hợp", "nào", ...); anything else after a negation is reported as unverified.
FILLER_TOKENS = frozenset(
    "khong ko chua co hop ky xung dot voi cho da nao la duoc nhung cac mot can tim goi y giup minh em toi tui ban "
    "nha nhe a oi san pham loai hang gia duoi tren tu den khoang k tot nhat re mua dung chung it nhieu bi gay lam "
    "thanh phan chat khac cung phu mat tuong giong thay the".split())


@dataclass(frozen=True)
class Plan:
    tool: str
    args: dict[str, Any]
    reason: str
    unverified: tuple[str, ...] = ()


@dataclass(frozen=True)
class PlanContext:
    snapshot: Any                       # search_service.dictionary.DictionarySnapshot
    vocab: Vocabulary
    groups: gt.IngredientGroups
    catalog: Any | None = None          # CatalogStore-like (products()/get())
    current_product_id: str | None = None


def _folded_tokens(message: str) -> tuple[str, list[str]]:
    from search_service.intent_parser import _TOKEN, clean, extract_price, normalize

    folded = normalize(clean(message))
    _price, rest, _chars = extract_price(folded)
    return folded, _TOKEN.findall(rest)


def group_exclusions(tokens: Sequence[str], groups: gt.IngredientGroups, entity_pos: set[int]
                     ) -> tuple[list[str], list[str], set[int]]:
    """Ingredient groups named by a word the dictionary does not know ("không paraben", "silicone free").

    Returns ``(negated group ids, every mentioned group id, token positions covered by those words)``.
    """

    from search_service.intent_parser import NEGATION_SUFFIXES

    found: list[tuple[int, int, str]] = []
    for group in groups.exclusion.values():
        for surface in group.surfaces:
            n = len(surface)
            for i in range(len(tokens) - n + 1):
                if tuple(tokens[i:i + n]) == surface and not set(range(i, i + n)) & entity_pos:
                    found.append((i, n, group.id))
    found.sort()
    negated_ids: list[str] = []
    mentioned: list[str] = []
    covered: set[int] = set()
    previous: tuple[int, bool] | None = None
    for start, length, gid in found:
        if set(range(start, start + length)) & covered:
            continue
        covered.update(range(start, start + length))
        if gid not in mentioned:
            mentioned.append(gid)
        after = tokens[start + length] if start + length < len(tokens) else None
        negated, conflict = _neg(tokens, start, entity_pos, previous)
        if not negated and after in NEGATION_SUFFIXES:
            negated = True
        if negated:
            previous = (start + length, conflict)
            if gid not in negated_ids:
                negated_ids.append(gid)
    return negated_ids, mentioned, covered


def _neg(tokens: Sequence[str], start: int, entity_pos: set[int], previous: tuple[int, bool] | None) -> tuple[bool, bool]:
    from search_service.intent_parser import negation_scope

    return negation_scope(list(tokens), start, entity_pos, previous)


NEG_CUE_RE = re.compile(r"(?<!\w)(?:không|ko)\s+(?:(?:gây|làm|bị|chứa|có|dùng)\s+)*", re.I)
CLAIM_WORD_RE = re.compile(r"[^\s,.;!?]+")
CLAIM_SKIP = frozenset("ky xung dot voi cung chung khac thanh phan chat".split())
CLAIM_STOP = frozenset("cho duoc a nhe nha hay va hoac la biet can phai minh em toi tui ban gi".split())


def _unverified(message: str, covered_words: set[str]) -> list[str]:
    """"không gây bí da": a negated property the graph cannot check. Reported to the customer, never silently dropped."""

    from graph_sync.normalize import strip_accents

    text = message.lower()
    out: list[str] = []
    for cue in NEG_CUE_RE.finditer(text):
        words = CLAIM_WORD_RE.findall(text[cue.end():cue.end() + 40])[:3]
        keep: list[str] = []
        for original in words:
            word = strip_accents(original).lower()
            if word in covered_words or word in CLAIM_STOP or word in {"khong", "ko"}:
                break
            if word in CLAIM_SKIP and not keep:
                continue                      # "không kỵ ...", "không xung đột với ...": fillers before the claim
            keep.append(original)
        if keep:
            phrase = "không " + " ".join(keep)
            if phrase not in out:
                out.append(phrase)
    return out[:2]


def _resolve_product(ctx: PlanContext, intent: Any, content: Sequence[str]) -> str | None:
    """Product id named in the sentence: brand + every remaining content word inside exactly one product name."""

    if ctx.catalog is None or not intent.brands or not content:
        return None
    from graph_sync.normalize import slugify

    brand_fold = slugify(ctx.vocab.label("brands", intent.brands[0]))
    hits: list[str] = []
    for product in ctx.catalog.products():
        if slugify(product.get("thuong_hieu")) != brand_fold:
            continue
        name_tokens = set(slugify(product.get("ten_san_pham")).split("_"))
        if all(t in name_tokens for t in content):
            if intent.categories and product.get("category_keys") and not set(intent.categories) & set(product["category_keys"]):
                continue
            hits.append(str(product["id"]))
    return hits[0] if len(hits) == 1 else None


def build_plan(message: str, ctx: PlanContext) -> Plan | None:
    """Map a customer sentence to one graph tool call, or ``None`` (= keep the RAG pipeline)."""

    from search_service.intent_parser import _match, parse

    if not isinstance(message, str) or not message.strip() or len(message) > 400:
        return None
    folded, tokens = _folded_tokens(message)
    if not tokens or SURVEY_RE.match(folded):
        return None
    padded = f" {folded} "
    if ROUTINE_RE.search(padded) or KNOWLEDGE_RE.search(padded) or STORE_RE.search(padded) or COMPARE_RE.search(padded):
        return None

    trace: list[dict[str, Any]] = []
    intent = parse(message, ctx.snapshot, trace=trace)
    entity_pos = {p for t in trace for p in range(t["pos"], t["pos"] + len(t.get("tokens", [t.get("token")])))}
    all_matches = _match(tokens, ctx.snapshot)
    matched_pos = {p for start, length, _t, _k in all_matches for p in range(start, start + length)}
    group_ids, group_mentions, group_pos = group_exclusions(tokens, ctx.groups, entity_pos)
    exclude = list(dict.fromkeys(list(intent.exclude_ingredients) + group_ids))
    avoid = list(intent.avoid_conflict_with)
    unverified = tuple(_unverified(message, {tokens[p] for p in matched_pos | group_pos | entity_pos if p < len(tokens)}))
    ingredients = list(intent.ingredients)

    # 1) "A dùng chung với B được không" -> rule-based conflict check between ingredients
    if CONFLICT_RE.search(padded) and len(ingredients) >= 2 and not intent.categories:
        return Plan("check_conflicts", {"ingredient_keys": ingredients[:4]}, "ingredient_pair")

    # 2) products named in the sentence / the product being viewed
    this_product = ctx.current_product_id if (ctx.current_product_id and THIS_RE.search(padded)) else None
    covered_all = matched_pos | group_pos | entity_pos
    content = [t for i, t in enumerate(tokens) if i not in covered_all and t not in FILLER_TOKENS and len(t) > 1 and not t.isdigit()]
    named = _resolve_product(ctx, intent, content) if not this_product else None
    target = this_product or named
    if target and SIMILAR_RE.search(padded):
        return Plan("similar_products", {"product_id": target, "limit": 5}, "similar_to_product")
    # ingredients asked about ("có cồn không", "có paraben không"): dictionary keys + group ids, either polarity
    probe_keys = list(dict.fromkeys(ingredients + list(intent.exclude_ingredients) + list(intent.avoid_conflict_with)
                                    + group_mentions))[:5]
    has_property = bool(probe_keys or intent.skin_types or intent.concerns or intent.benefits)
    if target and has_property:
        return Plan("explain_product", {"product_id": target, "probe_ingredients": probe_keys},
                    "named_product" if named else "current_product", unverified)
    if (ctx.current_product_id and not target and not intent.categories and not intent.brands
            and has_property and len(tokens) <= 12 and not PRODUCT_WORD_RE.search(padded)):
        return Plan("explain_product", {"product_id": ctx.current_product_id, "probe_ingredients": probe_keys},
                    "current_product", unverified)

    # 3) conditional product search
    seeking = bool(intent.categories or intent.brands or PRODUCT_WORD_RE.search(padded))
    if not seeking or target:
        return None
    if intent.brands and content:
        return None            # a product name the catalog lookup could not resolve: keep the RAG pipeline, never guess
    if len(intent.categories) > 1:
        return None            # "toner và serum không cồn": one tool call answers one category, so RAG keeps it
    if COMPATIBLE_RE.search(padded) and ingredients and not exclude:
        avoid, ingredients = list(dict.fromkeys(avoid + ingredients)), []     # "toner dùng chung được với retinol"
    strong = [bool(exclude), bool(avoid), intent.price is not None, bool(ingredients),
              bool(intent.brands and intent.categories)]
    weak = len(intent.skin_types) + len(intent.concerns) + len(intent.benefits)    # category/brand are the target noun
    if not (any(strong) or weak >= 3):
        return None
    args: dict[str, Any] = {
        "category": intent.categories[0] if intent.categories else None,
        "skin_type": intent.skin_types[0] if intent.skin_types else None,
        "concerns": intent.concerns[:3], "benefits": intent.benefits[:3],
        "include_ingredients": ingredients[:4], "exclude_ingredients": exclude[:6],
        "avoid_conflict_with": avoid[:3], "brand": intent.brands[0] if intent.brands else None,
        "price_max": intent.price.max_vnd if intent.price else None,
        "price_min": intent.price.min_vnd if intent.price else None, "limit": gt.DEFAULT_LIMIT,
    }
    return Plan("find_products", {k: v for k, v in args.items() if v not in (None, [], "")}, "conditions", unverified)


# ---------------------------------------------------------------- answer composition


def vnd(value: Any) -> str:
    try:
        return f"{int(value):,}".replace(",", ".") + "đ"
    except (TypeError, ValueError):
        return "liên hệ"


SEVERITY_VI = {"high": "mức cao", "medium": "mức trung bình", "low": "mức nhẹ"}
TIMING_VI = {"same_routine": "không nên dùng trong cùng một routine, hãy dùng xen kẽ",
             "same_session": "không nên chồng lớp cùng buổi, có thể tách sáng/tối",
             "ok_alternate_days": "dùng được nếu cách ngày"}


def describe_filters(filters: Mapping[str, Any], vocab: Vocabulary) -> str:
    parts: list[str] = []
    if filters.get("category"):
        parts.append(vocab.label("categories", filters["category"]))
    if filters.get("brand"):
        parts.append("hãng " + vocab.label("brands", filters["brand"]))
    if filters.get("skin_type"):
        parts.append("hợp " + vocab.label("skin_types", filters["skin_type"]).lower())
    parts += [vocab.label("concerns", c) for c in filters.get("concerns", [])]
    parts += [vocab.label("benefits", b) for b in filters.get("benefits", [])]
    parts += ["có " + vocab.label("ingredients", k) for k in filters.get("include", [])]
    if filters.get("avoid_conflict_with"):
        parts.append("không kỵ " + ", ".join(vocab.label("ingredients", k) for k in filters["avoid_conflict_with"]))
    if filters.get("exclude"):
        parts.append("loại trừ thành phần đã nêu")
    if filters.get("price_max"):
        parts.append(f"giá ≤ {vnd(filters['price_max'])}")
    if filters.get("price_min"):
        parts.append(f"giá ≥ {vnd(filters['price_min'])}")
    return " · ".join(parts)


def _link(item: Mapping[str, Any]) -> str:
    return f"**[{item.get('name') or item.get('id')}](index.php?r=chitiet&id={item['id']})**"


def render_template(result: Mapping[str, Any], vocab: Vocabulary, unverified: Sequence[str] = ()) -> str:
    """Deterministic answer written from the tool result alone (used when the LLM is down or misbehaves)."""

    tool = result.get("tool")
    lines: list[str] = []
    if tool == "find_products":
        items = result.get("products") or []
        cond = describe_filters(result.get("filters") or {}, vocab)
        if not items:
            lines.append("Syna tra trong dữ liệu cửa hàng nhưng chưa thấy sản phẩm nào thỏa đủ các điều kiện"
                         + (f" ({cond})" if cond else "") + ". Bạn thử nới một điều kiện (giá, loại da hoặc hoạt chất) để mình tìm lại nhé!")
        else:
            lines.append(f"Syna tìm được {len(items)} sản phẩm đúng điều kiện" + (f" ({cond})" if cond else "") + ":")
            for i, item in enumerate(items, 1):
                extra = "; ".join(item.get("why") or [])
                brand = f" - {item['brand']}" if item.get("brand") else ""
                lines.append(f"{i}. {_link(item)}{brand} - {vnd(item.get('price'))}" + (f" ({extra})" if extra else ""))
    elif tool == "check_conflicts":
        pairs = result.get("pairs") or []
        if not pairs:
            lines.append(result.get("note") or "Syna chưa ghi nhận xung đột nào cho các mục này.")
        else:
            lines.append("Syna kiểm tra theo luật xung đột hoạt chất của cửa hàng:")
            for p in pairs:
                lines.append(f"- **{p['a']} + {p['b']}**: {SEVERITY_VI.get(p['severity'], p['severity'])}, "
                             f"{TIMING_VI.get(p.get('timing'), '')}. {p.get('advice') or ''}".replace(", .", "."))
    elif tool == "explain_product":
        product = result.get("product") or {}
        lines.append(f"Về {_link(product)} ({vnd(product.get('price'))}):")
        for probe in result.get("probe") or []:
            if not probe["readable"]:
                lines.append(f"- Chưa đọc được bảng thành phần nên Syna chưa khẳng định được có/không {probe['ingredient']}.")
            else:
                lines.append(f"- {probe['ingredient']}: " + ("CÓ trong bảng thành phần." if probe["present"] else "không thấy trong bảng thành phần."))
        if result.get("actives"):
            lines.append("- Hoạt chất chính: " + ", ".join(f"{a['name']}" for a in result["actives"][:5]) + ".")
        if result.get("skin_types"):
            lines.append("- Loại da phù hợp: " + ", ".join(s["name"] for s in result["skin_types"]) + ".")
        if result.get("benefits"):
            lines.append("- Công dụng: " + ", ".join(b["name"] for b in result["benefits"]) + ".")
    elif tool == "similar_products":
        items = result.get("items") or []
        if not items:
            lines.append("Syna chưa tìm được sản phẩm tương tự phù hợp cho sản phẩm này.")
        else:
            lines.append("Một số sản phẩm tương tự:")
            for i, item in enumerate(items, 1):
                shared = f" (chung hoạt chất: {', '.join(item['shared'])})" if item.get("shared") else ""
                lines.append(f"{i}. {_link(item)} - {vnd(item.get('price'))}{shared}")
    for note in result.get("notes") or []:
        lines.append(f"Lưu ý: {note}")
    for item in unverified:
        lines.append(f"Lưu ý: Syna chưa kiểm chứng được điều kiện \"{item}\" từ dữ liệu thành phần nên chưa lọc theo ý này.")
    if result.get("safety_note"):
        lines.append(result["safety_note"])
    return "\n".join(lines).strip()


PROMPT = """Bạn là SYNA, trợ lý AI skincare của SkinSyntaxVN (xưng "Syna" hoặc "mình", gọi khách là "bạn"; giọng thân thiện, ngắn gọn).
Hãy trả lời câu hỏi của khách CHỈ dựa trên KẾT QUẢ TRA CỨU ĐỒ THỊ bên dưới (dữ liệu thật của cửa hàng).

QUY TẮC BẮT BUỘC
1. Chỉ nhắc các sản phẩm có trong kết quả. Mỗi sản phẩm viết đúng dạng **[Tên](index.php?r=chitiet&id=ID)** kèm giá (ví dụ 199.000đ). Không bịa sản phẩm, giá, công dụng hay thành phần.
2. Nêu ngắn gọn vì sao sản phẩm đáp ứng điều kiện, dựa vào "why", "filters", "notes". Không chẩn đoán bệnh.
3. Nếu "notes" hoặc "unverified" có điều kiện chưa kiểm chứng/giới hạn dữ liệu thì nói rõ cho khách.
4. Nếu count = 0: nói thẳng là chưa có sản phẩm thỏa đủ điều kiện và gợi ý nới đúng một điều kiện; không bịa.
5. Nếu có xung đột hoạt chất: nêu mức độ (cao/trung bình/nhẹ) và cách dùng an toàn lấy từ "advice". Nếu consult_expert = true, kết bằng khuyến nghị hỏi bác sĩ da liễu.
6. Tối đa khoảng 170 từ, tiếng Việt, không lặp lại JSON.

CÂU HỎI CỦA KHÁCH: {question}

KẾT QUẢ TRA CỨU (JSON): {tool_json}
CHƯA KIỂM CHỨNG ĐƯỢC: {unverified}
"""

_LINK_ID_RE = re.compile(r"index\.php\?r=chitiet&id=([A-Za-z0-9_-]+)")


def result_ids(result: Mapping[str, Any]) -> set[str]:
    ids: set[str] = set()
    for key in ("products", "items"):
        ids.update(str(i["id"]) for i in result.get(key) or [])
    if isinstance(result.get("product"), dict):
        ids.add(str(result["product"]["id"]))
    for pair in result.get("pairs") or []:
        ids.update(str(pair[k]) for k in ("a_id", "b_id") if pair.get(k))
    return ids


def accept_llm_answer(answer: str | None, result: Mapping[str, Any]) -> str | None:
    """Reject empty answers and answers that link a product the tool did not return; always keep the expert note."""

    if not answer or not answer.strip():
        return None
    text = answer.strip()
    if set(_LINK_ID_RE.findall(text)) - result_ids(result):
        return None
    if result.get("tool") == "find_products" and (result.get("count") or 0) > 0 and not _LINK_ID_RE.search(text):
        return None
    note = result.get("safety_note")
    if note and "bác sĩ" not in text.lower() and "chuyên gia" not in text.lower():
        text = f"{text}\n\n{note}"
    return text


def compose_answer(question: str, result: Mapping[str, Any], vocab: Vocabulary, unverified: Sequence[str],
                   generate: Callable[[str], str | None] | None, stats: dict[str, Any] | None = None) -> tuple[str, str]:
    """Return ``(answer, source)``; ``source`` is ``llm`` or ``template``. ``stats`` (optional) receives
    ``prompt_chars`` / ``answer_chars`` so the cost of the LLM step can be estimated."""

    if generate is not None:
        prompt = PROMPT.format(question=question[:300], unverified="; ".join(unverified) or "không có",
                               tool_json=json.dumps(gt.public_view(result), ensure_ascii=False, separators=(",", ":")))
        if stats is not None:
            stats["prompt_chars"] = len(prompt)
        try:
            raw = generate(prompt)
            if stats is not None:
                stats["answer_chars"] = len(raw or "")
            text = accept_llm_answer(raw, result)
        except Exception as exc:
            logger.warning("graph answer: LLM failed (%s); using template", type(exc).__name__)
            text = None
        if text:
            if unverified and "chưa kiểm chứng" not in text.lower():
                text += "\n\nLưu ý: " + "; ".join(f"Syna chưa kiểm chứng được \"{u}\" từ dữ liệu thành phần" for u in unverified) + "."
            return text, "llm"
    return render_template(result, vocab, unverified), "template"


# ---------------------------------------------------------------- orchestration


@dataclass
class Runtime:
    tools: gt.GraphTools
    context: Callable[[str | None], PlanContext]


def build_response(question: str, plan: Plan, result: Mapping[str, Any], answer: str, source: str,
                   started: float, cart_conflicts: Sequence[Any] = (), stats: Mapping[str, Any] | None = None) -> dict[str, Any]:
    cards = list(result.get("_cards") or [])
    return {
        "ok": True, "answer": answer, "products": cards, "conflicts": list(cart_conflicts),
        "fallback": False, "fallback_reason": "", "pipeline_mode": PIPELINE_MODE,
        "query_type": f"graph tool: {plan.tool}", "intent_mode": "GRAPH_TOOL",
        "profile_state": "BYPASSED", "profile_gate": "BYPASSED",
        "latency": round(time.time() - started, 2), "eval_scores": {"ar": 1.0, "gr": 1.0, "cr": 1.0},
        "graph_tool": {"name": plan.tool, "reason": plan.reason, "count": result.get("count"),
                       "answer_source": source, "degraded": bool(result.get("degraded")), **(stats or {})},
    }


def try_answer(message: str, msg_data: Mapping[str, Any] | None, runtime: Runtime | None, *,
               generate: Callable[[str], str | None] | None = None,
               blocked: Callable[[Plan], bool] | None = None) -> dict[str, Any] | None:
    """Answer from graph tools or return ``None`` (caller continues with the RAG pipeline).

    Never raises: a Neo4j problem, a bad plan or any bug means ``None`` and the old behaviour.
    """

    if runtime is None:
        return None
    started = time.time()
    try:
        current = None
        if isinstance(msg_data, Mapping) and msg_data.get("current_product_id") not in (None, ""):
            current = str(msg_data.get("current_product_id")).strip()
        ctx = runtime.context(current)
        plan = build_plan(message, ctx)
        if plan is None:
            return None
        if blocked is not None and blocked(plan):
            logger.info(json.dumps({"event": "syna_graph_skip", "reason": "safety_gate", "tool": plan.tool}))
            return None
        result = runtime.tools.call(plan.tool, plan.args)
        stats: dict[str, Any] = {}
        answer, source = compose_answer(message, result, ctx.vocab, plan.unverified, generate, stats)
        cart_conflicts = msg_data.get("cart_conflicts", []) if isinstance(msg_data, Mapping) else []
        return build_response(message, plan, result, answer, source, started, cart_conflicts or [], stats)
    except ToolError as exc:
        logger.warning("graph tools -> RAG fallback: %s", exc.code)
        return None
    except Exception as exc:
        logger.warning("graph tools -> RAG fallback: %s", type(exc).__name__, exc_info=logger.isEnabledFor(logging.DEBUG))
        return None


_runtime: Runtime | None = None
_runtime_lock = threading.Lock()


def get_runtime() -> Runtime | None:
    """Lazy default wiring on top of ``search_service`` (graph, catalog, dictionary, U08/U09 services)."""

    global _runtime
    if _runtime is not None:
        return _runtime
    with _runtime_lock:
        if _runtime is not None:
            return _runtime
        try:
            from graph_sync.conflicts import load_rule_set
            from search_service import config as search_config
            from search_service import neo4j_client
            from search_service.catalog import get_mongo_db
            from search_service.dictionary import get_default_dictionary
            from search_service.service import get_cart_service, get_parts, get_similar_service

            if not (search_config.SEARCH_GRAPH_ENABLED and neo4j_client.is_enabled()):
                return None
            parts = get_parts()
            catalog = parts.get("catalog")
            groups = gt.load_groups()
            dictionary = get_default_dictionary()
            cart_service = get_cart_service()
            cache: dict[int, Vocabulary] = {}

            def snapshot_and_vocab() -> tuple[Any, Vocabulary]:
                snap = dictionary.snapshot_nowait()
                vocab = cache.get(id(snap))
                if vocab is None:
                    vocab = Vocabulary.from_snapshot(snap)
                    cache.clear()
                    cache[id(snap)] = vocab
                return snap, vocab

            deps = gt.ToolDeps(
                graph=parts["graph"], vocab=lambda: snapshot_and_vocab()[1], groups=groups, rule_set=load_rule_set(),
                facts=gt.make_facts_loader(catalog, get_mongo_db),
                similar=get_similar_service(), product_conflicts=cart_service.conflicts_for)
            tools = gt.GraphTools(deps)

            def context(current: str | None) -> PlanContext:
                snap, vocab = snapshot_and_vocab()
                return PlanContext(snapshot=snap, vocab=vocab, groups=groups, catalog=catalog, current_product_id=current)

            _runtime = Runtime(tools=tools, context=context)
        except Exception as exc:
            logger.warning("graph tools runtime unavailable: %s", type(exc).__name__)
            return None
    return _runtime
