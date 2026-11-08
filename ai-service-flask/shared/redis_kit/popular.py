"""Popular search queries for PHP autocomplete (U04): record, seed and daily maintenance.

Data model (mirrors ``backend/app/services/SearchPopularity.php`` - keep both in sync):

* ``search:popular:v1`` ZSET - member ``q_norm`` (lower-case, accents kept, single spaces, 2..60 chars),
  score = number of searches that returned results.
* ``search:prefix:v1``  ZSET (all scores 0) - member ``fold(q_norm) + "\x1f" + q_norm`` so PHP can run
  ``ZRANGEBYLEX`` on an accent-free prefix.

PII rules (phone numbers, emails, URLs, odd characters, > 60 chars) are applied by ``normalize_query``.
``maintain()`` is the daily job (scheduled by U11): decays every score (x0.9), drops entries below
``min_score``, keeps the top ``keep`` and removes orphaned prefix members - one atomic Lua script.
"""

from __future__ import annotations

import logging
import re
from typing import Any, Iterable, Mapping

from graph_sync.normalize import strip_accents

from . import client as _client
from . import keys

logger = logging.getLogger("redis_kit.popular")

SEP = "\x1f"
MIN_LEN = 2
MAX_LEN = 60
KEEP_TOP = 5000
DECAY_FACTOR = 0.9
MIN_SCORE = 0.5
SEED_PREFIX_BLOCKLIST = ("u03verify",)  # test records written while verifying U03 - never seed them

_SPACES = re.compile(r"\s+")
_ALLOWED = re.compile(r"^[\w \-'+&.%/()]+$", re.UNICODE)
_EMAIL_URL = re.compile(r"@|https?:|www\.|\.(?:com|vn|net|org)\b", re.IGNORECASE)
_LONG_DIGITS = re.compile(r"(?:\d[\s.\-]?){7,}")
_PHONE = re.compile(r"(?:\+?84|0)[\s.\-]?\d{2,3}[\s.\-]?\d{3}[\s.\-]?\d{3,4}")

_MAINTAIN_LUA = """
local popular, prefix = KEYS[1], KEYS[2]
local factor, keep, min_score = tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3])
local items = redis.call('ZRANGE', popular, 0, -1, 'WITHSCORES')
local removed = 0
for i = 1, #items, 2 do
  local score = tonumber(items[i + 1]) * factor
  if score < min_score then
    redis.call('ZREM', popular, items[i])
    removed = removed + 1
  else
    redis.call('ZADD', popular, score, items[i])
  end
end
local card = redis.call('ZCARD', popular)
if card > keep then
  redis.call('ZREMRANGEBYRANK', popular, 0, card - keep - 1)
  removed = removed + (card - keep)
end
local orphans = 0
for _, member in ipairs(redis.call('ZRANGE', prefix, 0, -1)) do
  local p = string.find(member, string.char(31), 1, true)
  local q = p and string.sub(member, p + 1) or member
  if not redis.call('ZSCORE', popular, q) then
    redis.call('ZREM', prefix, member)
    orphans = orphans + 1
  end
end
return {redis.call('ZCARD', popular), redis.call('ZCARD', prefix), removed, orphans}
"""


def fold(text: Any) -> str:
    """Lower-case, accent-free, single-spaced (same output as PHP ``SearchSkinProfile::fold``)."""

    return _SPACES.sub(" ", strip_accents(str(text or "")).lower()).strip()


def looks_like_pii(text: str) -> bool:
    return bool(_EMAIL_URL.search(text) or _LONG_DIGITS.search(text) or _PHONE.search(text))


def normalize_query(raw: Any) -> str | None:
    """``q_norm`` or ``None`` when the query must never enter the popular list."""

    text = _SPACES.sub(" ", str(raw or "")).strip().lower()
    if not (MIN_LEN <= len(text) <= MAX_LEN):
        return None
    if looks_like_pii(text) or not _ALLOWED.match(text) or "_" in text:
        return None
    return text


def prefix_member(q_norm: str) -> str:
    return f"{fold(q_norm)}{SEP}{q_norm}"


def record(raw_query: Any, count: int = 1, *, client: Any | None = None) -> bool:
    """Add ``count`` successful searches for ``raw_query``. False when filtered out or Redis is down."""

    q_norm = normalize_query(raw_query)
    redis_client = client if client is not None else _client.get_redis()
    if q_norm is None or redis_client is None or count <= 0:
        return False
    try:
        pipe = redis_client.pipeline()
        pipe.zincrby(keys.SEARCH_POPULAR, count, q_norm)
        pipe.zadd(keys.SEARCH_PREFIX, {prefix_member(q_norm): 0})
        pipe.execute()
        return True
    except Exception as exc:
        _client.mark_down_if(client is None, exc)
        logger.warning("popular.record failed: %s", type(exc).__name__)
        return False


def seed(counts: Mapping[str, int] | Iterable[tuple[str, int]], *, client: Any | None = None) -> dict[str, int]:
    """Aggregate raw ``(query, count)`` pairs (PII/blocklist filtered) and ``record`` them.

    Returns ``{"seeded": n, "skipped": n}``. Used once to bootstrap from the historical search log.
    """

    pairs = counts.items() if isinstance(counts, Mapping) else counts
    merged: dict[str, int] = {}
    skipped = 0
    for raw, count in pairs:
        q_norm = normalize_query(raw)
        if q_norm is None or fold(q_norm).startswith(SEED_PREFIX_BLOCKLIST) or int(count) <= 0:
            skipped += 1
            continue
        merged[q_norm] = merged.get(q_norm, 0) + int(count)
    seeded = sum(1 for q_norm, count in merged.items() if record(q_norm, count, client=client))
    return {"seeded": seeded, "skipped": skipped + (len(merged) - seeded)}


def maintain(*, client: Any | None = None, factor: float = DECAY_FACTOR, keep: int = KEEP_TOP,
             min_score: float = MIN_SCORE) -> dict[str, int] | None:
    """Daily job: decay x``factor``, drop scores < ``min_score``, keep top ``keep``, prune orphan prefix members."""

    redis_client = client if client is not None else _client.get_redis()
    if redis_client is None:
        return None
    try:
        popular, prefix, removed, orphans = redis_client.eval(
            _MAINTAIN_LUA, 2, keys.SEARCH_POPULAR, keys.SEARCH_PREFIX, factor, int(keep), min_score
        )
    except Exception as exc:
        _client.mark_down_if(client is None, exc)
        logger.warning("popular.maintain failed: %s", type(exc).__name__)
        return None
    return {"popular": int(popular), "prefix": int(prefix), "removed": int(removed), "orphans": int(orphans)}
