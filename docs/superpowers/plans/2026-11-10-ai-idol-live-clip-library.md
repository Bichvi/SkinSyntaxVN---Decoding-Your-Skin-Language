# AI Idol Live Clip Library Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn AI Idol live campaigns into a 30–60 minute routine-ordered show built from a library of short Syna clips. Syna greets once, gives a 10–20 s break between products, rotates angles and fillers between rounds, recaps prices, and says goodbye once.

**Architecture:**
- A campaign with `show_format = "clip_library_v1"` gets a `show_plan` (products in routine order). A writer drafts every clip with the Syna persona.
- Each clip becomes an existing segment+job that skips script generation and runs the existing validate → voice → Syna render path.
- A pure scheduler turns the READY clips into a timeline. The composer concatenates that timeline into one master video, which the unchanged broadcaster and `?r=live` player play.

**Tech Stack:** Python 3.11 (Flask, Celery, pymongo, requests) in `ai-idol-service`; Node render server; PHP views; vanilla JS with `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-10-ai-idol-live-clip-library-design.md` (bản 2). Read it before any task. Section numbers below (§) refer to it.

## Global Constraints

- **Scope:** only purposes `live_and_product` and `live_only` use the new format. `product_video` and legacy campaigns (no `show_format`) must behave exactly as today.
- **Product limit:** a live campaign holds at most **6** products. More is rejected with `ValueError("Chương trình live tối đa 6 sản phẩm")`.
- **Clip lengths:**

  | Clip | Length |
  |---|---|
  | `product` (per angle) | 100–140 s |
  | `interlude` | 10–20 s |
  | `opening` | 60–120 s |
  | `transition` | 20–30 s |
  | `faq` | 20–40 s |
  | `tip` | 60–90 s |
  | `routine_recap` | 60–90 s |
  | `closing` | 20–30 s |

  Every clip stays ≤ 180 s (renderer limit).
- **Library counts:** 2 angles per product, 6 interludes (2 each of `cta`, `about_shop`, `fun`), 1 opening, 2 transitions, 4 faq, 2 tip, 1 routine_recap, 1 closing.
- **Speech rate for budgeting:** about 4 Vietnamese words per second.
- **Section labels for product clips, in order:** `[Lead-in] [Pain Point] [Why] [Solution] [Feel] [How to Use] [Price] [CTA]`.
  - Canonical keys: `lead_in, pain_point, why, solution, feel, how_to_use, price, cta`.
- **Numbering:** "món số N" may appear only in `[CTA]` and `routine_recap`. Numbers are never reassigned after a product is dropped.
- **Prices:**
  - Only exact phrases from `core.vi_money.price_phrases`.
  - `<X> cành` is valid only when `<X> nghìn đồng` is a valid phrase.
  - The bundle total is computed in code and read verbatim as `tổng cả bộ: <words> đồng`.
- **Honesty:** the D7 patterns in §5.6 are hard errors. Robotic words (`hệ thống|thuật toán|dữ liệu|quét`) are `SOFT_QUALITY` errors.
- **Shop facts allowed:**
  - `SHOP_FACTS_VI = ("sản phẩm chính hãng", "giao hàng toàn quốc", "hỗ trợ tận tâm", "cá nhân hóa routine theo làn da", "chat với Syna trên web để được tư vấn")`.
- **Scheduler output length:** total within `[target − 60 s, target + 150 s]`.
- **Preparation time:** `library_minutes = 4 × n_products + 10`; `preparation_minutes = max(MIN_LEAD_MINUTES, ceil(library_minutes × RENDER_FACTOR + 10))`.
  - `RENDER_FACTOR` comes from env `AI_IDOL_RENDER_FACTOR`, default `4`.
- **Transient render retry:** at most 2 retries, sleeping 30 s then 90 s.
  - Transient means the message contains `Cannot read audio duration`, `page.goto: Timeout`, `ffprobe timed out`, `Connection`, or `HTTP 5`.
- **Tests:**
  - Run from `ai-idol-service` with `..\.venv\Scripts\python.exe -m pytest <files> -q`. `tests/conftest.py` blocks real Mongo; use `tests.fakes.FakeDb` for DB code. Never call a real LLM or HTTP service in tests.
  - Do not run scripts in `backend/tests` (they write the real DB).
- **Repo rules (AGENTS.md):**
  - Before editing an existing function, run `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .` and report the risk. Treat `UNKNOWN` as unresolved and confirm with `rg`.
  - Before any commit, run `node .gitnexus/run.cjs detect-changes --scope all --repo .`.
  - **Commit only when the user has approved committing.** Until then, the "Commit" steps mean: stage nothing, list the files in the task report.
- **Uncommitted user work:** many files already have uncommitted user changes (see `git status`). Edit additively and never revert or reformat unrelated code.

## Review Focus

