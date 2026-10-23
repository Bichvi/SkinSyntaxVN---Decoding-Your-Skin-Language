# -*- coding: utf-8 -*-
"""
graph_tools.py (U10) - parameterised Neo4j tools for the Syna chatbot.

The LLM never writes Cypher. Every tool here has a fixed, parameterised query (``search_service.graph_repository``)
and validates its arguments against the project vocabulary (graph keys, ranges) before any query runs. Results are
compact JSON (<= ``MAX_RESULT_BYTES``) with ``evidence`` (shared actives, relation sources, rule ids).

Tools
  find_products(category?, skin_type?, concerns[], benefits[], include_ingredients[], exclude_ingredients[],
                avoid_conflict_with[], brand?, price_max?, price_min?, limit=6)
  check_conflicts(product_ids[] | ingredient_keys[])
  explain_product(product_id, probe_ingredients[])
  similar_products(product_id, limit=5)             (reuses the U08 service)

Prices / stock / visibility are re-read from Mongo (catalog snapshot as fallback) for the products returned, never
taken from the graph copy alone. Pure module: every dependency (graph, vocabulary, facts, rules) is injected.
"""
from __future__ import annotations

import json
import logging
import re
import time
from dataclasses import dataclass, field
from functools import lru_cache
from pathlib import Path
from typing import Any, Callable, Iterable, Mapping, Sequence

import yaml

logger = logging.getLogger("syna_graph")

MAX_RESULT_BYTES = 2048
DEFAULT_LIMIT = 6
MAX_LIMIT = 10
MIN_EXACT_SKIN = 3             # fewer exact skin-type matches than this -> also accept "Mọi loại da"
FETCH_ROWS = 80
SOURCE_RANK = {"manual": 3, "llm_approved": 2, "data": 1, "inferred": 0}
_ID_RE = re.compile(r"^[A-Za-z0-9_-]{1,32}$")
_KEY_RE = re.compile(r"^[a-z0-9_]{1,80}$")
GROUPS_PATH = Path(__file__).resolve().parents[1] / "data" / "graph" / "ingredient_groups.yaml"
EXPERT_NOTE = ("Đây là thông tin tham khảo, không phải chẩn đoán. Nếu da đang kích ứng, đang điều trị da liễu hoặc "
               "mang thai/cho con bú, hãy hỏi bác sĩ da liễu trước khi phối các hoạt chất này.")


class ToolError(Exception):
    """A tool could not run. ``code``: invalid_params | not_found | graph_unavailable."""

    def __init__(self, code: str, message: str = "") -> None:
        super().__init__(f"{code}: {message}" if message else code)
        self.code = code
        self.message = message


# ---------------------------------------------------------------- vocabulary & groups


@dataclass(frozen=True)
class Vocabulary:
    """Allowed graph keys per entity type (``key -> display name``). Tool arguments must come from here."""

    categories: Mapping[str, str] = field(default_factory=dict)
    skin_types: Mapping[str, str] = field(default_factory=dict)
    concerns: Mapping[str, str] = field(default_factory=dict)
    benefits: Mapping[str, str] = field(default_factory=dict)
    brands: Mapping[str, str] = field(default_factory=dict)
    ingredients: Mapping[str, str] = field(default_factory=dict)

    @classmethod
    def from_snapshot(cls, snap: Any) -> "Vocabulary":
        """Build from an ``EntityDictionary`` snapshot (names per type) + the ingredient alias file."""

        names = getattr(snap, "names", {}) or {}

        def pick(kind: str) -> dict[str, str]:
            return {key: text for (etype, key), text in names.items() if etype == kind}

        from graph_sync.ingredients import load_aliases

        ingredients: dict[str, str] = {}
        for entry in load_aliases().values():
            ingredients.setdefault(entry.key, entry.inci or entry.key)
        for entity_type, key in getattr(snap, "surface", {}).values():
            if entity_type == "ingredient":
                ingredients.setdefault(key, key)
        return cls(categories=pick("category"), skin_types=pick("skin_type"), concerns=pick("concern"),
                   benefits=pick("benefit"), brands=pick("brand"), ingredients=ingredients)

    def label(self, kind: str, key: str) -> str:
        return str(getattr(self, kind).get(key) or key)


@dataclass(frozen=True)
class ExclusionGroup:
    id: str
    name: str
    triggers: frozenset[str]
    surfaces: tuple[tuple[str, ...], ...]
    keys: tuple[str, ...]


