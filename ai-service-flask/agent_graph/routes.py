"""Flask boundary for the v2 commerce agent."""

from __future__ import annotations

import hmac
import json
import logging
import os
import re
import threading
from concurrent.futures import ThreadPoolExecutor
from contextlib import closing
from typing import Any, Iterator

from flask import Blueprint, Response, jsonify, request, stream_with_context
from langchain_core.messages import AIMessage, HumanMessage

from . import config
from .auth import identity_from_request
from .graph import build_graph, run_turn
from .llm import build_agent_llms, build_router_llms, with_schema, with_tools
from .php_client import PhpClient
from .prompts import PROMPT_VERSION
from .router import RouterOutput
from .state import ConvState, StateStore
from .tools import ToolContext


logger = logging.getLogger(__name__)
agent_bp = Blueprint("agent", __name__)

_graph_lock = threading.Lock()
_graph_initialized = False
_graph: Any | None = None
_state_store_lock = threading.Lock()
_redis_client: Any | None = None
_persistence_executor = ThreadPoolExecutor(max_workers=2, thread_name_prefix="agent-persist")
_EVENT_NAMES = {"meta", "token", "ui", "cart_effect", "done", "error"}
_TERMINAL_NAMES = {"done", "error"}


def _get_graph() -> Any | None:
    """Build the graph once, without constructing provider clients at import time."""

    global _graph, _graph_initialized
    if _graph_initialized:
        return _graph
    with _graph_lock:
        if _graph_initialized:
            return _graph
        router_llms = build_router_llms()
        agent_llms = build_agent_llms()
        if router_llms and agent_llms:
            router_llm = with_schema(router_llms, RouterOutput)
            if router_llm is not None:
                _graph = build_graph(
                    router_llm,
                    lambda tools: with_tools(agent_llms, tools),
                    legacy=None,
                )
        _graph_initialized = True
        return _graph


def _get_state_store() -> StateStore | None:
    global _redis_client
    if not config.REDIS_URL:
        return None
    try:
        import redis

        if _redis_client is None:
            with _state_store_lock:
                if _redis_client is None:
                    _redis_client = redis.Redis.from_url(
                        config.REDIS_URL,
                        socket_timeout=2,
                        socket_connect_timeout=2,
                    )
        return StateStore(_redis_client)
    except Exception as exc:
        logger.warning("agent state store unavailable: %s", type(exc).__name__)
        return None


def _json_error(message: str, status: int):
    return jsonify({"ok": False, "message": message}), status


def _sse(name: str, payload: dict) -> str:
    return f"event: {name}\ndata: {json.dumps(payload, ensure_ascii=False)}\n\n"


def _cart_snapshot(value: Any) -> list[dict]:
    if not isinstance(value, list):
        return []
    result: list[dict] = []
    for item in value[:50]:
        if not isinstance(item, dict):
            continue
        if (
            not isinstance(item.get("id"), str)
            or not isinstance(item.get("name"), str)
            or not isinstance(item.get("qty"), int)
            or isinstance(item.get("qty"), bool)
            or not isinstance(item.get("unit_price"), int)
            or isinstance(item.get("unit_price"), bool)
        ):
            continue
        result.append({
            "id": item["id"],
            "name": item["name"],
            "qty": item["qty"],
            "unit_price": item["unit_price"],
        })
    return result


def _profile_str_list(value: Any) -> list[str]:
    items = [value] if isinstance(value, str) else value if isinstance(value, list) else []
    result = [item.strip()[:200] for item in items if isinstance(item, str) and item.strip()]
    return result[:10]


