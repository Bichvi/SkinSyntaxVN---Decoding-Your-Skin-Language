"""U13: Chroma (catalog._HIDDEN), Neo4j (transform.HIDDEN_STATUSES) và PHP phải cùng một danh sách trạng thái ẩn."""

from __future__ import annotations

import re
from pathlib import Path

from graph_sync.transform import HIDDEN_STATUSES
from search_service import catalog


def test_catalog_hidden_matches_graph_hidden_statuses() -> None:
    assert set(catalog._HIDDEN) == set(HIDDEN_STATUSES)
    assert len(catalog._HIDDEN) == len(set(catalog._HIDDEN))


def test_catalog_hidden_matches_php_catalog_events() -> None:
    php = Path(__file__).resolve().parents[3] / "backend" / "app" / "services" / "CatalogEvents.php"
    if not php.exists():
        return
    match = re.search(r"HIDDEN_STATUSES\s*=\s*\[(.*?)\]", php.read_text(encoding="utf-8"), re.S)
    assert match, "CatalogEvents::HIDDEN_STATUSES không tìm thấy"
    php_values = set(re.findall(r"'([^']*)'", match.group(1)))
    assert php_values == set(catalog._HIDDEN)
