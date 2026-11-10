"""Plain data types shared by the live clip-library show code."""
from dataclasses import dataclass


@dataclass(frozen=True)
class ClipInfo:
    segment_id: str
    kind: str
    duration: float
    product_id: str | None = None
    angle: str | None = None
    interlude_type: str | None = None
    variant: int = 0


@dataclass(frozen=True)
class ProgramItem:
    segment_id: str
    kind: str
    product_id: str | None
    angle: str | None
    start: float
    end: float


@dataclass
class ClipDraft:
    kind: str
    script: str
    product_id: str | None = None
    angle: str | None = None
    interlude_type: str | None = None
    variant: int = 0
