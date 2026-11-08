import json

from tagging.labeler import (
    MAX_ITEMS, ProductInput, build_suggestion_doc, build_system_prompt, build_user_message, diff_tags,
    load_vocabulary, parse_llm_output,
)

VOCAB = {
    "benefits": {"cap_am": "Cấp ẩm", "lam_diu": "Làm dịu", "sang_da": "Sáng da", "phuc_hoi": "Phục hồi",
                 "kiem_dau": "Kiềm dầu", "chong_lao_hoa": "Chống lão hóa"},
    "concerns": {"mun": "Mụn", "kho_da": "Khô"},
    "skin_types": {"da_dau": "Da dầu", "da_kho": "Da khô"},
}


def reply(**kw):
    base = {"benefits": [], "concerns": [], "skin_types": [], "evidence": []}
    base.update(kw)
    return json.dumps(base, ensure_ascii=False)


def test_valid_reply():
    p = parse_llm_output(reply(benefits=["cap_am", "lam_diu"], concerns=["kho_da"], skin_types=["da_kho"],
                               evidence=["Có Sodium Hyaluronate"]), VOCAB)
    assert p.ok and p.benefits == ["cap_am", "lam_diu"] and p.concerns == ["kho_da"] and p.skin_types == ["da_kho"]
    assert p.evidence == ["Có Sodium Hyaluronate"] and p.dropped == []


def test_unknown_keys_are_dropped_and_reported():
    p = parse_llm_output(reply(benefits=["cap_am", "tang_chieu_cao"], concerns=["mun_xyz"]), VOCAB)
    assert p.benefits == ["cap_am"] and p.concerns == []
    assert any("tang_chieu_cao" in d for d in p.dropped) and any("mun_xyz" in d for d in p.dropped)


def test_too_many_benefits_cut_to_four():
    p = parse_llm_output(reply(benefits=["cap_am", "lam_diu", "sang_da", "phuc_hoi", "kiem_dau", "chong_lao_hoa"]), VOCAB)
    assert MAX_ITEMS["benefits"] == 4
    assert p.benefits == ["cap_am", "lam_diu", "sang_da", "phuc_hoi"]
    assert any("cắt 6 -> 4" in d for d in p.dropped)


def test_duplicates_removed():
    assert parse_llm_output(reply(benefits=["cap_am", "cap_am"]), VOCAB).benefits == ["cap_am"]


def test_broken_json_is_an_error_not_an_exception():
    for bad in ("", "không phải json", '{"benefits": ["cap_am"', "[]", "null", None):
        p = parse_llm_output(bad, VOCAB)
        assert not p.ok and p.error and p.benefits == []


def test_code_fence_and_prose_are_tolerated():
    body = reply(benefits=["cap_am"])
    assert parse_llm_output("```json\n" + body + "\n```", VOCAB).benefits == ["cap_am"]
    assert parse_llm_output("Đây là kết quả:\n" + body + "\nHy vọng hữu ích", VOCAB).benefits == ["cap_am"]


def test_wrong_types_do_not_crash():
    p = parse_llm_output(json.dumps({"benefits": 5, "concerns": {"a": 1}, "skin_types": "da_dau", "evidence": 3}), VOCAB)
    assert p.ok and p.benefits == [] and p.concerns == [] and p.skin_types == ["da_dau"] and p.evidence == []


def test_missing_groups_default_empty_and_evidence_limited():
    p = parse_llm_output(json.dumps({"evidence": ["x" * 500] * 10}), VOCAB)
    assert p.ok and p.benefits == [] and len(p.evidence) == 6 and all(len(e) <= 200 for e in p.evidence)


def test_diff_tags():
    d = diff_tags({"benefits": ["cap_am", "lam_diu"], "concerns": [], "skin_types": ["da_kho"]},
                  {"benefits": ["cap_am", "sang_da"], "concerns": ["mun"], "skin_types": ["da_kho"]})
    assert d["benefits"] == {"added": ["lam_diu"], "removed": ["sang_da"]}
    assert d["concerns"] == {"added": [], "removed": ["mun"]}
    assert d["skin_types"] == {"added": [], "removed": []} and d["differs"] is True
    same = diff_tags({"benefits": ["a"]}, {"benefits": ["a"]})
    assert same["differs"] is False


def test_prompt_lists_only_vocabulary_and_forbids_new_keys():
    sys_prompt = build_system_prompt(VOCAB)
    for key in ("cap_am", "kho_da", "da_dau"):
        assert f"- {key}:" in sys_prompt
    assert "Không tự tạo key mới" in sys_prompt and "Tối đa 4 công dụng" in sys_prompt


def test_user_message_truncates_and_carries_only_product_facts():
    product = ProductInput(7, "Kem A", "Kem dưỡng", tuple(f"ing{i}" for i in range(50)), "m" * 5000)
    msg = build_user_message(product)
    assert "ing29" in msg and "ing30" not in msg
    assert len(msg) < 1400 and "@" not in msg


def test_real_vocabulary_matches_yaml():
    vocab = load_vocabulary()
    assert {"cap_am", "lam_diu"} <= set(vocab["benefits"]) and "mun" in vocab["concerns"] and "da_dau" in vocab["skin_types"]


def test_suggestion_doc_shape():
    parsed = parse_llm_output(reply(benefits=["cap_am"], evidence=["e"]), VOCAB)
    doc = build_suggestion_doc(7, parsed, {"benefits": ["sang_da"]}, {}, "gpt-x", {"total_tokens": 12, "junk": "x"})
    assert doc["status"] == "pending" and doc["reviewed_by"] is None and doc["reviewed_at"] is None
    assert doc["suggested"] == {"benefits": ["cap_am"], "concerns": [], "skin_types": []}
    assert doc["rule"]["benefits"] == ["sang_da"] and doc["diff"]["differs"] is True
    assert doc["usage"] == {"total_tokens": 12} and doc["model"] == "gpt-x" and doc["ma_san_pham"] == 7


def test_accent_and_case_variants_of_real_keys_are_accepted():
    vocab = {**VOCAB, "concerns": {"mun": "Mụn", "kho_da": "Khô"}}
    p = parse_llm_output(reply(concerns=["khô_da", "Mun"], benefits=["Cấp-Ẩm", "mun"]), vocab)
    assert p.concerns == ["kho_da", "mun"]
    assert p.benefits == ["cap_am"]                       # "mun" is a concern key: never accepted as a benefit
    assert any("mun" in d for d in p.dropped)
