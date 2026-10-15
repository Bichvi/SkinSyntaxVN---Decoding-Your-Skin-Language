# AI IDOL SERVICE — CHANGED FILES & INFRASTRUCTURE ACTIONS

**Date:** 2026-10-11

---

## 1. Infrastructure Actions

1. **Docker Image Build:**
   - Command: `docker compose build ai-idol-service`
   - Built: `skinsyntax-ai-idol:local` (Image ID: `a73410f82c19`, base: Python 3.11-slim, PyTorch CPU, sentence-transformers model cached, Piper Vietnamese TTS ONNX cached).
2. **Container Activation:**
   - Command: `docker compose up -d ai-idol-service`
   - Command: `docker compose up -d ai-idol-worker`
   - Started and healthy on `skinsyntax-network`:
     - `skinsyntax-ai-idol` (HTTP API port `5003`)
     - `skinsyntax-ai-idol-worker` (Celery background worker connected to `redis://redis:6379/2`)

---

## 2. Source Code Changes

### [frontend/public/assets/js/ai-idol-studio.js](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/public/assets/js/ai-idol-studio.js)

1. **`api()` Function (Lines 232–257):**
   - Added `AbortController` timeout guard (30 seconds for standard queries, 60 seconds for multipart file uploads).
   - Clear user-facing timeout error message: `"Yêu cầu kết nối quá thời gian chờ (timeout). Vui lòng thử lại."`
2. **`refreshAgentRuns()` Function (Lines 290–315):**
   - Added an explicit Retry button (`.retry-agent-btn`) in the empty/error state to re-trigger agent loading without reloading the browser.
3. **`studioForm` Submission (Lines 378–395):**
   - Added validation check to ensure `result.campaign_id` exists before announcing success.
   - Guaranteed that `createButton` always re-enables and resets text back to `"Tạo kịch bản"` in the `finally` block even if an upload or API request fails.
4. **`refreshCampaigns()` Function (Lines 430–455):**
   - Added an explicit Retry button (`.retry-campaigns-btn`) in the library empty/error state.
   - Displayed clear connection loss indicator: `"<span class=\"sync-dot\"></span> Mất kết nối AI Service"` when offline.
