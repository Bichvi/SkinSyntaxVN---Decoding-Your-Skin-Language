"""LangGraph orchestration for the scoped commerce agent."""

from __future__ import annotations

import json
import logging
import copy
import re
import threading
import time
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FutureTimeoutError
from datetime import datetime
from queue import Empty, Queue
from typing import Any, Callable, Iterator, TypedDict
from zoneinfo import ZoneInfo

from langchain_core.messages import AIMessage, BaseMessage, HumanMessage, SystemMessage, ToolMessage
from langgraph.graph import END, START, StateGraph
from pydantic import ValidationError

from .config import MAX_STEPS, TURN_TIMEOUT_S
from .prompts import AGENT_PROMPT, render
from .router import RouterOutput, classify, fold
from .state import ConvState, StateStore
from .tools import ToolContext, build_tools


logger = logging.getLogger(__name__)
_INVOKE_EXECUTOR = ThreadPoolExecutor(max_workers=16, thread_name_prefix="agent-turn")
_TURN_EXECUTOR = ThreadPoolExecutor(max_workers=16, thread_name_prefix="agent-turn-worker")
_EVENT_QUEUE_GRACE_S = 0.05

LEGACY_LABELS = {"STORE_SUPPORT", "SKIN_SURVEY", "SMALLTALK", "OUT_OF_SCOPE", "KNOWLEDGE"}
AGENT_LABELS = {"ADVISE", "PRODUCT_INFO", "BUY", "CART", "ORDER_STATUS"}
LOGIN_REQUIRED_LABELS = {"BUY", "CART", "ORDER_STATUS"}
TIMEOUT_TEXT = "Mình cần thêm chút thời gian, bạn nhắn lại giúp mình nhé."
DEFAULT_TEXT = "Mình chưa rõ bạn muốn mình hỗ trợ việc gì. Bạn nói rõ hơn giúp mình nhé."
ERROR_TEXT = "Mình gặp sự cố khi xử lý yêu cầu. Bạn thử lại giúp mình nhé."
MUTATION_DRAFT_TEXT = "Mình đã cập nhật đơn nháp, bạn xem lại thẻ đơn bên trên và bấm “Xác nhận đặt hàng” nếu đã đúng nhé."
MUTATION_CART_TEXT = "Mình đã cập nhật giỏ hàng của bạn. Bạn mở giỏ hàng để kiểm tra lại nhé, không cần gửi lại yêu cầu."
VIETNAMESE_TIMEZONE = ZoneInfo("Asia/Ho_Chi_Minh")
PAYMENT_SUPPORT_TEXT = (
    "SkinSyntaxVN hiện hỗ trợ 3 cách thanh toán: COD (trả tiền khi nhận hàng), VNPay và chuyển khoản QR. "
    "Cửa hàng chưa hỗ trợ trả góp hay các ví điện tử khác như MoMo, ZaloPay. "
    "Bạn chọn một trong 3 cách trên giúp mình nhé."
)
NO_DRAFT_FALLBACK_TEXT = (
    "Mình chưa lập được đơn nháp cho bạn. Bạn cho mình biết người nhận, số điện thoại, địa chỉ giao hàng "
    "và cách thanh toán (COD, VNPay hoặc chuyển khoản QR) để mình lập đơn nhé."
)
ORDER_FALLBACK_TEXT = "Đơn hàng chỉ được tạo khi bạn bấm “Xác nhận đặt hàng” trên thẻ đơn nháp nhé."
_NO_DRAFT_CORRECTION = (
    "[Kiểm tra tự động] Chưa có đơn nháp nào được tạo trong hệ thống (prepare_order_draft chưa trả về thẻ đơn). "
    "Không được nói đơn nháp đã sẵn sàng hay đơn đã được đặt. Hãy gọi prepare_order_draft nếu đã đủ thông tin, "
    "hoặc hỏi khách đúng một thông tin còn thiếu (địa chỉ giao hàng, người nhận, SĐT, cách thanh toán)."
)
_ORDER_CORRECTION = (
    "[Kiểm tra tự động] Đơn hàng chưa được tạo: đơn chỉ được tạo khi khách tự bấm \"Xác nhận đặt hàng\" "
    "trên thẻ đơn nháp. Không được nói đơn đã được đặt hay đặt hàng thành công. Hãy trả lời lại, nhắc khách "
    "kiểm tra thẻ đơn nháp và bấm \"Xác nhận đặt hàng\"."
)

