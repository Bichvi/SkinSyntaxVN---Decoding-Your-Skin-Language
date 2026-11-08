import re

from shared.redis_kit import keys

CONVENTION = re.compile(r"^[a-z0-9_]+(:[a-z0-9_]+)*:v\d+(:[a-z0-9_.\-]+)*$")


def test_all_stored_keys_follow_convention():
    for name in ("SEARCH_CACHE_GEN", "SEARCH_TRENDING", "SEARCH_EVENTS_STREAM", "SEARCH_EVENTS_DEAD",
                 "SEARCH_POPULAR", "SEARCH_BREAKER", "CATALOG_CHANGES_STREAM", "CATALOG_CHANGES_DEAD"):
        assert CONVENTION.match(getattr(keys, name)), name
    assert CONVENTION.match(keys.SEARCH_CACHE_PREFIX + "g1:abc")
    assert CONVENTION.match(keys.ratelimit("Search Event", "Abc123"))
    assert CONVENTION.match(keys.lock("graph sync"))


def test_ratelimit_and_lock_key_shape():
    assert keys.ratelimit("search_recommend", "172.18.0.5") == "rl:search_recommend:v1:172.18.0.5"
    assert keys.lock("x") == "lock:x:v1"
