"""U10 graph tools: argument validation, filtering against fresh facts, result size, conflicts, fallbacks."""

from __future__ import annotations

import json
import logging

import pytest

from chatbot_service import graph_tools as gt
from graph_fakes import FakeGraph, make_tools, row


def facts_for(**by_id):
    def load(ids):
        return {i: by_id[i] for i in ids if i in by_id}
    return load


# ------------------------------------------------------------------ validation (no query may run on bad input)


@pytest.mark.parametrize("args", [
    {"category": "serum' OR 1=1 //"},                  # injection attempt: not a vocabulary key
    {"category": "khong_co_danh_muc"},
    {"skin_type": "da_xanh"},
    {"brand": "hang_la"},
    {"concerns": ["mun", "khong_phai_concern"]},
    {"include_ingredients": "retinol"},                # must be a list
    {"exclude_ingredients": ["alcohol'; MATCH (n) DETACH DELETE n //"]},
    {"avoid_conflict_with": ["khong_co_hoat_chat"]},
    {"price_max": "re"}, {"price_max": 10}, {"price_max": 10**10}, {"price_min": 500000, "price_max": 100000},
    {"limit": 0}, {"limit": 99}, {"limit": True},
    {"concerns": ["mun"] * 9},
    {"unknown_param": 1},
])
def test_find_products_rejects_bad_arguments_without_touching_graph(args):
    graph = FakeGraph([row("1")])
    tools = make_tools(graph)
    with pytest.raises(gt.ToolError) as err:
        tools.call("find_products", args)
    assert err.value.code == "invalid_params"
    assert graph.calls == []


def test_unknown_tool_and_check_conflicts_argument_shapes():
    tools = make_tools()
    with pytest.raises(gt.ToolError):
        tools.call("run_cypher", {"query": "MATCH (n) RETURN n"})
    for bad in ({}, {"product_ids": ["1", "2"], "ingredient_keys": ["retinol", "salicylic_acid"]},
                {"ingredient_keys": ["retinol"]}, {"product_ids": ["1"]}, {"product_ids": ["1; DROP", "2"]},
                {"ingredient_keys": ["retinol", "khong_co"]}):
        with pytest.raises(gt.ToolError):
            tools.call("check_conflicts", bad)
    with pytest.raises(gt.ToolError):
        tools.call("explain_product", {"product_id": "../etc"})


def test_find_products_normalises_arguments_for_the_repository():
    graph = FakeGraph([row("1")])
    make_tools(graph).call("find_products", {
        "category": "serum", "skin_type": "da_dau", "brand": "torriden", "concerns": ["mun"],
        "include_ingredients": ["ascorbic_acid"], "exclude_ingredients": ["alcohol_denat"],
        "avoid_conflict_with": ["retinol"], "price_max": 300000, "limit": 3})
    call = graph.calls[0]
    assert call["categories"] == ["serum"] and call["skin_type"] == "da_dau" and call["brand"] == "torriden"
    assert call["concerns"] == ["mun"] and call["avoid_keys"] == ["retinol"]
    assert call["price"] == {"min_vnd": None, "max_vnd": 300000}
    # the "ascorbic_acid" request is satisfied by any vitamin C form (family), "cồn" by the whole alcohol group
    assert len(call["include_groups"]) == 1 and "ascorbyl_glucoside" in call["include_groups"][0]
    assert {"alcohol_denat", "isopropyl_alcohol"} <= set(call["exclude_keys"])
    assert "cetyl_alcohol" not in call["exclude_keys"]          # fatty alcohols are not drying alcohol


@pytest.mark.parametrize("group,member", [("paraben", "propylparaben"), ("silicone", "dimethicone"),
                                          ("sulfate", "sodium_lauryl_sulfate"), ("fragrance", "linalool"),
                                          ("mineral_oil", "mineral_oil")])
def test_exclusion_groups_expand_to_member_keys(group, member):
    graph = FakeGraph([row("1")])
    make_tools(graph).call("find_products", {"category": "kem_duong", "exclude_ingredients": [group]})
    assert member in graph.calls[0]["exclude_keys"]


def test_exclusion_group_yaml_keys_exist_in_alias_vocabulary():
    from graph_sync.ingredients import load_aliases

    known = {e.key for e in load_aliases().values()}
    groups = gt.load_groups()
    for group in groups.exclusion.values():
        assert group.keys, group.id
        assert set(group.keys) <= known, (group.id, set(group.keys) - known)
        assert group.triggers <= known
    for family, members in groups.families.items():
        assert family in members and set(members) <= known, family


