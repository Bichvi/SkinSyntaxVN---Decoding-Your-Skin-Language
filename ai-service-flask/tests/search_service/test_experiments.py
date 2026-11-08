"""U12 part B: A/B experiment framework (assignment, overrides, registry) + service integration."""

import hashlib
import time

import pytest

from search_service import config, experiments as ex
from search_service.ranker import Candidate
from search_service.schemas import validate_request
from search_service.service import SearchRecommendService, cache_key

SESSION = "ab" * 32


def make(status="running", traffic=None, overrides=None, exp_id="graph_weight_v1"):
    return ex.parse_experiment({
        "id": exp_id, "status": status, "traffic": traffic or {"control": 50, "variant": 50},
        "overrides": {"weights.graph": 0.45, "weights.semantic": 0.20} if overrides is None else overrides,
    })


# ---------------------------------------------------------------- bucketing

def test_bucket_is_sha256_mod_100_and_stable():
    expected = int(hashlib.sha256((SESSION + "graph_weight_v1").encode()).hexdigest(), 16) % 100
    assert ex.bucket(SESSION, "graph_weight_v1") == expected
    assert all(ex.bucket(SESSION, "graph_weight_v1") == expected for _ in range(5))


def test_experiments_bucket_independently():
    same = sum(ex.bucket(f"{i:064x}", "exp_a") == ex.bucket(f"{i:064x}", "exp_b") for i in range(2000))
    assert same < 60          # ~1% expected by chance; a shared bucket would give 2000


def test_distribution_is_even_over_10k_sessions():
    experiment = make()
    counts = {"control": 0, "variant": 0}
    for i in range(10_000):
        counts[ex.variant_for(experiment, hashlib.sha256(f"session-{i}".encode()).hexdigest())] += 1
    assert abs(counts["control"] / 10_000 - 0.5) <= 0.02, counts
    assert abs(counts["variant"] / 10_000 - 0.5) <= 0.02, counts


def test_distribution_follows_uneven_traffic():
    experiment = make(traffic={"control": 80, "variant": 20})
    n = sum(ex.variant_for(experiment, hashlib.sha256(f"s{i}".encode()).hexdigest()) == "variant" for i in range(10_000))
    assert abs(n / 10_000 - 0.20) <= 0.02


def test_assignment_is_sticky_per_session():
    experiment = make()
    first = {s: ex.variant_for(experiment, s) for s in (f"{i:064x}" for i in range(200))}
    assert first == {s: ex.variant_for(experiment, s) for s in first}


def test_control_arm_has_no_overrides_variant_has():
    experiment = make()
    control = next(s for s in (f"{i:064x}" for i in range(500)) if ex.variant_for(experiment, s) == "control")
    variant = next(s for s in (f"{i:064x}" for i in range(500)) if ex.variant_for(experiment, s) == "variant")
    assert ex.assign(experiment, control).overrides.is_empty()
    assert ex.assign(experiment, variant).overrides.weights == {"graph": 0.45, "semantic": 0.20}


# ---------------------------------------------------------------- overrides / validation

def test_overrides_merge_weights_without_touching_the_rest():
    merged = ex.Overrides(weights={"graph": 0.45, "semantic": 0.20}).apply_weights(config.WEIGHTS)
    assert merged["graph"] == 0.45 and merged["semantic"] == 0.20
    assert merged["popularity"] == config.WEIGHTS["popularity"] and merged["personal"] == config.WEIGHTS["personal"]


def test_parse_overrides_accepts_scalars_and_switches():
    o = ex.parse_overrides({"w_cop": 0.2, "w_ctr": 0.1, "co_purchase": True, "ctr_boost": False, "weights.personal": 0.3})
    assert (o.w_cop, o.w_ctr, o.co_purchase, o.ctr_boost, dict(o.weights)) == (0.2, 0.1, True, False, {"personal": 0.3})


@pytest.mark.parametrize("raw", [
    {"weights.nonsense": 0.1}, {"typo_key": 1}, {"weights.graph": 2}, {"weights.graph": "0.4"}, {"w_cop": True},
    {"co_purchase": 1}, "not a mapping",
])
def test_parse_overrides_rejects_bad_input(raw):
    with pytest.raises(ex.ExperimentConfigError):
        ex.parse_overrides(raw)


