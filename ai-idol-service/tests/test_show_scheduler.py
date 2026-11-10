import pytest

from core.show_types import ClipInfo
from engines.show_scheduler import build_program


def make_library(n_products, product_seconds=120, missing_b=(), transitions=2, interludes=6):
    clips = [ClipInfo("open", "opening", 90), ClipInfo("recap", "routine_recap", 75),
             ClipInfo("close", "closing", 25)]
    pids = [f"p{i}" for i in range(1, n_products + 1)]
    for pid in pids:
        for angle in ("A", "B"):
            if angle == "B" and pid in missing_b:
                continue
            clips.append(ClipInfo(f"{pid}-{angle}", "product", product_seconds, pid, angle))
    types = ["cta", "about_shop", "fun"]
    for n in range(interludes):
        t = types[n % 3]
        clips.append(ClipInfo(f"int-{t}-{n // 3}", "interlude", 15, interlude_type=t, variant=n // 3))
    for n in range(transitions):
        clips.append(ClipInfo(f"tr{n}", "transition", 25, variant=n))
    for n in range(4):
        clips.append(ClipInfo(f"faq{n}", "faq", 30, product_id=pids[n % len(pids)], variant=n))
    for n in range(2):
        clips.append(ClipInfo(f"tip{n}", "tip", 75, variant=n))
    return clips, pids


def assert_invariants(program, target, pids=None, check_rounds=True):
    assert program[0].kind == "opening"
    assert program[-1].kind == "closing"
    assert sum(1 for p in program if p.kind == "closing") == 1
    for a, b in zip(program, program[1:]):
        assert not (a.kind == "product" and b.kind == "product")
        assert a.segment_id != b.segment_id
        assert abs(a.end - b.start) < 1e-9
    assert program[0].start == 0
    total = program[-1].end
    assert target - 60 <= total <= target + 150, total
    if check_rounds and pids:
        rounds, cur = [], None
        for p in program:
            if p.kind in ("opening", "transition") or (p.kind == "interlude" and cur is not None
                                                       and program[program.index(p) - 1].kind == "routine_recap"):
                cur = []
                rounds.append(cur)
            if p.kind == "product":
                cur.append(p.product_id)
        for rd in rounds[:-1]:
            assert set(pids) <= set(rd)


def kinds(program):
    return [p.kind for p in program]


def test_three_products_30_min():
    clips, pids = make_library(3)
    assert_invariants(build_program(clips, 1800, pids), 1800, pids)


def test_six_products_60_min():
    clips, pids = make_library(6)
    prog = build_program(clips, 3600, pids)
    assert_invariants(prog, 3600, pids)
    assert set(pids) <= {x.product_id for x in prog}


def test_single_product_show():
    clips, pids = make_library(1)
    prog = build_program(clips, 1800, pids)
    assert_invariants(prog, 1800, pids)
    assert "interlude" not in kinds(prog)


def test_interlude_between_every_two_products():
    clips, pids = make_library(4)
    prog = build_program(clips, 1800, pids)
    ks = kinds(prog)
    for i, k in enumerate(ks[:-2]):
        if k == "product" and ks[i + 2] == "product":
            assert ks[i + 1] == "interlude"
        if k == "product" and ks[i + 1] in ("faq", "tip"):
            assert ks[i + 2] != "product"


def test_filler_follows_interlude_after_two_products():
    clips, pids = make_library(4)
    prog = build_program(clips, 1800, pids)
    ks = kinds(prog)
    i = ks.index("product", ks.index("product") + 1)  # second product
    assert ks[i + 1] == "interlude" and ks[i + 2] in ("faq", "tip")


def test_missing_angle_b_uses_a():
    clips, pids = make_library(3, missing_b=("p2",))
    prog = build_program(clips, 3000, pids)
    assert_invariants(prog, 3000, pids)
    assert any(p.segment_id == "p2-A" for p in prog)
    assert not any(p.segment_id == "p2-B" for p in prog)


def test_no_transition_uses_about_shop_interlude():
    clips, pids = make_library(2, transitions=0)
    prog = build_program(clips, 1800, pids)
    assert_invariants(prog, 1800, pids)
    openers = [prog[i + 1] for i, p in enumerate(prog[:-1]) if p.kind == "routine_recap"
               and prog[i + 1].kind != "closing"]
    assert openers and all(o.kind == "interlude" and "about_shop" in o.segment_id for o in openers)


def test_one_interlude_only_never_adjacent_to_itself():
    clips, pids = make_library(3, interludes=1)
    assert_invariants(build_program(clips, 1800, pids), 1800, pids)


def test_angles_alternate_by_round():
    clips, pids = make_library(2)
    prog = build_program(clips, 1800, pids)
    angle_by_round, rnd = {}, 0
    for p in prog:
        if p.kind in ("opening", "transition"):
            rnd += 1
        if p.kind == "product":
            angle_by_round.setdefault(rnd, set()).add(p.angle)
    assert angle_by_round[1] == {"A"}
    assert angle_by_round[2] == {"B"}


def test_interlude_types_rotate():
    clips, pids = make_library(6)
    prog = build_program(clips, 3600, pids)
    ids = [p.segment_id for p in prog if p.kind == "interlude"]
    assert len(set(ids)) >= 6
    for a, b in zip(ids, ids[1:]):
        assert a != b
    assert len({i.split("-")[1] for i in ids}) == 3


def test_target_15_min_short_show():
    clips, pids = make_library(3)
    prog = build_program(clips, 900, pids)
    assert_invariants(prog, 900, pids, check_rounds=False)


def test_impossible_upper_bound_returns_shortest_valid_show():
    # One full round (6 x 120 s) exceeds 900 + 150: cut at a product boundary.
    clips, pids = make_library(6)
    prog = build_program(clips, 900, pids)
    ks = kinds(prog)
    assert ks[0] == "opening" and ks[-1] == "closing" and ks[-2] == "routine_recap"
    assert sum(k == "product" for k in ks) < 6
    assert prog[-1].end >= 900 - 60


def test_ignores_products_outside_order():
    clips, pids = make_library(3)
    prog = build_program(clips, 1800, pids[:2])
    assert "p3" not in {p.product_id for p in prog}


@pytest.mark.parametrize("drop", ["opening", "closing", "routine_recap", "product"])
def test_raises_without_required_clip(drop):
    clips, pids = make_library(2)
    with pytest.raises(ValueError):
        build_program([c for c in clips if c.kind != drop], 1800, pids)


def test_raises_without_closing():
    clips, pids = make_library(2)
    with pytest.raises(ValueError):
        build_program([c for c in clips if c.kind != "closing"], 1800, pids)
