import os, sys, json, base64, glob, time, urllib.request, urllib.error
URL = "http://127.0.0.1:5001/api/label/analyze"
TOK = os.environ.get("SEARCH_INTERNAL_TOKEN", "")
def post(body):
    h = {"Content-Type": "application/json"}
    if TOK: h["X-Internal-Token"] = TOK
    req = urllib.request.Request(URL, json.dumps(body).encode(), h)
    t = time.time()
    try:
        with urllib.request.urlopen(req, timeout=90) as r: return r.status, json.loads(r.read()), time.time() - t
    except urllib.error.HTTPError as e:
        try: return e.code, json.loads(e.read()), time.time() - t
        except Exception: return e.code, {}, time.time() - t
def show(name, st, j, wall):
    ing = j.get("ingredients") or {}
    print(json.dumps({"file": name, "http": st, "status": j.get("status"), "error": j.get("error"),
        "name": (j.get("product") or {}).get("name"), "brand": (j.get("product") or {}).get("brand"),
        "total": ing.get("total"), "recognized": ing.get("recognized"),
        "first8": [i["key"] for i in (ing.get("items") or [])[:8]],
        "all_keys": [i["key"] for i in (ing.get("items") or [])],
        "warnings": j.get("warnings"), "verdict": (j.get("skin_fit") or {}).get("verdict"),
        "took_ms": j.get("took_ms"), "wall_s": round(wall, 2),
        "conflicts": [(c.get("cart_product_id"), c.get("severity"), c.get("timing")) for c in j.get("conflicts") or []],
        "schedule": j.get("schedule")}, ensure_ascii=False))
for f in sorted(glob.glob("/tmp/lbl/*.png")):
    b = base64.b64encode(open(f, "rb").read()).decode()
    st, j, w = post({"image_b64": b, "image_mime": "image/png", "skin_profile": {"skin_type": "da_kho"}, "product_ids": []})
    show(os.path.basename(f), st, j, w)
if len(sys.argv) > 1:
    txt = "Water, Glycolic Acid, Butylene Glycol, Salicylic Acid, Niacinamide, Glycerin, Sodium Hydroxide, Panthenol, Allantoin, Fragrance, Phenoxyethanol"
    st, j, w = post({"ingredients_text": txt, "product_ids": [sys.argv[1]], "skin_profile": {"skin_type": "da_kho"}})
    show("text-aha-bha+cart", st, j, w)
