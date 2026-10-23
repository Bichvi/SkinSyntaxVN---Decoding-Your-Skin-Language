"""A/B experiment framework for the recommend API (U12 part B).

Experiments live in ``data/experiments.yaml``::

    experiments:
      - id: graph_weight_v1
        status: draft                      # draft | running | stopped  (only ``running`` is applied)
        traffic: {control: 50, variant: 50}
        overrides: {weights.graph: 0.45, weights.semantic: 0.20}      # applied to the non-control arm

Assignment is stable and stateless: ``bucket = sha256(session_hash + experiment_id) % 100`` and the arms
split the 0..99 buckets in declared order (``control`` first). The same session always gets the same arm,
different experiments bucket independently (the id is part of the hash).

Overrides may change: ``weights.graph|semantic|popularity|personal``, ``w_cop``, ``w_curated``, ``w_ctr`` and
the two switches ``co_purchase`` / ``ctr_boost`` (turn the behavioural boosts on for ONE arm instead of for
everybody - this is how the gated features of U12 are meant to be rolled out). Anything else is rejected, so a
typo cannot silently run an experiment with no effect. Pure and I/O-free except ``ExperimentRegistry`` (reads
the YAML, re-checks its mtime every ``SEARCH_EXPERIMENTS_RELOAD_S`` seconds; a broken file keeps the last good copy).
"""

from __future__ import annotations

import hashlib
import logging
import os
import re
import threading
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable, Iterable, Mapping

from . import config

logger = logging.getLogger("search_service.experiments")

STATUSES = ("draft", "running", "stopped")
WEIGHT_KEYS = ("graph", "semantic", "popularity", "personal")
SCALAR_KEYS = ("w_cop", "w_curated", "w_ctr")
SWITCH_KEYS = ("co_purchase", "ctr_boost")
CONTROL = "control"
_ID_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,39}$")
_VARIANT_RE = re.compile(r"^[a-z0-9][a-z0-9_-]{0,19}$")
DEFAULT_FILE = Path(__file__).resolve().parents[1] / "data" / "experiments.yaml"


class ExperimentConfigError(ValueError):
    """An experiment entry is invalid (reported per entry; the other entries still load)."""


@dataclass(frozen=True)
class Overrides:
    """Ranking parameters an arm changes. ``None`` / empty = keep the global setting."""

    weights: Mapping[str, float] = field(default_factory=dict)
    w_cop: float | None = None
    w_curated: float | None = None
    w_ctr: float | None = None
    co_purchase: bool | None = None
    ctr_boost: bool | None = None

    def apply_weights(self, base: Mapping[str, float]) -> dict[str, float]:
        merged = dict(base)
        merged.update(self.weights)
        return merged

    def is_empty(self) -> bool:
        return not (self.weights or self.w_cop is not None or self.w_curated is not None or self.w_ctr is not None
                    or self.co_purchase is not None or self.ctr_boost is not None)


NO_OVERRIDES = Overrides()


@dataclass(frozen=True)
class Experiment:
    id: str
    status: str
    arms: tuple[tuple[str, int], ...]       # (variant, percent) in bucket order, ``control`` first
    overrides: Overrides                     # applied to every non-control arm
    description: str = ""

    @property
    def running(self) -> bool:
        return self.status == "running"


@dataclass(frozen=True)
class Assignment:
    experiment_id: str
    variant: str
    overrides: Overrides

    @property
    def tag(self) -> str:
        """Cache-key component: a response is only valid for the arm it was computed for."""

        return f"{self.experiment_id}:{self.variant}"

    def to_dict(self) -> dict[str, str]:
        return {"id": self.experiment_id, "variant": self.variant}


def bucket(session_hash: str, experiment_id: str) -> int:
    """``sha256(session_hash + experiment_id) % 100`` - stable for a given pair, ~uniform over 0..99."""

    digest = hashlib.sha256((str(session_hash) + str(experiment_id)).encode("utf-8")).hexdigest()
    return int(digest, 16) % 100


def variant_for(experiment: Experiment, session_hash: str) -> str:
    point = bucket(session_hash, experiment.id)
    upper = 0
    for name, percent in experiment.arms:
        upper += percent
        if point < upper:
            return name
    return experiment.arms[-1][0]       # unreachable when traffic sums to 100 (validated)


def assign(experiment: Experiment, session_hash: str) -> Assignment:
    variant = variant_for(experiment, session_hash)
    return Assignment(experiment.id, variant, NO_OVERRIDES if variant == CONTROL else experiment.overrides)


def _number(value: Any, name: str, low: float, high: float) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ExperimentConfigError(f"override {name} must be a number")
    if not low <= float(value) <= high:
        raise ExperimentConfigError(f"override {name} must be in [{low}, {high}]")
    return float(value)


def parse_overrides(raw: Any) -> Overrides:
    if raw is None:
        return NO_OVERRIDES
    if not isinstance(raw, Mapping):
        raise ExperimentConfigError("overrides must be a mapping")
    weights: dict[str, float] = {}
    scalars: dict[str, Any] = {}
    for key, value in raw.items():
        key = str(key)
        if key.startswith("weights."):
            name = key[len("weights."):]
            if name not in WEIGHT_KEYS:
                raise ExperimentConfigError(f"unknown weight '{name}' (allowed: {', '.join(WEIGHT_KEYS)})")
            weights[name] = _number(value, key, 0.0, 1.0)
        elif key in SCALAR_KEYS:
            scalars[key] = _number(value, key, 0.0, 1.0)
        elif key in SWITCH_KEYS:
            if not isinstance(value, bool):
                raise ExperimentConfigError(f"override {key} must be true/false")
            scalars[key] = value
        else:
            raise ExperimentConfigError(f"unknown override '{key}'")
    return Overrides(weights=weights, **scalars)


