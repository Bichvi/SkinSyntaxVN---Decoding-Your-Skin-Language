"""Chạy bộ đánh giá chatbot hỗ trợ cửa hàng qua endpoint thật (Bước 07).

Dùng:  python tests/run_store_support_eval.py [--url URL] [--out FILE]
Không gửi user_email / conversation_id. Mỗi câu một request độc lập, history rỗng.
Chấm tự động: đúng slug, chứa must_contain, số liệu lạ, fallback, thời gian.
Chấm bán tự động: ca "overclaim" (cụm khẳng định không có trong nguồn) và ca "attack".
"""
import argparse
import datetime
import json
import re
import statistics
import time
import unicodedata
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CASES = Path(__file__).with_name("store_support_eval_cases.json")
SUPPORT = ROOT / "backend" / "app" / "content" / "support.json"


def fold(s: str) -> str:
    s = unicodedata.normalize("NFD", (s or "").lower().replace("đ", "d"))
    return "".join(c for c in s if unicodedata.category(c) != "Mn")


def number_tokens(text: str) -> set:
    return {m.rstrip(".,") for m in re.findall(r"\d[\d.,]*\d|\d", text)}


def strip_noise(answer: str) -> str:
    """Bỏ dòng Nguồn, chip quicksend, URL, và số thứ tự đầu dòng."""
    out = []
    for line in answer.splitlines():
        if line.strip().startswith("Nguồn:") or "quicksend:" in line:
            continue
        line = re.sub(r"\(/?[^)\s]*\)", "", line)
        line = re.sub(r"^\s*\d+[.)]\s+", "", line)
        out.append(line)
    return "\n".join(out)


_NEGATION = re.compile(r"\b(khong|chua|ko|chang|khong the|tu choi)\b")


def overclaim_hits(answer: str, phrases: list) -> list:
    """Cụm 'unsupported' xuất hiện trong câu KHẲNG ĐỊNH. Cụm đứng trong câu có phủ định (không/chưa) không tính,
    trừ khi chính cụm đó chứa phủ định (vd 'không xuất hóa đơn')."""
    sentences = [fold(x) for x in re.split(r"[.;!?\n]|, ", strip_noise(answer))]
    hits = []
    for p in phrases:
        fp = fold(p)
        self_negated = bool(_NEGATION.search(fp))
        if any(fp in s and (self_negated or not _NEGATION.search(s)) for s in sentences):
            hits.append(p)
    return hits


def call(url: str, q: str):
    body = json.dumps({"message": q, "history": []}, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json"})
    t = time.time()
    try:
        d = json.loads(urllib.request.urlopen(req, timeout=150).read().decode("utf-8"))
    except Exception as e:  # noqa: BLE001
        return {"error": str(e)}, time.time() - t
    return d, time.time() - t


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default="http://localhost:8080/index.php?r=ai_chat_assistant")
    ap.add_argument("--out", default=None)
    args = ap.parse_args()
    cases = json.loads(CASES.read_text(encoding="utf-8"))["cases"]
    allowed = number_tokens(SUPPORT.read_text(encoding="utf-8"))
    results = []
    for c in cases:
        d, dt = call(args.url, c["question"])
        ans = d.get("answer") or ""
        slug = d.get("support_topic")
        intent = d.get("intent_mode")
        want = c.get("expected_slug_any") or ([c["expected_slug"]] if c.get("expected_slug") else [])
        is_ss = intent == "STORE_SUPPORT"
        fa = fold(ans)
        fa_noise = fold(strip_noise(ans))
        r = {
            "type": c["type"], "question": c["question"], "intent": intent, "slug": slug,
            "pipeline_mode": d.get("pipeline_mode"), "fallback": bool(d.get("fallback")),
            "seconds": round(dt, 2), "error": d.get("error"), "answer": ans,
        }
        if c["type"] == "product":
            r["ok_intent"] = not is_ss
        else:
            r["ok_intent"] = is_ss
            r["ok_slug"] = bool(want) and slug in want
            r["ok_contain"] = all(fold(m) in fa for m in c.get("must_contain", []))
            r["strange_numbers"] = sorted(
                n for n in number_tokens(strip_noise(ans)) - allowed - number_tokens(c["question"])
            )
            bad = [p for p in c.get("must_not_contain", []) if fold(p) in fa]
            r["forbidden_hit"] = bad
            if c.get("any_of"):
                r["ok_any_of"] = any(fold(p) in fa_noise for p in c["any_of"])
            if c["type"] == "overclaim":
                r["overclaim_hits"] = overclaim_hits(ans, c.get("unsupported", []))
                r["note"] = c.get("note")
        results.append(r)
        print(f"[{c['type']}] {c['question'][:50]!r} -> {intent}/{slug} {dt:.1f}s", flush=True)

    pol = [r for r in results if r["type"] in ("policy", "overclaim")]
    pol_plain = [r for r in results if r["type"] == "policy"]
    attack = [r for r in results if r["type"] == "attack"]
    prod = [r for r in results if r["type"] == "product"]
    over = [r for r in results if r["type"] == "overclaim"]
    with_contain = [c for c in cases if c["type"] in ("policy", "overclaim") and c.get("must_contain")]
    times = sorted(r["seconds"] for r in results if not r.get("error"))
    pct = lambda a, b: round(100 * a / b, 1) if b else None  # noqa: E731
    summary = {
        "date": datetime.date.today().isoformat(),
        "total": len(results),
        "policy_slug_correct": f"{sum(r['ok_slug'] for r in pol_plain)}/{len(pol_plain)} ({pct(sum(r['ok_slug'] for r in pol_plain), len(pol_plain))}%)",
        "policy_overclaim_slug_correct": f"{sum(r['ok_slug'] for r in pol)}/{len(pol)}",
        "must_contain_ok": f"{sum(r['ok_contain'] for r in pol if any(c['question']==r['question'] and c.get('must_contain') for c in cases))}/{len(with_contain)}",
        "strange_number_cases": sum(1 for r in results if r.get("strange_numbers")),
        "strange_number_detail": [(r["question"], r["strange_numbers"]) for r in results if r.get("strange_numbers")],
        "product_not_captured": f"{sum(r['ok_intent'] for r in prod)}/{len(prod)}",
        "attack_ok": f"{sum(1 for r in attack if r['ok_intent'] and r['ok_slug'] and r['ok_contain'] and not r['forbidden_hit'] and r.get('ok_any_of', True))}/{len(attack)}",
        "overclaim_flagged_auto": f"{sum(1 for r in over if r['overclaim_hits'])}/{len(over)}",
        "fallback_rate": f"{sum(r['fallback'] for r in results if r['intent']=='STORE_SUPPORT')}/{sum(1 for r in results if r['intent']=='STORE_SUPPORT')}",
        "p50_s": round(statistics.median(times), 2) if times else None,
        "p95_s": round(times[min(len(times) - 1, int(0.95 * len(times)))], 2) if times else None,
        "errors": sum(1 for r in results if r.get("error")),
    }
    out = Path(args.out) if args.out else ROOT / "report" / "store-support-e2e" / f"eval-{summary['date']}.json"
    out.parent.mkdir(parents=True, exist_ok=True)
    out.write_text(json.dumps({"summary": summary, "results": results}, ensure_ascii=False, indent=1), encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=1))
    print("wrote", out)


if __name__ == "__main__":
    main()
