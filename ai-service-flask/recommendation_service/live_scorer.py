"""Small latency and result-quality scorer for service telemetry."""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class LiveScore:
    latency_ms: float
    selected_products: int
    safety_warning_count: int

    def to_dict(self) -> dict[str, Any]:
        return {
            "latency_ms": round(self.latency_ms, 2),
            "selected_products": self.selected_products,
            "safety_warning_count": self.safety_warning_count,
        }


def score_response(response: dict[str, Any]) -> LiveScore:
    """Create a non-blocking score from an already-built response."""

    selected = list(response.get("combo", {}).get("product_ids", []))
    warnings = list(response.get("conflict_warnings", []))
    return LiveScore(float(response.get("latency_ms") or 0), len(selected), len(warnings))
