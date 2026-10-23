"""Zero-result keyword loop (U06, part B): search log -> reviewable synonym suggestions.

Read-only against Mongo. ``collect_zero_queries`` aggregates committed (non-live) searches whose
``result_count`` was 0, dropping PII-looking queries, test-account traffic and the ``u03verify*``
probes. ``analyze_query`` explains each query with the intent parser and proposes, in order:

1. ``alias``     - the unexplained residual phrase is within ``alias_cutoff`` (rapidfuzz ratio) of an
                   existing category / concern / benefit / skin-type / ingredient surface;
2. ``category``  - the semantic index (Chroma) agrees on one category (>= 3 of the top 4 hits and a good
                   best distance) - an alias for that category is proposed;
3. ``need_review`` - everything else (also when the intent is fully understood but nothing is in stock).

Nothing is applied automatically: a human fills ``approve`` in the CSV and runs
``scripts/apply_synonym_suggestions.py``. The module has no import-time side effects (Mongo, Chroma
and the dictionary are injected), so it is unit-testable without services.
"""

from __future__ import annotations

import csv
import hashlib
import logging
import os
from collections import Counter
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Callable, Iterable, Mapping, Sequence

from rapidfuzz import fuzz, process

from graph_sync.normalize import group_synonyms, normalize_key
from shared.redis_kit.popular import normalize_query

from .metrics import PSEUDO_QUERY_PREFIXES, TEST_QUERY_PREFIXES, fold

logger = logging.getLogger("search_service.zero_results")

EXCLUDED_PREFIXES = TEST_QUERY_PREFIXES + PSEUDO_QUERY_PREFIXES   # shared with metrics.py (U11)
ALIAS_CUTOFF = 88.0
SEMANTIC_MAX_DISTANCE = 1.1
SEMANTIC_MIN_AGREE = 3
MIN_ALIAS_LEN = 4
# entity type in the dictionary -> synonyms.yaml group (ingredients live in ingredient_aliases.yaml)
TYPE_TO_GROUP = {"category": "categories", "concern": "concerns", "benefit": "benefits",
                 "skin_type": "skin_types", "ingredient": "ingredients"}
COLUMNS = ["q_norm", "count", "brands", "categories", "ingredients", "concerns", "residual",
           "suggestion_type", "suggestion", "alias", "target_group", "target_key", "score", "note", "approve"]


@dataclass(frozen=True)
class CollectResult:
    queries: list[tuple[str, int]]
    stats: dict[str, int]


def user_hash(email: str, salt: str) -> str:
    """Same recipe as PHP ``SearchEventTracker::userHash`` (sha256(lower(trim(email)) + salt))."""

    return hashlib.sha256((email.strip().lower() + salt).encode("utf-8")).hexdigest()


def is_excluded_query(q_norm: str) -> bool:
    return fold(q_norm).startswith(EXCLUDED_PREFIXES)


def aggregate_zero_queries(
    docs: Iterable[Mapping[str, Any]],
    *,
    test_user_ids: Iterable[Any] = (),
    test_queries: Iterable[str] = (),
    min_count: int = 2,
) -> CollectResult:
    """Pure: search-log docs -> ``[(q_norm, count)]`` (count desc) plus counters of what was dropped."""

    test_ids = {str(x) for x in test_user_ids}
    blocked = {fold(q) for q in test_queries}
    counts: Counter[str] = Counter()
    stats = {"zero_rows": 0, "live_skipped": 0, "test_account": 0, "test_query": 0, "probe": 0, "pii_or_odd": 0}
    for doc in docs:
        meta = doc.get("metadata") or {}
        count = meta.get("result_count")
        if isinstance(count, bool) or not isinstance(count, (int, float)) or int(count) != 0:
            continue
        if meta.get("live") is True:
            stats["live_skipped"] += 1
            continue
        stats["zero_rows"] += 1
        if doc.get("ma_kh") is not None and str(doc.get("ma_kh")) in test_ids:
            stats["test_account"] += 1
            continue
        raw = str(meta.get("query") or "")
        q_norm = normalize_query(raw)
        if q_norm is None:
            stats["pii_or_odd"] += 1
            continue
        folded = fold(q_norm)
        if is_excluded_query(folded):
            stats["probe"] += 1
            continue
        if folded in blocked:
            stats["test_query"] += 1
            continue
        counts[folded] += 1
    queries = sorted(((q, n) for q, n in counts.items() if n >= min_count), key=lambda kv: (-kv[1], kv[0]))
    stats["distinct_queries"] = len(counts)
    stats["reported"] = len(queries)
    return CollectResult(queries, stats)


