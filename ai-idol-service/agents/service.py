from __future__ import annotations

import datetime as dt
import math
import re
from typing import Any, Callable
from zoneinfo import ZoneInfo

from agents.autopilot import (
    COUNT_RANGE_QUESTION,
    autopilot_limit,
    count_shortfall,
    drop_out_of_stock,
    make_decision,
    next_prime_slot,
    parse_slots,
    query_questions_decision,
    resolve_schedule,
    split_platforms,
)
from agents.intent_classifier import CREATE_CAMPAIGN, PRODUCT_SEARCH, classify_intent
from agents.intent_parser import clean_product_id, fallback_parse, infer_purpose, normalize_text, parse_agent_brief
from agents.command_query import (
    METRIC_LABELS,
    STOCK_FIELDS,
    build_query_plan,
    business_query_requested,
    catalog_search_requested,
    execute_query,
    first_number,
    number,
)
from agents.query_sources import load_catalog, load_query_sources, product_id_filter
from agents.product_resolver import _id_alias, normalize_catalog_product, resolve_products
from config import (
    AGENT_ALLOW_AUTO_PUBLISH,
    AUTOPILOT_DEFAULT_PRODUCTS,
    AUTOPILOT_MAX_PRODUCTS,
    AUTOPILOT_SLOTS,
    ESTIMATED_MINUTES_PER_PRODUCT,
    MIN_LEAD_MINUTES,
    RTMP_TARGETS,
    TIMEZONE,
    logger,
)
from core.agent_runs import get_agent_run, mark_agent_failed, update_agent_run
from core.campaigns import create_campaign, parse_schedule
from core.narration import LIVE_ONLY_STYLES, NARRATION_STYLES, normalize_styles, styles_from_text
from core.purpose import PRODUCT_VIDEO_LENGTH_MODE, has_broadcast
from core.redact import redact_secrets
from core.state_machine import claim_transition, get_db
from providers.llm_provider import LLMProvider


class ConflictError(ValueError):
    """The requested state change lost a race with another transition (HTTP 409)."""


_HIDDEN_STATUSES = {"inactive", "hidden", "disabled", "0"}
_LOCAL_PLATFORMS = {"internal", "preview"}


def _catalog() -> list[dict[str, Any]]:
    return [normalize_catalog_product(item) for item in load_catalog(get_db())
            if str(item.get("trang_thai", item.get("status", ""))).lower() not in _HIDDEN_STATUSES]


def _needs_fields(
    selected: list[dict[str, Any]],
    candidates: list[dict[str, Any]],
    unresolved_ids: list[str],
    reasons: dict[str, str],
    needs: list[str],
) -> dict[str, Any]:
    """Structured hints the UI uses to offer confirm buttons and input boxes."""
    confirmable: list[dict[str, Any]] = []
    seen: set[str] = set()
    for item in [*selected, *candidates]:
        product_id = str(item.get("product_id") or "")
        if product_id and product_id not in seen:
            seen.add(product_id)
            confirmable.append(item)
    return {
        "unresolved_ids": list(unresolved_ids),
        "unresolved_reasons": dict(reasons),
        "confirmable_products": confirmable,
        "needs": list(needs),
    }


def _classify_unresolved(ids: list[str], reasons: dict[str, str], *, lookup: bool) -> dict[str, str]:
    """NOT_FOUND unless the product exists but is hidden/discontinued (INACTIVE)."""
    result = {product_id: reasons.get(product_id, "NOT_FOUND") for product_id in ids}
    if not lookup:
        return result
    try:
        collection = get_db().san_pham
        for product_id in ids:
            doc = collection.find_one(product_id_filter(product_id), {"trang_thai": 1, "status": 1})
            if doc and str(doc.get("trang_thai", doc.get("status", ""))).lower() in _HIDDEN_STATUSES:
                result[product_id] = "INACTIVE"
    except Exception as exc:
        logger.warning("[AI-IDOL-AGENT] Unable to classify unresolved product ids: %s", exc)
    return result


def _unresolved_warning(product_id: str, reason: str) -> str:
    if reason == "INACTIVE":
        return f"Mã {product_id} đang ẩn/ngừng bán."
    return f"Không tìm thấy mã {product_id} trong catalog đang bán (đã đối chiếu mã sản phẩm, id và _id)."


def _earliest_start(product_count: int, now: dt.datetime) -> dt.datetime:
    """First moment a livestream of ``product_count`` products can start: preparation time plus a 15 minute margin."""
    required_minutes = max(
        60,
        int(math.ceil(max(MIN_LEAD_MINUTES, product_count * ESTIMATED_MINUTES_PER_PRODUCT) + 15)),
    )
    return now + dt.timedelta(minutes=required_minutes)


def _safe_schedule(value: str | None, product_count: int, *, now: dt.datetime | None = None) -> dt.datetime:
    local_now = now or dt.datetime.now(ZoneInfo(TIMEZONE))
    earliest = _earliest_start(product_count, local_now)
    if value:
        try:
            parsed = parse_schedule(value).astimezone(ZoneInfo(TIMEZONE))
            if parsed >= earliest:
                return parsed
        except (TypeError, ValueError):
            pass
    rounded = earliest + dt.timedelta(minutes=(5 - earliest.minute % 5) % 5)
    return rounded.replace(second=0, microsecond=0)


def _renderer_warnings(renderer_check: Callable[[], dict[str, Any]] | None) -> list[str]:
    """Non-blocking: a down renderer parks the jobs, it never stops planning."""
    if renderer_check is None:
        return []
    try:
        if renderer_check().get("ok"):
            return []
    except Exception as exc:
        logger.warning("[AI-IDOL-AGENT] Renderer health check failed: %s", exc)
    return ["Syna đang tắt. Chương trình vẫn được tạo; video sẽ tự tạo tiếp khi bạn bật Syna (scripts/start-ai-idol-syna.ps1)."]