1. **Live campaign with one product.** There are no interludes between products and no faq/tip slots. The show must still assemble (opening → product → recap → closing, repeated rounds) and must not fail the readiness check for missing interludes. Test: Task 4 `test_single_product_show`, Task 7 `test_ready_with_one_product_without_interlude`.
2. **Product dropped after phase A.** Both angles SKIPPED. Recap must list only the remaining numbers (e.g. 1 and 3), and the customer page must show "Số 1", "Số 3". Test: Task 7 `test_recap_after_drop_keeps_original_numbers`.
3. **Flash sale active.** `voucher`, `trợ giá`, `chỉ còn` must be allowed. Recap total uses flash prices. Test: Task 2 `test_promo_words_allowed_with_flash_sale`, Task 3 `test_bundle_total_uses_flash_price`.
4. **Search service down or unauthorized.** `/api/cart/analyze` returns 401/500/timeout. Routine order falls back to keywords and campaign creation still succeeds. Test: Task 3 `test_build_show_plan_falls_back_on_http_error`.
5. **Retry after a clip FAILED in the new format.** `retry_campaign` must re-queue only that clip's job (prewritten script kept). If it was a product clip, phase B re-runs. Test: Task 7 `test_retry_failed_product_reruns_phase_b`.

---

### Task 1: Syna persona module and new section labels

**Files:**
- Create: `ai-idol-service/engines/syna_persona.py`
- Modify: `ai-idol-service/core/script_text.py` (`SECTION_NAMES`, `_canonical_label`)
- Test: `ai-idol-service/tests/test_live_persona_sections.py`

**Interfaces:**
- Produces:
  - `PERSONA_RULES_VI: str`, the persona text of spec §5.1 (Vietnamese, includes the honesty rules and the interaction rules).
  - `SHOP_FACTS_VI: tuple[str, ...]` (exact values in Global Constraints).
  - `ROBOTIC_WORDS: re.Pattern`, matching `\b(hệ thống|thuật toán|dữ liệu|quét)\b`, case-insensitive.
  - `split_labeled_sections` recognises the new labels and returns the keys `lead_in, pain_point, why, solution, feel`.

- [ ] **Step 1: Write failing tests**

```python
def test_new_product_clip_labels_are_split():
    script = "[Lead-in]\nA\n[Pain Point]\nB\n[Why]\nC\n[Solution]\nD\n[Feel]\nE\n[How to Use]\nF\n[Price]\nG\n[CTA]\nH"
    assert [label for label, _ in split_labeled_sections(script)] == [
        "lead_in", "pain_point", "why", "solution", "feel", "how_to_use", "price", "cta"]

def test_legacy_labels_unchanged():
    assert split_labeled_sections("[Opening]\nx\n[Key Benefits]\ny")[1][0] == "key_benefits"

def test_persona_mentions_no_skin_joke_and_bans_fake_promos():
    assert "không có da" in PERSONA_RULES_VI
    assert "flash sale" in PERSONA_RULES_VI.lower()

def test_robotic_words():
    assert ROBOTIC_WORDS.search("theo dữ liệu của Syna")
    assert not ROBOTIC_WORDS.search("Syna thuộc làu bảng thành phần")
```

- [ ] **Step 2: Run and confirm they fail.** Run `..\.venv\Scripts\python.exe -m pytest tests/test_live_persona_sections.py -q`. Expected: FAIL (ImportError / label mismatch).
- [ ] **Step 3: Implement.**
  - Extend `SECTION_NAMES` with `lead[- ]?in|pain point|why|solution|feel`.
  - Map them in `_canonical_label` (`lead in` → `lead_in`, `pain point` → `pain_point`).
  - Write `syna_persona.py` with the three constants.
- [ ] **Step 4: Run the new test plus `tests/test_script_sections.py tests/test_subtitles.py`.** Expected: all PASS.
- [ ] **Step 5: Commit** (see Global Constraints): `feat(ai-idol): Syna live persona and product-clip section labels`.

### Task 2: Live clip validation (kinds, "cành", D7 patterns)

**Files:**
- Modify: `ai-idol-service/core/script_text.py` (`find_price_issues`, `_scan_price_text`)
- Modify: `ai-idol-service/engines/script_validator.py` (`FORBIDDEN_PATTERNS` usage, `_deterministic_issues`, `validate_script`, `_validate_with_retries`, `validate_and_repair_script`, `_revision_structure`, `_audit_prompts`)
- Test: `ai-idol-service/tests/test_live_clip_validation.py`

