"""Orchestration of the label scan: read (or take) ingredient text, match the shop catalog, check the cart for
conflicts, judge skin fit, plan a schedule, propose alternatives, summarise and save the chat turn.

Every dependency is injected; every step is guarded so one failure degrades the answer instead of failing it.
Never logs image bytes, ingredient text or e-mail addresses.
"""

from __future__ import annotations

import base64
import binascii
import logging
import threading
import time
from dataclasses import dataclass
from functools import lru_cache
from typing import Any, Callable, Mapping, Sequence

from graph_sync.ingredients import load_aliases, parse_ingredients
from search_service.cart_analyze import _ID_RE, conflicts_from_ingredients

from . import catalog_match, config, schedule, summary
from .skin_fit import Profile, evaluate, load_rules, normalize_profile
from .vision import VisionUnavailable, looks_like_label, read_label

logger = logging.getLogger(__name__)

SCAN_ID = "scan:label"            # not a valid product id (contains ':'), so it can never collide with a cart id
MAX_IDS = 50
PRODUCT_TYPES = {"cleanser", "toner", "serum", "moisturizer", "sunscreen", "mask", "other"}
VERDICT_VI = {"good": "hợp", "caution": "cần lưu ý", "avoid": "không phù hợp", "neutral": "trung tính",
              "unknown": "chưa đánh giá (thiếu hồ sơ da)"}
ALLOWED_MIMES = {"image/jpeg", "image/png", "image/webp"}
SKIN_VALUES = {"dau", "kho", "hon_hop", "thuong", None}
STEP_BY_TYPE = {"cleanser": "sua_rua_mat", "toner": "toner", "serum": "serum", "moisturizer": "kem_duong",
                "sunscreen": "kem_chong_nang"}
TYPE_BY_STEP = {v: k for k, v in STEP_BY_TYPE.items()}
GRAPH_SKIN = {"dau": "da_dau", "kho": "da_kho"}
SKIN_LABEL = {"dau": "da dầu", "kho": "da khô", "hon_hop": "da hỗn hợp", "thuong": "da thường"}
MAX_ALTERNATIVES = 3
NAME_FOR_SUMMARY = 80


# ---------------------------------------------------------------- request


class LabelValidationError(ValueError):
    def __init__(self, field_name: str) -> None:
        super().__init__(field_name)
        self.field = field_name


@dataclass(frozen=True)
class LabelRequest:
    image: bytes | None
    image_mime: str | None
    ingredients_text: str | None
    product_name: str
    brand: str
    product_ids: tuple[str, ...]
    skin_profile: Mapping | None
    skin_type_override: Mapping | None
    conversation_id: str | None
    email: str | None
    product_type: str | None = None


def _magic_ok(data: bytes, mime: str) -> bool:
    if mime == "image/jpeg":
        return data[:2] == b"\xff\xd8"
    if mime == "image/png":
        return data[:4] == b"\x89PNG"
    return data[:4] == b"RIFF" and data[8:12] == b"WEBP"


def _opt_str(payload: Mapping, name: str, limit: int) -> str | None:
    value = payload.get(name)
    if value is None or value == "":
        return None
    if not isinstance(value, str) or len(value) > limit:
        raise LabelValidationError(name)
    return value


