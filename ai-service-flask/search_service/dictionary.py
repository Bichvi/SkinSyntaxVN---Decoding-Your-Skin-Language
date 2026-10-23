"""Entity dictionary for the intent parser (Bước 04).

Surface form (token tuple, accent-free) -> (entity type, graph key). Sources, in priority
order on clashes: ``data/graph/synonyms.yaml`` groups -> Neo4j Category/SkinType/Concern/
Benefit names -> brands (Neo4j ``Brand``; fallback Mongo ``thuong_hieu.ten_thuong_hieu``)
-> ``ingredient_aliases.yaml`` -> Neo4j ``Ingredient`` (mapped; unmapped only multi-word).

Thread-safe: one immutable ``DictionarySnapshot`` is swapped in atomically; when the TTL
expires only one thread rebuilds (non-blocking lock), the others keep the old snapshot.
"""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass, field, replace
from threading import Lock, Thread
from typing import Any, Callable, Iterable

from graph_sync.ingredients import load_aliases
from graph_sync.normalize import SYNONYM_GROUPS, display_names, group_synonyms, slugify

from . import config

logger = logging.getLogger(__name__)

MAX_NGRAM = 6
# Query filler words (dropped by the parser, never part of an unmapped ingredient surface).
STOPWORDS = frozenset({"cho", "va", "cua", "loai", "danh", "nao", "tot", "nhat", "mua", "gia", "re"})
# synonyms.yaml group -> entity type used by the parser.
GROUP_TYPES = {"categories": "category", "skin_types": "skin_type", "concerns": "concern", "benefits": "benefit"}
LABEL_TYPES = {"Category": "category", "SkinType": "skin_type", "Concern": "concern", "Benefit": "benefit"}

# Light queries only (names/keys): Neo4j runs close to its memory limit.
Q_BRANDS = "MATCH (n:Brand) RETURN n.key AS key, n.name AS name"
Q_OTHERS = (
    "MATCH (n) WHERE n:Category OR n:SkinType OR n:Concern OR n:Benefit "
    "RETURN labels(n)[0] AS label, n.key AS key, n.name AS name"
)
Q_INGREDIENTS = "MATCH (n:Ingredient) RETURN n.key AS key, n.name AS name, n.inci AS inci"

Surface = tuple[str, ...]
Entity = tuple[str, str]


@dataclass(frozen=True)
class DictionarySnapshot:
    surface: dict[Surface, Entity]
    brand_forms: tuple[str, ...]          # compact (no space) brand forms for fuzzy matching
    brand_form_keys: dict[str, str]
    max_ngram: int
    source: str                           # neo4j | mongo | yaml
    loaded_at: float
    counts: dict[str, int] = field(default_factory=dict)
    names: dict[Entity, str] = field(default_factory=dict)  # (type, key) -> display name

    def lookup(self, tokens: Surface) -> Entity | None:
        return self.surface.get(tuple(tokens))

    def display_name(self, entity_type: str, key: str) -> str:
        """Human name for a parsed key (chips in PHP); falls back to the key."""

        return self.names.get((entity_type, key)) or key


def _tokens(text: Any) -> Surface:
    slug = slugify(text)
    return tuple(slug.split("_")) if slug else ()


def _records(result: Any) -> list[Any]:
    """Accept neo4j ``EagerResult`` / ``(records, summary, keys)`` tuples / plain lists."""

    if isinstance(result, tuple) or hasattr(result, "records"):
        return list(getattr(result, "records", None) or result[0])
    return list(result or [])


def _get(record: Any, name: str) -> Any:
    try:
        return record[name]
    except (KeyError, IndexError, TypeError):
        return getattr(record, name, None)


