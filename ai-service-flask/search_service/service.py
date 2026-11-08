"""SearchRecommendService: parallel branches, timeout, rank, cache, structured log."""

from __future__ import annotations

import hashlib
import json
import logging
import threading
import time
import unicodedata
from concurrent.futures import ThreadPoolExecutor, wait
from typing import Any, Callable, Mapping

from shared.redis_kit import keys, pubsub

from . import config, ranker
from .ranker import Candidate
from .schemas import RecommendRequest

logger = logging.getLogger("search_service")

BranchFn = Callable[[RecommendRequest, "dict | None"], list[Candidate]]


def _norm_q(q: str) -> str:
    text = unicodedata.normalize("NFKD", q.lower())
    text = "".join(ch for ch in text if not unicodedata.combining(ch)).replace("đ", "d")
    return " ".join(text.split())


def cache_key(req: RecommendRequest, variant: str = "") -> str:
    """Cache key of one recommend response. ``variant`` = ``Assignment.tag`` (U12): an A/B arm re-weights the ranking,
    so its response must never be served to the other arm (nor to requests outside the experiment)."""

    skin = json.dumps(req.skin_profile or {}, sort_keys=True, ensure_ascii=False)
    skin_hash = hashlib.sha1(skin.encode("utf-8")).hexdigest()
    parts = [_norm_q(req.q), skin_hash, ",".join(sorted(req.cart_ids)), str(req.limit_per_section),
             ",".join(sorted(req.exclude_ids))]
    if config.SEARCH_CO_PURCHASE_ENABLED and req.cart_ids:
        parts.append("cop1")  # Bước 08: flag flips never serve the other variant from cache
    if config.SEARCH_CTR_BOOST_ENABLED:
        parts.append("ctr1")  # U12: same rule for the CTR boost
    if variant:
        parts.append("exp:" + variant)
    # v2 (Bước 05B): responses now carry intent + graph sections; never serve mode-A entries.
    # v3 (U03): RedisJsonCache inserts the cache generation (g<gen>) after the prefix.
    return keys.SEARCH_CACHE_PREFIX + hashlib.sha1("|".join(parts).encode("utf-8")).hexdigest()


class RedisJsonCache:
    """Redis-only cache; any Redis failure means 'no cache'.

    The stored key carries the cache generation (``search:cachegen:v1``): ``publish_invalidate`` bumps it,
    which orphans every older entry at once (see ``shared.redis_kit.pubsub``).
    """

    def __init__(self, redis_factory: Callable[[], Any] | None = None, ttl: int = config.CACHE_TTL) -> None:
        self.redis_factory = redis_factory
        self.ttl = ttl

    def _client(self) -> Any | None:
        if self.redis_factory is not None:
            return self.redis_factory()
        from shared.redis_kit import client as redis_client

        return redis_client.get_redis()

    def _full_key(self, client: Any, key: str) -> str:
        return keys.search_cache_key_with_gen(key, pubsub.current_generation(client))

    def get(self, key: str) -> dict | None:
        if self.ttl <= 0:
            return None
        try:
            client = self._client()
            if client is None:
                return None
            raw = client.get(self._full_key(client, key))
            return json.loads(raw) if raw else None
        except Exception:
            return None

    def set(self, key: str, value: dict) -> None:
        if self.ttl <= 0:
            return
        try:
            client = self._client()
            if client is not None:
                client.setex(self._full_key(client, key), self.ttl, json.dumps(value, ensure_ascii=False))
        except Exception:
            pass


VOCABULARY_SCOPES = frozenset({"dictionary", "synonyms", "all"})


def _on_invalidate(message: dict) -> None:
    """Pub/Sub handler: drop in-process caches so product edits show up without waiting for the TTLs."""

    catalog = _parts.get("catalog")
    if catalog is not None and hasattr(catalog, "invalidate"):
        catalog.invalidate()
    if message.get("scope") in VOCABULARY_SCOPES:       # U06: synonyms.yaml / ingredient_aliases.yaml edited
        try:
            from graph_sync.ingredients import reload_vocabulary

            reload_vocabulary()
        except Exception as exc:
            logger.warning("vocabulary reload skipped: %s", type(exc).__name__)
    try:
        from .dictionary import get_default_dictionary

        get_default_dictionary().invalidate()
    except Exception as exc:
        logger.warning("dictionary invalidate skipped: %s", type(exc).__name__)
    logger.info("search caches invalidated scope=%s ids=%d", message.get("scope"), len(message.get("ids") or []))


