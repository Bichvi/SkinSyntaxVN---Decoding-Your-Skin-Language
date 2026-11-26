from __future__ import annotations

import json


def test_dry_run_evaluates_cases_and_writes_report(monkeypatch, tmp_path):
    from scripts import eval_agent_router

    cases = tmp_path / "router-cases.yaml"
    report = tmp_path / "router-eval.json"
    environment = tmp_path / "test.env"
    environment.write_text("", encoding="utf-8")
    # main() edits os.environ directly. Restore the prior test environment on teardown.
    for key in eval_agent_router.SERVICE_ENV_KEYS + tuple(eval_agent_router.ALLOWED_ENV_KEYS):
        monkeypatch.setenv(key, "")
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
                "--env-file",
                str(environment),
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