_ORDER_CLAIM_RE = re.compile(
    r"\bdat hang thanh cong\b"
    r"|\bdon hang (?:cua ban )?(?:da|vua) (?:duoc )?(?:tao|dat)\b"
    r"|\bda tao (?:xong )?don(?! nhap)\b"
)
_DRAFT_CLAIM_RE = re.compile(
    r"\bdon nhap (?:cua ban )?(?:da|dang) (?:duoc )?(?:san sang|tao|lap)\b"
    r"|\bdon nhap (?:cua ban )?(?:minh )?(?:tao|lap) san\b"
    r"|\bda (?:tao|lap) (?:xong )?don nhap\b"
)
_UNSUPPORTED_PAYMENT_RE = re.compile(
    # Card payments are NOT listed: VNPay accepts domestic and international cards.
    r"\btra gop\b|\bmomo\b|\bzalo ?pay\b|\bshopee ?pay\b|\bviettel ?money\b|\bpaypal\b"
    r"|\bvi dien tu\b|\bpay ?later\b|\bfundiin\b|\bkredivo\b"
)


def is_unsupported_payment_question(message: str) -> bool:
    """True when the customer mentions a payment method the store does not offer."""
    return bool(_UNSUPPORTED_PAYMENT_RE.search(fold(message or "")))


def claims_false_commitment(text: str, *, has_open_draft: bool) -> str | None:
    """Return "order" / "draft" when the text claims something the agent cannot have done."""
    folded = fold(text or "")
    if not folded:
        return None
    if _ORDER_CLAIM_RE.search(folded):
        return "order"
    if not has_open_draft and _DRAFT_CLAIM_RE.search(folded):
        return "draft"
    return None

Event = tuple[str, dict]


class _TurnEmitGate:
    """Serialize event publication and make terminal publication one-way."""

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self._closed = False

    def publish(self, event: Event, callback: Callable[[Event], None]) -> bool:
        with self._lock:
            if self._closed:
                logger.debug("dropping late event type=%s", event[0])
                return False
            callback(event)
            return True

    def close_and_publish(self, event: Event, callback: Callable[[Event], None]) -> bool:
        with self._lock:
            if self._closed:
                logger.debug("dropping duplicate terminal event type=%s", event[0])
                return False
            self._closed = True
            callback(event)
            return True

    def close(self) -> None:
        with self._lock:
            self._closed = True


class GraphState(TypedDict, total=False):
    message: str
    ctx: ToolContext
    history: list[BaseMessage]
    recent_turns: list[dict]
    legacy_payload: dict
    events: list[Event]
    event_sink: Callable[[Event], None]
    intent_queue: list[str]
    router_output: RouterOutput
    halted: bool
    turn_started: float
    clock: Callable[[], float]
    agent_llm_factory: Callable[[list[Any]], Any]
    steps: int
    timeout_emitted: bool
    emit_gate: _TurnEmitGate
    cancel_event: threading.Event
    state_snapshot: ConvState
    snapshot_box: dict


def _publish(state: GraphState, event: Event) -> None:
    def append_and_sink(value: Event) -> None:
        state["events"].append(value)
        sink = state.get("event_sink")
        if sink is not None:
            sink(value)

    gate = state.get("emit_gate")
    if gate is None:
        append_and_sink(event)
    else:
        gate.publish(event, append_and_sink)


def _publish_terminal(state: GraphState, event: Event) -> bool:
    def append_and_sink(value: Event) -> None:
        state["events"].append(value)
        sink = state.get("event_sink")
        if sink is not None:
            sink(value)

    gate = state.get("emit_gate")
    if gate is None:
        append_and_sink(event)
        return True
    return gate.close_and_publish(event, append_and_sink)


def _emit(state: GraphState, kind: str, payload: dict) -> None:
    _publish(state, (kind, payload))


def _emit_timeout(state: GraphState) -> None:
    if not state.get("timeout_emitted"):
        _emit(state, "token", {"text": TIMEOUT_TEXT})
        state["timeout_emitted"] = True


def _model_dump(value: Any) -> Any:
    if hasattr(value, "model_dump"):
        return value.model_dump(mode="json")
    return value


def _safe_mapping(value: Any, allowed: set[str]) -> dict:
    if not isinstance(value, dict):
        return {}
    return {key: value[key] for key in allowed if key in value}


