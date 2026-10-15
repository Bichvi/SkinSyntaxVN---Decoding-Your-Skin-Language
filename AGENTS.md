<!-- gitnexus:start -->

# GitNexus — Code Intelligence

This project is indexed by GitNexus as **SkinSyntaxVN---Decoding-Your-Skin-Language** (index pending).

> Index stale? Run `node .gitnexus/run.cjs analyze --index-only` from the project root — it auto-selects an available runner. No `.gitnexus/run.cjs` yet? Bootstrap with `npx`, `bunx`, or `pnpm dlx` — e.g. `bunx gitnexus@latest analyze` (npm 11 npx crash; #1939).

## Always Do

- **MUST run impact analysis before editing.** Use `impact({target: "symbolName", direction: "upstream"})` (MCP) or `node .gitnexus/run.cjs impact "symbolName" --direction upstream --repo .` (CLI fallback); report callers, processes, and risk. Never substitute grep for graph analysis.
- **MUST analyze graph changes before committing.** Use `detect_changes({scope: "all"})` (MCP) or `node .gitnexus/run.cjs detect-changes --scope all --repo .` (CLI fallback). `partial: true` or `truncated: true` is not a clean check — a zero means unseen, not unaffected; re-run it. For regression review: `detect_changes({scope: "compare", base_ref: "fix_chatbot_26_8"})` or `node .gitnexus/run.cjs detect-changes --scope compare --base-ref "fix_chatbot_26_8" --repo .`.
- **MUST warn the user** if impact analysis returns HIGH or CRITICAL risk before proceeding with edits.
- **MUST treat `risk: UNKNOWN` as unresolved, not as low.** An empty caller set is not evidence the symbol is unused — it can also mean the callers are not resolvable by the index (plain-object property access, dynamic dispatch, cross-language calls). `impact` pairs `UNKNOWN` with a `riskNote` saying so. Confirm with a text search before treating the symbol as safe to change or delete; do not proceed on the strength of a zero.
- When exploring unfamiliar code, use `query({search_query: "concept"})` to find execution flows instead of grepping. It returns process-grouped results ranked by relevance.
- When you need full context on a specific symbol — callers, callees, which execution flows it participates in — use `context({name: "symbolName"})`.
- For security review, `explain({target: "fileOrSymbol"})` lists taint findings (source→sink flows; needs `analyze --pdg`).

## Never Do

- NEVER edit a function, class, method, route, controller, or shared service before MCP/CLI impact analysis.
- NEVER ignore HIGH or CRITICAL risk warnings from impact analysis, and never read `UNKNOWN` as an all-clear — it means the walk could not answer, which is the one verdict that requires confirming by other means.
- NEVER rename symbols with find-and-replace — use `rename` which understands the call graph.
- NEVER commit before MCP/CLI graph change analysis.

## Resources

| Resource | Use for |
| --- | --- |
| `gitnexus://repos` | List all indexed repositories; read first |
| `gitnexus://repo/SkinSyntaxVN---Decoding-Your-Skin-Language/context` | Codebase overview, check index freshness |
| `gitnexus://repo/SkinSyntaxVN---Decoding-Your-Skin-Language/clusters` | All functional areas |
| `gitnexus://repo/SkinSyntaxVN---Decoding-Your-Skin-Language/processes` | All execution flows |
| `gitnexus://repo/SkinSyntaxVN---Decoding-Your-Skin-Language/process/{name}` | Step-by-step execution trace |

## CLI

| Task | Read this skill file |
| --- | --- |
| Understand architecture / "How does X work?" | `.claude/skills/gitnexus-exploring/SKILL.md` |
| Blast radius / "What breaks if I change X?" | `.claude/skills/gitnexus-impact-analysis/SKILL.md` |
| Trace bugs / "Why is X failing?" | `.claude/skills/gitnexus-debugging/SKILL.md` |
| Rename / extract / split / refactor | `.claude/skills/gitnexus-refactoring/SKILL.md` |
| Tools, resources, schema reference | `.claude/skills/gitnexus-guide/SKILL.md` |
| Index, status, clean, wiki CLI commands | `.claude/skills/gitnexus-cli/SKILL.md` |
| Lint, formatting, or code-quality validation | `.claude/skills/lint/SKILL.md` |
| PHP-rendered frontend or TypeScript tests | `.claude/skills/frontend-testing/SKILL.md` |
| Browser or user-facing end-to-end validation | `.claude/skills/e2e/SKILL.md` |

<!-- gitnexus:end -->

# SkinSyntaxVN Project Guidance

## Project mission and verified stack

SkinSyntaxVN is a skincare e-commerce platform with an AI assistant for personalized skincare advice, product recommendations, routine analysis, and cart ingredient-conflict detection.

- `frontend`: PHP views, HTML/CSS/JavaScript, customer and admin UI.
- `backend`: PHP MVC controllers, models, services, authentication, and BFF/API behavior.
- `ai-service-flask`: Flask, LangChain, RAG, hybrid search, streaming, and chatbot tests.
- `ai-idol-service` and related services: Python AI/voice/avatar functionality with tests.
- Databases/services: MongoDB, ChromaDB, Redis, Nginx, and Docker Compose.
- Package/runtime sources: Composer for PHP and the repository Python environments/requirements for Python services.

Verify the current repository configuration before relying on this summary. If the code and this file disagree, the code, package manifests, Compose files, and current documentation are the source of truth.

## Repository boundaries

- Keep customer UI, PHP backend, AI services, data scripts, and infrastructure changes within their owning layer.
- Do not bypass service boundaries by coupling PHP directly to internal AI implementation details.
- Preserve API, authentication, database, and response-shape compatibility by default.
- Do not expose secrets, tokens, `.env` contents, raw connection strings, or personal data in code, logs, reports, or commits.
- Do not commit generated datasets, uploads, build output, local indexes, or temporary runtime artifacts.

## Required workflow

1. Read this file, relevant `.agents/` rules/workflows, affected service documentation, and `git status` before changing anything.
2. Diagnose the concrete requirement first. For non-trivial work, state scope, acceptance criteria, risks, and verification before editing.
3. Before editing an existing symbol, run GitNexus upstream impact analysis. Use MCP `impact` when available; otherwise use the CLI in the GitNexus section above. Report callers, processes, and risk.
4. Treat `risk: UNKNOWN` as unresolved. Confirm it with targeted search and manual inspection; warn the user before HIGH or CRITICAL risk changes.
5. For a feature, multi-step change, or design with meaningful alternatives, use brainstorming and writing-plans before implementation.
6. Implement the smallest safe change that meets the requirement. Preserve working behavior and avoid unrelated cleanup.
7. Run affected tests, lint, and builds. If an API/service contract changes, add or update an integration or contract test.
   - For lint or formatting work, read and follow `.claude/skills/lint/SKILL.md`.
   - For PHP-rendered frontend or TypeScript tests, read and follow `.claude/skills/frontend-testing/SKILL.md`.
   - For browser or critical user-flow work, read and follow `.claude/skills/e2e/SKILL.md`.
8. For user-facing web behavior, verify the real running application with Playwright when the E2E harness is available. Do not claim E2E PASS from static inspection or a build alone.
9. Review the diff, run `git diff --check`, and record skipped checks or blockers clearly.
10. Before committing, run GitNexus `detect_changes`. Commit only a complete, tested logical milestone.

## Tool discipline

- Use `exec_command` for terminal commands, GitNexus CLI, lint, tests, builds, and E2E.
- Use `apply_patch` for file creation and edits. Do not use shell redirection or ad-hoc scripts to overwrite source files.
- Prefer `rg`/`rg --files` for search; exclude `.git`, `.venv`, `vendor`, `node_modules`, build output, and secrets.
- Never use destructive Git commands or delete broad directories without explicit user authorization.

## Testing and verification

- Discover exact commands from the affected package manifest, Composer configuration, Python configuration, or existing scripts instead of guessing.
- PHP changes: run `php -l` on changed PHP files and relevant Composer tests when configured.
- Python changes: run the relevant `pytest` suite in the project Python environment and applicable static checks.
- JavaScript/CSS changes: run the configured formatter/linter for the affected package when available.
- User-visible behavior changes: run the real E2E/acceptance flow when Playwright/Cypress or an existing harness is installed; otherwise state `E2E NOT RUN` and why.
- Do not claim lint, test, build, E2E, or GitNexus validation passed unless the command/tool actually ran successfully.

## Logging, secrets, and security

- Use existing framework loggers and preserve request/correlation context where supported.
- Never log passwords, API keys, JWT secrets, cookies, raw database URLs, or unnecessary personal data.
- Preserve authorization, validation, rate limits, size limits, and error handling at service boundaries.

## Git and workspace policy

- Preserve existing user changes and avoid unrelated refactors.
- Do not commit, push, deploy, migrate production data, or rewrite history unless explicitly requested.
- Before completion, report changed files, commands/results, skipped checks, risks, and next steps.
