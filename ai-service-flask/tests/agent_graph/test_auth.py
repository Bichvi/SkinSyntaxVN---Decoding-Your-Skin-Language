import pytest
import base64
import hashlib
import hmac
import json
import os
from types import SimpleNamespace

from agent_graph.auth import AuthError, identity_from_request, verify_agent_token


VECTOR = (
    "eyJzdWIiOiI3IiwiZW0iOiJ4QHkueiIsInNpZCI6ImMxIiwic2NvcGUiOlsiY2hhdCJdLCJleHAiOjE5MDAwMDAwMDAsImp0aSI6ImFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhIn0."
    "ZO3zgrq5otSKzQ2ViudDSqFTFmf3bgel-rRuShpAUOI"
)


def test_vector_matches_php():
    claims = verify_agent_token(VECTOR, "vector-secret", now=1_899_999_999)
    assert claims.sub == "7"
    assert claims.em == "x@y.z"
    assert claims.sid == "c1"
    assert claims.scope == ("chat",)


def test_expired():
    with pytest.raises(AuthError):
        verify_agent_token(VECTOR, "vector-secret", now=1_900_000_000)


def test_bad_signature():
    with pytest.raises(AuthError):
        verify_agent_token(VECTOR[:-1] + "A", "vector-secret", now=1_899_999_999)


def test_empty_secret_rejects():
    with pytest.raises(AuthError):
        verify_agent_token(VECTOR, "", now=1_899_999_999)


def _issue_token(secret: str, email: str) -> str:
    claims = {
        "sub": "7",
        "em": email,
        "sid": "",
        "scope": ["chat"],
        "exp": 2_000_000_000,
        "jti": "b" * 32,
    }
    payload = base64.urlsafe_b64encode(
        json.dumps(claims, separators=(",", ":"), ensure_ascii=False).encode()
    ).decode().rstrip("=")
    signature = hmac.new(secret.encode(), payload.encode(), hashlib.sha256).digest()
    return f"{payload}.{base64.urlsafe_b64encode(signature).decode().rstrip('=')}"


@pytest.fixture
def chatbot_routes(monkeypatch):
    from chatbot_service import chatbot_flask as routes
    import agent_graph.config as config

    monkeypatch.setattr(config, "TOKEN_SECRET", "route-secret")
    captured = {}

    def fake_chat(message, msg_data=None):
        captured["message"] = message
        captured["verified_email"] = msg_data.get("_verified_email")
        captured["customer_profile_email"] = (msg_data.get("customer_profile") or {}).get("email")
        return {"ok": True, "answer": "stub", "products": [], "conflicts": []}

    monkeypatch.setattr(routes, "xu_ly_cau_hoi", fake_chat)
    monkeypatch.setattr(routes, "create_new_conversation", lambda *_args: None)
    monkeypatch.setattr(routes, "save_chat_messages", lambda *_args: None)
    monkeypatch.setattr(routes, "get_conversation_by_id", lambda *_args: None)
    return routes, captured


def test_identity_from_request_ignores_body_email(chatbot_routes):
    routes, captured = chatbot_routes
    response = routes.app.test_client().post(
        "/api/chat/auto",
        json={"message": "hi", "user_email": "victim@x.com"},
    )
    assert response.status_code == 200
    assert captured["verified_email"] is None


def test_chat_uses_token_email_instead_of_body_email(chatbot_routes):
    routes, captured = chatbot_routes
    token = _issue_token("route-secret", "trusted@x.com")
    response = routes.app.test_client().post(
        "/api/chat/auto",
        headers={"X-Agent-Token": token},
        json={"message": "hi", "user_email": "victim@x.com"},
    )
    assert response.status_code == 200
    assert captured["verified_email"] == "trusted@x.com"


def test_customer_profile_email_does_not_become_persistence_identity(chatbot_routes):
    routes, captured = chatbot_routes
    response = routes.app.test_client().post(
        "/api/chat/auto",
        json={
            "message": "hi",
            "customer_profile": {"email": "victim@x.com"},
        },
    )
    assert response.status_code == 200
    assert captured["customer_profile_email"] == "victim@x.com"
    assert captured["verified_email"] is None


def test_pipeline_never_passes_body_profile_email_to_survey_persistence(monkeypatch):
    from chatbot_service import chatbot_flask as routes

    monkeypatch.setattr(routes, "get_llms", lambda: [])
    monkeypatch.setattr(routes, "get_vectorstore", lambda: None)
    monkeypatch.setattr(routes, "get_hybrid_pipeline", lambda: SimpleNamespace(reranker=None))
    monkeypatch.setattr(routes, "get_groq_llama_70b", lambda: None)
    monkeypatch.setattr(routes, "contextualize_query", lambda message, _history, _llms: message)
    monkeypatch.setattr(routes, "classify_intent", lambda _message, _llms: ("GENERAL_CONVERSATION", None))
    monkeypatch.setattr(
        routes,
        "rule_based_parse",
        lambda _message: SimpleNamespace(
            loai_da=None,
            loai_san_pham=None,
            ngan_sach=None,
            muc_gia=None,
            tu_khoa_ngu_nghia="",
            is_routine=False,
        ),
    )
    monkeypatch.setattr(routes.graph_router, "enabled", lambda: False)
    monkeypatch.setattr(routes.store_support, "extract_articles", lambda _data: [])
    monkeypatch.setattr(routes, "is_in_survey_flow", lambda _message, _history: True)
    persisted_emails = []
    monkeypatch.setattr(routes, "save_user_profile", lambda email, *_args, **_kwargs: persisted_emails.append(email))
    monkeypatch.setattr(
        routes,
        "handle_survey_flow",
        lambda _message, _history, email: (persisted_emails.append(email) or {"completed": False, "answer": "ok"}),
    )

    result = routes.xu_ly_cau_hoi(
        "hi",
        {"customer_profile": {"email": "victim@x.com"}, "_verified_email": None},
    )
    assert result["ok"] is True
    assert persisted_emails == [""]


def test_chatbot_flask_import_keeps_external_credentials_empty():
    from chatbot_service import chatbot_flask

    assert chatbot_flask is not None
    for name in (
        "MONGO_URI", "NEO4J_URI", "NEO4J_PASSWORD", "REDIS_URL",
        "OPENAI_API_KEY", "GOOGLE_API_KEY", "GOOGLE_API_KEYS", "GROQ_API_KEY",
        "ZHIPU_API_KEY", "OPENROUTER_API_KEY", "TAVILY_API_KEY",
    ):
        assert os.environ[name] == ""
