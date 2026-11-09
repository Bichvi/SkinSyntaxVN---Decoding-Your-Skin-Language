from __future__ import annotations

import json
import logging
import time
import unicodedata
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FutureTimeoutError
from dataclasses import dataclass, field
from typing import Any, Callable, Literal

from langchain_core.tools import StructuredTool
from pydantic import BaseModel, ConfigDict, Field, model_validator

from . import audit
from .auth import AgentClaims
from .php_client import PhpError, PhpClient
from .prompts import TOOL_DOCS
from .state import ConvState, product_price


logger = logging.getLogger(__name__)
_TEXT_SEARCH_EXECUTOR = ThreadPoolExecutor(max_workers=2, thread_name_prefix="agent-text-search")


TOOL_SCOPES: dict[str, str | None] = {
    "search_products": None, "get_product": None, "compare_products": None,
    "check_conflicts": None, "view_cart": "cart:write", "add_to_cart": "cart:write",
    "remove_from_cart": "cart:write", "get_checkout_defaults": "draft:create",
    "prepare_order_draft": "draft:create", "reorder_from_order": "draft:create",
    "get_order_status": "order:read",
}


@dataclass
class ToolContext:
    claims: AgentClaims | None
    php: PhpClient | None
    cart_snapshot: list[dict]
    emit: Callable[[str, dict], None]
    state: ConvState
    graph_tools: Any | None
    text_search: Callable[[Any], dict] | None = None
    text_search_factory: Callable[[], Any] | None = None
    text_search_timeout: float = 6.0
    name_lookup: Callable[[str], list[str]] | None = None
    skin_profile: dict[str, Any] = field(default_factory=dict)
    scanned_product: dict[str, Any] = field(default_factory=dict)
    # Product ids already shown as search cards in this turn (a ToolContext lives for one turn).
    turn_shown_ids: set[str] = field(default_factory=set)


class _Args(BaseModel):
    model_config = ConfigDict(extra="forbid")


class SearchArgs(_Args):
    query: str = Field(max_length=200)
    skin_type: str | None = None
    concerns: list[str] = Field(default_factory=list, max_length=4)
    price_max: int | None = Field(default=None, ge=0)
    brand: str | None = None
    limit: int = Field(default=3, ge=1, le=6)


class ProductArgs(_Args):
    product_id: str


class CompareArgs(_Args):
    product_ids: list[str] = Field(min_length=2, max_length=3)


class ConflictArgs(_Args):
    product_ids: list[str] = Field(default_factory=list, max_length=6)
    include_cart: bool = True


class CartItem(BaseModel):
    model_config = ConfigDict(extra="forbid")
    product_id: str
    qty: int = Field(ge=1, le=20)


class AddArgs(_Args):
    items: list[CartItem] = Field(min_length=1, max_length=5)


_ADDRESS_ALIASES: dict[str, tuple[str, ...]] = {
    "ten_nguoi_nhan": ("ten_nguoi_nhan", "name", "full_name", "receiver_name", "recipient_name", "ho_ten"),
    "sdt_nguoi_nhan": ("sdt_nguoi_nhan", "phone", "phone_number", "sdt", "so_dien_thoai", "mobile"),
    "dia_chi_giao_hang": ("dia_chi_giao_hang", "address", "full_address", "dia_chi"),
}
_ADDRESS_PARTS: tuple[tuple[str, ...], ...] = (
    ("street", "duong"), ("ward", "phuong"), ("district", "quan"), ("city", "tinh", "thanh_pho"),
)


def _first_text(data: dict[str, Any], keys: tuple[str, ...]) -> str | None:
    for key in keys:
        value = data.get(key)
        if isinstance(value, (str, int, float)) and not isinstance(value, bool) and str(value).strip():
            return str(value).strip()
    return None


