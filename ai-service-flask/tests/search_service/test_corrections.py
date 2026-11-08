"""U05: typo / abbreviation corrections for "Có phải bạn muốn tìm..." (pure, no DB)."""

from __future__ import annotations

import pytest

from search_service import config, routes, service
from search_service.corrections import find_corrections
from search_service.dictionary import EntityDictionary
from search_service.intent_parser import parse

BRANDS = [
    {"key": "torriden", "name": "Torriden"},
    {"key": "cerave", "name": "CeraVe"},
    {"key": "la_roche_posay", "name": "La Roche-Posay"},
]


class FakeDriver:
    def execute_query(self, query, *args, **kwargs):
        if ":Brand" in query:
            return BRANDS, None, ["key", "name"]
        return [], None, ["key", "name"]


@pytest.fixture(scope="module")
def snap():
    return EntityDictionary(driver=FakeDriver(), mongo_db=None, ttl_seconds=600).snapshot()


def fc(q, snap):
    return find_corrections(q, snap)


def test_fuzzy_brand_and_abbreviation(snap):
    out = fc("toriden srm", snap)
    assert out["corrected_q"] == "Torriden sữa rửa mặt"
    assert out["corrections"] == [
        {"from": "toriden", "to": "Torriden", "type": "fuzzy"},
        {"from": "srm", "to": "sữa rửa mặt", "type": "synonym"},
    ]


def test_fuzzy_only_keeps_other_words_verbatim(snap):
    out = fc("toriden Sữa Rửa Mặt 150ml", snap)
    assert out["corrected_q"] == "Torriden Sữa Rửa Mặt 150ml"
    assert [c["type"] for c in out["corrections"]] == ["fuzzy"]


def test_exact_brand_is_not_a_correction(snap):
    out = fc("Torriden sữa rửa mặt", snap)
    assert out == {"corrected_q": "Torriden sữa rửa mặt", "corrections": []}


def test_plain_words_no_correction(snap):
    out = fc("kem chống nắng", snap)
    assert out["corrections"] == [] and out["corrected_q"] == "kem chống nắng"


def test_full_word_synonym_is_not_expanded(snap):
    # "cleanser" is a valid alias but a normal word, not a clear abbreviation: reported as nothing.
    assert fc("cleanser", snap)["corrections"] == []


def test_abbreviation_alone(snap):
    out = fc("srm", snap)
    assert out["corrections"] == [{"from": "srm", "to": "sữa rửa mặt", "type": "synonym"}]
    assert out["corrected_q"] == "sữa rửa mặt"


def test_punctuation_and_case_in_original_word(snap):
    out = fc("Toriden, srm", snap)
    assert out["corrected_q"] == "Torriden sữa rửa mặt"
    assert out["corrections"][0]["from"] == "Toriden,"


def test_empty_and_garbage_never_raise(snap):
    assert fc("", snap) == {"corrected_q": "", "corrections": []}
    assert fc(None, snap)["corrections"] == []
    assert fc("\x00\x01 ???", snap)["corrections"] == []


def test_does_not_change_parse_result(snap):
    trace = []
    with_trace = parse("toriden srm", snap, trace=trace)
    assert with_trace == parse("toriden srm", snap)
    assert [e["kind"] for e in trace] == ["fuzzy", "match"] or {e["kind"] for e in trace} == {"fuzzy", "match"}


def test_passing_trace_avoids_second_parse(snap):
    trace = []
    intent = parse("toriden srm", snap, trace=trace)
    assert find_corrections("toriden srm", snap, intent=intent, trace=trace)["corrected_q"] == "Torriden sữa rửa mặt"


# ------------------------------------------------------------------ describe_intent (cache) + route


class MemRedis:
    def __init__(self):
        self.data = {}

    def get(self, k):
        return self.data.get(k)

    def setex(self, k, ttl, v):
        self.data[k] = v


def test_describe_intent_is_cached(monkeypatch, snap):
    mem = MemRedis()
    monkeypatch.setattr(service, "_intent_cache", service.RedisJsonCache(redis_factory=lambda: mem, ttl=600))
    monkeypatch.setattr("shared.redis_kit.pubsub.current_generation", lambda client=None: 7)
    calls = []

    class Dict:
        def snapshot_nowait(self):
            calls.append(1)
            return snap

    monkeypatch.setattr("search_service.dictionary.get_default_dictionary", lambda: Dict())
    first = service.describe_intent("toriden srm")
    second = service.describe_intent("toriden srm")
    assert first == second and first["corrected_q"] == "Torriden sữa rửa mặt"
    assert len(calls) == 1
    assert any(k.startswith("search:intent:v1:g7:") for k in mem.data)
    assert set(first) == {"intent", "corrected_q", "corrections"}


@pytest.fixture
def client(monkeypatch):
    import os

    from flask import Flask

    monkeypatch.setattr(routes, "_warm_pid", os.getpid())
    monkeypatch.setattr(config, "INTERNAL_TOKEN", "tok")
    app = Flask(__name__)
    app.register_blueprint(routes.search_bp)
    return app.test_client()


def test_intent_route_auth_and_validation(client, monkeypatch):
    assert client.get("/api/search/intent?q=x").status_code == 401
    h = {"X-Internal-Token": "tok"}
    assert client.get("/api/search/intent", headers=h).status_code == 400
    assert client.get("/api/search/intent?q=%20%20", headers=h).status_code == 400


def test_intent_route_ok_and_failure_is_soft(client, monkeypatch):
    h = {"X-Internal-Token": "tok"}
    body = {"intent": None, "corrected_q": "Torriden", "corrections": [{"from": "toriden", "to": "Torriden", "type": "fuzzy"}]}
    monkeypatch.setattr(service, "describe_intent", lambda q: body)
    r = client.get("/api/search/intent?q=toriden", headers=h)
    assert r.status_code == 200 and r.get_json() == body

    def boom(q):
        raise RuntimeError("down")

    monkeypatch.setattr(service, "describe_intent", boom)
    r = client.get("/api/search/intent?q=toriden", headers=h)
    assert r.status_code == 200 and r.get_json()["corrections"] == []


def test_intent_route_rate_limited(client, monkeypatch):
    monkeypatch.setattr(routes.ratelimit, "allow", lambda *a, **k: (False, 1.0))
    r = client.get("/api/search/intent?q=x", headers={"X-Internal-Token": "tok"})
    assert r.status_code == 429 and "Retry-After" in r.headers