def parse_request(payload: Any, *, max_image_bytes: int) -> LabelRequest:
    """Validate a decoded JSON body (plan 4.1). Raises ``LabelValidationError(field)``;
    an oversized image raises ``LabelValidationError("image_too_large")`` (the route answers 413)."""

    if not isinstance(payload, dict):
        raise LabelValidationError("body")
    b64, text = payload.get("image_b64"), payload.get("ingredients_text")
    has_image, has_text = b64 not in (None, ""), text not in (None, "")
    if has_image == has_text:
        raise LabelValidationError("image_b64")
    image: bytes | None = None
    mime: str | None = None
    clean_text: str | None = None
    if has_image:
        if not isinstance(b64, str):
            raise LabelValidationError("image_b64")
        if len(b64) > (max_image_bytes * 4) // 3 + 16:
            raise LabelValidationError("image_too_large")
        try:
            image = base64.b64decode(b64, validate=True)
        except (binascii.Error, ValueError):
            raise LabelValidationError("image_b64") from None
        if not image:
            raise LabelValidationError("image_b64")
        if len(image) > max_image_bytes:
            raise LabelValidationError("image_too_large")
        mime = payload.get("image_mime")
        if not isinstance(mime, str) or mime not in ALLOWED_MIMES or not _magic_ok(image, mime):
            raise LabelValidationError("image_mime")
    else:
        if not isinstance(text, str):
            raise LabelValidationError("ingredients_text")
        clean_text = text.strip()
        if len(clean_text) > config.MAX_INGREDIENTS_TEXT or len(clean_text) < config.MIN_INGREDIENTS_TEXT:
            raise LabelValidationError("ingredients_text")
    raw_ids = payload.get("product_ids", [])
    if raw_ids is None:
        raw_ids = []
    if not isinstance(raw_ids, list) or len(raw_ids) > MAX_IDS:
        raise LabelValidationError("product_ids")
    ids: list[str] = []
    for value in raw_ids:
        if isinstance(value, bool) or not isinstance(value, (str, int)):
            raise LabelValidationError("product_ids")
        item = str(value).strip()
        if not _ID_RE.match(item):
            raise LabelValidationError("product_ids")
        if item not in ids:
            ids.append(item)
    profile = payload.get("skin_profile")
    if profile is not None:
        if not isinstance(profile, dict):
            raise LabelValidationError("skin_profile")
        for key in ("skin_type", "loai_da_name"):
            if profile.get(key) is not None and not isinstance(profile[key], str):
                raise LabelValidationError("skin_profile")
        concerns = profile.get("concerns")
        if concerns is not None and (not isinstance(concerns, list) or len(concerns) > 20
                                     or not all(isinstance(c, str) for c in concerns)):
            raise LabelValidationError("skin_profile")
    ptype = payload.get("product_type")
    if ptype is not None and (not isinstance(ptype, str) or ptype not in PRODUCT_TYPES):
        raise LabelValidationError("product_type")
    override = payload.get("skin_type_override")
    if override is not None:
        if not isinstance(override, dict):
            raise LabelValidationError("skin_type_override")
        if "skin_type" in override:
            wanted = override["skin_type"]
            if not (wanted is None or (isinstance(wanted, str) and wanted in SKIN_VALUES)):
                raise LabelValidationError("skin_type_override")
        if "sensitive" in override and not isinstance(override["sensitive"], bool):
            raise LabelValidationError("skin_type_override")
        override = {k: override[k] for k in ("skin_type", "sensitive") if k in override}
    return LabelRequest(
        image=image, image_mime=mime, ingredients_text=clean_text,
        product_name=(_opt_str(payload, "product_name", 200) or "").strip(),
        brand=(_opt_str(payload, "brand", 200) or "").strip(),
        product_ids=tuple(ids), skin_profile=profile, skin_type_override=override,
        conversation_id=_opt_str(payload, "conversation_id", 64), email=_opt_str(payload, "email", 254),
        product_type=ptype)


# ---------------------------------------------------------------- helpers


@lru_cache(maxsize=1)
def _default_aliases() -> Any:
    return load_aliases()


def _entries(aliases: Mapping[str, Any]) -> dict[str, Any]:
    return {e.key: e for e in aliases.values()}


def _default_saver(conv_id: str, email: str, user_text: str, assistant_text: str, products: list, conflicts: list) -> Any:
    from chatbot_service.conversation_repository import save_chat_messages

    return save_chat_messages(conv_id, email, user_text, assistant_text, products=products, conflicts=conflicts)


def _empty_profile() -> dict:
    return {"verdict": "unknown", "profile": {"skin_type": None, "sensitive": False, "concerns": []},
            "good": [], "caution": [], "avoid": []}


def _blank(status: str, name: str = "", brand: str = "", ptype: str = "other", source: str = "photo",
           warnings: Sequence[str] = ()) -> dict:
    result = {
        "status": status,
        "product": {"name": name, "brand": brand, "product_type": ptype, "matched_product_id": None,
                    "match_score": None},
        "ingredients": {"source": source, "total": 0, "recognized": 0, "items": [], "raw_text": ""},
        "conflicts": [], "schedule": [], "skin_fit": _empty_profile(), "alternatives": [],
        "warnings": list(dict.fromkeys(warnings)), "summary_vi": "", "sunscreen_note": False, "took_ms": 0,
    }
    result["summary_vi"] = summary.render(result)
    return result


def _rank_filtered(pairs: Sequence[tuple[str, int | None]], rule_set: Any) -> list[tuple[str, int | None]]:
    return [(k, r) for k, r in pairs if r is None or r <= rule_set.rank_limit(k)]


