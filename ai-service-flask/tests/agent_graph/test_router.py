from __future__ import annotations

import json

import pytest
from langchain_core.messages import HumanMessage, SystemMessage

from agent_graph.router import RouterOutput, classify, fast_route
from agent_graph.state import ConvState, StateStore


class FakeRouter:
    def __init__(self, result=None, error=None):
        self.result = result
        self.error = error
        self.messages = None

    def invoke(self, messages):
        self.messages = messages
        if self.error:
            raise self.error
        return self.result


def output(*labels, entities=None, clarification=None):
    return RouterOutput(
        intents=[{"label": label, "confidence": 0.9} for label in labels],
        entities=entities or {},
        needs_clarification=clarification,
    )


def test_order_code_fast_routes():
    result = classify("tra #DH1234 giúp tui", ConvState(), [], FakeRouter())
    assert [item.label for item in result.intents] == ["ORDER_STATUS"]
    assert result.entities.order_code == "DH1234"


def test_draft_open_payment_change_is_buy():
    result = classify("đổi sang vnpay", ConvState(pending="draft_open:DR1"), [], FakeRouter())
    assert result.intents[0].label == "BUY"
    assert result.entities.payment_method == "vnpay"


def test_fast_route_extracts_transfer_payment_and_combo_products():
    state = ConvState(last_shown_products=[{"idx": 1, "id": "p1"}, {"idx": 2, "id": "p2"}])
    result = classify("chốt combo này, chuyển khoản nhé", state, [], FakeRouter())
    assert result.entities.payment_method == "bank_transfer_qr"
    assert result.entities.resolved_product_ids == ["p1", "p2"]


def test_classify_sends_customer_message_as_human_message():
    router = FakeRouter(output("ADVISE"))
    classify("unique-customer-token-7f3a", ConvState(), [], router)
    assert isinstance(router.messages[0], SystemMessage)
    assert isinstance(router.messages[1], HumanMessage)
    assert "unique-customer-token-7f3a" in router.messages[1].content
    assert "unique-customer-token-7f3a" not in router.messages[0].content


def test_fast_route_does_not_treat_purchase_as_order_status():
    message = "cho tui đặt hàng 1 chai sữa rửa mặt some by me màu tím, về địa chỉ nhà tôi"
    assert fast_route(message, ConvState()) is None


def test_don_without_accent_is_not_confused_with_don():
    assert fast_route("đón tôi đâu rồi", ConvState()) is None


def test_shop_address_question_is_not_draft_buy_route():
    assert fast_route("shop có địa chỉ ở đâu", ConvState(pending="draft_open:DR1")) is None


def test_all_shown_products_resolve_without_llm():
    state = ConvState(last_shown_products=[
        {"idx": 1, "id": "p1", "name": "One"},
        {"idx": 2, "id": "p2", "name": "Two"},
    ])
    result = classify("cho tôi toàn combo này đặt giao về nhà tôi", state, [], FakeRouter(output("BUY")))
    assert result.entities.resolved_product_ids == ["p1", "p2"]
    assert result.entities.scope == "mentioned_items"


def test_cart_scope_wins_over_all_shown_rule():
    state = ConvState(last_shown_products=[{"idx": 1, "id": "p1"}, {"idx": 2, "id": "p2"}])
    result = classify("đặt tất cả trong giỏ", state, [], FakeRouter(output("BUY")))
    assert result.entities.scope == "whole_cart"
    assert result.entities.resolved_product_ids == []


@pytest.mark.parametrize("message, expected", [
    ("mua chai thứ hai", ["p2"]),
    ("mua lọ đầu tiên", ["p1"]),
    ("mua hộp cuối cùng", ["p3"]),
])
def test_ordinals_resolve_supported_forms(message, expected):
    state = ConvState(last_shown_products=[{"id": "p1"}, {"id": "p2"}, {"id": "p3"}])
    result = classify(message, state, [], FakeRouter(output("BUY")))
    assert result.entities.resolved_product_ids == expected


@pytest.mark.parametrize("message", ["mua cái thứ 6", "mua tuýp thứ hai"])
def test_ordinal_out_of_range_or_empty_needs_clarification(message):
    state = ConvState(last_shown_products=[] if "thứ 6" in message else [{"id": "p1"}])
    result = classify(message, state, [], FakeRouter(output("BUY")))
    assert result.needs_clarification == "Bạn đang nói tới sản phẩm nào ạ?"


def test_hallucinated_resolved_id_is_dropped_and_clarified():
    state = ConvState(last_shown_products=[{"id": "p1"}])
    result = classify("mua sản phẩm này", state, [], FakeRouter(RouterOutput(
        intents=[{"label": "BUY", "confidence": 0.9}],
        entities={"product_refs": ["sản phẩm này"], "resolved_product_ids": ["zz"]},
    )))
    assert result.entities.resolved_product_ids == []
    assert result.needs_clarification == "Bạn đang nói tới sản phẩm nào ạ?"


