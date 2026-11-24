"""Pure unit tests for search_service.active_overlaps (levels: warn = leave-on, info = wash-off)."""

from search_service.active_overlaps import active_overlaps, rinse_off_of

CART = {"a": ["retinol"], "b": ["tretinoin"]}


def test_default_level_is_warn_and_backward_compatible():
    out = active_overlaps({"adapalene"}, CART, lambda p: f"P{p}")
    assert out == [{"group": "retinoids", "label_vi": "retinoid", "cart_product_id": "a", "cart_product_name": "Pa",
                    "level": "warn"}]


def test_wash_off_candidate_is_info():
    assert [o["level"] for o in active_overlaps({"retinol"}, CART, None, cand_rinse_off=True)] == ["info"]


def test_wash_off_cart_product_is_info_and_leave_on_wins_when_mixed():
    assert [o["level"] for o in active_overlaps({"retinol"}, CART, None, cart_rinse_off={"a": True, "b": True})] == ["info"]
    out = active_overlaps({"retinol"}, CART, None, cart_rinse_off={"a": True, "b": False})
    assert [(o["level"], o["cart_product_id"]) for o in out] == [("warn", "b")]


def test_rinse_off_of_uses_category_chains():
    got = rinse_off_of({"1": ["serum"], 2: ["sua_rua_mat", "x"]}, {"sua_rua_mat"})
    assert got == {"1": False, "2": True}
