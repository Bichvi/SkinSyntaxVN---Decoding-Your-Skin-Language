from datetime import datetime

from search_service.metrics import collect, fold, section_stats, zero_result_stats


def S(query, count=None, live=None):
    meta = {"query": query}
    if count is not None:
        meta["result_count"] = count
    if live is not None:
        meta["live"] = live
    return {"metadata": meta}


def test_fold_matches_php():
    assert fold("  ĐẶC TRỊ   Mụn ") == "dac tri mun"


def test_zero_result_rate_skips_live_and_legacy_rows():
    out = zero_result_stats([
        S("xyzabc", 0), S("XYZABC", 0), S("sữa rửa mặt", 5), S("ser", 0, live=True), S("old row"),
        S("Toner không cồn", 0), S("x", True),
    ])
    assert out["searches"] == 6 and out["searches_with_result_count"] == 4
    assert out["zero_result"] == 3 and out["zero_result_rate"] == 0.75
    assert out["live_partial_skipped"] == 1
    assert out["top_zero_queries"] == [("xyzabc", 2), ("toner khong con", 1)]


def test_zero_result_rate_none_without_counts():
    assert zero_result_stats([S("a")])["zero_result_rate"] is None


def test_section_ctr_and_atc():
    ev = (
        [{"action": "impression", "section": "semantic", "session_hash": "s1"}] * 4
        + [{"action": "click", "section": "semantic", "session_hash": "s1"}]
        + [{"action": "add_to_cart", "section": "semantic", "session_hash": "s2"}] * 2
        + [{"action": "impression", "section": "trending", "session_hash": "s2"}]
        + [{"action": "chip_click", "section": "chips", "chip": "best_sellers", "session_hash": "s2"},
           {"action": "bogus", "section": "semantic"}]
    )
    out = section_stats(ev)
    rows = {r["section"]: r for r in out["sections"]}
    assert rows["semantic"]["ctr"] == 0.25 and rows["semantic"]["atc_rate"] == 0.5
    assert rows["trending"]["clicks"] == 0 and rows["trending"]["ctr"] == 0.0
    assert rows["ALL"]["impressions"] == 5 and rows["ALL"]["ctr"] == 0.2
    assert out["chip_clicks"] == {"best_sellers": 1} and out["sessions"] == 2
    assert [r["section"] for r in out["sections"]] == ["semantic", "trending", "ALL"]


class Coll:
    def __init__(self, docs):
        self.docs, self.queries = docs, []

    def find(self, query, projection=None):
        self.queries.append(query)
        return list(self.docs)


def test_collect_queries_are_read_only_finds_with_window():
    db = {"tuong_tac_nguoi_dung": Coll([S("a", 0)]), "search_events": Coll([])}
    out = collect(db, 7, now=datetime(2026, 10, 21))
    assert out["since"] == datetime(2026, 10, 14)
    assert db["tuong_tac_nguoi_dung"].queries[0]["loai_tuong_tac"] == "search"
    assert db["search_events"].queries[0] == {"ts": {"$gte": datetime(2026, 10, 14)}}
    assert out["zero"]["zero_result"] == 1