def _build_campaign_name(campaign_name: str, selected: list[dict[str, Any]], scheduled_at: dt.datetime | None) -> str:
    if campaign_name:
        return campaign_name[:160]
    if len(selected) == 1:
        return f"Syna giới thiệu {selected[0]['name']}"
    if scheduled_at is None:
        return f"Syna giới thiệu {len(selected)} sản phẩm"
    return f"Syna tư vấn {len(selected)} sản phẩm · {scheduled_at:%d/%m %H:%M}"


# --- Autopilot helpers (runs with mode == "autopilot"; the classic path never calls them) -------------------------

_STYLE_LABELS = {key: label for key, label, _, _ in NARRATION_STYLES}
# What the query layer reports as a gap, in words the admin understands.
_MISSING_DATA_LABELS = {
    "lifetime_sales": "doanh số toàn kỳ",
    "expiry_or_batch_quantity": "hạn dùng/số lượng theo lô",
    "stock": "tồn kho",
    "price": "giá bán",
    "brand": "thương hiệu",
    "growth_baseline": "số liệu kỳ trước",
    "invalid_conversion_cohort": "số liệu chuyển đổi",
    "comparison_sample": "mẫu so sánh",
}


def _local_today(now: dt.datetime | None) -> dt.date | None:
    if now is None:
        return None
    return (now.astimezone(ZoneInfo(TIMEZONE)) if now.tzinfo else now).date()


def _catalog_rows_by_id(raw_catalog: list[dict[str, Any]]) -> dict[str, dict[str, Any]]:
    """Raw catalog rows by every id form, a primary ma_san_pham beating another row's alias (as resolve_products does)."""
    normalized = [(normalize_catalog_product(item), item) for item in raw_catalog]
    by_id: dict[str, dict[str, Any]] = {}
    for product, item in normalized:
        for alias in product["aliases"]:
            by_id.setdefault(alias.lower(), item)
    for product, item in normalized:
        if product["id"]:
            by_id[product["id"].lower()] = item
    return by_id


def _stored_stock(product_id: str) -> float | None:
    try:
        document = get_db().san_pham.find_one(product_id_filter(product_id), dict.fromkeys(STOCK_FIELDS, 1))
    except Exception as exc:
        logger.warning("[AI-IDOL-AGENT] Unable to read stock of product %s: %s", product_id, exc)
        return None
    return first_number(document, STOCK_FIELDS) if document else None


def _stock_levels(selected: list[dict[str, Any]], raw_catalog: list[dict[str, Any]] | None) -> dict[str, float | None]:
    """Stock per product id: the query's evidence, else the raw catalog row, else the san_pham document; None if unknown."""
    rows: dict[str, dict[str, Any]] | None = None
    levels: dict[str, float | None] = {}
    for item in selected:
        product_id = str(item.get("product_id") or "")
        if not product_id:
            continue
        stock = number((item.get("evidence") or {}).get("stock"))
        if stock is None and raw_catalog:
            if rows is None:
                rows = _catalog_rows_by_id(raw_catalog)
            row = rows.get(product_id.lower())
            stock = first_number(row, STOCK_FIELDS) if row else None
        if stock is None:
            stock = _stored_stock(product_id)
        levels[product_id] = stock
    return levels


def _unique_text(values) -> list[str]:
    return [text for text in dict.fromkeys(str(value).strip() for value in values or []) if text]


def _autopilot_stop(
    run_id: str,
    decisions: list[dict[str, Any]],
    decision: dict[str, Any],
    plan: dict[str, Any],
    warnings=(),
) -> dict[str, Any]:
    """End a run that cannot go on: log the stop (with the given warnings after its message) and park it for the admin."""
    extra = _unique_text(warnings)
    stop = {**decision, "message": redact_secrets(" ".join([decision["message"], *extra]))} if extra else decision
    decisions.append(stop)
    plan = {"resolved_products": [], "candidate_products": [], **plan, "warnings": [stop["message"], *extra]}
    update_agent_run(run_id, {
        "status": "NEEDS_INPUT",
        "current_stage": "AUTOPILOT_STOPPED",
        "plan": plan,
        "decisions": decisions,
        "error_message": None,
    })
    return get_agent_run(run_id) or plan


def _apply_autopilot_limit(query_plan: dict[str, Any], decisions: list[dict[str, Any]]) -> None:
    """Default or cap the product count; the planner's 1-50 range question is answered by this cap."""
    limit, decision = autopilot_limit(
        query_plan["limit"], bool(query_plan.get("limit_explicit")),
        default=AUTOPILOT_DEFAULT_PRODUCTS, maximum=AUTOPILOT_MAX_PRODUCTS,
    )
    if decision:
        decisions.append(decision)
    query_plan["limit"] = limit
    query_plan["questions"] = [item for item in query_plan["questions"] if not str(item).startswith(COUNT_RANGE_QUESTION)]
    query_plan["summary"] = [
        f"Số lượng: tối đa {limit} sản phẩm" if str(line).startswith("Số lượng:") else line
        for line in query_plan.get("summary") or []
    ]


def _missing_data_message(query_plan: dict[str, Any], missing_data: dict[str, Any]) -> str:
    window = query_plan.get("window_days")
    labels = []
    for key in missing_data:
        label = f"số liệu bán hàng {window} ngày" if key == "sales_snapshot" and window else (
            "số liệu bán hàng theo kỳ" if key == "sales_snapshot" else _MISSING_DATA_LABELS.get(key, "dữ liệu xác minh"))
        if label not in labels:
            labels.append(label)
    return f"Một số sản phẩm thiếu {', '.join(labels)}; xếp hạng chỉ áp dụng trên các sản phẩm đủ số liệu."