def _safe_cart_snapshot(snapshot: list[dict]) -> list[dict]:
    allowed = {"id", "product_id", "name", "qty", "price", "size"}
    return [_safe_mapping(item, allowed) for item in snapshot if isinstance(item, dict)]


def _safe_products(products: list[dict]) -> list[dict]:
    allowed = {"idx", "id", "name"}
    return [_safe_mapping(item, allowed) for item in products if isinstance(item, dict)]


def _safe_agent_entities(router_output: RouterOutput | None) -> dict:
    if router_output is None:
        return {}
    raw = _model_dump(router_output.entities)
    if not isinstance(raw, dict):
        return {}
    allowed = {
        "resolved_product_ids", "qty", "scope", "payment_method",
        "skin_type", "concerns", "budget_max",
    }
    return {key: raw[key] for key in allowed if key in raw}


def _agent_messages(state: GraphState) -> list[BaseMessage]:
    ctx = state["ctx"]
    skin_profile = _safe_mapping(
        getattr(ctx, "skin_profile", {}),
        {"skin_type", "concerns", "goal", "budget_max", "avoid_ingredients", "sensitivity"},
    )
    open_draft = {"draft_id": ctx.state.open_draft_id} if ctx.state.open_draft_id else "none"
    prompt = render(
        AGENT_PROMPT,
        customer_display_name="bạn",
        is_logged_in=bool(ctx.claims),
        skin_profile=json.dumps(skin_profile, ensure_ascii=False, separators=(",", ":")),
        cart_summary=json.dumps(_safe_cart_snapshot(ctx.cart_snapshot), ensure_ascii=False, separators=(",", ":")),
        open_draft_summary=json.dumps(open_draft, ensure_ascii=False, separators=(",", ":")),
        intent_queue=json.dumps(state.get("intent_queue", []), ensure_ascii=False, separators=(",", ":")),
        entities=json.dumps(_safe_agent_entities(state.get("router_output")), ensure_ascii=False, separators=(",", ":")),
        last_shown_products=json.dumps(
            _safe_products(ctx.state.last_shown_products), ensure_ascii=False, separators=(",", ":")
        ),
        now_vn=datetime.now(VIETNAMESE_TIMEZONE).isoformat(),
    )
    return [SystemMessage(content=prompt), *state["history"], HumanMessage(content=state["message"])]


def _text_content(message: Any) -> str:
    content = getattr(message, "content", "")
    if isinstance(content, str):
        return content.strip()
    if isinstance(content, list):
        parts = []
        for block in content:
            if isinstance(block, dict) and isinstance(block.get("text"), str):
                parts.append(block["text"])
        return "".join(parts).strip()
    return str(content or "").strip()


def _tool_args(call: dict) -> dict:
    args = call.get("args", {})
    if isinstance(args, str):
        args = json.loads(args)
    if not isinstance(args, dict):
        raise ValueError("tool arguments must be an object")
    return args


def _remaining(state: GraphState) -> float:
    return TURN_TIMEOUT_S - (state["clock"]() - state["turn_started"])


def _timed_out(state: GraphState) -> bool:
    return _remaining(state) <= 0


def _cancelled(state: GraphState) -> bool:
    cancel_event = state.get("cancel_event")
    return cancel_event is not None and cancel_event.is_set()


def _snapshot_state(state: GraphState) -> None:
    # LangGraph hands each node a copy of the state dict, so a plain key write here would be lost
    # before run_turn saves. The box is one shared mutable object that every copy references.
    try:
        snapshot = copy.deepcopy(state["ctx"].state)
        state["state_snapshot"] = snapshot
        box = state.get("snapshot_box")
        if box is not None:
            box["value"] = snapshot
    except Exception as exc:
        logger.debug("state snapshot failed: %s", type(exc).__name__)


def _state_to_save(state: GraphState) -> ConvState:
    box = state.get("snapshot_box")
    snapshot = box.get("value") if box is not None else None
    if snapshot is None:
        snapshot = state.get("state_snapshot")
    if snapshot is None:
        snapshot = copy.deepcopy(state["ctx"].state)
    else:
        snapshot = copy.deepcopy(snapshot)
    if snapshot.open_draft_id:
        snapshot.pending = f"draft_open:{snapshot.open_draft_id}"
    elif snapshot.pending in ("", "none"):
        snapshot.pending = "none"
    snapshot.intent_queue = []
    return snapshot