@dataclass(frozen=True)
class IngredientGroups:
    exclusion: Mapping[str, ExclusionGroup]
    families: Mapping[str, tuple[str, ...]]

    def group_for_trigger(self, key: str) -> ExclusionGroup | None:
        for group in self.exclusion.values():
            if key in group.triggers:
                return group
        return None

    def family(self, key: str) -> tuple[str, ...]:
        return self.families.get(key, (key,))


@lru_cache(maxsize=4)
def load_groups(path: str | None = None) -> IngredientGroups:
    from graph_sync.ingredients import load_aliases

    with open(path or GROUPS_PATH, encoding="utf-8") as handle:
        raw = yaml.safe_load(handle) or {}
    entries = {e.key: e for e in load_aliases().values()}
    exclusion: dict[str, ExclusionGroup] = {}
    for gid, spec in (raw.get("exclusion_groups") or {}).items():
        spec = spec or {}
        keys: list[str] = [str(k) for k in spec.get("keys") or []]
        roles = set(spec.get("roles") or [])
        contains = [str(x) for x in spec.get("key_contains") or []]
        suffixes = [str(x) for x in spec.get("key_suffix") or []]
        for key, entry in entries.items():
            if (entry.role in roles) or any(c in key for c in contains) or any(key.endswith(s) for s in suffixes):
                keys.append(key)
        surfaces = tuple(tuple(str(s).split()) for s in spec.get("surfaces") or [])
        exclusion[str(gid)] = ExclusionGroup(
            id=str(gid), name=str(spec.get("name") or gid), triggers=frozenset(spec.get("triggers") or []),
            surfaces=surfaces, keys=tuple(dict.fromkeys(keys)))
    families = {str(k): tuple(str(x) for x in (v or [])) for k, v in (raw.get("include_families") or {}).items()}
    return IngredientGroups(exclusion=exclusion, families=families)


# ---------------------------------------------------------------- argument validation (pure)


def _as_list(value: Any, name: str, max_items: int) -> list[Any]:
    if value is None or value == "":
        return []
    if isinstance(value, (str, bytes)) or not isinstance(value, Iterable):
        raise ToolError("invalid_params", f"{name} must be a list")
    items = list(value)
    if len(items) > max_items:
        raise ToolError("invalid_params", f"{name} has more than {max_items} items")
    return items


def _keys(value: Any, name: str, allowed: Mapping[str, str], max_items: int) -> list[str]:
    out: list[str] = []
    for item in _as_list(value, name, max_items):
        key = item if isinstance(item, str) else ""
        if not _KEY_RE.match(key) or key not in allowed:
            raise ToolError("invalid_params", f"{name}: unknown key")
        if key not in out:
            out.append(key)
    return out


def _one_key(value: Any, name: str, allowed: Mapping[str, str]) -> str | None:
    if value is None or value == "":
        return None
    if not isinstance(value, str) or not _KEY_RE.match(value) or value not in allowed:
        raise ToolError("invalid_params", f"{name}: unknown key")
    return value


def _price(value: Any, name: str) -> int | None:
    if value is None or value == "":
        return None
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ToolError("invalid_params", f"{name} must be a number")
    amount = int(value)
    if not 1_000 <= amount <= 100_000_000:
        raise ToolError("invalid_params", f"{name} out of range")
    return amount


def _product_id(value: Any, name: str = "product_id") -> str:
    text = str(value).strip() if isinstance(value, (str, int)) and not isinstance(value, bool) else ""
    if not _ID_RE.match(text):
        raise ToolError("invalid_params", f"{name} is not a product id")
    return text


def _limit(value: Any, default: int, maximum: int = MAX_LIMIT) -> int:
    if value is None:
        return default
    if isinstance(value, bool) or not isinstance(value, int) or not 1 <= value <= maximum:
        raise ToolError("invalid_params", f"limit must be 1..{maximum}")
    return value


# ---------------------------------------------------------------- result size


def public_view(result: Mapping[str, Any]) -> dict[str, Any]:
    """The JSON the LLM sees: keys starting with ``_`` (full product cards for the UI) are private."""

    return {k: v for k, v in result.items() if not str(k).startswith("_")}


def result_size(result: Mapping[str, Any]) -> int:
    return len(json.dumps(public_view(result), ensure_ascii=False, separators=(",", ":")).encode("utf-8"))


