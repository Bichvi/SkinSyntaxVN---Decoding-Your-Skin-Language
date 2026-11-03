from __future__ import annotations

import base64
import hashlib
import hmac
import json
import logging
import time
from dataclasses import dataclass
from typing import Any

from . import config

log = logging.getLogger(__name__)
_missing_secret_warned = False


class AuthError(ValueError):
    """Raised when an agent token is malformed, invalid, or expired."""


@dataclass(frozen=True)
class AgentClaims:
    sub: str
    em: str
    sid: str
    scope: tuple[str, ...]
    exp: int
    jti: str

    def has(self, scope: str) -> bool:
        return scope in self.scope


def _decode(value: str) -> bytes:
    if not value or any(char not in "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_" for char in value):
        raise AuthError("Invalid token")
    try:
        return base64.urlsafe_b64decode(value + "=" * ((4 - len(value) % 4) % 4))
    except (ValueError, base64.binascii.Error) as exc:
        raise AuthError("Invalid token") from exc


def verify_agent_token(token: str, secret: str, now: int | None = None) -> AgentClaims:
    if not secret:
        raise AuthError("Invalid token")
    parts = token.split(".")
    if len(parts) != 2 or not parts[0] or not parts[1]:
        raise AuthError("Invalid token")
    payload, encoded_signature = parts
    try:
        signature = _decode(encoded_signature)
        claims = json.loads(_decode(payload).decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise AuthError("Invalid token") from exc
    expected = hmac.new(secret.encode("utf-8"), payload.encode("ascii"), hashlib.sha256).digest()
    if not hmac.compare_digest(expected, signature) or not isinstance(claims, dict):
        raise AuthError("Invalid token")

    sub, em, sid = claims.get("sub"), claims.get("em"), claims.get("sid")
    scope, exp, jti = claims.get("scope"), claims.get("exp"), claims.get("jti")
    if (
        not isinstance(sub, str) or not isinstance(em, str) or not isinstance(sid, str)
        or not isinstance(scope, list) or not all(isinstance(item, str) for item in scope)
        or not isinstance(exp, int) or isinstance(exp, bool) or not isinstance(jti, str)
        or (now if now is not None else int(time.time())) >= exp
    ):
        raise AuthError("Invalid token")
    return AgentClaims(sub=sub, em=em, sid=sid, scope=tuple(scope), exp=exp, jti=jti)


def identity_from_request(req: Any) -> AgentClaims | None:
    global _missing_secret_warned
    secret = config.TOKEN_SECRET
    if not secret:
        if not _missing_secret_warned:
            log.warning("CHAT_AGENT_TOKEN_SECRET is not configured; agent requests are anonymous")
            _missing_secret_warned = True
        return None
    token = req.headers.get("X-Agent-Token", "").strip()
    if not token:
        return None
    try:
        return verify_agent_token(token, secret)
    except AuthError:
        return None