def _bounded(state: GraphState, fn: Callable[[], Any]) -> tuple[bool, Any]:
    """Run one external/model operation within the remaining turn budget."""

    if _cancelled(state):
        return False, None
    remaining = _remaining(state)
    if remaining <= 0:
        return False, None
    future = _INVOKE_EXECUTOR.submit(fn)
    try:
        return True, future.result(timeout=remaining)
    except FutureTimeoutError:
        future.cancel()
        return False, None


def _tool_error(code: str) -> str:
    return f"<tool_data>{json.dumps({'error': code}, separators=(',', ':'))}</tool_data>"


def _count_step(state: GraphState) -> None:
    state["steps"] = state.get("steps", 0) + 1


def _consecutive_agent_count(queue: list[str]) -> int:
    count = 0
    for label in queue:
        if label not in AGENT_LABELS:
            break
        count += 1
    return count


def _claim_kind(ctx: ToolContext, text: str, batch: list[str]) -> str | None:
    kind = claims_false_commitment(text, has_open_draft=bool(ctx.state.open_draft_id))
    if kind == "order" and "ORDER_STATUS" in batch:
        # Order lookups legitimately say "đơn hàng của bạn đã được tạo lúc ..."
        return None
    return kind


def _guard_text(ctx: ToolContext, text: str, batch: list[str]) -> str:
    """Replace a false order/draft claim with a deterministic, truthful message."""
    kind = _claim_kind(ctx, text, batch) if text else None
    if kind is None:
        return text
    logger.warning("agent final text replaced by fallback: false %s claim", kind)
    return NO_DRAFT_FALLBACK_TEXT if not ctx.state.open_draft_id else ORDER_FALLBACK_TEXT


def _final_no_tools_summary(state: GraphState, messages: list[BaseMessage], last_text: str) -> str:
    if _timed_out(state):
        return last_text
    completed, runnable = _bounded(state, lambda: state["agent_llm_factory"]([]))
    if not completed or runnable is None:
        return last_text
    completed, response = _bounded(state, lambda: runnable.invoke(messages))
    if not completed:
        return last_text
    return _text_content(response) or last_text


def _run_agent(state: GraphState) -> None:
    ctx = state["ctx"]
    batch_size = _consecutive_agent_count(state["intent_queue"])
    batch = state["intent_queue"][:batch_size]
    if not batch:
        return

    if ctx.claims is None and any(label in LOGIN_REQUIRED_LABELS for label in batch) and len(batch) == 1:
        _emit(state, "ui", {"kind": "login_required", "payload": {}})
        _emit(state, "token", {"text": "Bạn cần đăng nhập để tiếp tục với giỏ hàng hoặc đơn hàng nhé."})
        state["intent_queue"] = state["intent_queue"][batch_size:]
        ctx.state.intent_queue = list(state["intent_queue"])
        return

    if _cancelled(state) or _timed_out(state):
        _emit_timeout(state)
        state["halted"] = True
        return

    tools = build_tools(ctx)
    completed, runnable = _bounded(state, lambda: state["agent_llm_factory"](tools))
    if not completed:
        _emit_timeout(state)
        state["halted"] = True
        return
    if runnable is None:
        raise RuntimeError("agent unavailable")

    tool_map = {tool.name: tool for tool in tools}
    messages = _agent_messages(state)
    last_text = ""
    stopped = False
    hit_step_cap = False
    corrected = False

    while not stopped:
        if _cancelled(state) or _timed_out(state):
            state["halted"] = True
            stopped = True
            break
        completed, response = _bounded(state, lambda: runnable.invoke(messages))
        if not completed:
            state["halted"] = True
            stopped = True
            break
        if not isinstance(response, AIMessage):
            response = AIMessage(content=_text_content(response))
        messages.append(response)
        response_text = _text_content(response)
        last_text = response_text or last_text
        tool_calls = list(getattr(response, "tool_calls", []) or [])
        if not tool_calls:
            claim = _claim_kind(ctx, response_text, batch) if response_text else None
            if claim and not corrected and not _cancelled(state) and not _timed_out(state):
                corrected = True
                logger.info("agent claim corrected: kind=%s", claim)
                correction = (
                    _ORDER_CORRECTION if claim == "order" and ctx.state.open_draft_id else _NO_DRAFT_CORRECTION
                )
                messages.append(HumanMessage(content=correction))
                last_text = ""
                continue
            if last_text:
                _emit(state, "token", {"text": _guard_text(ctx, last_text, batch)})
            break

        for call in tool_calls:
            if state.get("steps", 0) >= MAX_STEPS:
                hit_step_cap = True
                state["halted"] = True
                stopped = True
                break
            if _cancelled(state) or _timed_out(state):
                state["halted"] = True
                stopped = True
                break
            call_id = str(call.get("id", ""))
            name = str(call.get("name", ""))
            tool = tool_map.get(name)
            if tool is None:
                messages.append(ToolMessage(content=_tool_error("unknown_tool"), tool_call_id=call_id or name))
                _count_step(state)
                continue
            try:
                args = _tool_args(call)
            except (TypeError, ValueError, json.JSONDecodeError):
                messages.append(ToolMessage(content=_tool_error("invalid_args"), tool_call_id=call_id or name))
                _count_step(state)
                continue
            try:
                completed, result = _bounded(state, lambda: tool.invoke(args))
            except (ValidationError, TypeError, ValueError):
                messages.append(ToolMessage(content=_tool_error("invalid_args"), tool_call_id=call_id or name))
                _count_step(state)
                continue
            if not completed:
                state["halted"] = True
                stopped = True
                break
            messages.append(ToolMessage(content=str(result), tool_call_id=call_id or name))
            _count_step(state)
            _snapshot_state(state)
        if stopped:
            break
        if state.get("steps", 0) >= MAX_STEPS:
            hit_step_cap = True
            state["halted"] = True
            stopped = True

    if hit_step_cap and not state.get("timeout_emitted") and not _cancelled(state):
        summary = _final_no_tools_summary(state, messages, last_text)
        guarded = _guard_text(ctx, summary, batch) if summary else summary
        if guarded and (summary != last_text or guarded != summary):
            _emit(state, "token", {"text": guarded})
        _emit_timeout(state)
    elif stopped and not _cancelled(state):
        if last_text:
            _emit(state, "token", {"text": _guard_text(ctx, last_text, batch)})
        _emit_timeout(state)

    state["intent_queue"] = state["intent_queue"][batch_size:]
    ctx.state.intent_queue = list(state["intent_queue"])


