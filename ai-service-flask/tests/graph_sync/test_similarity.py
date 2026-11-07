import pytest

from graph_sync.similarity import ProductFeatures, compute_similar, features_from_row, jaccard, score_pair

A = ProductFeatures(
    id="A", ingredients=("water", "niacinamide", "hyaluronic_acid", "glycerin"),
    actives=("niacinamide", "hyaluronic_acid"),
    names={"niacinamide": "Niacinamide", "hyaluronic_acid": "Hyaluronic Acid"},
    cat1="c1", cat2="c2", brand="x",
    benefits=frozenset({"sang_da", "cap_am"}), skin_types=frozenset({"da_dau"}),
)
B = ProductFeatures(
    id="B", ingredients=("water", "niacinamide", "hyaluronic_acid", "panthenol"),
    actives=("niacinamide", "hyaluronic_acid", "panthenol"), names={},
    cat1="c1", cat2="c2", brand="x",
    benefits=frozenset({"sang_da"}), skin_types=frozenset({"da_dau", "da_kho"}),
)
C = ProductFeatures(
    id="C", ingredients=("glycerin",), actives=(), names={},
    cat1="c1", cat2="other", brand="y", benefits=frozenset(), skin_types=frozenset(),
)


def test_jaccard_edge_cases():
    assert jaccard(set(), set()) == 0.0
    assert jaccard({"a"}, {"a"}) == 1.0
    assert jaccard({"a", "b"}, {"b", "c"}) == pytest.approx(1 / 3)


def test_score_hand_computed():
    # 0.40*3/5 + 0.25*1 + 0.15*1/2 + 0.10*1/2 + 0.10*1 = 0.715
    assert score_pair(A, B) == pytest.approx(0.715)
    # 0.40*1/4 + 0.25*0.5 = 0.225
    assert score_pair(A, C) == pytest.approx(0.225)
    # 0.40*0 + 0.25*0.5 = 0.125
    assert score_pair(B, C) == pytest.approx(0.125)


def test_score_only_uses_top_15_ingredients():
    long_a = A.__class__(**{**A.__dict__, "id": "LA", "ingredients": tuple(f"i{n}" for n in range(15)) + ("z",)})
    long_b = A.__class__(**{**A.__dict__, "id": "LB", "ingredients": ("z",)})
    assert score_pair(long_a, long_b) == pytest.approx(0.25 + 0.15 + 0.10 + 0.10)


def test_compute_similar_threshold_reasons_and_direction():
    edges = compute_similar([A, B, C], top_k=10, min_score=0.2)
    got = {(e["source"], e["target"]): e for e in edges}
    assert set(got) == {("A", "B"), ("B", "A"), ("A", "C"), ("C", "A")}
    assert got[("A", "B")]["reason"] == "shared_ingredients"
    assert got[("A", "B")]["shared"] == ["Niacinamide", "Hyaluronic Acid"]
    assert got[("A", "C")]["reason"] == "same_category" and got[("A", "C")]["shared"] == []
    assert got[("A", "B")]["score"] == pytest.approx(0.715)


def test_top_k_and_ordering_is_deterministic():
    many = [
        ProductFeatures(id=f"P{n}", ingredients=("water", "niacinamide"), actives=("niacinamide",),
                        names={}, cat1="c1", cat2="c2", brand=None,
                        benefits=frozenset(), skin_types=frozenset())
        for n in range(6)
    ]
    edges = compute_similar(many, top_k=2, min_score=0.2)
    from_p0 = [e["target"] for e in edges if e["source"] == "P0"]
    assert from_p0 == ["P1", "P2"]
    assert all(sum(1 for e in edges if e["source"] == p.id) == 2 for p in many)


def test_brand_only_pair_reason_same_brand():
    a = ProductFeatures(id="a", ingredients=("rare1", "x1"), actives=(), names={}, cat1=None, cat2=None,
                        brand="b", benefits=frozenset(), skin_types=frozenset())
    b = ProductFeatures(id="b", ingredients=("rare1", "y1"), actives=(), names={}, cat1=None, cat2=None,
                        brand="b", benefits=frozenset(), skin_types=frozenset())
    edges = compute_similar([a, b], top_k=5, min_score=0.2)
    assert edges and edges[0]["reason"] == "same_brand"


def test_features_from_row():
    row = {
        "id": "1", "active": True,
        "ingredients": [
            {"key": "water", "name": "Water", "role": "base", "rank": 1},
            {"key": "niacinamide", "name": "Niacinamide", "role": "active", "rank": 2},
            {"key": "zinc_oxide", "name": "Zinc Oxide", "role": "uv_filter", "rank": 3},
        ],
        "categories": [{"key": "k1", "level": 1}, {"key": "k2", "level": 2}, {"key": "k3", "level": 3}],
        "brand": [{"key": "torriden", "name": "Torriden"}],
        "benefits": [{"key": "sang_da"}], "skin_types": [{"key": "da_dau"}],
    }
    f = features_from_row(row)
    assert f.ingredients == ("water", "niacinamide", "zinc_oxide")
    assert f.actives == ("niacinamide", "zinc_oxide")
    assert (f.cat1, f.cat2, f.brand) == ("k1", "k2", "torriden")
    assert f.benefits == frozenset({"sang_da"}) and f.skin_types == frozenset({"da_dau"})
