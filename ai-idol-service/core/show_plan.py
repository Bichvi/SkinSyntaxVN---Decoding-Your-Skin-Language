"""Show plan for live clip-library campaigns: routine order, conflicts, bundle total."""
import logging
import math
import re
import unicodedata

import requests

import config
from core.text_budget import clip_text
from core.vi_money import money_words

logger = logging.getLogger("ai_idol.show_plan")

STEP_ORDER = ("tay_trang", "sua_rua_mat", "tay_te_bao_chet", "toner", "serum", "kem_duong", "kem_chong_nang")
STEP_LABELS_VI = {
    "tay_trang": "Tẩy trang",
    "sua_rua_mat": "Sữa rửa mặt",
    "tay_te_bao_chet": "Tẩy tế bào chết",
    "toner": "Toner",
    "serum": "Serum",
    "kem_duong": "Kem dưỡng",
    "kem_chong_nang": "Kem chống nắng",
}

# Checked in this order: "tẩy tế bào chết" must win over "tẩy trang".
_KEYWORDS = (
    ("kem_chong_nang", ("chong nang", "sunscreen")),
    ("tay_te_bao_chet", ("tay te bao chet", "tay da chet", "exfoliat", "peeling")),
    ("tay_trang", ("tay trang", "micellar", "cleansing oil", "cleansing balm")),
    ("sua_rua_mat", ("sua rua mat", "gel rua mat", "rua mat", "cleanser")),
    ("toner", ("toner", "nuoc hoa hong", "nuoc can bang")),
    ("serum", ("serum", "tinh chat", "ampoule")),
    ("kem_duong", ("kem duong", "duong am", "moisturi", "gel duong")),
)
_UNKNOWN_RANK = STEP_ORDER.index("kem_duong") - 0.5
_ROUTINE_PATH = "/api/cart/analyze"


def _fold(text: str) -> str:
    text = unicodedata.normalize("NFD", str(text or "").lower().replace("đ", "d"))
    text = "".join(c for c in text if unicodedata.category(c) != "Mn")
    return re.sub(r"\s+", " ", text).strip()


def step_from_keywords(name: str, category: str = "") -> str | None:
    haystack = _fold(f"{name} {category}")
    for key, words in _KEYWORDS:
        if any(w in haystack for w in words):
            return key
    return None


def order_products(products: list[dict], steps: dict[str, str | None]) -> list[dict]:
    def rank(item):
        key = steps.get(str(item["product_id"]))
        return STEP_ORDER.index(key) if key in STEP_ORDER else _UNKNOWN_RANK

    ordered = sorted(products, key=rank)  # stable: ties keep input order
    out = []
    for number, item in enumerate(ordered, start=1):
        key = steps.get(str(item["product_id"]))
        key = key if key in STEP_ORDER else None
        out.append({
            "product_id": str(item["product_id"]),
            "number": number,
            "step_key": key,
            "step_label": STEP_LABELS_VI.get(key, "") if key else "",
        })
    return out


def _headers() -> dict:
    headers = {"Content-Type": "application/json"}
    if config.SEARCH_SERVICE_TOKEN:
        headers["X-Internal-Token"] = config.SEARCH_SERVICE_TOKEN
    return headers


def _call(post, product_ids: list[str], timeout) -> dict | None:
    url = config.SEARCH_SERVICE_URL.rstrip("/") + _ROUTINE_PATH
    try:
        resp = post(url, json={"product_ids": product_ids}, headers=_headers(), timeout=timeout)
        if resp.status_code != 200:
            logger.warning("cart analyze returned HTTP %s", resp.status_code)
            return None
        body = resp.json()
    except (requests.RequestException, ValueError) as exc:
        logger.warning("cart analyze failed: %s", type(exc).__name__)
        return None
    if not isinstance(body, dict) or body.get("degraded"):
        return None
    return body


def _conflict_message(entry: dict) -> str:
    advice = str(entry.get("advice_vi") or "").strip()
    if advice:
        return advice
    names = [str(n) for n in (entry.get("ingredients") or []) if n]
    if names:
        return "Hai sản phẩm này có thể xung đột vì " + " và ".join(names[:2]) + ", nên dùng cách buổi."
    return "Hai sản phẩm này có thể xung đột, nên dùng cách buổi."


def _map_conflicts(raw) -> list[dict]:
    out = []
    for entry in raw or []:
        if not isinstance(entry, dict) or not entry.get("a") or not entry.get("b"):
            continue
        out.append({
            "a": str(entry["a"]),
            "b": str(entry["b"]),
            "severity": str(entry.get("severity") or "low"),
            "message_vi": _conflict_message(entry),
        })
    return out


def fetch_routine_steps(product_ids, *, post=requests.post, timeout=5):
    ids = [str(p) for p in product_ids]
    steps: dict[str, str | None] = {}
    source = "graph"
    for pid in ids:
        body = _call(post, [pid], timeout)
        if body is None:
            source = "keyword"
            steps[pid] = None
            continue
        has = [k for k in ((body.get("routine") or {}).get("has") or []) if k in STEP_ORDER]
        steps[pid] = min(has, key=STEP_ORDER.index) if has else None
    conflicts: list[dict] = []
    if len(ids) > 1:
        body = _call(post, ids, timeout)
        if body is None:
            source = "keyword"
        else:
            conflicts = _map_conflicts(body.get("conflicts"))
    return steps, conflicts, source


def build_show_plan(snapshots: list[dict], *, post=requests.post) -> dict:
    items = []
    names = {}
    for snap in snapshots:
        pid = str(snap.get("id") or snap.get("product_id") or "")
        items.append({"product_id": pid})
        names[pid] = (snap.get("name") or "", snap.get("category") or snap.get("category_name") or "")
    steps, conflicts, source = fetch_routine_steps([i["product_id"] for i in items], post=post)
    for pid in steps:
        if steps[pid] is None:
            steps[pid] = step_from_keywords(*names.get(pid, ("", "")))
    return {
        "products": order_products(items, steps),
        "conflicts": conflicts,
        "theme_vi": "",
        "routine_source": source,
    }


def bundle_total(pricings: list[dict | None]) -> int:
    total = 0
    for pricing in pricings:
        if not pricing:
            continue
        flash = pricing.get("flash")
        price = flash.get("flash_price") if flash else None
        total += int(price if price else pricing.get("regular_price") or 0)
    return total


def bundle_total_phrase(total: int) -> str:
    return "tổng cả bộ: " + money_words(int(total))


def estimate_preparation_minutes(n_products: int) -> int:
    library_minutes = 4 * n_products + 10
    return max(config.MIN_LEAD_MINUTES, math.ceil(library_minutes * config.RENDER_FACTOR + 10))


DESCRIPTION_CHARS = 200


def combined_snapshot(plan: dict, snapshots: dict[str, dict], bundle_total: int | None = None) -> dict:
    """One snapshot for clips that are not about a single product (shape read by script_validator)."""
    products = []
    for item in plan.get("products", []):
        snap = snapshots.get(str(item.get("product_id"))) or {}
        # A short description lets the recap's one-line uses be checked against real facts.
        products.append({"name": snap.get("name"), "brand": snap.get("brand"), "pricing": snap.get("pricing"),
                         "description": clip_text(snap.get("description"), DESCRIPTION_CHARS)})
    return {
        "name": plan.get("theme_vi") or "",
        "brand": "SkinSyntax",
        "products": products,
        "bundle_total": bundle_total,
        "pricing": None,
    }