def collect_zero_queries(db: Any, days: int, min_count: int = 2, now: datetime | None = None,
                         salt: str | None = None) -> CollectResult:
    """Read the search log (+ test-account markers) from Mongo and aggregate."""

    since = (now or datetime.utcnow()) - timedelta(days=days)
    test_ids: list[Any] = []
    test_queries: list[str] = []
    try:
        test_docs = list(db["khach_hang"].find({"is_test_account": True}, {"_id": 0, "ma_kh": 1, "email": 1}))
        test_ids = [d["ma_kh"] for d in test_docs if d.get("ma_kh") is not None]
        salt = os.getenv("SEARCH_USER_HASH_SALT", "") if salt is None else salt
        hashes = [user_hash(str(d["email"]), salt) for d in test_docs if d.get("email")] if salt else []
        if hashes:
            test_queries = [e["q_norm"] for e in db["search_events"].find(
                {"ts": {"$gte": since}, "user_hash": {"$in": hashes}}, {"_id": 0, "q_norm": 1}) if e.get("q_norm")]
    except Exception as exc:  # noqa: BLE001 - test-account lookup is best effort, report still useful
        logger.warning("test-account lookup failed: %s", type(exc).__name__)
    docs = db["tuong_tac_nguoi_dung"].find(
        {"loai_tuong_tac": "search", "created_at": {"$gte": since}, "metadata.result_count": 0},
        {"_id": 0, "ma_kh": 1, "metadata.query": 1, "metadata.result_count": 1, "metadata.live": 1},
    )
    return aggregate_zero_queries(docs, test_user_ids=test_ids, test_queries=test_queries, min_count=min_count)


# ------------------------------------------------------------------ analysis ----------------------

@dataclass
class SemanticVerdict:
    category_key: str
    category_name: str
    distance: float
    agree: int


SemanticProbe = Callable[[str], "SemanticVerdict | None"]


@dataclass
class _Choices:
    texts: list[str] = field(default_factory=list)
    entities: list[tuple[str, str]] = field(default_factory=list)   # (type, key)


def _alias_choices(snapshot: Any) -> _Choices:
    out = _Choices()
    for surface, (etype, key) in snapshot.surface.items():
        text = " ".join(surface)
        if etype in TYPE_TO_GROUP and len(text) >= MIN_ALIAS_LEN:
            out.texts.append(text)
            out.entities.append((etype, key))
    return out


def _phrases(tokens: Sequence[str]) -> list[str]:
    """Residual phrase candidates, longest first: whole residual, bigrams, single tokens."""

    phrases: list[str] = []
    if len(tokens) > 1:
        phrases.append(" ".join(tokens))
    if len(tokens) > 2:
        phrases += [" ".join(tokens[i:i + 2]) for i in range(len(tokens) - 1)]
    phrases += list(tokens)
    seen: set[str] = set()
    return [p for p in phrases if len(p) >= MIN_ALIAS_LEN and not (p in seen or seen.add(p))]


def best_alias(tokens: Sequence[str], choices: _Choices, cutoff: float = ALIAS_CUTOFF) -> tuple[str, str, str, float] | None:
    """``(phrase, entity_type, key, score)`` for the first residual phrase close to a known surface."""

    if not choices.texts:
        return None
    for phrase in _phrases(tokens):
        hit = process.extractOne(phrase, choices.texts, scorer=fuzz.ratio, score_cutoff=cutoff)
        if hit is not None:
            _, score, idx = hit
            etype, key = choices.entities[idx]
            return phrase, etype, key, round(float(score), 1)
    return None


