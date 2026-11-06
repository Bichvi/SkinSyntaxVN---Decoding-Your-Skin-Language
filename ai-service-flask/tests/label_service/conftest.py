"""Make ``ai-service-flask`` importable for label_service tests."""

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

import pytest  # noqa: E402


@pytest.fixture(autouse=True)
def _no_real_redis(monkeypatch):
    """Unit tests never talk to a developer's Redis."""

    from shared.redis_kit import client as redis_client

    redis_client.reset()
    monkeypatch.setattr(redis_client, "get_redis", lambda: None)
