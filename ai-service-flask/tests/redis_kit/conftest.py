"""Fixtures for shared.redis_kit tests (fakeredis; integration tests need a real local Redis)."""

import os
import sys
import uuid
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

fakeredis = pytest.importorskip("fakeredis")


def pytest_configure(config):
    config.addinivalue_line("markers", "integration: needs a real Redis (REDIS_TEST_URL, default redis://127.0.0.1:6380/15)")


@pytest.fixture
def r():
    client = fakeredis.FakeRedis(decode_responses=True)
    yield client
    client.flushall()


@pytest.fixture
def real_redis():
    """Real Redis on a scratch db; every test uses unique key names and cleans only its own keys."""

    import redis

    url = os.getenv("REDIS_TEST_URL", "redis://127.0.0.1:6380/15")
    client = redis.Redis.from_url(url, decode_responses=True, socket_timeout=2, socket_connect_timeout=1)
    try:
        client.ping()
    except Exception:
        pytest.skip("no real Redis reachable (set REDIS_TEST_URL)")
    prefix = f"u03test:{uuid.uuid4().hex[:8]}:"
    client.test_prefix = prefix  # type: ignore[attr-defined]
    yield client
    for key in client.scan_iter(match=prefix + "*", count=500):
        client.delete(key)