def start_invalidation_listener() -> None:
    """Subscribe this worker process to ``cache:invalidate`` (idempotent per process)."""

    pubsub.register_handler(_on_invalidate)
    pubsub.start_listener()


class SearchRecommendService:
    def __init__(
        self,
        branches: Mapping[str, BranchFn],
        cache: Any | None = None,
        sold_map: Callable[[], Mapping[str, float]] | None = None,
        stock_map: Callable[[], Mapping[str, bool]] | None = None,
        text_map: Callable[[], Mapping[str, str]] | None = None,
        score_pairs: Callable[[], Callable | None] | None = None,
        intent_parser: Callable[[str], dict | None] | None = None,
        timeout_ms: int = config.SEARCH_TIMEOUT_MS,
        co_purchase: Callable[[list[str], list[str]], Mapping[str, float]] | None = None,
        curated_map: Callable[[], Mapping[str, bool]] | None = None,
        experiments: Any | None = None,
        ctr_lookup: Callable[[Mapping[str, list[str]]], Mapping[str, Mapping[str, float]]] | None = None,
    ) -> None:
        self.branches = dict(branches)
        self.cache = cache
        self.sold_map = sold_map or (lambda: {})
        self.stock_map = stock_map or (lambda: {})
        self.curated_map = curated_map or (lambda: {})
        self.text_map = text_map or (lambda: {})
        self.score_pairs = score_pairs or (lambda: None)
        self.intent_parser = intent_parser
        self.timeout_ms = timeout_ms
        self._ce_executor = ThreadPoolExecutor(max_workers=1, thread_name_prefix="search-ce")
        self.co_purchase = co_purchase
        self._cop_executor = ThreadPoolExecutor(max_workers=2, thread_name_prefix="search-cop")
        self.experiments = experiments          # ExperimentRegistry-like: .assign(session_hash) -> Assignment | None
        self.ctr_lookup = ctr_lookup            # {section: [ids]} -> {section: {id: ctr_norm}}

    def _assignment(self, req: RecommendRequest) -> Any | None:
        """A/B arm of this request (U12). Any failure means "not in an experiment", never a failed search."""

        if self.experiments is None or not req.session_hash:
            return None
        try:
            return self.experiments.assign(req.session_hash)
        except Exception as exc:  # noqa: BLE001
            logger.warning("search experiments skipped: %s", type(exc).__name__)
            return None

    def _ctr_norms(self, by_branch: Mapping[str, list[Candidate]]) -> dict[str, Mapping[str, float]]:
        if self.ctr_lookup is None:
            return {}
        try:
            wanted = {branch: [c.product_id for c in cands] for branch, cands in by_branch.items() if cands}
            return dict(self.ctr_lookup(wanted) or {})
        except Exception as exc:  # noqa: BLE001
            logger.warning("search ctr boost skipped: %s", type(exc).__name__)
            return {}

    def _bought_together(self, req: RecommendRequest, by_branch: Mapping[str, list[Candidate]],
                         degraded: list[str], enabled: bool | None = None) -> dict[str, float]:
        """CO_PURCHASED boost (flag + cart_ids only). Failure/timeout -> no boost, branch degraded.

        ``enabled`` (U12) lets an A/B arm switch the boost on/off for itself; ``None`` = the global flag."""

        use = config.SEARCH_CO_PURCHASE_ENABLED if enabled is None else enabled
        if not (use and self.co_purchase is not None and req.cart_ids):
            return {}
        ids = list(dict.fromkeys(c.product_id for cands in by_branch.values() for c in cands))
        if not ids:
            return {}
        try:
            fut = self._cop_executor.submit(self.co_purchase, list(req.cart_ids), ids)
            counts = fut.result(timeout=config.CO_PURCHASE_TIMEOUT_MS / 1000)
            return ranker.bought_together_scores(counts or {})
        except Exception as exc:
            logger.warning("search co_purchase degraded: %s", type(exc).__name__)
            degraded.append("bought_together")
            return {}

    def recommend(self, req: RecommendRequest, correlation_id: str = "") -> dict:
        started = time.perf_counter()
        assignment = self._assignment(req)
        overrides = assignment.overrides if assignment is not None else None
        key = cache_key(req, assignment.tag if assignment is not None else "")
        cached = self.cache.get(key) if self.cache is not None else None
        if cached:
            cached["took_ms"] = int((time.perf_counter() - started) * 1000)
            self._log(correlation_id, cached, cache_hit=True, q=req.q)
            return cached

        intent = None
        if self.intent_parser is not None:
            try:
                intent = self.intent_parser(req.q)
            except Exception:
                intent = None

        by_branch: dict[str, list[Candidate]] = {}
        degraded: list[str] = []
        executor = ThreadPoolExecutor(max_workers=5, thread_name_prefix="search-branch")
        try:
            futures = {executor.submit(fn, req, intent): name for name, fn in self.branches.items()}
            done, not_done = wait(futures, timeout=self.timeout_ms / 1000)
            for fut in not_done:
                fut.cancel()
                degraded.append(futures[fut])
            for fut in done:
                name = futures[fut]
                try:
                    by_branch[name] = list(fut.result() or [])[: config.BRANCH_MAX_CANDIDATES]
                except Exception as exc:
                    logger.warning("search branch %s failed: %s", name, type(exc).__name__)
                    degraded.append(name)
        finally:
            executor.shutdown(wait=False, cancel_futures=True)

        bought = self._bought_together(req, by_branch, degraded, getattr(overrides, "co_purchase", None))
        use_ctr = getattr(overrides, "ctr_boost", None)
        use_ctr = config.SEARCH_CTR_BOOST_ENABLED if use_ctr is None else use_ctr
        rank_args: dict[str, Any] = {}
        if overrides is not None:                       # U12: this request is in an A/B arm with its own parameters
            rank_args["weights"] = overrides.apply_weights(config.WEIGHTS)
            for name in ("w_cop", "w_curated", "w_ctr"):
                value = getattr(overrides, name, None)
                if value is not None:
                    rank_args[name] = value
        ranked = ranker.combine(
            by_branch, self.sold_map(), self.stock_map(), bought_together=bought, curated=self.curated_map(),
            ctr=self._ctr_norms(by_branch) if use_ctr else None, **rank_args,
        )
        reranked = False
        if config.SEARCH_RERANK_ENABLED:
            ranked, reranked = ranker.cross_encoder_blend(
                req.q, ranked, self.text_map(), self.score_pairs(), executor=self._ce_executor,
            )
        ranked = ranker.dedupe(ranked, req.exclude_ids)
        sections = ranker.cut(ranked, req.limit_per_section)

        degraded = sorted(set(degraded))
        response = {
            "mode": "fallback",
            "degraded": bool(degraded),
            "degraded_branches": degraded,
            "took_ms": int((time.perf_counter() - started) * 1000),
            "intent": intent,
            "sections": sections,
        }
        if assignment is not None:
            response["experiment"] = assignment.to_dict()
        if self.cache is not None and not degraded:
            self.cache.set(key, response)
        self._log(correlation_id, response, cache_hit=False, q=req.q, reranked=reranked)
        return response

    @staticmethod
    def _log(correlation_id: str, response: dict, cache_hit: bool, q: str, reranked: bool = False) -> None:
        record = {
            "event": "search_recommend",
            "correlation_id": correlation_id,
            "took_ms": response.get("took_ms"),
            "degraded_branches": response.get("degraded_branches"),
            "counts": {s["key"]: len(s["items"]) for s in response.get("sections", [])},
            "cache_hit": cache_hit,
            "reranked": reranked,
        }
        if len(q) <= 50:
            record["q"] = q
        if response.get("experiment"):
            record["experiment"] = response["experiment"]
        logger.info(json.dumps(record, ensure_ascii=False))


