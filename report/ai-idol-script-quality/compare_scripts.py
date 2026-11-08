"""Read-only before/after harness for AI Idol script quality.

Generates scripts for real products straight from the prompt builder (no campaign,
no job, no DB writes) and saves each script plus prompt stats.

Usage (from ai-idol-service/):  ../.venv/Scripts/python.exe ../report/ai-idol-script-quality/compare_scripts.py before
"""
from __future__ import annotations

import json
import sys
import time
from pathlib import Path

sys.path.insert(0, ".")

from config import GROQ_MAX_PROMPT_CHARS  # noqa: E402
from core.purpose import LIVE_AND_PRODUCT, PRODUCT_VIDEO  # noqa: E402
from core.script_text import split_labeled_sections  # noqa: E402
from core.text_budget import fit_message_pair  # noqa: E402
from engines.content_agent import build_script_prompts  # noqa: E402
from engines.product_collector import collect_product_and_snapshot  # noqa: E402
from engines import script_selector  # noqa: E402
from engines.script_selector import select_best_script  # noqa: E402
from engines.script_validator import _deterministic_issues  # noqa: E402
from providers.llm_provider import LLMProvider  # noqa: E402

CASES = [("98", PRODUCT_VIDEO), ("101", PRODUCT_VIDEO), ("68", PRODUCT_VIDEO), ("101", LIVE_AND_PRODUCT)]
CLIP_MARKER = "[đã rút gọn]"


def knowledge_for(snapshot: dict) -> str:
    try:
        from engines.knowledge_retriever import retrieve_knowledge
        return retrieve_knowledge(snapshot) or ""
    except Exception as exc:  # ChromaDB may be offline locally; both runs then get the same empty context.
        print(f"  (knowledge unavailable: {exc})")
        return ""


def main(tag: str, only: str = "") -> None:
    """`only` limits the run to one case, e.g. "101:product_video"."""
    out = Path(__file__).parent / tag
    out.mkdir(parents=True, exist_ok=True)
    cache = Path(__file__).parent / "inputs.json"
    inputs = json.loads(cache.read_text(encoding="utf-8")) if cache.exists() else {}
    summary = []
    best_mode = tag.startswith("best")
    cases = [case for case in CASES if not only or f"{case[0]}:{case[1]}" == only]
    for case_no, (pid, purpose) in enumerate(cases):
        if best_mode and case_no:
            time.sleep(20)  # Groq free tier: 8000 TPM
        if pid not in inputs:
            snap = collect_product_and_snapshot(pid)
            inputs[pid] = {"snapshot": json.loads(json.dumps(snap, default=str)), "knowledge": knowledge_for(snap)}
            cache.write_text(json.dumps(inputs, ensure_ascii=False, indent=1), encoding="utf-8")
        snap, knowledge = inputs[pid]["snapshot"], inputs[pid]["knowledge"]
        config = {"length_mode": None}
        system, user = build_script_prompts(snap, knowledge, "Giới thiệu sản phẩm", config, purpose)
        sent_system, sent_user = fit_message_pair(system, user, GROQ_MAX_PROMPT_CHARS)
        start = time.time()
        selection = None
        if best_mode:
            drafts = []
            real_generate = script_selector._generate

            def capture(*args, **kwargs):
                result = real_generate(*args, **kwargs)
                drafts.extend(result[0])
                return result

            script_selector._generate = capture  # keep every candidate for the report
            try:
                script, selection = select_best_script(system, user, snap, purpose)  # n = SCRIPT_CANDIDATES
            finally:
                script_selector._generate = real_generate
        else:
            script = LLMProvider().invoke(system, user)
        issues = _deterministic_issues(script, snap, purpose)
        stats = {
            "product": pid, "name": snap.get("name"), "purpose": purpose,
            "prompt_chars": len(system) + len(user),
            "sent_chars": len(sent_system) + len(sent_user),
            "clipped": CLIP_MARKER in sent_system or CLIP_MARKER in sent_user,
            "newlines_kept": sent_system.count("\n") + sent_user.count("\n"),
            "sections": [label for label, _ in split_labeled_sections(script)],
            "words": len(script.split()),
            "deterministic_issues": [f"{i.code}: {i.original_text}" for i in issues],
            "seconds": round(time.time() - start, 1),
        }
        if selection is not None:
            stats["selection"] = selection
        summary.append(stats)
        name = f"{pid}_{purpose}.md"
        (out / name).write_text(
            f"# {snap.get('name')} ({purpose})\n\n```json\n{json.dumps(stats, ensure_ascii=False, indent=1)}\n```\n\n{script}\n",
            encoding="utf-8")
        if selection is not None:
            for i, draft in enumerate(drafts):
                (out / f"{pid}_{purpose}_cand{i}.md").write_text(draft + "\n", encoding="utf-8")
        print(f"{name}: clipped={stats['clipped']} words={stats['words']} issues={len(issues)}")
    (out / "summary.json").write_text(json.dumps(summary, ensure_ascii=False, indent=1), encoding="utf-8")


if __name__ == "__main__":
    main(sys.argv[1] if len(sys.argv) > 1 else "before", sys.argv[2] if len(sys.argv) > 2 else "")
