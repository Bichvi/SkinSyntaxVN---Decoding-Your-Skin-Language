"""Tests for chatbot_service.profile_context (pure) + static checks on chatbot_flask.py (never imported)."""
import ast
import pathlib

from chatbot_service.profile_context import normalize_profile, profile_context_lines

SRC = pathlib.Path(__file__).resolve().parent.parent / "chatbot_service" / "chatbot_flask.py"


def test_agent_style():
    n = normalize_profile({"skin_type": "Da dầu", "concerns": ["Mụn", "Thâm mụn"], "goal": "Dưỡng sáng, mờ thâm nám"})
    assert n == {"skin_type": "Da dầu", "skin_issues": ["Mụn", "Thâm mụn"], "avoid_ingredients": [], "goal": "Dưỡng sáng, mờ thâm nám"}


def test_php_legacy_style():
    n = normalize_profile({"loai_da": "Da khô", "concerns": ["Lão hóa"], "muc_tieu_cham_soc": "Căng bóng",
                           "avoid_ingredients": ["Cồn", "Hương liệu"]})
    assert n["skin_type"] == "Da khô"
    assert n["skin_issues"] == ["Lão hóa"]
    assert n["goal"] == "Căng bóng"
    assert n["avoid_ingredients"] == ["Cồn", "Hương liệu"]


def test_old_style_comma_strings():
    n = normalize_profile({"loai_da": "Da hỗn hợp", "tinh_trang_da": "Mụn, Lỗ chân lông to",
                           "thanh_phan_can_tranh": "Cồn, Paraben"})
    assert n["skin_issues"] == ["Mụn", "Lỗ chân lông to"]
    assert n["avoid_ingredients"] == ["Cồn", "Paraben"]
    assert n["goal"] is None


def test_placeholders_dropped():
    n = normalize_profile({"skin_type": "chưa xác định", "concerns": ["Không có", " ", "Mụn"],
                           "avoid_ingredients": "Không có / Không quan tâm", "goal": "KHÔNG CÓ"})
    assert n == {"skin_type": None, "skin_issues": ["Mụn"], "avoid_ingredients": [], "goal": None}


def test_non_dict():
    for bad in (None, "x", 5, []):
        assert normalize_profile(bad) == {"skin_type": None, "skin_issues": [], "avoid_ingredients": [], "goal": None}


def test_lines():
    n = normalize_profile({"skin_type": "Da dầu", "concerns": ["Mụn", "Thâm"], "avoid_ingredients": ["Cồn"], "goal": "Sáng da"})
    assert profile_context_lines(n) == [
        "- Loại da của khách: Da dầu",
        "- Tình trạng da: Mụn, Thâm",
        "- Thành phần cần tránh: Cồn",
        "- Mục tiêu chăm sóc: Sáng da",
    ]
    assert profile_context_lines(normalize_profile({})) == []


def _tree():
    text = SRC.read_text(encoding="utf-8")
    return text, ast.parse(text)


def test_general_prompt_has_rich_context():
    _, tree = _tree()
    val = None
    for node in tree.body:
        if isinstance(node, ast.Assign) and any(getattr(t, "id", "") == "GENERAL_CONVERSATION_SYSTEM_PROMPT" for t in node.targets):
            val = node.value.value
    assert val is not None and "{rich_context}" in val


def test_general_prompt_build_replaces_rich_context():
    text, tree = _tree()
    found = False
    for node in ast.walk(tree):
        if isinstance(node, ast.Assign) and any(getattr(t, "id", "") == "prompt" for t in node.targets):
            seg = ast.get_source_segment(text, node.value) or ""
            if seg.lstrip().startswith("GENERAL_CONVERSATION_SYSTEM_PROMPT"):
                assert '.replace("{rich_context}", rich_context)' in seg
                found = True
    assert found