class NewAddress(BaseModel):
    """Địa chỉ giao hàng mới, đúng 3 trường mà backend PHP chấp nhận."""

    model_config = ConfigDict(extra="ignore")
    ten_nguoi_nhan: str | None = Field(default=None, description="Tên người nhận hàng.")
    sdt_nguoi_nhan: str | None = Field(default=None, description="Số điện thoại người nhận.")
    dia_chi_giao_hang: str | None = Field(
        default=None,
        description='Địa chỉ đầy đủ: "số nhà đường, phường/xã, quận/huyện, tỉnh/thành".',
    )

    @model_validator(mode="before")
    @classmethod
    def _map_aliases(cls, data: Any) -> Any:
        if not isinstance(data, dict):
            return data
        mapped: dict[str, Any] = {}
        for field_name, keys in _ADDRESS_ALIASES.items():
            value = _first_text(data, keys)
            if value is not None:
                mapped[field_name] = value
        if "dia_chi_giao_hang" not in mapped:
            parts = [part for keys in _ADDRESS_PARTS if (part := _first_text(data, keys))]
            if parts:
                mapped["dia_chi_giao_hang"] = ", ".join(parts)
        return mapped


class DraftArgs(_Args):
    scope: Literal["mentioned_items", "whole_cart"]
    product_ids: list[str] = Field(default_factory=list)
    qty: dict[str, int] | None = None
    address_choice: Literal["default", "new"]
    new_address: NewAddress | None = None
    payment_method: Literal["cod", "vnpay", "bank_transfer_qr"]
    voucher_code: str | None = None
    remove_voucher: bool = False


class ReorderArgs(_Args):
    order_code: str


class OrderStatusArgs(_Args):
    order_code: str | None = None


def _wrapped(value: Any) -> str:
    encoded = json.dumps(value, ensure_ascii=False, default=str).replace("<", "\\u003c").replace(">", "\\u003e")
    return "<tool_data>" + encoded + "</tool_data>"


def _actor(ctx: ToolContext) -> str:
    return ctx.claims.sub if ctx.claims else "guest"


def _error_code(exc: PhpError) -> str:
    code = str(getattr(exc, "code", "") or "")
    if code == "out_of_stock":
        return "out_of_stock"
    if exc.status in (401, 403):
        return "login_required"
    if exc.status == 409:
        return code or "conflict"
    if exc.status == 0:
        return "php_unavailable"
    return code or "php_error"


def _php(ctx: ToolContext, route: str, payload: dict | None) -> dict:
    if ctx.php is None:
        raise PhpError(0, "PHP client unavailable")
    return ctx.php.call(route, payload)


def _graph(ctx: ToolContext, name: str, args: dict) -> dict:
    graph = ctx.graph_tools
    if graph is None:
        try:
            from chatbot_service.graph_router import get_runtime
            runtime = get_runtime()
            graph = runtime.tools if runtime is not None else None
        except Exception:
            graph = None
    if graph is None:
        return {"error": "search_unavailable"}
    graph = getattr(graph, "tools", graph)
    try:
        return graph.call(name, args)
    except Exception as exc:
        code = getattr(exc, "code", "")
        return {"error": "invalid_params" if code == "invalid_params" else "search_unavailable"}


def _graph_public(result: dict) -> dict:
    try:
        from chatbot_service.graph_tools import public_view
        return public_view(result)
    except Exception:
        return {key: value for key, value in result.items() if not str(key).startswith("_")}


def _safe_params(value: Any) -> Any:
    if isinstance(value, BaseModel):
        return value.model_dump(mode="json")
    if isinstance(value, dict):
        return {str(key): _safe_params(item) for key, item in value.items()}
    if isinstance(value, list):
        return [_safe_params(item) for item in value]
    if isinstance(value, tuple):
        return [_safe_params(item) for item in value]
    return value


def _call(ctx: ToolContext, name: str, fn: Callable[[], Any], params: dict) -> str:
    outcome = "ok"
    try:
        value = fn()
        if isinstance(value, dict) and value.get("failed"):
            outcome = "failed" if not value.get("added") else "partial"
        return _wrapped(value)
    except PhpError as exc:
        outcome = _error_code(exc)
        if outcome == "login_required":
            ctx.emit("ui", {"kind": "login_required", "payload": {}})
        return _wrapped({"error": outcome, "message": exc.message})
    finally:
        audit.record(_actor(ctx), name, _safe_params(params), outcome)


def _scope_ok(ctx: ToolContext, name: str) -> bool:
    scope = TOOL_SCOPES[name]
    return scope is None or (ctx.claims is not None and ctx.claims.has(scope))


def _guarded(ctx: ToolContext, name: str, fn: Callable[[], Any], params: dict) -> str:
    if not _scope_ok(ctx, name):
        ctx.emit("ui", {"kind": "login_required", "payload": {}})
        result = _wrapped({"error": "login_required"})
        audit.record(_actor(ctx), name, _safe_params(params), "login_required")
        return result
    return _call(ctx, name, fn, params)


