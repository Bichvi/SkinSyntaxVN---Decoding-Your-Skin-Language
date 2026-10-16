"""Targeted script repair: only failing sections go back to the LLM; the rest is kept verbatim."""
from __future__ import annotations

from config import logger
from core.script_text import _canonical_label, _header_label, sanitize_external_retailer_mentions
from core.text_budget import clip_text
from providers.llm_provider import LLMProvider

_REPAIR_SYSTEM = """Bạn là biên tập viên kịch bản bán mỹ phẩm tiếng Việt. Chỉ sửa đúng các lỗi được nêu, giữ nguyên ý và giọng văn.
Quy tắc: chỉ nói giá bằng đúng nguyên văn các cụm giá trong dữ liệu sản phẩm; không nhắc sàn thương mại ngoài SkinSyntax;
không cam kết trị khỏi, không kể trải nghiệm cá nhân đã dùng; không dùng ký hiệu, emoji, số viết tắt (viết phần trăm, mililít bằng chữ).
Chỉ trả về nội dung đã sửa, không giải thích, không thêm dòng tiêu đề trong ngoặc vuông."""


def _split_raw(script: str) -> list[dict]:
    """Split into blocks keeping the exact raw text: [{label, header, body}] (first block may be headerless)."""
    blocks: list[dict] = [{"label": "", "header": "", "body": []}]
    for line in str(script or "").split("\n"):
        label = _header_label(line.strip("\r"))
        if label is not None:
            blocks.append({"label": label, "header": line, "body": []})
        else:
            blocks[-1]["body"].append(line)
    return blocks


def _join(blocks: list[dict]) -> str:
    lines: list[str] = []
    for block in blocks:
        if block["header"]:
            lines.append(block["header"])
        lines.extend(block["body"])
    return "\n".join(lines)


def _issue_attr(issue, name, default=None):
    return issue.get(name, default) if isinstance(issue, dict) else getattr(issue, name, default)


def _describe(issues) -> str:
    return "\n".join(f"- [{_issue_attr(i, 'code')}] {_issue_attr(i, 'reason')}"
                     + (f" (đoạn: \"{_issue_attr(i, 'original_text')}\")" if _issue_attr(i, "original_text") else "")
                     for i in issues)


def _product_facts(snapshot: dict) -> str:
    from engines.script_validator import price_data_lines
    return (f"- Tên sản phẩm: {snapshot.get('name')}\n- Thương hiệu: {snapshot.get('brand')}\n"
            f"{price_data_lines(snapshot)}\n- Thành phần: {clip_text(snapshot.get('ingredients'), 900)}\n"
            f"- Công dụng thật: {clip_text(snapshot.get('description'), 1200)}\n"
            f"- Cách dùng: {clip_text(snapshot.get('usage'), 650)}")


def _ask(text: str, issues, snapshot: dict, scope: str) -> str:
    user = (f"{scope}:\n\"\"\"\n{clip_text(text, 6000)}\n\"\"\"\n\nLỖI CẦN SỬA:\n{_describe(issues)}\n\n"
            f"THÔNG TIN SẢN PHẨM GỐC:\n{_product_facts(snapshot)}\n\nTrả về duy nhất nội dung đã sửa.")
    return sanitize_external_retailer_mentions(LLMProvider().invoke(_REPAIR_SYSTEM, user)).strip()


def repair_script(script: str, issues, snapshot: dict, purpose: str) -> str:
    """Repair `script`. Issues with a known section only rewrite that section; section=None rewrites the whole script."""
    issues = [i for i in issues if _issue_attr(i, "severity", "error") == "error"]
    if not issues:
        return script
    blocks = _split_raw(script)
    by_label: dict[str, list] = {}
    whole: list = []
    for issue in issues:
        section = _issue_attr(issue, "section")
        label = _canonical_label(str(section)) if section else None
        if label and any(b["label"] == label for b in blocks):
            by_label.setdefault(label, []).append(issue)
        else:
            whole.append(issue)
    if whole:
        # An issue without a locatable section: the whole script is the repair unit.
        logger.info("[SCRIPT-REPAIR] whole-script repair for %d issue(s)", len(whole))
        return _ask(script, issues, snapshot, "KỊCH BẢN CẦN SỬA (giữ nguyên các dòng tiêu đề trong ngoặc vuông nếu có)")
    for block in blocks:
        group = by_label.get(block["label"])
        if not group or not block["header"]:
            continue
        logger.info("[SCRIPT-REPAIR] repairing section %s (%d issue(s))", block["label"], len(group))
        fixed = _ask("\n".join(block["body"]).strip(), group, snapshot, f"PHẦN [{block['label']}] CẦN SỬA")
        block["body"] = fixed.split("\n")
    return _join(blocks)