**Interfaces:**
- Consumes: Task 1 `ROBOTIC_WORDS`, `SHOP_FACTS_VI`, the new labels.
- Produces:
  - `LIVE_CLIP_KINDS = frozenset({"opening","product","interlude","faq","tip","routine_recap","transition","closing"})` in `script_validator.py`.
  - `validate_script(script, snapshot, purpose=LEGACY_PURPOSE, clip_kind: str | None = None) -> list[ValidationIssue]`.
  - `validate_and_repair_script(script, snapshot, purpose=LEGACY_PURPOSE, max_attempts=None, on_event=None, clip_kind: str | None = None) -> ScriptValidationResult`.
  - With `clip_kind=None`, behaviour is byte-for-byte the current one.
  - For non-product kinds, `snapshot` is the combined snapshot of Task 6: `{"name": theme, "brand": "SkinSyntax", "products": [{"name","brand","pricing"}...], "bundle_total": int|None, "pricing": None}`.
  - `find_price_issues(text, pricing, extra_phrases: dict[str, str] | None = None)`. `extra_phrases` adds allowed phrases (used for combined pricings and the bundle total). "cành" handling applies always.

- [ ] **Step 1: Write failing tests**, one per rule. Each asserts on `[i.code for i in validate_script(...)]` with the LLM audit patched to return `[]` (`patch("engines.script_validator._llm_audit_issues", return_value=[])`):
  - `test_canh_alias_valid`: pricing regular 278000; text "…hai trăm bảy mươi tám nghìn đồng, tức là hai trăm bảy mươi tám cành thôi" → no `PRICE_*` code.
  - `test_canh_alias_wrong_number`: "hai trăm cành" → contains `PRICE_MISMATCH`.
  - `test_fake_quota_rejected`: "30 bà đầu tiên" and "40 suất duy nhất" → `BLACKLIST_CLAIM`.
  - `test_fake_gift_and_unlock_rejected`: "tặng kèm bông", "5.000 tim là mở khóa" → `BLACKLIST_CLAIM`.
  - `test_end_of_live_price_rejected`: "tắt live là giá nhảy về" → `BLACKLIST_CLAIM`.
  - `test_efficacy_days_rejected`: "bôi 3 hôm là dịu", "chuẩn y khoa" → `BLACKLIST_CLAIM`.
  - `test_promo_words_allowed_with_flash_sale`: pricing has `flash` → "voucher", "trợ giá" pass.
  - `test_robotic_word_is_soft_error`: "theo dữ liệu" → code `ROBOTIC_WORDING`, category `SOFT_QUALITY`, severity `error`.
  - `test_tip_with_product_name_rejected`: clip_kind `tip`, combined snapshot product "Kem Chống Nắng Beplain" → code `TIP_MENTIONS_PRODUCT`.
  - `test_interlude_with_price_rejected`: clip_kind `interlude`, any price phrase → `PRICE_NOT_ALLOWED`.
  - `test_product_clip_evergreen_part`: clip_kind `product`, "comment" in `[Pain Point]` → `EVERGREEN_VIOLATION`; "comment" in `[CTA]` → no issue.
  - `test_recap_total_phrase_valid`: clip_kind `routine_recap`, `bundle_total=371000`, text "tổng cả bộ: ba trăm bảy mươi mốt nghìn đồng" → no price issue.
  - `test_legacy_validation_unchanged`: an existing `live_and_product` 8-section script with `clip_kind=None` returns the same codes as before (copy one fixture from `tests/test_price_rules.py`).
- [ ] **Step 2: Run** `..\.venv\Scripts\python.exe -m pytest tests/test_live_clip_validation.py -q`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - D7 regexes from spec §5.6 go into a new tuple `LIVE_FORBIDDEN_PATTERNS`, applied only when `clip_kind` is set. `voucher|trợ giá` are skipped when `snapshot.get("pricing", {}).get("flash")`, or any combined product has a flash.
  - "cành": in `_scan_price_text`, after removing valid phrases, remove `<X> cành` occurrences whose `<X> nghìn đồng` is a valid phrase. Any remaining `<spelled-number> cành` → `PRICE_MISMATCH`.
  - Kind rules:
    - `PRICE_NOT_ALLOWED` for `tip|interlude|opening|transition|closing`.
    - `TIP_MENTIONS_PRODUCT` for `tip` (folded product name or brand substring).
    - `EVERGREEN_VIOLATION` for `product` sections before `price` matching `livestream|hôm nay|comment|thả tim|bình luận` or any price phrase.
  - `_revision_structure("clip_product")` returns the 8-label structure text. `validate_and_repair_script` passes `clip_kind` through to `_validate_with_retries` and to `repair_script`; read `engines/script_repair.py` and thread the parameter the same way `purpose` is threaded.
  - `_audit_prompts`: for non-product kinds, "THÔNG TIN SẢN PHẨM GỐC" lists every combined product (name, brand, price lines), plus `SHOP_FACTS_VI` for `interlude`.
- [ ] **Step 4: Run** the new test plus `tests/test_price_rules.py tests/test_script_repair.py tests/test_validation_contract.py tests/test_repair_exhausted.py tests/test_script_review.py`. Expected: all PASS.
- [ ] **Step 5: Commit:** `feat(ai-idol): validation rules for live library clips`.

### Task 3: Show plan (routine order, conflicts, bundle total)

