"""Exercise both legacy context builders with the structured PHP cart payload."""

from types import SimpleNamespace

from chatbot_service import chatbot_flask, pipeline


MESSAGE = "Tìm serum cho da dầu"


def cart_payload():
    return {
        "customer_profile": {"skin_type": "Da dầu", "skin_issues": [{"label": "Mụn"}, "Bóng dầu"]},
        "conversation_history": [{"sender": "user", "text": "Tôi muốn tìm serum"}],
        "cart_items": [{"name": "Serum A"}, {"name": "Serum B"}],
        "cart_conflicts": [{"product_a": "Serum A", "product_b": "Serum B",
                            "warning": "Nên dùng cách ngày"}],
    }


def test_legacy_pipeline_builds_context_and_preserves_cart_objects(monkeypatch):
    payload = cart_payload()
    fake_search = SimpleNamespace(search=lambda **_: ([], {}))
    monkeypatch.setattr(pipeline, "get_llms", lambda: [])
    monkeypatch.setattr(pipeline, "get_classifier_llm", lambda: None)
    monkeypatch.setattr(pipeline, "get_hybrid_pipeline", lambda: fake_search)
    monkeypatch.setattr(pipeline, "get_session_state", lambda _: {})
    monkeypatch.setattr(pipeline, "_analyze_and_parse", lambda *_: (
        MESSAGE, "PRODUCT_INQUIRY", None, False, pipeline._rule_based_parse(MESSAGE),
    ))

    context = pipeline._prepare_pipeline(MESSAGE, payload)

    assert context["cart_conflicts"] == payload["cart_conflicts"]
    assert "Serum A / Serum B / Nên dùng cách ngày" in context["prompt_vars"]["rich_context"]
    assert "Bóng dầu" in context["prompt_vars"]["rich_context"]
    assert "Tôi muốn tìm serum" in context["chat_history_str"]


def test_flask_chat_pipeline_reaches_graph_with_structured_cart(monkeypatch):
    payload = cart_payload()
    monkeypatch.setattr(chatbot_flask, "get_llms", lambda: [])
    monkeypatch.setattr(chatbot_flask, "get_vectorstore", lambda: None)
    monkeypatch.setattr(chatbot_flask, "get_hybrid_pipeline", lambda: None)
    monkeypatch.setattr(chatbot_flask, "get_groq_llama_70b", lambda: None)
    monkeypatch.setattr(chatbot_flask, "is_in_survey_flow", lambda *_: False)
    monkeypatch.setattr(chatbot_flask.graph_router, "enabled", lambda: True)
    monkeypatch.setattr(chatbot_flask.graph_router, "get_runtime", lambda: None)

    def graph_answer(message, data, runtime, **_):
        assert message == MESSAGE
        assert data["cart_conflicts"] == payload["cart_conflicts"]
        return {"ok": True, "answer": "Fixture", "conflicts": data["cart_conflicts"]}

    monkeypatch.setattr(chatbot_flask.graph_router, "try_answer", graph_answer)
    reply = chatbot_flask.xu_ly_cau_hoi(MESSAGE, payload)
    assert reply["ok"] and reply["answer"] == "Fixture"
    assert reply["conflicts"] == payload["cart_conflicts"]
