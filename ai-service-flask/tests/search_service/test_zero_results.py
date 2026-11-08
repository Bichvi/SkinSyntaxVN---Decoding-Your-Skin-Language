"""U06 part B: zero-result report (filters, suggestions) and apply_synonym_suggestions."""

import csv
import importlib.util
import sys
from pathlib import Path

import pytest
import yaml

from graph_sync import ingredients
from graph_sync.normalize import clear_caches, group_synonyms, load_synonyms
from search_service import zero_results as zr
from search_service.dictionary import EntityDictionary
from search_service.intent_parser import parse

SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "apply_synonym_suggestions.py"
spec = importlib.util.spec_from_file_location("apply_synonym_suggestions", SCRIPT)
apply_mod = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = apply_mod
spec.loader.exec_module(apply_mod)


def log(q, count=0, ma_kh=None, live=None):
    meta = {"query": q, "result_count": count}
    if live is not None:
        meta["live"] = live
    return {"ma_kh": ma_kh, "metadata": meta}


@pytest.fixture(scope="module")
def snap():
    return EntityDictionary(driver=None, mongo_db=None).snapshot()


# ----------------------------------------------------------------------------- collection ----

def test_collect_filters_pii_probes_test_accounts_and_live():
    docs = (
        [log("Sữa rửa mặt xyz")] * 3
        + [log("sua rua mat xyz")]                       # folds to the same q_norm
        + [log("U03verify abc")] * 5                    # probe, any case
        + [log("0912345678")] * 4                       # phone number (PII)
        + [log("me@x.com")] * 3
        + [log("test acct query", ma_kh=77)] * 4        # test account id
        + [log("e2e probe")] * 2                         # searched by a test account (search_events)
        + [log("partial", live=True)] * 3
        + [log("has results", count=5)] * 9
        + [log("rare one")]
    )
    result = zr.aggregate_zero_queries(docs, test_user_ids=[77], test_queries=["E2E probe"], min_count=2)
    assert result.queries == [("sua rua mat xyz", 4)]
    st = result.stats
    assert st["probe"] == 5 and st["pii_or_odd"] == 7 and st["test_account"] == 4
    assert st["test_query"] == 2 and st["live_skipped"] == 3 and st["distinct_queries"] == 2


def test_user_hash_matches_php_recipe():
    import hashlib

    assert zr.user_hash("  A@B.com ", "salt") == hashlib.sha256(b"a@b.comsalt").hexdigest()


# ------------------------------------------------------------------------------ analysis -----

def test_residual_close_to_known_alias_gets_alias_suggestion(snap):
    row = zr.analyze_all([("sunscren", 3)], snap, parse)[0]
    assert row["suggestion_type"] == "alias" and row["target_group"] == "categories"
    assert row["target_key"] == "kem_chong_nang" and row["alias"] == "sunscren" and row["score"] >= 88
    assert row["approve"] == ""


def test_semantic_probe_proposes_category_only_when_it_agrees(snap):
    good = lambda q: zr.SemanticVerdict("ho_tro_tri_mun", "Hỗ Trợ Trị Mụn", 1.02, 4)      # noqa: E731
    weak = lambda q: zr.SemanticVerdict("ho_tro_tri_mun", "Hỗ Trợ Trị Mụn", 1.5, 4)       # noqa: E731
    split = lambda q: zr.SemanticVerdict("ho_tro_tri_mun", "Hỗ Trợ Trị Mụn", 1.0, 2)      # noqa: E731
    r = zr.analyze_all([("xyzkemtrimun", 2)], snap, parse, good)[0]
    assert (r["suggestion_type"], r["target_group"], r["target_key"]) == ("category", "categories", "ho_tro_tri_mun")
    for probe in (weak, split, None):
        assert zr.analyze_all([("xyzkemtrimun", 2)], snap, parse, probe)[0]["suggestion_type"] == "need_review"


def test_probe_errors_degrade_to_review(snap):
    def boom(q):
        raise RuntimeError("chroma down")

    row = zr.analyze_all([("qqqwwweee", 2)], snap, parse, boom)[0]
    assert row["suggestion_type"] == "need_review" and "semantic probe lỗi" in row["note"]


def test_fully_understood_intent_is_flagged_not_aliased(snap):
    row = zr.analyze_all([("srm", 5)], snap, parse)[0]
    assert row["residual"] == "" and row["suggestion_type"] == "need_review" and "intent hiểu đủ" in row["note"]


def test_report_csv_columns(tmp_path, snap):
    rows = zr.analyze_all([("sunscren", 3)], snap, parse)
    out = tmp_path / "r" / "z.csv"
    zr.write_report(out, rows)
    parsed = list(csv.DictReader(open(out, encoding="utf-8-sig")))
    assert list(parsed[0].keys()) == zr.COLUMNS and parsed[0]["q_norm"] == "sunscren"


