# Technology Inventory & Implementation Traceability — Plan

> **Revision 3 (2026-10-21, retry 2): overrides Revision 2 where they differ.**
> - Docker Desktop IS running now: read-only `docker exec … pip freeze/php -m/ffmpeg -version` and read-only Mongo/Chroma/Redis introspection are allowed (rules in the brief).
> - 6 Sonnet audit areas instead of 4: WEB, CHAT (prefix AI), RCM, IDOL-PIPE (prefix IDOL), IDOL-AGENT (prefix AGT), INF. Shared rules + exact draft format: `side-session-notes/<parent>/tech-inventory-drafts/BRIEF.md` (outside the repo); drafts are written next to it, not in `docs/tech-inventory/drafts/`.
> - Output `update_technology_inventory.md` is assembled by a script (matrix grouped by category, profiles/traceability/quick-ref per area, change history sorted by date) plus hand-written sections by the main session; top-level sections are numbered I–XI to match the master prompt.

> **Status 2026-10-22:** the inventory was completed by the 6-area run above and lives in `update_technology_inventory.md` (2026-10-21 15:14). A parallel 4-area retry from another session was stopped and its skeleton drafts removed. Follow-up work = incremental updates per MASTER_PROMPT §X (see Revision 4 below).
>
> **Revision 4 (2026-10-22): incremental update.** Bring `update_technology_inventory.md` up to date with commits `e42f4ac..42a3dfc` (Neo4j service/schema/`graph_sync` ETL, `search_service` recommend API mode B, gunicorn gthread warm-up, `search_events` + metrics, PHP fallback search services, per-token accent-insensitive catalog search, opcache ini, `AfterResponse`, lazy images) and relevant uncommitted changes. Edit only the affected sections, append Change History rows, and keep everything else as is. Sources: the commits, `update_smart_search_neo4j.md`, `update_neo4j_search_recommendation.md`.

