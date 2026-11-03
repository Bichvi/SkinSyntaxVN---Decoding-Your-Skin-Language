from types import SimpleNamespace

import pytest
import requests

from agent_graph.php_client import PhpClient, PhpError


class FakeSession:
    def __init__(self, response):
        self.response = response
        self.calls = []

    def post(self, *args, **kwargs):
        self.calls.append((args, kwargs))
        return self.response


def test_ping_sends_internal_headers_and_json():
    response = SimpleNamespace(status_code=200, json=lambda: {"ok": True, "sub": "7"}, text="")
    session = FakeSession(response)
    client = PhpClient("http://nginx:8081/index.php", "internal-secret", "agent-token", session=session)

    assert client.ping() == {"ok": True, "sub": "7"}
    args, kwargs = session.calls[0]
    assert args == ("http://nginx:8081/index.php",)
    assert kwargs["params"] == {"r": "internal_agent_ping"}
    assert kwargs["json"] == {}
    assert kwargs["headers"] == {
        "X-Internal-Token": "internal-secret",
        "Authorization": "Bearer agent-token",
        "X-Agent-Token": "agent-token",
    }


def test_http_error_becomes_php_error():
    response = SimpleNamespace(status_code=403, json=lambda: {"code": "forbidden", "message": "no access", "token": "token-leak"}, text="token-leak")
    client = PhpClient("http://php/index.php", "internal-secret", "agent-token", session=FakeSession(response))

    with pytest.raises(PhpError) as caught:
        client.call("internal_agent_ping")

    assert caught.value.status == 403
    assert caught.value.code == "forbidden"
    assert str(caught.value) == "no access"
    assert "token-leak" not in str(caught.value)


def test_http_error_uses_generic_message_without_leaking_body():
    response = SimpleNamespace(status_code=409, json=lambda: {"error": "out_of_stock", "secret": "token-leak"}, text="token-leak")
    client = PhpClient("http://php/index.php", "internal-secret", "agent-token", session=FakeSession(response))
    with pytest.raises(PhpError) as caught:
        client.call("internal_agent_cart_add")
    assert caught.value.code == ""
    assert caught.value.message == "PHP request failed with HTTP 409"
    assert "token-leak" not in str(caught.value)


def test_non_json_success_becomes_generic_php_error():
    response = SimpleNamespace(status_code=200, json=lambda: (_ for _ in ()).throw(ValueError("raw-secret")))
    client = PhpClient("http://php/index.php", "internal-secret", "agent-token", session=FakeSession(response))

    with pytest.raises(PhpError) as caught:
        client.call("internal_agent_ping")

    assert caught.value.status == 200
    assert str(caught.value) == "Invalid PHP response"
    assert "raw-secret" not in str(caught.value)


def test_request_exception_becomes_generic_php_error():
    class FailingSession:
        def post(self, *args, **kwargs):
            raise requests.Timeout("agent-token should not be exposed")

    client = PhpClient("http://php/index.php", "internal-secret", "agent-token", session=FailingSession())

    with pytest.raises(PhpError) as caught:
        client.call("internal_agent_ping")

    assert caught.value.status == 0
    assert str(caught.value) == "PHP request failed"
    assert "agent-token" not in str(caught.value)
