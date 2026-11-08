"""LLM tag suggestions for products (U07 part B). Pure: prompt building + output validation + diffing.

The model may only choose keys from the project vocabulary (``data/graph/synonyms.yaml``). Whatever it
returns is parsed defensively (broken JSON, code fences, unknown keys, too many items) and nothing here
touches Mongo or the network.
"""

from __future__ import annotations

import json
import re
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any, Mapping, Sequence

from graph_sync.curation import clean_keys
from graph_sync.normalize import load_synonym_groups, slugify

PROMPT_VERSION = "u07-v1"
GROUPS: tuple[str, ...] = ("benefits", "concerns", "skin_types")
# Output limits (benefits: spec says at most 4).
MAX_ITEMS: dict[str, int] = {"benefits": 4, "concerns": 4, "skin_types": 3}
MAX_EVIDENCE = 6
MAX_EVIDENCE_CHARS = 200
MAX_INGREDIENTS = 30
MAX_DESCRIPTION_CHARS = 800

Vocabulary = dict[str, dict[str, str]]          # group -> {key: Vietnamese name}


def load_vocabulary(path: str | None = None) -> Vocabulary:
    """Canonical key -> display name per tag group, from the synonyms YAML."""

    raw = load_synonym_groups(path)
    return {
        group: {str(key): str((spec or {}).get("name") or key) for key, spec in raw.get(group, {}).items()}
        for group in GROUPS
    }


def load_hints(path: str | None = None) -> dict[str, dict[str, list[str]]]:
    raw = load_synonym_groups(path)
    return {
        group: {str(k): [str(a) for a in ((spec or {}).get("aliases") or [])][:3] for k, spec in raw.get(group, {}).items()}
        for group in GROUPS
    }


@dataclass(frozen=True)
class ProductInput:
    ma_san_pham: Any
    name: str
    category: str = ""
    ingredients: tuple[str, ...] = ()
    description: str = ""


def truncate(text: str, limit: int) -> str:
    text = re.sub(r"\s+", " ", str(text or "")).strip()
    return text if len(text) <= limit else text[: limit - 1].rstrip() + "…"


def build_system_prompt(vocab: Vocabulary, hints: Mapping[str, Mapping[str, Sequence[str]]] | None = None) -> str:
    def block(group: str, title: str) -> str:
        lines = []
        for key, name in vocab[group].items():
            extra = [a for a in (hints or {}).get(group, {}).get(key, [])]
            lines.append(f"- {key}: {name}" + (f" (còn gọi: {', '.join(extra)})" if extra else ""))
        return f"{title}:\n" + "\n".join(lines)

    return (
        "Bạn là chuyên gia mỹ phẩm/skincare gán nhãn cho cửa hàng mỹ phẩm Việt Nam.\n"
        "Từ tên, danh mục, thành phần và mô tả sản phẩm, hãy chọn các nhãn phù hợp NHẤT.\n\n"
        "QUY TẮC BẮT BUỘC\n"
        "1. CHỈ được chọn key trong 3 danh sách dưới đây, chép đúng từng ký tự. Không tự tạo key mới.\n"
        f"2. Tối đa {MAX_ITEMS['benefits']} công dụng (benefits), {MAX_ITEMS['concerns']} vấn đề da (concerns), "
        f"{MAX_ITEMS['skin_types']} loại da (skin_types). Ít mà chắc còn hơn nhiều mà đoán; để mảng rỗng nếu không đủ căn cứ.\n"
        "3. Chỉ gán khi có căn cứ rõ ràng trong thành phần hoặc mô tả được cung cấp. Không suy diễn từ tên thương hiệu.\n"
        "4. evidence: danh sách ngắn (tối đa 6 câu, mỗi câu < 200 ký tự, tiếng Việt) nêu thành phần/câu mô tả "
        "là căn cứ cho từng nhãn đã chọn.\n"
        "5. Chỉ trả về MỘT đối tượng JSON, không văn bản nào khác, đúng dạng:\n"
        '{"benefits": [], "concerns": [], "skin_types": [], "evidence": []}\n\n'
        + block("benefits", "CÔNG DỤNG (benefits)") + "\n\n"
        + block("concerns", "VẤN ĐỀ DA (concerns) - vấn đề mà sản phẩm giúp cải thiện") + "\n\n"
        + block("skin_types", "LOẠI DA (skin_types) - loại da phù hợp")
    )


def build_user_message(product: ProductInput) -> str:
    """Only product facts: no customer data ever goes to the model."""

    ingredients = ", ".join(product.ingredients[:MAX_INGREDIENTS]) or "(không có thông tin thành phần)"
    return (
        f"Tên sản phẩm: {truncate(product.name, 200)}\n"
        f"Danh mục: {truncate(product.category, 120) or '(không rõ)'}\n"
        f"Thành phần ({min(len(product.ingredients), MAX_INGREDIENTS)} đầu tiên): {ingredients}\n"
        f"Mô tả: {truncate(product.description, MAX_DESCRIPTION_CHARS) or '(không có)'}"
    )