> **Revision 2 (2026-10-17, retry): these overrides win over anything below.**
> - **Target file:** the full inventory goes to a NEW file `update_technology_inventory.md` at the repo root (user's choice: separate files are named `update_<task>.md`). Its top-level sections are numbered 0–10 (replacing "16.0–16.10" below). `update.md` only gets one short pointer section appended at the very end; sections 1–15 and "Sửa sau bàn giao" stay byte-identical.
> - **Requirements text:** `docs/tech-inventory/MASTER_PROMPT.md`. Every agent reads it first.
> - **Docker Desktop is NOT running.** Skip every `docker ...` command. Versions come from lockfiles / pinned requirements / `package.json` / local venv (`.venv/Scripts/python.exe -m pip show <pkg>`, Python 3.12.5; it has no celery). Runtime-only facts (live Mongo indexes, container versions) become `UNKNOWN (Docker tắt lúc kiểm kê 2026-10-17)` unless documented with a date in `update.md`, `git show 8a4ba28~1:update.md`, or code (e.g. `create_index(...)` calls are code evidence).
> - **Do not touch other in-progress work:** the uncommitted files `ai-idol-service/agents/{command_query,query_sources,service,catalog_constraints}.py`, `ai-idol-service/tests/test_catalog_constraints.py` and `docs/plans/2026-10-16-catalog-attribute-constraints.md` belong to another task. Read them only; describe them as "đang làm dở, chưa commit".
> - Allowed offline test (optional, safe, uses FakeDb): `cd ai-idol-service && ../.venv/Scripts/python.exe -m pytest tests -q -p no:cacheprovider` (tests importing celery fail with ModuleNotFoundError; that failure is known and must be recorded as such, not as an app bug). JS: `node --test frontend/public/assets/js/tests/*.test.js`.

**Goal:** Append to `update.md` (same file as `UPDATE.md` on Windows) a new top-level section **"16. TECHNOLOGY INVENTORY & IMPLEMENTATION TRACEABILITY"** covering every technology really used in the repo, with code evidence and status. The user's full requirements are in the "MASTER PROMPT" (sections I–XII) that the main session forwards to every agent. This plan fixes how the work is split, the shared format and the safety rules.

**Workflow:** 4 read-only audit agents (Sonnet) work in parallel. Each writes ONE draft file `docs/tech-inventory/drafts/<AREA>.md`. Then a merge agent (Sonnet) builds section 16 in `update.md` from the 4 drafts. The main session (Opus) reviews and spot-checks.

## Hard rules (all agents)

- **Read-only on code and data.** Do not edit any file except your own draft (the merge agent edits only `update.md` and may delete `docs/tech-inventory/drafts/` at the end only if told). No refactors. No DB writes. No commits. No container restarts or rebuilds.
- **NEVER run or loop over scripts in `backend/tests/`.** Many of them write to the production MongoDB Atlas. The only runnable tests are the ones named under "Allowed commands" below.
- **No secrets:** list env var *names* only. Never print `.env` values, URIs, keys, tokens or passwords (mask like `mongodb+srv://***@…`). Do not copy the test-account passwords.
- **Evidence or nothing:** every status claim cites `path:line`, `path::Class.method`, a commit hash, or a dated test record. If evidence is missing, write `UNKNOWN` / "chưa xác minh". Never invent versions, benchmarks or test results.
- **Status taxonomy (exact):** INSTALLED, REFERENCED, IMPLEMENTED, INTEGRATED, VERIFIED, PARTIAL, PLANNED, DEPRECATED, UNKNOWN. A dependency in requirements.txt alone ⇒ INSTALLED at most. VERIFIED needs a dated run/test record: the current `update.md`, the older detailed log `git show 8a4ba28~1:update.md` (sections 1–34, until 15/10/2026), test output you ran yourself today, or runtime evidence.
- **Versions:** take them from lockfiles (`composer.lock`, `package-lock.json`, …), pinned requirements, or the running container (`docker exec <ctr> pip show <pkg>` or `pip freeze`, read-only). Say which source you used.
- **Language:** Vietnamese prose; identifiers, paths and statuses in English. No source dumps; at most 3-line snippets when truly needed.
- **Size budget per draft:** about 900–1,400 lines. Full 15-point profiles only for important technologies. Minor libraries appear only as matrix rows.
- **Allowed commands:** `git log/show/grep/ls-files`; `rg`/grep; reading files; `docker ps`; `docker exec <ctr> pip show|pip freeze|python -c "import x; print(x.__version__)"`; `docker exec skinsyntax-php php -m`; read-only Mongo introspection inside `skinsyntax-ai-idol`: `list_collection_names()`, `index_information()`, `estimated_document_count()`, one `find_one` with projection excluding personal fields. Optional safe tests: ai-idol-service offline unittest in a temp copy (`tar ai-idol-service | docker exec -i skinsyntax-ai-idol sh -c 'mkdir -p /tmp/ais_inv && tar -xf - -C /tmp/ais_inv --strip-components=1'`, then `python -m unittest discover -s tests -q`, then remove `/tmp/ais_inv`); `docker exec -w /var/www/html skinsyntax-php php tests/order_tabs_test.php`; `/c/xampp/php/php.exe backend/tests/admin_usecase_contract_test.php` (static). Do NOT run tests that call paid LLM/TTS APIs. In Git Bash prefix docker commands with `export MSYS_NO_PATHCONV=1;`.

## Draft file format (each area)

```
# <AREA> draft
## A. Matrix rows
| ID | Technology | Category | Version | Purpose | Module | Status | Evidence |
## B. Detailed profiles      (TECH-<PREFIX>-NN, the 15 numbered subsections of MASTER PROMPT §III)
## C. Deep dives             (only the MASTER PROMPT §IV subsections assigned below)
## D. Feature traceability rows
| Feature | Technology (in execution order) | Implementation File | Database | Input | Output | Status |
## E. Dependency edges for the global Mermaid graph
   one line each: `NodeA -->|data| NodeB` + failure-propagation notes
## F. Change history rows    (from git log of the area: Change ID, Date, Technology, Previous → New, Reason, Files, Deps, DB, API, Perf, Compat, Tests, Rollback, Commit, Status — group minor commits)
## G. Roadmap / status checks (assigned techs: real status + evidence of absence or presence)
## H. Quick reference entries (MASTER PROMPT §IX bullet list per main technology)
## I. Open questions / inconsistencies found (code vs docs)
```

ID prefixes: WEB (area A), AI (B), IDOL (C), INF (D).

## Areas

**A — PHP web app & clients (prefix WEB):** `backend/` (MVC controllers/models/services, auth/roles `frontend/views/helpers.php::route_access_map`, sessions, `ProductEventTracker`, `OrderTabs`, PHP recommenders `ContentBasedRecommender`/`GoiYContentBased`/Simple WR in `SanPham`, `RecommendationClient`, mail/OTP/OAuth, payments QR/COD, vouchers, loyalty, inventory `InventoryBatch`, admin), `frontend/` (views, JS incl. `live-filter.js`, `live-search.js`, `smart-search.js`, `ai-idol-studio.js`, CSS/Bootstrap, mascot 3D packages under `frontend/public/ai-idol-mascot-*`), root `index.php`, `composer.json/.lock` (both root and backend), `mobile/` (identify framework), `nginx/`, `public/`. Deep dive: §4.7 (PHP-side recommendation).

**B — AI chatbot & recommendation service (prefix AI):** `ai-service-flask/` (chatbot_service: Flask app, LangChain usage, LLM providers/models + failover/key rotation, intent router, skin-profile state machine, RAG/retrieval/hybrid search, ChromaDB client and collections, embedding model, rerank if any, chat history in Mongo, streaming), `recommendation/`, `recommendation_service/`, the requirements files, `Dockerfile.ai`, and the audit docs at repo root (`langchain_recommendation_audit.md`, `recommendation_evaluation_plan.md`, `goiy_audit.md`). Deep dives: §4.1 LLM, §4.2 Embeddings & Vector Search, §4.4 Hybrid Search, §4.7 Recommendation (Flask side), §4.5 if the chatbot uses an agent/tool loop.

**C — AI Idol / Syna (prefix IDOL):** `ai-idol-service/` (Flask `app.py`, Celery `celery_app.py`/`tasks.py` with queues, beat, `core/` pipeline, state machines, script recovery, pricing, product_metrics, `agents/` intent classifier/parser/command_query/product_resolver/service, `engines/` voice/avatar/broadcast/syna, `providers/` TTS, FFmpeg/audio mixing, storage, publishing, `fixtures/prompt_builder`), `ai-idol-agent-service/` (identify framework, e.g. CrewAI), `ai-idol-avatar-service/`, `Dockerfile.ai-idol*`, `backend/app/routes/ai_idol.php` boundary, `e2e/`. Deep dives: §4.1 LLM (AI Idol side), §4.5 Agent orchestration, §4.8 AI video production (input/output/file/status/dependency per step).

**D — Infrastructure, data, voice & misc (prefix INF):** `docker-compose.yml` (every service, image, ports, volumes, healthchecks, env var names), all Dockerfiles not covered by C, `docker/` (incl. `php/zz-skinsyntax-fpm.conf`), `nginx/nginx.conf`, MongoDB (Atlas vs local container, collections, indexes; read-only introspection), Redis, ChromaDB server, `voice-service/`, `livekit-voice-agent/`, `spiders/` (crawler framework), `scripts/`, `database/`, `services/`, `e2e/` harness, testing tools overall, logging/monitoring/tracing, CI/CD presence, `mcp.json`, `.gitnexus`. Deep dives: §4.3 GraphRAG / Neo4j (status check), §4.6 Constraint optimization (status check). Section G MUST check the real status of: LangGraph, Multi-Agent Architecture, GraphRAG, ChromaDB, Neo4j, OR-Tools CP-SAT, Reciprocal Rank Fusion, Neural Reranking, Langfuse, OpenTelemetry, Contextual Bandit, LLM Structured Output, Product Knowledge Graph, Autonomous Decision Engine. Search the whole repo with rg, excluding vendor, node_modules, .venv, .runtime and spiders data.

## Merge step (after all 4 drafts exist)

Build `## 16. TECHNOLOGY INVENTORY & IMPLEMENTATION TRACEABILITY` at the end of `update.md` with the subsections below. Keep the existing sections 1–15 and "Sửa sau bàn giao" untouched.

- **16.0** Phạm vi, nguồn bằng chứng, chú giải trạng thái, ngày kiểm kê. The older detailed log lives at `git show 8a4ba28~1:update.md`.
- **16.1** Technology Matrix: grouped A. Frontend, B. Backend, C. Databases, D. AI, E. Multimedia, F. Infrastructure, plus "Khác". Dedupe across areas.
- **16.2** Detailed profiles.
- **16.3** AI deep dives §4.1–4.8, in order; techs that are absent are stated explicitly.
- **16.4** Technology Dependency Graph: one Mermaid `flowchart LR`, built from all the E-sections, with failure-propagation notes.
- **16.5** Technology-to-Feature traceability.
- **16.6** Technology Change History.
- **16.7** Roadmap, with the 14 named techs and their real status.
- **16.8** "TECHNOLOGY QUICK REFERENCE FOR NEXT AI AGENT", including explicit answers to the 4 sample questions in MASTER PROMPT §IX.
- **16.9** Automatic documentation update rule (MASTER PROMPT §X, adapted).
- **16.10** Quality-gate checklist with results, plus open inconsistencies.

The merge agent then runs a secrets scan on the new section: `rg -n "(mongodb\+srv://[^*]|sk-[A-Za-z0-9]{20}|AIza[0-9A-Za-z_-]{30}|gsk_[A-Za-z0-9]{20}|password\s*[:=]\s*\S)" update.md`. It must find nothing. It also checks that the Mermaid block is syntactically balanced and that existing sections 1–15 are byte-identical to before (compare `git diff update.md` hunks: additions only).
