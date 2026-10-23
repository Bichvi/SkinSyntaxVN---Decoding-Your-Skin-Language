import json
from pathlib import Path

import pytest

from home_service import config, service
from home_service.schemas import validate_home_request
from home_service.service import (
    Candidate, HomeRecommendService, pick, score_concern, score_seen, score_skin,
)

SCHEMA_PATH = Path(config.__file__).parent / "contract" / "home_recommend_response.schema.json"


def _srow(pid, sim=0.5, shared=("Niacinamide",), brand="a", sold=0):
    return {"id": pid, "sim": sim, "shared": list(shared), "brand": brand, "sold": sold}


def _crow(pid, helps=("Niacinamide",), skin_fit=True, sold=100, rating=4.0, brand=None):
    return {"id": pid, "helps": list(helps), "skin_fit": skin_fit, "sold": sold,
            "rating": rating, "brand": brand}


def _krow(pid, fit="data", names=("Mụn",), sold=10):
    return {"id": pid, "fit": fit, "concern_names": list(names), "sold": sold}


class FakeRepo:
    def __init__(self, seen=None, skin=None, concern=None, conflicts=()):
        self.seen = seen or {}
        self.skin = skin or []
        self.concern = concern or []
        self.conflicts = set(conflicts)
        self.calls = {"seen": 0, "skin": 0, "concern": 0, "conflict": 0}
        self.error = None

    def _maybe_raise(self):
        if self.error:
            raise self.error

    def seed_names(self, ids):
        self._maybe_raise()
        return {i: f"SP {i}" for i in ids}

    def seen_candidates(self, seed_id):
        self._maybe_raise()
        self.calls["seen"] += 1
        return self.seen.get(seed_id, [])

    def skin_candidates(self, skin_type, concerns):
        self._maybe_raise()
        self.calls["skin"] += 1
        return self.skin

    def concern_candidates(self, concern, skin_type):
        self._maybe_raise()
        self.calls["concern"] += 1
        return self.concern

    def conflicting_ids(self, cart_ids, candidate_ids):
        self._maybe_raise()
        self.calls["conflict"] += 1
        return {c for c in candidate_ids if c in self.conflicts}

    def label_name(self, label, key):
        return {"SkinType": "Da dầu", "Concern": "Mụn"}[label]


class DictCache:
    def __init__(self):
        self.d = {}

    def get(self, key):
        return self.d.get(key)

    def set(self, key, rows, ttl):
        self.d[key] = rows


def _req(**kw):
    payload = {"sections": ["seen", "skin", "concern"], "seen_ids": ["100"],
               "skin_profile": {"skin_type": "da_dau", "concerns": ["mun"]}}
    payload.update(kw)
    return validate_home_request(payload)


def _svc(repo, cache=None):
    return HomeRecommendService(lambda: repo, cache)


def _ids(section):
    return [i["product_id"] for i in section["items"]]


def _by_key(resp):
    return {s["key"]: s for s in resp["sections"]}


def test_score_seen_weights_mru():
    out = score_seen([("1", "A", [_srow("9")]), ("2", "B", [_srow("9")])])
    assert len(out) == 1
    assert out[0].score == pytest.approx(1.0 * 0.55 + 0.8 * 0.55, abs=1e-9)
    assert out[0].reason["code"] == "seen_shared_ingredient"
    assert out[0].reason["seed_id"] == "1"


def test_score_seen_without_shared_uses_same_category():
    out = score_seen([("1", "A", [_srow("9", shared=())])])
    assert out[0].reason == {"code": "seen_same_category", "seed_id": "1", "seed_name": "A"}
    assert out[0].score == pytest.approx(0.5)


def test_score_skin_formula():
    out = score_skin([_krow("1", fit="data", names=("Mụn",), sold=999)], "Da dầu")
    assert out[0].score == pytest.approx(1.0 + 0.3 + 0.1 * 3 / 4)
    assert out[0].reason == {"code": "skin_fit", "skin_type": "Da dầu", "concerns": ["Mụn"]}


def test_score_concern_formula():
    out = score_concern([_crow("1", sold=9999, rating=5)], "Mụn")
    assert out[0].score == pytest.approx(0.5 + 0.2 + 0.3 + 0.1)
    assert out[0].reason == {"code": "concern_ingredient", "concern": "Mụn", "ingredients": ["Niacinamide"]}
    plain = score_concern([_crow("2", helps=(), rating=None)], "Mụn")
    assert plain[0].reason == {"code": "concern_target", "concern": "Mụn"}