# ------------------------------------------------------------------ results use fresh facts, not the graph copy


def test_price_stock_and_visibility_come_from_facts():
    rows = [row("1", price=100000), row("2", price=290000), row("3", price=150000), row("4", price=120000),
            row("5", price=110000)]
    facts = facts_for(**{
        "1": {"price": 350000, "in_stock": True},       # graph said 100k, source of truth says 350k -> over the limit
        "2": {"price": 250000, "in_stock": True},       # graph said 290k, now 250k -> stays
        "3": {"price": 150000, "in_stock": False},      # sold out -> dropped
        "4": {"hidden": True},                          # hidden in Mongo -> dropped
        "5": {"price": 110000, "in_stock": True, "image_url": "http://img/5.jpg"},
    })
    result = make_tools(FakeGraph(rows), facts).call("find_products", {"category": "serum", "price_max": 300000})
    by_id = {p["id"]: p for p in result["products"]}
    assert set(by_id) == {"2", "5"}
    assert by_id["2"]["price"] == 250000
    assert all(p["price"] <= 300000 for p in result["products"])
    assert {c["id"] for c in result["_cards"]} == set(by_id)
    assert next(c for c in result["_cards"] if c["id"] == "5")["image_url"] == "http://img/5.jpg"
    assert any("hết hàng" in n for n in result["notes"])


def test_when_no_fact_source_is_reachable_graph_values_are_kept():
    result = make_tools(FakeGraph([row("1", price=99000)]), lambda ids: {}).call("find_products", {"category": "serum"})
    assert result["products"][0]["price"] == 99000


def test_universal_skin_tag_is_added_only_when_exact_matches_are_scarce():
    graph = FakeGraph([row("1")], rows_universal=[row("1"), row("2", skin_exact=False), row("3", skin_exact=False)])
    result = make_tools(graph).call("find_products", {"category": "serum", "skin_type": "da_dau"})
    assert [c["universal"] for c in graph.calls] == [False, True]
    assert [p["skin"] for p in result["products"]] == ["exact", "universal", "universal"]    # exact first
    assert any("mọi loại da" in n for n in result["notes"])
    many = FakeGraph([row(str(i)) for i in range(5)])
    make_tools(many).call("find_products", {"category": "serum", "skin_type": "da_dau"})
    assert len(many.calls) == 1


def test_avoid_conflict_note_names_the_rule_and_unknown_pairs_are_reported():
    graph = FakeGraph([row("1")])
    result = make_tools(graph).call("find_products", {"category": "serum", "avoid_conflict_with": ["retinol"]})
    assert any("Không kỵ Retinol" in n and "Salicylic" in n for n in result["notes"])
    result = make_tools(graph).call("find_products", {"category": "serum", "avoid_conflict_with": ["niacinamide"]})
    assert any("Chưa có luật xung đột" in n for n in result["notes"])


def test_result_stays_under_2kb_and_is_marked_truncated():
    rows = [row(str(i), name="Serum rất dài tên " * 6, include_hits=[{"name": "Niacinamide", "rank": 3, "key": "niacinamide"}],
                concern_hits=[{"name": "Mụn", "source": "manual"}], benefit_hits=[{"name": "Cấp ẩm", "source": "inferred"}])
            for i in range(10)]
    result = make_tools(FakeGraph(rows)).call("find_products", {"category": "serum", "limit": 10})
    assert gt.result_size(result) <= gt.MAX_RESULT_BYTES
    assert len(json.dumps(gt.public_view(result), ensure_ascii=False, separators=(",", ":")).encode("utf-8")) <= gt.MAX_RESULT_BYTES
    assert result["count"] == len(result["products"])


def test_graph_failure_becomes_graph_unavailable():
    tools = make_tools(FakeGraph(fail=RuntimeError("neo4j_down")))
    with pytest.raises(gt.ToolError) as err:
        tools.call("find_products", {"category": "serum"})
    assert err.value.code == "graph_unavailable"


def test_log_has_tool_and_normalised_params_but_no_sentence(caplog):
    caplog.set_level(logging.INFO, logger="syna_graph")
    make_tools(FakeGraph([row("1")])).call("find_products", {"category": "serum", "skin_type": "da_dau", "price_max": 300000})
    record = json.loads(next(r.message for r in caplog.records if "syna_graph_tool" in r.message))
    assert record["tool"] == "find_products" and record["count"] == 1
    assert record["params"]["category"] == "serum" and record["params"]["price_max"] == 300000
    assert "took_ms" in record