def _profile(value: Any) -> dict:
    """Canonical skin profile: skin_type, goal, concerns, avoid_ingredients, budget_max.

    Legacy keys loai_da / van_de are still accepted. Everything else is dropped.
    """
    if not isinstance(value, dict):
        return {}
    result: dict = {}
    skin_type = value.get("skin_type", value.get("loai_da"))
    if isinstance(skin_type, str) and skin_type.strip():
        result["skin_type"] = skin_type.strip()[:200]
    goal = value.get("goal")
    if isinstance(goal, str) and goal.strip():
        result["goal"] = goal.strip()[:200]
    concerns = _profile_str_list(value.get("concerns", value.get("van_de")))
    if concerns:
        result["concerns"] = concerns
    avoid = _profile_str_list(value.get("avoid_ingredients"))
    if avoid:
        result["avoid_ingredients"] = avoid
    budget = value.get("budget_max")
    if isinstance(budget, int) and not isinstance(budget, bool) and budget > 0:
        result["budget_max"] = budget
    sensitivity = value.get("sensitivity")
    if isinstance(sensitivity, str) and sensitivity.strip():
        result["sensitivity"] = sensitivity.strip()[:200]
    updated_at = value.get("updated_at")
    if isinstance(updated_at, str):
        updated_at = updated_at.strip()
        if len(updated_at) <= 40 and re.match(r"^\d{4}-\d{2}-\d{2}", updated_at):
            result["updated_at"] = updated_at
    return result


def _conversation_messages(conversation: dict | None) -> tuple[list[Any], list[dict]]:
    if not isinstance(conversation, dict):
        return [], []
    messages = conversation.get("messages")
    if not isinstance(messages, list):
        return [], []
    history: list[Any] = []
    recent: list[dict] = []
    for item in messages[-10:]:
        if not isinstance(item, dict) or not isinstance(item.get("content"), str):
            continue
        role = item.get("role")
        content = item["content"]
        if role == "user":
            history.append(HumanMessage(content=content))
        elif role == "assistant":
            history.append(AIMessage(content=content))
        else:
            continue
        recent.append({"role": role, "content": content})
    return history, recent[-4:]


def _conversation_helpers():
    # Keep the legacy chatbot module out of route import-time initialization.
    from chatbot_service import chatbot_flask

    return (
        chatbot_flask.create_new_conversation,
        chatbot_flask.save_chat_messages,
        chatbot_flask.get_conversation_by_id,
    )


def warm_agent_search() -> None:
    """Best effort: build the search service, load the catalog and open the facts store before a customer asks.

    A cold first search_products call (service build + catalog + Mongo connect) can exceed the 6 s text-search budget.
    """

    try:
        from search_service.service import get_parts

        catalog = get_parts().get("catalog")
        if catalog is not None:
            catalog.products()
        from chatbot_service.graph_router import get_runtime

        runtime = get_runtime()
        facts = getattr(getattr(getattr(runtime, "tools", None), "deps", None), "facts", None)
        if callable(facts):
            facts(["1"])
    except Exception as exc:
        logger.warning("agent search warm-up degraded: %s", type(exc).__name__)


_warm_pid: int | None = None


@agent_bp.before_app_request
def _warm_once_per_worker() -> None:
    """Gunicorn preloads the app and forks: threads and Mongo clients must start per worker, on first request."""

    global _warm_pid
    if not config.AGENT_WARMUP or _warm_pid == os.getpid():
        return
    _warm_pid = os.getpid()
    threading.Thread(target=warm_agent_search, daemon=True, name="agent-search-warmup").start()


def register(app: Any) -> None:
    app.register_blueprint(agent_bp)


def _persist_conversation(conversation_id: str, email: str, message: str, assistant_text: str) -> None:
    try:
        _conversation_helpers()[1](conversation_id, email, message, assistant_text)
    except Exception as exc:
        logger.warning("agent conversation persistence failed: %s", type(exc).__name__)


def _stream_turn(
    *,
    graph: Any | None,
    context: ToolContext,
    message: str,
    history: list[Any],
    recent_turns: list[dict],
    legacy_payload: dict,
    conversation_id: str | None,
    profile: dict,
    state_store: StateStore | None,
) -> Iterator[str]:
    token_text: list[str] = []
    terminal_sent = False
    completed = False
    try:
        graph = _get_graph()
        if graph is None:
            yield _sse("error", {"message": "Trợ lý AI hiện chưa sẵn sàng, bạn thử lại sau nhé."})
            terminal_sent = True
            completed = True
            return

        with closing(run_turn(
            graph,
            message=message,
            ctx=context,
            history=history,
            recent_turns=recent_turns,
            legacy_payload=legacy_payload,
            conversation_id=conversation_id,
            skin_profile=profile,
            state_store=state_store,
        )) as turn_events:
            for name, payload in turn_events:
                if terminal_sent:
                    break
                if name not in _EVENT_NAMES:
                    continue
                if not isinstance(payload, dict):
                    payload = {}
                if name == "token" and isinstance(payload.get("text"), str):
                    token_text.append(payload["text"])
                yield _sse(name, payload)
                if name in _TERMINAL_NAMES:
                    terminal_sent = True
        if not terminal_sent:
            yield _sse("done", {})
            terminal_sent = True
        completed = True
    except Exception as exc:
        logger.warning("agent turn failed: %s", type(exc).__name__)
        if not terminal_sent:
            yield _sse("error", {"message": "Mình gặp sự cố khi xử lý yêu cầu, bạn thử lại giúp mình nhé."})
    finally:
        assistant_text = "".join(token_text)
        if (
            completed
            and terminal_sent
            and assistant_text.strip()
            and context.claims is not None
            and context.claims.em.strip()
            and conversation_id
        ):
            _persistence_executor.submit(
                _persist_conversation,
                conversation_id,
                context.claims.em.strip(),
                message,
                assistant_text,
            )


