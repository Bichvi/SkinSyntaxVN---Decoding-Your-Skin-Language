"""U04: popular-queries record/seed/maintenance (fakeredis + Lua)."""

import pytest

from shared.redis_kit import keys, popular


def test_fold_and_prefix_member():
    assert popular.fold("Sữa  Rửa Mặt Đỏ") == "sua rua mat do"
    assert popular.prefix_member("sữa rửa mặt") == "sua rua mat\x1fsữa rửa mặt"


@pytest.mark.parametrize("raw", [
    "0912345678", "+84 912 345 678", "0912 345 678", "090.123.4567", "84912345678",
    "a@b.com", "me@example", "http://x.vn", "www.shop", "shop.vn",
    "<img src=x onerror=alert(1)>", 'say "hi"', "back`tick", "a" * 61, "x", "", None, "snake_case",
    "1234 5678",  # >= 7 digits spaced
])
def test_normalize_rejects_pii_and_junk(raw):
    assert popular.normalize_query(raw) is None


@pytest.mark.parametrize("raw,expected", [
    ("  Sữa   Rửa  Mặt ", "sữa rửa mặt"),
    ("SPF 50+", "spf 50+"),
    ("la roche-posay", "la roche-posay"),
    ("1496", "1496"),
    ("serum b5 (30ml)", "serum b5 (30ml)"),
])
def test_normalize_accepts(raw, expected):
    assert popular.normalize_query(raw) == expected


def test_record_increments_and_indexes_prefix(r):
    assert popular.record("Sữa rửa mặt", client=r)
    assert popular.record("sữa  rửa mặt", client=r)
    assert r.zscore(keys.SEARCH_POPULAR, "sữa rửa mặt") == 2
    assert r.zrangebylex(keys.SEARCH_PREFIX, "[sua rua", "[sua rua\xff") == ["sua rua mat\x1fsữa rửa mặt"]
    assert popular.record("0912345678", client=r) is False
    assert r.zcard(keys.SEARCH_POPULAR) == 1


def test_record_without_redis_is_soft_failure(monkeypatch):
    monkeypatch.setattr(popular._client, "get_redis", lambda: None)
    assert popular.record("serum") is False
    assert popular.maintain() is None


def test_seed_filters_blocklist_pii_and_merges(r):
    out = popular.seed({"Serum": 3, "serum": 2, "u03verify abc": 9, "0912345678": 5, "kem chống nắng": 4}, client=r)
    assert out["seeded"] == 2 and out["skipped"] == 2
    assert r.zscore(keys.SEARCH_POPULAR, "serum") == 5
    assert r.zscore(keys.SEARCH_POPULAR, "kem chống nắng") == 4
    assert r.zscore(keys.SEARCH_POPULAR, "u03verify abc") is None


def test_maintain_decays_drops_trims_and_prunes_orphans(r):
    for q, n in {"serum": 10, "toner": 1, "kem duong": 3, "mat na": 2}.items():
        popular.record(q, n, client=r)
    r.zadd(keys.SEARCH_PREFIX, {"orphan\x1forphan": 0})  # prefix without popular entry
    out = popular.maintain(client=r, factor=0.9, keep=3, min_score=0.95)
    # toner: 1*0.9=0.9 < 0.95 dropped; remaining serum 9, kem duong 2.7, mat na 1.8 (3 <= keep)
    assert r.zscore(keys.SEARCH_POPULAR, "serum") == pytest.approx(9.0)
    assert r.zscore(keys.SEARCH_POPULAR, "toner") is None
    assert r.zscore(keys.SEARCH_POPULAR, "kem duong") == pytest.approx(2.7)
    assert out["popular"] == 3 and out["orphans"] == 2  # toner's and the fake orphan
    members = r.zrange(keys.SEARCH_PREFIX, 0, -1)
    assert sorted(m.split("\x1f")[1] for m in members) == ["kem duong", "mat na", "serum"]


def test_maintain_keeps_only_top_n(r):
    for i in range(10):
        popular.record(f"san pham {i}", i + 5, client=r)
    out = popular.maintain(client=r, factor=1.0, keep=4, min_score=0.1)
    assert out["popular"] == 4 and out["prefix"] == 4
    assert set(r.zrange(keys.SEARCH_POPULAR, 0, -1)) == {f"san pham {i}" for i in (6, 7, 8, 9)}
