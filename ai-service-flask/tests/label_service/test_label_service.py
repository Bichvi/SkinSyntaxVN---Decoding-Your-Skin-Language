"""LabelAnalyzeService: personas of plan 8.3, degradation, saving and request validation (fakes only)."""

import base64

import pytest

from graph_sync.conflicts import load_rule_set
from graph_sync.ingredients import load_aliases
from label_service import service as svc_mod
from label_service.service import LabelAnalyzeService, LabelRequest, LabelValidationError, parse_request
from label_service.vision import LabelText, VisionUnavailable
from search_service.routine import load_routine

ALIASES = load_aliases()
RULE_SET = load_rule_set()
ROUTINE = load_routine()

T1 = ("Water, Butylene Glycol, Glycolic Acid, Glycerin, Sodium Hyaluronate, Salicylic Acid, Fragrance, Panthenol, "
      "Allantoin, Centella Asiatica Extract")
K1 = "Water, Alcohol Denat., Glycerin, Dimethicone, Squalane, Tocopherol, Ceramide NP"
R1_KEYS = [("retinol", 4)]


class FakeCart:
    """Stands for CartAnalyzeService: only the public surface the label service uses."""

    def __init__(self, cart=None, degraded=False, candidates=None, cand_error=None, cats=None):
        self.rule_set, self.routine = RULE_SET, ROUTINE
        self.cart, self.degraded, self.candidates, self.cand_error = cart or {}, degraded, candidates or [], cand_error
        self.cats = cats or {}
        self.cand_calls = []

    def cart_ingredients(self, ids):
        return {i: list(self.cart.get(i, [])) for i in ids}, {i: self.cats.get(i, ["serum"]) for i in ids}, self.degraded

    def step_candidates(self, step_key, cart_ids, skin_type, extra_keys=(), limit=6):
        self.cand_calls.append((step_key, tuple(cart_ids), skin_type, tuple(extra_keys), limit))
        if self.cand_error:
            raise self.cand_error
        return list(self.candidates)


class FakeCatalog:
    def __init__(self, products=None):
        self.products_ = products or []

    def products(self):
        return self.products_

    def get(self, pid):
        return next((p for p in self.products_ if p["id"] == pid), None)


def label(text=T1, ptype="toner", name="AHA BHA Toner", brand="Some By Mi", is_label=True):
    return LabelText(is_label, name, brand, ptype, text, "en", "groq")


def vision_of(lt):
    calls = []

    def vision(image, mime, *, correlation_id=""):
        calls.append((image, mime, correlation_id))
        if isinstance(lt, Exception):
            raise lt
        return lt

    vision.calls = calls
    return vision


def req(**kw):
    base = dict(image=b"\xff\xd8img", image_mime="image/jpeg", ingredients_text=None, product_name="", brand="",
                product_ids=(), skin_profile=None, skin_type_override=None, conversation_id=None, email=None)
    base.update(kw)
    return LabelRequest(**base)


def make(cart=None, lt=None, catalog=None, saver=None, loader=None, **cart_kw):
    cs = FakeCart(cart, **cart_kw)
    return LabelAnalyzeService(cart_service=cs, catalog=catalog or FakeCatalog(), vision=vision_of(lt or label()),
                               text_loader=loader or (lambda ids: {}), saver=saver, aliases=ALIASES), cs


def alt(pid, code="fits_skin_type", ings=()):
    return {"product_id": pid, "score": 1.0, "reason_code": code, "reason_params": ["x"],
            "conflict_ings": [{"key": k, "rank": 3} for k in ings]}


# ---- personas
def test_k01_guest_empty_cart():
    s, _ = make()
    out = s.analyze(req())
    assert out["status"] == "ok" and out["conflicts"] == [] and out["schedule"] == []
    assert {"cart_empty", "profile_missing"} <= set(out["warnings"])
    assert out["skin_fit"]["verdict"] == "unknown" and out["ingredients"]["source"] == "photo"
    assert out["ingredients"]["raw_text"] == T1 and out["sunscreen_note"] is True
    assert out["alternatives"] == []


