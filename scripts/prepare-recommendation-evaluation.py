"""Prepare a blind labeling sheet or validate labels and join frozen rankings."""

import argparse
import csv
import json
from pathlib import Path


def load(path):
    return json.loads(Path(path).read_text(encoding="utf-8"))


def identifier(value):
    result = str(value or "").strip()
    if not result or result.startswith(("=", "+", "-", "@")):
        raise ValueError("Invalid identifier")
    return result


def label_pairs(cases, pool):
    if set(cases) != set(pool):
        raise ValueError("Cases and candidate pools must have the same IDs")
    pairs = [(case, identifier(product)) for case in cases for product in pool[case]]
    if len(set(pairs)) != len(pairs) or set(case for case, _ in pairs) != set(cases):
        raise ValueError("Each case needs a nonempty pool with distinct product IDs")
    return sorted(pairs)


def join_labels(cases, pool, rows, ranked):
    expected = set(label_pairs(cases, pool))
    labels = {}
    for row in rows:
        key = (row["case_id"], row["product_id"])
        if key not in expected or key in labels or row["relevant"] not in {"0", "1"}:
            raise ValueError("Unexpected, duplicate or unlabeled assessment")
        labels[key] = row["relevant"] == "1"
    if set(labels) != expected or set(ranked) != set(cases):
        raise ValueError("Missing labels or rankings")
    runs = []
    for case in cases:
        ordered = [identifier(product) for product in ranked[case]]
        if len(set(ordered)) != len(ordered) or not all((case, product) in expected for product in ordered):
            raise ValueError(f"Duplicate or unjudged ranked product in {case}")
        runs.append({
            "case_id": case,
            "ranked_ids": ordered,
            "relevant_ids": [p for (c, p), relevant in labels.items() if c == case and relevant],
        })
    return runs


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("cases")
    parser.add_argument("pool", help="JSON object mapping case ID to frozen candidate IDs")
    parser.add_argument("--labels", help="Completed blinded CSV")
    parser.add_argument("--rankings", help="JSON object mapping case ID to ordered output IDs")
    parser.add_argument("--output", required=True, help="New CSV for labeling or JSON ready for ranked evaluation")
    args = parser.parse_args()
    try:
        case_ids = [identifier(row["case_id"]) for row in load(args.cases)]
        if not case_ids or len(set(case_ids)) != len(case_ids):
            raise ValueError("Case IDs must be present and distinct")
        pool = load(args.pool)
        pairs = label_pairs(case_ids, pool)
        if bool(args.labels) != bool(args.rankings):
            raise ValueError("Provide both labels and rankings to join a run")
        if args.labels:
            with open(args.labels, encoding="utf-8-sig", newline="") as source:
                result = join_labels(case_ids, pool, csv.DictReader(source), load(args.rankings))
            with open(args.output, "x", encoding="utf-8") as output:
                json.dump(result, output, indent=2, ensure_ascii=False)
        else:
            with open(args.output, "x", encoding="utf-8-sig", newline="") as output:
                writer = csv.writer(output)
                writer.writerow(("case_id", "product_id", "relevant", "reason"))
                writer.writerows((case, product, "", "") for case, product in pairs)
    except (ValueError, KeyError, OSError) as error:
        parser.error(str(error))


if __name__ == "__main__":
    main()
