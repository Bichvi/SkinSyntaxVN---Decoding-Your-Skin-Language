"""U09: ``POST /api/cart/analyze`` - ingredient conflicts between cart products + missing routine steps.

* Conflicts: Neo4j ``CONFLICTS_WITH`` edges (``graph_sync --conflicts-only`` from ``data/graph/conflict_rules.yaml``).
  Neo4j down -> the same rules evaluated in Python on ingredient text read from Mongo, and as a last resort the
  older ``recommendation_service.knowledge`` rules; ``degraded=true``.
* Routine: category chains (catalog snapshot, graph as backup) against ``data/graph/routine_steps.yaml``.
* Suggestions (graph only): best products of up to 3 missing steps that do not conflict with the cart.

All logic is in pure helpers; ``CartAnalyzeService`` wires graph / catalog / cache / text loader by injection.
Cache: Redis ``cart:analyze:v1:g<gen>:<sha1(sorted ids | skin type)>``, 10 minutes, healthy answers only.
"""

from __future__ import annotations

import hashlib
import json
import logging
import re
import time
from dataclasses import dataclass
from typing import Any, Callable, Iterable, Mapping, Sequence

from graph_sync.conflicts import (SEVERITIES, RuleSet, evaluate_pair, expand_pairs, load_rule_set,
                                  lower_severity)
from shared.redis_kit import keys, pubsub

from . import config, ranker
from .routine import RoutineDef, analyze as analyze_routine, load_routine

logger = logging.getLogger("search_service")

_ID_RE = re.compile(r"^[A-Za-z0-9_-]{1,32}$")
_SKIN_RE = re.compile(r"^[a-z0-9_]{1,32}$")
MAX_INGREDIENT_NAMES = 4
LEGACY_SEVERITY = {"aha": "high", "bha": "high", "benzoyl peroxide": "medium"}


class CartValidationError(ValueError):
    def __init__(self, field_name: str) -> None:
        super().__init__(field_name)
        self.field = field_name


@dataclass(frozen=True)
class CartRequest:
    product_ids: tuple[str, ...]
    skin_type: str | None = None


def parse_request(payload: Any) -> CartRequest:
    """Validate a decoded JSON body. Raises ``CartValidationError(field)``."""

    if not isinstance(payload, dict):
        raise CartValidationError("body")
    raw = payload.get("product_ids")
    if not isinstance(raw, list) or len(raw) > config.CART_MAX_IDS:
        raise CartValidationError("product_ids")
    ids: list[str] = []
    for value in raw:
        if isinstance(value, bool) or not isinstance(value, (str, int)):
            raise CartValidationError("product_ids")
        text = str(value).strip()
        if not _ID_RE.match(text):
            raise CartValidationError("product_ids")
        if text not in ids:
            ids.append(text)
    skin = None
    profile = payload.get("skin_profile")
    if profile is not None:
        if not isinstance(profile, dict):
            raise CartValidationError("skin_profile")
        value = profile.get("skin_type")
        if value is not None:
            if not isinstance(value, str) or not _SKIN_RE.match(value.strip().lower()):
                raise CartValidationError("skin_profile")
            skin = value.strip().lower()
    return CartRequest(tuple(ids), skin)


def cache_digest(req: CartRequest) -> str:
    return hashlib.sha1(("|".join(sorted(req.product_ids)) + "#" + (req.skin_type or "")).encode("utf-8")).hexdigest()


# ---------------------------------------------------------------- conflicts (pure)


def adjust_severity(severity: str, chain_a: Iterable[str], chain_b: Iterable[str], rule_set: RuleSet) -> str | None:
    """Wash-off products touch the skin briefly: if either product is wash-off the conflict is one level lower
    (``None`` = dropped). Heuristic documented in conflict_rules.yaml."""

    if (set(chain_a) | set(chain_b)) & rule_set.rinse_off_categories:
        return lower_severity(severity)
    return severity


def _ignored(chain: Iterable[str], rule_set: RuleSet) -> bool:
    return bool(set(chain) & rule_set.ignore_categories)


def _conflict_entry(id_a: str, id_b: str, severity: str, timing: str, names: Sequence[str], rule_id: str,
                    advice: str, evidence: str) -> dict[str, Any]:
    return {"a": id_a, "b": id_b, "severity": severity, "timing": timing,
            "ingredients": [n for n in names if n][:MAX_INGREDIENT_NAMES], "rule_id": rule_id,
            "advice_code": rule_id, "advice_vi": advice, "evidence": evidence}


