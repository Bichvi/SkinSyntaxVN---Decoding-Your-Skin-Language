"""Best-of-N script generation: N drafts, deterministic filter, LLM pairwise judge."""
from __future__ import annotations

import re

from config import SCRIPT_CANDIDATES, logger
from core.purpose import LIVE_AND_PRODUCT, PRODUCT_VIDEO
from core.script_text import sanitize_external_retailer_mentions, split_labeled_sections
from core.text_budget import clip_prompt_text, clip_text
from engines.script_validator import _deterministic_issues, price_data_lines
from providers.llm_provider import LLMProvider

CLICHES = ("bạn có biết", "hãy gặp", "tóm lại", "sản phẩm tuyệt vời", "chân ái", "thần thánh",
           "trải nghiệm sự khác biệt", "đừng bỏ lỡ", "đã được chứng minh")

_PRODUCT_VIDEO_LABELS = ("hook", "problem", "product_intro", "specs", "ingredients", "how_to_use", "wrap_up")
_LIVE_LABELS = ("opening", "hook", "product_intro", "key_benefits", "how_to_use", "price", "cta", "closing")
_JUDGE_BUDGET = 7600

_JUDGE_SYSTEM = """Bạn là biên tập viên nội dung video skincare tiếng Việt, khó tính và công bằng. Hãy so sánh hai kịch bản A và B viết cho cùng một sản phẩm, để AI Idol Syna đọc bằng giọng máy. Chấm mỗi bản từ 1 đến 10 cho từng tiêu chí:
- hook: câu mở có phải một khoảnh khắc cụ thể khiến người xem muốn nghe tiếp không.
- flow: các phần có nối với nhau thành một câu chuyện liền mạch không, hay rời rạc như danh sách.
- natural: có giống người thật đang nói chuyện không; trừ điểm câu sáo, lặp ý, lặp tên sản phẩm, câu quá dài, chữ số hoặc ký hiệu khó đọc.
- faithful: hoạt chất, công dụng, con số và giá có khớp thông tin sản phẩm không; trừ nặng nếu bịa, thổi phồng hoặc hứa hẹn hiệu quả.
Không ưu tiên bản dài hơn chỉ vì dài. Không ưu tiên bản đứng trước. Viết reason ngắn gọn bằng tiếng Việt. Trả về JSON đúng schema."""

_SCORE_SCHEMA = {
    "type": "object",
    "properties": {key: {"type": "integer"} for key in ("hook", "flow", "natural", "faithful")},
    "required": ["hook", "flow", "natural", "faithful"],
    "additionalProperties": False,
}
_JUDGE_SCHEMA = {
    "type": "object",
    "properties": {
        "a": _SCORE_SCHEMA,
        "b": _SCORE_SCHEMA,
        "winner": {"type": "string", "enum": ["A", "B"]},
        "reason": {"type": "string"},
    },
    "required": ["a", "b", "winner", "reason"],
    "additionalProperties": False,
}


def _rotated_models(models, start: int) -> list:
    models = list(models)
    if not models:
        return models
    start %= len(models)
    return models[start:] + models[:start]


def _generate(system: str, user: str, n: int) -> tuple[list[str], list[int]]:
    """Drafts plus the candidate index each came from; candidate i starts on model i."""
    drafts: list[str] = []
    indexes: list[int] = []
    last_error: Exception | None = None
    for i in range(n):
        try:
            llm = LLMProvider()
            llm.models = _rotated_models(llm.models, i)
            drafts.append(sanitize_external_retailer_mentions(llm.invoke(system, user)))
            indexes.append(i)
        except Exception as exc:
            last_error = exc
            logger.warning("[SCRIPT-SELECTOR] candidate %s failed: %s", i, exc)
    if not drafts:
        raise RuntimeError(f"All script candidates failed: {last_error}")
    return drafts, indexes


def generate_candidates(system: str, user: str, n: int) -> list[str]:
    return _generate(system, user, n)[0]


def expected_labels(purpose: str) -> tuple[str, ...]:
    if purpose == PRODUCT_VIDEO:
        return _PRODUCT_VIDEO_LABELS
    if purpose == LIVE_AND_PRODUCT:
        return _LIVE_LABELS
    return ()


def hard_problems(script: str, snapshot: dict, purpose: str) -> list[str]:
    problems = [issue.code for issue in _deterministic_issues(script, snapshot, purpose)
                if issue.severity == "error"]
    present = {label for label, _ in split_labeled_sections(script)}
    problems += [f"MISSING_SECTION:{label}" for label in expected_labels(purpose) if label not in present]
    return problems


