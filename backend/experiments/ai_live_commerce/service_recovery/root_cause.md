# AI IDOL SERVICE — ROOT CAUSE ANALYSIS

**Investigation Date:** 2026-10-11

---

## 1. Root Cause 1: Service Image Unbuilt & Containers Not Running

* **Finding:** While `ai-idol-service` and its Celery worker `ai-idol-worker` were fully defined in `docker-compose.yml`, the Docker image `skinsyntax-ai-idol:local` had never been built on the Docker host, and the containers were never launched.
* **Mechanism:** 
  1. The PHP backend container (`skinsyntax-php`) attempted to send proxy cURL requests to `http://ai-idol-service:5003`.
  2. Because the container `skinsyntax-ai-idol` did not exist on `skinsyntax-network`, the Docker internal DNS resolver (`127.0.0.11`) could not resolve the hostname `ai-idol-service`.
  3. This threw the exact error:
     `Could not resolve host: ai-idol-service (Domain name not found)`
  4. Any API call (`admin_ai_idol_campaigns`, `admin_ai_idol_create_campaign`, `admin_ai_idol_agent_runs`) failed with HTTP 502.

---

## 2. Root Cause 2: Celery Worker Absence for Background Job Processing

* **Finding:** All AI Idol asynchronous jobs (product snapshot building, RAG knowledge retrieval, LLM script generation, Piper TTS voice generation, video composition) are managed via Celery tasks (`prepare_campaign`, `generate_job`, `process_agent_run`).
* **Mechanism:**
  Even if the Flask web API were running, without `ai-idol-worker` active on `redis://redis:6379/2`, submitted tasks would remain queued in Redis indefinitely and campaigns would stay stuck in `PENDING` or `GENERATING`.

---

## 3. Root Cause 3: Frontend Hanging and Unhandled Client Timeouts

* **Finding:** In `frontend/public/assets/js/ai-idol-studio.js`, the `api()` helper function used `fetch(url, options)` without an `AbortSignal` timeout.
* **Mechanism:**
  1. When cURL inside PHP stalled for 30–60 seconds on DNS resolution timeout, the frontend browser connection also hung.
  2. The wizard submit button `createButton` stayed disabled with text `"Đang chuẩn bị…"`.
  3. When the request eventually failed, the empty state in the library and agent list displayed an error message with no explicit Retry button, forcing users to refresh the entire browser page.
