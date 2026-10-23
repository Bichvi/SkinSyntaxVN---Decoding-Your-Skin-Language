import pytest
from pymongo.errors import AutoReconnect

from shared.redis_kit import streams
from workers import search_events_flusher as w


def msg(i="1700000000000-0", **over):
    fields = {"ts_ms": "1700000000123", "q_norm": "xyzabc", "section": "semantic", "product_id": "1496",
              "action": "click", "user_hash": "", "session_hash": "a" * 64}
    fields.update(over)
    return streams.Message(i, fields)


class Coll:
    def __init__(self, exc=None):
        self.docs, self.exc = [], exc

    def insert_many(self, docs, ordered=True):
        assert ordered is False
        if self.exc:
            raise self.exc
        self.docs.extend(docs)


def test_build_doc_matches_php_shape():
    doc = w.build_doc(msg(chip="best_sellers", action="chip_click", section="chips", product_id=""))
    assert doc["_id"] == "sev1-1700000000000-0" and doc["user_hash"] is None
    assert doc["ts"].isoformat().startswith("2023-11-14T22:13:20.123") and doc["ts"].tzinfo is not None
    assert doc["chip"] == "best_sellers"
    assert set(doc) == {"_id", "ts", "q_norm", "section", "product_id", "action", "user_hash", "session_hash", "chip"}


@pytest.mark.parametrize("bad", [dict(action="purchase"), dict(section="evil"), dict(q_norm=""),
                                 dict(session_hash=""), dict(ts_ms="x")])
def test_invalid_entries_are_dropped_but_acked(bad):
    coll = Coll()
    acked = list(w.flush([msg(**bad), msg("2-0")], coll))
    assert acked == ["1700000000000-0", "2-0"] and len(coll.docs) == 1


def test_flush_raises_on_mongo_outage_so_nothing_is_acked():
    with pytest.raises(AutoReconnect):
        w.flush([msg()], Coll(AutoReconnect("down")))


def test_duplicate_key_errors_count_as_success():
    class Dup(Exception):
        details = {"writeErrors": [{"code": 11000}]}

    assert list(w.flush([msg()], Coll(Dup()))) == ["1700000000000-0"]


# ---------------------------------------------------------------- U12 optional fields

def test_build_doc_without_u12_fields_is_unchanged():
    doc = w.build_doc(msg())
    assert "product_ids" not in doc and "position" not in doc and "experiment" not in doc and "variant" not in doc


def test_build_doc_keeps_u12_fields():
    imp = w.build_doc(msg(action="impression", product_id="", product_ids="1496,18,ab-1", experiment="graph_weight_v1", variant="variant"))
    assert imp["product_ids"] == ["1496", "18", "ab-1"] and imp["experiment"] == "graph_weight_v1" and imp["variant"] == "variant"
    clk = w.build_doc(msg(position="3"))
    assert clk["position"] == 3 and "product_ids" not in clk


def test_build_doc_drops_invalid_u12_values_but_keeps_the_event():
    doc = w.build_doc(msg(action="impression", product_id="", product_ids="1,;DROP,3," + ",".join(str(i) for i in range(30)),
                          position="9", experiment="Bad Id", variant="v"))
    assert doc is not None and doc["product_ids"][:2] == ["1", "3"] and len(doc["product_ids"]) == 20
    assert "position" not in doc and "experiment" not in doc and "variant" not in doc
    assert "position" not in w.build_doc(msg(position="0")) and "position" not in w.build_doc(msg(position="51"))
    assert "experiment" not in w.build_doc(msg(experiment="ok"))          # variant missing -> both dropped