# Rankings that read a per-period sales snapshot (window_days set); lifetime sales are the fallback for all of them.
_WINDOWED_RANKS = frozenset({"units", "revenue_low_conversion", "views_no_orders", "cart", "discount_slow", "growth"})
_LIFETIME_PERIOD = "Kỳ thống kê: toàn kỳ theo số bán trong catalog (không suy ra 30 ngày)"


def _lifetime_sales_fallback(
    query_plan: dict[str, Any],
    query_result: dict[str, Any],
    products: list[dict[str, Any]],
    batches: list[dict[str, Any]] | None,
    brief_text: str,
) -> tuple[dict[str, Any], dict[str, Any]] | None:
    """Without snapshots for the period, rank by lifetime sales instead: (new query result, decision), else None.

    Only when the windowed ranking left the list short because snapshots are missing, and only when
    the lifetime ranking finds more products than the period one did.
    """
    rank, window = query_plan["rank_by"], query_plan.get("window_days")
    if (
        rank not in _WINDOWED_RANKS or not window
        or "sales_snapshot" not in query_result["missing_data"]
        or len(query_result["selected"]) >= query_plan["limit"]
    ):
        return None
    fallback_plan = {
        **query_plan, "rank_by": "units", "window_days": None,
        "summary": [
            f"Xếp hạng: {METRIC_LABELS['units']}" if str(line).startswith("Xếp hạng:") else line
            for line in query_plan.get("summary") or []
        ],
        "assumptions": [
            *(item for item in query_plan.get("assumptions") or [] if not str(item).startswith("Kỳ thống kê:")),
            _LIFETIME_PERIOD,
        ],
    }
    fallback = execute_query(fallback_plan, products, batches=batches, snapshots=[], brief=brief_text)
    if len(fallback["selected"]) <= len(query_result["selected"]):
        return None
    message = f"Chưa có số liệu {window} ngày cho tiêu chí “{METRIC_LABELS[rank]}”; xếp theo doanh số toàn kỳ thay thế."
    return fallback, make_decision("METRICS_FALLBACK", "adjusted", message)


def _autopilot_query_outcome(
    query_plan: dict[str, Any],
    query_result: dict[str, Any],
    decisions: list[dict[str, Any]],
    products: list[dict[str, Any]],
    batches: list[dict[str, Any]] | None,
    brief_text: str,
) -> tuple[dict[str, Any], dict[str, Any] | None]:
    """Autopilot makes what it found: (query result to use, stop decision if it found nothing at all).

    A short or incomplete result is explained rather than stopping; missing period figures fall back to lifetime sales.
    """
    replaced = _lifetime_sales_fallback(query_plan, query_result, products, batches, brief_text)
    if replaced:
        query_result, fallback_decision = replaced
        decisions.append(fallback_decision)
    shortfall = count_shortfall(query_plan["limit"], len(query_result["selected"]))
    if shortfall and shortfall["level"] == "stopped":
        return query_result, shortfall
    if shortfall:
        decisions.append(shortfall)
    if query_result["missing_data"]:
        # Nothing was replaced for these gaps: say so plainly, never as a fallback.
        decisions.append(make_decision(
            "DATA_GAPS", "adjusted", _missing_data_message(query_plan, query_result["missing_data"])))
    return query_result, None


def _without_constraint_mismatches(
    brief_text: str,
    brief,
    resolution: dict[str, Any],
    products: list[dict[str, Any]],
    decisions: list[dict[str, Any]],
) -> dict[str, Any]:
    """resolve_products empties the whole selection on a mismatch; resolve again for the ids that were not at fault."""
    mismatches = resolution["constraint_mismatches"]
    at_fault = {item["product_id"] for item in mismatches}
    ids: dict[str, str] = {}
    normalized = [normalize_catalog_product(item) for item in products]
    for product in normalized:
        for alias in product["aliases"]:
            ids.setdefault(alias.lower(), product["id"])
    for product in normalized:
        if product["id"]:
            ids[product["id"].lower()] = product["id"]
    keep = [raw for raw in brief.explicit_product_ids if ids.get(_id_alias(raw).lower()) not in at_fault]
    names = ", ".join(f"{item['name']} (mã {item['product_id']})" for item in mismatches)
    active = ", ".join(resolution.get("constraints") or [])
    decisions.append(make_decision(
        "CONSTRAINT_DROPPED", "adjusted", f"Bỏ sản phẩm không đáp ứng điều kiện hoạt chất {active}: {names}."))
    if not keep:  # never let a name or fuzzy match stand in for ids the admin typed
        return {**resolution, "selected": [], "ambiguous": [], "unresolved_ids": [], "unresolved_reasons": {},
                "constraint_mismatches": []}
    return resolve_products(brief_text, keep, brief.product_queries, products)


