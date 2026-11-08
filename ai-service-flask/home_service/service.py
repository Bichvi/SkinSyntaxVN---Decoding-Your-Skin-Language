"""Scoring, de-duplication and caching for POST /api/home/recommend.

Candidate rows come from ``HomeGraphRepository`` (cached raw, per key). Scores are computed
here so tuning never needs a Cypher change. Never raises out of ``recommend``: any failure
returns ``degraded: true`` with no sections. Logs carry no profile, cart or product ids.
"""

from __future__ import annotations

import json
import logging
import math
import time
from dataclasses import dataclass
from typing import Any, Callable

from search_service import neo4j_client

from . import config
from .repository import HomeGraphRepository
from .schemas import HomeRequest

logger = logging.getLogger(__name__)

SKIN_BASE = {"data": 1.0, "inferred": 0.7, "all": 0.4}


@dataclass
class Candidate:
    id: str
    score: float
    sold: float
    brand: str | None
    reason: dict


def _sold_term(sold: Any) -> float:
    return math.log10(1 + max(0.0, float(sold or 0))) / 4


def _sort(cands: list[Candidate]) -> list[Candidate]:
    return sorted(cands, key=lambda c: (-c.score, -c.sold, c.id))


def score_seen(rows_by_seed: list[tuple[str, str, list[dict]]]) -> list[Candidate]:
    acc: dict[str, dict] = {}
    for idx, (seed_id, seed_name, rows) in enumerate(rows_by_seed):
        weight = config.SEEN_WEIGHTS[min(idx, len(config.SEEN_WEIGHTS) - 1)]
        for row in rows:
            pid = str(row["id"])
            shared = list(row.get("shared") or [])
            contrib = weight * (float(row.get("sim") or 0.0) + 0.05 * len(shared))
            entry = acc.get(pid)
            if entry is None:
                entry = acc[pid] = {"score": 0.0, "best": -1.0, "reason": None,
                                    "sold": float(row.get("sold") or 0), "brand": row.get("brand")}
            entry["score"] += contrib
            if contrib > entry["best"]:
                entry["best"] = contrib
                if shared:
                    entry["reason"] = {"code": "seen_shared_ingredient", "seed_id": seed_id,
                                       "seed_name": seed_name, "ingredients": shared[:2]}
                else:
                    entry["reason"] = {"code": "seen_same_category", "seed_id": seed_id,
                                       "seed_name": seed_name}
    return _sort([Candidate(pid, e["score"], e["sold"], e["brand"], e["reason"]) for pid, e in acc.items()])


def score_skin(rows: list[dict], skin_type_name: str) -> list[Candidate]:
    out = []
    for row in rows:
        names = list(row.get("concern_names") or [])
        sold = float(row.get("sold") or 0)
        score = SKIN_BASE.get(row.get("fit"), 0.4) + 0.3 * len(names) + 0.1 * _sold_term(sold)
        out.append(Candidate(str(row["id"]), score, sold, None,
                             {"code": "skin_fit", "skin_type": skin_type_name, "concerns": names[:2]}))
    return _sort(out)


def score_concern(rows: list[dict], concern_name: str) -> list[Candidate]:
    out = []
    for row in rows:
        helps = list(row.get("helps") or [])
        sold = float(row.get("sold") or 0)
        score = (0.5 * (1 if helps else 0) + 0.2 * (1 if row.get("skin_fit") else 0)
                 + 0.3 * _sold_term(sold) + 0.1 * float(row.get("rating") or 0) / 5)
        if helps:
            reason = {"code": "concern_ingredient", "concern": concern_name, "ingredients": helps[:2]}
        else:
            reason = {"code": "concern_target", "concern": concern_name}
        out.append(Candidate(str(row["id"]), score, sold, row.get("brand"), reason))
    return _sort(out)


def pick(cands: list[Candidate], used: set[str], blocked: set[str], limit: int,
         max_per_brand: int | None = None) -> list[Candidate]:
    chosen: list[Candidate] = []
    per_brand: dict[str, int] = {}
    for cand in _sort(cands):
        if len(chosen) >= limit:
            break
        if cand.id in used or cand.id in blocked:
            continue
        if max_per_brand is not None and cand.brand:
            if per_brand.get(cand.brand, 0) >= max_per_brand:
                continue
            per_brand[cand.brand] = per_brand.get(cand.brand, 0) + 1
        chosen.append(cand)
    return chosen


class CandidateCache:
    """Interface for the raw-row cache; the base class is a no-op."""

    def get(self, key: str) -> list[dict] | None:
        return None

    def set(self, key: str, rows: list[dict], ttl: int) -> None:
        return None


class RedisCandidateCache(CandidateCache):
    """Redis-backed cache; every Redis failure degrades to miss / no-op."""

    def get(self, key: str) -> list[dict] | None:
        try:
            from shared.redis_store import get_redis

            client = get_redis()
            if client is None:
                return None
            raw = client.get(key)
            if raw is None:
                return None
            value = json.loads(raw)
            return value if isinstance(value, list) else None
        except Exception:
            return None

    def set(self, key: str, rows: list[dict], ttl: int) -> None:
        if ttl <= 0:
            return
        try:
            from shared.redis_store import get_redis

            client = get_redis()
            if client is not None:
                client.setex(key, ttl, json.dumps(rows, ensure_ascii=False))
        except Exception:
            return


