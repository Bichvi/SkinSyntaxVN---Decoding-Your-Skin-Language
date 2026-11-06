"""AC3: the label path (scan = catalog product A) gives the same conflicts as CartAnalyzeService for cart [A, B].

The oracle is the real CartAnalyzeService (graph down -> its Python/Mongo-text path, text loader faked)."""

import pytest

from graph_sync.ingredients import load_aliases
from label_service.service import LabelAnalyzeService, LabelRequest
from label_service.vision import LabelText
from search_service.cart_analyze import CartAnalyzeService

ALIASES = load_aliases()
A_TEXT = "Water, Butylene Glycol, Glycolic Acid, Glycerin, Sodium Hyaluronate, Panthenol, Allantoin"
B_TEXT = "Water, Glycerin, Retinol, Squalane, Tocopherol, Panthenol, Allantoin"
TEXTS = {"A": {"full": A_TEXT, "sach": ""}, "B": {"full": B_TEXT, "sach": ""}}


class Catalog:
    def __init__(self, a_cats):
        self.items = {"A": {"id": "A", "ten_san_pham": "Glycolic Toner", "thuong_hieu": "Brand",
                            "category_keys": a_cats},
                      "B": {"id": "B", "ten_san_pham": "Retinol Serum", "thuong_hieu": "Other",
                            "category_keys": ["serum", "cham_soc_da_mat"]}}

    def products(self):
        return list(self.items.values())

    def get(self, pid):
        return self.items.get(pid)


def key(c):
    return (c["severity"], c["timing"], c["rule_id"], tuple(sorted(c["ingredients"])))


@pytest.mark.parametrize("a_cats", [
    ["toner", "cham_soc_da_mat"],
    ["sua_rua_mat", "cham_soc_da_mat"],      # rinse-off: severity lowered on both paths
    ["tay_trang", "cham_soc_da_mat"],        # rinse-off category outside the 5 mapped routine steps
    ["mat_na_rua", "cham_soc_da_mat"],
    ["trang_diem"],                          # ignored category: no conflict on either path
])
def test_label_path_equals_cart_service(a_cats):
    catalog = Catalog(a_cats)
    cart = CartAnalyzeService(None, catalog, text_loader=lambda ids: {i: TEXTS[i] for i in ids if i in TEXTS})
    expected, _ = cart.conflicts_for(["A", "B"])
    service = LabelAnalyzeService(
        cart_service=cart, catalog=catalog, text_loader=cart.text_loader, aliases=ALIASES,
        vision=lambda *a, **k: LabelText(True, "Glycolic Toner", "Brand", "toner", A_TEXT, "en", "groq"))
    out = service.analyze(LabelRequest(b"\xff\xd8x", "image/jpeg", None, "", "", ("B",), None, None, None, None))
    assert out["ingredients"]["source"] == "catalog" and out["product"]["matched_product_id"] == "A"
    assert [key(c) for c in out["conflicts"]] == [key(c) for c in expected]
    if a_cats[0] == "trang_diem":
        assert expected == []
    else:
        assert expected
    if a_cats[0] in ("sua_rua_mat", "tay_trang", "mat_na_rua"):
        assert out["conflicts"][0]["severity"] != "high"        # lowered by the rinse-off rule, like the cart page