def _fold(value: str) -> str:
    text = unicodedata.normalize("NFKD", value.casefold())
    return "".join(ch for ch in text if not unicodedata.combining(ch)).strip()


def _vocab_key(values: Any, value: str) -> str | None:
    wanted = _fold(value)
    for key, label in (values or {}).items():
        if wanted in {_fold(str(key)), _fold(str(label))}:
            return str(key)
    return None


def _graph_runtime(ctx: ToolContext) -> tuple[Any, Any]:
    graph = ctx.graph_tools
    if graph is not None:
        graph = getattr(graph, "tools", graph)
        return graph, getattr(graph, "vocab", None)
    try:
        from chatbot_service.graph_router import get_runtime
        runtime = get_runtime()
        return (runtime.tools, runtime.tools.vocab) if runtime is not None else (None, None)
    except Exception:
        return None, None


def _text_products(response: dict) -> list[dict]:
    """Flatten recommend() sections into one list ranked by score.

    The sections come in a fixed display order (category_alt, same_brand, ...), so concatenating them
    buries the best match - e.g. an exact product name lands in same_brand behind three category_alt
    items - and the caller's limit then cuts it off.  Scores are comparable across sections.
    """

    best: dict[str, dict] = {}
    for section in response.get("sections") or []:
        for item in section.get("items") or []:
            if not isinstance(item, dict):
                continue
            product = dict(item)
            product.setdefault("id", product.get("product_id"))
            if product.get("id") is None:
                continue
            pid = str(product["id"])
            current = best.get(pid)
            if current is None or _score(product) > _score(current):
                best[pid] = product
    return sorted(best.values(), key=lambda item: -_score(item))


def _score(item: dict) -> float:
    try:
        return float(item.get("score") or 0)
    except (TypeError, ValueError):
        return 0.0


_MIN_NAME_MATCH_CHARS = 8


def _name_rank(query: str, name: str) -> int:
    """0 = exact name, 1 = near-exact (one contains the other), 2 = no name match."""

    wanted, candidate = _fold(query), _fold(name)
    if not wanted or not candidate:
        return 2
    if wanted == candidate:
        return 0
    shorter = min(len(wanted), len(candidate))
    if shorter >= _MIN_NAME_MATCH_CHARS and (wanted in candidate or candidate in wanted):
        return 1
    return 2


def _catalog_name_ids(ctx: ToolContext, query: str) -> list[str]:
    """Ids of catalog products whose name equals / nearly equals the query (exact names first)."""

    lookup = ctx.name_lookup
    if lookup is None:
        if ctx.text_search is not None or ctx.text_search_factory is not None:
            return []  # a custom text search is injected (tests): do not reach for the real catalog
        def lookup(text: str) -> list[str]:
            from search_service.service import get_parts

            catalog = get_parts().get("catalog")
            rows = catalog.products() if catalog is not None else []
            ranked = sorted(
                ((_name_rank(text, str(row.get("name") or "")), str(row.get("id") or "")) for row in rows),
                key=lambda pair: pair[0],
            )
            return [pid for rank, pid in ranked if rank < 2 and pid][:3]
    try:
        return [str(pid) for pid in lookup(query) if pid]
    except Exception as exc:
        logger.warning("agent catalog name lookup unavailable: %s", type(exc).__name__)
        return []


def _prefer_named(query: str, products: list[dict]) -> list[dict]:
    return sorted(products, key=lambda item: _name_rank(query, str(item.get("name") or "")))


def _facts_loader(graph: Any) -> Callable[[list[str]], dict] | None:
    deps = getattr(graph, "deps", None)
    facts = getattr(deps, "facts", None)
    if callable(facts):
        return facts
    facts = getattr(graph, "facts", None)
    return facts if callable(facts) else None


def _card_for(graph: Any, row: dict) -> dict:
    card_builder = getattr(graph, "_card", None)
    if callable(card_builder):
        return card_builder(row)
    pid = str(row["id"])
    return {
        "id": pid, "name": str(row.get("name") or ""), "brand": row.get("brand") or "",
        "price": row.get("price") or 0, "image_url": row.get("image_url") or "",
        "detail_url": f"index.php?r=chitiet&id={pid}", "summary": str(row.get("summary") or ""),
    }


