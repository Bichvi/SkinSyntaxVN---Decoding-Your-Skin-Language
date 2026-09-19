"""Pure consultation routine rules."""
from __future__ import annotations
import re



def owned_routine_steps(message, history=()):
    from .request_rules import CATEGORY_WORDS, folded
    owned = set()
    for text in [h['content'] for h in history if h.get('role') == 'user'] + [message]:
        for clause in re.findall(r'(?:da co|dang dung|co san)\s+([^,;.!?]+)', folded(text)):
            for category, aliases in CATEGORY_WORDS.items():
                if any(re.search(r'\b' + re.escape(alias) + r'\b', clause) for alias in aliases):
                    owned.add(category)
    return sorted(owned)


def choose_routine(docs, budget=None, owned=()):
    """Choose once AFTER all candidate sources are merged.

    Three basic roles first; extras only within budget. Enumeration is bounded to
    three candidates per role. Incomplete output is explicitly reported.
    """
    roles = [r for r in ("Sữa Rửa Mặt", "Kem / Gel / Dầu Dưỡng", "Chống Nắng Da Mặt") if r not in owned]
    groups = {role: [] for role in roles}
    for doc in docs:
        if doc.metadata.get("loai_san_pham") in groups:
            try:
                price = int(doc.metadata.get("gia_ban") or 0)
            except (ValueError, TypeError):
                continue
            if price > 0:
                groups[doc.metadata["loai_san_pham"]].append(doc)
    from itertools import product
    # Include affordable alternatives, otherwise the first expensive hits can
    # make a feasible routine appear incomplete.
    choices = []
    for group in groups.values():
        pool = group[:3]
        for doc in sorted(group, key=lambda d: int(d.metadata['gia_ban']))[:3]:
            if doc not in pool:
                pool.append(doc)
        choices.append([None] + pool)
    best, best_key = [], (-1, -1, float("-inf"))
    for combo in product(*choices):
        chosen = [d for d in combo if d is not None]
        total = sum(int(d.metadata["gia_ban"]) for d in chosen)
        if budget is not None and total > budget:
            continue
        relevance = sum(-groups[r].index(d) for r, d in zip(roles, combo) if d is not None)
        key = (len(chosen), relevance, -total)
        if key > best_key:
            best, best_key = chosen, key
    missing = [r for r in roles if not any(d.metadata.get("loai_san_pham") == r for d in best)]
    return best, missing
