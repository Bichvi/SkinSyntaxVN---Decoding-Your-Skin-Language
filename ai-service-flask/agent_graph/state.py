from __future__ import annotations

import json
import logging
from dataclasses import dataclass, field


logger = logging.getLogger(__name__)


@dataclass
class ConvState:
    last_shown_products: list[dict] = field(default_factory=list)
    open_draft_id: str | None = None
    open_draft_qty: dict[str, int] | None = None
    open_draft_deadline: float | None = None
    pending: str = "none"
    intent_queue: list[str] = field(default_factory=list)


class StateStore:
    """Persist the conversation state while keeping customer addresses out of Redis."""

    PREFIX = "skinsyntax:agent:state:"

    def __init__(self, redis_client):
        self.redis = redis_client

    @classmethod
    def _key(cls, sid: str) -> str:
        return f"{cls.PREFIX}{sid}"

    @staticmethod
    def _product_view(product: dict) -> dict:
        return {key: product[key] for key in ("id", "name", "idx") if key in product}

    @staticmethod
    def _valid_qty(value) -> dict[str, int] | None:
        if not isinstance(value, dict) or not value:
            return None
        for key, qty in value.items():
            if not isinstance(key, str) or isinstance(qty, bool) or not isinstance(qty, int) or qty <= 0:
                return None
        return dict(value)

    @staticmethod
    def _valid_deadline(value) -> float | None:
        if isinstance(value, bool) or not isinstance(value, (int, float)) or value <= 0:
            return None
        return value

    def load(self, sid: str) -> ConvState:
        try:
            raw = self.redis.get(self._key(sid))
            if raw is None:
                return ConvState()
            if isinstance(raw, bytes):
                raw = raw.decode("utf-8")
            data = json.loads(raw)
            products = data.get("last_shown_products", [])
            if not isinstance(products, list):
                products = []
            return ConvState(
                last_shown_products=[self._product_view(item) for item in products if isinstance(item, dict)],
                open_draft_id=data.get("open_draft_id") or data.get("draft_id"),
                open_draft_qty=self._valid_qty(data.get("draft_qty")),
                open_draft_deadline=self._valid_deadline(data.get("draft_deadline")),
                pending=str(data.get("pending", "none")),
                intent_queue=list(data.get("intent_queue", [])),
            )
        except Exception:
            return ConvState()

    def save(self, sid: str, state: ConvState, ttl: int = 7200) -> None:
        payload = {
            "last_shown_products": [self._product_view(item) for item in state.last_shown_products],
            "draft_id": state.open_draft_id,
            "draft_qty": state.open_draft_qty,
            "draft_deadline": state.open_draft_deadline,
            "pending": state.pending,
            "intent_queue": state.intent_queue,
        }
        try:
            self.redis.setex(self._key(sid), ttl, json.dumps(payload, ensure_ascii=False))
        except Exception as exc:
            logger.warning("state store save failed: %s", type(exc).__name__)