@dataclass
class ParsedTags:
    benefits: list[str] = field(default_factory=list)
    concerns: list[str] = field(default_factory=list)
    skin_types: list[str] = field(default_factory=list)
    evidence: list[str] = field(default_factory=list)
    dropped: list[str] = field(default_factory=list)      # human-readable notes about what was discarded
    error: str | None = None

    @property
    def ok(self) -> bool:
        return self.error is None

    def tags(self) -> dict[str, list[str]]:
        return {group: list(getattr(self, group)) for group in GROUPS}


def _fold_key(item: Any, allowed: Mapping[str, str]) -> Any:
    if not isinstance(item, str) or item.strip() in allowed:
        return item
    folded = slugify(item)
    return folded if folded in allowed else item


_FENCE = re.compile(r"^```[a-zA-Z0-9_-]*\s*|\s*```$")


def _extract_json(text: str) -> Any:
    """Decode the first JSON object in ``text`` (handles ```json fences and surrounding prose)."""

    cleaned = _FENCE.sub("", str(text or "").strip()).strip()
    try:
        return json.loads(cleaned)
    except (TypeError, ValueError):
        pass
    start = cleaned.find("{")
    if start < 0:
        raise ValueError("không tìm thấy đối tượng JSON")
    value, _ = json.JSONDecoder().raw_decode(cleaned[start:])
    return value


def parse_llm_output(text: str, vocab: Vocabulary) -> ParsedTags:
    """Validate the model's reply against the vocabulary. Never raises."""

    try:
        data = _extract_json(text)
    except (ValueError, TypeError) as exc:
        return ParsedTags(error=f"JSON hỏng: {exc}"[:200])
    if not isinstance(data, dict):
        return ParsedTags(error="JSON không phải đối tượng")

    out = ParsedTags()
    for group in GROUPS:
        raw = data.get(group, [])
        if raw is None:
            raw = []
        if not isinstance(raw, (list, tuple, str)):
            out.dropped.append(f"{group}: kiểu dữ liệu sai")
            continue
        items = [raw] if isinstance(raw, str) else list(raw)
        # Cosmetic variants of a real key ("khô_da", "Kho-Da") are accepted after slugify; anything else is dropped.
        items = [_fold_key(i, vocab[group]) for i in items]
        valid = clean_keys(items, vocab[group])
        unknown = [str(i) for i in items if str(i or "").strip() and str(i).strip() not in vocab[group]]
        if unknown:
            out.dropped.append(f"{group}: bỏ key lạ {unknown[:5]}")
        if len(valid) > MAX_ITEMS[group]:
            out.dropped.append(f"{group}: cắt {len(valid)} -> {MAX_ITEMS[group]}")
            valid = valid[: MAX_ITEMS[group]]
        setattr(out, group, valid)

    evidence = data.get("evidence", [])
    if isinstance(evidence, str):
        evidence = [evidence]
    if isinstance(evidence, (list, tuple)):
        out.evidence = [
            truncate(e, MAX_EVIDENCE_CHARS) for e in evidence if isinstance(e, str) and e.strip()
        ][:MAX_EVIDENCE]
    return out


def diff_tags(suggested: Mapping[str, Sequence[str]], rule: Mapping[str, Sequence[str]]) -> dict[str, Any]:
    """Per group: keys only the LLM proposes (``added``), only the rules give (``removed``)."""

    out: dict[str, Any] = {}
    differs = False
    for group in GROUPS:
        s, r = list(suggested.get(group, [])), list(rule.get(group, []))
        added = [k for k in s if k not in r]
        removed = [k for k in r if k not in s]
        out[group] = {"added": added, "removed": removed}
        differs = differs or bool(added or removed)
    out["differs"] = differs
    return out


def build_suggestion_doc(
    ma_san_pham: Any,
    parsed: ParsedTags,
    rule: Mapping[str, Sequence[str]],
    current: Mapping[str, Sequence[str]] | None,
    model: str,
    usage: Mapping[str, Any] | None,
    now: datetime | None = None,
) -> dict[str, Any]:
    """The ``tag_suggestions`` document (status pending, never auto-approved)."""

    suggested = parsed.tags()
    return {
        "ma_san_pham": ma_san_pham,
        "suggested": suggested,
        "evidence": list(parsed.evidence),
        "rule": {g: list(rule.get(g, [])) for g in GROUPS},
        "current": {g: list((current or {}).get(g, [])) for g in GROUPS},
        "diff": diff_tags(suggested, rule),
        "dropped": list(parsed.dropped),
        "model": model,
        "usage": {k: int(v) for k, v in (usage or {}).items() if isinstance(v, (int, float))},
        "prompt_version": PROMPT_VERSION,
        "created_at": now or datetime.now(timezone.utc),
        "status": "pending",
        "reviewed_by": None,
        "reviewed_at": None,
    }