def _remember_legacy_products(ctx: ToolContext, products: Any) -> list[dict]:
    if not isinstance(products, list):
        return []
    remembered = []
    for index, product in enumerate(products, 1):
        if not isinstance(product, dict):
            continue
        product_id = product.get("id") or product.get("product_id")
        if product_id is None:
            continue
        remembered.append({
            "idx": index,
            "id": str(product_id),
            "name": str(product.get("name") or product.get("title") or ""),
        })
    if remembered:
        ctx.state.last_shown_products = remembered
    return remembered


def build_graph(router_llm, agent_llm_factory, legacy: Callable[[str, dict], dict] | None):
    """Build the compiled route/legacy/agent/finish graph."""

    if legacy is None:
        def legacy(message: str, payload: dict) -> dict:
            from chatbot_service.chatbot_flask import xu_ly_cau_hoi

            return xu_ly_cau_hoi(message, payload)

    def route_node(state: GraphState) -> dict:
        ctx = state["ctx"]
        if _cancelled(state) or _timed_out(state):
            _emit_timeout(state)
            return {"halted": True, "intent_queue": []}
        completed, routed = _bounded(
            state,
            lambda: classify(state["message"], ctx.state, state["recent_turns"], router_llm),
        )
        if not completed:
            _emit_timeout(state)
            return {"halted": True, "intent_queue": []}
        queue = [intent.label for intent in routed.intents]
        clarification = routed.needs_clarification
        payment_question = is_unsupported_payment_question(state["message"])
        if payment_question:
            # The store only supports COD / VNPay / QR transfer: the legacy bot must not improvise.
            queue = [label for label in queue if label not in LEGACY_LABELS]
            if ctx.claims is None:
                queue = [label for label in queue if label not in LOGIN_REQUIRED_LABELS]
            clarification = None
        ctx.state.intent_queue = list(queue)
        _snapshot_state(state)
        state["router_output"] = routed
        if payment_question and "BUY" not in queue:
            _emit(state, "token", {"text": PAYMENT_SUPPORT_TEXT})
            if not queue:
                ctx.state.intent_queue = []
                _snapshot_state(state)
                return {"router_output": routed, "intent_queue": [], "halted": True}
            return {"router_output": routed, "intent_queue": queue}
        if clarification and ctx.claims is None and any(
            label in LOGIN_REQUIRED_LABELS for label in queue
        ):
            _emit(state, "ui", {"kind": "login_required", "payload": {}})
            _emit(state, "token", {"text": "Bạn cần đăng nhập để tiếp tục với giỏ hàng hoặc đơn hàng nhé."})
            ctx.state.intent_queue = []
            _snapshot_state(state)
            return {"router_output": routed, "intent_queue": [], "halted": True}
        if clarification:
            _emit(state, "ui", {"kind": "clarify", "payload": {"question": clarification, "options": []}})
            _emit(state, "token", {"text": clarification})
            ctx.state.intent_queue = []
            _snapshot_state(state)
            return {"router_output": routed, "intent_queue": [], "halted": True}
        if not queue:
            _emit(state, "token", {"text": DEFAULT_TEXT})
            ctx.state.intent_queue = []
            _snapshot_state(state)
            return {"router_output": routed, "intent_queue": [], "halted": True}
        return {"router_output": routed, "intent_queue": queue}

    def legacy_node(state: GraphState) -> dict:
        if _cancelled(state) or _timed_out(state):
            _emit_timeout(state)
            return {"halted": True, "intent_queue": []}
        completed, result = _bounded(state, lambda: legacy(state["message"], state["legacy_payload"]))
        if not completed:
            _emit_timeout(state)
            return {"halted": True, "intent_queue": []}
        if not isinstance(result, dict):
            raise RuntimeError("legacy response invalid")
        answer = result.get("answer")
        if isinstance(answer, str) and answer.strip():
            _emit(state, "token", {"text": answer})
        products = result.get("products")
        if products:
            _emit(state, "ui", {"kind": "product_list", "payload": {"products": products}})
            _remember_legacy_products(state["ctx"], products)
        state["intent_queue"] = state["intent_queue"][1:]
        state["ctx"].state.intent_queue = list(state["intent_queue"])
        _snapshot_state(state)
        return {"intent_queue": state["intent_queue"], "timeout_emitted": state.get("timeout_emitted", False)}

    def agent_node(state: GraphState) -> dict:
        _run_agent(state)
        if not state.get("halted") and not state.get("timeout_emitted"):
            _snapshot_state(state)
        return {
            "intent_queue": state["intent_queue"],
            "steps": state.get("steps", 0),
            "halted": state.get("halted", False),
            "timeout_emitted": state.get("timeout_emitted", False),
        }

    def finish_node(state: GraphState) -> dict:
        return {}

    def next_node(state: GraphState) -> str:
        if state.get("halted") or not state.get("intent_queue"):
            return "finish"
        label = state["intent_queue"][0]
        if label in LEGACY_LABELS:
            return "legacy"
        if label in AGENT_LABELS:
            return "agent"
        return "finish"

    workflow = StateGraph(GraphState)
    workflow.add_node("route", route_node)
    workflow.add_node("legacy", legacy_node)
    workflow.add_node("agent", agent_node)
    workflow.add_node("finish", finish_node)
    workflow.add_edge(START, "route")
    workflow.add_conditional_edges("route", next_node, {"legacy": "legacy", "agent": "agent", "finish": "finish"})
    workflow.add_conditional_edges("legacy", next_node, {"legacy": "legacy", "agent": "agent", "finish": "finish"})
    workflow.add_conditional_edges("agent", next_node, {"legacy": "legacy", "agent": "agent", "finish": "finish"})
    workflow.add_edge("finish", END)
    compiled = workflow.compile()
    compiled.agent_llm_factory = agent_llm_factory
    return compiled