def _autopilot_pick(
    brief,
    resolution: dict[str, Any],
    unresolved_reasons: dict[str, str],
    popularity_unverified: bool,
    decisions: list[dict[str, Any]],
) -> tuple[list[dict[str, Any]], dict[str, Any] | None, list[str]]:
    """Decide the products without asking: (selected, stop decision if nothing is left, warnings for the stop)."""
    selected = list(resolution["selected"])
    warnings: list[str] = []
    unresolved = list(resolution["unresolved_ids"])
    if unresolved:
        parts = ", ".join(
            f"{item} ({'đang ẩn/ngừng bán' if unresolved_reasons.get(item) == 'INACTIVE' else 'không tìm thấy trong catalog đang bán'})"
            for item in unresolved
        )
        decisions.append(make_decision("IDS_DROPPED", "adjusted", f"Bỏ mã không dùng được: {parts}."))
        warnings.extend(_unresolved_warning(item, unresolved_reasons.get(item, "NOT_FOUND")) for item in unresolved)
    if not selected and not brief.explicit_product_ids and resolution["ambiguous"]:
        picked = resolution["ambiguous"][0]
        selected = [picked]
        decisions.append(make_decision(
            "AMBIGUOUS_PICKED", "adjusted",
            f"Tên sản phẩm chưa rõ nên chọn “{picked['name']}” (mã {picked['product_id']}), ứng viên khớp nhất.",
        ))
    if selected and popularity_unverified:
        decisions.append(make_decision(
            "METRICS_FALLBACK", "adjusted",
            "Catalog chưa có doanh số để xác minh sản phẩm bán chạy; chọn theo mức khớp với lệnh.",
        ))
    if not selected:
        return [], count_shortfall(1, 0), warnings
    return selected, None, warnings


_EXPLICIT_DATE = re.compile(r"\b(\d{1,2})/(\d{1,2})/(20\d{2})\b")
_TOMORROW = re.compile(r"\b(?:ngay|toi|sang) mai\b")
_HOUR_TOKEN = re.compile(r"\b\d{1,2}(?:h\b|h\d|\s*gio\b|:\d{2})")


def _requested_day(text: str, now: dt.datetime) -> dt.date | None:
    """The day a prompt asks for without an hour: dd/mm/yyyy, or tomorrow ("ngày/tối/sáng mai"). ``text`` is accent-stripped."""
    match = _EXPLICIT_DATE.search(text)
    if match:
        day, month, year = map(int, match.groups())
        try:
            return dt.date(year, month, day)
        except ValueError:
            return None
    if _TOMORROW.search(text):
        return _local_today(now) + dt.timedelta(days=1)
    return None


def _schedule_on_day(
    day: dt.date, earliest: dt.datetime, slots: list[dt.time], tz: ZoneInfo, unreadable: bool,
) -> tuple[dt.datetime, dict[str, Any]]:
    """The first golden slot on ``day`` that leaves enough preparation time; a day already gone starts at ``earliest``."""
    if earliest.tzinfo is None:
        earliest = earliest.replace(tzinfo=tz)
    when = next_prime_slot(max(earliest, dt.datetime.combine(day, dt.time(0, 0), tzinfo=tz)), slots, tz)
    shown, clock = f"{day:%d/%m}", f"{when:%H:%M %d/%m}"
    if day < earliest.astimezone(tz).date():
        message = f"Ngày {shown} trong prompt đã qua; chọn giờ vàng {clock}."
    elif when.date() == day:
        missing = "không đọc được giờ" if unreadable else "không có giờ"
        message = f"Prompt có ngày {shown} nhưng {missing}; chọn giờ vàng {clock}."
    else:
        message = f"Ngày {shown} không còn khung giờ vàng đủ thời gian chuẩn bị; chọn giờ vàng {clock}."
    return when, make_decision("SLOT_PICKED", "adjusted", message)


def _autopilot_schedule(
    value: str | None, product_count: int, now: dt.datetime, unreadable: bool, day: dt.date | None = None,
) -> tuple[dt.datetime, dict[str, Any]]:
    """The broadcast time: the requested one if it is still reachable, else the nearest golden slot / earliest start.

    ``day`` is a date the prompt gave without a usable hour: the slot is searched on that day, not from now.
    """
    tz = ZoneInfo(TIMEZONE)
    requested = None
    if value:
        try:
            requested = parse_schedule(value).astimezone(tz)
        except (TypeError, ValueError):
            unreadable = True
    earliest = _earliest_start(product_count, now)
    if requested is None and day is not None:
        return _schedule_on_day(day, earliest, parse_slots(AUTOPILOT_SLOTS), tz, unreadable)
    return resolve_schedule(requested, earliest, parse_slots(AUTOPILOT_SLOTS), tz, unreadable=unreadable)


def _autopilot_styles(brief_text: str, purpose: str) -> tuple[list[str], list[dict[str, Any]]]:
    """Narration styles named in the prompt, minus those this purpose cannot say, each change explained."""
    styles, dropped = normalize_styles(styles_from_text(brief_text), purpose)
    decisions: list[dict[str, Any]] = []
    if styles:
        names = ", ".join(_STYLE_LABELS[key].lower() for key in styles)
        decisions.append(make_decision("STYLES_APPLIED", "done", f"Áp dụng phong cách dẫn: {names}."))
    if any(key in LIVE_ONLY_STYLES for key in dropped):
        decisions.append(make_decision(
            "STYLES_PRICE_DROPPED", "adjusted",
            "Bỏ phong cách tập trung chốt sale vì video sản phẩm không kêu gọi chốt đơn."))
    conflict = [key for key in dropped if key not in LIVE_ONLY_STYLES]
    if conflict:
        kept = next((key for key in styles if key in {"concise", "detailed"}), None)
        names = ", ".join(_STYLE_LABELS[key].lower() for key in conflict)
        decisions.append(make_decision(
            "STYLES_CONFLICT_DROPPED", "adjusted",
            f"Bỏ phong cách {names}" + (f", giữ {_STYLE_LABELS[kept].lower()}" if kept else "")
            + " vì hai phong cách này loại trừ nhau.",
        ))
    return styles, decisions