**Files:**
- Create: `ai-idol-service/core/show_plan.py`
- Modify: `ai-idol-service/config.py` (add `SEARCH_SERVICE_URL = os.getenv("AI_IDOL_SEARCH_URL", "http://ai-service:5001")`, `SEARCH_SERVICE_TOKEN = os.getenv("SEARCH_INTERNAL_TOKEN", "")`, `RENDER_FACTOR = float(os.getenv("AI_IDOL_RENDER_FACTOR", "4"))`, `LIVE_MAX_PRODUCTS = 6`)
- Test: `ai-idol-service/tests/test_show_plan.py`

**Interfaces:**
- Produces:
  - `STEP_ORDER = ("tay_trang","sua_rua_mat","tay_te_bao_chet","toner","serum","kem_duong","kem_chong_nang")`.
  - `STEP_LABELS_VI: dict[str, str]`, e.g. `"tay_te_bao_chet": "Tẩy tế bào chết"`.
  - `step_from_keywords(name: str, category: str = "") -> str | None`.
  - `order_products(products: list[dict], steps: dict[str, str | None]) -> list[dict]`. Input items have `product_id`; output items are `{"product_id","number","step_key","step_label"}`. An unknown step sorts just before `kem_duong`. Ties keep input order. Numbers run 1..N.
  - `fetch_routine_steps(product_ids: list[str], *, post=requests.post, timeout=5) -> tuple[dict[str, str | None], list[dict], str]`. Returns `(steps_by_id, conflicts, source)`, where `source` is `"graph"`, or `"keyword"` when any call failed or was `degraded`.
  - `build_show_plan(snapshots: list[dict], *, post=requests.post) -> dict`. Returns the `show_plan` shape of §4.1. `theme_vi` is `""` here; the writer fills it.
  - `bundle_total(pricings: list[dict | None]) -> int`. Sum of the effective price (flash price if `flash` else regular).
  - `bundle_total_phrase(total: int) -> str` → `"tổng cả bộ: " + money_words(total)`.
  - `estimate_preparation_minutes(n_products: int) -> int` (formula in Global Constraints).

- [ ] **Step 1: Write failing tests:**
  - `test_order_full_routine`: with step keys given out of order, the output follows `STEP_ORDER`, numbers 1..N.
  - `test_exfoliant_after_cleanser_before_serum`.
  - `test_unknown_step_before_moisturiser`.
  - `test_same_step_keeps_input_order`.
  - `test_keywords`: `"Gel Tẩy Tế Bào Chết Arrahan"` → `tay_te_bao_chet`; `"Kem Chống Nắng Beplain"` → `kem_chong_nang`; `"Serum Timeless Vitamin B5"` → `serum`; `"Son môi"` → `None`.
  - `test_build_show_plan_uses_graph_steps`: fake `post` returns `{"routine":{"has":["serum"]},"conflicts":[]}` → `routine_source == "graph"`.
  - `test_build_show_plan_falls_back_on_http_error`: fake `post` raises `requests.Timeout`, or returns status 401 → `routine_source == "keyword"`, order still correct.
  - `test_bundle_total_uses_flash_price`: regular 95000 and 278000, the second with flash 250000 → `345000`.
  - `test_bundle_total_phrase`: `371000` → `"tổng cả bộ: ba trăm bảy mươi mốt nghìn đồng"`.
  - `test_estimate_preparation_minutes`: 3 → `98`; 6 → `146`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `fetch_routine_steps` posts `{"product_ids":[id]}` per product, plus one call with all ids for `conflicts`, with header `X-Internal-Token` when the token is set.
  - Map `conflicts` entries to `{a, b, severity, message_vi}`. Read the response shape from `ai-service-flask/search_service/cart_analyze.py` and keep only what exists.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat(ai-idol): routine show plan and bundle total`.

### Task 4: Show scheduler (pure)

**Files:**
- Create: `ai-idol-service/core/show_types.py`
- Create: `ai-idol-service/engines/show_scheduler.py`
- Test: `ai-idol-service/tests/test_show_scheduler.py`

**Interfaces:**
- Produces, in `core/show_types.py`:
  ```python
  @dataclass(frozen=True)
  class ClipInfo:
      segment_id: str; kind: str; duration: float
      product_id: str | None = None; angle: str | None = None
      interlude_type: str | None = None; variant: int = 0

  @dataclass(frozen=True)
  class ProgramItem:
      segment_id: str; kind: str; product_id: str | None; angle: str | None
      start: float; end: float
  ```
- `build_program(clips: list[ClipInfo], target_seconds: float, product_order: list[str]) -> list[ProgramItem]`. Raises `ValueError` when there is no opening, closing, routine_recap, or product.
- Round algorithm: exactly spec §6.1 steps 1–5 and its filling rule.

- [ ] **Step 1: Write failing tests.** A helper `make_library(n_products, product_seconds=120, missing_b=())` builds ClipInfo lists with the counts in Global Constraints. Use a shared `assert_invariants(program, target)` that checks:
  - first is `opening`, last is `closing`, exactly one `closing`;
  - no two `product` items adjacent;
  - no identical `segment_id` adjacent;
  - every product appears in each complete round;
  - `target-60 <= total <= target+150`;
  - `start`/`end` contiguous from 0.

  Cases:
  - `test_three_products_30_min`;
  - `test_six_products_60_min`;
  - `test_single_product_show` (no interlude needed; still valid);
  - `test_missing_angle_b_uses_a`;
  - `test_no_transition_uses_about_shop_interlude`;
  - `test_one_interlude_only_never_adjacent_to_itself`;
  - `test_angles_alternate_by_round` (round 1 A, round 2 B);
  - `test_interlude_types_rotate`;
  - `test_target_15_min_short_show`;
  - `test_raises_without_closing`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement** `build_program`. Use a per-kind rotating cursor (`itertools.cycle` over variants, skipping the last-used id when another exists).
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat(ai-idol): pure show scheduler`.