class _Builder:
    def __init__(self) -> None:
        self.surface: dict[Surface, Entity] = {}
        self.brand_forms: dict[str, str] = {}
        self.counts: dict[str, int] = {}
        self.names: dict[Entity, str] = {}

    def name(self, entity_type: str, key: str, name: Any, override: bool = False) -> None:
        text = str(name or "").strip()
        if key and text and (override or (entity_type, key) not in self.names):
            self.names[(entity_type, key)] = text

    def add(self, tokens: Surface, entity_type: str, key: str) -> None:
        if not tokens or not key or len(tokens) > MAX_NGRAM:
            return
        if len(tokens) == 1 and len(tokens[0]) < 2:
            return  # "c", "e", "d" ... too ambiguous
        if tokens not in self.surface:
            self.surface[tokens] = (entity_type, key)
            self.counts[entity_type] = self.counts.get(entity_type, 0) + 1

    def add_brand(self, key: Any, name: Any) -> None:
        key_slug = slugify(key) or slugify(name)
        if not key_slug:
            return
        self.name("brand", key_slug, name)
        for form in {key_slug, slugify(name)}:
            tokens = tuple(t for t in form.split("_") if t)
            self.add(tokens, "brand", key_slug)
            compact = "".join(tokens)
            if len(tokens) > 1:
                self.add((compact,), "brand", key_slug)
            if len(compact) >= 4:
                self.brand_forms.setdefault(compact, key_slug)

    def snapshot(self, source: str, loaded_at: float) -> DictionarySnapshot:
        longest = max((len(t) for t in self.surface), default=1)
        return DictionarySnapshot(
            surface=self.surface,
            brand_forms=tuple(self.brand_forms),
            brand_form_keys=dict(self.brand_forms),
            max_ngram=min(MAX_NGRAM, longest),
            source=source,
            loaded_at=loaded_at,
            counts=dict(self.counts),
            names=dict(self.names),
        )