def _hydrate_products(graph: Any, products: list[dict], cards: list[dict]) -> tuple[list[dict], dict[str, dict]]:
    facts_loader = _facts_loader(graph)
    if facts_loader is None:
        return [], {}
    ids = [str(item.get("id") or item.get("product_id")) for item in products]
    try:
        facts = facts_loader(ids) or {}
    except Exception as exc:
        logger.warning("agent product hydration unavailable: %s", type(exc).__name__)
        return [], {}
    card_by_id = {str(card.get("id")): card for card in cards if card.get("id")}
    hydrated: list[dict] = []
    hydrated_cards: dict[str, dict] = {}
    for item in products:
        pid = str(item.get("id") or item.get("product_id") or "")
        fact = facts.get(pid) or {}
        if not pid or fact.get("hidden") or not fact.get("name"):
            continue
        row = {**item, **fact, "id": pid, "name": fact["name"]}
        card = card_by_id.get(pid) or _card_for(graph, row)
        entry = {
            "id": pid, "name": str(row.get("name") or ""), "price": row.get("price"),
            "brand": row.get("brand") or "", "image_url": row.get("image_url") or "", "card": card,
        }
        hydrated.append(entry)
        hydrated_cards[pid] = card
    return hydrated, hydrated_cards


def _run_text_search(ctx: ToolContext, request: Any) -> dict | None:
    def invoke() -> dict:
        search = ctx.text_search
        if search is None:
            factory = ctx.text_search_factory
            if factory is None:
                from search_service.service import get_default_service
                factory = get_default_service
            search = factory().recommend
        return search(request)

    try:
        future = _TEXT_SEARCH_EXECUTOR.submit(invoke)
        try:
            return future.result(timeout=ctx.text_search_timeout)
        except FutureTimeoutError:
            future.cancel()
            logger.warning("agent text search unavailable: %s", "TimeoutError")
            return None
    except Exception as exc:
        logger.warning("agent text search unavailable: %s", type(exc).__name__)
        return None
    except BaseException:
        raise


def _search(ctx: ToolContext, args: SearchArgs) -> Any:
    graph, vocab = _graph_runtime(ctx)
    graph_args: dict[str, Any] = {"limit": args.limit}
    extra_q: list[str] = []
    mapped_skin = _vocab_key(getattr(vocab, "skin_types", None), args.skin_type) if args.skin_type else None
    mapped_brand = _vocab_key(getattr(vocab, "brands", None), args.brand) if args.brand else None
    mapped_concerns = [_vocab_key(getattr(vocab, "concerns", None), item) for item in args.concerns]
    if args.skin_type and mapped_skin:
        graph_args["skin_type"] = mapped_skin
    elif args.skin_type:
        extra_q.append(args.skin_type)
    if args.brand and mapped_brand:
        graph_args["brand"] = mapped_brand
    elif args.brand:
        extra_q.append(args.brand)
    if args.concerns:
        graph_args["concerns"] = [mapped for mapped in mapped_concerns if mapped]
        extra_q.extend(item for item, mapped in zip(args.concerns, mapped_concerns) if not mapped)
    if args.price_max is not None:
        graph_args["price_max"] = args.price_max

    # A budget filter drops candidates after the search, so fetch a wider pool first.
    pool = args.limit if args.price_max is None else min(20, args.limit * 4)
    text_result: dict | None = None
    try:
        from search_service.schemas import RecommendRequest
        request = RecommendRequest(q=" ".join([args.query, *extra_q]), limit_per_section=pool)
        text_result = _run_text_search(ctx, request)
    except Exception:
        text_result = None

    graph_result = None
    mapped_filter = bool(mapped_skin or mapped_brand or any(mapped_concerns))
    if graph is not None and mapped_filter:
        graph_result = _graph(ctx, "find_products", graph_args)
        if graph_result.get("error"):
            graph_result = None

    # Over-fetch before hydration: hydration drops hidden/unknown ids and name matching reorders the head.
    text_products = _text_products(text_result or {})[:max(pool * 3, 9)]
    if graph is not None:
        known = {str(item.get("id")) for item in text_products}
        text_products = [
            *({"id": pid} for pid in _catalog_name_ids(ctx, args.query) if pid not in known),
            *text_products,
        ]
    graph_products = list((graph_result or {}).get("products") or [])
    text_products, text_cards = _hydrate_products(graph, text_products, []) if graph is not None else ([], {})
    text_products = _prefer_named(args.query, text_products)
    graph_products, graph_cards = _hydrate_products(graph, graph_products, list((graph_result or {}).get("_cards") or [])) if graph is not None else ([], {})
    if text_result is None and graph_result is None and not text_products:
        return {"error": "search_unavailable"}
    if not text_products and not graph_products:
        return {"error": "search_unavailable"}
    merged: list[dict] = []
    seen: set[str] = set()
    for product in [*text_products, *graph_products]:
        pid = str(product.get("id") or product.get("product_id") or "")
        if pid and pid not in seen:
            seen.add(pid)
            merged.append(product)
    if args.price_max is not None:
        # The text search ignores the budget, so filter here (a product without a known price can't be shown).
        merged = [item for item in merged if (price := product_price(item)) is not None and price <= args.price_max]
        if not merged:
            return {"tool": "find_products", "ok": True, "count": 0, "products": [],
                    "note": "no_product_within_price_max", "price_max": args.price_max}
    merged = merged[:args.limit]
    result = {"tool": "find_products", "ok": True, "count": len(merged), "products": merged[:args.limit]}
    all_cards = {**graph_cards, **text_cards}
    # A second search in the same turn (e.g. "kem" then "gel") often returns the same products again:
    # show only the new ones, and number them after the cards already on screen.
    earlier_in_turn = bool(ctx.turn_shown_ids)
    fresh = [item for item in merged if item.get("id") and str(item["id"]) not in ctx.turn_shown_ids]
    # The chat widget renders payload["products"]; it needs the full card (detail_url, summary) to show the
    # cart/detail buttons, so send the cards there, the same shape compare_products emits.
    cards = [
        {key: value for key, value in all_cards[str(item["id"])].items() if not str(key).startswith("_")}
        for item in fresh if str(item["id"]) in all_cards
    ]
    if cards:
        ctx.emit("ui", {"kind": "product_list", "payload": {**result, "count": len(cards), "products": cards}})
    shown = list(ctx.state.last_shown_products) if earlier_in_turn else []
    shown += [_shown_entry(len(shown) + i, p) for i, p in enumerate(fresh, 1)]
    ctx.state.last_shown_products = shown
    ctx.turn_shown_ids.update(str(item["id"]) for item in fresh)
    return _graph_public(result)


