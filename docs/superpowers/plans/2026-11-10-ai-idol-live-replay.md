# AI Idol Live Replay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finished internal Syna live shows are uploaded to R2 and listed on `?r=live` as "Xem lại" sessions playable with the existing replay player.

**Architecture:** A new `engines/replay_publisher.py` uploads a finished campaign's master video + poster to R2 and stamps `replay.*` on `ai_idol_campaigns`. The master file is deleted only by one gate, `media_publisher.release_master_video`, once both the replay and any product-page clips are on R2. PHP maps campaigns with `replay.video_url` into the existing `$liveSessions` shape (`status = replay_ready`), so `live.php` needs only a one-line JS guard.

**Tech Stack:** Python 3 / Celery / boto3 (R2) / pymongo, `tests.fakes.FakeDb` + unittest; PHP 8 MVC + MongoDB driver; Playwright (MCP) for E2E.

**Spec:** `docs/superpowers/specs/2026-11-10-ai-idol-live-replay-design.md`

## Global Constraints

- R2 key prefix `live-replays`; keys `live-replays/{campaign_id}-{secrets.token_hex(6)}.mp4` and `.jpg`; `CacheControl` = `media_publisher.CACHE_CONTROL`; ContentType `video/mp4` / `image/jpeg`.
- Campaign field `replay: {video_url, poster_url, keys, duration, uploaded_at, started_at, attempts, last_error}`.
- Retry cap = `media_publisher.MAX_ATTEMPTS` (5). Claim stale after 30 minutes.
- Copy (exact): `"Chưa cấu hình Cloudflare R2 nên chưa có video xem lại."`, `"Không còn file video gốc để lưu bản xem lại."`.
- PHP session id prefix `ai-`; streamer `'Syna · AI Idol'`; status `'replay_ready'`; list limit 30, sorted `completed_at` desc.
- Keep replays forever (no cleanup code). Do not touch `findAiIdolLive`, `streamAiIdolLiveVideo`, the live Syna block, or human `phien_live` behaviour.
- Python tests: run from `ai-idol-service` with `..\.venv\Scripts\python.exe -m pytest <files> -q`; `tests/conftest.py` blocks real Mongo; never call real R2/HTTP. Do **not** run other scripts in `backend/tests` (they write the real DB); the single new pure PHP test is fine.
- Never log/print R2 keys, `MONGO_URI` or `.env` contents. All R2 errors go through `media_publisher._scrub`.
- No `git commit` unless the user asks; before any commit run GitNexus `detect-changes` (AGENTS.md).

## Review Focus

1. Legacy campaign with no `purpose` and no `platforms` (the 3 backfill targets) → treated as `live_only` + `internal`, gets a replay. Test: Task 2 `test_backfill_includes_legacy_campaign_without_purpose_or_platforms`.
2. Second worker / retry beat while an upload is running, and a claim older than 30 min (crashed worker) → first is skipped, stale one is re-claimed. Tests: Task 2 `test_fresh_claim_blocks_second_worker`, `test_stale_claim_is_reclaimed`.
3. Replay and product-clip tasks finishing in either order → master survives until both are done. Tests: Task 1 `test_release_keeps_master_until_replay_uploaded`, `test_release_keeps_master_while_clip_pending`.
4. A non-http `replay.video_url` / `poster_url` in the DB (e.g. `javascript:`) → card skipped / poster ignored. Test: Task 4 `rejects non-http video url`, `ignores non-http poster`.
5. Campaign with empty `product_ids` → card still renders with no pinned product. Test: Task 4 `no product yields null pinned and empty thumbnail`.

---

### Task 0: GitNexus impact analysis (no code)

- [ ] **Step 1:** From the repo root run, for each symbol, `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`: `publish_campaign_videos`, `finalize_live_campaigns`, `broadcast_campaign`, `_queue_product_publish`, `retry_product_video_publish_task`, `index` (LiveController). If the index is stale, run `node .gitnexus/run.cjs analyze --index-only` first.
- [ ] **Step 2:** Report callers / processes / risk. HIGH or CRITICAL → stop and warn the user. `UNKNOWN` → confirm with `rg` (e.g. `rg -n "publish_campaign_videos|_queue_product_publish" ai-idol-service`) before proceeding.

---

### Task 1: `campaign_wants_replay` + single master-release gate

**Files:**
- Modify: `ai-idol-service/core/purpose.py` (append function)
- Modify: `ai-idol-service/engines/media_publisher.py` (`publish_campaign_videos`, ~line 172-193; add `release_master_video`)
- Test: `ai-idol-service/tests/test_media_publisher.py`