def fit_result(result: dict[str, Any], list_key: str, budget: int = MAX_RESULT_BYTES) -> dict[str, Any]:
    """Shrink ``result`` below ``budget`` bytes: shorten names, drop ``why`` details, then drop trailing items."""

    if result_size(result) <= budget:
        return result
    items = result.get(list_key) or []
    for item in items:
        if isinstance(item.get("name"), str) and len(item["name"]) > 48:
            item["name"] = item["name"][:47] + "…"
        if isinstance(item.get("why"), list):
            item["why"] = item["why"][:2]
    if result_size(result) <= budget:
        return result
    for item in items:
        item.pop("why", None)
    while items and result_size(result) > budget:
        items.pop()
        result["truncated"] = True
    if "count" in result:
        result["count"] = len(items)
    return result


# ---------------------------------------------------------------- the tools


@dataclass
class ToolDeps:
    """Everything a tool needs. All members are injectable so tests need no database."""

    graph: Any                                          # GraphRepository-like (find_products / explain_product)
    vocab: "Vocabulary | Callable[[], Vocabulary]"      # a provider lets the dictionary snapshot refresh in place
    groups: IngredientGroups
    rule_set: Any                                       # graph_sync.conflicts.RuleSet
    facts: Callable[[Sequence[str]], Mapping[str, Mapping[str, Any]]] = lambda ids: {}
    similar: Any | None = None                          # search_service.similar.SimilarProductsService
    product_conflicts: Callable[[Sequence[str]], tuple[list[dict], bool]] | None = None
    clock: Callable[[], float] = time.perf_counter


