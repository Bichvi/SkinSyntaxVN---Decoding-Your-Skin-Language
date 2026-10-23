"""Suggest INCI aliases for unmapped ingredient names (U06). Never writes YAML.

    python scripts/ingredient_coverage.py --top 5000 --out /tmp/unmapped.csv      # container
    python scripts/suggest_ingredient_aliases.py --unmapped /tmp/unmapped.csv \\
        --out /tmp/alias_suggestions.csv [--threshold 92] [--min-count 3]

For every unmapped name it looks for the closest *known* ingredient spelling (rapidfuzz ratio on the
slug with spaces). A suggestion is emitted only when the score is >= ``--threshold`` (default 92) AND
the digit sequence is identical (so ``Polyglyceryl-3`` is never suggested for ``-4``). The CSV has an
empty ``approve`` column: a human decides, then adds the alias to ``data/graph/ingredient_aliases.yaml``.
Ambiguous rows belong in ``data/graph/ingredient_review.csv`` instead of the YAML.
"""

from __future__ import annotations

import argparse
import csv
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, Mapping, Sequence

SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(SERVICE_ROOT))

from rapidfuzz import fuzz, process  # noqa: E402

from graph_sync.ingredients import AliasEntry, load_aliases  # noqa: E402
from graph_sync.normalize import slugify  # noqa: E402

DEFAULT_THRESHOLD = 92.0
MIN_SLUG_LEN = 6            # tiny names ("ha", "bht") are too ambiguous for fuzzy matching


@dataclass(frozen=True)
class Suggestion:
    name: str
    count: int
    target_key: str
    target_inci: str
    matched_variant: str
    score: float


def _digits(slug: str) -> str:
    return "".join(re.findall(r"\d+", slug))


def suggest(
    unmapped: Iterable[tuple[str, int]],
    table: Mapping[str, AliasEntry],
    threshold: float = DEFAULT_THRESHOLD,
    min_count: int = 1,
) -> list[Suggestion]:
    """Pure: closest known spelling for each unmapped ``(name, count)``; sorted by count desc."""

    variants = [slug for slug in table if len(slug) >= MIN_SLUG_LEN]
    choices = [v.replace("_", " ") for v in variants]
    out: list[Suggestion] = []
    for name, count in unmapped:
        if count < min_count:
            continue
        slug = slugify(name)
        if len(slug) < MIN_SLUG_LEN or slug in table:
            continue
        digits = _digits(slug)
        for text, score, idx in process.extract(slug.replace("_", " "), choices, scorer=fuzz.ratio,
                                                score_cutoff=threshold, limit=5):
            variant = variants[idx]
            if _digits(variant) != digits:
                continue
            entry = table[variant]
            out.append(Suggestion(name, count, entry.key, entry.inci, variant, round(float(score), 1)))
            break
    out.sort(key=lambda s: (-s.count, s.name))
    return out


def read_unmapped(path: Path) -> list[tuple[str, int]]:
    """``ingredient_coverage.py --out`` CSV (key,name,count) or a plain (name,count) CSV."""

    rows: list[tuple[str, int]] = []
    with open(path, newline="", encoding="utf-8") as fh:
        reader = csv.reader(fh)
        header = next(reader, [])
        name_col, count_col = (1, 2) if "key" in header else (0, 1)
        for row in reader:
            if len(row) > count_col and row[count_col].isdigit():
                rows.append((row[name_col], int(row[count_col])))
    return rows


def write_csv(path: Path, suggestions: Sequence[Suggestion]) -> None:
    with open(path, "w", newline="", encoding="utf-8") as fh:
        writer = csv.writer(fh)
        writer.writerow(["name", "count", "target_key", "target_inci", "matched_variant", "score", "approve"])
        for s in suggestions:
            writer.writerow([s.name, s.count, s.target_key, s.target_inci, s.matched_variant, s.score, ""])


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Suggest ingredient aliases (rapidfuzz, review required)")
    parser.add_argument("--unmapped", required=True, help="CSV from ingredient_coverage.py --out")
    parser.add_argument("--out", required=True)
    parser.add_argument("--threshold", type=float, default=DEFAULT_THRESHOLD)
    parser.add_argument("--min-count", type=int, default=1)
    args = parser.parse_args(argv)
    if not 80 <= args.threshold <= 100:
        print("--threshold must be 80..100", file=sys.stderr)
        return 2
    suggestions = suggest(read_unmapped(Path(args.unmapped)), load_aliases(), args.threshold, args.min_count)
    write_csv(Path(args.out), suggestions)
    print(f"{len(suggestions)} suggestions (threshold {args.threshold}) -> {args.out}; review before editing YAML")
    return 0


if __name__ == "__main__":
    sys.exit(main())
