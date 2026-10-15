---
name: lint
description: Run the repository's applicable PHP, Python, JavaScript, and stylesheet quality checks after code changes; use when validating or fixing lint and formatting issues.
---

# Lint

Use `exec_command` to inspect the repository and run the checks that are actually configured. Do not invent a passing result when a tool or configuration is missing.

## Workflow

1. Inspect the changed files and relevant manifests/configuration before choosing commands.
2. Run `git diff --check` for whitespace errors.
3. For changed PHP files, run `php -l` on each applicable file. Use the repository's configured PHP tools if a PHP linter or formatter is present.
4. For changed Python files, use the configured Ruff/Flake8/Black tooling when available. If no linter is installed or configured, report that clearly instead of installing dependencies implicitly.
5. For JavaScript, TypeScript, CSS, and HTML, use package scripts or repository configuration when present. Do not assume a root Node package exists.
6. Keep fixes separate from diagnosis: make source edits only with `apply_patch`, then rerun the failed check.

## Reporting

Report every command run and its result. Distinguish `PASS`, `FAIL`, and `NOT RUN` (missing tool/configuration). Include the file and line information for failures when available.