def soft_penalty(script: str, snapshot: dict) -> int:
    lowered = str(script or "").lower()
    penalty = sum(lowered.count(phrase) for phrase in CLICHES)
    body = "\n".join(
        line for line in str(script or "").splitlines()
        if not re.match(r"^\s*\[[^\]]*\]\s*:?\s*$", line)
    )
    name = str(snapshot.get("name") or "").strip()
    if name:
        body = re.sub(re.escape(name), " ", body, flags=re.IGNORECASE)
    return penalty + len(re.findall(r"\d+", body))


def _facts(snapshot: dict) -> str:
    return "\n".join([
        f"- Tên: {clip_text(snapshot.get('name'), 200)}",
        f"- Thương hiệu: {clip_text(snapshot.get('brand'), 100)}",
        price_data_lines(snapshot),
        f"- Thành phần: {clip_text(snapshot.get('ingredients'), 400)}",
        f"- Mô tả: {clip_text(snapshot.get('description'), 600)}",
    ])


def _judge_user(snapshot: dict, a: str, b: str) -> str:
    facts = _facts(snapshot)
    template = "THÔNG TIN SẢN PHẨM:\n{facts}\n\nKỊCH BẢN A:\n{a}\n\nKỊCH BẢN B:\n{b}"
    overhead = len(_JUDGE_SYSTEM) + len(template.format(facts=facts, a="", b=""))
    share = max(500, (_JUDGE_BUDGET - overhead) // 2)
    return template.format(facts=facts, a=clip_prompt_text(a, share), b=clip_prompt_text(b, share))


def _pair_total(scores: dict) -> int:
    return (int(scores["hook"]) + int(scores["flow"]) + int(scores["natural"])
            + 2 * int(scores["faithful"]))


def _decide(result: dict) -> str:
    a, b = result["a"], result["b"]
    a_bad, b_bad = int(a["faithful"]) < 6, int(b["faithful"]) < 6
    if a_bad != b_bad:
        return "B" if a_bad else "A"
    total_a, total_b = _pair_total(a), _pair_total(b)
    if total_a != total_b:
        return "A" if total_a > total_b else "B"
    return "B" if str(result.get("winner")).upper() == "B" else "A"


def _judge(snapshot: dict, a: str, b: str) -> dict:
    result = LLMProvider().invoke_json_schema(
        _JUDGE_SYSTEM, _judge_user(snapshot, a, b), schema=_JUDGE_SCHEMA, schema_name="script_pair_judgement")
    return {"scores": {"a": result["a"], "b": result["b"]}, "winner": _decide(result),
            "reason": str(result.get("reason") or "")}


def select_best_script(system: str, user: str, snapshot: dict, purpose: str,
                       n: int = SCRIPT_CANDIDATES) -> tuple[str, dict]:
    drafts, starts = _generate(system, user, n)
    report: dict = {"n": n, "candidates": [], "judgements": [], "chosen": 0, "reason": "single"}
    if len(drafts) == 1:
        report["candidates"] = [{"index": 0, "hard": [], "soft_penalty": soft_penalty(drafts[0], snapshot),
                                 "words": len(drafts[0].split()), "model_start": starts[0]}]
        logger.info("[SCRIPT-SELECTOR] n=%s generated=1 chosen=0 reason=single", n)
        return drafts[0], report

    hard = [hard_problems(d, snapshot, purpose) for d in drafts]
    soft = [soft_penalty(d, snapshot) for d in drafts]
    for i, draft in enumerate(drafts):
        report["candidates"].append({"index": i, "hard": hard[i], "soft_penalty": soft[i],
                                     "words": len(draft.split()), "model_start": starts[i]})
    pool = [i for i in range(len(drafts)) if not hard[i]]
    if not pool:
        fewest = min(len(h) for h in hard)
        pool = [i for i in range(len(drafts)) if len(hard[i]) == fewest]

    if len(pool) == 1:
        report["chosen"], report["reason"] = pool[0], "only_clean"
    else:
        champion, reason = pool[0], "judge"
        for challenger in pool[1:]:
            entry = {"a": champion, "b": challenger}
            try:
                verdict = _judge(snapshot, drafts[champion], drafts[challenger])
                entry.update(scores=verdict["scores"], winner=verdict["winner"], reason=verdict["reason"])
                champion = champion if verdict["winner"] == "A" else challenger
            except Exception as exc:
                reason = "fallback"
                keep = soft[challenger] >= soft[champion]
                entry.update(winner="A" if keep else "B", error=str(exc))
                champion = champion if keep else challenger
                logger.warning("[SCRIPT-SELECTOR] judge failed (%s vs %s): %s", entry["a"], entry["b"], exc)
            report["judgements"].append(entry)
        report["chosen"], report["reason"] = champion, reason

    logger.info("[SCRIPT-SELECTOR] n=%s generated=%s hard=%s soft=%s chosen=%s reason=%s",
                n, len(drafts), [len(h) for h in hard], soft, report["chosen"], report["reason"])
    return drafts[report["chosen"]], report
