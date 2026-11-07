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

from . import config, ranker
from .ranker import Candidate
from .schemas import RecommendRequest

logger = logging.getLogger("search_service")

BranchFn = Callable[[RecommendRequest, "dict | None"], list[Candidate]]


def _norm_q(q: str) -> str:
    text = unicodedata.normalize("NFKD", q.lower())
    text = "".join(ch for ch in text if not unicodedata.combining(ch)).replace("đ", "d")
    return " ".join(text.split())


def cache_key(req: RecommendRequest) -> str:
    skin = json.dumps(req.skin_profile or {}, sort_keys=True, ensure_ascii=False)
    skin_hash = hashlib.sha1(skin.encode("utf-8")).hexdigest()
    parts = [_norm_q(req.q), skin_hash, ",".join(sorted(req.cart_ids)), str(req.limit_per_section),
             ",".join(sorted(req.exclude_ids))]
    if config.SEARCH_CO_PURCHASE_ENABLED and req.cart_ids:
        parts.append("cop1")  # Bước 08: flag flips never serve the other variant from cache
    # v2 (Bước 05B): responses now carry intent + graph sections; never serve mode-A entries.
    return "search:rec:v2:" + hashlib.sha1("|".join(parts).encode("utf-8")).hexdigest()


class RedisJsonCache:
    """Redis-only cache; any Redis failure means 'no cache'."""

    def __init__(self, redis_factory: Callable[[], Any] | None = None, ttl: int = config.CACHE_TTL) -> None:
        self.redis_factory = redis_factory
        self.ttl = ttl

    def _client(self) -> Any | None:
        if self.redis_factory is not None:
            return self.redis_factory()
        from shared.redis_store import get_redis

        return get_redis()

    def get(self, key: str) -> dict | None:
        if self.ttl <= 0:
            return None
        try:
            client = self._client()
            raw = client.get(key) if client is not None else None
            return json.loads(raw) if raw else None
        except Exception:
            return None

    def set(self, key: str, value: dict) -> None:
        if self.ttl <= 0:
            return
        try:
            client = self._client()
            if client is not None:
                client.setex(key, self.ttl, json.dumps(value, ensure_ascii=False))
        except Exception:
            pass


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
    ) -> None:
        self.branches = dict(branches)
        self.cache = cache
        self.sold_map = sold_map or (lambda: {})
        self.stock_map = stock_map or (lambda: {})
        self.text_map = text_map or (lambda: {})
        self.score_pairs = score_pairs or (lambda: None)
        self.intent_parser = intent_parser
        self.timeout_ms = timeout_ms
        self._ce_executor = ThreadPoolExecutor(max_workers=1, thread_name_prefix="search-ce")
        self.co_purchase = co_purchase
        self._cop_executor = ThreadPoolExecutor(max_workers=2, thread_name_prefix="search-cop")

    def _bought_together(self, req: RecommendRequest, by_branch: Mapping[str, list[Candidate]],
                         degraded: list[str]) -> dict[str, float]:
        """CO_PURCHASED boost (flag + cart_ids only). Failure/timeout -> no boost, branch degraded."""

        if not (config.SEARCH_CO_PURCHASE_ENABLED and self.co_purchase is not None and req.cart_ids):
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
        key = cache_key(req)
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

        bought = self._bought_together(req, by_branch, degraded)
        ranked = ranker.combine(by_branch, self.sold_map(), self.stock_map(), bought_together=bought)
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
        from .neo4j_client import QUERY_TIMEOUT_S, get_driver, report_failure

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
                return getattr(GraphRepository(driver, timeout_s=QUERY_TIMEOUT_S), name)(*args, **kwargs)
            except Exception as exc:
                report_failure(exc)
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
    intent = parse(q, snap)
    out = intent.to_dict()
    out["brand_names"] = [snap.display_name("brand", k) for k in intent.brands]
    out["category_names"] = [snap.display_name("category", k) for k in intent.categories]
    return out


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
            _default = SearchRecommendService(
                branches=fns,
                cache=RedisJsonCache(),
                sold_map=catalog.sold_map,
                stock_map=catalog.stock_map,
                text_map=catalog.text_map,
                score_pairs=_cross_encoder_scorer,
                intent_parser=parse_intent,
                co_purchase=(lambda cart, ids: graph.co_purchased(cart, ids))
                if config.SEARCH_GRAPH_ENABLED and config.SEARCH_CO_PURCHASE_ENABLED else None,
            )
    return _default


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
    if config.SEARCH_CO_PURCHASE_ENABLED:
        graph.co_purchased(["1496"], ["1085"])


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