def _shown_entry(idx: int, product: dict) -> dict:
    entry = {"idx": idx, "id": str(product["id"]), "name": str(product.get("name") or "")}
    price = product_price(product)
    if price is not None:
        entry["price"] = price
    return entry


_MAX_CONFLICT_WARNINGS = 3
_MAX_CONFLICT_WARNING_CHARS = 200


def _stale_replaces(exc: PhpError) -> bool:
    if exc.status in (404, 410):
        return True
    return exc.status == 409 and str(getattr(exc, "code", "") or "") != "out_of_stock"


def _card_deadline(card: dict) -> float:
    expires, now = card.get("expires_at"), card.get("server_now")
    numbers = all(isinstance(v, (int, float)) and not isinstance(v, bool) for v in (expires, now))
    return time.time() + (expires - now if numbers else 600)


def _draft_product_ids(ctx: ToolContext, payload: dict) -> list[str]:
    if payload.get("scope") == "whole_cart":
        candidates = [item.get("product_id") or item.get("id") for item in ctx.cart_snapshot if isinstance(item, dict)]
    else:
        candidates = list(payload.get("product_ids") or [])
    ids: list[str] = []
    for candidate in candidates:
        pid = str(candidate or "").strip()
        if pid and pid not in ids:
            ids.append(pid)
    return ids[:6]


def _draft_conflict_warnings(ctx: ToolContext, payload: dict) -> list[str]:
    """Advice strings for the products in a draft; best effort, never blocks the draft."""
    ids = _draft_product_ids(ctx, payload)
    if len(ids) < 2:
        return []
    try:
        result = _graph(ctx, "check_conflicts", {"product_ids": ids})
    except Exception:
        return []
    warnings: list[str] = []
    for pair in (result.get("pairs") if isinstance(result, dict) else None) or []:
        if not isinstance(pair, dict):
            continue
        advice = " ".join(str(pair.get("advice") or "").split())
        if not advice:
            continue
        names = " + ".join(str(pair.get(key) or "").strip() for key in ("a", "b") if pair.get(key))
        text = f"{names}: {advice}" if names else advice
        warnings.append(text[:_MAX_CONFLICT_WARNING_CHARS])
        if len(warnings) >= _MAX_CONFLICT_WARNINGS:
            break
    return warnings