@agent_bp.post("/api/agent/chat")
def chat_agent():
    internal_secret = config.INTERNAL_SECRET
    provided_internal = request.headers.get("X-Internal-Token", "")
    if not internal_secret or not hmac.compare_digest(provided_internal, internal_secret):
        return _json_error("Unauthorized", 401)

    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        data = {}
    message = data.get("message")
    if not isinstance(message, str) or not message.strip() or len(message) > 2000:
        return _json_error("message must be a string between 1 and 2000 characters", 400)

    cart_snapshot = _cart_snapshot(data.get("cart_snapshot"))
    profile = _profile(data.get("profile"))
    raw_agent_token = request.headers.get("X-Agent-Token", "")
    claims = identity_from_request(request)
    if not claims or not claims.has("chat") or not claims.em.strip():
        claims = None

    requested_conversation_id = data.get("conversation_id")
    requested_conversation_id = (
        requested_conversation_id.strip() or None
        if isinstance(requested_conversation_id, str)
        else None
    )

    conversation_id: str | None = None
    conversation: dict | None = None
    history: list[Any] = []
    recent_turns: list[dict] = []
    if claims is not None:
        create_new_conversation, _save_chat_messages, get_conversation_by_id = _conversation_helpers()
        email = claims.em.strip()
        if requested_conversation_id:
            conversation = get_conversation_by_id(requested_conversation_id, email)
            if not conversation:
                return _json_error("Không tìm thấy cuộc hội thoại của tài khoản này.", 403)
            conversation_id = requested_conversation_id
        else:
            try:
                conversation_id = create_new_conversation(email, message)
                if conversation_id is not None:
                    conversation_id = str(conversation_id)
            except Exception as exc:
                logger.warning("agent conversation creation failed: %s", type(exc).__name__)
        history, recent_turns = _conversation_messages(conversation)

    # The legacy chatbot reads `budget`; the agent profile uses `budget_max`.
    legacy_customer_profile = dict(profile)
    if "budget_max" in profile:
        legacy_customer_profile["budget"] = profile["budget_max"]
    legacy_payload = {
        "_verified_email": claims.em.strip() if claims else None,
        "customer_profile": legacy_customer_profile,
        "cart_items": cart_snapshot,
        "conversation_history": [
            {"sender": item.get("role"), "text": item.get("content")}
            for item in recent_turns
        ],
    }
    php = PhpClient(config.PHP_INTERNAL_URL, config.INTERNAL_SECRET, raw_agent_token) if claims is not None and raw_agent_token else None
    context = ToolContext(
        claims=claims,
        php=php,
        cart_snapshot=cart_snapshot,
        emit=lambda *_args: None,
        state=ConvState(),
        graph_tools=None,
    )

    def generate() -> Iterator[str]:
        yield _sse("meta", {"conversation_id": conversation_id, "prompt_version": PROMPT_VERSION})
        yield from _stream_turn(
            graph=None,
            context=context,
            message=message,
            history=history,
            recent_turns=recent_turns,
            legacy_payload=legacy_payload,
            conversation_id=conversation_id,
            profile=profile,
            state_store=_get_state_store(),
        )

    response = Response(stream_with_context(generate()), headers={
        "Content-Type": "text/event-stream",
        "Cache-Control": "no-cache",
        "X-Accel-Buffering": "no",
    })
    return response
