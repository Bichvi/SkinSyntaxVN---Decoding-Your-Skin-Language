"""Redis key / channel names. Convention: ``<domain>:<purpose>:v<n>[:<id>]`` (see docs/redis/conventions.md).

Never spell key strings elsewhere in Python code - add a function here. PHP mirrors the same names
(``RedisLite``/``RateLimiter``/``SearchEventTracker``); ``docs/redis/conventions.md`` is the contract.
"""

from __future__ import annotations

# -- search --
SEARCH_CACHE_PREFIX = "search:rec:v3:"          # + g<gen>:<sha1>   STRING json, TTL SEARCH_CACHE_TTL
SEARCH_CACHE_GEN = "search:cachegen:v1"         # STRING int, long-lived counter (bumped by invalidate)
SEARCH_INTENT_PREFIX = "search:intent:v1:"      # + g<gen>:<sha1>   STRING json, TTL 600s (U05 corrections)
SEARCH_TRENDING = "search:trending:v1"          # STRING json, TTL
SEARCH_EVENTS_STREAM = "search:events:v1"       # STREAM, MAXLEN ~ 100000, group "flusher"
SEARCH_EVENTS_DEAD = SEARCH_EVENTS_STREAM + ":dead"
SEARCH_POPULAR = "search:popular:v1"            # ZSET (U04): member q_norm, score = successful searches; pruned by popular.maintain
SEARCH_PREFIX = "search:prefix:v1"              # ZSET score 0 (lex index, U04): member fold(q) + "" + q_norm
SEARCH_SUGGEST_PREFIX = "search:suggest:v1:"    # + g<gen>:<sha1>   STRING json, TTL 60s (PHP autocomplete cache, U04)
SEARCH_BREAKER = "search:recommend:breaker:v1"  # STRING json, TTL (PHP)
SEARCH_CTR_PREFIX = "search:ctr:v1:"            # + <section>  HASH product_id -> "<ctr>|<ctr_norm>|<impressions>", TTL 2 days (U12 job ctr_boost)
SEARCH_CTR_META = "search:ctr:v1:_meta"         # HASH section -> json {alpha, beta, products, eligible, ...}, TTL 2 days

# -- product detail --
PRODUCT_SIMILAR_PREFIX = "product:similar:v1:"  # + g<gen>:<id>:<skin|any>  STRING json, TTL SEARCH_SIMILAR_CACHE_TTL (U08)

# -- cart --
CART_ANALYZE_PREFIX = "cart:analyze:v1:"         # + g<gen>:<sha1(ids|skin)>  STRING json, TTL SEARCH_CART_CACHE_TTL (U09)

# -- catalog --
CATALOG_CHANGES_STREAM = "catalog:changes:v1"   # STREAM (U11)
CATALOG_CHANGES_DEAD = CATALOG_CHANGES_STREAM + ":dead"

# -- ops (U11) --
OPS_GRAPH_SYNC_SINCE = "ops:graph_sync:last_since"    # STRING ISO8601 UTC, mốc của job đồng bộ tăng dần (không TTL: cấu trúc dài hạn)
OPS_WORKER_STARTED = "ops:worker:started_at"          # STRING epoch s (lần đầu worker chạy; TTL 30 ngày, làm mới mỗi khi khởi động)
OPS_JOB_TTL_S = 30 * 86400


def ops_job_last(name: str) -> str:
    """``ops:job:<name>:last`` - STRING json {name, started_at, finished_at, duration_s, status, ...}, TTL 30 ngày."""

    return f"ops:job:{_part(name)}:last"


def ops_job_fails(name: str) -> str:
    """``ops:job:<name>:fails`` - STRING int, số lần thất bại LIÊN TIẾP, TTL 30 ngày."""

    return f"ops:job:{_part(name)}:fails"


def ops_filehash(name: str) -> str:
    """``ops:filehash:<name>`` - STRING sha256 của file cấu hình đã xử lý lần cuối (conflict_rules.yaml...), TTL 30 ngày."""

    return f"ops:filehash:{_part(name)}"


# -- pub/sub --
CHANNEL_INVALIDATE = "cache:invalidate"

# -- locks --
LEGACY_GRAPH_SYNC_LOCK = "graph_sync:lock"      # kept verbatim for compatibility with running jobs


def search_cache_key_with_gen(key: str, gen: int) -> str:
    """``search:rec:v3:<digest>`` -> ``search:rec:v3:g<gen>:<digest>`` (a new gen orphans all old entries)."""

    for prefix in (SEARCH_CACHE_PREFIX, SEARCH_INTENT_PREFIX):
        if key.startswith(prefix):
            return f"{prefix}g{int(gen)}:{key[len(prefix):]}"
    return f"{key}:g{int(gen)}"


def product_similar_key(product_id: str, skin_type: str | None, gen: int) -> str:
    """``product:similar:v1:g<gen>:<id>:<skin|any>`` - ranked candidates of one product (exclude/limit applied later)."""

    return f"{PRODUCT_SIMILAR_PREFIX}g{int(gen)}:{_part(product_id)}:{_part(skin_type) if skin_type else 'any'}"


def cart_analyze_key(digest: str, gen: int) -> str:
    """``cart:analyze:v1:g<gen>:<sha1>`` - analysis of one set of product ids + skin type."""

    return f"{CART_ANALYZE_PREFIX}g{int(gen)}:{_part(digest)}"


def search_ctr(section: str) -> str:
    """``search:ctr:v1:<section>`` - HASH of smoothed click-through rates for one result section (U12)."""

    return f"{SEARCH_CTR_PREFIX}{_part(section)}"


def ratelimit(scope: str, ident: str) -> str:
    """``rl:<scope>:v1:<ident>`` - ZSET (sliding window, Python) / counter (PHP fixed window)."""

    return f"rl:{_part(scope)}:v1:{_part(ident)}"


def lock(name: str) -> str:
    """``lock:<name>:v1`` (new locks). ``graph_sync`` keeps ``LEGACY_GRAPH_SYNC_LOCK``."""

    return f"lock:{_part(name)}:v1"


def dead_stream(stream: str) -> str:
    return stream + ":dead"


def _part(value: str) -> str:
    text = str(value).strip().lower().replace(" ", "_")
    if not text:
        raise ValueError("empty key part")
    return text
