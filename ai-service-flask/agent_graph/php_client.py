from __future__ import annotations

from typing import Any

import requests


class PhpError(RuntimeError):
    def __init__(self, status: int, message: str, code: str = ""):
        super().__init__(message)
        self.status = status
        self.message = message
        self.code = code


class PhpClient:
    def __init__(self, base_url: str, internal_secret: str, agent_token: str, timeout: float = 8.0, session: Any = None):
        self.base_url = base_url
        self.internal_secret = internal_secret
        self.agent_token = agent_token
        self.timeout = timeout
        self.session = session or requests.Session()

    def call(self, route: str, payload: dict | None = None) -> dict:
        try:
            response = self.session.post(
                self.base_url,
                params={'r': route},
                json=payload or {},
                headers={
                    'X-Internal-Token': self.internal_secret,
                    'Authorization': f'Bearer {self.agent_token}',
                    'X-Agent-Token': self.agent_token,
                },
                timeout=self.timeout,
            )
        except requests.RequestException as exc:
            raise PhpError(0, 'PHP request failed') from exc
        if response.status_code >= 400:
            generic = f'PHP request failed with HTTP {response.status_code}'
            code, message = "", generic
            try:
                body = response.json()
                if isinstance(body, dict):
                    if isinstance(body.get("code"), str):
                        code = body["code"]
                    if isinstance(body.get("message"), str) and body["message"]:
                        message = body["message"]
            except (ValueError, TypeError):
                pass
            raise PhpError(response.status_code, message, code=code)
        try:
            body = response.json()
        except (ValueError, TypeError) as exc:
            raise PhpError(response.status_code, 'Invalid PHP response') from exc
        if not isinstance(body, dict):
            raise PhpError(response.status_code, 'Invalid PHP response')
        return body

    def ping(self) -> dict:
        return self.call('internal_agent_ping')
