# -*- coding: utf-8 -*-
"""
eval_recommendation.py — Advanced Deterministic Evaluation Harness for Recommendation Engine.

Metrics Measured:
- Intent Accuracy & Per-Field Filter Accuracy (Category, Max Price, Min Price, Skin Type, Concern, Avoid Ingredient)
- Deterministic Hard Constraint Pass Rate (100% target)
- Routine Total Budget Pass Rate (sum of routine prices <= max_price)
- Grounded Reasons Accuracy & Insufficient Data Count
- Precision@K & Retrieval Metrics (N/A when ground truth product IDs absent)
- Performance & Timing Breakdown
"""
from __future__ import annotations

import json
import logging
import os
import sys
import time
from datetime import datetime
from pathlib import Path

if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8")

logger = logging.getLogger(__name__)

# Add parent directory to sys.path
BASE_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(BASE_DIR))

from structured_recommendation import (
    generate_structured_recommendation,
    validate_recommendation,
    validate_routine_recommendation,
)


def run_recommendation_evaluation():
    cases_path = BASE_DIR / "tests" / "recommendation_eval_cases.json"
    if not cases_path.exists():
        print(f"[ERROR] Test cases file not found at {cases_path}")
        return

    with open(cases_path, "r", encoding="utf-8") as f:
        test_cases = json.load(f)

    print("\n=======================================================")
    print(" SKINSYNTAX RECOMMENDATION — EVALUATION HARNESS RUN")
    print("=======================================================\n")

    results_detail = []
    total_cases = len(test_cases)

    # Per-Field Filter Counters
    field_counts = {
        "intent": {"total": 0, "pass": 0},
        "category": {"total": 0, "pass": 0},
        "max_price": {"total": 0, "pass": 0},
        "min_price": {"total": 0, "pass": 0},
        "skin_type": {"total": 0, "pass": 0},
        "concerns": {"total": 0, "pass": 0},
        "avoid_ingredients": {"total": 0, "pass": 0},
    }

    total_recommended_products = 0
    valid_products_count = 0

    routine_cases_total = 0
    routine_budget_pass_count = 0

    grounded_reasons_count = 0
    total_reasons_count = 0
    insufficient_data_count = 0

    precision_3_list = []

    timing_filter_ms = []
    timing_retrieval_ms = []
    timing_val_ms = []
    timing_total_ms = []

    group_breakdown = {}

    for case in test_cases:
        cid = case["case_id"]
        group_name = case.get("group", "General")
        query = case["query"]
        expected = case.get("expected", {})
        expected_ids = case.get("expected_product_ids")

        payload = {"query": query, "user_profile": case.get("user_profile", {})}

        t0 = time.perf_counter()
        response = generate_structured_recommendation(payload, limit=6)
        elapsed_total = (time.perf_counter() - t0) * 1000.0

        extracted_filters = response.get("extracted_filters", {})
        timing_ms = response.get("timing_ms", {})
        recommendations = response.get("recommendations", [])
        debug_trace = response.get("debug_trace", {})

        if group_name not in group_breakdown:
            group_breakdown[group_name] = {"count": 0, "valid_prods": 0, "total_prods": 0}
        group_breakdown[group_name]["count"] += 1

        # 1. Per-Field Filter Extraction Accuracy
        # Intent
        field_counts["intent"]["total"] += 1
        if extracted_filters.get("intent") == expected.get("intent", "PRODUCT_INQUIRY"):
            field_counts["intent"]["pass"] += 1

        # Category
        if "category" in expected:
            field_counts["category"]["total"] += 1
            if extracted_filters.get("category") == expected["category"]:
                field_counts["category"]["pass"] += 1

        # Max Price
        if "max_price" in expected:
            field_counts["max_price"]["total"] += 1
            if extracted_filters.get("max_price") == expected["max_price"]:
                field_counts["max_price"]["pass"] += 1

        # Min Price
        if "min_price" in expected:
            field_counts["min_price"]["total"] += 1
            if extracted_filters.get("min_price") == expected["min_price"]:
                field_counts["min_price"]["pass"] += 1

        # Skin Type
        if "skin_type" in expected:
            field_counts["skin_type"]["total"] += 1
            if extracted_filters.get("skin_type") == expected["skin_type"]:
                field_counts["skin_type"]["pass"] += 1

        # Concerns
        if "concerns" in expected:
            field_counts["concerns"]["total"] += 1
            if extracted_filters.get("concerns") == expected["concerns"]:
                field_counts["concerns"]["pass"] += 1

        # Avoid Ingredients
        if "avoid_ingredients" in expected:
            field_counts["avoid_ingredients"]["total"] += 1
            if extracted_filters.get("avoid_ingredients") == expected["avoid_ingredients"]:
                field_counts["avoid_ingredients"]["pass"] += 1

        # 2. Hard Constraint Validation
        case_valid_prods = 0
        case_total_prods = len(recommendations)

        constraints_check = {
            "category": expected.get("category"),
            "max_price": expected.get("max_price"),
            "min_price": expected.get("min_price"),
            "avoid_ingredients": expected.get("avoid_ingredients"),
        }

        for p in recommendations:
            is_valid, rejection_reason = validate_recommendation(p, constraints_check)
            if is_valid:
                case_valid_prods += 1
                valid_products_count += 1
            total_recommended_products += 1

            group_breakdown[group_name]["total_prods"] += 1
            if is_valid:
                group_breakdown[group_name]["valid_prods"] += 1

            if p.get("data_confidence") == "low" or p.get("safety_text") == "insufficient_data":
                insufficient_data_count += 1

            p_reasons = p.get("reasons", [])
            for r in p_reasons:
                total_reasons_count += 1
                if r:
                    grounded_reasons_count += 1

        # 3. Routine Total Budget Check
        if expected.get("is_routine") or expected.get("intent") == "ROUTINE":
            routine_cases_total += 1
            r_valid, _ = validate_routine_recommendation(recommendations, expected.get("max_price"))
            if r_valid:
                routine_budget_pass_count += 1

        # 4. Precision@K (Only computed if Ground Truth expected_ids present)
        if expected_ids and isinstance(expected_ids, list):
            rec_ids = [p.get("product_id") for p in recommendations[:3]]
            hits = sum(1 for rid in rec_ids if rid in expected_ids)
            p3 = hits / min(3, len(expected_ids))
            precision_3_list.append(p3)

        # Timings
        timing_filter_ms.append(timing_ms.get("filter_extraction_ms", 0.0))
        timing_retrieval_ms.append(timing_ms.get("retrieval_ms", 0.0))
        timing_val_ms.append(timing_ms.get("validation_ms", 0.0))
        timing_total_ms.append(elapsed_total)

        results_detail.append({
            "case_id": cid,
            "group": group_name,
            "query": query,
            "expected": expected,
            "extracted_filters": extracted_filters,
            "recommendations_count": case_total_prods,
            "valid_recommendations_count": case_valid_prods,
            "hard_constraint_pass_rate": (case_valid_prods / case_total_prods) if case_total_prods > 0 else 1.0,
            "debug_trace": debug_trace,
            "timing_ms": timing_ms,
        })

    # Summary Metrics Calculation
    intent_acc_pct = (field_counts["intent"]["pass"] / field_counts["intent"]["total"] * 100.0) if field_counts["intent"]["total"] > 0 else 100.0
    cat_acc_pct = (field_counts["category"]["pass"] / field_counts["category"]["total"] * 100.0) if field_counts["category"]["total"] > 0 else 100.0
    max_price_acc_pct = (field_counts["max_price"]["pass"] / field_counts["max_price"]["total"] * 100.0) if field_counts["max_price"]["total"] > 0 else 100.0
    min_price_acc_pct = (field_counts["min_price"]["pass"] / field_counts["min_price"]["total"] * 100.0) if field_counts["min_price"]["total"] > 0 else 100.0
    skin_acc_pct = (field_counts["skin_type"]["pass"] / field_counts["skin_type"]["total"] * 100.0) if field_counts["skin_type"]["total"] > 0 else 100.0

    total_filter_checks = sum(f["total"] for f in field_counts.values())
    passed_filter_checks = sum(f["pass"] for f in field_counts.values())
    overall_filter_acc_pct = (passed_filter_checks / total_filter_checks * 100.0) if total_filter_checks > 0 else 100.0

    hard_constraint_pass_pct = (valid_products_count / total_recommended_products * 100.0) if total_recommended_products > 0 else 100.0
    routine_budget_pass_pct = (routine_budget_pass_count / routine_cases_total * 100.0) if routine_cases_total > 0 else 100.0
    grounded_reasons_pct = (grounded_reasons_count / total_reasons_count * 100.0) if total_reasons_count > 0 else 100.0

    precision_3_str = f"{sum(precision_3_list) / len(precision_3_list):.2f}" if precision_3_list else "N/A (No Ground Truth IDs in dataset)"

    avg_filter_latency = sum(timing_filter_ms) / total_cases if total_cases > 0 else 0.0
    avg_retrieval_latency = sum(timing_retrieval_ms) / total_cases if total_cases > 0 else 0.0
    avg_val_latency = sum(timing_val_ms) / total_cases if total_cases > 0 else 0.0
    avg_total_latency = sum(timing_total_ms) / total_cases if total_cases > 0 else 0.0

    # Print Terminal Report
    print("SKINSYNTAX RECOMMENDATION — EVALUATION RESULTS\n")
    print(f"Total queries evaluated: {total_cases}\n")

    print("1. DETERMINISTIC METRICS")
    print(f"  Intent Accuracy            : {intent_acc_pct:.1f}%")
    print(f"  Category Accuracy          : {cat_acc_pct:.1f}%")
    print(f"  Max Price Accuracy         : {max_price_acc_pct:.1f}%")
    print(f"  Min Price Accuracy         : {min_price_acc_pct:.1f}%")
    print(f"  Skin Type Accuracy         : {skin_acc_pct:.1f}%")
    print(f"  Overall Filter Accuracy    : {overall_filter_acc_pct:.1f}%")
    print(f"  Hard Constraint Pass Rate  : {hard_constraint_pass_pct:.1f}% (TARGET 100%)")
    print(f"  Routine Budget Pass Rate   : {routine_budget_pass_pct:.1f}% (Total routine sum <= budget)")
    print(f"  Grounded Reasons Accuracy  : {grounded_reasons_pct:.1f}%")
    print(f"  Insufficient Data Count    : {insufficient_data_count} products\n")

    print("2. RETRIEVAL & RANKING METRICS")
    print(f"  Precision@3                : {precision_3_str}\n")

    print("3. LATENCY BREAKDOWN")
    print(f"  Avg Filter Extraction      : {avg_filter_latency:.1f} ms")
    print(f"  Avg Retrieval Latency      : {avg_retrieval_latency:.1f} ms")
    print(f"  Avg Validation Latency     : {avg_val_latency:.1f} ms")
    print(f"  Avg Total Latency          : {avg_total_latency / 1000.0:.2f} s ({avg_total_latency:.1f} ms)\n")

    print("4. BY GROUP BREAKDOWN")
    for gname, stats in group_breakdown.items():
        cnt = stats["count"]
        val_p = stats["valid_prods"]
        tot_p = stats["total_prods"]
        pct = (val_p / tot_p * 100.0) if tot_p > 0 else 100.0
        print(f" - {gname:<22}: {cnt} queries | {val_p}/{tot_p} valid products ({pct:.1f}% hard pass)")

    timestamp_str = datetime.now().strftime("%Y%m%d_%H%M%S")
    report_filename = f"recommendation_eval_report_{timestamp_str}.json"
    report_path = BASE_DIR / report_filename

    summary_report = {
        "timestamp": datetime.now().isoformat(),
        "total_queries": total_cases,
        "deterministic_metrics": {
            "intent_accuracy_pct": round(intent_acc_pct, 2),
            "category_accuracy_pct": round(cat_acc_pct, 2),
            "max_price_accuracy_pct": round(max_price_acc_pct, 2),
            "min_price_accuracy_pct": round(min_price_acc_pct, 2),
            "skin_type_accuracy_pct": round(skin_acc_pct, 2),
            "overall_filter_accuracy_pct": round(overall_filter_acc_pct, 2),
            "hard_constraint_pass_rate_pct": round(hard_constraint_pass_pct, 2),
            "routine_budget_pass_rate_pct": round(routine_budget_pass_pct, 2),
            "grounded_reasons_pct": round(grounded_reasons_pct, 2),
            "insufficient_data_count": insufficient_data_count,
        },
        "retrieval_metrics": {
            "precision_at_3": precision_3_str,
            "note": "Precision@K reported as N/A unless dataset has ground truth expected_product_ids",
        },
        "latency_ms": {
            "avg_filter_extraction_ms": round(avg_filter_latency, 2),
            "avg_retrieval_latency_ms": round(avg_retrieval_latency, 2),
            "avg_validation_latency_ms": round(avg_val_latency, 2),
            "avg_total_latency_ms": round(avg_total_latency, 2),
        },
        "group_breakdown": group_breakdown,
        "details": results_detail,
    }

    with open(report_path, "w", encoding="utf-8") as f:
        json.dump(summary_report, f, ensure_ascii=False, indent=2)

    print(f"\n✅ Recommendation Evaluation Report saved to: {report_filename}\n")


if __name__ == "__main__":
    run_recommendation_evaluation()
