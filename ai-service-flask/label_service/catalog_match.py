"""Recognise a scanned product in the shop catalog: name/brand shortlist, then ingredient-overlap confirmation."""

from __future__ import annotations

import logging
import re
from dataclasses import dataclass
from typing import Any, Callable, Mapping, Sequence

from graph_sync.ingredients import load_aliases, parse_ingredients

from .skin_fit import fold

logger = logging.getLogger(__name__)

MAX_CANDIDATES = 5
MIN_NAME_SCORE = 0.5
JACCARD_FIRST_N = 15
ACCEPT_JACCARD = 0.8
ACCEPT_JACCARD_SAME_BRAND = 0.5
ACCEPT_NAME_SCORE_SAME_BRAND = 0.8


@dataclass(frozen=True)
class Match:
    product_id: str
    name: str
    score: float
    keys: tuple[tuple[str, int | None], ...]   # the catalog product's full parsed ingredient list


GENERIC_WORDS = frozenset({"serum", "cream", "kem", "toner", "gel", "lotion", "sua", "rua", "mat", "duong", "ml", "g"})
_SIZE_RE = re.compile(r"^\d+(ml|g|gr|oz)?$")


def _tokens(text: Any) -> set[str]:
    return {t for t in re.findall(r"[a-z0-9]+", fold(text)) if len(t) >= 2}


def _significant(tokens: set[str]) -> set[str]:
    """Tokens that identify a product: generic type words and sizes ("serum", "150ml") do not."""

    return {t for t in tokens if t not in GENERIC_WORDS and not _SIZE_RE.match(t)}


def _name_of(product: Mapping[str, Any]) -> str:
    return str(product.get("ten_san_pham") or product.get("name") or "")


def _jaccard(a: Sequence[str], b: Sequence[str]) -> float:
    left, right = set(a[:JACCARD_FIRST_N]), set(b[:JACCARD_FIRST_N])
    union = left | right
    return len(left & right) / len(union) if union else 0.0


def match(product_name: str, brand: str, scan_keys: Sequence[str], catalog: Any,
          text_loader: Callable[[Sequence[str]], Mapping[str, Mapping[str, str]]] | None,
          aliases: Mapping[str, Any] | None = None) -> Match | None:
    """Best catalog product for a scanned name/brand whose ingredients agree with ``scan_keys``; ``None`` otherwise.

    Never raises: any failure (no catalog, loader error) is logged by exception type and means "no match"."""

    scan_tokens = _significant(_tokens(product_name))
    if not scan_tokens or not scan_keys:
        return None
    try:
        scan_brand = fold(brand)
        shortlist: list[tuple[bool, float, str, Mapping[str, Any]]] = []
        for product in catalog.products():
            score = len(scan_tokens & _tokens(_name_of(product))) / len(scan_tokens)
            if score < MIN_NAME_SCORE:
                continue
            same_brand = bool(scan_brand) and fold(product.get("thuong_hieu")) == scan_brand
            shortlist.append((same_brand, score, str(product.get("id") or product.get("ma_san_pham") or ""), product))
        shortlist.sort(key=lambda c: (not c[0], -c[1], c[2]))
        shortlist = [c for c in shortlist if c[2]][:MAX_CANDIDATES]
        if not shortlist:
            return None
        texts = text_loader([c[2] for c in shortlist]) if text_loader is not None else {}
        table = aliases if aliases is not None else load_aliases()
        accepted: list[tuple[float, float, str, Match]] = []
        for same_brand, name_score, pid, product in shortlist:
            doc = texts.get(pid) or {}
            refs = parse_ingredients(doc.get("full"), table) or parse_ingredients(doc.get("sach"), table)
            if not refs:
                continue
            jac = _jaccard(list(scan_keys), [r.key for r in refs])
            ok = jac >= ACCEPT_JACCARD or (same_brand and name_score >= ACCEPT_NAME_SCORE_SAME_BRAND
                                           and jac >= ACCEPT_JACCARD_SAME_BRAND)
            if ok:
                found = Match(pid, _name_of(product), round(jac, 4), tuple((r.key, r.rank) for r in refs))
                accepted.append((name_score, jac, pid, found))
        if not accepted:
            return None
        accepted.sort(key=lambda a: (-a[1], -a[0], a[2]))
        return accepted[0][3]
    except Exception as exc:
        logger.warning("label catalog match skipped: %s", type(exc).__name__)
        return None