# ------------------------------------------------------------------------------ apply --------

YAML = """# header comment
categories:
  # --- level 3
  sua_rua_mat: {name: "Sữa Rửa Mặt", aliases: ["srm", "cleanser"]}
  toner: {name: "Toner"}
concerns:
  mun: {name: "Mụn", aliases: ["acne"]}  # trailing comment
"""


def row(alias, key, group="categories", approve="y"):
    return {"alias": alias, "target_key": key, "target_group": group, "approve": approve}


def test_apply_only_approved_rows_keeps_comments_and_parses():
    new, out = apply_mod.apply_approved(YAML, [row("rua mat", "sua_rua_mat"), row("nuoc hoa hong", "toner"),
                                               row("ignored", "toner", approve="n"), row("blank", "toner", approve="")])
    assert [o.status for o in out] == ["applied", "applied"]
    assert new.startswith("# header comment\ncategories:\n  # --- level 3\n")
    assert '"srm", "cleanser", "rua mat"]' in new
    assert 'toner: {name: "Toner", aliases: ["nuoc hoa hong"]}' in new
    assert "# trailing comment" in new and "ignored" not in new and "blank" not in new
    assert yaml.safe_load(new)["categories"]["toner"]["aliases"] == ["nuoc hoa hong"]


def test_apply_rejects_duplicates_across_groups_and_unknown_targets():
    new, out = apply_mod.apply_approved(YAML, [
        row("acne", "sua_rua_mat"),                       # belongs to concerns/mun
        row("Cleanser", "sua_rua_mat"),                   # same group+key already -> duplicate
        row("whatever", "khong_ton_tai"),
        row("ab", "toner"),                               # too short
        row("xyz", "paraben", group="ingredients"),
        row("cn", "mun", group="skin_types"),             # wrong group for that key
    ])
    assert [o.status for o in out] == ["rejected", "duplicate", "rejected", "rejected", "rejected", "rejected"]
    assert new == YAML


def test_apply_infers_group_from_key_and_second_row_cannot_reuse_first_alias():
    new, out = apply_mod.apply_approved(YAML, [row("da mun", "mun", group=""), row("da mun", "toner")])
    assert [o.status for o in out] == ["applied", "rejected"]
    assert '"acne", "da mun"]' in new


def test_applied_yaml_still_passes_the_one_group_rule(tmp_path):
    path = tmp_path / "syn.yaml"
    new, out = apply_mod.apply_approved(YAML, [row("rua mat", "sua_rua_mat")])
    path.write_text(new, encoding="utf-8")
    seen = {}
    for group in ("categories", "skin_types", "concerns", "benefits"):
        for variant in group_synonyms(group, path):
            assert seen.setdefault(variant, group) == group
    assert load_synonyms(path)["rua_mat"] == "sua_rua_mat"


def test_cli_dry_run_does_not_touch_file(tmp_path, capsys):
    yml = tmp_path / "s.yaml"
    yml.write_text(YAML, encoding="utf-8")
    csv_path = tmp_path / "r.csv"
    csv_path.write_text("alias,target_key,target_group,approve\nrua mat,sua_rua_mat,categories,y\n", encoding="utf-8")
    assert apply_mod.main(["--file", str(csv_path), "--yaml", str(yml), "--dry-run"]) == 0
    assert yml.read_text(encoding="utf-8") == YAML
    assert apply_mod.main(["--file", str(csv_path), "--yaml", str(yml)]) == 0
    assert '"rua mat"' in yml.read_text(encoding="utf-8")
    assert "export_search_dictionary" in capsys.readouterr().out


# --------------------------------------------------------------------- vocabulary reload ------

def test_reload_vocabulary_picks_up_edited_yaml(tmp_path):
    path = tmp_path / "syn.yaml"
    path.write_text(YAML, encoding="utf-8")
    assert "rua_mat" not in group_synonyms("categories", path)
    path.write_text(YAML.replace('"cleanser"]', '"cleanser", "rua mat"]'), encoding="utf-8")
    assert "rua_mat" not in group_synonyms("categories", path)        # still cached
    ingredients.reload_vocabulary()
    assert "rua_mat" in group_synonyms("categories", path)
    clear_caches()


def test_invalidate_handler_reloads_vocabulary_for_dictionary_scope(monkeypatch):
    from search_service import service

    calls = []
    monkeypatch.setattr(ingredients, "reload_vocabulary", lambda: calls.append(1))
    service._on_invalidate({"scope": "catalog"})
    service._on_invalidate({"scope": "dictionary"})
    assert calls == [1]
