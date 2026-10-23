"""Apply human-approved synonym suggestions to ``data/graph/synonyms.yaml`` (U06, part B).

    python scripts/apply_synonym_suggestions.py --file report/search-metrics/zero_results_20261022.csv [--dry-run]

Only rows with ``approve`` = y / yes / 1 are applied, and only for the four synonym groups
(categories, skin_types, concerns, benefits). A row needs ``alias`` and ``target_key`` (+ ``target_group``;
when empty the key is looked up across groups). A row is REJECTED (reported, YAML untouched) when
the target key does not exist, the alias is already used by a *different* canonical key in any group
(the variant-in-two-groups rule enforced by tests/graph_sync/test_normalize.py), or the alias is too
short. The file is edited line by line, so comments, ordering and the flow style are preserved; the
result is parsed again before it is written (atomic replace).

Ingredient suggestions (``target_group=ingredients``) and brands are not handled here: ingredient aliases
go to ``ingredient_aliases.yaml`` and brand names come from the catalog.
"""

from __future__ import annotations

import argparse
import csv
import os
import re
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, Mapping

import yaml

SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

from graph_sync.normalize import SYNONYM_GROUPS, SYNONYMS_PATH, slugify  # noqa: E402

APPROVED = {"y", "yes", "1", "true"}
MIN_ALIAS_SLUG = 3


@dataclass(frozen=True)
class Outcome:
    alias: str
    target: str
    status: str          # applied | duplicate | rejected
    reason: str = ""


def read_rows(path: Path) -> list[dict[str, str]]:
    with open(path, newline="", encoding="utf-8-sig") as fh:
        return [{k: (v or "").strip() for k, v in row.items() if k} for row in csv.DictReader(fh)]


def _variant_owners(data: Mapping[str, Mapping[str, Mapping]]) -> dict[str, tuple[str, str]]:
    """slug -> (group, canonical key) for every name/alias/canonical key in the YAML."""

    owners: dict[str, tuple[str, str]] = {}
    for group in SYNONYM_GROUPS:
        for key, spec in (data.get(group) or {}).items():
            canonical = slugify(key)
            spec = spec or {}
            for variant in [key, spec.get("name", "")] + list(spec.get("aliases") or []):
                slug = slugify(variant)
                if slug:
                    owners.setdefault(slug, (group, canonical))
    return owners


def _add_alias_to_line(line: str, alias: str) -> str | None:
    """``  key: {name: "..", aliases: [..]}`` -> same line with ``alias`` appended; None if not a flow line."""

    match = re.match(r"^(\s*[^\s:#]+:\s*\{)(.*)(\}\s*(?:#.*)?)$", line)
    if not match:
        return None
    body = match.group(2)
    quoted = '"' + alias.replace("\\", "\\\\").replace('"', '\\"') + '"'
    if "aliases: [" in body:
        body = re.sub(r"aliases: \[(.*)\]\s*$",
                      lambda m: "aliases: [" + (m.group(1) + ", " if m.group(1).strip() else "") + quoted + "]", body)
    else:
        body = f"{body}, aliases: [{quoted}]"
    return match.group(1) + body + match.group(3)


