"""U04: export of the public search dictionary (brands + categories + accent-free synonyms)."""

from __future__ import annotations

import json

from search_service.dictionary import EntityDictionary
from search_service.dictionary_export import build_export, fold, write_export


class Driver:
    def __init__(self, brands, others=()):
        self.brands = brands
        self.others = list(others)

    def execute_query(self, query, *args, **kwargs):
        if ":Brand" in query:
            return list(self.brands), None, []
        if ":Category" in query:
            return list(self.others), None, []
        return [], None, []


def _export(driver=None):
    return build_export(EntityDictionary(driver=driver).reload(), generated_at="2026-01-01T00:00:00Z")


def test_fold():
    assert fold("  Sữa  Rửa Mặt ĐỎ ") == "sua rua mat do"
    assert fold(None) == ""


def test_brands_from_neo4j_have_key_name_fold():
    payload = _export(Driver([{"key": "torriden", "name": "Torriden"}, {"key": "la_roche_posay", "name": "La Roche-Posay"}]))
    assert payload["source"] == "neo4j"
    assert payload["brands"] == [
        {"key": "la_roche_posay", "name": "La Roche-Posay", "fold": "la roche-posay"},
        {"key": "torriden", "name": "Torriden", "fold": "torriden"},
    ]
    assert payload["version"] == 1 and payload["generated_at"] == "2026-01-01T00:00:00Z"


def test_categories_from_yaml_with_accent_free_aliases():
    payload = _export(None)  # no database: YAML only
    assert payload["source"] == "yaml"
    cats = {c["key"]: c for c in payload["categories"]}
    srm = cats["sua_rua_mat"]
    assert srm["name"] == "Sữa Rửa Mặt" and srm["fold"] == "sua rua mat"
    assert "srm" in srm["aliases"] and "gel rua mat" in srm["aliases"]
    assert srm["fold"] not in srm["aliases"]
    assert srm["aliases"] == sorted(srm["aliases"])
    for cat in payload["categories"]:
        for alias in cat["aliases"]:
            assert alias == fold(alias), alias  # already accent-free
    assert payload["brands"] == []


def test_only_public_fields_exported():
    payload = _export(Driver([{"key": "torriden", "name": "Torriden"}]))
    assert set(payload) == {"version", "generated_at", "source", "brands", "categories"}
    assert all(set(b) == {"key", "name", "fold"} for b in payload["brands"])
    assert all(set(c) == {"key", "name", "fold", "aliases"} for c in payload["categories"])


def test_neo4j_category_name_wins_and_is_listed():
    driver = Driver([], [{"label": "Category", "key": "serum", "name": "Serum / Tinh Chất"}])
    cats = {c["key"]: c for c in _export(driver)["categories"]}
    assert cats["serum"]["name"] == "Serum / Tinh Chất"


def test_deterministic_output_and_atomic_write(tmp_path):
    driver = Driver([{"key": "b", "name": "B"}, {"key": "a", "name": "A"}])
    assert _export(driver) == _export(driver)
    target = tmp_path / "nested" / "search_dictionary.json"
    write_export(_export(driver), target)
    data = json.loads(target.read_text(encoding="utf-8"))
    assert [b["key"] for b in data["brands"]] == ["a", "b"]
    assert "Sữa Rửa Mặt" in target.read_text(encoding="utf-8")  # real UTF-8, not \u escapes
    assert list(target.parent.glob("*.tmp")) == []
