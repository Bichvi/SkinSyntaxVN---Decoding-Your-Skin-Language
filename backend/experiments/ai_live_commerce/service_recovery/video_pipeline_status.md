# AI IDOL SERVICE — VIDEO PIPELINE STATUS

**Date:** 2026-10-11

---

## 1. Actual Video Pipeline Execution Results

Upon script approval of Job `6aca782426e9d8acf59c4575`, the background Celery worker executed the end-to-end media generation pipeline:

### Step 1: Voice Generation (TTS)
* **Provider:** Piper TTS (model: `/opt/piper/vi_VN-vais1000-medium.onnx`)
* **Output:** `/app/storage/audio/voice_6aca782426e9d8acf59c4575.wav`
* **Duration:** 8.35 seconds
* **Status:** **PASS**

### Step 2: Avatar Video Generation
* **Provider:** `MockAvatarProvider` (native FFmpeg inside Docker container)
* **Output:** `/app/storage/output/avatar_6aca782426e9d8acf59c4575.mp4`
* **Status:** **PASS**

### Step 3: Video Compositing & Subtitling
* **Provider:** `engines/video_composer.py`
* **Subtitles:** Generated SRT `/app/storage/output/sub_6aca782426e9d8acf59c4575.srt`
* **Product Card:** Downloaded and scaled product image from CDN
* **FFmpeg Command:**
  `ffmpeg -y -i /app/storage/output/avatar_... -i /app/storage/output/prod_... -filter_complex ... subtitles=...`
* **Output:** `/app/storage/output/final_6aca782426e9d8acf59c4575.mp4`
* **Status:** **PASS**

### Step 4: Campaign Assembly
* **Provider:** `engines/program_composer.py`
* **Output File:** `/app/storage/programs/campaign_6aca7822dec4b62c4301b1bf.mp4`
* **File Size:** `244,284 bytes` (Valid H.264 / AAC MP4 file)
* **Status:** **PASS**

### Step 5: Web Video Streaming & Browser Delivery
* **Endpoint:** `GET /index.php?r=admin_ai_idol_stream_campaign&campaign_id=6aca7822dec4b62c4301b1bf`
* **HTTP Status:** 200 OK
* **Response Headers:**
  - `Content-Type: video/mp4`
  - `Content-Length: 244284`
  - `Accept-Ranges: bytes`
  - `Content-Disposition: inline; filename=campaign_6aca7822dec4b62c4301b1bf.mp4`
* **Status:** **PASS**

---

## 2. Dependency Audit & Missing External Providers

While the core container pipeline (Piper TTS + FFmpeg composition + MP4 assembly + video streaming) is **100% operational**, the following external optional renderers require external services:

1. **Syna 3D Mascot Renderer (`syna_3d`):**
   - URL: `http://host.docker.internal:7862/health`
   - Requirement: Requires the local renderer process to be running on the host via `scripts/start-ai-idol-syna.ps1`. When absent, jobs configured as `syna_3d` will report: `"Syna chưa sẵn sàng. Chạy scripts/start-ai-idol-syna.ps1..."`.
2. **GPU Deep Learning Lip-Sync (`sadtalker` / `musetalk`):**
   - URL: `http://host.docker.internal:7861` (`AI_IDOL_AVATAR_SERVICE_URL`)
   - Requirement: Reserved for dedicated GPU server running SadTalker or MuseTalk. When absent, the system automatically falls back to `MockAvatarProvider` (looping avatar/background with accurate audio sync).
