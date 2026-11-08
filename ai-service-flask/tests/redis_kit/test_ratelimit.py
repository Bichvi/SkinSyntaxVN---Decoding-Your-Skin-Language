from shared.redis_kit import ratelimit


class Broken:
    def pipeline(self, *a, **k):
        raise ConnectionError("down")


def test_allows_up_to_limit_then_denies_with_retry_after(r):
    key = "rl:t:v1:a"
    results = [ratelimit.allow(key, 3, 10, client=r, now=100.0 + i) for i in range(3)]
    assert all(ok for ok, _ in results)
    ok, retry = ratelimit.allow(key, 3, 10, client=r, now=103.0)
    assert ok is False
    assert 6.9 < retry < 7.1  # oldest hit (t=100) leaves the window at t=110


def test_window_boundary_slides(r):
    key = "rl:t:v1:b"
    for i in range(3):
        assert ratelimit.allow(key, 3, 10, client=r, now=100.0 + i)[0]
    assert ratelimit.allow(key, 3, 10, client=r, now=109.9)[0] is False   # first hit still inside
    assert ratelimit.allow(key, 3, 10, client=r, now=110.1)[0] is True    # t=100 slid out
    assert ratelimit.allow(key, 3, 10, client=r, now=110.2)[0] is False   # t=101,102,110.1 inside


def test_rejected_hits_do_not_extend_the_block(r):
    key = "rl:t:v1:c"
    for i in range(2):
        assert ratelimit.allow(key, 2, 10, client=r, now=100.0 + i)[0]
    for i in range(50):  # hammering while blocked
        assert ratelimit.allow(key, 2, 10, client=r, now=102.0 + i * 0.01)[0] is False
    assert r.zcard(key) == 2
    assert ratelimit.allow(key, 2, 10, client=r, now=110.5)[0] is True


def test_keys_are_independent_and_ttl_is_set(r):
    assert ratelimit.allow("rl:t:v1:x", 1, 5, client=r, now=1.0)[0]
    assert ratelimit.allow("rl:t:v1:y", 1, 5, client=r, now=1.0)[0]
    assert r.pttl("rl:t:v1:x") > 0


def test_fail_open_when_redis_errors_or_missing():
    assert ratelimit.allow("k", 1, 1, client=Broken()) == (True, 0.0)
    assert ratelimit.allow("k", 0, 1, client=Broken()) == (True, 0.0)  # limit 0 = disabled


def test_retry_after_header_is_at_least_one_integer():
    assert ratelimit.retry_after_header(0.0) == "1"
    assert ratelimit.retry_after_header(6.2) == "7"