# ── Default wiring (used by routes) ──
_default: SearchRecommendService | None = None
_default_lock = threading.Lock()
_parts: dict[str, Any] = {}


def _cross_encoder_scorer() -> Callable | None:
    """Only use the cross-encoder once it is already loaded (warm-up thread)."""

    try:
        from shared import embedding_provider

        model = embedding_provider._cross_encoder
    except Exception:
        return None
    return model.score if model is not None else None


STUCK_CALL_SECONDS = 1.5  # > QUERY_TIMEOUT_S + connection_timeout headroom
_inflight: dict[object, float] = {}
_inflight_lock = threading.Lock()


def _stuck_graph_call() -> bool:
    now = time.monotonic()
    with _inflight_lock:
        return any(now - started > STUCK_CALL_SECONDS for started in _inflight.values())


class LazyGraph:
    """GraphRepository resolved per call: Neo4j down -> RuntimeError (branch degraded), and a
    connectivity error opens the neo4j_client breaker so later requests degrade instantly.
    Branches with nothing to ask the graph (no brand/category/skin) never touch Neo4j."""

    def __getattr__(self, name: str) -> Any:
        from .graph_repository import GraphRepository
        from .neo4j_client import QUERY_TIMEOUT_S, get_driver, report_failure, run_with_deadline

        def call(*args: Any, **kwargs: Any) -> Any:
            if _stuck_graph_call():
                # A call has been hanging longer than any server-side tx timeout allows: the
                # host is unreachable (stopped container -> ~4s DNS failure). Open the breaker now.
                report_failure(None)
            driver = get_driver()
            if driver is None:
                raise RuntimeError("neo4j_down")
            token = object()
            with _inflight_lock:
                _inflight[token] = time.monotonic()
            try:
                repo_call = getattr(GraphRepository(driver, timeout_s=QUERY_TIMEOUT_S), name)
                return run_with_deadline(lambda: repo_call(*args, **kwargs))
            except Exception as exc:
                report_failure(exc)   # idempotent: a deadline timeout already opened the breaker
                raise
            finally:
                with _inflight_lock:
                    _inflight.pop(token, None)

        return call