class GraphTools:
    TOOLS = {
        "find_products": ("category", "skin_type", "concerns", "benefits", "include_ingredients",
                          "exclude_ingredients", "avoid_conflict_with", "brand", "price_max", "price_min", "limit"),
        "check_conflicts": ("product_ids", "ingredient_keys"),
        "explain_product": ("product_id", "probe_ingredients"),
        "similar_products": ("product_id", "limit"),
    }

    def __init__(self, deps: ToolDeps) -> None:
        self.deps = deps

    @property
    def vocab(self) -> Vocabulary:
        provided = self.deps.vocab
        return provided() if callable(provided) else provided

    # -- dispatcher -------------------------------------------------------------------------------
    def call(self, name: str, args: Mapping[str, Any] | None = None) -> dict[str, Any]:
        """Run tool ``name``. Unknown tools or parameters are rejected (``ToolError('invalid_params')``)."""

        if name not in self.TOOLS:
            raise ToolError("invalid_params", "unknown tool")
        args = dict(args or {})
        unknown = set(args) - set(self.TOOLS[name])
        if unknown:
            raise ToolError("invalid_params", f"unknown parameter: {sorted(unknown)[0]}")
        started = self.deps.clock()
        try:
            result = getattr(self, name)(**args)
        except ToolError as exc:
            self._log(name, args, None, started, error=exc.code)
            raise
        self._log(name, result.get("filters") or {k: v for k, v in args.items() if k != "limit"},
                  result.get("count"), started, degraded=bool(result.get("degraded")))
        return result

    def _log(self, tool: str, params: Mapping[str, Any], count: Any, started: float, *, error: str = "",
             degraded: bool = False) -> None:
        # Normalised parameters only (graph keys / ids / numbers) - the customer's sentence is never logged.
        record = {"event": "syna_graph_tool", "tool": tool, "params": params, "count": count,
                  "took_ms": int((self.deps.clock() - started) * 1000), "degraded": degraded}
        if error:
            record["error"] = error
        logger.info(json.dumps(record, ensure_ascii=False, default=str))

    # -- facts ------------------------------------------------------------------------------------
    def _facts(self, ids: Sequence[str]) -> Mapping[str, Mapping[str, Any]]:
        try:
            return self.deps.facts(list(ids)) or {}
        except Exception as exc:
            logger.warning("graph tool facts degraded: %s", type(exc).__name__)
            return {}

    def _run_graph(self, fn: Callable[[], Any]) -> Any:
        try:
            return fn()
        except ToolError:
            raise
        except Exception as exc:
            raise ToolError("graph_unavailable", type(exc).__name__) from exc

    # -- find_products ----------------------------------------------------------------------------
    def find_products(self, category: str | None = None, skin_type: str | None = None, concerns: Any = None,
                      benefits: Any = None, include_ingredients: Any = None, exclude_ingredients: Any = None,
                      avoid_conflict_with: Any = None, brand: str | None = None, price_max: Any = None,
                      price_min: Any = None, limit: Any = None) -> dict[str, Any]:
        vocab, groups = self.vocab, self.deps.groups
        category_key = _one_key(category, "category", vocab.categories)
        skin_key = _one_key(skin_type, "skin_type", vocab.skin_types)
        brand_key = _one_key(brand, "brand", vocab.brands)
        concern_keys = _keys(concerns, "concerns", vocab.concerns, 4)
        benefit_keys = _keys(benefits, "benefits", vocab.benefits, 4)
        include_keys = _keys(include_ingredients, "include_ingredients", vocab.ingredients, 5)
        avoid_keys = _keys(avoid_conflict_with, "avoid_conflict_with", vocab.ingredients, 4)
        excluded_labels: list[str] = []
        exclude_keys: list[str] = []
        for item in _as_list(exclude_ingredients, "exclude_ingredients", 8):
            if not isinstance(item, str) or not _KEY_RE.match(item):
                raise ToolError("invalid_params", "exclude_ingredients: unknown key")
            if item in groups.exclusion:
                group = groups.exclusion[item]
            elif item in vocab.ingredients:
                group = groups.group_for_trigger(item)
            else:
                raise ToolError("invalid_params", "exclude_ingredients: unknown key")
            expanded = group.keys if group else (item,)
            label = group.name if group else vocab.label("ingredients", item)
            if label not in excluded_labels:
                excluded_labels.append(label)
            exclude_keys.extend(k for k in expanded if k not in exclude_keys)
        high, low = _price(price_max, "price_max"), _price(price_min, "price_min")
        if high is not None and low is not None and low > high:
            raise ToolError("invalid_params", "price_min > price_max")
        want = _limit(limit, DEFAULT_LIMIT)
        families = [list(groups.family(k)) for k in include_keys]
        rank_limits = dict(getattr(self.deps.rule_set, "rank_limits", {}) or {})
        price = {"min_vnd": low, "max_vnd": high} if (low is not None or high is not None) else None

        def query(universal: bool) -> list[dict[str, Any]]:
            return self._run_graph(lambda: self.deps.graph.find_products(
                categories=[category_key] if category_key else [], brand=brand_key or "", skin_type=skin_key or "",
                universal=universal, concerns=concern_keys, benefits=benefit_keys, include_groups=families,
                exclude_keys=exclude_keys, avoid_keys=avoid_keys, rank_limits=rank_limits, price=price,
                fetch=FETCH_ROWS))

        rows = query(False)
        picked, dropped = self._refine(rows, low, high)
        skin_note = ""
        if skin_key and skin_key != "moi_loai_da" and len(picked) < min(want, MIN_EXACT_SKIN):
            more_rows = query(True)
            picked, dropped = self._refine(more_rows, low, high)
            skin_note = "universal_included"
        picked.sort(key=lambda r: self._rank_key(r, bool(skin_key)))
        shown = picked[:want]
        filters = {k: v for k, v in {
            "category": category_key, "skin_type": skin_key, "brand": brand_key, "concerns": concern_keys,
            "benefits": benefit_keys, "include": include_keys, "exclude": exclude_keys[:6] if exclude_keys else [],
            "avoid_conflict_with": avoid_keys, "price_min": low, "price_max": high,
        }.items() if v not in (None, [], "")}
        result: dict[str, Any] = {
            "tool": "find_products", "ok": True, "filters": filters, "count": len(shown),
            "matched": len(picked), "products": [self._product_entry(r, skin_key, vocab) for r in shown],
        }
        notes = self._notes(avoid_keys, excluded_labels, skin_note, dropped, len(rows))
        if notes:
            result["notes"] = notes
        fit_result(result, "products")
        shown_ids = {p["id"] for p in result["products"]}
        result["_cards"] = [self._card(r) for r in shown if str(r["id"]) in shown_ids]
        return result

    @staticmethod
    def _card(row: Mapping[str, Any]) -> dict[str, Any]:
        """UI product card (same keys as ``chatbot_flask.docs_to_products``); price/stock are the refreshed values."""

        pid = str(row["id"])
        return {"id": pid, "name": str(row.get("name") or ""), "brand": row.get("brand") or "",
                "price": row.get("price") or 0, "image_url": row.get("image_url") or "",
                "detail_url": f"index.php?r=chitiet&id={pid}", "summary": str(row.get("summary") or ""),
                "loai_san_pham": row.get("category") or "", "category": row.get("category") or "",
                "loai_da": row.get("loai_da") or "", "skin_type": row.get("loai_da") or "",
                "gia_thi_truong": row.get("market_price") or 0, "phan_tram_giam": row.get("discount_percent") or 0,
                "tien_tiet_kiem": row.get("savings") or 0}

    def _refine(self, rows: Sequence[Mapping[str, Any]], low: int | None, high: int | None
                ) -> tuple[list[dict[str, Any]], dict[str, int]]:
        """Re-read price / stock / visibility from the source of truth and re-apply the price window."""

        ids = [str(r["id"]) for r in rows]
        facts = self._facts(ids)
        kept: list[dict[str, Any]] = []
        dropped = {"hidden": 0, "out_of_stock": 0, "price": 0}
        for row in rows:
            item = dict(row)
            pid = str(item["id"])
            fact = facts.get(pid)
            if facts and (fact is None or fact.get("hidden")):
                dropped["hidden"] += 1
                continue
            if fact is not None:
                if fact.get("price") is not None:
                    item["price"] = fact["price"]
                if fact.get("in_stock") is not None:
                    item["in_stock"] = fact["in_stock"]
                for extra in ("image_url", "summary", "loai_da", "market_price", "discount_percent", "savings"):
                    item[extra] = fact.get(extra)
            if item.get("in_stock") is False:
                dropped["out_of_stock"] += 1
                continue
            amount = item.get("price")
            if amount is not None and ((high is not None and amount > high) or (low is not None and amount < low)):
                dropped["price"] += 1
                continue
            kept.append(item)
        return kept, dropped

    @staticmethod
    def _rank_key(row: Mapping[str, Any], skin_requested: bool) -> tuple:
        curated = sum(SOURCE_RANK.get(str(h.get("source")), 0) for h in (row.get("concern_hits") or []) + (row.get("benefit_hits") or []))
        prominence = min((int(h.get("rank") or 99) for h in row.get("include_hits") or []), default=0)
        return (0 if (not skin_requested or row.get("skin_exact")) else 1, -curated, prominence,
                -(row.get("sold") or 0), str(row["id"]))

    @staticmethod
    def _product_entry(row: Mapping[str, Any], skin_key: str | None, vocab: Vocabulary) -> dict[str, Any]:
        why: list[str] = []
        entry: dict[str, Any] = {"id": str(row["id"]), "name": str(row.get("name") or "")[:70],
                                 "brand": row.get("brand") or "", "price": row.get("price")}
        if row.get("category"):
            entry["cat"] = row["category"]
        if skin_key:
            entry["skin"] = "exact" if row.get("skin_exact") else "universal"
            why.append(f"{vocab.label('skin_types', skin_key)}: " + ("dữ liệu khớp" if row.get("skin_exact") else "ghi 'mọi loại da'"))
        for hit in (row.get("include_hits") or [])[:2]:
            why.append(f"có {hit.get('name')} (vị trí {hit.get('rank')})")
        for hit in (row.get("concern_hits") or [])[:2]:
            why.append(f"nhắm {hit.get('name')} [{hit.get('source')}]")
        for hit in (row.get("benefit_hits") or [])[:2]:
            why.append(f"công dụng {hit.get('name')} [{hit.get('source')}]")
        if why:
            entry["why"] = why
        return entry

    def _notes(self, avoid_keys: Sequence[str], excluded: Sequence[str], skin_note: str,
               dropped: Mapping[str, int], fetched: int) -> list[str]:
        vocab = self.vocab
        notes: list[str] = []
        for key in avoid_keys:
            others = sorted({vocab.label("ingredients", o) for o in self._conflicting_with(key)})
            if others:
                notes.append(f"Không kỵ {vocab.label('ingredients', key)}: đã loại sản phẩm có {', '.join(others[:6])} "
                             "(mức trung bình/cao, đủ gần đầu bảng thành phần).")
            else:
                notes.append(f"Chưa có luật xung đột nào cho {vocab.label('ingredients', key)} nên không loại gì.")
        if excluded:
            notes.append("Không chứa " + ", ".join(excluded) + ": chỉ xét sản phẩm có bảng thành phần đã đọc được.")
        if skin_note:
            notes.append("Ít sản phẩm khớp đúng loại da nên có thêm sản phẩm ghi 'mọi loại da'.")
        if dropped.get("out_of_stock"):
            notes.append(f"Đã bỏ {dropped['out_of_stock']} sản phẩm hết hàng.")
        if fetched >= FETCH_ROWS:
            notes.append("Danh sách đã cắt ở các sản phẩm bán chạy nhất.")
        return notes

    def _conflicting_with(self, key: str) -> list[str]:
        from graph_sync.conflicts import expand_pairs

        out: list[str] = []
        for pair in expand_pairs(self.deps.rule_set):
            if pair.severity == "low":
                continue
            if pair.a == key:
                out.append(pair.b)
            elif pair.b == key:
                out.append(pair.a)
        return out

    # -- check_conflicts --------------------------------------------------------------------------
    def check_conflicts(self, product_ids: Any = None, ingredient_keys: Any = None) -> dict[str, Any]:
        has_products = product_ids not in (None, [], ())
        has_keys = ingredient_keys not in (None, [], ())
        if has_products == has_keys:
            raise ToolError("invalid_params", "give either product_ids or ingredient_keys")
        if has_keys:
            keys = _keys(ingredient_keys, "ingredient_keys", self.vocab.ingredients, 5)
            if len(keys) < 2:
                raise ToolError("invalid_params", "ingredient_keys needs at least 2 ingredients")
            return self._conflicts_by_ingredient(keys)
        ids = [_product_id(i) for i in _as_list(product_ids, "product_ids", 6)]
        ids = list(dict.fromkeys(ids))
        if len(ids) < 2:
            raise ToolError("invalid_params", "product_ids needs at least 2 products")
        return self._conflicts_by_product(ids)

    def _conflicts_by_ingredient(self, keys: Sequence[str]) -> dict[str, Any]:
        from graph_sync.conflicts import expand_pairs

        table = {(p.a, p.b): p for p in expand_pairs(self.deps.rule_set)}
        sources = {r.id: r.source for r in self.deps.rule_set.rules}
        vocab = self.vocab
        pairs: list[dict[str, Any]] = []
        for i, first in enumerate(keys):
            for second in keys[i + 1:]:
                pair = table.get(tuple(sorted((first, second))))  # type: ignore[arg-type]
                if pair is None:
                    continue
                pairs.append({
                    "a": vocab.label("ingredients", first), "b": vocab.label("ingredients", second),
                    "severity": pair.severity, "timing": pair.timing, "advice": pair.advice_vi[:230],
                    "evidence": pair.evidence, "rule": pair.rule_id, "source": sources.get(pair.rule_id, "")[:110],
                })
        pairs.sort(key=lambda p: -("low", "medium", "high").index(p["severity"]))
        return self._conflict_result({"ingredients": [vocab.label("ingredients", k) for k in keys]}, pairs, False)

    def _conflicts_by_product(self, ids: Sequence[str]) -> dict[str, Any]:
        if self.deps.product_conflicts is None:
            raise ToolError("graph_unavailable", "no product conflict source")
        conflicts, degraded = self._run_graph(lambda: self.deps.product_conflicts(list(ids)))
        names = {pid: (f.get("name") or pid) for pid, f in self._facts(ids).items()}
        pairs = [{
            "a": names.get(c["a"], c["a"])[:60], "b": names.get(c["b"], c["b"])[:60], "a_id": c["a"], "b_id": c["b"],
            "severity": c["severity"], "timing": c["timing"], "advice": str(c.get("advice_vi") or "")[:230],
            "evidence": c.get("evidence"), "rule": c.get("rule_id"), "ingredients": list(c.get("ingredients") or [])[:3],
        } for c in conflicts]
        return self._conflict_result({"products": list(ids)}, pairs, degraded)

    @staticmethod
    def _conflict_result(subject: dict[str, Any], pairs: list[dict[str, Any]], degraded: bool) -> dict[str, Any]:
        result: dict[str, Any] = {"tool": "check_conflicts", "ok": True, "subject": subject, "count": len(pairs),
                                  "pairs": pairs, "conflict_found": bool(pairs), "degraded": degraded}
        if any(p["severity"] == "high" for p in pairs):
            result["consult_expert"] = True
            result["safety_note"] = EXPERT_NOTE
        if not pairs:
            result["note"] = "Chưa có luật xung đột nào trong cơ sở tri thức hiện tại cho các mục này (không đồng nghĩa là tuyệt đối an toàn)."
        return fit_result(result, "pairs")

    # -- explain_product --------------------------------------------------------------------------
    def explain_product(self, product_id: Any, probe_ingredients: Any = None) -> dict[str, Any]:
        pid = _product_id(product_id)
        vocab, groups = self.vocab, self.deps.groups
        probes: dict[str, tuple[str, tuple[str, ...]]] = {}     # item -> (label, member keys); key or group id ("paraben")
        probe_keys: list[str] = []
        for item in _as_list(probe_ingredients, "probe_ingredients", 5):
            if not isinstance(item, str) or not _KEY_RE.match(item):
                raise ToolError("invalid_params", "probe_ingredients: unknown key")
            if item in groups.exclusion:
                label, expanded = groups.exclusion[item].name, groups.exclusion[item].keys
            elif item in vocab.ingredients:
                trigger = groups.group_for_trigger(item)
                label = trigger.name if trigger else vocab.label("ingredients", item)
                expanded = trigger.keys if trigger else groups.family(item)
            else:
                raise ToolError("invalid_params", "probe_ingredients: unknown key")
            probes.setdefault(item, (label, tuple(expanded)))
            probe_keys.extend(k for k in expanded if k not in probe_keys)
        row = self._run_graph(lambda: self.deps.graph.explain_product(pid, probe_keys, 15))
        if not row or row.get("active") is False:
            raise ToolError("not_found", "product")
        fact = self._facts([pid]).get(pid)
        if fact is not None:
            row = {**row, "price": fact.get("price", row.get("price")), "in_stock": fact.get("in_stock", row.get("in_stock"))}
        vocab = self.vocab
        actives = sorted(row.get("actives") or [], key=lambda a: int(a.get("rank") or 99))[:6]
        hit_keys = {h.get("key"): h for h in row.get("probe_hits") or []}
        result: dict[str, Any] = {
            "tool": "explain_product", "ok": True, "count": 1,
            "product": {"id": pid, "name": str(row.get("name") or "")[:80], "brand": row.get("brand") or "",
                        "category": row.get("category") or "", "price": row.get("price"), "in_stock": row.get("in_stock")},
            "actives": [{"name": a.get("name"), "rank": a.get("rank")} for a in actives],
            "benefits": [{"name": b.get("name"), "source": b.get("source")} for b in (row.get("benefits") or [])[:5]],
            "concerns": [{"name": c.get("name"), "source": c.get("source")} for c in (row.get("concerns") or [])[:5]],
            "skin_types": [{"name": s.get("name"), "source": s.get("source")} for s in (row.get("skin_types") or [])[:4]],
            "ingredients_read": row.get("n_ingredients") or 0,
        }
        if probes:
            result["probe"] = [{"ingredient": label, "present": any(k in hit_keys for k in members),
                                "readable": (row.get("n_ingredients") or 0) >= 3} for label, members in probes.values()]
        fit_result(result, "actives")
        result["_cards"] = [self._card({**row, "id": pid, "image_url": (fact or {}).get("image_url"),
                                        "summary": (fact or {}).get("summary"), "loai_da": (fact or {}).get("loai_da"),
                                        "market_price": (fact or {}).get("market_price"),
                                        "discount_percent": (fact or {}).get("discount_percent"),
                                        "savings": (fact or {}).get("savings")})]
        return result

    # -- similar_products -------------------------------------------------------------------------
    def similar_products(self, product_id: Any, limit: Any = None) -> dict[str, Any]:
        if self.deps.similar is None:
            raise ToolError("graph_unavailable", "similar service")
        from search_service.similar import SimilarValidationError, parse_args

        pid = _product_id(product_id)
        want = _limit(limit, 5, 8)
        try:
            request = parse_args(pid, want)
        except SimilarValidationError as exc:
            raise ToolError("invalid_params", exc.field) from exc
        body = self._run_graph(lambda: self.deps.similar.similar(request))
        items = body.get("items") or []
        facts = self._facts([i["product_id"] for i in items] + [pid])
        out = []
        cards = []
        for item in items:
            fact = facts.get(item["product_id"])
            if facts and (fact is None or fact.get("hidden")):
                continue
            fact = fact or {}
            if fact.get("in_stock") is False:
                continue
            entry = {"id": item["product_id"], "name": str(fact.get("name") or "")[:70], "brand": fact.get("brand") or "",
                     "price": fact.get("price"), "reason": item.get("reason_code")}
            if item.get("reason_params"):
                entry["shared"] = list(item["reason_params"])[:3]
            out.append(entry)
            cards.append(self._card({"id": item["product_id"], "name": fact.get("name"), "brand": fact.get("brand"),
                                     "price": fact.get("price"), "image_url": fact.get("image_url"),
                                     "summary": fact.get("summary"), "loai_da": fact.get("loai_da"),
                                     "market_price": fact.get("market_price"),
                                     "discount_percent": fact.get("discount_percent"), "savings": fact.get("savings")}))
        result = {"tool": "similar_products", "ok": True, "count": len(out), "source": body.get("source"),
                  "degraded": bool(body.get("degraded")), "of": {"id": pid, "name": (facts.get(pid) or {}).get("name", "")[:70]},
                  "items": out}
        fit_result(result, "items")
        keep = {i["id"] for i in result["items"]}
        result["_cards"] = [c for c in cards if c["id"] in keep]
        return result


