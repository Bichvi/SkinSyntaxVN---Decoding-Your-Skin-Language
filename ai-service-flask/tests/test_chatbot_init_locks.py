"""Double-checked locking cho get_llms / get_vectorstore / get_hybrid_pipeline (gthread 8 luồng)."""
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

WORKERS = 16


def _hammer(fn):
    barrier = threading.Barrier(WORKERS)

    def call(_):
        barrier.wait()
        return fn()

    with ThreadPoolExecutor(WORKERS) as pool:
        return list(pool.map(call, range(WORKERS)))


def _slow_counter(result, counter, delay=0.15):
    def builder(*_a, **_k):
        counter.append(1)
        time.sleep(delay)
        return result

    return builder


def test_llm_pool_builder_runs_once_and_no_empty_list(monkeypatch):
    from chatbot_service import llm_pool

    builds = []
    monkeypatch.setattr(llm_pool, "_llms", None)
    monkeypatch.setattr(llm_pool, "_build_llms", _slow_counter(["llm-a"], builds))

    results = _hammer(llm_pool.get_llms)
    assert len(builds) == 1
    assert all(r == ["llm-a"] for r in results)


def test_llm_pool_builder_never_exposes_partial_list(monkeypatch):
    from chatbot_service import llm_pool

    seen = []

    def builder():
        built = []
        time.sleep(0.1)
        built.append("llm-a")
        time.sleep(0.1)
        built.append("llm-b")
        return built

    monkeypatch.setattr(llm_pool, "_llms", None)
    monkeypatch.setattr(llm_pool, "_build_llms", builder)
    seen = _hammer(llm_pool.get_llms)
    assert all(r == ["llm-a", "llm-b"] for r in seen)


def test_retrieval_vectorstore_and_pipeline_built_once(monkeypatch):
    from chatbot_service import retrieval

    vs_builds, pl_builds = [], []
    vs = object()
    pipeline = object()
    monkeypatch.setattr(retrieval, "_vectorstore", None)
    monkeypatch.setattr(retrieval, "_hybrid_pipeline", None)
    monkeypatch.setattr(retrieval, "_build_vectorstore", _slow_counter(vs, vs_builds))
    monkeypatch.setattr(retrieval, "_build_hybrid_pipeline", _slow_counter(pipeline, pl_builds))

    assert all(r is vs for r in _hammer(retrieval.get_vectorstore))
    assert all(r is pipeline for r in _hammer(retrieval.get_hybrid_pipeline))
    assert len(vs_builds) == 1 and len(pl_builds) == 1


def test_chatbot_flask_get_llms_builds_once(monkeypatch):
    cf = pytest.importorskip("chatbot_service.chatbot_flask")
    builds = []
    monkeypatch.setattr(cf, "_llms", None)
    monkeypatch.setattr(cf, "_last_llm_init_time", 0)
    monkeypatch.setattr(cf, "_build_llms", _slow_counter(["llm-a", "llm-b"], builds))
    # U11: validation now runs in a background thread (covered by test_chatbot_llm_warmup.py); keep this test
    # about the build lock only.
    monkeypatch.setattr(cf, "_schedule_validation", lambda: None)

    results = _hammer(cf.get_llms)
    assert len(builds) == 1
    assert all(r == ["llm-a", "llm-b"] for r in results)


def test_chatbot_flask_vectorstore_and_pipeline_built_once(monkeypatch):
    cf = pytest.importorskip("chatbot_service.chatbot_flask")
    vs_builds = []
    pl_builds = []
    vs = type("VS", (), {})()

    class FakePipeline:
        def __init__(self, vectorstore, bm25_index, alpha):
            pl_builds.append(1)
            time.sleep(0.15)
            self.vectorstore = vectorstore

    monkeypatch.setattr(cf, "_vectorstore", None)
    monkeypatch.setattr(cf, "_hybrid_pipeline", None)
    monkeypatch.setattr(cf, "_build_vectorstore", _slow_counter(vs, vs_builds))
    monkeypatch.setattr(cf, "HybridSearchPipeline", FakePipeline)
    monkeypatch.setattr(cf, "BM25Search", lambda *a, **k: object())

    pipes = _hammer(cf.get_hybrid_pipeline)
    assert len(vs_builds) == 1 and len(pl_builds) == 1
    assert all(p is pipes[0] for p in pipes)


def test_chatbot_flask_vectorstore_failure_returns_none(monkeypatch):
    cf = pytest.importorskip("chatbot_service.chatbot_flask")
    monkeypatch.setattr(cf, "_vectorstore", None)
    monkeypatch.setattr(cf, "_build_vectorstore", lambda: False)
    assert cf.get_vectorstore() is None
    assert cf.get_vectorstore() is None
