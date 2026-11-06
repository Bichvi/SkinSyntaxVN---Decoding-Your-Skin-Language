"""catalog_match: name shortlist + ingredient-overlap confirmation (fakes only)."""

import pytest

from graph_sync.ingredients import load_aliases
from label_service import catalog_match

ALIASES = load_aliases()
TEXT = "Water, Glycerin, Niacinamide, Panthenol, Butylene Glycol, Sodium Hyaluronate, Allantoin, Squalane, Tocopherol, Dimethicone"
SCAN = ["water", "glycerin", "niacinamide", "panthenol", "butylene_glycol", "sodium_hyaluronate", "allantoin",
        "squalane", "tocopherol", "dimethicone"]


class FakeCatalog:
    def __init__(self, products):
        self._p = products

    def products(self):
        return self._p


def prod(pid, name, brand):
    return {"id": pid, "ten_san_pham": name, "thuong_hieu": brand}


def loader(texts):
    return lambda ids: {i: {"full": texts[i], "sach": ""} for i in ids if i in texts}


def run(catalog, texts, name="Toner AHA BHA Miracle", brand="Some By Mi", keys=SCAN):
    return catalog_match.match(name, brand, keys, catalog, loader(texts), ALIASES)


def test_match_by_jaccard_09():
    # catalog list shares 9 of 10 first keys -> jaccard 0.82 (>= 0.8)
    other = TEXT.replace("Dimethicone", "Cetyl Alcohol")
    found = run(FakeCatalog([prod("7", "AHA BHA Miracle Toner", "Some By Mi")]), {"7": other})
    assert found and found.product_id == "7" and found.score >= 0.8
    assert found.keys[0] == ("water", 1) and len(found.keys) == 10


def test_same_name_other_brand_with_different_ingredients_is_none():
    cat = FakeCatalog([prod("7", "AHA BHA Miracle Toner", "Other Brand")])
    assert run(cat, {"7": "Water, Alcohol Denat., Retinol, Dimethicone, Squalane, Tocopherol"}) is None


def test_jaccard_06_other_brand_is_none():
    # 6 shared keys of 10 + 4 different on the other side -> jaccard 6/14 = 0.43; use 8 shared of 10 each = 0.67
    other = "Water, Glycerin, Niacinamide, Panthenol, Butylene Glycol, Sodium Hyaluronate, Allantoin, Squalane, Retinol, Fragrance"
    cat = FakeCatalog([prod("7", "AHA BHA Miracle Toner", "Other Brand")])
    assert run(cat, {"7": other}) is None


def test_same_brand_strong_name_accepts_jaccard_06():
    other = "Water, Glycerin, Niacinamide, Panthenol, Butylene Glycol, Sodium Hyaluronate, Allantoin, Squalane, Retinol, Fragrance"
    cat = FakeCatalog([prod("7", "AHA BHA Miracle Toner", "SOME by mi")])
    found = run(cat, {"7": other})
    assert found and found.product_id == "7"


def test_loader_failure_returns_none():
    def boom(ids):
        raise RuntimeError("down")

    cat = FakeCatalog([prod("7", "AHA BHA Miracle Toner", "Some By Mi")])
    assert catalog_match.match("AHA BHA Miracle Toner", "Some By Mi", SCAN, cat, boom, ALIASES) is None


@pytest.mark.parametrize("catalog, name, keys", [
    (None, "AHA BHA Miracle Toner", SCAN),
    (FakeCatalog([]), "AHA BHA Miracle Toner", SCAN),
    (FakeCatalog([prod("7", "AHA BHA Miracle Toner", "x")]), "", SCAN),
    (FakeCatalog([prod("7", "AHA BHA Miracle Toner", "x")]), "AHA BHA Miracle Toner", []),
    (FakeCatalog([prod("7", "Kem Duong Khac Hoan Toan", "x")]), "AHA BHA Miracle Toner", SCAN),
])
def test_no_candidate_or_input_returns_none(catalog, name, keys):
    assert catalog_match.match(name, "x", keys, catalog, loader({"7": TEXT}), ALIASES) is None


def test_accent_folded_names_match():
    cat = FakeCatalog([prod("9", "Nước hoa hồng AHA", "Cocoon")])
    found = catalog_match.match("nuoc hoa hong aha", "cocoon", SCAN, cat, loader({"9": TEXT}), ALIASES)
    assert found and found.product_id == "9"


def test_exact_formula_sibling_beats_higher_name_score_variant():
    scan = ["k1", "k2", "k3", "k4", "k5", "k6", "k7", "k8", "k9", "k10"]
    texts = {
        # A: same name + brand, shares 7 of 10 keys (jaccard 7/13 = 0.54), extra retinol/fragrance/alcohol
        "A": "Water, Glycerin, Niacinamide, Panthenol, Butylene Glycol, Sodium Hyaluronate, Allantoin, Retinol, Fragrance, Alcohol Denat.",
        "B": TEXT,
    }
    cat = FakeCatalog([prod("A", "Miracle Calm Toner Edition", "X"), prod("B", "Miracle Toner Pure", "X")])
    found = catalog_match.match("Miracle Calm Toner", "X", SCAN, cat, loader(texts), ALIASES)
    assert found and found.product_id == "B" and found.score == 1.0 and scan


def test_tokenizer_strips_punctuation_and_ignores_generic_words():
    cat = FakeCatalog([prod("7", "AHA BHA Miracle (Toner), 150ml", "Some By Mi")])
    assert catalog_match.match("aha, bha miracle toner", "Some By Mi", SCAN, cat, loader({"7": TEXT}), ALIASES)
    # a bare generic name identifies nothing
    assert catalog_match.match("Toner 150ml", "Some By Mi", SCAN, cat, loader({"7": TEXT}), ALIASES) is None