# ---------------------------------------------------------------- default wiring helpers


def first_image(value: Any) -> str:
    match = re.search(r"https?://[^\s,|;]+", str(value or ""))
    return match.group(0) if match else str(value or "").strip()


def make_facts_loader(catalog: Any | None, mongo_db_getter: Callable[[], Any] | None,
                      max_time_ms: int = 400) -> Callable[[Sequence[str]], dict[str, dict[str, Any]]]:
    """Fresh price / stock / image for a handful of products.

    Mongo (Atlas) first (read-only, ``max_time_ms``); on any failure the catalog snapshot answers. When a source
    answered, ids it does not know come back as ``{"hidden": True}`` (hidden or unknown products must not be
    shown). An empty dict means "no source reachable": callers then keep the graph's own values.
    """

    def snapshot_fact(pid: str) -> dict[str, Any] | None:
        product = catalog.get(pid) if catalog is not None else None
        if product is None:
            return None
        return {"name": product.get("ten_san_pham"), "brand": product.get("thuong_hieu"), "price": product.get("price"),
                "in_stock": product.get("in_stock", True), "image_url": "", "loai_da": product.get("loai_da") or "",
                "summary": "", "source": "catalog"}

    def from_mongo(wanted: list[str]) -> dict[str, dict[str, Any]] | None:
        db = mongo_db_getter() if mongo_db_getter else None
        if db is None:
            return None
        variants: list[Any] = []
        for pid in wanted:
            variants.append(pid)
            if pid.isdigit():
                variants.append(int(pid))
        projection = {"_id": 0, "ma_san_pham": 1, "ten_san_pham": 1, "gia_ban": 1, "gia_thi_truong": 1,
                      "phan_tram_giam": 1, "tien_tiet_kiem": 1, "so_luong_ton_kho": 1, "trang_thai_kho": 1,
                      "trang_thai": 1, "link_hinh_anh": 1, "loai_da": 1, "thanh_phan": 1}
        found: dict[str, dict[str, Any]] = {}
        hidden = {"inactive", "hidden", "disabled", "0", "tam_an"}
        for doc in db["san_pham"].find({"ma_san_pham": {"$in": variants}}, projection, max_time_ms=max_time_ms):
            if str(doc.get("trang_thai") or "").lower() in hidden:
                continue
            stock = doc.get("so_luong_ton_kho")
            in_stock = not (doc.get("trang_thai_kho") == "het_hang" or (isinstance(stock, (int, float)) and stock <= 0))
            price = doc.get("gia_ban")
            pid = str(doc.get("ma_san_pham"))
            base = snapshot_fact(pid) or {}
            found[pid] = {**base, "name": doc.get("ten_san_pham") or base.get("name"),
                          "price": int(price) if isinstance(price, (int, float)) else base.get("price"),
                          "market_price": doc.get("gia_thi_truong"), "discount_percent": doc.get("phan_tram_giam"),
                          "savings": doc.get("tien_tiet_kiem"), "in_stock": in_stock,
                          "image_url": first_image(doc.get("link_hinh_anh")), "loai_da": doc.get("loai_da") or "",
                          "summary": str(doc.get("thanh_phan") or "")[:120], "source": "mongo"}
        return found

    def load(ids: Sequence[str]) -> dict[str, dict[str, Any]]:
        wanted = [str(i) for i in ids]
        if not wanted:
            return {}
        try:
            found = from_mongo(wanted)
            if found is not None:
                return {pid: found.get(pid) or {"hidden": True} for pid in wanted}
        except Exception as exc:
            logger.warning("graph tool facts: Mongo degraded (%s); using catalog snapshot", type(exc).__name__)
        if catalog is None:
            return {}
        out: dict[str, dict[str, Any]] = {}
        for pid in wanted:
            out[pid] = snapshot_fact(pid) or {"hidden": True}
        return out if any(not f.get("hidden") for f in out.values()) else {}

    return load