def parse_intent(q: str) -> dict | None:
    """Deterministic intent (Bước 04) + display names for PHP chips (``brand_names``,
    ``category_names``: Mongo/Neo4j names, e.g. "Torriden", "Sữa Rửa Mặt")."""

    from .dictionary import get_default_dictionary
    from .intent_parser import parse

    snap = get_default_dictionary().snapshot_nowait()
    return _intent_dict(parse(q, snap), snap)


def _intent_dict(intent: Any, snap: Any) -> dict:
    out = intent.to_dict()
    out["brand_names"] = [snap.display_name("brand", k) for k in intent.brands]
    out["category_names"] = [snap.display_name("category", k) for k in intent.categories]
    return out


_intent_cache: RedisJsonCache | None = None


def describe_intent(q: str) -> dict:
    """``GET /api/search/intent`` body (U05): ``{intent, corrected_q, corrections:[{from,to,type}]}``.

    ``fuzzy`` = a brand typo fixed by the parser, ``synonym`` = an abbreviation expanded (``srm``).
    Cached in Redis (``SEARCH_INTENT_CACHE_TTL``, default 10 min, keyed by the cache generation).
    """

    from .corrections import find_corrections
    from .dictionary import get_default_dictionary
    from .intent_parser import clean, parse

    global _intent_cache
    if _intent_cache is None:
        _intent_cache = RedisJsonCache(ttl=config.INTENT_CACHE_TTL)
    raw = clean(q)
    key = keys.SEARCH_INTENT_PREFIX + hashlib.sha1(raw.encode("utf-8")).hexdigest()
    cached = _intent_cache.get(key)
    if cached:
        return cached
    snap = get_default_dictionary().snapshot_nowait()
    trace: list[dict] = []
    intent = parse(raw, snap, trace=trace)
    body = {"intent": _intent_dict(intent, snap)}
    body.update(find_corrections(raw, snap, intent=intent, trace=trace))
    _intent_cache.set(key, body)
    return body


