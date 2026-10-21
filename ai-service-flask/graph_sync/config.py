"""Graph-sync settings from the environment (never logged: URIs/passwords)."""

from __future__ import annotations

import os
from dataclasses import dataclass


def _int(name: str, default: int) -> int:
    try:
        return int(os.getenv(name, str(default)))
    except ValueError:
        return default


@dataclass(frozen=True)
class Settings:
    mongo_uri: str
    mongo_db: str
    neo4j_uri: str
    neo4j_user: str
    neo4j_password: str
    redis_url: str
    batch_size: int = 500
    similar_top_k: int = 10
    similar_min_score: float = 0.2
    lock_key: str = "graph_sync:lock"
    lock_ttl: int = 1800


def load_settings() -> Settings:
    return Settings(
        mongo_uri=os.getenv("MONGO_URI", "mongodb://127.0.0.1:27017"),
        mongo_db=os.getenv("MONGO_DB_NAME", os.getenv("MONGO_DB", "skinsyntax")),
        neo4j_uri=os.getenv("NEO4J_URI", "bolt://localhost:7687").strip(),
        neo4j_user=os.getenv("NEO4J_USER", "neo4j").strip(),
        neo4j_password=os.getenv("NEO4J_PASSWORD", ""),
        redis_url=os.getenv("REDIS_URL", "").strip(),
        batch_size=max(1, _int("BATCH_SIZE", 500)),
        similar_top_k=max(1, _int("SIMILAR_TOP_K", 10)),
    )
