"""Keep the ai-idol test suite away from every real MongoDB.

``config`` loads the repository ``.env``, which may hold the production Atlas URI. ``load_dotenv``
never overrides a variable that is already set, so pinning ``MONGO_URI`` here, before any test
module imports ``config``, keeps that URI out of the test process.

On top of that, ``core.state_machine.get_db`` cannot build a ``MongoClient`` during the tests:
a test that reaches the real ``get_db`` fails with a message naming the fix (patch the caller's
``get_db`` with ``tests.fakes.FakeDb``). Code that swallows the error (stock lookups, best-effort
writes) cannot hide it either: the test then fails at teardown.
"""
from __future__ import annotations

import os
import sys

import pytest

# A reserved ".invalid" host on port 1: nothing can answer, even for a client built some other way.
BLOCKED_MONGO_URI = "mongodb://real-mongo-blocked-by-tests.invalid:1/"
os.environ["MONGO_URI"] = BLOCKED_MONGO_URI

SERVICE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import config  # noqa: E402  (imported after the pin on purpose)
import core.state_machine as state_machine  # noqa: E402

# Already imported by something else first? Pin the copies the code actually reads.
config.MONGO_URI = state_machine.MONGO_URI = BLOCKED_MONGO_URI

BLOCKED_MESSAGE = (
    "A test reached core.state_machine.get_db() and tried to open a real MongoDB connection. "
    "Patch the caller's get_db (for example agents.service.get_db or core.agent_runs.get_db) "
    "with tests.fakes.FakeDb."
)
_blocked_attempts: list[str] = []


class RealMongoBlocked(RuntimeError):
    """Raised instead of connecting when a test reaches the real ``get_db``."""


def _refuse_real_mongo(*_args, **_kwargs):
    _blocked_attempts.append(BLOCKED_MESSAGE)
    raise RealMongoBlocked(BLOCKED_MESSAGE)


# Session-wide, so code that runs while test modules are imported is covered too.
state_machine.MongoClient = _refuse_real_mongo
state_machine._client = None


@pytest.fixture(autouse=True)
def _no_real_mongo(monkeypatch):
    """Re-arm the guard for each test and fail the test if it was tripped, even when the error was swallowed."""
    monkeypatch.setattr(state_machine, "MongoClient", _refuse_real_mongo)
    monkeypatch.setattr(state_machine, "_client", None)
    _blocked_attempts.clear()
    yield
    attempts = len(_blocked_attempts)
    _blocked_attempts.clear()
    if attempts:
        pytest.fail(f"{attempts} real MongoDB connection attempt(s) blocked. {BLOCKED_MESSAGE}", pytrace=False)
