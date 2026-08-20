#!/usr/bin/env python3
"""Merge missing keys from .env.example into .env (keeps existing values)."""

from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
EXAMPLE = ROOT / ".env.example"
TARGET = ROOT / ".env"


def parse_env(text: str) -> tuple[list[str], dict[str, str]]:
    lines: list[str] = []
    values: dict[str, str] = {}
    for line in text.splitlines():
        lines.append(line)
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in stripped:
            continue
        key, val = stripped.split("=", 1)
        key = key.strip()
        if key and re.match(r"^[A-Za-z_][A-Za-z0-9_]*$", key):
            values[key] = val
    return lines, values


def main() -> int:
    if not EXAMPLE.is_file():
        print(f"Missing {EXAMPLE}", file=sys.stderr)
        return 1

    example_text = EXAMPLE.read_text(encoding="utf-8")
    _, example_values = parse_env(example_text)

    if TARGET.is_file():
        target_text = TARGET.read_text(encoding="utf-8")
        _, target_values = parse_env(target_text)
    else:
        target_text = ""
        target_values = {}

    merged = dict(example_values)
    merged.update(target_values)

    missing = [k for k in example_values if k not in target_values]
    fixed_endpoint = False
    chat = merged.get("AI_CHAT_ENDPOINT", "")
    if chat.endswith("/api/chat") and not chat.endswith("/api/chat/auto"):
        merged["AI_CHAT_ENDPOINT"] = chat.rstrip("/") + "/auto"
        fixed_endpoint = True

    out_lines: list[str] = []
    written_keys: set[str] = set()

    for line in example_text.splitlines():
        stripped = line.strip()
        if stripped.startswith("#") or not stripped:
            out_lines.append(line)
            continue
        if "=" not in stripped:
            out_lines.append(line)
            continue
        key = stripped.split("=", 1)[0].strip()
        if key in merged:
            out_lines.append(f"{key}={merged[key]}")
            written_keys.add(key)
        else:
            out_lines.append(line)

    extra_keys = sorted(set(merged) - written_keys)
    if extra_keys:
        out_lines.append("")
        out_lines.append("# --- Extra keys (kept from previous .env) ---")
        for key in extra_keys:
            out_lines.append(f"{key}={merged[key]}")

    TARGET.write_text("\n".join(out_lines).rstrip() + "\n", encoding="utf-8")

    print(f"Updated {TARGET}")
    if missing:
        print(f"Added {len(missing)} key(s): {', '.join(missing)}")
    else:
        print("No new keys needed.")
    if fixed_endpoint:
        print("Fixed AI_CHAT_ENDPOINT → .../api/chat/auto")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
