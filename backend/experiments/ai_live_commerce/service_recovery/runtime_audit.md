# AI IDOL SERVICE — RUNTIME AUDIT REPORT

**Date:** 2026-10-11  
**Target Environment:** Docker `skinsyntax` project (Windows Host / WSL2 Engine)

---

## 1. Initial State (`docker compose ps`)

Prior to recovery, the running containers under Docker Compose were:

| Container Name | Service Name | Image | Status | Ports |
| :--- | :--- | :--- | :--- | :--- |
| `skinsyntax-ai` | `ai-service` | `skinsyntax-ai-service` | Up (healthy) | `0.0.0.0:5001->5001` |
| `skinsyntax-chromadb` | `chromadb` | `chromadb/chroma:0.5.20` | Up (healthy) | `0.0.0.0:8000->8000` |
| `skinsyntax-mongo` | `mongodb` | `mongo:7` | Up (healthy) | `0.0.0.0:27018->27017` |
| `skinsyntax-nginx` | `nginx` | `nginx:1.27-alpine` | Up | `0.0.0.0:80->80` |
| `skinsyntax-php` | `php-backend` | `skinsyntax-php-backend` | Up | `9000` |
| `skinsyntax-redis` | `redis` | `redis:7-alpine` | Up (healthy) | `0.0.0.0:6380->6379` |

**Observation:** `ai-idol-service` (`skinsyntax-ai-idol`) and `ai-idol-worker` were completely absent from `docker compose ps` and `docker ps -a`.

---

## 2. Docker Compose Configuration & Networks

- **Configuration File:** `docker-compose.yml`
- **Network:** `skinsyntax-net` (driver: bridge, name: `skinsyntax-network`, subnet: `172.18.0.0/16`)
- **Defined Services for AI Idol:**
  1. `ai-idol-service` (container: `skinsyntax-ai-idol`, port: `5003`, command: `gunicorn --bind 0.0.0.0:5003 --workers 1 --threads 4 --timeout 120 app:app`)
  2. `ai-idol-worker` (container: `skinsyntax-ai-idol-worker`, command: `celery -A celery_app:celery_app worker --queues=celery,agent,llm,gpu,media`)
  3. `ai-idol-agent-service` (container: `skinsyntax-ai-idol-agent`, port: `5013`)
  4. `ai-idol-broadcast-worker` (container: `skinsyntax-ai-idol-broadcast`)
  5. `ai-idol-beat` (container: `skinsyntax-ai-idol-beat`)

---

## 3. PHP Container & DNS Resolution Audit

- Inside `skinsyntax-php`:
  - `AI_IDOL_ENDPOINT` environment variable was set to: `http://ai-idol-service:5003`.
  - DNS resolution check (`getent hosts ai-idol-service`): **FAILED (exit code 1)**.
  - Reason: Docker's embedded DNS server (`127.0.0.11`) only registers active containers attached to `skinsyntax-network`. Since `skinsyntax-ai-idol` was never started, DNS lookups failed with `Could not resolve host: ai-idol-service (Domain name not found)`.

---

## 4. Source Code and Dockerfile Verification

- Dockerfile: `Dockerfile.ai-idol` (Python 3.11-slim, PyTorch CPU, sentence-transformers, Piper TTS Vietnamese voice ONNX `/opt/piper/vi_VN-vais1000-medium.onnx`).
- Source directory: `ai-idol-service/` containing complete Flask application (`app.py`), Celery task queue (`tasks.py`), pipeline state machine (`core/pipeline.py`), and media engines.