**Interfaces:**
- Produces: `core.purpose.campaign_wants_replay(campaign: dict | None) -> bool` (body exactly as spec §4.1).
- Produces: `engines.media_publisher.release_master_video(campaign_id: str) -> bool` — True when the file was removed.

- [ ] **Step 1: Write failing tests** in `test_media_publisher.py` (class `PublisherTestCase` helpers `self.file`, `self.video`, `self.db`):
  - `test_campaign_wants_replay`: `campaign_wants_replay({"purpose": "live_only"})` True; `({"purpose": "live_and_product", "platforms": ["internal", "youtube"]})` True; `({"purpose": "live_only", "platforms": ["youtube"]})` False; `({"purpose": "product_video"})` False; `({})` True (legacy → live_only, default internal); `(None)` True.
  - `test_release_keeps_master_until_replay_uploaded`: campaign `{"status": "COMPLETED", "purpose": "live_only", "master_video_path": master}` → `release_master_video(id)` returns False, file exists; after `$set replay.uploaded_at = NOW` → returns True, file gone, `master_video_path` value unchanged in DB.
  - `test_release_keeps_master_while_clip_pending`: `live_and_product`, `replay.uploaded_at = NOW`, one video with `product_page_file_path` set and no `product_page_ready_at` → False, file exists; set `product_page_ready_at` → True, file gone.
  - `test_release_drops_master_when_no_replay_needed`: `live_and_product`, `platforms: ["youtube"]`, all clips ready → True.
  - Rewrite `test_completed_both_mode_campaign_publishes_bodies_then_drops_full_media` → rename `test_completed_both_mode_campaign_keeps_master_until_replay`: same setup; assert `full` and `body` removed, `master` **still exists**. Add sibling `test_completed_both_mode_campaign_with_replay_drops_master`: same but campaign has `"replay": {"uploaded_at": NOW}` → all three removed.
- [ ] **Step 2:** Run `..\.venv\Scripts\python.exe -m pytest tests/test_media_publisher.py -q`. Expected: FAIL (ImportError / master still deleted).
- [ ] **Step 3:** Add `campaign_wants_replay` to `core/purpose.py`. Add `release_master_video` to `media_publisher.py`: re-read campaign by `object_id`; missing campaign or empty/absent file → False; apply the two conditions of spec §4.3 (pending clip = any `ai_idol_videos` with `campaign_id`, `product_page_file_path != None` and no `product_page_ready_at`); then `_remove(path)` and return True. In `publish_campaign_videos` replace `_remove(campaign.get("master_video_path"))` with `release_master_video(campaign_id)`; keep the per-clip `file_path` removal.
- [ ] **Step 4:** Run the same command. Expected: PASS (whole file).

---

### Task 2: `engines/replay_publisher.py`

**Files:**
- Create: `ai-idol-service/engines/replay_publisher.py`
- Test: `ai-idol-service/tests/test_replay_publisher.py`

**Interfaces:**
- Consumes: `campaign_wants_replay` (Task 1), `media_publisher.release_master_video` (Task 1), `media_publisher._r2, r2_configured, _default_client, _scrub, make_poster, _remove, MAX_ATTEMPTS, CACHE_CONTROL`, `program_composer.probe_duration`, `core.state_machine.get_db, object_id, utcnow`.
- Produces:
  - `KEY_PREFIX = "live-replays"`, `CLAIM_STALE = dt.timedelta(minutes=30)`
  - `replay_publishable(campaign: dict) -> bool`
  - `publish_campaign_replay(campaign_id: str, *, client=None) -> bool`
  - `retry_pending_replays(limit: int = 5, *, client=None) -> int`
  - `backfill(apply: bool, *, client=None) -> list[dict]` (rows `{"campaign_id", "name", "master", "published"}`)
  - `main(argv=None) -> int`, `if __name__ == "__main__": raise SystemExit(main())`

Import helpers as module attributes (`from engines.media_publisher import make_poster, ...`) so tests patch `replay_publisher.make_poster`, `replay_publisher.probe_duration`, `replay_publisher.get_db`; also patch `media_publisher.get_db` (used by `release_master_video`).

