"""Export the standard tag keys for the PHP admin product form and review page (U07).

    python ai-service-flask/scripts/export_tag_keys.py [--out backend/app/data/tag_keys.json] [--dry-run]

Reads only ``data/graph/synonyms.yaml``; writes one small JSON file (no database access).
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve()
SERVICE_ROOT = HERE.parents[1]
REPO_ROOT = HERE.parents[2]
DEFAULT_OUT = REPO_ROOT / "backend" / "app" / "data" / "tag_keys.json"
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))


def main(argv: list[str] | None = None) -> int:
    from tagging.keys_export import build_tag_keys

    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--out", default=str(DEFAULT_OUT))
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)
    payload = build_tag_keys()
    text = json.dumps(payload, ensure_ascii=False, indent=2) + "\n"
    counts = {g: len(items) for g, items in payload["groups"].items()}
    if args.dry_run:
        print(text)
        return 0
    Path(args.out).parent.mkdir(parents=True, exist_ok=True)
    Path(args.out).write_text(text, encoding="utf-8")
    print(f"wrote {args.out} {counts}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