def process_agent_run(
    run_id: str,
    *,
    enqueue_campaign: Callable[[str], Any] | None = None,
    llm=None,
    catalog: list[dict[str, Any]] | None = None,
    batches: list[dict[str, Any]] | None = None,
    snapshots: list[dict[str, Any]] | None = None,
    renderer_check: Callable[[], dict[str, Any]] | None = None,
    now: dt.datetime | None = None,
) -> dict[str, Any]:
    run = get_agent_run(run_id)
    if not run:
        raise ValueError("Agent run not found")
    if run.get("status") == "CANCELLED":
        return run
    if run.get("campaign_id"):
        return run

    # Autopilot (Studio "Tự động") decides what the classic path would ask the admin, and records each decision.
    autopilot = run.get("mode") == "autopilot"
    decisions: list[dict[str, Any]] = []
    # ``now`` pins the clock for tests; without it every time-dependent call keeps its own default.
    clock = {} if now is None else {"now": now}
    query_clock = {} if now is None else {"today": _local_today(now)}
    schedule_now = now if now is not None else dt.datetime.now(ZoneInfo(TIMEZONE))

    update_agent_run(run_id, {
        "status": "INTERPRETING",
        "current_stage": "CLASSIFYING_INTENT",
        "error_message": None,
    })
    try:
        classification = classify_intent(run["brief"])
        classification_data = classification.to_dict()
        update_agent_run(run_id, {
            "intent_classification": classification_data,
            "current_stage": "CLASSIFYING_INTENT",
        })
        overrides = run.get("overrides") or {}
        override_ids = list(dict.fromkeys(
            cleaned for cleaned in (clean_product_id(item) for item in (overrides.get("explicit_product_ids") or []))
            if cleaned
        ))
        query_result = None
        if autopilot:
            if classification.intent not in {CREATE_CAMPAIGN, PRODUCT_SEARCH}:
                reason = classification.reason.rstrip(".")
                return _autopilot_stop(run_id, decisions, make_decision(
                    "INTENT_UNSUPPORTED", "stopped",
                    f"Prompt chưa phải yêu cầu tạo video/livestream ({reason}). Hãy ghi rõ sản phẩm và việc cần làm, "
                    "ví dụ “Làm video giới thiệu sản phẩm mã 995”; việc hủy, duyệt hay thử lại hãy làm trong bảng Hoạt động.",
                ), {"intent_classification": classification_data, "brief": run["brief"]})
            if classification.intent == PRODUCT_SEARCH:
                decisions.append(make_decision(
                    "INTENT_CREATE", "done", "Coi lệnh tìm sản phẩm này là yêu cầu làm video giới thiệu sản phẩm."))
        # Product ids chosen/typed by the staff replace the business query.
        if (
            not override_ids
            and classification.intent in {CREATE_CAMPAIGN, PRODUCT_SEARCH}
            and (business_query_requested(run["brief"])
                 # A plain "Tìm <danh mục>" is read-only; it never widens a create command.
                 or (classification.intent == PRODUCT_SEARCH and catalog_search_requested(run["brief"])))
        ):
            query_plan = build_query_plan(run["brief"], **query_clock)
            if autopilot:
                unclear = query_questions_decision(query_plan["questions"])
                if unclear:
                    return _autopilot_stop(run_id, decisions, unclear, {
                        "intent_classification": classification_data, "brief": run["brief"],
                        "query_result": execute_query(query_plan, [], brief=run["brief"]),
                    })
                _apply_autopilot_limit(query_plan, decisions)
            update_agent_run(run_id, {"current_stage": "QUERYING_PRODUCTS"})
            # Clarification does not require a database read or an LLM request.
            products = [] if query_plan["questions"] else catalog if catalog is not None else load_catalog(get_db())
            if not query_plan["questions"] and catalog is None:
                batches, snapshots = load_query_sources(get_db(), query_plan)
            query_result = execute_query(query_plan, products, batches=batches, snapshots=snapshots, brief=run["brief"])
            plan = {
                "intent_classification": classification_data, "brief": run["brief"],
                "query_result": query_result, "resolved_products": query_result["selected"],
                "candidate_products": [], "warnings": query_result["warnings"],
            }
            is_query_only = classification.intent == PRODUCT_SEARCH
            enough = len(query_result["selected"]) == query_plan["limit"]
            if autopilot:
                query_result, empty = _autopilot_query_outcome(
                    query_plan, query_result, decisions, products, batches, run["brief"])
                plan.update(query_result=query_result, resolved_products=query_result["selected"],
                            warnings=query_result["warnings"])
                if empty:
                    return _autopilot_stop(run_id, decisions, empty, plan, query_result["warnings"])
            elif is_query_only or not query_result["complete"] or not enough:
                if not is_query_only and query_result["status"] == "RESULTS_READY":
                    plan["warnings"] = [*plan["warnings"], "Chưa tạo livestream: thiếu sản phẩm hoặc dữ liệu chưa đủ bao phủ. Hãy sửa số lượng/bộ lọc hoặc chọn thủ công trong Wizard."]
                final_status = query_result["status"] if is_query_only else "NEEDS_INPUT"
                if final_status == "NEEDS_INPUT":
                    plan.update(_needs_fields(query_result["selected"], [], [], {}, ["product"]))
                update_agent_run(run_id, {
                    "status": final_status,
                    "current_stage": "QUERY_RESULTS", "plan": plan, "error_message": None,
                })
                return get_agent_run(run_id) or plan

        if classification.intent != CREATE_CAMPAIGN and not autopilot:
            plan = {
                "intent_classification": classification_data,
                "brief": run["brief"],
                "resolved_products": [],
                "candidate_products": [],
                "warnings": [
                    "Agent chưa tạo campaign vì lệnh này không phải yêu cầu tạo livestream.",
                    classification.next_step,
                ],
                **_needs_fields([], [], [], {}, ["intent"]),
            }
            update_agent_run(run_id, {
                "status": "NEEDS_INPUT",
                "current_stage": "INTENT_REVIEW",
                "plan": plan,
                "error_message": None,
            })
            return get_agent_run(run_id) or plan

        update_agent_run(run_id, {"current_stage": "INTERPRETING"})
        if query_result is not None:
            brief = fallback_parse(run["brief"], run.get("overrides") or {}, **clock)
        else:
            provider = llm if llm is not None else LLMProvider()
            brief = parse_agent_brief(run["brief"], run.get("overrides") or {}, llm=provider, **clock)
        text = normalize_text(run["brief"])
        deterministic = fallback_parse(run["brief"], run.get("overrides") or {}, **clock)
        clock_text = re.sub(r"(?:keo dai|thoi luong|trong|chay)\s*\d{1,2}\s*(?:gio|hours?)", "", text)
        explicit_clock = re.search(r"\b\d{1,2}(?:h\b|h\d|\s*gio\b|:\d{2})|\b\d{1,2}/\d{1,2}/20\d{2}\b", clock_text)
        # Product ids: staff overrides win, then the deterministic regex. An id
        # returned by the LLM survives only if it literally appears in the brief.
        if override_ids:
            explicit_ids = override_ids
        else:
            explicit_ids = list(deterministic.explicit_product_ids)
            for raw_id in brief.explicit_product_ids:
                token = clean_product_id(raw_id)
                if token and token not in explicit_ids and re.search(
                    rf"(?<![a-z0-9]){re.escape(token)}(?![a-z0-9])", text
                ):
                    explicit_ids.append(token)
        brief.explicit_product_ids = explicit_ids
        # A clock time nobody can read is not a reason to stop autopilot: it picks a golden slot instead.
        unreadable_clock = bool(
            autopilot and explicit_clock and not deterministic.scheduled_at
            and not (run.get("overrides") or {}).get("scheduled_at")
        )
        if deterministic.scheduled_at:
            brief.scheduled_at = deterministic.scheduled_at
        elif unreadable_clock:
            brief.scheduled_at = None
        elif explicit_clock and not (run.get("overrides") or {}).get("scheduled_at"):
            update_agent_run(run_id, {
                "status": "NEEDS_INPUT", "current_stage": "SCHEDULE_REVIEW",
                "plan": {"intent_classification": classification_data, "query_result": query_result,
                         "resolved_products": query_result["selected"] if query_result else [],
                         "warnings": ["Chưa đọc được ngày/giờ hợp lệ. Hãy ghi rõ như: lúc 19h ngày 30/9/2026; thời lượng 60 phút."],
                         "candidate_products": [],
                         **_needs_fields(query_result["selected"] if query_result else [], [], [], {}, ["schedule"])},
            })
            return get_agent_run(run_id) or {}
        # A date with no usable hour (dd/mm/yyyy, "ngày mai") is still the day the admin asked for.
        requested_day = _requested_day(text, schedule_now) if autopilot and not brief.scheduled_at else None
        if requested_day is not None and not _HOUR_TOKEN.search(clock_text):
            unreadable_clock = False  # the date alone tripped explicit_clock; there is no hour to be unreadable
        if autopilot:
            brief.autonomy_policy = "auto_preview"  # whatever the overrides say, autopilot never waits for a review
        update_agent_run(run_id, {
            "normalized_brief": brief.to_dict(),
            "current_stage": "RESOLVING_PRODUCTS",
        })

        products = catalog if catalog is not None else _catalog() if query_result is None else []
        resolution = {"selected": query_result["selected"], "unresolved_ids": [], "ambiguous": []} if query_result is not None else resolve_products(
            run["brief"],
            brief.explicit_product_ids,
            brief.product_queries,
            products,
        )
        if autopilot and resolution.get("constraint_mismatches"):
            resolution = _without_constraint_mismatches(run["brief"], brief, resolution, products, decisions)
        selected = resolution["selected"]
        unresolved_ids = resolution["unresolved_ids"]
        unresolved_reasons = _classify_unresolved(
            unresolved_ids,
            resolution.get("unresolved_reasons") or {},
            lookup=bool(unresolved_ids) and catalog is None and query_result is None,
        )
        constraint_mismatches = resolution.get("constraint_mismatches") or []
        popularity_unverified = bool(
            resolution.get("popularity_requested")
            and not resolution.get("popularity_data_available")
            and not brief.explicit_product_ids
        )
        if autopilot:
            # Drop what cannot be used and carry on with the rest; only an empty selection stops the run.
            selected, empty, empty_warnings = _autopilot_pick(
                brief, resolution, unresolved_reasons, popularity_unverified, decisions)
            if empty:
                return _autopilot_stop(run_id, decisions, empty, {
                    "intent_classification": classification_data, "brief": brief.to_dict(),
                    "candidate_products": resolution["ambiguous"],
                    "constraints": resolution.get("constraints", []),
                }, empty_warnings)
            unresolved_ids, constraint_mismatches, popularity_unverified = [], [], False
        if not selected or unresolved_ids or constraint_mismatches or popularity_unverified:
            warnings = ["Cần xác nhận sản phẩm trước khi tạo kịch bản."]
            warnings.extend(_unresolved_warning(item, unresolved_reasons[item]) for item in unresolved_ids)
            if resolution.get("constraints"):
                warnings.append(
                    "Chỉ được chọn sản phẩm đáp ứng: " + ", ".join(resolution["constraints"])
                )
            if constraint_mismatches:
                warnings.append("Mã sản phẩm đã nhập không đáp ứng điều kiện hoạt chất.")
            if popularity_unverified:
                warnings.append(
                    "Catalog chưa có dữ liệu doanh số để xác minh sản phẩm bán chạy; chưa tự động lên lịch."
                )
            plan = {
                "intent_classification": classification_data,
                "brief": brief.to_dict(),
                "resolved_products": selected,
                "candidate_products": resolution["ambiguous"],
                **_needs_fields(selected, resolution["ambiguous"], unresolved_ids, unresolved_reasons, ["product"]),
                "constraints": resolution.get("constraints", []),
                "constraint_mismatches": constraint_mismatches,
                "popularity_requested": bool(resolution.get("popularity_requested")),
                "popularity_data_available": bool(resolution.get("popularity_data_available")),
                "warnings": warnings,
            }
            update_agent_run(run_id, {
                "status": "NEEDS_INPUT",
                "current_stage": "RESOLVING_PRODUCTS",
                "plan": plan,
                "error_message": None,
            })
            return get_agent_run(run_id) or plan

        policy = brief.autonomy_policy
        if policy == "auto_publish" and not AGENT_ALLOW_AUTO_PUBLISH:
            update_agent_run(run_id, {
                "status": "NEEDS_INPUT",
                "current_stage": "POLICY_GATE",
                "plan": {
                    "intent_classification": classification_data,
                    "brief": brief.to_dict(),
                    "resolved_products": selected,
                    "candidate_products": [],
                    "warnings": [
                        "AUTO_PUBLISH đang khóa. Hãy dùng AUTO_PREVIEW hoặc bật cấu hình sau khi hoàn tất kiểm thử."
                    ],
                    **_needs_fields(selected, [], [], {}, ["platform"]),
                },
                "error_message": None,
            })
            return get_agent_run(run_id) or {}

        purpose = brief.purpose or infer_purpose(normalize_text(run["brief"]), brief.scheduled_at)
        live = has_broadcast(purpose)
        if autopilot and live:
            # A sold-out product cannot be sold on air; its product video is unaffected.
            before = len(selected)
            selected, sold_out = drop_out_of_stock(selected, _stock_levels(selected, catalog), purpose)
            if sold_out:
                decisions.append(sold_out)
            if not selected:
                return _autopilot_stop(run_id, decisions, make_decision(
                    "NO_PRODUCTS", "stopped",
                    f"Cả {before} sản phẩm đã chọn đều hết hàng nên không thể livestream; "
                    "hãy chọn sản phẩm còn hàng hoặc làm video sản phẩm.",
                ), {"intent_classification": classification_data, "brief": brief.to_dict(), "purpose": purpose})
        # A product video is never broadcast: no platform, RTMP or schedule checks apply.
        platforms = brief.platforms if live else []
        if live and policy == "auto_preview":
            platforms = ["internal"]
        pending_external: list[str] = []
        if autopilot and live:
            # Livestreams start on the internal channel; YouTube/Facebook wait for the admin's permission.
            mentioned = list(dict.fromkeys([*brief.platforms, *deterministic.platforms]))
            platforms, pending_external, platform_decisions = split_platforms(mentioned, set(RTMP_TARGETS))
            decisions.extend(platform_decisions)
        missing_rtmp = [item for item in platforms if item not in _LOCAL_PLATFORMS and item not in RTMP_TARGETS]
        if missing_rtmp:
            update_agent_run(run_id, {
                "status": "NEEDS_INPUT", "current_stage": "POLICY_GATE",
                "plan": {
                    "intent_classification": classification_data,
                    "brief": brief.to_dict(),
                    "resolved_products": selected,
                    "candidate_products": [],
                    "platforms": platforms,
                    "warnings": [
                        f"Chưa cấu hình RTMP cho {item}. Chọn phát nội bộ hoặc cấu hình trước."
                        for item in missing_rtmp
                    ],
                    **_needs_fields(selected, [], [], {}, ["platform"]),
                },
                "error_message": None,
            })
            return get_agent_run(run_id) or {}
        if autopilot and live:
            scheduled_at, schedule_decision = _autopilot_schedule(
                brief.scheduled_at, len(selected), schedule_now, unreadable_clock, requested_day)
            decisions.append(schedule_decision)
        else:
            scheduled_at = _safe_schedule(brief.scheduled_at, len(selected)) if live else None
        # An explicit appointment is not permission to silently move it.
        if not autopilot and live and brief.scheduled_at and parse_schedule(brief.scheduled_at).astimezone(ZoneInfo(TIMEZONE)) != scheduled_at:
            update_agent_run(run_id, {
                "status": "NEEDS_INPUT", "current_stage": "SCHEDULE_REVIEW",
                "plan": {"intent_classification": classification_data, "resolved_products": selected,
                         "query_result": query_result, "candidate_products": [],
                         "scheduled_at": scheduled_at.isoformat(),
                         "warnings": ["Giờ yêu cầu đã qua hoặc không đủ thời gian chuẩn bị. Đây chỉ là lịch đề xuất; hãy chọn lại lịch phát, chưa tạo chương trình."],
                         **_needs_fields(selected, [], [], {}, ["schedule"])},
            })
            return get_agent_run(run_id) or {}
        product_ids = [item["product_id"] for item in selected]
        plan = {
            "intent_classification": classification_data,
            "brief": brief.to_dict(),
            "resolved_products": selected,
            "candidate_products": [],
            "scheduled_at": scheduled_at.isoformat() if scheduled_at else None,
            "purpose": purpose,
            "platforms": platforms,
            "autonomy_policy": policy,
            "approval_mode": "human" if policy == "assisted" else "auto",
            "warnings": _renderer_warnings(renderer_check),
            "query_result": query_result,
        }
        styles: list[str] = []
        if autopilot:
            styles, style_decisions = _autopilot_styles(run["brief"], purpose)
            decisions.extend(style_decisions)
            plan.update(pending_external_platforms=pending_external, narration_styles=styles)
        update_agent_run(run_id, {"status": "PLAN_READY", "current_stage": "PLAN_READY", "plan": plan})

        campaign_payload = {
            "name": _build_campaign_name(brief.campaign_name, selected, scheduled_at),
            "product_ids": product_ids,
            "scheduled_at": scheduled_at.isoformat() if scheduled_at else None,
            "purpose": purpose,
            "platforms": platforms,
            "duration_minutes": brief.duration_minutes,
            "content_mode": brief.content_mode,
            "idempotency_key": f"agent:{run_id}",
            "configuration": {
                "format": "16:9",
                "length_mode": "Short (30-60s)" if live else PRODUCT_VIDEO_LENGTH_MODE,
                "avatar_mode": "syna_3d",
                "agent_managed": True,
                "agent_run_id": run_id,
                "agent_autonomy_policy": policy,
                "script_approval_mode": "human" if policy == "assisted" else "auto",
            },
        }
        if autopilot:
            campaign_payload["configuration"].update(agent_mode="autopilot", narration_styles=styles)
            campaign_payload["pending_external_platforms"] = pending_external
        try:
            campaign_id, created = create_campaign(campaign_payload, created_by=run.get("created_by"))
        except ValueError as exc:
            if autopilot:
                return _autopilot_stop(run_id, decisions, make_decision(
                    "CAMPAIGN_REJECTED", "stopped", f"Không tạo được chương trình: {exc}",
                ), plan)
            # Input/validation problem, not infrastructure: ask the staff, never retry.
            logger.warning("[AI-IDOL-AGENT] Run %s rejected by campaign validation: %s", run_id, exc)
            update_agent_run(run_id, {
                "status": "NEEDS_INPUT",
                "current_stage": "SCHEDULE_REVIEW",
                "plan": {
                    **plan,
                    "warnings": [f"Không tạo được chương trình: {exc}"],
                    **_needs_fields(selected, [], [], {}, ["schedule"]),
                },
                "error_message": None,
            })
            return get_agent_run(run_id) or {}
        created_values: dict[str, Any] = {
            "status": "CAMPAIGN_CREATED",
            "current_stage": "CAMPAIGN_CREATED",
            "campaign_id": campaign_id,
            "campaign_created": created,
        }
        if autopilot:
            decisions.append(make_decision(
                "CAMPAIGN_CREATED", "done",
                f"Đã tạo chương trình “{campaign_payload['name']}” với {len(product_ids)} sản phẩm.",
            ))
            created_values["decisions"] = decisions
        update_agent_run(run_id, created_values)
        if created and enqueue_campaign is not None:
            enqueue_campaign(campaign_id)
        logger.info("[AI-IDOL-AGENT] Run %s created campaign %s", run_id, campaign_id)
        return get_agent_run(run_id) or {}
    except Exception as exc:
        logger.exception("[AI-IDOL-AGENT] Run %s failed", run_id)
        mark_agent_failed(run_id, "AGENT_ORCHESTRATION", exc)
        raise