def parse_experiment(raw: Any) -> Experiment:
    if not isinstance(raw, Mapping):
        raise ExperimentConfigError("experiment must be a mapping")
    exp_id = str(raw.get("id", "")).strip()
    if not _ID_RE.match(exp_id):
        raise ExperimentConfigError("id must match [a-z0-9][a-z0-9_-]{0,39}")
    status = str(raw.get("status", "draft")).strip().lower()
    if status not in STATUSES:
        raise ExperimentConfigError(f"status must be one of {', '.join(STATUSES)}")
    traffic = raw.get("traffic")
    if not isinstance(traffic, Mapping) or CONTROL not in traffic or len(traffic) != 2:
        raise ExperimentConfigError("traffic must be {control: N, <variant>: M} (exactly two arms)")
    arms: list[tuple[str, int]] = [(CONTROL, 0)]
    for name, percent in traffic.items():
        name = str(name)
        if isinstance(percent, bool) or not isinstance(percent, int) or not 1 <= percent <= 99:
            raise ExperimentConfigError(f"traffic.{name} must be an integer percentage 1..99")
        if name != CONTROL:
            if not _VARIANT_RE.match(name):
                raise ExperimentConfigError("variant name must match [a-z0-9][a-z0-9_-]{0,19}")
            arms.append((name, percent))
        else:
            arms[0] = (CONTROL, percent)
    if sum(percent for _, percent in arms) != 100:
        raise ExperimentConfigError("traffic percentages must sum to 100")
    overrides = parse_overrides(raw.get("overrides"))
    if status == "running" and overrides.is_empty():
        raise ExperimentConfigError("a running experiment needs at least one override")
    return Experiment(id=exp_id, status=status, arms=tuple(arms), overrides=overrides,
                      description=str(raw.get("description", "")).strip()[:300])


def parse_experiments(document: Any) -> tuple[list[Experiment], list[str]]:
    """YAML document -> (valid experiments, error messages). Duplicate ids keep the first entry."""

    entries = document.get("experiments") if isinstance(document, Mapping) else None
    if entries is None:
        return [], []
    if not isinstance(entries, list):
        return [], ["'experiments' must be a list"]
    out: list[Experiment] = []
    errors: list[str] = []
    seen: set[str] = set()
    for index, raw in enumerate(entries):
        try:
            experiment = parse_experiment(raw)
        except ExperimentConfigError as exc:
            label = raw.get("id") if isinstance(raw, Mapping) else index
            errors.append(f"experiment {label}: {exc}")
            continue
        if experiment.id in seen:
            errors.append(f"experiment {experiment.id}: duplicate id ignored")
            continue
        seen.add(experiment.id)
        out.append(experiment)
    return out, errors


def select(experiments: Iterable[Experiment], session_hash: str | None) -> Assignment | None:
    """The arm of the first ``running`` experiment for this session (None = no session or nothing running).

    Only one experiment is applied per request, otherwise two overrides could fight over the same weight.
    """

    if not session_hash:
        return None
    for experiment in experiments:
        if experiment.running:
            return assign(experiment, session_hash)
    return None


class ExperimentRegistry:
    """Loads ``experiments.yaml`` lazily and re-reads it when its mtime changes (cheap ``stat`` every few seconds)."""

    def __init__(self, path: str | os.PathLike[str] | None = None, reload_s: float | None = None,
                 clock: Callable[[], float] = time.monotonic) -> None:
        self.path = Path(path or config.EXPERIMENTS_FILE or DEFAULT_FILE)
        self.reload_s = config.EXPERIMENTS_RELOAD_S if reload_s is None else reload_s
        self._clock = clock
        self._lock = threading.Lock()
        self._experiments: tuple[Experiment, ...] = ()
        self._mtime: float | None = None
        self._checked_at = -1e9
        self.errors: tuple[str, ...] = ()

    def experiments(self) -> tuple[Experiment, ...]:
        now = self._clock()
        if now - self._checked_at < self.reload_s:
            return self._experiments
        with self._lock:
            if now - self._checked_at < self.reload_s:
                return self._experiments
            self._checked_at = now
            self._reload_if_changed()
        return self._experiments

    def _reload_if_changed(self) -> None:
        try:
            mtime = self.path.stat().st_mtime
        except OSError:
            if self._mtime is not None:
                logger.warning("experiments file disappeared; experiments disabled")
            self._experiments, self._mtime, self.errors = (), None, ()
            return
        if mtime == self._mtime:
            return
        try:
            import yaml

            document = yaml.safe_load(self.path.read_text(encoding="utf-8"))
        except Exception as exc:  # noqa: BLE001 - a bad edit must not break search: keep the last good copy
            logger.warning("experiments file not loaded (%s); keeping previous experiments", type(exc).__name__)
            self._mtime = mtime
            return
        experiments, errors = parse_experiments(document)
        for message in errors:
            logger.warning("experiments: %s", message)
        running = [e.id for e in experiments if e.running]
        if len(running) > 1:
            logger.warning("experiments: %d running (%s); only the first one is applied", len(running), ", ".join(running))
        self._experiments, self._mtime, self.errors = tuple(experiments), mtime, tuple(errors)
        logger.info("experiments loaded: %d defined, %d running", len(experiments), len(running))

    def assign(self, session_hash: str | None) -> Assignment | None:
        if not config.SEARCH_EXPERIMENTS_ENABLED:
            return None
        return select(self.experiments(), session_hash)

    def wants(self, switch: str) -> bool:
        """True when a running experiment turns ``switch`` (``co_purchase`` / ``ctr_boost``) on for its variant."""

        return any(e.running and getattr(e.overrides, switch, None) for e in self.experiments())