- [ ] **Step 1: Write failing tests** (copy `R2`, `NOW`, `FakeS3` pattern from `test_media_publisher.py`; `setUp` patches the four targets above with one `FakeDb`; helper `campaign(**fields)` inserts `{"_id": ObjectId(), "status": "COMPLETED", "purpose": "live_only", "platforms": ["internal"], "name": "Live 15/10", "completed_at": NOW, "master_video_path": <temp file>, **fields}`):
  - `test_publish_uploads_video_and_poster_and_releases_master`: returns True; `FakeS3.uploads` has 2 entries, keys match `^live-replays/<id>-[0-9a-f]{12}\.(mp4|jpg)$`, ExtraArgs ContentType `video/mp4` / `image/jpeg`; stored `replay.video_url == "https://pub-test.r2.dev/live-replays/...mp4"` (no double slash), `poster_url` ends `.jpg`, `duration == 30.0`, `uploaded_at` set, `last_error is None`, no `started_at`; master file removed; local poster removed.
  - `test_publish_is_idempotent`: second call returns True with zero new uploads.
  - `test_unconfigured_r2_records_message_without_attempt`: no R2 env → False; `replay.last_error == "Chưa cấu hình Cloudflare R2 nên chưa có video xem lại."`; `replay.attempts` absent/0.
  - `test_missing_master_is_not_retried`: master path deleted beforehand → False; `last_error == "Không còn file video gốc để lưu bản xem lại."`; `replay.attempts == 5`.
  - `test_fresh_claim_blocks_second_worker`: `replay.started_at = NOW - 5 min` (patch `replay_publisher.utcnow` → NOW) → False, zero uploads.
  - `test_stale_claim_is_reclaimed`: `replay.started_at = NOW - 31 min` → True.
  - `test_upload_failure_counts_attempt_and_scrubs_secret`: `FakeS3(fail="boom AKIDSECRETVALUE")` → False; `attempts == 1`; `"AKIDSECRETVALUE" not in last_error`; `started_at` removed; master still exists.
  - `test_not_publishable`: `purpose="product_video"` → False; `platforms=["youtube"]` → False; `status="LIVE"` → False; `status="PARTIAL_FAILURE"` without an internal COMPLETED row in `ai_idol_broadcasts` → False, with `{"campaign_id": str(id), "platform": "internal", "status": "COMPLETED"}` → True.
  - `test_retry_skips_exhausted_and_done`: three campaigns (attempts 5 / `replay.uploaded_at` set / fresh) → `retry_pending_replays()` returns 1.
  - `test_backfill_includes_legacy_campaign_without_purpose_or_platforms`: campaign with `purpose` and `platforms` keys removed → `backfill(apply=False)` lists it with `published False` and no uploads; `backfill(apply=True, client=FakeS3())` → `published True`.
  - `test_main_apply_without_r2_returns_2`.
- [ ] **Step 2:** Run `..\.venv\Scripts\python.exe -m pytest tests/test_replay_publisher.py -q`. Expected: FAIL (ModuleNotFoundError).
- [ ] **Step 3:** Implement per spec §4.2 steps 1-7. Notes the spec leaves open:
  - Claim = `update_one({"_id": oid, "replay.uploaded_at": {"$exists": False}, "$or": [{"replay.started_at": {"$exists": False}}, {"replay.started_at": {"$lt": now - CLAIM_STALE}}]}, {"$set": {"replay.started_at": now, "updated_at": now}})`; `modified_count == 0` → return False.
  - Poster path `os.path.splitext(master)[0] + ".replay-poster.jpg"`; `_remove` it in both success and failure paths.
  - Public URL `f"{base.rstrip('/')}/{key}.mp4"`.
  - On success `$unset {"replay.started_at": ""}` in the same update as the `$set`.
  - `retry_pending_replays` / `backfill` query `{"status": {"$in": ["COMPLETED", "PARTIAL_FAILURE"]}, "master_video_path": {"$ne": None}, "replay.uploaded_at": {"$exists": False}}`, then filter in Python: `int((c.get("replay") or {}).get("attempts") or 0) < MAX_ATTEMPTS`, `replay_publishable(c)`, and (backfill only) `os.path.isfile(master)`.
  - Warn once when R2 is unconfigured (module flag `_warned_unconfigured`, like `publish_video`).
- [ ] **Step 4:** Run the same command. Expected: PASS. Then run `tests/test_media_publisher.py` again. Expected: PASS.

---

### Task 3: Queue on show end, Celery task, retry beat

**Files:**
- Modify: `ai-idol-service/engines/broadcast_engine.py` (next to `_send_publish_task` / `_queue_product_publish`, ~line 105-118; both call sites ~line 157 and ~line 199)
- Modify: `ai-idol-service/tasks.py` (~line 167-174)
- Modify: `ai-idol-service/celery_app.py` (`TASK_ROUTES`, ~line 20)
- Test: `ai-idol-service/tests/test_broadcast_lifecycle.py`

