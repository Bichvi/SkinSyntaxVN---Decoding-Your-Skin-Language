import pytest

from label_service import summary

RAW = "SECRET-RAW-TEXT Water, Glycerin"
VERDICTS = ["good", "caution", "avoid", "neutral", "unknown"]


def base(**kw):
    data = {"status": "ok", "product": {"name": "Toner AHA"}, "ingredients": {"raw_text": RAW},
            "conflicts": [], "skin_fit": {"verdict": "good"}, "schedule": [], "warnings": []}
    data.update(kw)
    return data


def test_error_statuses_have_distinct_guidance_and_no_raw_text():
    texts = set()
    for status in ("not_label", "unreadable", "vision_unavailable"):
        text = summary.render({"status": status, "ingredients": {"raw_text": RAW}})
        assert text and "SECRET" not in text
        texts.add(text)
    assert len(texts) == 3


@pytest.mark.parametrize("verdict, fragment", [
    ("good", "có vẻ hợp"), ("caution", "lưu ý"), ("avoid", "không phù hợp"),
    ("neutral", "không thấy điểm cần lưu ý"), ("unknown", "loại da"),
])
def test_ok_without_conflict_per_verdict(verdict, fragment):
    text = summary.render(base(skin_fit={"verdict": verdict}))
    assert fragment in text and "Toner AHA" in text
    assert "cùng buổi" not in text
    assert ("có vẻ hợp" in text) == (verdict == "good")   # "hợp" alone also sits inside "không phù hợp"


@pytest.mark.parametrize("verdict", VERDICTS)
def test_ok_with_high_conflict_names_cart_product(verdict):
    conflicts = [{"cart_product_id": "456", "severity": "high", "cart_product_name": "Retinol Serum"}]
    schedule = [{"cart_product_id": "456", "kind": "split_session"}]
    text = summary.render(base(skin_fit={"verdict": verdict}, conflicts=conflicts, schedule=schedule))
    assert "Retinol Serum" in text and "cùng buổi" in text and "sáng và tối" in text


def test_conflict_without_name_uses_generic_wording_and_non_high_is_ignored():
    assert "sản phẩm trong giỏ" in summary.render(base(conflicts=[{"severity": "high"}]))
    medium = [{"severity": "medium", "cart_product_name": "X"}]
    assert "cùng buổi" not in summary.render(base(conflicts=medium))


def test_no_split_sentence_without_split_schedule():
    conflicts = [{"severity": "high", "cart_product_name": "X"}]
    schedule = [{"kind": "alternate_days"}]
    assert "sáng và tối" not in summary.render(base(conflicts=conflicts, schedule=schedule))


def test_low_recognition_adds_not_enough_data():
    text = summary.render(base(skin_fit={"verdict": "neutral"}, warnings=["low_recognition"]))
    assert "chưa đủ cơ sở" in text


def test_never_includes_raw_text():
    for verdict in VERDICTS:
        assert "SECRET" not in summary.render(base(skin_fit={"verdict": verdict}))


def test_missing_product_name_and_skin_fit():
    assert "loại da" in summary.render({"status": "ok"})