@pytest.mark.parametrize("entry", [
    {"id": "Bad Id", "traffic": {"control": 50, "variant": 50}},
    {"id": "x", "status": "paused", "traffic": {"control": 50, "variant": 50}},
    {"id": "x", "traffic": {"control": 60, "variant": 50}},
    {"id": "x", "traffic": {"control": 100}},
    {"id": "x", "traffic": {"a": 50, "b": 50}},
    {"id": "x", "traffic": {"control": 50, "variant": 25, "other": 25}},
    {"id": "x", "status": "running", "traffic": {"control": 50, "variant": 50}},      # running without overrides
])
def test_parse_experiment_rejects(entry):
    with pytest.raises(ex.ExperimentConfigError):
        ex.parse_experiment(entry)


def test_parse_experiments_keeps_valid_entries_and_reports_errors():
    ok, errors = ex.parse_experiments({"experiments": [
        {"id": "a", "status": "draft", "traffic": {"control": 50, "variant": 50}, "overrides": {"w_cop": 0.1}},
        {"id": "b", "traffic": {"control": 10}},
        {"id": "a", "traffic": {"control": 50, "variant": 50}},
    ]})
    assert [e.id for e in ok] == ["a"] and len(errors) == 2


def test_only_running_experiments_apply_and_first_wins():
    drafts = [make(status="draft"), make(status="stopped", exp_id="old")]
    assert ex.select(drafts, SESSION) is None
    running = [make(status="draft"), make(exp_id="second"), make(exp_id="third")]
    assert ex.select(running, SESSION).experiment_id == "second"
    assert ex.select([make()], None) is None and ex.select([make()], "") is None


# ---------------------------------------------------------------- registry (YAML)

YAML_TEXT = """
experiments:
  - id: graph_weight_v1
    status: {status}
    traffic: {{control: 50, variant: 50}}
    overrides:
      weights.graph: 0.45
      weights.semantic: 0.20
"""


def test_registry_reloads_on_change_and_keeps_last_good_copy(tmp_path):
    path = tmp_path / "experiments.yaml"
    path.write_text(YAML_TEXT.format(status="draft"), encoding="utf-8")
    now = [0.0]
    reg = ex.ExperimentRegistry(path, reload_s=5, clock=lambda: now[0])
    assert [e.status for e in reg.experiments()] == ["draft"]
    assert reg.assign(SESSION) is None
    path.write_text(YAML_TEXT.format(status="running"), encoding="utf-8")
    import os
    os.utime(path, (time.time() + 10, time.time() + 10))
    assert reg.experiments()[0].status == "draft"            # inside the 5s window: no re-read yet
    now[0] = 6.0
    assert reg.experiments()[0].status == "running" and reg.assign(SESSION) is not None
    path.write_text("experiments: [ this is : not valid yaml", encoding="utf-8")
    os.utime(path, (time.time() + 20, time.time() + 20))
    now[0] = 12.0
    assert reg.experiments()[0].status == "running"          # broken edit keeps the last good copy
    path.unlink()
    now[0] = 18.0
    assert reg.experiments() == ()


def test_shipped_experiments_file_is_valid_and_draft():
    document = __import__("yaml").safe_load(ex.DEFAULT_FILE.read_text(encoding="utf-8"))
    parsed, errors = ex.parse_experiments(document)
    assert errors == [] and parsed, "data/experiments.yaml must contain a valid sample"
    sample = parsed[0]
    assert sample.id == "graph_weight_v1" and sample.status == "draft"
    assert sample.overrides.weights == {"graph": 0.45, "semantic": 0.20}


def test_wants_reports_gated_switches_of_running_experiments(tmp_path):
    path = tmp_path / "e.yaml"
    path.write_text("experiments:\n  - {id: cop, status: running, traffic: {control: 50, variant: 50}, overrides: {co_purchase: true}}\n",
                    encoding="utf-8")
    reg = ex.ExperimentRegistry(path, reload_s=1)
    assert reg.wants("co_purchase") is True and reg.wants("ctr_boost") is False


# ---------------------------------------------------------------- service integration

def sem(req, intent):
    return [Candidate("1", 0.9, "semantic_match", "semantic"), Candidate("2", 0.5, "semantic_match", "semantic")]


def graph(req, intent):
    return [Candidate("2", 3, "same_category", "category_alt"), Candidate("1", 1, "same_category", "category_alt")]