def conflicts_from_rows(rows: Iterable[Mapping[str, Any]], categories: Mapping[str, Sequence[str]],
                        rule_set: RuleSet) -> list[dict[str, Any]]:
    """Group ``CART_CONFLICTS`` rows per product pair, keep the highest severity, most severe first."""

    best: dict[tuple[str, str], dict[str, Any]] = {}
    names: dict[tuple[str, str], list[str]] = {}
    for row in rows:
        id1, id2 = str(row["id1"]), str(row["id2"])
        chain1, chain2 = categories.get(id1, ()), categories.get(id2, ())
        if _ignored(chain1, rule_set) or _ignored(chain2, rule_set):
            continue
        k1, k2 = str(row.get("key1")), str(row.get("key2"))
        if row.get("rank1") is not None and int(row["rank1"]) > rule_set.rank_limit(k1):
            continue
        if row.get("rank2") is not None and int(row["rank2"]) > rule_set.rank_limit(k2):
            continue
        severity = str(row.get("severity") or "")
        if severity not in SEVERITIES:
            severity = "low"            # edges written by an older ETL ("same_session")
        severity = adjust_severity(severity, chain1, chain2, rule_set) or ""
        if not severity:
            continue
        pair = (id1, id2)
        current = best.get(pair)
        entry = _conflict_entry(id1, id2, severity, str(row.get("timing") or "same_session"),
                                [str(row.get("name1") or k1), str(row.get("name2") or k2)],
                                str(row.get("rule_id") or ""), str(row.get("advice_vi") or ""),
                                str(row.get("evidence") or ""))
        if current is None or SEVERITIES.index(severity) > SEVERITIES.index(current["severity"]):
            best[pair] = entry
        names.setdefault(pair, [])
        for n in entry["ingredients"]:
            if n not in names[pair]:
                names[pair].append(n)
    out = []
    for pair, entry in best.items():
        entry["ingredients"] = (entry["ingredients"] + [n for n in names[pair] if n not in entry["ingredients"]])[:MAX_INGREDIENT_NAMES]
        out.append(entry)
    out.sort(key=lambda e: (-SEVERITIES.index(e["severity"]), e["a"], e["b"]))
    return out


def conflicts_from_ingredients(ingredients: Mapping[str, Sequence[tuple[str, int | None]]],
                               categories: Mapping[str, Sequence[str]], rule_set: RuleSet,
                               display: Mapping[str, str] | None = None) -> list[dict[str, Any]]:
    """Python twin of the Cypher path: ``{product id: [(ingredient key, rank)]}`` -> conflicts."""

    display = display or {}
    table = {(p.a, p.b): p for p in expand_pairs(rule_set)}
    ids = sorted(ingredients)
    rows: list[dict[str, Any]] = []
    for i, id1 in enumerate(ids):
        for id2 in ids[i + 1:]:
            for pair, ka, kb in evaluate_pair(ingredients[id1], ingredients[id2], rule_set, table):
                rows.append({"id1": id1, "id2": id2, "key1": ka, "key2": kb, "name1": display.get(ka, ka),
                             "name2": display.get(kb, kb), "rank1": None, "rank2": None,
                             "severity": pair.severity, "timing": pair.timing, "rule_id": pair.rule_id,
                             "advice_vi": pair.advice_vi, "evidence": pair.evidence})
    return conflicts_from_rows(rows, categories, rule_set)


