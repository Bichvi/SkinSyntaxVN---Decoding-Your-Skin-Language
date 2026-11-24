"""Strong active groups shared between a suggested product and a product already in the cart.

Warning-only helper (products are never filtered): used by the label scan alternatives and the cart insights.
"""

from __future__ import annotations

from functools import lru_cache
from typing import Callable, Iterable, Mapping

OVERLAP_GROUP_LABELS = {
    "retinoids": "retinoid",
    "exfoliating_acids": "axit tẩy da chết (AHA/BHA)",
    "benzoyl_peroxide": "benzoyl peroxide",
    "vitamin_c": "vitamin C",
}
MAX_OVERLAPS = 4


@lru_cache(maxsize=1)
def overlap_groups() -> dict[str, frozenset[str]]:
    """Members of the watched ingredient groups, read from the reviewed conflict_rules.yaml."""

    try:
        import yaml
        from graph_sync.conflicts import CONFLICT_RULES_PATH

        with open(CONFLICT_RULES_PATH, encoding="utf-8") as handle:
            raw = (yaml.safe_load(handle) or {}).get("groups") or {}
        return {g: frozenset(str(x) for x in (raw.get(g) or [])) for g in OVERLAP_GROUP_LABELS}
    except Exception:
        return {g: frozenset() for g in OVERLAP_GROUP_LABELS}


def active_overlaps(cand_keys: set[str], cart_keys: Mapping[str, Iterable[str]],
                    name_of: Callable[[str], str] | None = None, cand_rinse_off: bool = False,
                    cart_rinse_off: Mapping[str, bool] | None = None) -> list[dict]:
    """Watched active groups the candidate shares with a cart product.

    ``level`` is "info" (soft notice) when either side of the pair is wash-off, else "warn". When several cart
    products match a group, the first leave-on one is reported so the stronger warning wins.
    """

    rinse = cart_rinse_off or {}
    out: list[dict] = []
    for group, members in overlap_groups().items():
        if not (cand_keys & members):
            continue
        first: str | None = None
        chosen: str | None = None
        for pid, keys in cart_keys.items():
            if not any(k in members for k in keys):
                continue
            if first is None:
                first = pid
            if not cand_rinse_off and not rinse.get(pid, False):
                chosen = pid
                break
        pid = chosen if chosen is not None else first
        if pid is None:
            continue
        info = cand_rinse_off or rinse.get(pid, False)
        out.append({"group": group, "label_vi": OVERLAP_GROUP_LABELS[group], "cart_product_id": str(pid),
                    "cart_product_name": name_of(pid) if name_of else "", "level": "info" if info else "warn"})
    return out[:MAX_OVERLAPS]


def rinse_off_of(chains: Mapping[str, Iterable[str]], rinse_categories: Iterable[str]) -> dict[str, bool]:
    """Per cart product: does its category chain touch a wash-off category."""

    rset = set(rinse_categories)
    return {str(pid): bool(set(chain or ()) & rset) for pid, chain in chains.items()}