### Task 5: Show writer (LLM drafts)

**Files:**
- Create: `ai-idol-service/engines/show_writer.py`
- Test: `ai-idol-service/tests/test_show_writer.py`

**Interfaces:**
- Consumes:
  - Task 1 `PERSONA_RULES_VI`, `SHOP_FACTS_VI`; Task 3 `bundle_total_phrase`.
  - `engines.content_agent.price_lines(pricing)`, `engines.content_agent._product_source_lines(...)`.
  - `providers.llm_provider.LLMProvider().invoke_json_schema(system, user, schema=..., schema_name=...)`.
- Produces:
  - `ClipDraft` dataclass in `core/show_types.py`: `kind, script, product_id=None, angle=None, interlude_type=None, variant=0`.
  - `write_product_clips(snapshot: dict, item: dict, plan: dict, *, llm=None) -> list[ClipDraft]`. Returns 2 drafts, angles "A" and "B". `item` is a `show_plan.products` entry.
  - `write_frame_clips(plan: dict, snapshots: dict[str, dict], faqs: list[dict], *, llm=None) -> tuple[str, list[ClipDraft]]`. Returns `(theme_vi, drafts)` with 1 opening, 2 transition, 6 interlude (2×cta/about_shop/fun), 4 faq, 2 tip, 1 closing.
  - `write_routine_recap(plan: dict, snapshots: dict[str, dict], *, llm=None) -> ClipDraft`. Kind `routine_recap`; the prompt carries every remaining product's number, step label and price lines, plus `bundle_total_phrase(...)` as a mandatory verbatim phrase.
  - `load_answered_faqs(db, product_ids: list[str], limit: int = 4) -> list[dict]`. Reads `hoi_dap_san_pham` where `ma_san_pham ∈ ids` and `tra_loi.noi_dung` is non-empty, newest first. Returns `{"product_id","question","answer"}`. Check the real field names in `backend/app/models/HoiDap.php` before coding.
- Prompts:
  - `PERSONA_RULES_VI` goes into every system prompt.
  - User prompts follow §5.3 exactly: section table, word budgets from Global Constraints, the "self-contained clip" rule, greetings only in opening/transition, goodbye only in closing.
  - Each interlude ends with a generic next-product teaser.

- [ ] **Step 1: Write failing tests** with a fake LLM object recording prompts and returning canned JSON:
  - `test_product_clips_two_angles_with_labels`: both drafts contain all 8 labels, angles A/B, `kind == "product"`.
  - `test_persona_in_every_system_prompt`.
  - `test_frame_clip_counts`: counts per kind/type exactly as Global Constraints; `theme_vi` non-empty.
  - `test_recap_prompt_contains_total_and_numbers`: plan numbers 1 and 3 only → prompt contains "món số 1", "món số 3", and the total phrase.
  - `test_faq_prefers_answered_questions`: FakeDb `hoi_dap_san_pham` with one answered and one unanswered → only the answered one is returned.
  - `test_malformed_llm_json_raises_value_error`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.** One JSON schema per call. Store each script as labelled text: join sections as `"[Label]\ntext"` lines for product clips; plain text for the other kinds.
- [ ] **Step 4: Run.** Expected: PASS.
- [ ] **Step 5: Commit:** `feat(ai-idol): Syna show writer for live clip library`.

### Task 6: Campaign creation and phase-A preparation

**Files:**
- Modify: `ai-idol-service/core/campaigns.py` (`create_campaign`, `prepare_campaign`)
- Modify: `ai-idol-service/core/state_machine.py` (`create_job`: optional `clip_kind`, `prewritten_script`)
- Test: `ai-idol-service/tests/test_live_library_campaign.py`

**Interfaces:**
- Consumes: Tasks 3 and 5.
- Produces:
  - `create_job(product_id, content_mode, configuration, *, campaign_id=None, segment_id=None, product_snapshot=None, purpose="live_only", clip_kind: str | None = None, prewritten_script: str | None = None) -> str`. When `prewritten_script` is set, it stores `clip_kind`, `prewritten_script: True`, and `artifacts.prewritten_draft`.
  - `combined_snapshot(plan: dict, snapshots: dict[str, dict], bundle_total: int | None = None) -> dict` in `core/show_plan.py` (shape in Task 2).
  - New campaign fields `show_format`, `show_plan` (§4.1); segments with fields of §4.2.
  - Legacy creation is unchanged for `product_video`.

