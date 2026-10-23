"""U10 tests: make ``ai-service-flask`` importable and keep Redis out of unit tests."""

import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
for path in (ROOT, ROOT / "chatbot_service"):
    if str(path) not in sys.path:
        sys.path.insert(0, str(path))


def pytest_configure(config):
    config.addinivalue_line(
        "markers", "integration: needs a real local Neo4j (NEO4J_TEST_URI + NEO4J_PASSWORD), else skipped")


@pytest.fixture(autouse=True)
def _no_real_redis(monkeypatch):
    from shared.redis_kit import client as redis_client

    redis_client.reset()
    monkeypatch.setattr(redis_client, "get_redis", lambda: None)
