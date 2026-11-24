from datetime import datetime, timedelta

from label_service import metrics as m
from search_service.metrics import TestTraffic

T0 = datetime(2026, 11, 1, 10, 0, 0)
PRICES = {"groq": {"in": 1.0, "out": 2.0}, "gemini": {"in": 0.5, "out": 4.0}}


def scan(sid="s1", ts=T0, outcome="ok", inp="photo", conflicts=0, high=0, alts=(), usage=(), verdict="unknown", user=None):
    return {"type": "scan", "ts": ts, "session_hash": sid, "outcome": outcome, "input": inp, "conflicts_total": conflicts,
            "conflicts_high": high, "alternative_ids": list(alts), "usage": list(usage), "skin_verdict": verdict,
            "user_hash": user}


def atc(pid, sid="s1", ts=T0 + timedelta(minutes=5), kind="alternative"):
    return {"type": "add_to_cart", "product_id": pid, "session_hash": sid, "ts": ts, "kind": kind}


def order(oid, sid="s1", ts=T0 + timedelta(hours=1), tam_tinh=300000, status="pending", ma_kh=None):
    return {"ma_hoa_don": oid, "analytics_sid": sid, "ngay_dat": ts, "tam_tinh": tam_tinh, "trang_thai_normalized": status,
            "ma_kh": ma_kh}


def line(oid, pid, price, qty=1):
    return {"ma_hoa_don": oid, "ma_san_pham": pid, "don_gia": price, "so_luong": qty}


def use(provider="groq", ok=True, p=1000, c=100):
    return {"provider": provider, "model": "x", "ok": ok, "prompt_tokens": p, "completion_tokens": c}


# ---- scans
def test_scan_stats_counts():
    scans = [scan("s1", alts=["20"], conflicts=2, high=1, verdict="caution"),
             scan("s1", outcome="not_label"),
             scan("s2", inp="text", alts=["21"], conflicts=1),
             scan("s3", outcome="vision_unavailable")]
    atcs = [atc("20", "s1"), atc("20", "s1", kind="matched"), atc("99", "s9")]
    st = m.scan_stats(scans, atcs)
    assert st["scans"] == 4 and st["sessions"] == 3
    assert st["outcomes"]["ok"] == 2 and st["outcomes"]["not_label"] == 1 and st["outcomes"]["vision_unavailable"] == 1
    assert st["inputs"] == {"photo": 3, "text": 1}
    assert st["with_conflict"] == 2 and st["with_high_conflict"] == 1
    assert st["with_alternatives"] == 2 and st["alt_sessions"] == 2
    assert st["atc"]["alternative"] == {"count": 2, "sessions": 2} and st["atc"]["matched"]["count"] == 1
    assert st["atc_alt_sessions"] == 1          # s9 never saw alternatives
    assert st["skin_verdicts"]["caution"] == 1


# ---- orders
def test_attributed_vs_assisted_revenue():
    scans = [scan("s1", alts=["20"])]
    atcs = [atc("20")]
    orders = [order("o1", tam_tinh=300000)]
    lines = [line("o1", "20", 100000, 2), line("o1", "77", 100000, 1)]
    r = m.attribute_orders(scans, atcs, orders, lines)
    assert r["attributed"] == {"orders": 1, "lines": 1, "revenue": 200000}
    assert r["assisted"] == {"orders": 1, "revenue": 300000}


def test_cancelled_orders_excluded():
    r = m.attribute_orders([scan()], [atc("20")], [order("o1", status="cancelled"), order("o2", status="confirmed")],
                           [line("o1", "20", 100), line("o2", "20", 100)])
    assert r["attributed"]["orders"] == 1 and r["attributed"]["revenue"] == 100
    assert r["excluded"]["cancelled"] == 1
    assert m.is_cancelled({"trang_thai": "Đã hủy"}) and not m.is_cancelled({"trang_thai": "Đang giao"})


def test_orders_before_first_scan_excluded():
    early = order("o1", ts=T0 - timedelta(days=1))
    r = m.attribute_orders([scan()], [atc("20")], [early], [line("o1", "20", 100)])
    assert r["attributed"]["orders"] == 0 and r["assisted"]["orders"] == 0 and r["excluded"]["before_first_scan"] == 1


def test_other_sessions_and_unattributed_products():
    r = m.attribute_orders([scan("s1")], [atc("20", "s1")],
                           [order("o1", sid="s2"), order("o2", sid="s1")],
                           [line("o1", "20", 100), line("o2", "55", 100)])
    assert r["attributed"]["orders"] == 0 and r["assisted"]["orders"] == 1
    assert r["assisted"]["revenue"] == 300000


def test_order_before_the_add_to_cart_is_not_attributed():
    atcs = [atc("20", ts=T0 + timedelta(hours=2))]
    r = m.attribute_orders([scan()], atcs, [order("o1", ts=T0 + timedelta(hours=1))], [line("o1", "20", 100)])
    assert r["attributed"]["lines"] == 0 and r["assisted"]["orders"] == 1


def test_test_account_orders_excluded():
    r = m.attribute_orders([scan()], [atc("20")], [order("o1", ma_kh=7)], [line("o1", "20", 100)], test_user_ids=["7"])
    assert r["assisted"]["orders"] == 0 and r["excluded"]["test_account"] == 1