def test_k02_cart_with_retinol_high_conflict_and_schedule():
    s, _ = make(cart={"456": R1_KEYS}, catalog=FakeCatalog([{"id": "456", "ten_san_pham": "Retinol Serum"}]))
    out = s.analyze(req(product_ids=("456",)))
    assert [c["severity"] for c in out["conflicts"]] == ["high"]
    c = out["conflicts"][0]
    assert c["cart_product_id"] == "456" and c["cart_product_name"] == "Retinol Serum"
    assert any("Retinol" in n for n in c["ingredients"])
    assert out["schedule"][0]["cart_product_id"] == "456"
    # retinoid_aha is timing same_routine in conflict_rules.yaml -> alternate days (not an AM/PM split)
    assert out["schedule"][0]["kind"] == "alternate_days" and out["schedule"][0]["scan_slot"] is None
    assert "profile_missing" in out["warnings"] and "cart_empty" not in out["warnings"]
    assert "Retinol Serum" in out["summary_vi"]


def test_k04_logged_in_without_profile_saves_chat_turn():
    saved = []
    s, _ = make(cart={"456": R1_KEYS}, saver=lambda *a, **k: saved.append((a, k)))
    out = s.analyze(req(product_ids=("456",), conversation_id="c1", email="a@b.vn"))
    assert "profile_missing" in out["warnings"] and len(saved) == 1
    args, kwargs = saved[0]
    assert args[0] == "c1" and args[1] == "a@b.vn" and args[2] == "📷 Ảnh nhãn: AHA BHA Toner"
    assert args[3].startswith(out["summary_vi"]) and 2 <= len(args[3].splitlines()) <= 5
    assert kwargs["conflicts"] == out["conflicts"] and kwargs["products"] == []


def test_saver_not_called_without_conversation_or_email_and_errors_swallowed():
    saved = []
    s, _ = make(saver=lambda *a, **k: saved.append(1))
    s.analyze(req(conversation_id="c1"))
    s.analyze(req(email="a@b.vn"))
    assert saved == []

    def boom(*a, **k):
        raise RuntimeError("mongo")

    s2, _ = make(saver=boom)
    assert s2.analyze(req(conversation_id="c1", email="a@b.vn"))["status"] == "ok"


def test_k05_oily_acne_good_no_alternatives():
    s, cs = make()
    out = s.analyze(req(skin_profile={"skin_type": "da dầu", "concerns": ["mụn"]}))
    assert out["skin_fit"]["verdict"] == "good"
    assert any(f["rule_id"] == "oily_bha" for f in out["skin_fit"]["good"])
    assert out["conflicts"] == [] and out["alternatives"] == [] and cs.cand_calls == []


def test_k06_dry_skin_alcohol_avoid_with_alternatives():
    s, cs = make(lt=label(K1, "moisturizer", "Kem Duong"),
                 candidates=[alt("m1", "fits_skin_type"), alt("m2", "completes_routine", ["alcohol_denat"]),
                             alt("m3", "completes_routine")])
    out = s.analyze(req(skin_profile={"skin_type": "da khô"}))
    assert out["skin_fit"]["verdict"] == "avoid"
    assert [a["product_id"] for a in out["alternatives"]] == ["m1", "m3"]      # m2 holds the offending key
    assert out["alternatives"][0] == {"product_id": "m1", "reason_code": "gentler_fits_skin",
                                      "reason_params": ["da khô"]}
    assert out["alternatives"][1]["reason_code"] == "no_cart_conflict"
    step, ids, skin, extra, limit = cs.cand_calls[0]
    assert step == "kem_duong" and skin == "da_kho" and "alcohol_denat" in extra


