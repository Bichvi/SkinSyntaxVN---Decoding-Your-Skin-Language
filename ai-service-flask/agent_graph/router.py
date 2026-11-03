"""Structured intent routing for the conversation agent."""

from __future__ import annotations

import json
import logging
import re
from typing import Literal

from langchain_core.messages import HumanMessage, SystemMessage
from pydantic import BaseModel, Field

from .prompts import ROUTER_PROMPT, render
from .state import ConvState
from search_service.metrics import fold as _search_fold


logger = logging.getLogger(__name__)


IntentLabel = Literal[
    "ADVISE", "PRODUCT_INFO", "KNOWLEDGE", "BUY", "CART", "ORDER_STATUS",
    "STORE_SUPPORT", "SKIN_SURVEY", "SMALLTALK", "OUT_OF_SCOPE",
]


class Intent(BaseModel):
    label: IntentLabel
    confidence: float = Field(ge=0, le=1)


class RouterEntities(BaseModel):
    product_refs: list[str] = Field(default_factory=list)
    resolved_product_ids: list[str] = Field(default_factory=list)
    qty: int | None = None
    skin_type: str | None = None
    concerns: list[str] = Field(default_factory=list)
    budget_max: float | None = None
    brand: str | None = None
    payment_method: Literal["cod", "vnpay", "bank_transfer_qr"] | None = None
    wants_new_address: bool = False
    order_code: str | None = None
    scope: Literal["mentioned_items", "whole_cart"] | None = None


class RouterOutput(BaseModel):
    intents: list[Intent] = Field(default_factory=list)
    entities: RouterEntities = Field(default_factory=RouterEntities)
    needs_clarification: str | None = None
    fallback: bool = False
    error: str | None = None


def fold(text: str) -> str:
    return _search_fold(str(text).replace("đ", "d").replace("Đ", "D"))


def _result(label: IntentLabel, confidence: float = 1.0) -> RouterOutput:
    return RouterOutput(intents=[Intent(label=label, confidence=confidence)])


def fast_route(message: str, state: ConvState) -> RouterOutput | None:
    text = fold(message)
    has_order_word = bool(re.search(r"\b(?:đơn|don)\b", message, re.IGNORECASE))
    if re.search(r"(?<!\w)#?dh\d+(?!\w)", text) or (
        has_order_word and re.search(r"\b(?:don(?: hang)?|cua toi|toi dau|dau roi)\b", text)
    ):
        return _result("ORDER_STATUS")
    if state.pending.startswith("draft_open") and re.search(
        r"\b(?:dia chi|cod|vnpay|chuyen khoan|voucher|ma giam|so luong)\b", text
    ) and not (re.search(r"\b(?:shop|cua hang)\b", text) and re.search(r"\bdia chi\b", text)):
        return _result("BUY")
    if state.last_shown_products and re.search(r"\b(?:them(?: vao)? gio|dat luon|chot)\b", text):
        return _result("BUY")
    return None


_ALL_SHOWN_RE = re.compile(r"\b(?:combo nay|tat ca|ca may cai nay|toan bo)\b")
_ORDINAL_RE = re.compile(
    r"\b(?:cai|chai|lo|tuyp|hop)\s+thu\s+(?P<number>\d+|mot|nhat|hai|nhi|ba|bon|tu|nam)\b"
)
_EDGE_REF_RE = re.compile(r"\b(?:cai|chai|lo|tuyp|hop)\s+(?P<edge>dau(?: tien)?|cuoi(?: cung)?)\b")
_UNRESOLVED_REF_RE = re.compile(
    r"\b(?:cai|chai|lo|tuyp|hop)\s+thu\s+\d+\b|\b(?:lo|chai|cai)\s+(?:dau(?: tien)?|cuoi(?: cung)?)\b|\bcai re hon\b"
)
_DEICTIC_RE = re.compile(
    r"\b(?:cai|chai|lo|tuyp|hop|san pham|mon|combo)\s+(?:do|nay|kia|re hon|dat hon)\b"
    r"|\b(?:combo nay|tat ca|ca may cai nay|toan bo)\b"
    r"|\b(?:cai|chai|lo|tuyp|hop)\s+(?:dau(?: tien)?|cuoi(?: cung)?)\b"
    r"|\bthu\s+(?:\d+|mot|nhat|hai|nhi|ba|bon|tu|nam)\b"
)
_CLARIFICATION = "Bạn đang nói tới sản phẩm nào ạ?"
_NUMBER_WORDS = {"mot": 1, "nhat": 1, "hai": 2, "nhi": 2, "ba": 3, "bon": 4, "tu": 4, "nam": 5}


def _json(value: object) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def _as_output(value: RouterOutput | dict) -> RouterOutput:
    return value if isinstance(value, RouterOutput) else RouterOutput.model_validate(value)