**Interfaces:**
- Consumes: `campaign_wants_replay` (Task 1); `publish_campaign_replay`, `retry_pending_replays` (Task 2).
- Produces: `broadcast_engine._send_replay_task(campaign_id: str) -> None` (sends `"ai_idol.publish_campaign_replay"` by name, lazy `from celery_app import celery_app`), `broadcast_engine._queue_replay_publish(campaign: dict) -> None`; Celery task `ai_idol.publish_campaign_replay(campaign_id)` on queue `media`; `retry_product_video_publish_task()` now returns `{"clips": int, "replays": int}`.

- [ ] **Step 1: Write failing tests** in `test_broadcast_lifecycle.py`:
  - `test_finalize_queues_replay_for_internal_live_campaign`: campaign `{"status": "LIVE", "purpose": "live_only", "platforms": ["internal"], "ends_at": NOW - 1s}`; patch `broadcast_engine._send_replay_task` → after `finalize_live_campaigns(NOW)` it was called once with `str(_id)`.
  - `test_queue_replay_skips_non_internal`: `_queue_replay_publish({"_id": oid(), "purpose": "live_only", "platforms": ["youtube"]})` → not called.
  - `test_queue_replay_swallows_send_errors`: `_send_replay_task` raises `RuntimeError` → `_queue_replay_publish` does not raise.
  - `test_replay_task_routed_to_media`: `celery_app.TASK_ROUTES["ai_idol.publish_campaign_replay"] == {"queue": "media"}`.
  - **Existing tests:** every test class that calls `finalize_live_campaigns` or `broadcast_campaign` (in `test_broadcast_lifecycle.py` and `test_broadcast.py`) must patch `broadcast_engine._send_replay_task` in `setUp` so no test reaches a real broker.
- [ ] **Step 2:** Run `..\.venv\Scripts\python.exe -m pytest tests/test_broadcast_lifecycle.py tests/test_broadcast.py -q`. Expected: FAIL (AttributeError `_send_replay_task`).
- [ ] **Step 3:** Implement: `_queue_replay_publish` mirrors `_queue_product_publish` (guard `campaign_wants_replay`, `try/except Exception` → `logger.warning("[BROADCAST] Could not queue replay publish for %s: %s", ...)`); call it right after each `_queue_product_publish(campaign)` call. Add the task in `tasks.py`; `retry_product_video_publish_task` runs `retry_pending()` and `retry_pending_replays()` each in its own `try/except Exception` (log warning, count 0) and returns the dict. Add the route.
- [ ] **Step 4:** Run the same command plus `tests/test_media_publisher.py tests/test_replay_publisher.py`. Expected: PASS.

---

### Task 4: PHP session mapping, controller, JS guard

**Files:**
- Create: `backend/app/services/AiIdolReplaySessions.php`
- Modify: `backend/app/controllers/LiveController.php` (`index()` before the `$currentLive` selection ~line 98; new private method after `findAiIdolLive` ~line 199)
- Modify: `frontend/views/live.php` (inline script: `const base=...` line ~224 and `async function refreshPinned(){if(!liveId)return;`)
- Test: `backend/tests/ai_idol_replay_sessions_test.php`

**Interfaces:**
- Produces: `final class AiIdolReplaySessions { public static function toSession(array $campaign, ?array $firstProduct, string $fallbackImage = ''): ?array }` — keys/values exactly the table in spec §5.1, with `thumbnail` = http(s) `replay.poster_url`, else `$fallbackImage`, else `''`. No dependency on view helpers (controller passes the resolved image).
- Produces: `LiveController::findAiIdolReplays($mongoDb, SanPham $spModel, int $limit = 30): array`.

- [ ] **Step 1: Write failing test** `backend/tests/ai_idol_replay_sessions_test.php` in the `check()` style of `product_video_client_test.php`. Base campaign `['_id' => '6ace67e735f427f1bf0c492c', 'name' => 'Livestream 15/10', 'replay' => ['video_url' => 'https://pub-test.r2.dev/live-replays/a.mp4', 'poster_url' => 'https://pub-test.r2.dev/live-replays/a.jpg']]`, product `['ma_san_pham' => '93', 'gia_ban' => 139000]`. Checks:
  - `id === 'ai-6ace67e735f427f1bf0c492c'`, `status === 'replay_ready'`, `streamer === 'Syna · AI Idol'`, `url_ban_ghi` = video url, `thumbnail` = poster url, `pinned_product` = product, `gia_uu_dai_live === 139000.0`, `viewers === 0`, `danh_sach_deal === []`, `server_livekit_url === ''`.
  - `rejects non-http video url` (`javascript:alert(1)`) → `null`; missing `_id` → `null`; missing `replay` → `null`.
  - `ignores non-http poster` → thumbnail falls back to `'https://img/p.jpg'` passed as `$fallbackImage`.
  - `no product yields null pinned and empty thumbnail` (no poster, `null` product, no fallback) → `pinned_product === null`, `thumbnail === ''`, `gia_uu_dai_live === 0.0`.