class MemCache:
    def __init__(self):
        self.store = {}

    def get(self, key):
        v = self.store.get(key)
        return dict(v) if v else None

    def set(self, key, value):
        self.store[key] = dict(value)


class FixedRegistry:
    def __init__(self, experiment):
        self.experiment = experiment

    def assign(self, session_hash):
        return ex.select([self.experiment], session_hash)


def sessions_by_variant(experiment):
    out = {}
    for i in range(500):
        s = f"{i:064x}"
        out.setdefault(ex.variant_for(experiment, s), s)
        if len(out) == 2:
            return out
    raise AssertionError("no split")


def request(session_hash=None):
    payload = {"q": "toner"}
    if session_hash:
        payload["session_hash"] = session_hash
    return validate_request(payload)


def test_cache_key_contains_variant():
    base = cache_key(request())
    assert cache_key(request(), "graph_weight_v1:control") != cache_key(request(), "graph_weight_v1:variant")
    assert cache_key(request(), "graph_weight_v1:control") != base
    assert cache_key(request(), "") == base


def test_response_has_experiment_and_variant_changes_the_ranking():
    experiment = make(overrides={"weights.graph": 0.9, "weights.semantic": 0.0})
    arms = sessions_by_variant(experiment)
    svc = SearchRecommendService({"semantic": sem, "category_alt": graph}, experiments=FixedRegistry(experiment))
    control = svc.recommend(request(arms["control"]))
    variant = svc.recommend(request(arms["variant"]))
    assert control["experiment"] == {"id": "graph_weight_v1", "variant": "control"}
    assert variant["experiment"] == {"id": "graph_weight_v1", "variant": "variant"}
    scores = lambda out, key: {i["product_id"]: i["score"] for s in out["sections"] if s["key"] == key for i in s["items"]}
    assert scores(variant, "category_alt")["2"] > scores(control, "category_alt")["2"]       # graph weight 0.9 vs 0.35
    assert "experiment" not in svc.recommend(request())                                       # no session -> no experiment


def test_cached_responses_are_not_shared_between_arms():
    experiment = make(overrides={"weights.graph": 0.9, "weights.semantic": 0.0})
    arms = sessions_by_variant(experiment)
    cache = MemCache()
    svc = SearchRecommendService({"semantic": sem, "category_alt": graph}, cache=cache, experiments=FixedRegistry(experiment))
    svc.recommend(request(arms["control"]))
    svc.recommend(request(arms["variant"]))
    svc.recommend(request())
    assert len(cache.store) == 3
    again = svc.recommend(request(arms["variant"]))
    assert again["experiment"]["variant"] == "variant"


def test_draft_experiment_changes_nothing():
    svc = SearchRecommendService({"semantic": sem, "category_alt": graph}, experiments=FixedRegistry(make(status="draft")))
    plain = SearchRecommendService({"semantic": sem, "category_alt": graph})
    a, b = svc.recommend(request(SESSION)), plain.recommend(request(SESSION))
    assert "experiment" not in a and a["sections"] == b["sections"]


def test_broken_registry_never_fails_the_search():
    class Broken:
        def assign(self, _):
            raise RuntimeError("yaml exploded")

    out = SearchRecommendService({"semantic": sem}, experiments=Broken()).recommend(request(SESSION))
    assert out["sections"] and "experiment" not in out


def test_arm_can_switch_co_purchase_on_for_itself_only():
    experiment = ex.parse_experiment({"id": "cop", "status": "running", "traffic": {"control": 50, "variant": 50},
                                      "overrides": {"co_purchase": True}})
    arms = sessions_by_variant(experiment)
    calls = []

    def cop(cart, ids):
        calls.append(tuple(cart))
        return {"2": 5.0}

    svc = SearchRecommendService({"semantic": sem}, co_purchase=cop, experiments=FixedRegistry(experiment))
    payload = {"q": "toner", "cart_ids": ["9"]}
    svc.recommend(validate_request({**payload, "session_hash": arms["control"]}))
    assert calls == []                                    # global flag is off and the control arm keeps it off
    out = svc.recommend(validate_request({**payload, "session_hash": arms["variant"]}))
    assert calls == [("9",)]
    assert out["sections"][0]["items"][0]["reason_code"] in {"bought_together", "semantic_match"}