def test_k07_sensitive_conflict_plus_caution_and_alternative_for_toner():
    s, cs = make(cart={"456": R1_KEYS}, candidates=[alt("t9", "fits_skin_type")])
    out = s.analyze(req(product_ids=("456",), skin_profile={"skin_type": "thường"},
                        skin_type_override={"sensitive": True}))
    assert out["conflicts"] and out["skin_fit"]["verdict"] in ("caution", "avoid")
    assert out["skin_fit"]["profile"]["sensitive"] is True
    assert out["alternatives"][0]["product_id"] == "t9"
    assert cs.cand_calls[0][2] == "da_nhay_cam"
    assert out["alternatives"][0]["reason_params"] == ["da nhạy cảm"]


def test_override_beats_profile_k03():
    s, _ = make()
    out = s.analyze(req(skin_profile={"skin_type": "da khô"}, skin_type_override={"skin_type": "dau"}))
    assert out["skin_fit"]["profile"]["skin_type"] == "dau"
    assert any(f["rule_id"] == "oily_bha" for f in out["skin_fit"]["good"])


def test_k09_matched_product_in_cart_has_no_self_conflict():
    catalog = FakeCatalog([{"id": "10", "ten_san_pham": "AHA BHA Toner", "thuong_hieu": "Some By Mi",
                            "category_keys": ["toner"]}])
    loader = lambda ids: {"10": {"full": T1, "sach": ""}}
    s, cs = make(cart={"10": [("glycolic_acid", 3), ("salicylic_acid", 6)]}, catalog=catalog, loader=loader,
                 lt=label(T1, "other"))
    out = s.analyze(req(product_ids=("10",)))
    assert out["conflicts"] == [] and out["schedule"] == []
    assert out["ingredients"]["source"] == "catalog" and out["ingredients"]["raw_text"] == ""
    assert out["product"]["matched_product_id"] == "10" and out["product"]["match_score"] == 1.0
    assert out["product"]["product_type"] == "toner"           # inferred from the catalog categories
    assert out["ingredients"]["items"][2]["key"] == "glycolic_acid" and out["ingredients"]["items"][2]["rank"] == 3
    assert "cart_empty" not in out["warnings"]


def test_k10_low_recognition_blocks_strong_good():
    s, _ = make(lt=label("Water, Salicylic Acid, Xyzzy Foo, Bar Baz Qux"))
    out = s.analyze(req(skin_profile={"skin_type": "da dầu"}))
    assert "low_recognition" in out["warnings"] and out["skin_fit"]["verdict"] != "good"
    assert out["status"] == "ok"


def test_k11_not_label():
    s, _ = make(lt=label(is_label=False))
    out = s.analyze(req())
    assert out["status"] == "not_label" and out["ingredients"]["items"] == [] and out["summary_vi"]
    assert out["conflicts"] == [] and out["skin_fit"]["verdict"] == "unknown"


def test_unreadable_when_no_ingredient_parsed():
    s, _ = make(lt=label("!!! ??? ... ,,, ;;; ###########"))
    assert s.analyze(req())["status"] == "unreadable"


def test_k12_user_edit_skips_vision():
    s, cs = make(cart={"456": R1_KEYS})
    out = s.analyze(req(image=None, image_mime=None, ingredients_text=T1, product_name="Toner X", brand="B",
                        product_ids=("456",)))
    assert out["ingredients"]["source"] == "user_edit" and out["conflicts"]
    assert s.vision.calls == [] and out["product"]["name"] == "Toner X"


def test_k15_vision_unavailable():
    s, _ = make(lt=VisionUnavailable("all failed"))
    out = s.analyze(req(product_name="Mine"))
    assert out["status"] == "vision_unavailable" and out["product"]["name"] == "Mine" and out["summary_vi"]


def test_request_name_overrides_vision_name():
    s, _ = make()
    assert s.analyze(req(product_name="Tên khách nhập", brand="X"))["product"]["name"] == "Tên khách nhập"


def test_k16_graph_degraded_still_conflicts_no_alternatives():
    s, _ = make(cart={"456": R1_KEYS}, degraded=True, cand_error=RuntimeError("neo4j"))
    out = s.analyze(req(product_ids=("456",), skin_type_override={"sensitive": True}))
    assert out["conflicts"] and out["alternatives"] == []
    assert "graph_degraded" in out["warnings"] and out["status"] == "ok"