_MAX_LINE_QTY = 20


def _snapshot_key(item: Any) -> str:
    if not isinstance(item, dict):
        return ""
    return str(item.get("id") or item.get("product_id") or "")


def _snapshot_add(ctx: ToolContext, line: Any, fallback_id: str, fallback_qty: int) -> None:
    """Mirror a successful cart add into the turn's cart snapshot (in place), like ChatCartEffect."""
    line = line if isinstance(line, dict) else {}
    pid = str(line.get("id") or line.get("product_id") or fallback_id)
    try:
        qty = int(line.get("qty") or fallback_qty)
    except (TypeError, ValueError):
        qty = fallback_qty
    for existing in ctx.cart_snapshot:
        if _snapshot_key(existing) == pid:
            try:
                current = int(existing.get("qty") or 0)
            except (TypeError, ValueError):
                current = 0
            existing["qty"] = min(_MAX_LINE_QTY, current + qty)
            return
    ctx.cart_snapshot.append({
        "id": pid,
        "name": str(line.get("name") or ""),
        "qty": min(_MAX_LINE_QTY, qty),
        "unit_price": line.get("unit_price") or 0,
    })


def _snapshot_remove(ctx: ToolContext, product_id: str) -> None:
    ctx.cart_snapshot[:] = [item for item in ctx.cart_snapshot if _snapshot_key(item) != str(product_id)]


