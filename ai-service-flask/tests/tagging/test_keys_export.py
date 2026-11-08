import json
from pathlib import Path

import pytest

from tagging.keys_export import build_tag_keys
from tagging.labeler import load_vocabulary

PHP_JSON = Path(__file__).resolve().parents[3] / "backend" / "app" / "data" / "tag_keys.json"


def test_export_shape_matches_vocabulary():
    payload = build_tag_keys()
    vocab = load_vocabulary()
    assert set(payload["groups"]) == {"benefits", "concerns", "skin_types"}
    for group, items in payload["groups"].items():
        assert {i["key"]: i["name"] for i in items} == vocab[group]


@pytest.mark.skipif(not PHP_JSON.is_file(), reason="backend/app/data/tag_keys.json not generated")
def test_php_json_in_sync_with_yaml():
    """If this fails run: python ai-service-flask/scripts/export_tag_keys.py"""

    committed = json.loads(PHP_JSON.read_text(encoding="utf-8"))["groups"]
    assert committed == build_tag_keys()["groups"]
