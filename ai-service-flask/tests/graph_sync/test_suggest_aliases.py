"""suggest_ingredient_aliases.py: threshold, digit guard, never touches YAML."""

import csv
import importlib.util
import sys
from pathlib import Path

from graph_sync.ingredients import AliasEntry

SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "suggest_ingredient_aliases.py"
spec = importlib.util.spec_from_file_location("suggest_ingredient_aliases", SCRIPT)
mod = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = mod
spec.loader.exec_module(mod)

TABLE = {
    "phenoxyethanol": AliasEntry("phenoxyethanol", "Phenoxyethanol", None, "preservative"),
    "polyglyceryl_3_stearate": AliasEntry("polyglyceryl_3_stearate", "Polyglyceryl-3 Stearate", None, "base"),
    "tocopheryl_acetate": AliasEntry("tocopheryl_acetate", "Tocopheryl Acetate", None, "active"),
    "ha": AliasEntry("hyaluronic_acid", "Hyaluronic Acid", None, "active"),
}


def names(result):
    return {s.name: s for s in result}


def test_typo_above_threshold_is_suggested():
    result = names(mod.suggest([("Phenoxyetanol", 9), ("Phenoxyethanol", 3)], TABLE))
    assert result["Phenoxyetanol"].target_key == "phenoxyethanol"
    assert result["Phenoxyetanol"].score >= 92
    assert "Phenoxyethanol" not in result            # already known -> nothing to suggest


def test_digits_must_match():
    # "Polyglyceryl-4 Stearate" is a *different* ingredient, ratio is high but digits differ
    assert mod.suggest([("Polyglyceryl-4 Stearate", 5)], TABLE) == []
    assert mod.suggest([("Polyglyceryl-3 Stearat", 5)], TABLE)[0].target_key == "polyglyceryl_3_stearate"


def test_threshold_min_count_and_short_names():
    assert mod.suggest([("Phenoxyetanol", 9)], TABLE, threshold=99.5) == []
    assert mod.suggest([("Phenoxyetanol", 1)], TABLE, min_count=2) == []
    assert mod.suggest([("hb", 50)], TABLE) == []     # too short to fuzzy match


def test_sorted_by_count_and_csv_has_blank_approve_column(tmp_path):
    result = mod.suggest([("Phenoxyetanol", 2), ("Tocopheryl Acetat", 7)], TABLE)
    assert [s.count for s in result] == [7, 2]
    out = tmp_path / "s.csv"
    mod.write_csv(out, result)
    rows = list(csv.reader(open(out, encoding="utf-8")))
    assert rows[0][-1] == "approve" and all(r[-1] == "" for r in rows[1:])


def test_read_unmapped_supports_both_layouts(tmp_path):
    a = tmp_path / "a.csv"
    a.write_text("key,name,count\nfoo_bar,Foo Bar,5\n", encoding="utf-8")
    b = tmp_path / "b.csv"
    b.write_text("name,count\nFoo Bar,5\n", encoding="utf-8")
    assert mod.read_unmapped(a) == mod.read_unmapped(b) == [("Foo Bar", 5)]
