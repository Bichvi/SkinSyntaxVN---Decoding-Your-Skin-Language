"""Policy tests for scripts/auto_approve_tags.py (no Mongo/Redis)."""

import importlib.util
from pathlib import Path

SPEC = importlib.util.spec_from_file_location(
    "auto_approve_tags", Path(__file__).resolve().parents[2] / "scripts" / "auto_approve_tags.py")
aat = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(aat)

VOCAB = {"benefits": {"cap_am": "Cấp ẩm", "lam_diu": "Làm dịu"}, "concerns": {"mun": "Mụn"}, "skin_types": {"da_dau": "Da dầu"}}


def sug(**over):
    base = {"suggested": {"benefits": ["cap_am"], "concerns": ["mun"], "skin_types": []},
            "evidence": ["hyaluronic acid"], "dropped": [], "rule": {"benefits": ["cap_am", "lam_diu"]}}
    base.update(over)
    return base


PRODUCT = {"ma_san_pham": 1, "trang_thai": "active", "tag_source": ""}


def test_confident_when_all_hold():
    ok, reasons, sel = aat.evaluate(sug(), PRODUCT, VOCAB)
    assert ok and reasons == [] and sel["benefits"] == ["cap_am"]


def test_dropped_excludes():
    assert aat.evaluate(sug(dropped=["khô_da"]), PRODUCT, VOCAB)[1] == [aat.R_DROPPED]


def test_unknown_suggested_key_excludes():
    s = sug(suggested={"benefits": ["cap_am", "xxx"], "concerns": [], "skin_types": []})
    ok, reasons, sel = aat.evaluate(s, PRODUCT, VOCAB)
    assert not ok and reasons == [aat.R_INVALID] and sel["benefits"] == ["cap_am"]


def test_empty_or_blank_evidence_excludes():
    assert aat.R_NO_EVIDENCE in aat.evaluate(sug(evidence=[]), PRODUCT, VOCAB)[1]
    assert aat.R_NO_EVIDENCE in aat.evaluate(sug(evidence=["  "]), PRODUCT, VOCAB)[1]
    assert aat.R_NO_EVIDENCE in aat.evaluate(sug(evidence=None), PRODUCT, VOCAB)[1]


def test_benefit_overlap_required():
    assert aat.R_NO_OVERLAP in aat.evaluate(sug(rule={"benefits": ["lam_diu"]}), PRODUCT, VOCAB)[1]
    assert aat.R_NO_OVERLAP in aat.evaluate(sug(rule={"benefits": []}), PRODUCT, VOCAB)[1]
    assert aat.R_NO_OVERLAP in aat.evaluate(sug(suggested={"benefits": [], "concerns": ["mun"]}), PRODUCT, VOCAB)[1]


def test_product_conditions():
    assert aat.R_NO_PRODUCT in aat.evaluate(sug(), None, VOCAB)[1]
    assert aat.R_MANUAL in aat.evaluate(sug(), {**PRODUCT, "tag_source": "manual"}, VOCAB)[1]
    assert aat.R_HIDDEN in aat.evaluate(sug(), {**PRODUCT, "trang_thai": "hidden"}, VOCAB)[1]
    assert aat.evaluate(sug(), {**PRODUCT, "tag_source": "llm_approved"}, VOCAB)[0]


def test_multiple_reasons_collected():
    ok, reasons, _ = aat.evaluate(sug(evidence=[], dropped=["x"]), {**PRODUCT, "tag_source": "manual"}, VOCAB)
    assert not ok and reasons == [aat.R_DROPPED, aat.R_NO_EVIDENCE, aat.R_MANUAL]


def test_product_tag_set_and_event_shape():
    s = aat.product_tag_set({"benefits": ["cap_am"], "concerns": [], "skin_types": []}, "adm_x", "NOW")
    assert s == {"cong_dung_keys": ["cap_am"], "van_de_da_keys": [], "loai_da_keys": [], "tag_source": "llm_approved",
                 "tag_updated_at": "NOW", "tag_updated_by": "adm_x"}
    assert aat.change_event(5, 1) == {"type": "product_updated", "ma_san_pham": "5", "reason": "tags",
                                      "source": "llm_approved", "ts": "1"}


def test_actor_hash_format():
    h = aat.actor_hash(salt="")
    assert h.startswith("adm_") and len(h) == 20
    assert h == aat.actor_hash("AUTO_POLICY_V1 ", salt="")
    assert h != aat.actor_hash(salt="other")