def test_cart_ingredients_exception_degrades():
    class Broken(FakeCart):
        def cart_ingredients(self, ids):
            raise RuntimeError("x")

    s = LabelAnalyzeService(cart_service=Broken(), catalog=FakeCatalog(), vision=vision_of(label()),
                            text_loader=lambda ids: {}, aliases=ALIASES)
    out = s.analyze(req(product_ids=("1",)))
    assert out["status"] == "ok" and out["conflicts"] == [] and "graph_degraded" in out["warnings"]


def test_rank_prefilter_drops_lactic_acid_at_rank_30():
    # an alias table exposes lactic acid; at rank 30 (> limit 10) it must not conflict with the cart's retinol
    text = ", ".join(["Water", "Glycerin", "Butylene Glycol", "Panthenol", "Allantoin", "Squalane", "Tocopherol",
                      "Dimethicone", "Sodium Hyaluronate", "Niacinamide"])
    s, _ = make(lt=label(text, "toner"), cart={"456": [("retinol", 4)]})
    from label_service import service as mod

    pairs = [("water", 1), ("lactic_acid", 30), ("glycolic_acid", 3)]
    kept = mod._rank_filtered(pairs, RULE_SET)
    assert ("lactic_acid", 30) not in kept and ("glycolic_acid", 3) in kept and ("water", 1) in kept
    # end to end: lactic acid only at rank 30 yields no conflict, at rank 3 it does
    far = text + ", " + ", ".join(f"Xyz{i}" for i in range(15)) + ", Lactic Acid"
    near = "Water, Lactic Acid, " + text
    low = s.analyze(req(ingredients_text=far, image=None, image_mime=None, product_ids=("456",)))
    high = s.analyze(req(ingredients_text=near, image=None, image_mime=None, product_ids=("456",)))
    assert low["conflicts"] == []
    assert high["conflicts"] and high["conflicts"][0]["cart_product_id"] == "456"


def test_cart_side_rank_filter():
    s, _ = make(cart={"456": [("lactic_acid", 30)]})
    out = s.analyze(req(product_ids=("456",)))       # T1 has no retinoid; cart lactic at 30 pairs with nothing anyway
    assert out["conflicts"] == []
    s2, _ = make(cart={"456": [("retinol", 4), ("lactic_acid", 30)]})
    names = [n for c in s2.analyze(req(product_ids=("456",)))["conflicts"] for n in c["ingredients"]]
    assert names and all("Lactic" not in n for n in names)


def test_never_raises_internal_error():
    s, cs = make()
    cs.rule_set = None                  # breaks step 4/5 -> caught by the outer guard
    out = s.analyze(req(product_ids=("1",)))
    assert out["status"] == "vision_unavailable" and "internal_error" in out["warnings"]


def test_took_ms_uses_clock():
    ticks = iter([10.0, 10.5, 11.0])
    s = LabelAnalyzeService(cart_service=FakeCart(), catalog=FakeCatalog(), vision=vision_of(label()),
                            text_loader=lambda ids: {}, aliases=ALIASES, clock=lambda: next(ticks))
    assert s.analyze(req())["took_ms"] == 500


def test_long_product_name_truncated_in_summary():
    s, _ = make(lt=label(name="N" * 150))
    out = s.analyze(req(skin_profile={"skin_type": "da dầu"}))
    assert "N" * 81 not in out["summary_vi"] and "N" * 80 in out["summary_vi"]


# ---- parse_request
def b64(data):
    return base64.b64encode(data).decode()


JPG = b"\xff\xd8\xff\xe0" + b"0" * 50
PNG = b"\x89PNG\r\n\x1a\n" + b"0" * 50
WEBP = b"RIFF\x10\x00\x00\x00WEBPVP8 " + b"0" * 20


def parse(payload, limit=1000):
    return parse_request(payload, max_image_bytes=limit)