def analyze_query(
    q_norm: str,
    count: int,
    snapshot: Any,
    parse_fn: Callable[..., Any],
    *,
    choices: _Choices | None = None,
    probe: SemanticProbe | None = None,
    alias_cutoff: float = ALIAS_CUTOFF,
    semantic_max_distance: float = SEMANTIC_MAX_DISTANCE,
) -> dict[str, Any]:
    intent = parse_fn(q_norm, snapshot)
    residual = list(getattr(intent, "residual_terms", []) or [])
    row: dict[str, Any] = {
        "q_norm": q_norm, "count": count,
        "brands": " ".join(intent.brands), "categories": " ".join(intent.categories),
        "ingredients": " ".join(intent.ingredients), "concerns": " ".join(intent.concerns),
        "residual": " ".join(residual),
        "suggestion_type": "need_review", "suggestion": "", "alias": "", "target_group": "", "target_key": "",
        "score": "", "note": "", "approve": "",
    }
    if not residual:
        row["note"] = "intent hiểu đủ (hãng/danh mục) nhưng tìm theo từ khóa gốc vẫn 0 kết quả: kiểm kho hoặc cần áp synonym phía tìm kiếm PHP"
        return row

    choices = choices if choices is not None else _alias_choices(snapshot)
    found = best_alias(residual, choices, alias_cutoff)
    if found:
        phrase, etype, key, score = found
        name = snapshot.display_name(etype, key) if hasattr(snapshot, "display_name") else key
        row.update(suggestion_type="alias", suggestion=f'alias "{phrase}" -> {name}', alias=phrase,
                   target_group=TYPE_TO_GROUP[etype], target_key=key, score=score,
                   note=f"rapidfuzz ratio >= {alias_cutoff:.0f}")
        return row

    verdict = None
    if probe is not None:
        try:
            verdict = probe(q_norm)
        except Exception as exc:  # noqa: BLE001 - semantic index is optional
            row["note"] = f"semantic probe lỗi: {type(exc).__name__}"
    if verdict and verdict.distance <= semantic_max_distance and verdict.agree >= SEMANTIC_MIN_AGREE:
        alias = " ".join(residual)
        row.update(suggestion_type="category", suggestion=f'alias "{alias}" -> {verdict.category_name}',
                   alias=alias, target_group="categories", target_key=verdict.category_key,
                   score=round(verdict.distance, 3),
                   note=f"semantic: {verdict.agree}/4 kết quả đầu cùng danh mục, distance tốt nhất {verdict.distance:.3f} (nhỏ hơn = gần hơn)")
    elif not row["note"]:
        row["note"] = "không có alias gần và semantic không đồng thuận - cần người xem"
    return row


def analyze_all(queries: Iterable[tuple[str, int]], snapshot: Any, parse_fn: Callable[..., Any],
                probe: SemanticProbe | None = None, **kwargs: Any) -> list[dict[str, Any]]:
    choices = _alias_choices(snapshot)
    return [analyze_query(q, n, snapshot, parse_fn, choices=choices, probe=probe, **kwargs) for q, n in queries]


def category_probe_from_vectorstore(store: Any, snapshot: Any, k: int = 4) -> SemanticProbe:
    """Probe over a LangChain-Chroma-like store (``similarity_search_with_score`` -> lower distance = closer)."""

    category_keys = {key for (etype, key) in snapshot.surface.values() if etype == "category"}
    syn = group_synonyms("categories")

    def category_of(metadata: Mapping[str, Any]) -> str | None:
        name = str(metadata.get("category") or "").split("->")[-1].strip()
        key = normalize_key(name, syn) if name else ""
        return key if key in category_keys else None

    def probe(q: str) -> SemanticVerdict | None:
        hits = store.similarity_search_with_score(q, k=k)
        if not hits:
            return None
        votes: Counter[str] = Counter()
        for doc, _ in hits:
            key = category_of(getattr(doc, "metadata", {}) or {})
            if key:
                votes[key] += 1
        if not votes:
            return None
        key, agree = votes.most_common(1)[0]
        best = min(float(score) for _, score in hits)
        name = snapshot.display_name("category", key) if hasattr(snapshot, "display_name") else key
        return SemanticVerdict(key, name, best, agree)

    return probe


def write_report(path: Path, rows: Sequence[Mapping[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", newline="", encoding="utf-8-sig") as fh:      # BOM: Excel opens UTF-8 correctly
        writer = csv.DictWriter(fh, fieldnames=COLUMNS, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)
