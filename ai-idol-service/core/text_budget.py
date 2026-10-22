import re


def clean_text(value) -> str:
    """Collapse noisy database whitespace before placing text in an LLM prompt."""
    if value is None:
        return ""
    return re.sub(r"\s+", " ", str(value)).strip()


def clip_text(value, max_chars: int, marker: str = " … [đã rút gọn] … ") -> str:
    """Keep both ends so product facts and trailing cautions survive compaction."""
    text = clean_text(value)
    if max_chars <= 0:
        return ""
    if len(text) <= max_chars:
        return text
    if max_chars <= len(marker) + 20:
        return text[:max_chars]
    remaining = max_chars - len(marker)
    head = int(remaining * 0.72)
    tail = remaining - head
    return text[:head].rstrip() + marker + text[-tail:].lstrip()


def clean_prompt_text(value) -> str:
    """Normalize whitespace but keep line breaks, so prompt structure survives."""
    if value is None:
        return ""
    text = str(value).replace("\r\n", "\n").replace("\r", "\n")
    lines = [re.sub(r"[ \t]+", " ", line).strip() for line in text.split("\n")]
    return re.sub(r"\n{3,}", "\n\n", "\n".join(lines)).strip()


def clip_prompt_text(value, max_chars: int, marker: str = " … [đã rút gọn] … ") -> str:
    """Like clip_text, but newline-preserving (for whole prompts, not DB fields)."""
    text = clean_prompt_text(value)
    if max_chars <= 0:
        return ""
    if len(text) <= max_chars:
        return text
    if max_chars <= len(marker) + 20:
        return text[:max_chars]
    remaining = max_chars - len(marker)
    head = int(remaining * 0.72)
    tail = remaining - head
    return text[:head].rstrip() + marker + text[-tail:].lstrip()


def fit_message_pair(system_prompt: str, user_prompt: str, max_chars: int) -> tuple[str, str]:
    """Fit a system/user pair into a conservative character budget."""
    system = clean_prompt_text(system_prompt)
    user = clean_prompt_text(user_prompt)
    if len(system) + len(user) <= max_chars:
        return system, user

    # System safety rules are more important than supplementary user context.
    system_budget = min(len(system), max(1800, int(max_chars * 0.45)))
    user_budget = max(500, max_chars - system_budget)
    system_budget = max(500, max_chars - user_budget)
    return clip_prompt_text(system, system_budget), clip_prompt_text(user, user_budget)