def test_parse_ok_variants():
    r = parse({"image_b64": b64(JPG), "image_mime": "image/jpeg", "product_ids": ["1", 2, "1"]})
    assert r.image == JPG and r.product_ids == ("1", "2") and r.ingredients_text is None
    assert parse({"image_b64": b64(PNG), "image_mime": "image/png"}).image == PNG
    assert parse({"image_b64": b64(WEBP), "image_mime": "image/webp"}).image == WEBP
    r = parse({"ingredients_text": T1, "product_name": "x", "skin_type_override": {"skin_type": None, "sensitive": True},
               "conversation_id": "c", "email": "a@b.vn", "skin_profile": {"skin_type": "dau"}})
    assert r.ingredients_text == T1 and r.skin_type_override == {"skin_type": None, "sensitive": True}


@pytest.mark.parametrize("payload, field", [
    (None, "body"), ([], "body"), ({}, "image_b64"),
    ({"image_b64": "QQ==", "image_mime": "image/jpeg", "ingredients_text": T1}, "image_b64"),
    ({"image_b64": "!!notbase64!!", "image_mime": "image/jpeg"}, "image_b64"),
    ({"image_b64": b64(PNG), "image_mime": "image/jpeg"}, "image_mime"),
    ({"image_b64": b64(JPG), "image_mime": "image/gif"}, "image_mime"),
    ({"image_b64": b64(JPG)}, "image_mime"),
    ({"image_b64": b64(b"MZ" + b"0" * 40), "image_mime": "image/jpeg"}, "image_mime"),
    ({"image_b64": b64(b"\xff\xd8" + b"0" * 2000), "image_mime": "image/jpeg"}, "image_too_large"),
    ({"ingredients_text": "short"}, "ingredients_text"),
    ({"ingredients_text": "a" * 6001}, "ingredients_text"),
    ({"ingredients_text": T1, "product_ids": [str(i) for i in range(51)]}, "product_ids"),
    ({"ingredients_text": T1, "product_ids": ["../x"]}, "product_ids"),
    ({"ingredients_text": T1, "product_ids": "1"}, "product_ids"),
    ({"ingredients_text": T1, "skin_profile": "x"}, "skin_profile"),
    ({"ingredients_text": T1, "skin_type_override": "nhay_cam"}, "skin_type_override"),
    ({"ingredients_text": T1, "skin_type_override": {"skin_type": "oily"}}, "skin_type_override"),
    ({"ingredients_text": T1, "skin_type_override": {"sensitive": "yes"}}, "skin_type_override"),
    ({"ingredients_text": T1, "conversation_id": "c" * 65}, "conversation_id"),
    ({"ingredients_text": T1, "email": "e" * 255}, "email"),
    ({"ingredients_text": T1, "product_name": "n" * 201}, "product_name"),
    ({"ingredients_text": T1, "brand": 5}, "brand"),
])
def test_parse_rejects(payload, field):
    with pytest.raises(LabelValidationError) as exc:
        parse(payload)
    assert exc.value.field == field


def test_service_singleton_is_lazy():
    assert svc_mod._service is None


# ---- fix round 1
@pytest.mark.parametrize("override", [{"skin_type": ["dau"]}, {"skin_type": {"a": 1}}, {"skin_type": 3}])
def test_parse_rejects_unhashable_override_skin_type(override):
    with pytest.raises(LabelValidationError) as exc:
        parse({"ingredients_text": T1, "skin_type_override": override})
    assert exc.value.field == "skin_type_override"


@pytest.mark.parametrize("profile", [{"concerns": 5}, {"concerns": [1]}, {"concerns": ["a"] * 21},
                                     {"skin_type": 3}, {"loai_da_name": ["x"]}])
def test_parse_rejects_bad_skin_profile(profile):
    with pytest.raises(LabelValidationError) as exc:
        parse({"ingredients_text": T1, "skin_profile": profile})
    assert exc.value.field == "skin_profile"


