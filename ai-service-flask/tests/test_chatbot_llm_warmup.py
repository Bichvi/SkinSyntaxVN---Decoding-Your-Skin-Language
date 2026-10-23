"""U11: chatbot_flask.get_llms() must not validate LLMs synchronously (first chat took ~23s).

The configured pool is returned immediately (marked "unvalidated"); a background thread validates and swaps
in the validated pool. The U02 locking guarantees (one build, no partial list) are kept.
"""
import os
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor

import pytest

_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
for _p in (_ROOT, os.path.join(_ROOT, "chatbot_service")):
    if _p not in sys.path:
        sys.path.insert(0, _p)

cf = pytest.importorskip("chatbot_service.chatbot_flask")


class FakeLLM:
    def __init__(self, name):
        self.model = name

    def __repr__(self):
        return f"FakeLLM({self.model})"


@pytest.fixture
def fresh(monkeypatch):
    """Reset the module-level pool + validation state around every test."""
    monkeypatch.setattr(cf, "_llms", None)
    monkeypatch.setattr(cf, "_last_llm_init_time", 0)
    monkeypatch.setattr(cf, "_llm_validation_thread", None)
    monkeypatch.setattr(cf, "_llm_validation_done", threading.Event())
    monkeypatch.setattr(cf, "_llm_state", {})
    monkeypatch.setattr(cf, "LLM_BG_VALIDATION_ENABLED", True)
    return cf


def install_builder(monkeypatch, quick, validated, gate=None, calls=None, raise_on_validate=False):
    def builder(validate=True):
        if calls is not None:
            calls.append(validate)
        if not validate:
            return list(quick)
        if gate is not None:
            assert gate.wait(10), "test gate never opened"
        if raise_on_validate:
            raise RuntimeError("provider outage")
        return list(validated)

    monkeypatch.setattr(cf, "_build_llms", builder)


def wait_done(module, timeout=5):
    assert module._llm_validation_done.wait(timeout), "background validation did not finish"


def test_first_call_returns_the_configured_pool_without_waiting_for_validation(fresh, monkeypatch):
    a, b = FakeLLM("a"), FakeLLM("b")
    gate = threading.Event()
    install_builder(monkeypatch, [a, b], [a], gate=gate)

    started = time.perf_counter()
    pool = fresh.get_llms()
    elapsed = time.perf_counter() - started

    assert pool == [a, b] and elapsed < 0.5            # validation is still blocked on the gate
    assert [fresh.llm_validation_state(x) for x in pool] == ["unvalidated", "unvalidated"]
    status = fresh.llm_pool_status()
    assert status["unvalidated"] == 2 and status["validation_started"] and not status["validation_done"]
    gate.set()
    wait_done(fresh)


def test_validation_swaps_in_the_validated_pool_and_marks_it(fresh, monkeypatch):
    a, b = FakeLLM("a"), FakeLLM("b")
    good = FakeLLM("a-validated")
    gate = threading.Event()
    install_builder(monkeypatch, [a, b], [good], gate=gate)
    assert fresh.get_llms() == [a, b]
    gate.set()
    wait_done(fresh)
    assert fresh.get_llms() == [good]                    # the provider that failed validation is gone
    assert fresh.llm_validation_state(good) == "validated"
    assert fresh.llm_pool_status() == {"size": 1, "unvalidated": 0, "validated": 1,
                                       "validation_started": True, "validation_done": True}


def test_all_providers_failing_validation_keeps_the_configured_pool(fresh, monkeypatch):
    a = FakeLLM("a")
    install_builder(monkeypatch, [a], [])
    assert fresh.get_llms() == [a]
    wait_done(fresh)
    assert fresh.get_llms() == [a] and fresh.llm_validation_state(a) == "unvalidated"


def test_validation_crash_never_breaks_the_pool(fresh, monkeypatch):
    a = FakeLLM("a")
    install_builder(monkeypatch, [a], [], raise_on_validate=True)
    assert fresh.get_llms() == [a]
    wait_done(fresh)
    assert fresh.get_llms() == [a]


def test_u02_locks_are_kept_one_quick_build_one_validation_under_16_threads(fresh, monkeypatch):
    calls = []
    a = FakeLLM("a")

    def builder(validate=True):
        calls.append(validate)
        time.sleep(0.15)
        return [a]

    monkeypatch.setattr(cf, "_build_llms", builder)
    barrier = threading.Barrier(16)

    def call(_):
        barrier.wait()
        return fresh.get_llms()

    with ThreadPoolExecutor(16) as pool:
        results = list(pool.map(call, range(16)))
    wait_done(fresh)
    assert all(r == [a] for r in results)               # nobody saw an empty / partial list
    assert sorted(calls) == [False, True]               # exactly one quick build + one background validation


def test_flag_off_keeps_the_old_synchronous_validation(fresh, monkeypatch):
    calls = []
    a = FakeLLM("a")
    install_builder(monkeypatch, [], [a], calls=calls)
    monkeypatch.setattr(cf, "LLM_BG_VALIDATION_ENABLED", False)
    assert fresh.get_llms() == [a] and calls == [True]
    assert fresh._llm_validation_thread is None


def test_empty_pool_is_not_validated_and_retries_after_cooldown(fresh, monkeypatch):
    calls = []
    install_builder(monkeypatch, [], [], calls=calls)
    assert fresh.get_llms() == []
    assert fresh._llm_validation_thread is None and calls == [False]
    assert fresh.get_llms() == [] and calls == [False]   # 300s throttle (U02) still applies


def test_quick_build_makes_no_network_validation_call(fresh, monkeypatch):
    """The real builder with validate=False must not call _test_llm_connection."""
    pytest.importorskip("langchain_openai")
    pytest.importorskip("langchain_google_genai")
    monkeypatch.setenv("OPENAI_API_KEY", "sk-test-not-real")
    monkeypatch.setenv("GROQ_API_KEY", "gsk-test-not-real")
    monkeypatch.setenv("ZHIPU_API_KEY", "zh-test-not-real")
    monkeypatch.setenv("OPENROUTER_API_KEY", "or-test-not-real")
    monkeypatch.setattr(cf, "GEMINI_KEYS", ["AIza-test-not-real"], raising=False)

    attempts = []

    def counting(_llm):
        attempts.append(1)
        return False                                     # a failing validation, as with a dead provider

    monkeypatch.setattr(cf, "_test_llm_connection", counting)
    started = time.perf_counter()
    built = cf._build_llms(validate=False)
    assert time.perf_counter() - started < 5
    assert attempts == []                                # no validation request at all
    assert len(built) == 5                               # one candidate per provider: OpenAI, Gemini, Groq, Zhipu, OpenRouter
    # validate=True does call it (proves the check above is meaningful) and drops providers that fail
    assert cf._build_llms(validate=True) == [] and attempts
