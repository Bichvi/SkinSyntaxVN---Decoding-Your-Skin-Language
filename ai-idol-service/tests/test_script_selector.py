import os
import sys
import unittest
from unittest.mock import MagicMock, patch

SERVICE_DIR = os.path.dirname(os.path.dirname(__file__))
if SERVICE_DIR not in sys.path:
    sys.path.insert(0, SERVICE_DIR)

import engines.script_selector as selector
from core.purpose import PRODUCT_VIDEO

SNAP = {"name": "Serum B5 2", "brand": "B", "category": "Serum", "origin": "VN",
        "ingredients": "Niacinamide, Zinc", "description": "Giảm dầu", "usage": "Sáng tối"}
HEADERS = ["Hook", "Problem", "Product Intro", "Specs", "Ingredients", "How to Use", "Wrap-up"]


def script(tag="", skip=(), extra=""):
    parts = []
    for header in HEADERS:
        if header in skip:
            continue
        parts.append(f"[{header}]\nCâu số {tag} cho phần {header.lower()} nhẹ nhàng.{extra}")
    return "\n".join(parts)


def judgement(a_faithful=8, b_faithful=8, winner="A", a_hook=7, b_hook=7):
    return {"a": {"hook": a_hook, "flow": 7, "natural": 7, "faithful": a_faithful},
            "b": {"hook": b_hook, "flow": 7, "natural": 7, "faithful": b_faithful},
            "winner": winner, "reason": "ok"}


class Provider:
    """Factory producing providers with scripted replies; records every instance."""

    def __init__(self, scripts, models=("m0", "m1"), judge=None, fail=()):
        self.scripts, self.models, self.judge, self.fail = list(scripts), list(models), judge, set(fail)
        self.instances, self.calls = [], 0
        self.invoke_count = 0
        self.judge_count = 0

    def __call__(self):
        inst = MagicMock()
        inst.models = list(self.models)
        index = len(self.instances)

        def invoke(system, user):
            self.invoke_count += 1
            if index in self.fail:
                raise RuntimeError("boom")
            return self.scripts[index]

        def invoke_json(system, user, *, schema, schema_name):
            self.judge_count += 1
            if isinstance(self.judge, Exception):
                raise self.judge
            return self.judge

        inst.invoke.side_effect = invoke
        inst.invoke_json_schema.side_effect = invoke_json
        self.instances.append(inst)
        return inst


def run(provider, n=3):
    with patch.object(selector, "LLMProvider", provider):
        return selector.select_best_script("sys", "usr", SNAP, PRODUCT_VIDEO, n=n)


class SelectorTests(unittest.TestCase):
    def test_single_candidate_skips_judge(self):
        provider = Provider([script("a")])
        chosen, report = run(provider, n=1)
        self.assertEqual(chosen, script("a"))
        self.assertEqual(provider.invoke_count, 1)
        self.assertEqual(provider.judge_count, 0)
        self.assertEqual(report["reason"], "single")

    def test_models_rotate_per_candidate(self):
        provider = Provider([script("a"), script("b")])
        with patch.object(selector, "LLMProvider", provider):
            selector.generate_candidates("s", "u", 2)
        self.assertEqual(provider.instances[0].models, ["m0", "m1"])
        self.assertEqual(provider.instances[1].models, ["m1", "m0"])

    def test_broken_candidate_excluded_from_judge(self):
        provider = Provider([script("a", skip=("Specs",)), script("b"), script("c")],
                            judge=judgement(winner="B"))
        chosen, report = run(provider)
        self.assertEqual(provider.judge_count, 1)
        self.assertEqual(report["reason"], "judge")
        self.assertEqual(chosen, script("c"))
        self.assertIn("MISSING_SECTION:specs", report["candidates"][0]["hard"])
        self.assertEqual((report["judgements"][0]["a"], report["judgements"][0]["b"]), (1, 2))

    def test_single_clean_candidate_needs_no_judge(self):
        provider = Provider([script("a", skip=("Hook",)), script("b"), script("c", skip=("Problem",))])
        chosen, report = run(provider)
        self.assertEqual(provider.judge_count, 0)
        self.assertEqual(report["reason"], "only_clean")
        self.assertEqual(chosen, script("b"))

    def test_faithfulness_floor_overrides_model_winner(self):
        provider = Provider([script("a"), script("b")], judge=judgement(a_faithful=4, b_faithful=8, winner="A", a_hook=10))
        chosen, report = run(provider, n=2)
        self.assertEqual(chosen, script("b"))
        self.assertEqual(report["judgements"][0]["winner"], "B")

    def test_higher_total_wins_and_tie_uses_model(self):
        provider = Provider([script("a"), script("b")], judge=judgement(winner="A", b_hook=9))
        self.assertEqual(run(provider, n=2)[0], script("b"))
        provider = Provider([script("a"), script("b")], judge=judgement(winner="B"))
        self.assertEqual(run(provider, n=2)[0], script("b"))

    def test_judge_failure_falls_back_to_soft_penalty(self):
        bad = script("a", extra=" Bạn có biết không, 100 phần trăm.")
        provider = Provider([bad, script("b")], judge=RuntimeError("429"))
        chosen, report = run(provider, n=2)
        self.assertEqual(chosen, script("b"))
        self.assertEqual(report["reason"], "fallback")
        self.assertIn("error", report["judgements"][0])

    def test_generation_failures(self):
        provider = Provider([script("a"), script("b"), script("c")], fail={0}, judge=judgement())
        chosen, _ = run(provider)
        self.assertIn(chosen, (script("b"), script("c")))
        with self.assertRaises(RuntimeError):
            run(Provider(["x", "y", "z"], fail={0, 1, 2}))

    def test_soft_penalty_ignores_digits_in_product_name(self):
        plain = "[Hook]\nSerum B5 2 rất nhẹ.\n[Problem]\nDa dầu."
        self.assertEqual(selector.soft_penalty(plain, SNAP), 0)
        self.assertEqual(selector.soft_penalty(plain + " Dùng 3 lần.", SNAP), 1)
        self.assertEqual(selector.soft_penalty("Tóm lại là chân ái.", SNAP), 2)


if __name__ == "__main__":
    unittest.main()
