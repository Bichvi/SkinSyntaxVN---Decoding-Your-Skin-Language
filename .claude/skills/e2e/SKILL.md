---
name: e2e
description: Verify real user-facing browser flows in SkinSyntaxVN after UI, authentication, API integration, or critical-path changes; use when end-to-end validation is needed.
---

# End-to-end testing

Use `exec_command` to discover and run the repository's existing end-to-end harness. Use the configured Playwright, Cypress, Selenium, or equivalent workflow; never claim an E2E pass without executing a real flow.

## Workflow

1. Inspect package manifests, test scripts, browser-test configuration, Docker services, and existing E2E tests before running anything.
2. Start only the services required by the repository's documented workflow. Reuse the configured ports, fixtures, and test accounts; do not expose secrets or create real user data.
3. Run the narrowest relevant test first, then the broader E2E suite when the change affects shared infrastructure or critical paths.
4. Exercise the user-visible path, including navigation, forms, authentication state, API responses, and important error states as applicable.
5. If no E2E harness or browser-test configuration exists, report `NOT RUN` with the exact reason. Do not substitute unit tests and call them E2E.
6. If a test fails, preserve the command, URL/flow, console or network evidence, and the first actionable error. Use `apply_patch` for test or source changes, then rerun the affected flow.

## Test accounts

Seed with `php scripts/e2e/seed_test_accounts.php` (idempotent; upserts only `e2e.customer@skinsyntax.test` and `e2e.admin@skinsyntax.test`, flagged `is_test_account: true`). Credentials live in the git-ignored `.env` as `E2E_CUSTOMER_EMAIL/PASSWORD` and `E2E_ADMIN_EMAIL/PASSWORD`; read them from the environment and never print them. Remove with `php scripts/e2e/remove_test_accounts.php`. Never use real user accounts.

## Reporting

Report the exact command, environment/services used, test scope, and result as `PASS`, `FAIL`, or `NOT RUN`. Separate infrastructure failures from application failures.