# ------------------------------------------------------------------ check_conflicts


def test_ingredient_conflict_retinol_bha_is_high_with_expert_note():
    result = make_tools().call("check_conflicts", {"ingredient_keys": ["retinol", "salicylic_acid"]})
    assert result["conflict_found"] and result["count"] == 1
    pair = result["pairs"][0]
    assert pair["severity"] == "high" and pair["timing"] == "same_routine" and pair["rule"] == "retinoid_bha"
    assert "xen kẽ" in pair["advice"]
    assert result["consult_expert"] and "bác sĩ da liễu" in result["safety_note"]


def test_no_rule_means_no_conflict_but_never_claims_absolute_safety():
    result = make_tools().call("check_conflicts", {"ingredient_keys": ["niacinamide", "hyaluronic_acid"]})
    assert result["conflict_found"] is False and "không đồng nghĩa" in result["note"]
    assert "consult_expert" not in result


def test_product_conflicts_use_injected_source_and_names():
    def conflicts(ids):
        return ([{"a": "1", "b": "2", "severity": "medium", "timing": "same_session", "advice_vi": "Tách sáng/tối.",
                  "ingredients": ["Retinol", "Glycolic Acid"], "rule_id": "r", "evidence": "strong"}], False)
    tools = make_tools(facts=facts_for(**{"1": {"name": "Serum A"}, "2": {"name": "Toner B"}}), product_conflicts=conflicts)
    result = tools.call("check_conflicts", {"product_ids": ["1", "2"]})
    assert result["pairs"][0]["a"] == "Serum A" and result["pairs"][0]["severity"] == "medium"
    assert "consult_expert" not in result


# ------------------------------------------------------------------ explain_product / similar_products


def test_explain_product_reports_probe_and_unreadable_ingredient_lists():
    explain = {"id": "9", "name": "Kem B", "price": 1, "in_stock": True, "active": True, "brand": "X", "category": "Kem",
               "actives": [{"key": "niacinamide", "name": "Niacinamide", "rank": 4, "role": "active"}],
               "probe_hits": [{"key": "alcohol_denat", "name": "Alcohol Denat.", "rank": 9}],
               "benefits": [{"name": "Cấp ẩm", "source": "inferred"}], "concerns": [],
               "skin_types": [{"name": "Da khô", "source": "data"}], "n_ingredients": 20}
    tools = make_tools(FakeGraph(explain=explain), facts_for(**{"9": {"price": 150000, "in_stock": True}}))
    result = tools.call("explain_product", {"product_id": "9", "probe_ingredients": ["alcohol_denat"]})
    assert result["product"]["price"] == 150000
    assert result["probe"] == [{"ingredient": "cồn khô", "present": True, "readable": True}]
    assert result["actives"][0]["name"] == "Niacinamide"
    unread = dict(explain, n_ingredients=0, probe_hits=[])
    result = make_tools(FakeGraph(explain=unread)).call(
        "explain_product", {"product_id": "9", "probe_ingredients": ["alcohol_denat"]})
    assert result["probe"][0]["present"] is False and result["probe"][0]["readable"] is False
    with pytest.raises(gt.ToolError) as err:
        make_tools(FakeGraph(explain=None)).call("explain_product", {"product_id": "404"})
    assert err.value.code == "not_found"


def test_similar_products_enriches_with_facts_and_drops_sold_out():
    class Similar:
        def similar(self, request):
            assert request.product_id == "1" and request.limit == 5
            return {"items": [{"product_id": "2", "reason_code": "shared_ingredients", "reason_params": ["Niacinamide"]},
                              {"product_id": "3", "reason_code": "same_category", "reason_params": []}],
                    "source": "graph", "degraded": False}
    facts = facts_for(**{"1": {"name": "Gốc"}, "2": {"name": "Serum 2", "price": 100000, "in_stock": True, "brand": "B"},
                         "3": {"name": "Serum 3", "price": 90000, "in_stock": False}})
    result = make_tools(similar=Similar(), facts=facts).call("similar_products", {"product_id": "1"})
    assert [i["id"] for i in result["items"]] == ["2"] and result["items"][0]["shared"] == ["Niacinamide"]
    assert [c["id"] for c in result["_cards"]] == ["2"]
