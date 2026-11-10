"""Pure show scheduler: turns READY clips into a routine-ordered program."""
from core.show_types import ClipInfo, ProgramItem

LOW_SLACK = 60.0
MAX_ROUNDS = 200


class _Rotor:
    """Deterministic rotation: use every clip once before repeating."""

    def __init__(self, clips):
        self.clips = sorted(clips, key=lambda c: (c.variant, c.interlude_type or "", c.segment_id))
        self.used = set()

    def pick(self, prev=None, prefer=None, accept=None):
        pool = [c for c in self.clips if accept is None or accept(c)]
        if not pool:
            return None
        fresh = [c for c in pool if c.segment_id not in self.used]
        if not fresh:
            self.used -= {c.segment_id for c in pool}
            fresh = pool
        steps = []
        if prefer:
            steps.append(prefer)
        if prev is not None:
            steps.append(lambda c: c.segment_id != prev.segment_id)
            steps.append(lambda c: not (c.interlude_type and c.interlude_type == prev.interlude_type))
        cands = fresh
        for ok in steps:
            narrowed = [c for c in cands if ok(c)]
            if narrowed:
                cands = narrowed
        # Never repeat the previous clip when the pool has another one.
        if prev is not None and cands[0].segment_id == prev.segment_id:
            cands = [c for c in pool if c.segment_id != prev.segment_id] or cands
        chosen = cands[0]
        self.used.add(chosen.segment_id)
        return chosen


def build_program(clips: list[ClipInfo], target_seconds: float, product_order: list[str]) -> list[ProgramItem]:
    """Build the show timeline (spec 6.1).

    Round 1 starts with the opening, later rounds with a transition (else an
    about_shop interlude, else any interlude, else nothing). Products follow
    product_order, with an interlude between every two products and a faq/tip
    filler after every 2 products (after the interlude, never replacing it).
    Each round ends with routine_recap. Rounds are added until
    total + recap + closing >= target - 60 s; the last round stops early at a
    product boundary.

    Known limit: when a single full round is already longer than
    target + 150 s (e.g. 15 min with 6 x 120 s products) the upper bound cannot
    be met. The result is then the shortest valid show: opening, a partial
    round cut at a product boundary, routine_recap and closing.
    """
    by_kind: dict[str, list[ClipInfo]] = {}
    for c in clips:
        by_kind.setdefault(c.kind, []).append(c)
    if not by_kind.get("opening"):
        raise ValueError("Thiếu clip opening")
    if not by_kind.get("closing"):
        raise ValueError("Thiếu clip closing")
    if not by_kind.get("routine_recap"):
        raise ValueError("Thiếu clip routine_recap")

    products: dict[str, dict[str, ClipInfo]] = {}
    for c in sorted(by_kind.get("product", []), key=lambda c: (c.angle or "A", c.segment_id)):
        if c.product_id in product_order:
            products.setdefault(c.product_id, {}).setdefault(c.angle or "A", c)
    order = [pid for pid in product_order if pid in products]
    if not order:
        raise ValueError("Thiếu clip sản phẩm")

    transitions = _Rotor(by_kind.get("transition", []))
    interludes = _Rotor(by_kind.get("interlude", []))
    faqs = _Rotor(by_kind.get("faq", []))
    tips = _Rotor(by_kind.get("tip", []))
    recaps = _Rotor(by_kind["routine_recap"])
    closing = by_kind["closing"][0]
    low = target_seconds - LOW_SLACK

    seq: list[ClipInfo] = []
    total = 0.0

    def add(c):
        nonlocal total
        seq.append(c)
        total += c.duration

    def prev():
        return seq[-1] if seq else None

    add(by_kind["opening"][0])
    filler_n = 0
    finished = False
    for r in range(1, MAX_ROUNDS + 1):
        if r > 1:
            opener = transitions.pick(prev())
            if opener is None:
                opener = (interludes.pick(prev(), accept=lambda c: c.interlude_type == "about_shop")
                          or interludes.pick(prev()))
            if opener is not None:
                add(opener)
        first_angle, second_angle = ("A", "B") if r % 2 == 1 else ("B", "A")
        recap_len = max(c.duration for c in recaps.clips)
        for i, pid in enumerate(order):
            angles = products[pid]
            add(angles.get(first_angle) or angles.get(second_angle) or next(iter(angles.values())))
            if total + recap_len + closing.duration >= low:
                finished = True
                break
            if i < len(order) - 1:
                it = interludes.pick(prev())
                if it is not None:
                    add(it)
            if (i + 1) % 2 == 0:
                recent = set(order[max(0, i - 1):i + 1])
                first, second = (faqs, tips) if filler_n % 2 == 0 else (tips, faqs)
                filler = (first.pick(prev(), prefer=lambda c: c.product_id in recent)
                          or second.pick(prev(), prefer=lambda c: c.product_id in recent))
                if filler is not None:
                    add(filler)
                    filler_n += 1
        add(recaps.pick(prev()))
        if finished or total + closing.duration >= low:
            break
    add(closing)

    program: list[ProgramItem] = []
    t = 0.0
    for c in seq:
        program.append(ProgramItem(c.segment_id, c.kind, c.product_id, c.angle, t, t + c.duration))
        t += c.duration
    return program
