from label_service import schedule

RETINOL = {"retinol", "water"}
AHA = {"glycolic_acid", "water"}
BPO = {"benzoyl_peroxide"}
VITC = {"ascorbic_acid"}
VITC_DERIV = {"sodium_ascorbyl_phosphate"}
PLAIN = {"water", "glycerin"}


def one(timing, scan_keys, cart_keys, a="scan", b="456"):
    out = schedule.plan([{"a": a, "b": b, "timing": timing}], {"scan": scan_keys, "456": cart_keys})
    assert len(out) == 1
    return out[0]


def slots(item):
    return item["scan_slot"], item["cart_slot"]


def test_aha_scan_vs_cart_retinol_same_session_aha_am_retinol_pm():
    item = one("same_session", AHA, RETINOL)
    assert item["kind"] == "split_session" and item["cart_product_id"] == "456"
    assert slots(item) == ("am", "pm")
    assert item["reason_vi"]


def test_retinol_scan_vs_cart_aha_mirrors():
    assert slots(one("same_session", RETINOL, AHA)) == ("pm", "am")


def test_retinol_vs_vitamin_c_split_pm_am():
    item = one("same_session", RETINOL, VITC)
    assert item["kind"] == "split_session" and slots(item) == ("pm", "am")


def test_vitamin_c_derivative_counts_as_vitamin_c():
    assert slots(one("same_session", VITC_DERIV, RETINOL)) == ("am", "pm")


def test_one_side_without_preference_gets_opposite_slot():
    assert slots(one("same_session", RETINOL, PLAIN)) == ("pm", "am")
    assert slots(one("same_session", PLAIN, RETINOL)) == ("am", "pm")
    assert slots(one("same_session", PLAIN, PLAIN)) == ("am", "pm")


def test_retinol_vs_bpo_alternate_nights():
    item = one("same_session", RETINOL, BPO)
    assert item["kind"] == "alternate_nights" and slots(item) == (None, None)


def test_two_pm_products_alternate_nights():
    assert one("same_session", AHA, BPO)["kind"] == "alternate_nights"
    assert one("same_session", RETINOL, {"retinal"})["kind"] == "alternate_nights"


def test_same_routine_and_ok_alternate_days():
    for timing in ("same_routine", "ok_alternate_days"):
        item = one(timing, RETINOL, AHA)
        assert item["kind"] == "alternate_days" and slots(item) == (None, None)


def test_scan_id_may_be_either_side():
    item = one("same_session", RETINOL, VITC, a="456", b="scan")
    assert item["cart_product_id"] == "456" and slots(item) == ("pm", "am")


def test_custom_scan_id_and_unknown_timing_skipped():
    out = schedule.plan([{"a": "x1", "b": "9", "timing": "same_session"},
                         {"a": "x1", "b": "8", "timing": "weird"}],
                        {"x1": RETINOL, "9": VITC, "8": VITC}, scan_id="x1")
    assert [o["cart_product_id"] for o in out] == ["9"]


def test_needs_sunscreen():
    assert schedule.needs_sunscreen({"retinol"})
    assert schedule.needs_sunscreen({"glycolic_acid"})
    assert schedule.needs_sunscreen({"salicylic_acid"})
    assert not schedule.needs_sunscreen({"niacinamide", "ascorbic_acid", "benzoyl_peroxide"})
    assert not schedule.needs_sunscreen(set())


def test_slot_preference_table():
    assert schedule.SLOT_PREFERENCE == {"retinoids": "pm", "exfoliating_acids": "pm",
                                        "benzoyl_peroxide": "pm", "vitamin_c": "am"}