def run_turn(
    graph,
    *,
    message: str,
    ctx: ToolContext,
    history: list[BaseMessage],
    recent_turns: list[dict],
    legacy_payload: dict,
    conversation_id: str | None = None,
    skin_profile: dict | None = None,
    clock: Callable[[], float] = time.monotonic,
    state_store: StateStore | None = None,
) -> Iterator[Event]:
    """Run one turn as a live event generator with one terminal event."""

    if state_store is not None:
        normalized_conversation_id = conversation_id.strip() if isinstance(conversation_id, str) else None
        if normalized_conversation_id:
            ctx.state = state_store.load(normalized_conversation_id)
        else:
            ctx.state = ConvState()
    else:
        normalized_conversation_id = None
    if skin_profile is not None:
        ctx.skin_profile = dict(skin_profile)

    events: list[Event] = []
    event_queue: Queue[Event | None] = Queue()
    emit_gate = _TurnEmitGate()
    cancel_event = threading.Event()
    input_state: GraphState = {
        "message": message,
        "ctx": ctx,
        "history": list(history),
        "recent_turns": list(recent_turns),
        "legacy_payload": dict(legacy_payload),
        "events": events,
        "event_sink": event_queue.put,
        "intent_queue": [],
        "turn_started": clock(),
        "clock": clock,
        "halted": False,
        "steps": 0,
        "timeout_emitted": False,
        "agent_llm_factory": graph.agent_llm_factory,
        "emit_gate": emit_gate,
        "cancel_event": cancel_event,
        "snapshot_box": {},
    }
    _snapshot_state(input_state)
    mutations: set[str] = set()

    def _emit_observing_mutations(kind: str, payload: dict) -> None:
        if kind == "cart_effect":
            mutations.add("cart")
        elif kind == "ui" and isinstance(payload, dict) and payload.get("kind") == "draft_card":
            mutations.add("draft")
        _publish(input_state, (kind, payload))

    ctx.emit = _emit_observing_mutations

    def _failure_terminal() -> Event:
        # A write tool already succeeded this turn: telling the customer "error" would make them retry
        # and double the cart/draft change. Say what happened instead.
        if mutations and not cancel_event.is_set():
            text = MUTATION_DRAFT_TEXT if "draft" in mutations else MUTATION_CART_TEXT
            _publish(input_state, ("token", {"text": text}))
            return ("done", {})
        return ("error", {"message": ERROR_TEXT})

    def worker() -> None:
        state_to_save: ConvState | None = None
        terminal: Event = ("done", {})
        base_exception: BaseException | None = None
        try:
            graph.invoke(input_state)
        except Exception as exc:
            logger.warning("turn worker failed: %s", type(exc).__name__)
            terminal = _failure_terminal()
        except BaseException as exc:
            logger.warning("turn worker failed: %s", type(exc).__name__)
            terminal = _failure_terminal()
            base_exception = exc

        try:
            state_to_save = _state_to_save(input_state)
        except BaseException as exc:
            logger.warning("state preparation failed: %s", type(exc).__name__)

        if state_to_save is not None and state_store is not None and normalized_conversation_id:
            try:
                state_store.save(normalized_conversation_id, state_to_save)
            except BaseException as exc:
                logger.warning("state store save failed: %s", type(exc).__name__)

        _publish_terminal(input_state, terminal)
        event_queue.put(None)
        if base_exception is not None:
            raise base_exception

    worker_future = _TURN_EXECUTOR.submit(worker)
    deadline = time.monotonic() + max(0.0, TURN_TIMEOUT_S) + _EVENT_QUEUE_GRACE_S
    timed_out_consumer = False
    terminal_yielded = False

    def _drain_after_failed_terminal_publish() -> list[Event]:
        drained: list[Event] = []
        while True:
            try:
                queued = event_queue.get_nowait()
            except Empty:
                break
            if queued is None:
                break
            drained.append(queued)
            if queued[0] in {"done", "error"}:
                break
        return drained

    def _timeout_events() -> list[Event]:
        cancel_event.set()
        terminal = ("error", {"message": ERROR_TEXT})
        if _publish_terminal(input_state, terminal):
            return [terminal]
        drained = _drain_after_failed_terminal_publish()
        if not any(event[0] in {"done", "error"} for event in drained):
            drained.append(terminal)
        return drained

    try:
        while True:
            try:
                event = event_queue.get_nowait()
            except Empty:
                if terminal_yielded:
                    break
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    timed_out_consumer = True
                    for event in _timeout_events():
                        if event[0] in {"done", "error"}:
                            terminal_yielded = True
                        yield event
                    break
                try:
                    event = event_queue.get(timeout=remaining)
                except Empty:
                    if terminal_yielded:
                        break
                    timed_out_consumer = True
                    for event in _timeout_events():
                        if event[0] in {"done", "error"}:
                            terminal_yielded = True
                        yield event
                    break
            if event is None:
                if not terminal_yielded:
                    terminal_yielded = True
                    fallback = ("error", {"message": ERROR_TEXT})
                    _publish_terminal(input_state, fallback)
                    yield fallback
                break
            if event[0] in {"done", "error"}:
                terminal_yielded = True
            yield event
    except GeneratorExit:
        cancel_event.set()
        emit_gate.close()
        raise

    if not timed_out_consumer:
        worker_future.result()
