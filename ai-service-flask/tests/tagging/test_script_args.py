import importlib.util
from pathlib import Path

import pytest

SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "llm_tag_products.py"
spec = importlib.util.spec_from_file_location("llm_tag_products", SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def test_limit_required():
    with pytest.raises(SystemExit):
        mod.parse_args([])


def test_full_run_needs_explicit_confirmation():
    with pytest.raises(SystemExit):
        mod.parse_args(["--all"])
    assert mod.parse_args(["--all", "--confirm-full"]).all is True


def test_normal_args():
    a = mod.parse_args(["--limit", "30", "--only-missing", "--dry-run"])
    assert a.limit == 30 and a.only_missing and a.dry_run and a.sample == "random" and a.seed == 7


def test_limit_must_be_positive():
    with pytest.raises(SystemExit):
        mod.parse_args(["--limit", "0"])


def test_id_variants():
    assert mod._id_variants("1496") == ["1496", 1496] and mod._id_variants("abc") == ["abc"]
