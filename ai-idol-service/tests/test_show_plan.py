import pytest
import requests

from core import show_plan as sp


def _items(*ids):
    return [{"product_id": i} for i in ids]


def test_order_full_routine():
    steps = {"a": "kem_chong_nang", "b": "serum", "c": "tay_trang", "d": "toner", "e": "kem_duong"}
    out = sp.order_products(_items("a", "b", "c", "d", "e"), steps)
    assert [o["product_id"] for o in out] == ["c", "d", "b", "e", "a"]
    assert [o["number"] for o in out] == [1, 2, 3, 4, 5]
    assert out[0]["step_key"] == "tay_trang"
    assert out[0]["step_label"] == sp.STEP_LABELS_VI["tay_trang"]


def test_exfoliant_after_cleanser_before_serum():
    steps = {"s": "serum", "x": "tay_te_bao_chet", "c": "sua_rua_mat"}
    out = sp.order_products(_items("s", "x", "c"), steps)
    assert [o["product_id"] for o in out] == ["c", "x", "s"]


def test_unknown_step_before_moisturiser():
    steps = {"k": "kem_duong", "u": None, "t": "toner"}
    out = sp.order_products(_items("k", "u", "t"), steps)
    assert [o["product_id"] for o in out] == ["t", "u", "k"]


def test_same_step_keeps_input_order():
    steps = {"b": "serum", "a": "serum", "c": "serum"}
    out = sp.order_products(_items("b", "a", "c"), steps)
    assert [o["product_id"] for o in out] == ["b", "a", "c"]


def test_keywords():
    assert sp.step_from_keywords("Gel Tẩy Tế Bào Chết Arrahan") == "tay_te_bao_chet"
    assert sp.step_from_keywords("Kem Chống Nắng Beplain") == "kem_chong_nang"
    assert sp.step_from_keywords("Serum Timeless Vitamin B5") == "serum"
    assert sp.step_from_keywords("Son môi") is None


class _Resp:
    def __init__(self, body, status=200):
        self._body, self.status_code = body, status

    def json(self):
        return self._body


def _snaps():
    return [
        {"id": "1", "name": "Kem Chống Nắng Beplain", "pricing": {"regular_price": 100000}},
        {"id": "2", "name": "Serum Timeless", "pricing": {"regular_price": 200000}},
    ]


def test_build_show_plan_uses_graph_steps():
    calls = []

    def post(url, json=None, headers=None, timeout=None):
        calls.append(json)
        ids = json["product_ids"]
        if len(ids) == 1:
            key = "kem_chong_nang" if ids[0] == "1" else "serum"
            return _Resp({"routine": {"has": [key]}, "conflicts": []})
        return _Resp({"routine": {"has": []}, "conflicts": [
            {"a": "1", "b": "2", "severity": "high", "advice_vi": "Không dùng chung.", "ingredients": ["AHA", "Retinol"]}]})

    plan = sp.build_show_plan(_snaps(), post=post)
    assert plan["routine_source"] == "graph"
    assert [p["product_id"] for p in plan["products"]] == ["2", "1"]
    assert plan["theme_vi"] == ""
    assert plan["conflicts"] == [{"a": "1", "b": "2", "severity": "high", "message_vi": "Không dùng chung."}]


@pytest.mark.parametrize("mode", ["timeout", "401"])
def test_build_show_plan_falls_back_on_http_error(mode):
    def post(url, json=None, headers=None, timeout=None):
        if mode == "timeout":
            raise requests.Timeout("slow")
        return _Resp({"error": "unauthorized"}, 401)

    plan = sp.build_show_plan(_snaps(), post=post)
    assert plan["routine_source"] == "keyword"
    assert [p["product_id"] for p in plan["products"]] == ["2", "1"]
    assert plan["conflicts"] == []


def test_degraded_response_is_keyword_source():
    def post(url, json=None, headers=None, timeout=None):
        return _Resp({"conflicts": [], "routine": {"has": []}, "degraded": True})

    assert sp.build_show_plan(_snaps(), post=post)["routine_source"] == "keyword"


def test_bundle_total_uses_flash_price():
    p = [{"regular_price": 95000, "market_price": None, "flash": None},
         {"regular_price": 278000, "market_price": None, "flash": {"flash_price": 250000}}]
    assert sp.bundle_total(p) == 345000
    assert sp.bundle_total([None, p[0]]) == 95000


def test_bundle_total_phrase():
    assert sp.bundle_total_phrase(371000) == "tổng cả bộ: ba trăm bảy mươi mốt nghìn đồng"


def test_estimate_preparation_minutes():
    assert sp.estimate_preparation_minutes(3) == 98
    assert sp.estimate_preparation_minutes(6) == 146
