import pytest

from shared.redis_kit.lock import LockHeld, RedisLock


def test_acquire_release_and_exclusion(r):
    with RedisLock("lock:t:v1", ttl_ms=5000, client=r) as lock:
        assert lock.acquired and r.get("lock:t:v1") == lock.token
        with pytest.raises(LockHeld):
            with RedisLock("lock:t:v1", ttl_ms=5000, client=r):
                pass
        assert r.get("lock:t:v1") == lock.token  # failed contender did not touch it
    assert r.get("lock:t:v1") is None


def test_release_does_not_free_a_lock_taken_over_by_another_owner(r):
    lock = RedisLock("lock:t:v2", ttl_ms=5000, client=r)
    assert lock.acquire()
    r.set("lock:t:v2", "someone-else")  # our TTL "expired" and another owner took it
    assert lock.release() is False
    assert r.get("lock:t:v2") == "someone-else"


def test_ttl_is_set_and_extend_requires_ownership(r):
    lock = RedisLock("lock:t:v3", ttl_ms=2000, client=r)
    lock.acquire()
    assert 0 < r.pttl("lock:t:v3") <= 2000
    assert lock.extend(60_000) is True and r.pttl("lock:t:v3") > 2000
    r.set("lock:t:v3", "other")
    assert lock.extend(60_000) is False


def test_exception_in_body_still_releases(r):
    with pytest.raises(ValueError):
        with RedisLock("lock:t:v4", ttl_ms=5000, client=r):
            raise ValueError("boom")
    assert r.get("lock:t:v4") is None


def test_legacy_key_name_is_used_verbatim(r):
    with RedisLock("graph_sync:lock", ttl_ms=1_800_000, client=r):
        assert r.exists("graph_sync:lock") == 1


class Down:
    def set(self, *a, **k):
        raise ConnectionError("down")


def test_redis_down_fail_open_vs_closed():
    with RedisLock("k", client=Down(), fail_open=True) as lock:
        assert lock.acquired is False and lock.degraded is True
    with pytest.raises(LockHeld):
        with RedisLock("k", client=Down(), fail_open=False):
            pass