def conflicts_from_legacy_text(texts: Mapping[str, str], categories: Mapping[str, Sequence[str]],
                               rule_set: RuleSet) -> list[dict[str, Any]]:
    """Last resort when the ingredient parser is unavailable: the project's older text rules
    (``recommendation_service.knowledge.ingredient_rules``, loaded by path), applied across product pairs."""

    from graph_sync.rules import _load_conflict_source

    module = _load_conflict_source()
    detected = {pid: module.ingredient_aliases(text) for pid, text in texts.items()}
    ids = sorted(detected)
    out: dict[tuple[str, str], dict[str, Any]] = {}
    for i, id1 in enumerate(ids):
        for id2 in ids[i + 1:]:
            if _ignored(categories.get(id1, ()), rule_set) or _ignored(categories.get(id2, ()), rule_set):
                continue
            for rule in module.CONFLICT_RULES:
                cross = ((rule.first in detected[id1] and rule.second in detected[id2])
                         or (rule.second in detected[id1] and rule.first in detected[id2]))
                if not cross:
                    continue
                severity = adjust_severity(LEGACY_SEVERITY.get(rule.second, "medium"), categories.get(id1, ()),
                                           categories.get(id2, ()), rule_set)
                if severity is None:
                    continue
                current = out.get((id1, id2))
                if current is None or SEVERITIES.index(severity) > SEVERITIES.index(current["severity"]):
                    out[(id1, id2)] = _conflict_entry(
                        id1, id2, severity, "same_session", [rule.first, rule.second],
                        f"legacy_{rule.first}_{rule.second}".replace(" ", "_"), str(rule.resolution), "moderate")
    return sorted(out.values(), key=lambda e: (-SEVERITIES.index(e["severity"]), e["a"], e["b"]))


# ---------------------------------------------------------------- suggestions (pure)


def candidate_conflicts_with_cart(candidate_ings: Sequence[Mapping[str, Any]], candidate_chain: Sequence[str],
                                  cart: Mapping[str, Mapping[str, Any]], categories: Mapping[str, Sequence[str]],
                                  rule_set: RuleSet, table: Mapping[tuple[str, str], Any]) -> bool:
    """True when the candidate would conflict (after the same filters as the cart warnings) with any cart product."""

    mine = [(str(i["key"]), i.get("rank")) for i in candidate_ings]
    if _ignored(candidate_chain, rule_set):
        return False
    for pid, data in cart.items():
        theirs = [(str(i["key"]), i.get("rank")) for i in data.get("conflict_ings", [])]
        for pair, _, _ in evaluate_pair(mine, theirs, rule_set, table):
            if _ignored(categories.get(pid, ()), rule_set):
                continue
            if adjust_severity(pair.severity, candidate_chain, categories.get(pid, ()), rule_set):
                return True
    return False


def rank_step_candidates(rows: Iterable[Mapping[str, Any]], cart_benefits: set[str],
                         cart_data: Mapping[str, Mapping[str, Any]], categories: Mapping[str, Sequence[str]],
                         step_categories: Sequence[str], step_label: str, skin_type: str | None,
                         rule_set: RuleSet, co_purchase: Mapping[str, float] | None = None,
                         limit: int = config.CART_ITEMS_PER_STEP) -> list[dict[str, Any]]:
    """Drop conflicting candidates, score the rest, cap brands. ``score = .5*benefit overlap + .3*sold + .2*skin fit
    (+ bought-together)``; reason ``fits_skin_type`` when the skin type matches, else ``completes_routine``."""

    table = {(p.a, p.b): p for p in expand_pairs(rule_set)}
    kept = [r for r in rows if not candidate_conflicts_with_cart(
        r.get("conflict_ings") or [], step_categories, cart_data, categories, rule_set, table)]
    popularity = ranker.popularity_scores({str(r["id"]): r.get("sold") or 0 for r in kept}, [str(r["id"]) for r in kept])
    cop = ranker.bought_together_scores(co_purchase or {})
    scored: list[dict[str, Any]] = []
    for r in kept:
        pid = str(r["id"])
        benefits = set(r.get("benefits") or [])
        union = benefits | cart_benefits
        overlap = len(benefits & cart_benefits) / len(union) if union else 0.0
        skin_fit = bool(r.get("skin_fit"))
        score = (config.W_CART_BENEFIT * overlap + config.W_CART_SOLD * popularity.get(pid, 0.0)
                 + config.W_CART_SKIN * (1.0 if skin_fit else 0.0) + config.W_COP * cop.get(pid, 0.0))
        if skin_fit and skin_type:
            code, params = "fits_skin_type", [skin_type]
        else:
            code, params = "completes_routine", [step_label]
        scored.append({"product_id": pid, "score": round(score, 4), "reason_code": code, "reason_params": params,
                       "brand": str(r.get("brand") or ""), "sold": int(r.get("sold") or 0)})
    scored.sort(key=lambda x: (-x["score"], -x["sold"], x["product_id"]))
    from .similar import diversify

    picked = diversify(scored, cap=2, min_keep=min(limit, 4))[:limit]
    return [{k: v for k, v in item.items() if k in ("product_id", "score", "reason_code", "reason_params")} for item in picked]


