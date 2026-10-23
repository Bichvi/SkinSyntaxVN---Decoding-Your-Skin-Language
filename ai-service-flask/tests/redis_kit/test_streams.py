import time

import pytest

from shared.redis_kit import keys, streams

S = "t:events:v1"


def read_all(r, stream=S):
    return [dict(f) for _, f in r.xrange(stream)]


def fail(msgs):
    raise RuntimeError("nope")


def test_publish_stringifies_and_bounds_length(r):
    assert streams.publish(S, {"a": 1, "b": None, "c": "x"}, maxlen=10, client=r)
    assert read_all(r) == [{"a": "1", "b": "", "c": "x"}]
    for i in range(500):
        streams.publish(S, {"i": i}, maxlen=10, client=r)
    assert r.xlen(S) < 500  # approximate trimming kicked in


def test_publish_returns_none_when_down():
    class Down:
        def xadd(self, *a, **k):
            raise ConnectionError("down")

    assert streams.publish(S, {"a": 1}, client=Down()) is None


def run(r, handler, **kw):
    streams.ensure_group(r, S, "g")
    params = dict(batch=100, block_ms=0, claim_idle_ms=0, failure_pause_s=0.0)
    params.update(kw)
    return streams.poll_once(r, S, "g", "c1", handler, **params)


def test_ensure_group_is_idempotent_and_reads_pre_existing_entries(r):
    streams.publish(S, {"n": 1}, client=r)  # published before the group exists
    streams.ensure_group(r, S, "g")
    streams.ensure_group(r, S, "g")
    got = []
    assert run(r, lambda msgs: got.extend(msgs)) == 1
    assert got[0].fields == {"n": "1"}


def test_ack_after_success_leaves_nothing_pending(r):
    for i in range(3):
        streams.publish(S, {"n": i}, client=r)
    assert run(r, lambda msgs: None) == 3
    assert r.xpending(S, "g")["pending"] == 0
    assert run(r, lambda msgs: None) == 0


def test_delete_on_ack_empties_the_buffer(r):
    for i in range(3):
        streams.publish(S, {"n": i}, client=r)
    run(r, lambda msgs: None, delete_on_ack=True)
    assert r.xlen(S) == 0


def test_handler_failure_keeps_messages_pending_then_retries(r):
    streams.publish(S, {"n": 1}, client=r)
    calls = []

    def flaky(msgs):
        calls.append([m.deliveries for m in msgs])
        if len(calls) == 1:
            raise RuntimeError("atlas down")

    assert run(r, flaky) == 0
    assert r.xpending(S, "g")["pending"] == 1
    time.sleep(0.01)
    assert run(r, flaky, claim_idle_ms=1) == 1
    assert calls == [[1], [2]]  # second attempt sees deliveries == 2
    assert r.xpending(S, "g")["pending"] == 0


def test_partial_ack_by_returning_ids(r):
    ids = [streams.publish(S, {"n": i}, client=r) for i in range(3)]
    assert run(r, lambda msgs: [msgs[0].id]) == 1
    pending = r.xpending_range(S, "g", "-", "+", 10)
    assert {p["message_id"] for p in pending} == set(ids[1:])


def test_poison_message_goes_to_dead_stream_after_max_deliveries(r):
    streams.publish(S, {"n": "bad"}, client=r)
    run(r, fail, claim_idle_ms=1, max_deliveries=5)  # attempt 1 (new message)
    for _ in range(4):  # attempts 2..5 (reclaimed)
        time.sleep(0.005)
        run(r, fail, claim_idle_ms=1, max_deliveries=5)
    assert r.xlen(keys.dead_stream(S)) == 0
    time.sleep(0.005)
    run(r, fail, claim_idle_ms=1, max_deliveries=5)  # 6th delivery -> dead letter, handler not called
    dead = r.xrange(keys.dead_stream(S))
    assert len(dead) == 1 and dead[0][1]["n"] == "bad" and dead[0][1]["_dl_deliveries"] == "6"
    assert r.xpending(S, "g")["pending"] == 0


def test_linger_waits_to_fill_a_batch(r):
    streams.ensure_group(r, S, "g")
    streams.publish(S, {"n": 0}, client=r)
    seen = []
    start = time.monotonic()
    run(r, lambda msgs: seen.append(len(msgs)), batch=500, block_ms=100, linger_ms=300)
    assert seen == [1] and time.monotonic() - start >= 0.25


def test_replay_dead_moves_back_without_meta(r):
    streams.publish(S, {"n": "x"}, client=r)
    run(r, fail, claim_idle_ms=1, max_deliveries=2)
    for _ in range(3):
        time.sleep(0.005)
        run(r, fail, claim_idle_ms=1, max_deliveries=2)
    assert r.xlen(keys.dead_stream(S)) == 1
    assert streams.replay_dead(S, client=r) == 1
    assert r.xlen(keys.dead_stream(S)) == 0
    assert read_all(r)[-1] == {"n": "x"}


@pytest.mark.integration
def test_real_redis_roundtrip(real_redis):
    stream = real_redis.test_prefix + "stream"
    streams.ensure_group(real_redis, stream, "g")
    for i in range(5):
        streams.publish(stream, {"n": i}, client=real_redis)
    got = []
    n = streams.poll_once(real_redis, stream, "g", "c", lambda m: got.extend(m), batch=10, block_ms=200,
                          claim_idle_ms=60_000, delete_on_ack=True)
    assert n == 5 and len(got) == 5
    assert real_redis.xlen(stream) == 0 and real_redis.xpending(stream, "g")["pending"] == 0