def _extract_entities(message: str, result: RouterOutput) -> RouterOutput:
    entities = result.entities
    updates: dict[str, object] = {}
    order_code = re.search(r"(?<!\w)#?(DH\d+)(?!\w)", message, re.IGNORECASE)
    if order_code:
        updates["order_code"] = order_code.group(1).upper()
    quantity = re.search(r"\b([1-9]\d*)\s+(?:chai|lo|tuyp|hop|san pham|mon)\b", fold(message))
    if quantity:
        updates["qty"] = int(quantity.group(1))
    folded = fold(message)
    if re.search(r"\bcod\b", folded):
        updates["payment_method"] = "cod"
    elif re.search(r"\bvnpay\b", folded):
        updates["payment_method"] = "vnpay"
    elif re.search(r"\bchuyen khoan\b", folded):
        updates["payment_method"] = "bank_transfer_qr"
    if re.search(r"\bsome by me\b", folded):
        updates["brand"] = "Some By Mi"
    if updates:
        entities = entities.model_copy(update=updates)
        return result.model_copy(update={"entities": entities})
    return result


def _resolve_ordinal(message: str, state: ConvState, result: RouterOutput) -> RouterOutput:
    folded = fold(message)
    match = _ORDINAL_RE.search(folded)
    edge_match = _EDGE_REF_RE.search(folded)
    if not match and not edge_match:
        return result
    if match:
        number = match.group("number")
        ordinal = int(number) if number.isdigit() else _NUMBER_WORDS.get(number, 0)
    else:
        ordinal = 1 if edge_match.group("edge").startswith("dau") else len(state.last_shown_products)
    if not state.last_shown_products or ordinal < 1 or ordinal > len(state.last_shown_products):
        return result.model_copy(update={"needs_clarification": result.needs_clarification or _CLARIFICATION})
    product = state.last_shown_products[ordinal - 1]
    if product.get("id") is None:
        return result.model_copy(update={"needs_clarification": result.needs_clarification or _CLARIFICATION})
    entities = result.entities.model_copy(update={"resolved_product_ids": [str(product["id"])]})
    return result.model_copy(update={"entities": entities})


def _resolve_references(message: str, state: ConvState, result: RouterOutput) -> RouterOutput:
    result = _resolve_ordinal(message, state, result)
    entities = result.entities
    shown_ids = {str(product["id"]) for product in state.last_shown_products if product.get("id") is not None}
    filtered_ids = [product_id for product_id in entities.resolved_product_ids if str(product_id) in shown_ids]
    if filtered_ids != entities.resolved_product_ids:
        entities = entities.model_copy(update={"resolved_product_ids": filtered_ids})
        result = result.model_copy(update={"entities": entities})
    if entities.scope == "whole_cart" or re.search(r"\b(?:gio|giỏ)\b", message, re.IGNORECASE):
        entities = entities.model_copy(update={"scope": "whole_cart", "resolved_product_ids": []})
        return result.model_copy(update={"entities": entities})
    updates: dict[str, object] = {}
    if _ALL_SHOWN_RE.search(fold(message)) and state.last_shown_products:
        updates["resolved_product_ids"] = [
            str(product["id"]) for product in state.last_shown_products if product.get("id") is not None
        ]
        updates["scope"] = "mentioned_items"
    elif _ALL_SHOWN_RE.search(fold(message)) and not state.last_shown_products:
        return result.model_copy(update={"needs_clarification": result.needs_clarification or _CLARIFICATION})
    elif (
        _UNRESOLVED_REF_RE.search(fold(message))
        or any(_DEICTIC_RE.search(fold(str(ref))) for ref in entities.product_refs)
    ) and not entities.resolved_product_ids:
        return result.model_copy(update={"needs_clarification": result.needs_clarification or _CLARIFICATION})
    if updates:
        entities = entities.model_copy(update=updates)
        return result.model_copy(update={"entities": entities})
    return result


def _post_process(message: str, state: ConvState, result: RouterOutput) -> RouterOutput:
    result = _extract_entities(message, result)
    result = _resolve_references(message, state, result)
    if not result.intents:
        folded = fold(message)
        if (
            re.search(r"\b(?:dat|mua|lay|chot)\b", folded)
            and result.entities.resolved_product_ids
            and (_ALL_SHOWN_RE.search(folded) or _ORDINAL_RE.search(folded) or _EDGE_REF_RE.search(folded))
        ):
            return _result("BUY", 0.8).model_copy(update={
                "entities": result.entities,
                "fallback": result.fallback,
                "error": result.error,
            })
        return _result("ADVISE", 0.5)
    if all(intent.confidence < 0.6 for intent in result.intents):
        result = result.model_copy(update={"needs_clarification": result.needs_clarification or _CLARIFICATION})
    return result


def classify(message: str, state: ConvState, recent_turns: list[dict], llm) -> RouterOutput:
    """Classify using the structured runnable passed as ``llm`` and its ``.invoke()`` method."""
    routed = fast_route(message, state)
    if routed is not None:
        return _post_process(message, state, routed)
    system_prompt = render(
        ROUTER_PROMPT,
        pending_state=_json(state.pending),
        last_shown_products=_json([
            {key: product[key] for key in ("idx", "id", "name") if key in product}
            for product in state.last_shown_products
        ]),
        recent_turns=_json(recent_turns[-4:]),
    )
    try:
        messages = [SystemMessage(content=system_prompt), HumanMessage(content=message)]
        result = _as_output(llm.invoke(messages)) if llm is not None else None
        if result is None:
            raise RuntimeError("router unavailable")
    except Exception as exc:
        error_type = type(exc).__name__
        logger.warning("router LLM failed: %s", error_type)
        fallback = RouterOutput(fallback=True, error=error_type)
        return _post_process(message, state, fallback)
    return _post_process(message, state, result)