class HomeRecommendService:
    def __init__(self, repo_factory: Callable[[], HomeGraphRepository | None],
                 cache: CandidateCache | None = None) -> None:
        self._repo_factory = repo_factory
        self._cache = cache

    def _cached(self, key: str, loader: Callable[[], list[dict]]) -> list[dict]:
        if self._cache is not None:
            try:
                hit = self._cache.get(key)
            except Exception:
                hit = None
            if hit is not None:
                return hit
        rows = loader()
        if self._cache is not None:
            try:
                self._cache.set(key, rows, config.CACHE_TTL)
            except Exception:
                pass
        return rows

    def _label(self, repo: HomeGraphRepository, label: str, key: str) -> str:
        rows = self._cached(f"home:name:{label}:{key}",
                            lambda: [{"name": repo.label_name(label, key) or ""}])
        name = rows[0].get("name") if rows else ""
        return name or key

    def recommend(self, req: HomeRequest, correlation_id: str = "") -> dict:
        started = time.monotonic()
        degraded = False
        sections: list[dict] = []
        try:
            repo = self._repo_factory()
            if repo is None:
                degraded = True
            else:
                sections = self._compute(repo, req)
        except Exception as exc:
            degraded = True
            sections = []
            try:
                if neo4j_client._is_connectivity_error(exc):
                    neo4j_client.report_failure(exc)
            except Exception:
                pass
        took_ms = int((time.monotonic() - started) * 1000)
        logger.info("home_recommend cid=%s took_ms=%d sections=%s degraded=%s", correlation_id,
                    took_ms, ",".join(s["key"] for s in sections), degraded)
        return {"degraded": degraded, "took_ms": took_ms, "sections": sections}

    def _compute(self, repo: HomeGraphRepository, req: HomeRequest) -> list[dict]:
        scored: dict[str, list[Candidate]] = {}
        meta: dict[str, dict] = {}

        if "seen" in req.sections and req.seen_ids:
            names = repo.seed_names(list(req.seen_ids))
            rows_by_seed: list[tuple[str, str, list[dict]]] = []
            for sid in req.seen_ids:
                name = names.get(sid)
                if not name:
                    continue  # unknown / unnamed seed: skip, keep the valid ones
                rows = self._cached(f"home:cand:seen:{sid}", lambda sid=sid: repo.seen_candidates(sid))
                rows_by_seed.append((sid, name, rows))
            if rows_by_seed:
                scored["seen"] = score_seen(rows_by_seed)
                meta["seen"] = {"seed": {"id": rows_by_seed[0][0], "name": rows_by_seed[0][1]}}

        if "skin" in req.sections and req.skin_type:
            skin_name = self._label(repo, "SkinType", req.skin_type)
            ckey = ",".join(sorted(req.concerns))
            rows = self._cached(f"home:cand:skin:{req.skin_type}:{ckey}",
                                lambda: repo.skin_candidates(req.skin_type, list(req.concerns)))
            scored["skin"] = score_skin(rows, skin_name)
            meta["skin"] = {"skin_type": {"key": req.skin_type, "name": skin_name}}

        if "concern" in req.sections:
            concern_name = self._label(repo, "Concern", req.concern)
            rows = self._cached(f"home:cand:concern:{req.concern}:{req.skin_type or '_'}",
                                lambda: repo.concern_candidates(req.concern, req.skin_type))
            scored["concern"] = score_concern(rows, concern_name)
            meta["concern"] = {"concern": {"key": req.concern, "name": concern_name}}

        all_ids = sorted({c.id for cands in scored.values() for c in cands})
        conflicts: set[str] = set()
        if req.cart_ids and all_ids:
            conflicts = set(repo.conflicting_ids(list(req.cart_ids), all_ids))
        blocked = set(req.cart_ids) | set(req.exclude_ids) | set(req.seen_ids) | conflicts

        used: set[str] = set()
        out: list[dict] = []
        for key in config.SECTION_KEYS:
            if key not in scored:
                continue
            cap = config.MAX_PER_BRAND if key == "seen" else None
            limit = req.limits.get(key, config.DEFAULT_LIMITS[key])
            chosen = pick(scored[key], used, blocked, limit, cap)
            if len(chosen) < config.MIN_ITEMS:
                continue
            used.update(c.id for c in chosen)
            out.append({"key": key, **meta[key],
                        "items": [{"product_id": c.id, "reason": c.reason} for c in chosen]})
        return out


_default: HomeRecommendService | None = None


def _default_repo() -> HomeGraphRepository | None:
    from search_service import config as search_config

    if neo4j_client.is_open():
        return None
    driver = neo4j_client.get_driver()
    if driver is None:
        return None
    return HomeGraphRepository(driver, timeout_s=search_config.SEARCH_TIMEOUT_MS / 1000)


def get_default_service() -> HomeRecommendService:
    global _default
    if _default is None:
        _default = HomeRecommendService(_default_repo, RedisCandidateCache())
    return _default
