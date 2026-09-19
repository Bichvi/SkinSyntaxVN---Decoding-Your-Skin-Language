from __future__ import annotations

import datetime as dt
import math
from typing import Any, Callable
from zoneinfo import ZoneInfo

from agents.intent_classifier import CREATE_CAMPAIGN, classify_intent
from agents.intent_parser import parse_agent_brief
from agents.product_resolver import normalize_catalog_product, resolve_products
from config import (
    AGENT_ALLOW_AUTO_PUBLISH,
    ESTIMATED_MINUTES_PER_PRODUCT,
    MIN_LEAD_MINUTES,
    TIMEZONE,
    logger,
)
from core.agent_runs import get_agent_run, mark_agent_failed, update_agent_run
from core.campaigns import create_campaign, parse_schedule
from core.state_machine import get_db
from providers.llm_provider import LLMProvider


def _catalog(limit: int = 2000) -> list[dict[str, Any]]:
    db = get_db()
    cursor = db.san_pham.find(
        {"trang_thai": {"$nin": ["inactive", "hidden", "disabled", "0"]}},
        limit=max(1, min(limit, 5000)),
    )
    return [normalize_catalog_product(dict(item)) for item in cursor]


def _safe_schedule(value: str | None, product_count: int, *, now: dt.datetime | None = None) -> dt.datetime:
    local_now = now or dt.datetime.now(ZoneInfo(TIMEZONE))
    required_minutes = max(
        60,
        int(math.ceil(max(MIN_LEAD_MINUTES, product_count * ESTIMATED_MINUTES_PER_PRODUCT) + 15)),
    )
    earliest = local_now + dt.timedelta(minutes=required_minutes)
    if value:
        try:
            parsed = parse_schedule(value).astimezone(ZoneInfo(TIMEZONE))
            if parsed >= earliest:
                return parsed
        except (TypeError, ValueError):
            pass
    rounded = earliest + dt.timedelta(minutes=(5 - earliest.minute % 5) % 5)
    return rounded.replace(second=0, microsecond=0)


def _build_campaign_name(campaign_name: str, selected: list[dict[str, Any]], scheduled_at: dt.datetime) -> str:
    if campaign_name:
        return campaign_name[:160]
    if len(selected) == 1:
        return f"Syna giới thiệu {selected[0]['name']}"
    return f"Syna tư vấn {len(selected)} sản phẩm · {scheduled_at:%d/%m %H:%M}"


def process_agent_run(
    run_id: str,
    *,
    enqueue_campaign: Callable[[str], Any] | None = None,
    llm=None,
    catalog: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    run = get_agent_run(run_id)
    if not run:
        raise ValueError("Agent run not found")
    if run.get("status") == "CANCELLED":
        return run
    if run.get("campaign_id"):
        return run

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
        if classification.intent != CREATE_CAMPAIGN:
            plan = {
                "intent_classification": classification_data,
                "brief": run["brief"],
                "resolved_products": [],
                "candidate_products": [],
                "warnings": [
                    "Agent chưa tạo campaign vì lệnh này không phải yêu cầu tạo livestream.",
                    classification.next_step,
                ],
            }
            update_agent_run(run_id, {
                "status": "NEEDS_INPUT",
                "current_stage": "INTENT_REVIEW",
                "plan": plan,
                "error_message": None,
            })
            return get_agent_run(run_id) or plan

        update_agent_run(run_id, {"current_stage": "INTERPRETING"})
        provider = llm if llm is not None else LLMProvider()
        brief = parse_agent_brief(run["brief"], run.get("overrides") or {}, llm=provider)
        update_agent_run(run_id, {
            "normalized_brief": brief.to_dict(),
            "current_stage": "RESOLVING_PRODUCTS",
        })

        products = catalog if catalog is not None else _catalog()
        resolution = resolve_products(
            run["brief"],
            brief.explicit_product_ids,
            brief.product_queries,
            products,
        )
        selected = resolution["selected"]
        constraint_mismatches = resolution.get("constraint_mismatches") or []
        popularity_unverified = bool(
            resolution.get("popularity_requested")
            and not resolution.get("popularity_data_available")
            and not brief.explicit_product_ids
        )
        if not selected or resolution["unresolved_ids"] or constraint_mismatches or popularity_unverified:
            warnings = ["Cần xác nhận sản phẩm trước khi tạo kịch bản."]
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
                "unresolved_ids": resolution["unresolved_ids"],
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
                },
                "error_message": None,
            })
            return get_agent_run(run_id) or {}

        platforms = brief.platforms
        if policy == "auto_preview":
            platforms = ["internal"]
        scheduled_at = _safe_schedule(brief.scheduled_at, len(selected))
        product_ids = [item["product_id"] for item in selected]
        plan = {
            "intent_classification": classification_data,
            "brief": brief.to_dict(),
            "resolved_products": selected,
            "candidate_products": [],
            "scheduled_at": scheduled_at.isoformat(),
            "platforms": platforms,
            "autonomy_policy": policy,
            "approval_mode": "human" if policy == "assisted" else "auto",
            "warnings": [],
        }
        update_agent_run(run_id, {"status": "PLAN_READY", "current_stage": "PLAN_READY", "plan": plan})

        campaign_payload = {
            "name": _build_campaign_name(brief.campaign_name, selected, scheduled_at),
            "product_ids": product_ids,
            "scheduled_at": scheduled_at.isoformat(),
            "platforms": platforms,
            "duration_minutes": brief.duration_minutes,
            "content_mode": brief.content_mode,
            "idempotency_key": f"agent:{run_id}",
            "configuration": {
                "format": "16:9",
                "length_mode": "Short (30-60s)",
                "avatar_mode": "syna_3d",
                "agent_managed": True,
                "agent_run_id": run_id,
                "agent_autonomy_policy": policy,
                "script_approval_mode": "human" if policy == "assisted" else "auto",
            },
        }
        campaign_id, created = create_campaign(campaign_payload, created_by=run.get("created_by"))
        update_agent_run(run_id, {
            "status": "CAMPAIGN_CREATED",
            "current_stage": "CAMPAIGN_CREATED",
            "campaign_id": campaign_id,
            "campaign_created": created,
        })
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
        overrides["explicit_product_ids"] = [str(item).strip() for item in product_ids if str(item).strip()]
    for key in ("scheduled_at", "duration_minutes", "platforms", "content_mode", "autonomy_policy"):
        if payload.get(key) not in (None, "", []):
            overrides[key] = payload[key]
    update_agent_run(run_id, {
        "overrides": overrides,
        "status": "RECEIVED",
        "current_stage": "RECEIVED",
        "error_message": None,
    })
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