- [ ] **Step 2:** Run `docker exec skinsyntax-php php /var/www/html/tests/ai_idol_replay_sessions_test.php`. Expected: fatal "Failed opening required" (class missing).
- [ ] **Step 3:** Implement the class. Then `findAiIdolReplays`: return `[]` unless `$mongoDb instanceof MongoDB\Database`; `find` with the spec §5.2 filter, `['sort' => ['completed_at' => -1], 'limit' => $limit, 'projection' => ['name' => 1, 'replay' => 1, 'product_ids' => 1, 'completed_at' => 1], 'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]`; collect first non-empty `product_ids[0]` of each, one `$spModel->findManyByIds($ids)`; fallback image `!empty($p['link_hinh_anh']) ? resolve_image_url((string)$p['link_hinh_anh']) : ''`; map via `toSession`, drop nulls; whole body in `try { } catch (Throwable $e) { return []; }`. `require_once __DIR__ . '/../services/AiIdolReplaySessions.php';` at the top of the controller. In `index()` add `$liveSessions = array_merge($liveSessions, $this->findAiIdolReplays($mongoDb, $spModel));` immediately before `$currentLive = null;`.
- [ ] **Step 4:** In `live.php` script add `const isAiReplay=liveId.indexOf('ai-')===0;` after the `const base=...` declaration and change `refreshPinned` to start `if(!liveId||isAiReplay)return;`.
- [ ] **Step 5:** Run the test from Step 2 (expected: all PASS, exit 0), then `docker exec skinsyntax-php php -l /var/www/html/app/controllers/LiveController.php`, `... php -l /var/www/html/app/services/AiIdolReplaySessions.php`, `... php -l /var/www/frontend/views/live.php`. Expected: "No syntax errors detected" ×3. (If the container paths differ, locate with `docker exec skinsyntax-php ls /var/www`.)

---

### Task 5: Full verification, deploy locally, backfill, E2E

- [ ] **Step 1:** From `ai-idol-service`: `..\.venv\Scripts\python.exe -m pytest tests -q`. Expected: all pass (report any pre-existing failures separately from new ones).
- [ ] **Step 2:** Run the `lint` skill checks for changed Python/PHP files; `git diff --check` clean.
- [ ] **Step 3:** Check no campaign is LIVE (read-only): `docker exec -i skinsyntax-ai-idol python -c "from core.state_machine import get_db; print(get_db().ai_idol_campaigns.count_documents({'status':'LIVE'}))"`. Non-zero → ask the user before restarting (restart interrupts the show).
- [ ] **Step 4:** Rebuild the image-baked services: `docker compose up -d --build ai-idol-service ai-idol-worker ai-idol-control-worker ai-idol-broadcast-worker ai-idol-beat` (service names from `docker-compose.yml`; `ai-idol-agent-service` has its own image and is not changed). Expected: all running/healthy.
- [ ] **Step 5:** Dry run: `docker exec skinsyntax-ai-idol python -m engines.replay_publisher backfill`. Expected: lists the 3 campaigns `6ace67e7…`, `6ace7dba…`, `6ace4166…` as "would publish".
- [ ] **Step 6: STOP — ask the user** before `--apply` (writes to their R2 bucket and Atlas). On yes: `docker exec skinsyntax-ai-idol python -m engines.replay_publisher backfill --apply`. Expected: 3× "published".
- [ ] **Step 7: E2E (Playwright MCP)** on `http://localhost:8080/index.php?r=live`: the 3 Syna cards show badge "Xem lại"; filter "Xem lại" keeps them visible; click "Xem lại bản ghi" → URL has `id=ai-…`; `#replayVideoPlayer` has an `https://…/live-replays/…mp4` src, `readyState >= 1`, and setting `currentTime = 10` sticks; no requests to `api_live_products` within 12 s; no console errors. Also open `?r=live&id=ai-000000000000000000000000` → page renders (falls back to first session). Report `E2E NOT RUN` with the reason if Playwright is unavailable.
- [ ] **Step 8:** `node .gitnexus/run.cjs detect-changes --scope all --repo .` and report. Write `update_ai_idol_live_replay.md` (per the project's `update_<task>.md` doc convention) summarising changed files, commands, results and skipped checks. Do not commit unless the user asks.
