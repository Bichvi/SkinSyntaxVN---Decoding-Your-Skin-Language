"""U09: what a cart covers of the basic face-care routine (``data/graph/routine_steps.yaml``). Pure.

A product counts for a step when any key of its category chain (leaf + ancestors, the ``CHILD_OF``
path of the graph / ``category_keys`` of the catalog) is one of the step's categories.
"""

from __future__ import annotations

from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence

import yaml

ROUTINE_PATH = Path(__file__).resolve().parents[1] / "data" / "graph" / "routine_steps.yaml"


@dataclass(frozen=True)
class Step:
    key: str
    label_vi: str
    categories: tuple[str, ...]
    core: bool
    optional: bool


@dataclass(frozen=True)
class RoutineDef:
    steps: tuple[Step, ...]                     # canonical order (as in the YAML)
    face_care_roots: frozenset[str]
    sessions: Mapping[str, tuple[str, ...]]

    def step(self, key: str) -> Step | None:
        return next((s for s in self.steps if s.key == key), None)


@lru_cache(maxsize=4)
def _load_raw(path: str) -> dict[str, Any]:
    with open(path, encoding="utf-8") as handle:
        return yaml.safe_load(handle) or {}


def load_routine(path: Path | str | None = None) -> RoutineDef:
    raw = _load_raw(str(path or ROUTINE_PATH))
    steps = tuple(
        Step(key=str(key), label_vi=str(spec.get("label_vi") or key),
             categories=tuple(str(c) for c in (spec.get("categories") or [])),
             core=bool(spec.get("core")), optional=bool(spec.get("optional")))
        for key, spec in (raw.get("steps") or {}).items()
    )
    known = {s.key for s in steps}
    sessions = {str(name): tuple(str(k) for k in keys if str(k) in known) for name, keys in (raw.get("sessions") or {}).items()}
    return RoutineDef(steps=steps, face_care_roots=frozenset(str(x) for x in (raw.get("face_care_roots") or [])),
                      sessions=sessions)


def steps_of(chain: Iterable[str], routine: RoutineDef) -> list[str]:
    """Step keys a product with this category chain belongs to (usually one)."""

    keys = set(chain)
    return [s.key for s in routine.steps if keys & set(s.categories)]


def analyze(routine: RoutineDef, categories_by_product: Mapping[str, Sequence[str]]) -> dict[str, Any]:
    """``{"applicable", "has", "missing", "suggest_order"}``.

    ``applicable`` is False when the cart holds no face-care product (only hair/body/make-up): nothing is
    claimed missing then. ``has``/``missing`` follow the canonical step order; ``suggest_order`` lists the
    missing steps core-first (the order suggestions are produced in). Optional steps are never missing.
    """

    chains = [list(c) for c in categories_by_product.values()]
    applicable = any(set(chain) & routine.face_care_roots for chain in chains)
    if not applicable:
        return {"applicable": False, "has": [], "missing": [], "suggest_order": []}
    has_set: set[str] = set()
    for chain in chains:
        has_set.update(steps_of(chain, routine))
    has = [s.key for s in routine.steps if s.key in has_set]
    missing_steps = [s for s in routine.steps if s.key not in has_set and not s.optional]
    ordered = sorted(missing_steps, key=lambda s: (0 if s.core else 1, routine.steps.index(s)))
    return {
        "applicable": True,
        "has": has,
        "missing": [s.key for s in missing_steps],
        "suggest_order": [s.key for s in ordered],
    }
