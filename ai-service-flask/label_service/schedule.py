"""Split conflicting products across the day (AM/PM) or across days. Pure."""

from __future__ import annotations

from typing import Mapping, Sequence

from .skin_fit import family_table, group_table

SLOT_PREFERENCE = {"retinoids": "pm", "exfoliating_acids": "pm", "benzoyl_peroxide": "pm", "vitamin_c": "am"}
_OTHER = {"am": "pm", "pm": "am"}
_SLOT_VI = {"am": "buổi sáng", "pm": "buổi tối"}


def _groups() -> dict[str, frozenset[str]]:
    table, families = group_table(), family_table()
    return {
        "retinoids": frozenset(table["retinoids"]),
        "exfoliating_acids": frozenset(table["exfoliating_acids"]),
        "benzoyl_peroxide": frozenset(table["benzoyl_peroxide"]),
        "vitamin_c": frozenset(families["ascorbic_acid"]),   # pure vitamin C and its derivatives
    }


_GROUPS = _groups()


def _slot_for(keys: set[str]) -> str | None:
    """Preferred slot of a product: pm wins when it holds any pm-preferring active."""

    slots = {slot for group, slot in SLOT_PREFERENCE.items() if keys & _GROUPS[group]}
    if "pm" in slots:
        return "pm"
    return "am" if slots else None


def needs_sunscreen(keys: set[str]) -> bool:
    return bool(keys & (_GROUPS["retinoids"] | _GROUPS["exfoliating_acids"]))


def _split(scan: set[str], cart: set[str]) -> tuple[str | None, str | None, str]:
    """Return (scan_slot, cart_slot, kind) for a same_session conflict."""

    scan_pref, cart_pref = _slot_for(scan), _slot_for(cart)
    if scan_pref and cart_pref:
        if scan_pref == cart_pref == "pm":
            # Plan 4.2/8.3: a retinoid keeps the evening, an exfoliating acid (without retinoid) moves to the morning.
            retinoids, acids = _GROUPS["retinoids"], _GROUPS["exfoliating_acids"]
            if scan & retinoids and cart & acids and not cart & retinoids:
                return "pm", "am", "split_session"
            if cart & retinoids and scan & acids and not scan & retinoids:
                return "am", "pm", "split_session"
            return None, None, "alternate_nights"
        if scan_pref == cart_pref:
            return None, None, "alternate_days"
        return scan_pref, cart_pref, "split_session"
    if scan_pref:
        return scan_pref, _OTHER[scan_pref], "split_session"
    if cart_pref:
        return _OTHER[cart_pref], cart_pref, "split_session"
    return "am", "pm", "split_session"


def plan(conflicts: Sequence[Mapping], keys_by_product: Mapping[str, set[str]], scan_id: str = "scan") -> list[dict]:
    out: list[dict] = []
    for conflict in conflicts:
        a, b = str(conflict.get("a")), str(conflict.get("b"))
        cart_id = b if a == scan_id else a
        timing = conflict.get("timing")
        scan_keys = set(keys_by_product.get(scan_id, ()))
        cart_keys = set(keys_by_product.get(cart_id, ()))
        if timing == "same_session":
            scan_slot, cart_slot, kind = _split(scan_keys, cart_keys)
            if kind == "split_session":
                reason = (f"Dùng sản phẩm vừa quét vào {_SLOT_VI[scan_slot]}, "
                          f"sản phẩm trong giỏ vào {_SLOT_VI[cart_slot]}.")
            elif kind == "alternate_nights":
                reason = "Cả hai nên dùng buổi tối: hãy dùng xen kẽ, mỗi tối một sản phẩm."
            else:
                reason = "Cả hai nên dùng buổi sáng: hãy dùng xen kẽ ngày."
        elif timing in ("same_routine", "ok_alternate_days"):
            scan_slot = cart_slot = None
            kind = "alternate_days"
            reason = "Nên dùng xen kẽ ngày: hôm nay sản phẩm này, hôm sau sản phẩm kia."
        else:
            continue
        out.append({"cart_product_id": cart_id, "scan_slot": scan_slot, "cart_slot": cart_slot,
                    "kind": kind, "reason_vi": reason})
    return out