# ---------------------------------------------------------------- IO helpers


class RedisCartCache:
    """Redis-only; any failure means "no cache". Keyed by the shared cache generation."""

    def __init__(self, redis_factory: Callable[[], Any] | None = None) -> None:
        self.redis_factory = redis_factory

    def _client(self) -> Any | None:
        if self.redis_factory is not None:
            return self.redis_factory()
        from shared.redis_kit import client as redis_client

        return redis_client.get_redis()

    def get(self, digest: str) -> dict | None:
        if config.CART_CACHE_TTL <= 0:
            return None
        try:
            client = self._client()
            if client is None:
                return None
            raw = client.get(keys.cart_analyze_key(digest, pubsub.current_generation(client)))
            return json.loads(raw) if raw else None
        except Exception:
            return None

    def set(self, digest: str, value: dict, ttl: int) -> None:
        if config.CART_CACHE_TTL <= 0 or ttl <= 0:
            return
        try:
            client = self._client()
            if client is not None:
                client.setex(keys.cart_analyze_key(digest, pubsub.current_generation(client)), ttl,
                             json.dumps(value, ensure_ascii=False))
        except Exception:
            pass


def mongo_text_loader(ids: Sequence[str]) -> dict[str, dict[str, str]]:
    """Read-only: ingredient text of a few products (fallback when Neo4j is down). ``{id: {full, sach}}``."""

    from .catalog import get_mongo_db

    db = get_mongo_db()
    if db is None or not ids:
        return {}
    variants: list[Any] = []
    for pid in ids:
        variants.append(pid)
        if pid.isdigit():
            variants.append(int(pid))
    out: dict[str, dict[str, str]] = {}
    for doc in db["san_pham"].find({"ma_san_pham": {"$in": variants}},
                                   {"_id": 0, "ma_san_pham": 1, "thanh_phan_full": 1, "thanh_phan_sach": 1}):
        out[str(doc.get("ma_san_pham"))] = {"full": doc.get("thanh_phan_full") or "", "sach": doc.get("thanh_phan_sach") or ""}
    return out