# ---------------------------------------------------------------- service


class LabelAnalyzeService:
    def __init__(self, *, cart_service: Any, catalog: Any = None, vision: Callable[..., Any] = read_label,
                 text_loader: Callable[[Sequence[str]], Mapping[str, Mapping[str, str]]] | None = None,
                 saver: Callable[..., Any] | None = None, aliases: Mapping[str, Any] | None = None,
                 rules: Any = None, clock: Callable[[], float] = time.monotonic) -> None:
        self.cart_service = cart_service
        self.catalog = catalog
        self.vision = vision
        self.text_loader = text_loader
        self.saver = saver
        self._aliases = aliases
        self._rules = rules
        self.clock = clock

    # -- public
    def analyze(self, req: LabelRequest, correlation_id: str = "") -> dict:
        started = self.clock()
        try:
            body = self._analyze(req, correlation_id)
        except Exception as exc:       # never raise: the route turns this into a normal 200
            logger.error("label_analyze internal_error type=%s correlation_id=%s", type(exc).__name__, correlation_id)
            body = _blank("vision_unavailable", warnings=["internal_error"])
        body["took_ms"] = max(0, int((self.clock() - started) * 1000))
        logger.info("label_analyze status=%s conflicts=%d warnings=%s took_ms=%d correlation_id=%s",
                    body["status"], len(body["conflicts"]), ",".join(body["warnings"]), body["took_ms"], correlation_id)
        return body

    # -- internals
    def aliases(self) -> Mapping[str, Any]:
        return self._aliases if self._aliases is not None else _default_aliases()

    def _text_loader(self) -> Callable[..., Any]:
        if self.text_loader is not None:
            return self.text_loader
        from search_service.cart_analyze import mongo_text_loader

        return mongo_text_loader

    def _cart_name(self, pid: str) -> str:
        try:
            product = self.catalog.get(pid) if self.catalog is not None else None
        except Exception:
            product = None
        return str((product or {}).get("ten_san_pham") or (product or {}).get("name") or "")

    def _analyze(self, req: LabelRequest, cid: str) -> dict:
        warnings: list[str] = []
        # 1. text source
        name, brand, ptype = req.product_name, req.brand, req.product_type or "other"
        if req.ingredients_text:
            text, source = req.ingredients_text, "user_edit"
        else:
            try:
                label = self.vision(req.image, req.image_mime, correlation_id=cid)
            except VisionUnavailable:
                return _blank("vision_unavailable", name, brand)
            except Exception as exc:
                logger.warning("label vision failed: %s correlation_id=%s", type(exc).__name__, cid)
                return _blank("vision_unavailable", name, brand)
            name, brand, ptype = (req.product_name or label.product_name, req.brand or label.brand,
                                  label.product_type or "other")
            if not looks_like_label(label):
                return _blank("not_label", name, brand, ptype)
            text, source = label.ingredients_text, "photo"
        name, brand = name[:200], brand[:200]
        text = text[: config.MAX_INGREDIENTS_TEXT]

        # 2. parse
        aliases = self.aliases()
        refs = parse_ingredients(text, aliases)
        if not refs:
            return _blank("unreadable", name, brand, ptype, source)
        by_key = _entries(aliases)
        display = {k: e.inci for k, e in by_key.items()}
        pairs: list[tuple[str, int | None]] = [(r.key, r.rank) for r in refs]
        items = [{"key": r.key, "name": r.inci or r.name, "name_vi": r.name_vi, "role": r.role, "rank": r.rank,
                  "highlight": r.role == "active"} for r in refs]
        total, recognized = len(refs), sum(1 for r in refs if r.inci is not None)

        # 3. catalog match
        matched_id: str | None = None
        match_score: float | None = None
        found = catalog_match.match(name, brand, [k for k, _ in pairs], self.catalog, self._text_loader(), aliases)
        raw_text = text
        if found is not None:
            matched_id, match_score = found.product_id, found.score
            name, source, raw_text = (found.name or name)[:200], "catalog", ""
            pairs = [(k, r) for k, r in found.keys]
            total = len(pairs)
            recognized = sum(1 for k, _ in pairs if k in by_key)
            items = []
            for k, r in pairs:
                entry = by_key.get(k)
                items.append({"key": k, "name": entry.inci if entry else k, "name_vi": entry.name_vi if entry else None,
                              "role": entry.role if entry else None, "rank": r,
                              "highlight": bool(entry and entry.role == "active")})
            if ptype == "other":
                ptype = self._type_from_catalog(found.product_id)
        ratio = recognized / total if total else 0.0
        if total < 5 or ratio < 0.6:
            warnings.append("low_recognition")

        # 4. cart
        cart_all = list(req.product_ids)
        if not cart_all:
            warnings.append("cart_empty")
        cart_ids = [pid for pid in cart_all if pid != matched_id]
        rule_set = self.cart_service.rule_set
        cart_keys: dict[str, list[tuple[str, int | None]]] = {}
        cart_cats: dict[str, list[str]] = {}
        if cart_ids:
            try:
                cart_keys, cart_cats, degraded = self.cart_service.cart_ingredients(cart_ids)
                if degraded:
                    warnings.append("graph_degraded")
            except Exception as exc:
                logger.warning("label cart_ingredients failed: %s correlation_id=%s", type(exc).__name__, cid)
                warnings.append("graph_degraded")

        # 5. conflicts
        step_key = STEP_BY_TYPE.get(ptype)
        scan_cats = self._catalog_categories(matched_id) if matched_id else []
        if not scan_cats and step_key:
            step = self.cart_service.routine.step(step_key)
            scan_cats = list(step.categories) if step else []
        scan_pairs = _rank_filtered(pairs, rule_set)
        cart_pairs = {pid: _rank_filtered(v, rule_set) for pid, v in cart_keys.items()}
        raw_conflicts: list[dict] = []
        conflicts: list[dict] = []
        try:
            all_conflicts = conflicts_from_ingredients({SCAN_ID: scan_pairs, **cart_pairs},
                                                       {**cart_cats, SCAN_ID: scan_cats}, rule_set, display)
            raw_conflicts = [c for c in all_conflicts if SCAN_ID in (c["a"], c["b"])]
            for c in raw_conflicts:
                other = c["b"] if c["a"] == SCAN_ID else c["a"]
                entry = {"cart_product_id": other, "severity": c["severity"], "timing": c["timing"],
                         "ingredients": list(c["ingredients"]), "advice_vi": c["advice_vi"], "rule_id": c["rule_id"],
                         "evidence": c["evidence"]}
                cart_name = self._cart_name(other)
                if cart_name:
                    entry["cart_product_name"] = cart_name
                conflicts.append(entry)
        except Exception as exc:
            logger.warning("label conflicts failed: %s correlation_id=%s", type(exc).__name__, cid)
            warnings.append("graph_degraded")

        # 6. skin fit
        rules = self._rules
        try:
            rules = rules or load_rules()
            profile = normalize_profile(req.skin_profile, req.skin_type_override)
            fit = evaluate(pairs, profile, rules, ratio)
            fit_dict = fit.to_dict(profile, display)
        except Exception as exc:
            logger.warning("label skin fit failed: %s correlation_id=%s", type(exc).__name__, cid)
            profile, rules = Profile(None, False, frozenset()), None
            fit = evaluate([], profile)
            fit_dict = _empty_profile()
        if not profile.known:
            warnings.append("profile_missing")

        # 7. schedule
        keys_by_product = {SCAN_ID: {k for k, _ in scan_pairs}, **{pid: {k for k, _ in v} for pid, v in cart_pairs.items()}}
        slots = schedule.plan(raw_conflicts, keys_by_product, scan_id=SCAN_ID)
        sunscreen = schedule.needs_sunscreen(keys_by_product[SCAN_ID])

        # 8. alternatives
        alternatives: list[dict] = []
        if step_key and (fit.verdict in ("caution", "avoid") or any(c["severity"] == "high" for c in conflicts)):
            try:
                alternatives = self._alternatives(step_key, cart_all, matched_id, profile, rules)
            except Exception as exc:
                logger.warning("label alternatives failed: %s correlation_id=%s", type(exc).__name__, cid)
                warnings.append("graph_degraded")

        result = {
            "status": "ok",
            "product": {"name": name, "brand": brand, "product_type": ptype, "matched_product_id": matched_id, "match_score": match_score},
            "ingredients": {"source": source, "total": total, "recognized": recognized, "items": items,
                            "raw_text": raw_text},
            "conflicts": conflicts, "schedule": slots, "skin_fit": fit_dict, "alternatives": alternatives,
            "warnings": list(dict.fromkeys(warnings)), "summary_vi": "", "sunscreen_note": sunscreen, "took_ms": 0,
        }
        result["summary_vi"] = summary.render({**result, "product": {**result["product"], "name": name[:NAME_FOR_SUMMARY]},
                                               "conflicts": conflicts})
        self._save(req, result, cid)
        return result

    def _catalog_categories(self, pid: str) -> list[str]:
        """Category chain of a matched shop product, exactly as the cart path sees it."""

        try:
            chain = list((self.catalog.get(pid) or {}).get("category_keys") or [])
            if not chain and hasattr(self.cart_service, "_categories"):
                chain = list(self.cart_service._categories([pid]).get(pid, []))
            return chain
        except Exception:
            return []

    def _type_from_catalog(self, pid: str) -> str:
        try:
            chain = set((self.catalog.get(pid) or {}).get("category_keys") or [])
        except Exception:
            return "other"
        for step_key, ptype in TYPE_BY_STEP.items():
            step = self.cart_service.routine.step(step_key)
            if step and chain & set(step.categories):
                return ptype
        return "other"

    @staticmethod
    def _risky_keys(rules: Any, profile: Profile) -> list[str]:
        """Keys of every caution/avoid rule that applies to this profile (candidates holding them are suspect)."""

        if rules is None:
            return []
        keys: set[str] = set()
        for rule in rules.rules:
            if rule.verdict in ("caution", "avoid") and rule.applies(profile):
                for matcher in rule.matchers:
                    keys |= set(matcher.keys)
        return sorted(keys)

    def _alternatives(self, step_key: str, cart_ids: Sequence[str], matched_id: str | None, profile: Profile,
                      rules: Any) -> list[dict]:
        graph_skin = "da_nhay_cam" if profile.sensitive else GRAPH_SKIN.get(profile.skin_type or "")
        candidates = self.cart_service.step_candidates(step_key, list(cart_ids), graph_skin,
                                                       extra_keys=self._risky_keys(rules, profile), limit=6)
        label = "da nhạy cảm" if profile.sensitive else SKIN_LABEL.get(profile.skin_type or "", "")
        out: list[dict] = []
        for cand in candidates:
            pid = str(cand.get("product_id"))
            ings = [(str(i.get("key")), i.get("rank")) for i in cand.get("conflict_ings") or []]
            if pid == matched_id:
                continue
            if rules is not None and evaluate(ings, profile, rules, 1.0).verdict in ("caution", "avoid"):
                continue
            fits = cand.get("reason_code") == "fits_skin_type"
            out.append({"product_id": pid, "reason_code": "gentler_fits_skin" if fits else "no_cart_conflict",
                        "reason_params": [label] if label else []})
            if len(out) >= MAX_ALTERNATIVES:
                break
        return out

    def _save(self, req: LabelRequest, result: dict, cid: str) -> None:
        if not (req.conversation_id and req.email):
            return
        try:
            saver = self.saver or _default_saver
            actives = [i["name"] for i in result["ingredients"]["items"] if i["highlight"]][:3]
            lines = [result["summary_vi"]]
            if actives:
                lines.append("- Hoạt chất chính: " + ", ".join(actives))
            if result["conflicts"]:
                top = result["conflicts"][0]
                lines.append(f"- Xung đột: {', '.join(top['ingredients'])} với "
                             f"{top.get('cart_product_name') or 'sản phẩm trong giỏ'} ({top['severity']})")
            lines.append(f"- Hợp da: {VERDICT_VI.get(result['skin_fit']['verdict'], 'chưa đánh giá')}")
            name = result["product"]["name"][:NAME_FOR_SUMMARY] or "không rõ tên"
            saver(req.conversation_id, req.email, f"📷 Ảnh nhãn: {name}", "\n".join(lines[:5]),
                  products=[{"id": a["product_id"]} for a in result["alternatives"]], conflicts=result["conflicts"])
        except Exception as exc:
            logger.warning("label save failed: %s correlation_id=%s", type(exc).__name__, cid)


# ---------------------------------------------------------------- singleton

_service: LabelAnalyzeService | None = None
_service_lock = threading.Lock()


def get_label_service() -> LabelAnalyzeService:
    global _service
    if _service is not None:
        return _service
    with _service_lock:
        if _service is None:
            from search_service.service import get_cart_service, get_parts

            _service = LabelAnalyzeService(cart_service=get_cart_service(), catalog=get_parts().get("catalog"))
    return _service