# ---- cost
def test_cost_stats_by_provider():
    scans = [scan(usage=[use("groq", False, 1000, 0), use("gemini", True, 2_000_000, 500_000)]), scan(usage=[use("groq", True, 1_000_000, 1_000_000)])]
    c = m.cost_stats(scans, PRICES, 25000)
    g, ge = c["by_provider"]["groq"], c["by_provider"]["gemini"]
    assert g["prompt"] == 1_001_000 and g["completion"] == 1_000_000 and g["calls"] == 2 and g["failed"] == 1
    assert abs(g["cost_usd"] - (1.001 + 2.0)) < 1e-9
    assert abs(ge["cost_usd"] - (1.0 + 2.0)) < 1e-9
    assert abs(c["cost_usd"] - 6.001) < 1e-9 and abs(c["cost_vnd"] - 6.001 * 25000) < 1e-6


def test_prices_from_env_override_and_defaults():
    p = m.prices_from_env({"LABEL_PRICE_GROQ_IN_USD_PER_M": "1.5", "LABEL_PRICE_GEMINI_OUT_USD_PER_M": "bad"})
    assert p["groq"]["in"] == 1.5 and p["groq"]["out"] == 0.59 and p["gemini"] == {"in": 0.30, "out": 2.50}
    assert m.usd_vnd_from_env({}) == 26000 and m.usd_vnd_from_env({"LABEL_USD_VND": "25500"}) == 25500


# ---- derived
def test_roi_none_when_cost_zero():
    scans = [scan(alts=["20"], inp="text")]
    sc = m.scan_stats(scans, [atc("20")])
    co = m.cost_stats(scans, PRICES)
    od = m.attribute_orders(scans, [atc("20")], [order("o1")], [line("o1", "20", 100000)])
    d = m.derive(sc, co, od, 0.3)
    assert co["cost_vnd"] == 0 and d["roi"] is None and d["breakeven_scans_per_order"] is None
    assert d["attributed_gross_profit_vnd"] == 30000 and d["atc_rate"] == 1.0


def test_roi_and_breakeven():
    scans = [scan(alts=["20"], usage=[use("groq", True, 1_000_000, 0)]), scan("s2")]
    sc = m.scan_stats(scans, [atc("20")])
    co = m.cost_stats(scans, PRICES, 1000)            # 1 USD = 1000 VND
    od = m.attribute_orders(scans, [atc("20")], [order("o1")], [line("o1", "20", 100000)])
    d = m.derive(sc, co, od, 0.5)
    assert co["cost_vnd"] == 1000 and d["cost_per_scan_vnd"] == 500
    assert d["attributed_gross_profit_vnd"] == 50000 and d["roi"] == 50
    assert d["breakeven_scans_per_order"] == 100       # 50000 gp per order / 500 per scan
    assert d["atc_rate"] == 1.0


def test_derive_no_scans():
    sc = m.scan_stats([], [])
    d = m.derive(sc, m.cost_stats([], PRICES), m.attribute_orders([], [], [], []), 0.3)
    assert d["roi"] is None and d["atc_rate"] is None and d["cost_per_scan_vnd"] is None


# ---- filtering + collect over an in-memory fake db
def test_filter_events_drops_test_accounts():
    traffic = TestTraffic(user_ids=set(), user_hashes={"hx"})
    dropped = __import__("collections").Counter()
    kept = m.filter_events([scan(user="hx"), scan(user="ok"), scan()], traffic, dropped)
    assert len(kept) == 2 and dropped["event_test_account"] == 1


class FakeColl:
    def __init__(self, rows):
        self.rows = rows

    def find(self, flt=None, proj=None):
        flt = flt or {}
        out = []
        for r in self.rows:
            ok = True
            for k, cond in flt.items():
                v = r.get(k)
                if isinstance(cond, dict):
                    if "$gte" in cond and not (v is not None and v >= cond["$gte"]):
                        ok = False
                    if "$in" in cond and v not in cond["$in"]:
                        ok = False
                elif v != cond:
                    ok = False
            if ok:
                out.append(dict(r))
        return out


class FakeDb:
    def __init__(self, **colls):
        self.colls = {k: FakeColl(v) for k, v in colls.items()}

    def __getitem__(self, name):
        return self.colls.get(name, FakeColl([]))


def test_collect_end_to_end_fake_db():
    now = T0 + timedelta(days=2)
    db = FakeDb(
        label_scan_events=[scan(alts=["20"], usage=[use("groq", True, 1_000_000, 0)]), atc("20"),
                           scan("old", ts=T0 - timedelta(days=60))],
        hoa_don=[order("o1"), order("o2", status="cancelled")],
        chi_tiet_hoa_don=[line("o1", "20", 90000), line("o2", "20", 90000)],
    )
    data = m.collect(db, 7, PRICES, usd_vnd=1000, margin=0.3, now=now, salt="")
    assert data["scan"]["scans"] == 1 and data["orders"]["attributed"]["revenue"] == 90000
    assert data["orders"]["excluded"]["cancelled"] == 1
    assert data["derived"]["roi"] == 27 and data["cost"]["cost_vnd"] == 1000