def apply_approved(yaml_text: str, rows: Iterable[Mapping[str, str]]) -> tuple[str, list[Outcome]]:
    """Pure: returns the new YAML text and one :class:`Outcome` per approved row."""

    data = yaml.safe_load(yaml_text) or {}
    owners = _variant_owners(data)
    lines = yaml_text.split("\n")
    outcomes: list[Outcome] = []
    for row in rows:
        if row.get("approve", "").strip().lower() not in APPROVED:
            continue
        alias = row.get("alias", "").strip()
        key = slugify(row.get("target_key", ""))
        group = row.get("target_group", "").strip()
        slug = slugify(alias)
        if group == "ingredients":
            outcomes.append(Outcome(alias, key, "rejected", "ingredient alias: dùng ingredient_aliases.yaml"))
            continue
        if len(slug) < MIN_ALIAS_SLUG:
            outcomes.append(Outcome(alias, key, "rejected", "alias quá ngắn/rỗng"))
            continue
        groups = [group] if group in SYNONYM_GROUPS else [g for g in SYNONYM_GROUPS if key in
                                                          {slugify(k) for k in (data.get(g) or {})}]
        found = [(g, k) for g in groups for k in (data.get(g) or {}) if slugify(k) == key]
        if len(found) != 1:
            reason = "target_key không tồn tại" if not found else "target_key mơ hồ giữa các nhóm: ghi rõ target_group"
            outcomes.append(Outcome(alias, key, "rejected", reason))
            continue
        target_group, target_name = found[0]
        owner = owners.get(slug)
        if owner is not None:
            if owner == (target_group, key):
                outcomes.append(Outcome(alias, key, "duplicate", "đã có trong nhóm này"))
            else:
                outcomes.append(Outcome(alias, key, "rejected", f"alias đã thuộc {owner[0]}/{owner[1]}"))
            continue
        if not _insert_line(lines, target_group, target_name, alias):
            outcomes.append(Outcome(alias, key, "rejected", "không sửa được dòng YAML (không phải dạng {..})"))
            continue
        owners[slug] = (target_group, key)
        outcomes.append(Outcome(alias, key, "applied"))
    new_text = "\n".join(lines)
    if new_text != yaml_text:
        yaml.safe_load(new_text)                 # must still parse
    return new_text, outcomes


def _insert_line(lines: list[str], group: str, key: str, alias: str) -> bool:
    in_group = False
    for index, line in enumerate(lines):
        if re.match(r"^[A-Za-z_]+:\s*$", line):
            in_group = line.strip().rstrip(":") == group
            continue
        if in_group and re.match(rf"^\s*{re.escape(key)}:\s*\{{", line):
            edited = _add_alias_to_line(line, alias)
            if edited is None:
                return False
            lines[index] = edited
            return True
    return False


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Apply approved synonym suggestions to synonyms.yaml")
    parser.add_argument("--file", required=True, help="CSV from search_metrics.py --zero-result-report (approve column filled)")
    parser.add_argument("--yaml", default=str(SYNONYMS_PATH), help="override the synonyms YAML (tests)")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)

    csv_path, yaml_path = Path(args.file), Path(args.yaml)
    if not csv_path.is_file() or not yaml_path.is_file():
        print("ERROR: --file / --yaml not found", file=sys.stderr)
        return 2
    text = yaml_path.read_text(encoding="utf-8")
    new_text, outcomes = apply_approved(text, read_rows(csv_path))
    if not outcomes:
        print("no approved rows (approve = y); nothing to do")
        return 0
    for o in outcomes:
        print(f"  {o.status:9s} {o.alias!r} -> {o.target}" + (f"  ({o.reason})" if o.reason else ""))
    applied = sum(1 for o in outcomes if o.status == "applied")
    if args.dry_run or not applied:
        print(f"dry-run / nothing applied: {applied} change(s) pending" if args.dry_run else "nothing applied")
        return 0 if applied or args.dry_run else 1
    handle, tmp = tempfile.mkstemp(dir=yaml_path.parent, prefix=".synonyms.", suffix=".tmp")
    with os.fdopen(handle, "w", encoding="utf-8", newline="") as fh:
        fh.write(new_text)
    os.replace(tmp, yaml_path)
    print(f"applied {applied} alias(es) to {yaml_path.name}. Next steps:")
    print("  1. pytest ai-service-flask/tests/graph_sync/test_normalize.py ai-service-flask/tests/search_service -q")
    print("  2. .venv/Scripts/python.exe ai-service-flask/scripts/export_search_dictionary.py   # PHP autocomplete (U04)")
    print("  3. MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-ai python -c \"from shared.redis_kit.pubsub import "
          "publish_invalidate; publish_invalidate('dictionary')\"   # Flask reloads YAML + dictionary, no restart")
    return 0


if __name__ == "__main__":
    sys.exit(main())
