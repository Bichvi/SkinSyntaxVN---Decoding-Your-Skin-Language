import json

from agent_graph.state import ConvState, StateStore


class _Redis:
    def __init__(self):
        self.data = {}

    def setex(self, key, ttl, value):
        self.data[key] = value

    def get(self, key):
        return self.data.get(key)


def test_open_draft_qty_roundtrip():
    store = StateStore(_Redis())
    store.save("s", ConvState(open_draft_id="D1", open_draft_qty={"1200": 3}))
    loaded = store.load("s")
    assert loaded.open_draft_id == "D1"
    assert loaded.open_draft_qty == {"1200": 3}


def test_none_qty_roundtrips_as_none():
    store = StateStore(_Redis())
    store.save("s", ConvState(open_draft_id="D1"))
    assert store.load("s").open_draft_qty is None


def test_invalid_draft_qty_loads_as_none():
    for bad in ({"1200": 0}, {"1200": -1}, {"1200": "3"}, {"1200": True}, [1], "x", 5, {}):
        redis = _Redis()
        redis.data[StateStore._key("s")] = json.dumps({"draft_id": "D1", "draft_qty": bad})
        assert StateStore(redis).load("s").open_draft_qty is None, bad


def test_old_payload_without_draft_qty_loads_as_none():
    redis = _Redis()
    redis.data[StateStore._key("s")] = json.dumps({"draft_id": "D1", "pending": "draft_open:D1"})
    loaded = StateStore(redis).load("s")
    assert loaded.open_draft_id == "D1"
    assert loaded.open_draft_qty is None


def test_draft_deadline_roundtrip_and_validation():
    store = StateStore(_Redis())
    store.save("s", ConvState(open_draft_id="D1", open_draft_deadline=1234.5))
    assert store.load("s").open_draft_deadline == 1234.5
    for bad in (0, -5, True, "9", None, [1]):
        redis = _Redis()
        redis.data[StateStore._key("s")] = json.dumps({"draft_id": "D1", "draft_deadline": bad})
        assert StateStore(redis).load("s").open_draft_deadline is None, bad


def test_old_payload_without_draft_deadline_loads_as_none():
    redis = _Redis()
    redis.data[StateStore._key("s")] = json.dumps({"draft_id": "D1"})
    assert StateStore(redis).load("s").open_draft_deadline is None
