---
name: frontend-testing
description: Test SkinSyntaxVN's PHP-rendered frontend and TypeScript behavior with the repository's configured unit, integration, and browser test runners; use after changing views, forms, client scripts, or frontend-facing PHP endpoints.
---

# Frontend testing

Use `exec_command` to inspect the repository and run the test runner that is actually configured. This project primarily uses PHP-rendered frontend code, with TypeScript/JavaScript behavior where present. Do not install dependencies or claim a passing test without an executed command.

## Choose the test layer

1. Inspect the changed files and relevant configuration first: `composer.json`, `phpunit.xml*`, `pest.php`, `package.json`, `tsconfig.json`, Vitest/Jest configuration, and existing test directories.
2. For PHP views, controllers, form handlers, and frontend-facing endpoints, run the configured PHPUnit or Pest tests. Prefer focused tests for the changed behavior, then the relevant suite.
3. For TypeScript or JavaScript modules, run the configured npm test script with its focused test selector when supported. Use Vitest or Jest only when the repository already configures it.
4. For server-rendered PHP plus browser interaction, use the `e2e` skill and exercise the real page in a browser. Unit tests alone do not prove DOM behavior, asset loading, or client/server integration.
5. If no suitable runner or test configuration exists, report `NOT RUN` and the exact missing tool/configuration. `php -l` checks syntax only; it is not a frontend behavior test.

## What to cover

- Rendered markup, validation messages, escaping, forms, redirects, and HTTP error states in PHP.
- TypeScript event handlers, state transitions, API response handling, loading states, and client-side error states.
- PHP/TS contracts: field names, JSON shape, status codes, CSRF/auth behavior, and selectors used by the UI.
- At least one regression test for each fixed frontend bug when the test structure supports it.

## Rules

- Use `exec_command` to run tests and `apply_patch` for test or source edits.
- Reuse project fixtures, test databases, and test accounts; never use production credentials or real user data.
- Do not substitute lint, type-checking, or unit tests for an explicitly requested browser E2E test.
- Report exact commands and classify each result as `PASS`, `FAIL`, or `NOT RUN`; separate infrastructure failures from application failures.

