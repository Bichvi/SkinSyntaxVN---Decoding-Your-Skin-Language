"""analyze() outputs validate against label_analyze_response.schema.json."""

import json
from pathlib import Path

import pytest

from label_service.vision import LabelText, VisionUnavailable
from test_label_service import (K1, R1_KEYS, T1, FakeCatalog, alt, label, make, req)

jsonschema = pytest.importorskip("jsonschema")
SCHEMA_PATH = Path(__file__).resolve().parents[2] / "label_service" / "contract" / "label_analyze_response.schema.json"
SCHEMA = json.loads(SCHEMA_PATH.read_text(encoding="utf-8"))
VALIDATOR = jsonschema.Draft202012Validator(SCHEMA)


def check(body):
    errors = sorted(VALIDATOR.iter_errors(body), key=lambda e: list(e.path))
    assert not errors, [f"{list(e.path)}: {e.message}" for e in errors]


def test_schema_itself_is_valid():
    jsonschema.Draft202012Validator.check_schema(SCHEMA)


def test_ok_with_conflict_alternatives_and_profile():
    s, _ = make(cart={"456": R1_KEYS}, catalog=FakeCatalog([{"id": "456", "ten_san_pham": "Retinol Serum"}]),
                candidates=[alt("t9")])
    out = s.analyze(req(product_ids=("456",), skin_type_override={"sensitive": True, "skin_type": "dau"}))
    assert out["conflicts"] and out["alternatives"]
    check(out)


def test_ok_empty_cart_unknown_profile():
    s, _ = make()
    check(s.analyze(req()))


def test_catalog_source_and_dry_skin_avoid():
    s, _ = make(lt=label(K1, "moisturizer"), candidates=[alt("m1")])
    out = s.analyze(req(skin_profile={"skin_type": "da khô"}))
    assert out["skin_fit"]["verdict"] == "avoid"
    check(out)


@pytest.mark.parametrize("lt", [label(is_label=False), VisionUnavailable("x"), label("!!! ??? ... ,,, ;;; ########")])
def test_error_statuses(lt):
    s, _ = make(lt=lt)
    out = s.analyze(req())
    assert out["status"] != "ok"
    check(out)


def test_user_edit_and_internal_error_bodies():
    s, cs = make()
    check(s.analyze(req(image=None, image_mime=None, ingredients_text=T1)))
    cs.rule_set = None
    out = s.analyze(req(product_ids=("1",)))
    assert "internal_error" in out["warnings"]
    check(out)


def test_schema_rejects_unknown_top_level_key_and_bad_status():
    s, _ = make()
    out = s.analyze(req())
    assert list(VALIDATOR.iter_errors({**out, "extra": 1}))
    assert list(VALIDATOR.iter_errors({**out, "status": "weird"}))