- [ ] **Step 1: Write failing tests** (FakeDb, `post`/writer patched):
  - `test_live_campaign_rejects_seven_products`.
  - `test_live_campaign_lead_time_uses_library_estimate`: 3 products scheduled 90 min ahead → `ValueError` mentioning `98`; 100 min ahead → ok.
  - `test_live_campaign_gets_show_format_and_plan`.
  - `test_product_video_unchanged`: no `show_format`, segments without `kind`.
  - `test_prepare_creates_phase_a_segments_and_jobs`: 3 products → 6 product segments plus 16 frame segments, every job `prewritten_script` true, non-product jobs `product_id is None` with the combined snapshot.
  - `test_prepare_is_idempotent`: a second call creates no duplicates.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `create_campaign`: for live purposes, set `show_format`; check `len(product_ids) <= LIVE_MAX_PRODUCTS`; use `estimate_preparation_minutes`; insert **no** per-product segments for `clip_library_v1` (prepare creates them).
  - `prepare_campaign`: branch on `show_format`.
    - Build snapshots, plan, faqs, then drafts; write `theme_vi` into `show_plan`.
    - Insert one segment per draft (`position` = running index, `phase "A"`, `script_text`), then create jobs.
    - Idempotency: skip when segments with `phase` already exist.
    - A writer error marks the campaign NEEDS_ATTENTION with `"Không viết được kịch bản buổi live: <msg>"`.
- [ ] **Step 4: Run** the new test plus `tests/test_campaigns.py tests/test_campaign_recovery.py tests/test_campaign_skip.py tests/test_purpose.py`. Expected: PASS.
- [ ] **Step 5: Commit:** `feat(ai-idol): create live campaigns as clip libraries`.

### Task 7: Phase B, readiness, retry

**Files:**
- Modify: `ai-idol-service/core/campaigns.py` (`update_campaign_from_job`, `retry_campaign`, `resume_ready_campaign`)
- Modify: `ai-idol-service/tasks.py` (new task `write_routine_recap_task(campaign_id)`)
- Test: `ai-idol-service/tests/test_live_library_phase_b.py`

**Interfaces:**
- Produces:
  - `library_ready(segments: list[dict]) -> tuple[bool, str]` in `core/campaigns.py`. Returns `(True, "")`, or `(False, "<Vietnamese reason naming the missing kind>")`. Rules: spec §7.3, where interludes are required only when ≥2 products remain.
  - `remaining_products(plan: dict, segments: list[dict]) -> list[dict]`. Plan items with at least one READY product angle, original `number` kept.
  - `start_phase_b(campaign_id: str) -> str | None`. Writes the recap with the remaining products, inserts the segment+job (`phase "B"`), and returns the job id. If a phase-B segment exists and its product set is unchanged, returns None. If the set changed, cancels the old phase-B segment and creates a new one.
- `update_campaign_from_job` for `clip_library_v1`:
  - When all `product` segments are final and phase B has not run for the current set → enqueue `write_routine_recap_task`.
  - Return the campaign id for assembly only when every segment is final **and** `library_ready` is true.
  - Otherwise, once all segments are final, set NEEDS_ATTENTION with the reason.

- [ ] **Step 1: Write failing tests** (FakeDb):
  - `test_phase_b_starts_after_all_product_clips_final`.
  - `test_recap_after_drop_keeps_original_numbers`: product 2 both SKIPPED → the recap writer receives numbers [1, 3].
  - `test_ready_with_one_product_without_interlude`.
  - `test_not_ready_without_closing_reason`: reason contains "kết buổi".
  - `test_retry_failed_product_reruns_phase_b`: the product set changes → the old recap is cancelled and a new one queued.
  - `test_legacy_campaign_update_unchanged`: reuse one scenario from `tests/test_campaign_recovery.py`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new test plus `tests/test_campaigns.py tests/test_campaign_recovery.py tests/test_campaign_skip.py tests/test_autopilot_no_stop.py`. Expected: PASS.
- [ ] **Step 5: Commit:** `feat(ai-idol): routine recap phase and library readiness`.

### Task 8: Pipeline for prewritten clips, page clip cut, non-product manifest, transient retry

**Files:**
- Modify: `ai-idol-service/core/pipeline.py` (`run_ai_idol_pipeline`)
- Modify: `ai-idol-service/engines/body_clip.py` (`product_page_clip`, `cut_body_clip`)
- Modify: `ai-idol-service/engines/syna_engine.py` (`build_manifest`, new helpers)
- Modify: `scripts/ai-idol-mascot/syna-render-server.cjs` (`probe`)
- Test: `ai-idol-service/tests/test_live_library_pipeline.py`

