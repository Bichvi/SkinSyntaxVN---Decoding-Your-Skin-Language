# AI IDOL SERVICE — WIZARD & SCRIPT PIPELINE TESTS

**Execution Date:** 2026-10-11  
**Target Catalog Product:** Product ID `93` (*"Sữa Dưỡng Ẩm Embryolisse Siêu Phục Hồi Da 30ml"*)

---

## 1. Flow A: Agent Creation & Intent Classification

* **API Call:** `POST /api/ai-idol/agent/runs` via PHP proxy
* **Payload:**
  ```json
  {
    "brief": "Tối mai lúc 20 giờ, tạo livestream giới thiệu sản phẩm mã 93 an toàn.",
    "autonomy_policy": "auto_preview",
    "idempotency_key": "test_1760117649",
    "created_by": "admin_test"
  }
  ```
* **API Response:** `{"created":true,"ok":true,"run_id":"6aca7711dec4b62c4301b1bb"}` (HTTP 202)
* **Execution Trace:**
  - Celery task `process_agent_run` executed.
  - LLM call: `POST https://api.groq.com/openai/v1/chat/completions` (HTTP 200 OK).
  - Intent classification: `CREATE_CAMPAIGN` with `confidence: 0.94`.
  - Product resolution: Resolved candidates and flagged safety policy requirement.
  - Final Agent Run status: `NEEDS_INPUT` (waiting for user confirmation per policy).
* **Result:** **PASS**.

---

## 2. Flow B: Wizard Campaign Creation

* **API Call:** `POST /api/ai-idol/campaigns` via PHP proxy
* **Payload:**
  ```json
  {
    "name": "Photo Render Test 00:38:24",
    "product_ids": ["93"],
    "scheduled_at": "2026-10-10T19:38",
    "platforms": ["internal"],
    "duration_minutes": 30,
    "content_mode": "Product Intro",
    "configuration": {
      "format": "9:16",
      "length_mode": "Short (30-60s)",
      "avatar_mode": "photo",
      "avatar_asset": "",
      "background_asset": ""
    }
  }
  ```
* **API Response:** `{"campaign_id":"6aca7822dec4b62c4301b1bf","created":true,"ok":true}` (HTTP 202)
* **Execution Trace:**
  1. Celery task `prepare_campaign` executed.
  2. Product collector pulled product attributes for `93` from MongoDB `san_pham`.
  3. Created campaign job `6aca782426e9d8acf59c4575`.
  4. Vector RAG queried ChromaDB collection `products`.
  5. Content generation: Groq LLM API generated draft script.
  6. Script validation: Regex blacklist checked and LLM audit performed.
  7. Transitioned to `AWAITING_APPROVAL` with script text:
     *"Chào mừng các bạn đến với SkinSyntax, nơi chăm sóc da trở nên dễ dàng và tuyệt vời hơn bao giờ hết..."*
* **Result:** **PASS**.

---

## 3. Flow C: Script Approval & Revision

* **API Call:** `POST /api/ai-idol/jobs/6aca782426e9d8acf59c4575/script/approve` via PHP proxy
* **Payload:**
  ```json
  {
    "script_text": "Chào mừng các bạn đến với livestream hôm nay tại SkinSyntax. Hôm nay chúng ta cùng khám phá sản phẩm dưỡng ẩm Embryolisse phục hồi da cực kỳ dịu nhẹ và hiệu quả."
  }
  ```
* **Response:**
  ```json
  {
    "job_id": "6aca782426e9d8acf59c4575",
    "ok": true,
    "script_version": 2,
    "status": "QUEUED"
  }
  ```
* **Result:** **PASS**.
