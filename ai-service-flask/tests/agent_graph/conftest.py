from __future__ import annotations

import os

import dotenv
import pytest


_SAFE_ENV_KEYS = (
    "MONGO_URI",
    "NEO4J_URI",
    "NEO4J_PASSWORD",
    "REDIS_URL",
    "OPENAI_API_KEY",
    "GOOGLE_API_KEY",
    "GOOGLE_API_KEYS",
    "GROQ_API_KEY",
    "ZHIPU_API_KEY",
    "OPENROUTER_API_KEY",
    "TAVILY_API_KEY",
)


def _no_load_dotenv(*_args, **_kwargs):
    return False


# This module is loaded before test modules.  chatbot_flask imports
# ``load_dotenv`` at module import time, so the patch must exist here rather
# than only in a fixture that runs after collection.
dotenv.load_dotenv = _no_load_dotenv
os.environ["SYNA_AGENT_WARMUP"] = "0"
for _key in _SAFE_ENV_KEYS:
    os.environ[_key] = ""


@pytest.fixture(scope="session", autouse=True)
def isolate_agent_graph_environment():
    patcher = pytest.MonkeyPatch()
    patcher.setattr(dotenv, "load_dotenv", _no_load_dotenv)
    for key in _SAFE_ENV_KEYS:
        patcher.setenv(key, "")
    yield
    patcher.undo()


class RealRuntimeAccess(BaseException):
    pass


@pytest.fixture(autouse=True)
def forbid_real_agent_runtimes(monkeypatch):
    from chatbot_service import graph_router
    from search_service import service
    from search_service import routes

    def blocked(*_args, **_kwargs):
        raise RealRuntimeAccess("agent_graph test used a real runtime; inject a fake dependency")

    monkeypatch.setattr(service, "get_default_service", blocked)
    monkeypatch.setattr(graph_router, "get_runtime", blocked)
    # Prevent chatbot_flask's blueprint hook from starting a real warm-up thread in this test package.
    monkeypatch.setattr(routes, "_warm_pid", os.getpid())
