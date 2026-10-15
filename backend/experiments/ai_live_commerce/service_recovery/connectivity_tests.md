# AI IDOL SERVICE — CONNECTIVITY TESTS

**Execution Date:** 2026-10-11  
**Test Harness:** Inside `skinsyntax-php` and external browser HTTP requests

---

## 1. Direct Healthcheck

* **Endpoint:** `GET http://ai-idol-service:5003/api/health`
* **Test:** `docker exec skinsyntax-php curl -s http://ai-idol-service:5003/api/health`
* **Response:**
  ```json
  {
    "agent_enabled": true,
    "mongo": "ok",
    "ok": true,
    "service": "ai-idol"
  }
  ```
* **Result:** **PASS** (HTTP 200 OK, MongoDB ping successful, Agent enabled).

---

## 2. DNS Resolution

* **Test:** `docker exec skinsyntax-php getent hosts ai-idol-service`
* **Resolution:** `172.18.0.8 ai-idol-service`
* **Result:** **PASS** (Docker internal DNS resolves hostname to container IPv4).

---

## 3. PHP Controller cURL Proxy Test

* **Controller:** `AdminAiIdolController::callFlaskAPI()`
* **Target:** `http://ai-idol-service:5003`

### Test A: List Campaigns
* **Route:** `GET /api/ai-idol/campaigns?limit=5`
* **Response:** `{"campaigns":[...],"ok":true}`
* **Result:** **PASS** (HTTP 200).

### Test B: List Agent Runs
* **Route:** `GET /api/ai-idol/agent/runs?limit=5`
* **Response:** `{"ok":true,"runs":[...]}`
* **Result:** **PASS** (HTTP 200, returned active runs).

---

## 4. Redis & Celery Message Bus Connectivity

* **Broker:** `redis://redis:6379/2`
* **Queues Active:** `agent`, `celery`, `gpu`, `llm`, `media`
* **Worker Status:**
  `[2026-10-10 17:31:01,064: INFO/MainProcess] celery@9b6408a870b2 ready.`
* **Result:** **PASS**.