class CartAnalyzeService:
    def __init__(self, graph: Any | None, catalog: Any | None, cache: Any | None = None,
                 text_loader: Callable[[Sequence[str]], Mapping[str, Mapping[str, str]]] | None = None,
                 rule_set: RuleSet | None = None, routine: RoutineDef | None = None,
                 co_purchase_enabled: bool | None = None) -> None:
        self.graph = graph
        self.catalog = catalog
        self.cache = cache
        self.text_loader = text_loader
        self.rule_set = rule_set or load_rule_set()
        self.routine = routine or load_routine()
        self.co_purchase_enabled = config.SEARCH_CO_PURCHASE_ENABLED if co_purchase_enabled is None else co_purchase_enabled

    # -- categories
    def _categories(self, ids: Sequence[str]) -> dict[str, list[str]]:
        found: dict[str, list[str]] = {}
        for pid in ids:
            product = self.catalog.get(pid) if self.catalog is not None else None
            if product and product.get("category_keys"):
                found[pid] = list(product["category_keys"])
        missing = [pid for pid in ids if pid not in found]
        if missing and self.graph is not None:
            try:
                for pid, chain in self.graph.cart_categories(missing).items():
                    if chain:
                        found[pid] = chain
            except Exception as exc:
                logger.warning("cart_analyze categories degraded: %s", type(exc).__name__)
        return found

    # -- conflicts
    def _conflicts(self, ids: Sequence[str], categories: Mapping[str, Sequence[str]]) -> tuple[list[dict[str, Any]], bool]:
        if len(ids) < 2:
            return [], False
        if self.graph is not None:
            try:
                return conflicts_from_rows(self.graph.cart_conflicts(list(ids)), categories, self.rule_set), False
            except Exception as exc:
                logger.warning("cart_analyze graph degraded: %s", type(exc).__name__)
        return self._fallback_conflicts(ids, categories), True

    def _fallback_conflicts(self, ids: Sequence[str], categories: Mapping[str, Sequence[str]]) -> list[dict[str, Any]]:
        loader = self.text_loader or mongo_text_loader
        try:
            texts = dict(loader(list(ids)))
        except Exception as exc:
            logger.warning("cart_analyze text loader failed: %s", type(exc).__name__)
            return []
        try:
            from graph_sync.ingredients import load_aliases, parse_ingredients

            aliases = load_aliases()
            display = {e.key: e.inci for e in aliases.values()}
            parsed: dict[str, list[tuple[str, int | None]]] = {}
            for pid, doc in texts.items():
                refs = parse_ingredients(doc.get("full"), aliases) or parse_ingredients(doc.get("sach"), aliases)
                parsed[pid] = [(r.key, r.rank) for r in refs]
            return self._rank_filtered(parsed, categories, display)
        except Exception as exc:
            logger.warning("cart_analyze parser fallback failed (%s); using legacy text rules", type(exc).__name__)
            try:
                flat = {pid: f"{doc.get('full') or ''} {doc.get('sach') or ''}" for pid, doc in texts.items()}
                return conflicts_from_legacy_text(flat, categories, self.rule_set)
            except Exception as exc2:
                logger.warning("cart_analyze legacy fallback failed: %s", type(exc2).__name__)
                return []

    def _rank_filtered(self, parsed: Mapping[str, Sequence[tuple[str, int | None]]],
                       categories: Mapping[str, Sequence[str]], display: Mapping[str, str]) -> list[dict[str, Any]]:
        return conflicts_from_ingredients(parsed, categories, self.rule_set, display)

    def conflicts_for(self, ids: Sequence[str]) -> tuple[list[dict[str, Any]], bool]:
        """Public entry for other callers (U10 chat tool): ``(conflicts, degraded)`` for these products, same rules,
        Neo4j-first with the Python fallback, no routine analysis and no suggestions."""

        ids = list(ids)
        return self._conflicts(ids, self._categories(ids))

    def cart_ingredients(self, ids: Sequence[str]) -> tuple[dict[str, list[tuple[str, int | None]]],
                                                              dict[str, list[str]], bool]:
        """Public entry for the label scan: ``({id: [(conflict-relevant key, rank)]}, {id: category chain}, degraded)``.

        Neo4j first (only keys that take part in a conflict rule, enough for conflict checks); on a missing graph or
        any graph error the Mongo text path of ``_fallback_conflicts`` with ``degraded=True``."""

        ids = list(ids)
        if not ids:
            return {}, {}, False
        categories = self._categories(ids)
        if self.graph is not None:
            try:
                profile = self.graph.cart_profile(ids, sorted(self.rule_set.keys()))
                found = {pid: [(str(i["key"]), i.get("rank")) for i in (profile.get(pid) or {}).get("conflict_ings", [])]
                         for pid in ids}
                return found, categories, False
            except Exception as exc:
                logger.warning("cart_ingredients graph degraded: %s", type(exc).__name__)
        loader = self.text_loader or mongo_text_loader
        parsed: dict[str, list[tuple[str, int | None]]] = {}
        try:
            texts = dict(loader(ids))
            from graph_sync.ingredients import load_aliases, parse_ingredients

            aliases = load_aliases()
            for pid, doc in texts.items():
                refs = parse_ingredients(doc.get("full"), aliases) or parse_ingredients(doc.get("sach"), aliases)
                parsed[pid] = [(r.key, r.rank) for r in refs]
        except Exception as exc:
            logger.warning("cart_ingredients text path failed: %s", type(exc).__name__)
        return parsed, categories, True

    def step_candidates(self, step_key: str, cart_ids: Sequence[str], skin_type: str | None,
                        extra_keys: Sequence[str] = (), limit: int = 6) -> list[dict[str, Any]]:
        """Public entry for the label scan: ranked non-conflicting candidates of one routine step, each with its
        ``conflict_ings`` (``extra_keys`` are added to the keys the graph reports). ``[]`` without a graph;
        graph errors propagate to the caller."""

        step = self.routine.step(step_key)
        if self.graph is None or step is None:
            return []
        ids = list(cart_ids)
        rule_keys = sorted(self.rule_set.keys())
        conflict_keys = sorted(set(rule_keys) | {str(k) for k in extra_keys})
        cart_data = self.graph.cart_profile(ids, rule_keys) if ids else {}
        cart_benefits: set[str] = set()
        for data in cart_data.values():
            cart_benefits.update(data["benefits"])
        rows = self.graph.routine_candidates(list(step.categories), ids, skin_type, conflict_keys, config.CART_CANDIDATES)
        cop: Mapping[str, float] | None = None
        if self.co_purchase_enabled and rows:
            try:
                cop = self.graph.co_purchased(ids, [str(r["id"]) for r in rows])
            except Exception:
                cop = None
        items = rank_step_candidates(rows, cart_benefits, cart_data, self._categories(ids), step.categories,
                                     step.label_vi, skin_type, self.rule_set, cop, limit)
        by_id = {str(r["id"]): list(r.get("conflict_ings") or []) for r in rows}
        return [dict(item, conflict_ings=by_id.get(item["product_id"], [])) for item in items]

    # -- suggestions
    def _suggestions(self, req: CartRequest, routine: Mapping[str, Any], categories: Mapping[str, Sequence[str]]) -> list[dict[str, Any]]:
        if self.graph is None or not routine["applicable"] or not routine["suggest_order"]:
            return []
        conflict_keys = sorted(self.rule_set.keys())
        cart_data = self.graph.cart_profile(list(req.product_ids), conflict_keys)
        cart_benefits: set[str] = set()
        for data in cart_data.values():
            cart_benefits.update(data["benefits"])
        out: list[dict[str, Any]] = []
        for step_key in routine["suggest_order"][: config.CART_MAX_STEPS]:
            step = self.routine.step(step_key)
            if step is None:
                continue
            rows = self.graph.routine_candidates(list(step.categories), list(req.product_ids), req.skin_type,
                                                 conflict_keys, config.CART_CANDIDATES)
            cop: Mapping[str, float] | None = None
            if self.co_purchase_enabled and rows:
                try:
                    cop = self.graph.co_purchased(list(req.product_ids), [str(r["id"]) for r in rows])
                except Exception:
                    cop = None
            items = rank_step_candidates(rows, cart_benefits, cart_data, categories, step.categories,
                                         step.label_vi, req.skin_type, self.rule_set, cop)
            if items:
                out.append({"step": step.key, "items": items})
        return out

    # -- entry point
    def analyze(self, req: CartRequest, correlation_id: str = "") -> dict[str, Any]:
        started = time.perf_counter()
        digest = cache_digest(req)
        cached = self.cache.get(digest) if self.cache is not None else None
        if cached is not None:
            body = dict(cached)
            body["took_ms"] = int((time.perf_counter() - started) * 1000)
            self._log(correlation_id, req, body, True)
            return body
        ids = list(req.product_ids)
        categories = self._categories(ids)
        conflicts, degraded = self._conflicts(ids, categories)
        routine = analyze_routine(self.routine, categories)
        suggestions: list[dict[str, Any]] = []
        if not degraded:
            try:
                suggestions = self._suggestions(req, routine, categories)
            except Exception as exc:
                logger.warning("cart_analyze suggestions degraded: %s", type(exc).__name__)
                degraded = True
        degraded = degraded or self.graph is None      # no graph = no suggestions
        body = {
            "conflicts": conflicts,
            "routine": {"has": routine["has"], "missing": routine["missing"],
                        "labels": {s.key: s.label_vi for s in self.routine.steps},
                        "order": [s.key for s in self.routine.steps]},     # JSON objects are key-sorted: send the canonical order
            "suggestions": suggestions,
            "degraded": degraded,
            "took_ms": 0,
        }
        if self.cache is not None and not degraded:
            self.cache.set(digest, body, config.CART_CACHE_TTL)
        body["took_ms"] = int((time.perf_counter() - started) * 1000)
        self._log(correlation_id, req, body, False)
        return body

    @staticmethod
    def _log(correlation_id: str, req: CartRequest, body: Mapping[str, Any], cache_hit: bool) -> None:
        logger.info(json.dumps({
            "event": "cart_analyze", "correlation_id": correlation_id, "n_products": len(req.product_ids),
            "conflicts": len(body["conflicts"]), "missing": len(body["routine"]["missing"]),
            "suggestions": sum(len(s["items"]) for s in body["suggestions"]), "degraded": body["degraded"],
            "cache_hit": cache_hit, "took_ms": body["took_ms"],
        }, ensure_ascii=False))