def confirm_agent_run(run_id: str, payload: dict[str, Any]) -> dict[str, Any]:
    run = get_agent_run(run_id)
    if not run:
        raise ValueError("Agent run not found")
    if run.get("campaign_id"):
        raise ValueError("Agent run already created a campaign")
    overrides = dict(run.get("overrides") or {})
    product_ids = payload.get("product_ids")
    if product_ids:
        if not isinstance(product_ids, list):
            product_ids = [product_ids]
        cleaned = list(dict.fromkeys(item for item in (clean_product_id(raw) for raw in product_ids) if item))
        if not cleaned:
            raise ValueError("Mã sản phẩm không hợp lệ")
        overrides["explicit_product_ids"] = cleaned
    for key in ("scheduled_at", "duration_minutes", "platforms", "content_mode", "autonomy_policy", "purpose"):
        if payload.get(key) not in (None, "", []):
            overrides[key] = payload[key]
    if payload.get("scheduled_at") not in (None, ""):
        try:
            # datetime-local values carry no zone and are read in TIMEZONE.
            overrides["scheduled_at"] = parse_schedule(str(payload["scheduled_at"]).strip()).astimezone(
                ZoneInfo(TIMEZONE)
            ).isoformat()
        except (TypeError, ValueError):
            raise ValueError("Lịch phát không hợp lệ") from None
    claimed = claim_transition(
        "ai_idol_agent_runs", run_id, {"NEEDS_INPUT", "NEEDS_ATTENTION"}, "RECEIVED",
        {"overrides": overrides, "current_stage": "RECEIVED", "error_message": None},
    )
    if not claimed:
        raise ConflictError("Agent đang xử lý, chờ vài giây rồi thử lại.")
    return get_agent_run(run_id) or {}


def cancel_agent_run(run_id: str) -> dict[str, Any]:
    run = get_agent_run(run_id)
    if not run:
        raise ValueError("Agent run not found")
    campaign_id = run.get("campaign_id")
    if campaign_id:
        raise ValueError("Chương trình đã được tạo; hãy quản lý hoặc hủy trong màn hình chương trình")
    update_agent_run(run_id, {"status": "CANCELLED", "current_stage": "CANCELLED"})
    return get_agent_run(run_id) or {}