def get_default_service() -> SearchRecommendService:
    global _default
    if _default is not None:
        return _default
    with _default_lock:
        if _default is None:
            from . import branches
            from .catalog import CatalogStore

            catalog = CatalogStore()
            vectorstore = branches.CatalogVectorstore()
            hybrid = branches.build_hybrid_search(catalog, vectorstore)
            memo = branches.SemanticMemo(hybrid, catalog)
            trending_source = branches.TrendingSource(catalog)
            fns: dict[str, BranchFn] = {
                "trending": lambda r, i: branches.trending(r, i, trending_source),
            }
            if config.SEARCH_SEMANTIC_ENABLED:
                fns["semantic"] = lambda r, i: branches.semantic(r, i, hybrid, catalog, memo)
            graph = LazyGraph()
            if config.SEARCH_GRAPH_ENABLED:
                sem_memo = memo if config.SEARCH_SEMANTIC_ENABLED else None
                fns["same_brand"] = lambda r, i: branches.same_brand(r, i, graph)
                fns["category_alt"] = lambda r, i: branches.category_alt(r, i, graph, sem_memo)
                fns["personalized"] = lambda r, i: branches.personalized(r, i, graph)
            _parts.update(catalog=catalog, vectorstore=vectorstore, hybrid=hybrid, memo=memo, graph=graph)
            if config.SEARCH_GRAPH_ENABLED:
                from . import neo4j_client

                neo4j_client.set_on_recover(lambda: _warm_graph(graph=_RepoGraph()))
            from .ctr import CtrLookup
            from .experiments import ExperimentRegistry

            registry = ExperimentRegistry()
            _parts["experiments"] = registry
            # The co-purchase callable is wired whenever the graph is on: the global flag (or an A/B arm's override)
            # decides per request whether it is used, so an experiment can turn it on for one arm only (U12).
            _default = SearchRecommendService(
                branches=fns,
                cache=RedisJsonCache(),
                sold_map=catalog.sold_map,
                stock_map=catalog.stock_map,
                curated_map=catalog.curated_map,
                text_map=catalog.text_map,
                score_pairs=_cross_encoder_scorer,
                intent_parser=parse_intent,
                co_purchase=(lambda cart, ids: graph.co_purchased(cart, ids)) if config.SEARCH_GRAPH_ENABLED else None,
                experiments=registry,
                ctr_lookup=CtrLookup().norms,
            )
    return _default


_similar_service: Any | None = None


def get_similar_service() -> Any:
    """U08 service (graph + Chroma fallback) sharing the catalog/vectorstore/graph of the recommend service."""

    global _similar_service
    if _similar_service is not None:
        return _similar_service
    get_default_service()
    with _default_lock:
        if _similar_service is None:
            from .similar import RedisSimilarCache, SimilarProductsService

            _similar_service = SimilarProductsService(
                graph=_parts.get("graph") if config.SEARCH_GRAPH_ENABLED else None,
                catalog=_parts.get("catalog"),
                vectorstore=_parts.get("vectorstore"),
                cache=RedisSimilarCache(),
            )
    return _similar_service


_cart_service: Any | None = None


def get_cart_service() -> Any:
    """U09 service (conflicts + routine) sharing the catalog / graph of the recommend service."""

    global _cart_service
    if _cart_service is not None:
        return _cart_service
    get_default_service()
    with _default_lock:
        if _cart_service is None:
            from .cart_analyze import CartAnalyzeService, RedisCartCache

            _cart_service = CartAnalyzeService(
                graph=_parts.get("graph") if config.SEARCH_GRAPH_ENABLED else None,
                catalog=_parts.get("catalog"),
                cache=RedisCartCache(),
            )
    return _cart_service


class _RepoGraph:
    """Direct repository on the current driver, bypassing the breaker (recovery warm-up)."""

    def __getattr__(self, name: str) -> Any:
        from . import neo4j_client
        from .graph_repository import GraphRepository

        return getattr(GraphRepository(neo4j_client._driver, timeout_s=10.0), name)


