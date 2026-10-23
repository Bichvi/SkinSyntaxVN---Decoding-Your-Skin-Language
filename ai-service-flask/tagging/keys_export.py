"""Standard tag keys (benefits / concerns / skin types) as a small JSON for the PHP admin UI (U07)."""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

from .labeler import GROUPS, load_vocabulary


def build_tag_keys(vocab: dict[str, dict[str, str]] | None = None, now: datetime | None = None) -> dict[str, Any]:
    vocab = vocab if vocab is not None else load_vocabulary()
    return {
        "generated_at": (now or datetime.now(timezone.utc)).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "source": "ai-service-flask/data/graph/synonyms.yaml",
        "groups": {
            group: [{"key": key, "name": name} for key, name in vocab[group].items()] for group in GROUPS
        },
    }