class EntityDictionary:
    """Cached entity dictionary. Inject ``driver``/``mongo_db`` (or providers) for tests."""

    def __init__(
        self,
        driver: Any | None = None,
        mongo_db: Any | None = None,
        *,
        driver_provider: Callable[[], Any] | None = None,
        mongo_provider: Callable[[], Any] | None = None,
        ttl_seconds: int = config.SEARCH_DICT_TTL,
        clock: Callable[[], float] = time.monotonic,
    ) -> None:
        self._driver = driver
        self._mongo_db = mongo_db
        self._driver_provider = driver_provider
        self._mongo_provider = mongo_provider
        self._ttl = ttl_seconds
        self._clock = clock
        self._lock = Lock()
        self._snap: DictionarySnapshot | None = None
        self._warned = False

    # ------------------------------------------------------------------ public

    def snapshot(self) -> DictionarySnapshot:
        snap = self._snap
        if snap is not None and self._clock() - snap.loaded_at < self._ttl:
            return snap
        if snap is None:
            with self._lock:  # first load: everyone waits for the single loader
                if self._snap is None:
                    self._snap = self._build(None)
                return self._snap
        if self._lock.acquire(blocking=False):  # stale: one thread reloads, others use old
            try:
                current = self._snap
                if current is None or self._clock() - current.loaded_at >= self._ttl:
                    self._snap = self._build(current)
            finally:
                self._lock.release()
        return self._snap or snap

    def snapshot_nowait(self) -> DictionarySnapshot:
        """Like ``snapshot()`` but a stale snapshot is rebuilt in a background thread, so the
        request path never pays the Neo4j reload (only the very first load blocks)."""

        snap = self._snap
        if snap is None:
            return self.snapshot()
        if self._clock() - snap.loaded_at >= self._ttl and not self._lock.locked():
            Thread(target=self.snapshot, daemon=True, name="entity-dict-reload").start()
        return snap

    def invalidate(self) -> None:
        """Mark the snapshot stale (reloaded in the background on the next ``snapshot_nowait``)."""

        snap = self._snap
        if snap is not None:
            self._snap = replace(snap, loaded_at=float("-inf"))

    def reload(self) -> DictionarySnapshot:
        with self._lock:
            self._snap = self._build(self._snap)
            return self._snap

    # ------------------------------------------------------------------ build

    def _resolve(self, value: Any, provider: Callable[[], Any] | None) -> Any:
        if value is not None:
            return value
        if provider is None:
            return None
        try:
            return provider()
        except Exception as exc:
            logger.warning("entity dictionary: provider failed: %s", type(exc).__name__)
            return None

    def _warn_fallback(self, exc: Exception | None) -> None:
        if not self._warned:
            self._warned = True
            logger.warning(
                "entity dictionary: Neo4j unavailable (%s); fallback to Mongo brands + YAML",
                type(exc).__name__ if exc else "not configured",
            )

    def _build(self, previous: DictionarySnapshot | None) -> DictionarySnapshot:
        now = self._clock()
        driver = self._resolve(self._driver, self._driver_provider)
        neo4j_data = None
        error: Exception | None = None
        if driver is not None:
            try:
                neo4j_data = (
                    _records(driver.execute_query(Q_BRANDS)),
                    _records(driver.execute_query(Q_OTHERS)),
                    _records(driver.execute_query(Q_INGREDIENTS)),
                )
            except Exception as exc:
                error = exc
        if neo4j_data is None:
            if driver is not None or self._driver_provider is not None:
                self._warn_fallback(error)
            if previous is not None and previous.source == "neo4j":
                return replace(previous, loaded_at=now)  # keep richer data, retry after TTL

        builder = _Builder()
        self._add_yaml_groups(builder)
        if neo4j_data is not None:
            brands, others, ingredients = neo4j_data
            for rec in others:
                entity_type = LABEL_TYPES.get(str(_get(rec, "label") or ""))
                key = slugify(_get(rec, "key"))
                if entity_type and key:
                    builder.add(_tokens(key), entity_type, key)
                    builder.add(_tokens(_get(rec, "name")), entity_type, key)
                    builder.name(entity_type, key, _get(rec, "name"), override=True)
            for rec in brands:
                builder.add_brand(_get(rec, "key"), _get(rec, "name"))
            self._add_ingredient_aliases(builder)
            self._add_graph_ingredients(builder, ingredients)
            return builder.snapshot("neo4j", now)

        source = "yaml"
        mongo = self._resolve(self._mongo_db, self._mongo_provider)
        if mongo is not None:
            try:
                names = mongo["thuong_hieu"].distinct("ten_thuong_hieu", maxTimeMS=3000)
                for name in names:
                    if isinstance(name, str) and name.strip():
                        builder.add_brand(None, name)
                source = "mongo"
            except Exception as exc:
                logger.warning("entity dictionary: Mongo brand fallback failed: %s", type(exc).__name__)
        self._add_ingredient_aliases(builder)
        return builder.snapshot(source, now)

    @staticmethod
    def _add_yaml_groups(builder: _Builder) -> None:
        for group in SYNONYM_GROUPS:
            entity_type = GROUP_TYPES[group]
            for variant, canonical in group_synonyms(group).items():
                builder.add(tuple(variant.split("_")), entity_type, canonical)
            for key, name in display_names(group).items():
                builder.name(entity_type, key, name)

    @staticmethod
    def _add_ingredient_aliases(builder: _Builder) -> None:
        for variant, entry in load_aliases().items():
            builder.add(tuple(variant.split("_")), "ingredient", entry.key)

    @staticmethod
    def _add_graph_ingredients(builder: _Builder, records: Iterable[Any]) -> None:
        # Unmapped names are raw ETL fragments: single words ("Kẽm", "nám", "C") and
        # descriptive phrases ("chống oxy hóa cho da", "obagi soothing retinol") would steal
        # longer matches from real entities, so they are skipped.
        protected = {tokens for tokens, (etype, _) in builder.surface.items() if etype != "ingredient"}
        for rec in records:
            key = slugify(_get(rec, "key"))
            if not key:
                continue
            mapped = bool(_get(rec, "inci"))
            for tokens in {_tokens(key), _tokens(_get(rec, "name"))}:
                if mapped or _plausible_unmapped(tokens, protected):
                    builder.add(tokens, "ingredient", key)


def _plausible_unmapped(tokens: Surface, protected: set[Surface]) -> bool:
    if len(tokens) < 2 or any(t in STOPWORDS for t in tokens):
        return False
    for n in range(1, len(tokens) + 1):
        for i in range(len(tokens) - n + 1):
            if tokens[i:i + n] in protected:
                return False
    return True


_default: EntityDictionary | None = None
_default_lock = Lock()


def get_default_dictionary() -> EntityDictionary:
    """Process-wide dictionary using the search Neo4j driver and catalog Mongo handle."""

    global _default
    if _default is None:
        with _default_lock:
            if _default is None:
                from . import catalog, neo4j_client

                _default = EntityDictionary(
                    driver_provider=neo4j_client.get_driver,
                    mongo_provider=catalog.get_mongo_db,
                )
    return _default