def _warm_graph(graph: Any | None = None) -> None:
    """Driver (created after fork) + one run of each Cypher so plans are cached server-side."""

    if graph is None:
        from .neo4j_client import get_driver

        if get_driver() is None:
            return
        graph = _RepoGraph()  # warm-up may exceed the per-request query timeout (cold store)
    graph.warm_cache()
    # Neo4j caches one plan per parameter-type signature: an empty list and a list of strings
    # are different signatures, so compile every empty/non-empty combination once.
    price = {"min_vnd": None, "max_vnd": 400000}
    for cats in ([], ["sua_rua_mat"]):
        graph.same_brand("torriden", cats, price)
    for ingredients in ([], ["ascorbic_acid"]):
        graph.category_alt("sua_rua_mat", "torriden", ingredients, price)
        graph.category_alt("serum", None, ingredients, price)
    for brand in (None, "torriden"):
        graph.similar_to(["1496"], brand, price)
    for skin in (None, "da_dau"):
        for concerns in ([], ["mun"]):
            for cats in ([], ["sua_rua_mat"]):
                for cart in ([], ["1496"]):
                    graph.personalized(skin, concerns, cats, cart, price)
    graph.skin_type_name("da_dau")
    for skin in ("", "da_dau"):
        graph.similar_products("1496", skin, config.SIMILAR_CANDIDATES)
    registry = _parts.get("experiments")
    if config.SEARCH_CO_PURCHASE_ENABLED or (registry is not None and registry.wants("co_purchase")):
        graph.co_purchased(["1496"], ["1085"])
    _warm_chat_tools(graph)
    # U09 cart analysis plans (parameter types must match the runtime calls).
    graph.cart_conflicts(["1496", "1085"])
    graph.cart_categories(["1496"])
    graph.cart_profile(["1496"], ["retinol"])
    for skin in ("", "da_dau"):
        graph.routine_candidates(["kem_chong_nang"], ["1496"], skin, ["retinol"], 5)


def _warm_chat_tools(graph: Any) -> None:
    """U10: Neo4j compiles ``find_products`` once per combination of active constraints (~0.4s each, longer than the
    chat tool deadline), so the usual combinations are compiled here, at worker start, not by the first customer."""

    base = dict(categories=[], brand="", skin_type="", universal=False, concerns=[], benefits=[], include_groups=[],
                exclude_keys=[], avoid_keys=[], rank_limits={}, price=None, fetch=5)
    shapes = (
        dict(categories=["serum"], skin_type="da_dau", avoid_keys=["retinol"]),
        dict(categories=["sua_rua_mat"], exclude_keys=["alcohol_denat"], price={"max_vnd": 300000}),
        dict(categories=["kem_chong_nang"], brand="torriden"),
        dict(categories=["serum"], include_groups=[["niacinamide"]], price={"min_vnd": 200000, "max_vnd": 500000}),
        dict(categories=["kem_duong"], skin_type="da_dau", universal=True),
        dict(skin_type="da_dau", concerns=["mun"], exclude_keys=["alcohol_denat"]),
        dict(categories=["serum"], skin_type="da_dau", benefits=["cap_am"], concerns=["mun"]),
        dict(categories=["toner"], exclude_keys=["alcohol_denat"], avoid_keys=["retinol"]),
    )
    for shape in shapes:
        graph.find_products(**{**base, **shape})
    graph.explain_product("1496", [], 15)
    graph.explain_product("1496", ["alcohol_denat"], 15)


def warm_up() -> None:
    """Best-effort warm-up (gunicorn post_fork): catalog, BM25, embeddings, entity dictionary,
    Neo4j driver + query plans, optional cross-encoder."""

    try:
        service = get_default_service()
        catalog = _parts.get("catalog")
        if catalog is not None:
            catalog.products()
        hybrid = _parts.get("hybrid")
        if hybrid is not None and catalog is not None:
            from .branches import semantic
            from .schemas import validate_request

            semantic(validate_request({"q": "sua rua mat"}), None, hybrid, catalog)
        if config.SEARCH_GRAPH_ENABLED:
            try:
                _warm_graph()
            except Exception as exc:
                logger.warning("search_service graph warm-up degraded: %s", type(exc).__name__)
        try:
            parse_intent("torriden sua rua mat")  # loads EntityDictionary (Neo4j or Mongo/YAML)
        except Exception as exc:
            logger.warning("search_service dictionary warm-up degraded: %s", type(exc).__name__)
        if config.SEARCH_RERANK_ENABLED:
            from shared.embedding_provider import get_cross_encoder

            get_cross_encoder()
        del service
        logger.info("search_service warm-up complete")
    except Exception as exc:
        logger.warning("search_service warm-up degraded: %s", type(exc).__name__)


def get_parts() -> dict[str, Any]:
    get_default_service()
    return _parts