def _build(ctx: ToolContext, name: str) -> StructuredTool:
    schemas: dict[str, type[BaseModel]] = {
        "search_products": SearchArgs, "get_product": ProductArgs, "compare_products": CompareArgs,
        "check_conflicts": ConflictArgs, "add_to_cart": AddArgs, "remove_from_cart": ProductArgs,
        "prepare_order_draft": DraftArgs, "reorder_from_order": ReorderArgs, "get_order_status": OrderStatusArgs,
    }

    def run(**raw: Any) -> str:
        params = dict(raw)
        if name == "search_products":
            return _guarded(ctx, name, lambda: _search(ctx, SearchArgs.model_validate(raw)), params)
        if name == "get_product":
            return _guarded(ctx, name, lambda: _graph_public(_graph(ctx, "explain_product", {"product_id": raw["product_id"]})), params)
        if name == "compare_products":
            def compare() -> dict:
                results = [_graph(ctx, "explain_product", {"product_id": pid}) for pid in raw["product_ids"]]
                cards = [
                    {key: value for key, value in card.items() if not str(key).startswith("_")}
                    for result in results for card in result.get("_cards") or [] if isinstance(card, dict)
                ]
                result = {"mode": "compare", "products": [_graph_public(item) for item in results]}
                ctx.emit("ui", {"kind": "product_list", "payload": {**result, "products": cards}})
                return result
            return _guarded(ctx, name, compare, params)
        if name == "check_conflicts":
            ids = list(raw.get("product_ids") or [])
            if raw.get("include_cart"):
                cart_ids = [str(item.get("product_id") or item.get("id")) for item in ctx.cart_snapshot
                            if item.get("product_id") or item.get("id")]
                ids.extend(cart_ids[:max(0, 6 - len(ids))])
            if len(ids) < 2:
                return _guarded(ctx, name, lambda: {"conflicts": [], "note": "need_two_products"}, params)
            return _guarded(ctx, name, lambda: _graph_public(_graph(ctx, "check_conflicts", {"product_ids": ids[:6]})), params)
        if name == "view_cart":
            return _guarded(ctx, name, lambda: {"cart": ctx.cart_snapshot}, params)
        if name == "add_to_cart":
            def add() -> dict:
                added = []
                failed = []
                for item in raw["items"]:
                    item_data = item.model_dump() if isinstance(item, BaseModel) else item
                    product_id = item_data["product_id"]
                    try:
                        response = _php(ctx, "internal_agent_cart_add", {"product_id": product_id, "qty": item_data["qty"]})
                        if response.get("effect") is not None:
                            ctx.emit("cart_effect", {"effect": response["effect"]})
                        _snapshot_add(ctx, response.get("line"), str(product_id), int(item_data["qty"]))
                        added.append(response.get("line", response))
                    except PhpError as exc:
                        code = _error_code(exc)
                        if code == "login_required":
                            ctx.emit("ui", {"kind": "login_required", "payload": {}})
                        failed.append({"product_id": product_id, "error": code, "message": exc.message})
                return {"added": added, "failed": failed}
            return _guarded(ctx, name, add, params)
        if name == "remove_from_cart":
            def remove() -> dict:
                response = _php(ctx, "internal_agent_cart_remove", {"product_id": raw["product_id"]})
                if response.get("effect") is not None:
                    ctx.emit("cart_effect", {"effect": response["effect"]})
                _snapshot_remove(ctx, raw["product_id"])
                return response
            return _guarded(ctx, name, remove, params)
        if name == "get_checkout_defaults":
            return _guarded(ctx, name, lambda: _php(ctx, "internal_agent_checkout_defaults", {}), params)
        if name == "prepare_order_draft":
            def draft() -> dict:
                payload = dict(raw)
                if isinstance(payload.get("new_address"), BaseModel):
                    payload["new_address"] = payload["new_address"].model_dump(exclude_none=True)
                payload["replaces"] = ctx.state.open_draft_id
                stored_qty = ctx.state.open_draft_qty
                deadline = ctx.state.open_draft_deadline
                original_qty = raw.get("qty")
                if (
                    not payload.get("qty") and ctx.state.open_draft_id and stored_qty
                    and deadline is not None and time.time() < deadline
                ):
                    # Re-drafting without a qty must not silently drop the quantity the customer already chose,
                    # but only for items still in the cart (confirm removes them) and while the draft is live.
                    in_cart = {
                        str(item.get("product_id") or item.get("id"))
                        for item in ctx.cart_snapshot if isinstance(item, dict)
                    }
                    wanted = in_cart
                    if payload.get("scope") == "mentioned_items" and payload.get("product_ids"):
                        wanted = in_cart & {str(pid) for pid in payload["product_ids"]}
                    carried = {pid: qty for pid, qty in stored_qty.items() if pid in wanted}
                    payload["qty"] = carried or None
                payload["cart_snapshot"] = ctx.cart_snapshot
                payload["conflict_warnings"] = _draft_conflict_warnings(ctx, payload)
                try:
                    response = _php(ctx, "internal_agent_draft", payload)
                except PhpError as exc:
                    if not (payload["replaces"] and _stale_replaces(exc)):
                        raise
                    # The draft we meant to replace is gone (expired, used or unknown): forget it, retry once.
                    ctx.state.open_draft_id = None
                    ctx.state.open_draft_qty = None
                    ctx.state.open_draft_deadline = None
                    payload["qty"] = original_qty  # the old draft is gone, so its quantities are too
                    payload["replaces"] = None
                    response = _php(ctx, "internal_agent_draft", payload)
                card = response.get("card")
                if card is not None:
                    ctx.state.open_draft_id = card.get("draft_id")
                    ctx.state.open_draft_qty = payload.get("qty") or None
                    ctx.state.open_draft_deadline = _card_deadline(card)
                    ctx.emit("ui", {"kind": "draft_card", "payload": card})
                return response
            return _guarded(ctx, name, draft, params)
        if name == "reorder_from_order":
            def reorder() -> dict:
                response = _php(ctx, "internal_agent_reorder", {"order_code": raw["order_code"], "cart_snapshot": ctx.cart_snapshot})
                card = response.get("card")
                if card is not None:
                    ctx.state.open_draft_id = card.get("draft_id")
                    ctx.state.open_draft_qty = None
                    ctx.state.open_draft_deadline = None
                    ctx.emit("ui", {"kind": "draft_card", "payload": card})
                return response
            return _guarded(ctx, name, reorder, params)
        def orders() -> dict:
            response = _php(ctx, "internal_agent_orders", {"order_code": raw.get("order_code")})
            ctx.emit("ui", {"kind": "order_list", "payload": {"orders": response.get("orders", [])}})
            return response
        return _guarded(ctx, name, orders, params)

    return StructuredTool.from_function(run, name=name, description=TOOL_DOCS[name], args_schema=schemas.get(name, _Args))


def build_tools(ctx: ToolContext) -> list[StructuredTool]:
    return [_build(ctx, name) for name in TOOL_DOCS if name in TOOL_SCOPES and _scope_ok(ctx, name)]
