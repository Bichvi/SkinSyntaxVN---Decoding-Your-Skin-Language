"""Evaluate the agent-graph router against the checked-in YAML cases.

This module deliberately imports ``agent_graph`` only after external service
environment variables have been removed and the explicitly selected dotenv
file has been parsed for the small allow-list of LLM settings.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from collections import Counter
from datetime import datetime
from pathlib import Path
from typing import Any

import yaml
from dotenv import dotenv_values


SERVICE_ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = SERVICE_ROOT.parent
DEFAULT_CASES = SERVICE_ROOT / "tests" / "agent_graph" / "router_cases.yaml"
DEFAULT_ENV_FILE = REPO_ROOT / ".env"
ALLOWED_ENV_KEYS = {
    "OPENAI_API_KEY",
    "GROQ_API_KEY",
    "GROQ_ROUTER_MODEL",
    "GOOGLE_API_KEY",
    "GOOGLE_API_KEYS",
    "GEMINI_CHAT_MODEL",
}
SERVICE_ENV_KEYS = ("MONGO_URI", "NEO4J_URI", "NEO4J_PASSWORD", "REDIS_URL")
ENTITY_FIELDS = (
    "scope",
    "resolved_product_ids",
    "qty",
    "payment_method",
    "wants_new_address",
    "brand",
)
REQUIRED_LABELS = ("BUY", "ORDER_STATUS")


def _configure_environment(env_file: Path) -> None:
    """Keep only explicitly selected LLM settings in the process environment."""
    for key in SERVICE_ENV_KEYS + tuple(ALLOWED_ENV_KEYS):
        os.environ.pop(key, None)
    values = dotenv_values(env_file)
    for key in ALLOWED_ENV_KEYS:
        value = values.get(key)
        if value is not None:
            os.environ[key] = str(value)


def _load_cases(path: Path) -> list[dict[str, Any]]:
    with path.open("r", encoding="utf-8") as handle:
        loaded = yaml.safe_load(handle) or []
    if not isinstance(loaded, list) or not all(isinstance(case, dict) for case in loaded):
        raise ValueError(f"case file must contain a YAML list of mappings: {path}")
    return loaded


def _state_for(case: dict[str, Any], conv_state_type: type) -> Any:
    products = case.get("last_shown_products") or []
    if not isinstance(products, list):
        raise ValueError("last_shown_products must be a list")
    return conv_state_type(
        pending=str(case.get("pending_state") or "none"),
        last_shown_products=products,
    )


class _DryRunRouter:
    def __init__(self, labels: list[str]):
        self.labels = labels

    def invoke(self, _messages: list[Any]) -> dict[str, Any]:
        return {
            "intents": [{"label": label, "confidence": 1.0} for label in self.labels],
            "entities": {},
        }


def _prediction(
    case: dict[str, Any],
    state_type: type,
    classify: Any,
    llm: Any,
) -> tuple[list[str], dict[str, Any], Any]:
    state = _state_for(case, state_type)
    result = classify(str(case.get("message") or ""), state, [], llm)
    labels = sorted({intent.label for intent in result.intents})
    entities = result.entities.model_dump(exclude_none=True)
    return labels, entities, result


def _label_metrics(cases: list[dict[str, Any]]) -> list[dict[str, Any]]:
    labels = sorted({label for case in cases for label in case["expected_labels"] + case["predicted_labels"]})
    metrics = []
    for label in labels:
        tp = sum(label in case["expected_labels"] and label in case["predicted_labels"] for case in cases)
        fp = sum(label not in case["expected_labels"] and label in case["predicted_labels"] for case in cases)
        fn = sum(label in case["expected_labels"] and label not in case["predicted_labels"] for case in cases)
        metrics.append({
            "label": label,
            "precision": round(tp / (tp + fp), 4) if tp + fp else 0.0,
            "recall": round(tp / (tp + fn), 4) if tp + fn else 0.0,
            "true_positive": tp,
            "false_positive": fp,
            "false_negative": fn,
        })
    return metrics


def _presence_accuracy(cases: list[dict[str, Any]], label: str) -> float:
    if not cases:
        return 0.0
    correct = sum((label in case["expected_labels"]) == (label in case["predicted_labels"]) for case in cases)
    return correct / len(cases)


def _build_report(cases: list[dict[str, Any]]) -> dict[str, Any]:
    overall = sum(set(case["expected_labels"]) == set(case["predicted_labels"]) for case in cases)
    return {
        "generated_at": datetime.now().astimezone().isoformat(timespec="seconds"),
        "case_count": len(cases),
        "overall_accuracy": round(overall / len(cases), 4) if cases else 0.0,
        "buy_accuracy": round(_presence_accuracy(cases, "BUY"), 4),
        "order_status_accuracy": round(_presence_accuracy(cases, "ORDER_STATUS"), 4),
        "label_metrics": _label_metrics(cases),
        "cases": cases,
    }


def _print_summary(report: dict[str, Any]) -> None:
    print("label                 precision  recall")
    print("--------------------  ---------  ------")
    for metric in report["label_metrics"]:
        print(f"{metric['label']:<20}  {metric['precision']:>9.4f}  {metric['recall']:>6.4f}")
    print(f"overall accuracy: {report['overall_accuracy']:.4f}")
    print(f"BUY accuracy: {report['buy_accuracy']:.4f}")
    print(f"ORDER_STATUS accuracy: {report['order_status_accuracy']:.4f}")


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--cases", type=Path, default=DEFAULT_CASES)
    parser.add_argument("--env-file", type=Path, default=DEFAULT_ENV_FILE)
    parser.add_argument("--min-acc", type=float, default=0.90)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--sleep", type=float, default=1.5, help="Seconds to wait between cases")
    parser.add_argument("--output", type=Path, help=argparse.SUPPRESS)
    return parser.parse_args()


def main() -> int:
    args = _parse_args()
    _configure_environment(args.env_file)
    sys.path.insert(0, str(SERVICE_ROOT))

    from agent_graph.router import RouterOutput, classify
    from agent_graph.state import ConvState

    cases = _load_cases(args.cases)
    for index, case in enumerate(cases, start=1):
        expected = [str(label) for label in case.get("expected_labels") or []]
        llm = _DryRunRouter(expected) if args.dry_run else None
        if not args.dry_run:
            from agent_graph.llm import build_router_llms, with_schema

            llm = with_schema(build_router_llms(), RouterOutput)
        predicted, entities, result = _prediction(case, ConvState, classify, llm)
        fallback_occurred = bool(getattr(result, "fallback", False))
        first_error = getattr(result, "error", None)
        if fallback_occurred and not args.dry_run:
            time.sleep(5)
            predicted, entities, result = _prediction(case, ConvState, classify, llm)
        expected_entities = case.get("expected_entities") or {}
        entity_checks = {
            field: {
                "expected": expected_entities[field],
                "actual": entities.get(field),
                "passed": entities.get(field) == expected_entities[field],
            }
            for field in ENTITY_FIELDS
            if field in expected_entities
        }
        cases[index - 1] = {
            "case_index": index,
            "expected_labels": sorted(set(expected)),
            "predicted_labels": predicted,
            "labels_passed": set(expected) == set(predicted),
            "entity_checks": entity_checks,
            "error": first_error or getattr(result, "error", None),
            "fallback": fallback_occurred or bool(getattr(result, "fallback", False)),
        }
        if index < len(cases) and args.sleep > 0:
            time.sleep(args.sleep)

    report = _build_report(cases)
    output = args.output or REPO_ROOT / "report" / "chat-agent" / (
        f"router-eval-{datetime.now().strftime('%Y%m%d-%H%M')}.json"
    )
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    _print_summary(report)
    print(f"fallback cases: {sum(bool(case.get('fallback')) for case in cases)}")
    threshold_ok = (
        report["overall_accuracy"] >= args.min_acc
        and all(report[f"{label.lower()}_accuracy"] >= 0.95 for label in REQUIRED_LABELS)
    )
    return 0 if threshold_ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
