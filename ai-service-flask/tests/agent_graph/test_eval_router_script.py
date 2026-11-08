from __future__ import annotations

import json
from pathlib import Path


def test_dry_run_evaluates_cases_and_writes_report(monkeypatch):
    from scripts import eval_agent_router

    cases = Path("report/chat-agent/_test-router-cases.yaml")
    report = Path("report/chat-agent/_test-router-eval.json")
    cases.parent.mkdir(parents=True, exist_ok=True)
    try:
        cases.write_text(
            '- message: "hello"\n'
            '  pending_state: "none"\n'
            '  expected_labels: [SMALLTALK]\n',
            encoding="utf-8",
        )
        monkeypatch.setattr(
            "sys.argv",
            [
                "eval_agent_router.py",
                "--cases",
                str(cases),
                "--dry-run",
                "--min-acc",
                "1.0",
                "--output",
                str(report),
            ],
        )

        assert eval_agent_router.main() == 0
        payload = json.loads(report.read_text(encoding="utf-8"))
        assert payload["overall_accuracy"] == 1.0
        assert payload["cases"][0]["predicted_labels"] == ["SMALLTALK"]
    finally:
        cases.unlink(missing_ok=True)
        report.unlink(missing_ok=True)
