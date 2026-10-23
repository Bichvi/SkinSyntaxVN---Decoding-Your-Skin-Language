"""U06: coverage numbers, orphan pruning statement, YAML-driven mapping of previously unmapped INCI."""

import importlib.util
import sys
from pathlib import Path

from graph_sync import load
from graph_sync.ingredients import parse_ingredients

SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "ingredient_coverage.py"
spec = importlib.util.spec_from_file_location("ingredient_coverage", SCRIPT)
cov = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = cov
spec.loader.exec_module(cov)


def row(*ings, active=True):
    return {"active": active, "ingredients": [
        {"key": k, "inci": inci, "name": k.title(), "role": role} for k, inci, role in ings]}


def test_compute_percentages_and_unmapped_by_key():
    rows = [
        row(("water", "Water", "base"), ("niacinamide", "Niacinamide", "active"), ("foo_x", None, None)),
        row(("water", "Water", "base"), ("foo_x", None, None)),
        row(("glycerin", "Glycerin", "humectant")),
        row(("niacinamide", "Niacinamide", "active"), active=False),        # hidden: ignored
    ]
    stats = cov.compute(rows)
    assert stats["products_active"] == 3 and stats["refs_total"] == 6 and stats["refs_mapped"] == 4
    assert stats["pct_refs_mapped"] == 66.67
    assert stats["pct_products_with_active"] == 33.33
    assert stats["unmapped"]["foo_x"] == 2 and stats["distinct_unmapped"] == 1


def test_prune_only_deletes_nodes_without_any_relationship():
    cypher = load.PRUNE_ORPHAN_INGREDIENTS
    assert "NOT (i)--()" in cypher and "DETACH" not in cypher.upper()


def test_u06_names_that_were_unmapped_now_resolve():
    refs = parse_ingredients("Disteardimonium Hectorite, Iron Oxides, Ci 77491, Peg-8, Mannose, Phenoxy ethanol, "
                             "Triethanolamine 2, Cera Alba, Lactococus/Hyaluronic Acid Ferment Filtrate")
    assert all(r.inci for r in refs), [r.name for r in refs if not r.inci]
    by_key = {r.key: r.role for r in refs}
    assert by_key["iron_oxides"] == "colorant" and by_key["disteardimonium_hectorite"] == "thickener"
    assert "cera_alba" not in by_key and "beeswax" in by_key
