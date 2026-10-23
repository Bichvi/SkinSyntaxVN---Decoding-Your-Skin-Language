import json
import threading
import time

import pytest

from search_service.service import RedisJsonCache
from shared.redis_kit import keys, pubsub


def test_publish_invalidate_bumps_generation_and_broadcasts(r):
    ps = r.pubsub(ignore_subscribe_messages=True)
    ps.subscribe(keys.CHANNEL_INVALIDATE)
    ps.get_message(timeout=0.1)
    assert pubsub.current_generation(r) == 0
    assert pubsub.publish_invalidate("catalog", ["1496", 18], client=r) is True
    assert pubsub.current_generation(r) == 1
    msg = ps.get_message(timeout=1.0)
    payload = json.loads(msg["data"])
    assert payload["scope"] == "catalog" and payload["ids"] == ["1496", "18"] and payload["gen"] == 1


def test_cache_entry_is_not_served_after_invalidate(r):
    cache = RedisJsonCache(redis_factory=lambda: r, ttl=300)
    key = keys.SEARCH_CACHE_PREFIX + "abc"
    cache.set(key, {"v": 1})
    assert cache.get(key) == {"v": 1}
    pubsub.publish_invalidate("search", client=r)
    assert cache.get(key) is None            # old generation orphaned
    cache.set(key, {"v": 2})
    assert cache.get(key) == {"v": 2}
    assert any(k.startswith(keys.SEARCH_CACHE_PREFIX + "g1:") for k in r.keys("*"))


def test_generation_key_format():
    assert keys.search_cache_key_with_gen("search:rec:v3:abc", 4) == "search:rec:v3:g4:abc"


def test_publish_invalidate_false_when_redis_down():
    class Down:
        def incr(self, *a):
            raise ConnectionError("down")

        def get(self, *a):
            raise ConnectionError("down")

    assert pubsub.publish_invalidate("catalog", client=Down()) is False
    assert pubsub.current_generation(Down()) == 0


def test_handlers_receive_message_and_errors_are_contained():
    seen = []
    pubsub._handlers.clear()
    pubsub.register_handler(lambda m: (_ for _ in ()).throw(RuntimeError("bad handler")))
    pubsub.register_handler(seen.append)
    pubsub._dispatch({"scope": "catalog", "ids": ["1"]})
    assert seen == [{"scope": "catalog", "ids": ["1"]}]
    pubsub._handlers.clear()


@pytest.mark.integration
def test_listener_thread_receives_message(real_redis):
    kw = real_redis.connection_pool.connection_kwargs
    redis_url = f"redis://{kw.get('host')}:{kw.get('port')}/{kw.get('db')}"
    received = threading.Event()
    pubsub._handlers.clear()
    pubsub.register_handler(lambda m: received.set() if m.get("scope") == "u03-it" else None)
    stop = threading.Event()
    t = threading.Thread(target=pubsub._listen_forever, args=(redis_url, stop), daemon=True)
    t.start()
    time.sleep(0.5)
    real_redis.publish(keys.CHANNEL_INVALIDATE, json.dumps({"scope": "u03-it", "ids": []}))
    assert received.wait(3.0)
    stop.set()
    pubsub._handlers.clear()