def test_pick_brand_cap_and_tiebreak():
    cands = [Candidate("1", 1.0, 5, "a", {}), Candidate("2", 1.0, 9, "a", {}),
             Candidate("3", 1.0, 1, "a", {}), Candidate("4", 1.0, 1, "a", {}),
             Candidate("5", 0.5, 0, "b", {}), Candidate("6", 0.4, 0, "b", {})]
    got = pick(cands, set(), set(), 4, max_per_brand=2)
    assert [c.id for c in got] == ["2", "1", "5", "6"]


def _full_repo(**kw):
    seen = {"100": [_srow(str(i), brand=f"b{i}") for i in range(1, 7)]}
    skin = [_krow(str(i)) for i in range(1, 12)]
    concern = [_crow(str(i), brand=f"c{i}") for i in range(1, 15)]
    return FakeRepo(seen=seen, skin=skin, concern=concern, **kw)


def test_dedupe_priority_seen_skin_concern():
    resp = _svc(_full_repo()).recommend(_req())
    secs = _by_key(resp)
    assert resp["degraded"] is False
    assert "1" in _ids(secs["seen"])
    for key in ("skin", "concern"):
        assert "1" not in _ids(secs[key])
    assert not set(_ids(secs["skin"])) & set(_ids(secs["concern"]))
    assert not set(_ids(secs["seen"])) & set(_ids(secs["skin"]))
    assert secs["seen"]["seed"] == {"id": "100", "name": "SP 100"}
    assert secs["skin"]["skin_type"] == {"key": "da_dau", "name": "Da dầu"}
    assert secs["concern"]["concern"] == {"key": "mun", "name": "Mụn"}


def test_section_below_threshold_omitted():
    repo = FakeRepo(concern=[_crow(str(i)) for i in range(1, 4)])
    resp = _svc(repo).recommend(_req(sections=["concern"]))
    assert resp["sections"] == []
    assert resp["degraded"] is False


def test_conflicting_candidates_removed_from_all_sections():
    repo = _full_repo(conflicts={"5"})
    resp = _svc(repo).recommend(_req(cart_ids=["999"]))
    for sec in resp["sections"]:
        assert "5" not in _ids(sec)
    assert repo.calls["conflict"] == 1


def test_cart_exclude_and_seen_ids_blocked():
    resp = _svc(_full_repo()).recommend(_req(cart_ids=["2"], exclude_ids=["3"]))
    for sec in resp["sections"]:
        assert not {"2", "3", "100"} & set(_ids(sec))


def test_skin_skipped_without_skin_type():
    repo = _full_repo()
    resp = _svc(repo).recommend(_req(skin_profile={}))
    assert "skin" not in _by_key(resp)
    assert repo.calls["skin"] == 0


def test_neo4j_down_returns_degraded():
    resp = HomeRecommendService(lambda: None).recommend(_req())
    assert resp["degraded"] is True
    assert resp["sections"] == []


def test_query_error_reports_failure(monkeypatch):
    seen = []
    monkeypatch.setattr(service.neo4j_client, "report_failure", lambda exc=None: seen.append(exc))
    repo = _full_repo()
    repo.error = ConnectionError("boom")
    resp = _svc(repo).recommend(_req())
    assert resp["degraded"] is True and resp["sections"] == []
    assert len(seen) == 1


def test_redis_down_computes_directly():
    class BrokenCache:
        def get(self, key):
            raise RuntimeError("redis down")

        def set(self, key, rows, ttl):
            raise RuntimeError("redis down")

    resp = _svc(_full_repo(), BrokenCache()).recommend(_req())
    assert {"seen", "skin", "concern"} == set(_by_key(resp))


def test_cache_hit_skips_repo():
    repo = _full_repo()
    svc = _svc(repo, DictCache())
    svc.recommend(_req())
    svc.recommend(_req())
    assert repo.calls["concern"] == 1
    assert repo.calls["skin"] == 1
    assert repo.calls["seen"] == 1


def test_response_matches_contract():
    jsonschema = pytest.importorskip("jsonschema")
    schema = json.loads(SCHEMA_PATH.read_text(encoding="utf-8"))
    resp = _svc(_full_repo()).recommend(_req(), correlation_id="cid-1")
    jsonschema.validate(resp, schema)
    assert len(resp["sections"]) == 3


def test_contract_enums_match_config():
    schema = json.loads(SCHEMA_PATH.read_text(encoding="utf-8"))
    sec = schema["properties"]["sections"]["items"]["properties"]
    assert tuple(sec["key"]["enum"]) == config.SECTION_KEYS
    codes = sec["items"]["items"]["properties"]["reason"]["properties"]["code"]["enum"]
    assert tuple(codes) == config.REASON_CODES