def test_parse_product_type():
    assert parse({"ingredients_text": T1, "product_type": "cleanser"}).product_type == "cleanser"
    assert parse({"ingredients_text": T1}).product_type is None
    with pytest.raises(LabelValidationError) as exc:
        parse({"ingredients_text": T1, "product_type": "perfume"})
    assert exc.value.field == "product_type"


def test_user_edit_uses_request_product_type_for_alternatives():
    s, cs = make(candidates=[alt("m1")])
    out = s.analyze(req(image=None, image_mime=None, ingredients_text=K1, product_type="moisturizer",
                        skin_profile={"skin_type": "da khô"}))
    assert out["product"]["product_type"] == "moisturizer" and cs.cand_calls[0][0] == "kem_duong"


def test_alternatives_drop_group_mates_and_matched_product():
    catalog = FakeCatalog([{"id": "10", "ten_san_pham": "AHA BHA Toner", "thuong_hieu": "Some By Mi",
                            "category_keys": ["toner"]}])
    loader = lambda ids: {"10": {"full": T1, "sach": ""}}
    s, cs = make(catalog=catalog, loader=loader,
                 candidates=[alt("10"), alt("lac", "fits_skin_type", ["lactic_acid"]),
                             alt("lin", "fits_skin_type", ["linalool"]), alt("ok", "fits_skin_type")])
    out = s.analyze(req(skin_profile={"skin_type": "da thường"}, skin_type_override={"sensitive": True}))
    assert out["product"]["matched_product_id"] == "10"
    assert [a["product_id"] for a in out["alternatives"]] == ["ok"]
    extra = cs.cand_calls[0][3]
    assert {"lactic_acid", "linalool", "glycolic_acid", "fragrance"} <= set(extra)


def test_skin_fit_failure_degrades_without_losing_conflicts():
    class BadRules:
        @property
        def rules(self):
            raise RuntimeError("bad yaml")

    cs = FakeCart({"456": R1_KEYS})
    s = LabelAnalyzeService(cart_service=cs, catalog=FakeCatalog(), vision=vision_of(label()),
                            text_loader=lambda ids: {}, aliases=ALIASES, rules=BadRules())
    out = s.analyze(req(product_ids=("456",), skin_profile={"skin_type": "dau"}))
    assert out["status"] == "ok" and out["conflicts"]
    assert out["skin_fit"]["verdict"] == "unknown" and "profile_missing" in out["warnings"]


def test_catalog_name_cut_to_200():
    catalog = FakeCatalog([{"id": "10", "ten_san_pham": "AHA BHA Toner " + "x" * 300, "thuong_hieu": "Some By Mi",
                            "category_keys": ["toner"]}])
    s, _ = make(catalog=catalog, loader=lambda ids: {"10": {"full": T1, "sach": ""}}, lt=label(T1, "toner"))
    out = s.analyze(req(product_name="AHA BHA Toner", brand="Some By Mi"))
    assert out["product"]["matched_product_id"] == "10" and len(out["product"]["name"]) == 200


def test_saved_turn_uses_vietnamese_verdict_and_alternative_ids():
    saved = []
    s, _ = make(candidates=[alt("m1")], saver=lambda *a, **k: saved.append((a, k)), lt=label(K1, "moisturizer"))
    s.analyze(req(skin_profile={"skin_type": "da khô"}, conversation_id="c", email="a@b.vn"))
    args, kwargs = saved[0]
    assert "Hợp da: không phù hợp" in args[3] and "avoid" not in args[3]
    assert kwargs["products"] == [{"id": "m1"}]


def test_get_label_service_builds_once(monkeypatch):
    import search_service.service as ss

    built = []
    monkeypatch.setattr(ss, "get_cart_service", lambda: built.append(1) or FakeCart())
    monkeypatch.setattr(ss, "get_parts", lambda: {"catalog": FakeCatalog()})
    monkeypatch.setattr(svc_mod, "_service", None)
    first = svc_mod.get_label_service()
    assert svc_mod.get_label_service() is first and built == [1]