def test_ordinal_without_shown_products_needs_clarification():
    result = classify("cho tôi cái thứ 2", ConvState(), [], FakeRouter(output("BUY")))
    assert result.needs_clarification == "Bạn đang nói tới sản phẩm nào ạ?"


def test_named_product_reference_does_not_trigger_clarification():
    message = "đặt cho mình 1 chai sữa rửa mặt some by mi"
    router = FakeRouter(RouterOutput(
        intents=[{"label": "BUY", "confidence": 0.9}],
        entities={"product_refs": ["sữa rửa mặt some by mi"]},
    ))
    result = classify(message, ConvState(), [], router)
    assert result.needs_clarification is None
    assert "BUY" in [item.label for item in result.intents]


def test_ordinal_with_empty_shown_products_still_clarifies():
    result = classify("cái thứ 2 cho mình", ConvState(), [], FakeRouter(RouterOutput(
        intents=[{"label": "BUY", "confidence": 0.9}],
        entities={"product_refs": ["cái thứ 2"]},
    )))
    assert result.needs_clarification == "Bạn đang nói tới sản phẩm nào ạ?"


def test_low_confidence_asks():
    result = classify("cái đó sao?", ConvState(), [], FakeRouter(RouterOutput(
        intents=[{"label": "PRODUCT_INFO", "confidence": 0.4}],
        needs_clarification="Bạn muốn hỏi sản phẩm nào?",
    )))
    assert result.needs_clarification == "Bạn muốn hỏi sản phẩm nào?"


def test_low_confidence_uses_default_clarification():
    result = classify("cái đó sao?", ConvState(), [], FakeRouter(RouterOutput(
        intents=[{"label": "PRODUCT_INFO", "confidence": 0.4}],
    )))
    assert result.needs_clarification == "Bạn đang nói tới sản phẩm nào ạ?"


def test_empty_llm_intents_default_to_advise():
    result = classify("tư vấn giúp mình", ConvState(), [], FakeRouter(RouterOutput()))
    assert [(item.label, item.confidence) for item in result.intents] == [("ADVISE", 0.5)]


def test_llm_failure_defaults_to_advise():
    result = classify("tư vấn giúp mình", ConvState(), [], FakeRouter(error=RuntimeError("down")))
    assert [item.label for item in result.intents] == ["ADVISE"]
    assert result.intents[0].confidence == 0.5


def test_llm_failure_resolves_combo_and_uses_buy_rule(caplog):
    state = ConvState(last_shown_products=[{"id": "p1"}, {"id": "p2"}])
    with caplog.at_level("WARNING"):
        result = classify(
            "cho tôi toàn combo này đặt giao về nhà tôi",
            state,
            [],
            FakeRouter(error=TimeoutError("rate limited")),
        )
    assert result.intents[0].label == "BUY"
    assert result.intents[0].confidence == 0.8
    assert result.entities.resolved_product_ids == ["p1", "p2"]
    assert result.fallback is True
    assert result.error == "TimeoutError"
    assert sum("router LLM failed: TimeoutError" in record.message for record in caplog.records) == 1


def test_state_roundtrip_and_redis_down():
    class FakeRedis:
        def __init__(self):
            self.values = {}
            self.expiry = None

        def setex(self, key, ttl, value):
            self.values[key] = value
            self.expiry = ttl

        def get(self, key):
            return self.values.get(key)

    redis = FakeRedis()
    store = StateStore(redis)
    state = ConvState(
        last_shown_products=[{"id": "p1", "name": "One", "idx": 1, "price": 999}],
        open_draft_id="DR1",
        pending="draft_open:DR1",
        intent_queue=["BUY"],
    )
    store.save("sid", state)
    raw = json.loads(redis.values["skinsyntax:agent:state:sid"])
    assert raw["last_shown_products"] == [{"id": "p1", "name": "One", "idx": 1}]
    assert "address" not in json.dumps(raw)
    assert redis.expiry == 7200
    loaded = store.load("sid")
    assert loaded.last_shown_products == [{"id": "p1", "name": "One", "idx": 1}]
    assert loaded.open_draft_id == state.open_draft_id
    assert loaded.pending == state.pending
    assert loaded.intent_queue == state.intent_queue

    class DownRedis:
        def get(self, key):
            raise RuntimeError("redis down")

    assert StateStore(DownRedis()).load("sid") == ConvState()


def test_state_save_swallows_redis_errors():
    class DownRedis:
        def setex(self, key, ttl, value):
            raise RuntimeError("redis down")

    StateStore(DownRedis()).save("sid", ConvState())