**Interfaces:**
- Consumes: Task 2 `validate_and_repair_script(..., clip_kind=...)`; Task 6 job fields.
- Produces:
  - In `run_ai_idol_pipeline`: if `job.get("prewritten_script")`, skip product collection when a snapshot exists, skip `retrieve_knowledge` (store `""`), use `artifacts.prewritten_draft` as `draft` instead of calling `generate_content_and_script`, and pass `clip_kind=job["clip_kind"]` to validation. Everything after is unchanged.
  - `product_page_clip(job_id, purpose, video_path, audio_path, *, intro, clip_kind: str | None = None)`. For `clip_kind == "product"` and angle A only (read the job's segment), the page clip runs from the start of `pain_point` to the start of `price`. Other live clip kinds return `(None, None)`.
  - `cut_body_clip(src, dst, cut_seconds, fade=0.6, start_seconds: float = 0.0)`.
  - `build_manifest(..., clip_kind: str | None = None, theme: str = "")`. For non-product kinds: `product.name = theme or "SkinSyntax Live"`, `price = 0`, `price_from = None`, `ingredients = []`.
  - `is_transient_render_error(message: str) -> bool` and `render_with_retries(render, *, sleeps=(30, 90), sleep=time.sleep)` in `syna_engine.py`. The pipeline wraps `render_syna_video` with it. `RendererUnavailable` is not retried here (existing parking path).
  - `syna-render-server.cjs`: `probe()` timeout is 30000 ms. On kill, it throws `new Error('ffprobe timed out')`.

- [ ] **Step 1: Write failing tests:**
  - `test_prewritten_job_skips_generation`: patch `generate_content_and_script` to raise; job with `prewritten_script` → validation receives the draft and `clip_kind`.
  - `test_page_clip_window_for_product_angle_a`: timings with labels → cut start = `pain_point` start + intro, end = `price` start + intro.
  - `test_no_page_clip_for_interlude`.
  - `test_manifest_for_interlude_hides_price`.
  - `test_transient_render_retried_twice_then_fails`: fake render raising `RuntimeError("Syna render HTTP 422: Cannot read audio duration")` 3 times → 3 calls, sleeps `[30, 90]`, error raised.
  - `test_non_transient_not_retried`.
  - `test_legacy_job_still_generates`.
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new test plus `tests/test_body_clip.py tests/test_syna.py tests/test_syna_lead_in.py tests/test_e2e_flow.py tests/test_approval_mode.py`. Also run `node --check scripts/ai-idol-mascot/syna-render-server.cjs` from the repo root. Expected: PASS and no syntax error.
- [ ] **Step 5: Commit:** `feat(ai-idol): render prewritten live clips with retries`.

### Task 9: Compose the timeline and broadcast its real length

**Files:**
- Modify: `ai-idol-service/engines/program_composer.py` (`assemble_campaign_program`)
- Modify: `ai-idol-service/engines/broadcast_engine.py` (`broadcast_campaign`)
- Test: `ai-idol-service/tests/test_live_library_compose.py`

**Interfaces:**
- Consumes: Task 4 `build_program`, `ClipInfo`; Task 7 `library_ready`.
- Produces:
  - `concat_lines(program: list[ProgramItem], paths: dict[str, str]) -> str` (pure).
  - `assemble_campaign_program(campaign_id)` branches on `show_format`. For `clip_library_v1`:
    - build `ClipInfo` from READY segments (duration via `probe_duration`, cached on the segment as `duration_seconds`);
    - `target = duration_minutes × 60`;
    - check free disk with `shutil.disk_usage(PROGRAM_DIR).free >= 2 × Σ clip_bytes × repeats`, otherwise NEEDS_ATTENTION `"Không đủ dung lượng ổ đĩa để ghép buổi live"`;
    - write the concat file, run the existing ffmpeg commands, save `program` (list of dicts), `duration_seconds`, `master_video_path`, status `SCHEDULED`.
  - `broadcast_campaign`: `duration_seconds = round(campaign["duration_seconds"])` when `show_format == "clip_library_v1"`, else unchanged.

- [ ] **Step 1: Write failing tests:**
  - `test_concat_lines_repeat_clips_in_order`.
  - `test_assemble_library_saves_program` (patch `subprocess.run`, `probe_duration`, `shutil.disk_usage`).
  - `test_assemble_library_low_disk_needs_attention`.
  - `test_assemble_legacy_unchanged`.
  - `test_broadcast_uses_master_duration_for_library` (extend the pattern in `tests/test_broadcast.py`).
- [ ] **Step 2: Run.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the new test plus `tests/test_broadcast.py tests/test_broadcast_lifecycle.py`. Expected: PASS.
- [ ] **Step 5: Commit:** `feat(ai-idol): assemble live shows from the clip timeline`.

### Task 10: Customer page numbering

**Files:**
- Modify: `backend/app/controllers/LiveController.php` (`findAiIdolLive`, `index`)
- Modify: `frontend/views/live.php` (AI Idol section only)
- Modify: `frontend/public/assets/css/ai-idol-live.css`

**Interfaces:**
- `findAiIdolLive` adds `'numbers' => [product_id => number]` from `show_plan.products` (empty array for legacy).
- `index()` orders `$aiIdolProducts` by number when `numbers` is non-empty.
- `live.php` shows a badge `<span class="ai-idol-live__num">Số N</span>` on each card when a number exists.

- [ ] **Step 1: Implement** the three changes.
- [ ] **Step 2: Verify.**
  - `docker exec skinsyntax-php php -l /var/www/html/app/controllers/LiveController.php` and `php -l /var/www/frontend/views/live.php` → "No syntax errors".
  - `curl -s -o NUL -w "%{http_code}" "http://localhost:8080/index.php?r=live"` → `200`.
- [ ] **Step 3: Commit:** `feat(live): number AI Idol products by show order`.

### Task 11: Studio clip groups and timeline; agent limits

**Files:**
- Modify: `frontend/public/assets/js/studio-logic.js` (new pure functions)
- Modify: `frontend/views/admin/ai_idol/index.php` and the script that renders campaign detail (find it with `rg "segments" frontend/public/assets/js/studio-*.js`)
- Modify: `ai-idol-service/core/campaigns.py` (`get_campaign`: include `kind, angle, interlude_type, phase, script_text` per segment, plus `show_plan`, `program`)
- Modify: `ai-idol-service/agents/service.py` (cap live product selection at 6; the reply mentions `estimate_preparation_minutes`)
- Test: `frontend/public/assets/js/tests/studio-logic.test.js`, `ai-idol-service/tests/test_agent.py`

**Interfaces:**
- `groupClipsByKind(segments) -> Array<{kind, label, items}>`, ordered `opening, product, interlude, faq, tip, transition, routine_recap, closing`. Vietnamese labels: "Mở màn", "Sản phẩm", "Nghỉ giữa hiệp", "Hỏi đáp", "Kiến thức da", "Chuyển vòng", "Tóm tắt giá", "Kết buổi".
- `programTimeline(program, totalSeconds) -> Array<{kind, leftPct, widthPct, title}>`.

- [ ] **Step 1: Write failing JS tests**:
  - `groupClipsByKind` ordering and labels;
  - `programTimeline` percentages sum to 100 (±0.01) for a 3-item program.
- [ ] **Step 2: Write a failing pytest**: `test_live_agent_caps_products_at_six` in `tests/test_agent.py`, following the existing brief tests.
- [ ] **Step 3: Implement**, then render the groups and the timeline bar in the campaign detail view (legacy campaigns: unchanged list).
- [ ] **Step 4: Run** `node --test frontend/public/assets/js/tests/studio-logic.test.js` from the repo root and `..\.venv\Scripts\python.exe -m pytest tests/test_agent.py -q`. Expected: PASS.
- [ ] **Step 5: Commit:** `feat(studio): clip groups and show timeline for live campaigns`.

### Task 12: Deploy to containers and real end-to-end run

**Files:** none (verification and notes only). Append results to `update_ai_idol_live_library.md`, a new doc per the repo's `update_<task>.md` convention, and add one line to `update.md`.

- [ ] **Step 1: Rebuild the AI Idol images** (no other builds running). Run `docker compose up -d --build ai-idol-service ai-idol-worker ai-idol-beat ai-idol-broadcast-worker ai-idol-control-worker ai-idol-agent-service`. Then confirm container code equals the repo for `engines/*.py core/*.py`.
- [ ] **Step 2: Restart the Node render server** so the new `probe()` timeout is used. Ask the user before stopping the running `syna-render-server.cjs` process.
- [ ] **Step 3: Create a live campaign.**
  - Products: 1386 (Arrahan exfoliating gel), 68 (Serum B5), 101 (Beplain sunscreen).
  - `purpose: live_and_product`, `platforms: ["internal"]`, `duration_minutes: 30`, `approval_mode: AUTO`, `avatar_mode: syna_3d`.
  - Schedule it at `estimate_preparation_minutes(3)` plus 5 minutes.
- [ ] **Step 4: Watch until SCHEDULED** and record the real preparation minutes. If it lands on NEEDS_ATTENTION, read the reason and fix, or ask.
- [ ] **Step 5: Air with `reschedule … "now"`.** Open `http://localhost:8080/index.php?r=live` and check by watching/listening:
  - one greeting;
  - an interlude between every product pair;
  - no goodbye before the end;
  - angle B in round 2;
  - the recap with "tổng cả bộ";
  - one closing;
  - cards numbered "Số 1–3".

  Record the result as **E2E PASS/FAIL** with notes. If anything is not observed, write "not verified" with the reason.
- [ ] **Step 6: Run GitNexus `detect-changes --scope all`** and report. Commit only with the user's approval.
