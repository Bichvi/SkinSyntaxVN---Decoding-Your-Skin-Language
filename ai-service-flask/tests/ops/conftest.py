"""Fixtures for U11 ops tests (fakeredis; no Mongo / Neo4j / Chroma)."""

import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

fakeredis = pytest.importorskip("fakeredis")


@pytest.fixture
def r():
    client = fakeredis.FakeRedis(decode_responses=True)
    yield client
    client.flushall()
