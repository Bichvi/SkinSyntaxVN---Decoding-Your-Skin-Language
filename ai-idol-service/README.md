# AI Idol Studio

This service is isolated from chatbot, recommendation, and voice chat. It accepts a product list, generates one video segment per product, concatenates the segments into a master program, and starts the configured RTMP broadcasts at the scheduled time.

## Runtime layout

- `ai-idol-service`: Flask API and health endpoint.
- `ai-idol-agent-service`: isolated CrewAI Script Council with a writer and a critical editor. It has no access to MongoDB, ChromaDB, media files, chatbot, recommendations, or voice chat.
- `ai-idol-worker`: durable product/video generation queue.
- `ai-idol-avatar` (Windows host runtime): SadTalker turns the selected portrait and
  the generated speech into a talking presenter with lip, eye, and head motion.
- `ai-idol-broadcast-worker`: dedicated real-time FFmpeg broadcaster.
- `ai-idol-beat`: checks due schedules every few seconds and performs preflight checks.
- MongoDB stores campaign/job/checkpoint state; Redis stores queue state; the `ai_idol_storage` volume stores generated media.

## Start

Copy the AI Idol variables from `.env.example` into `.env`, set `GROQ_API_KEY`, then run:

```bash
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-ai-idol-avatar.ps1
docker compose up -d --build ai-idol-agent-service ai-idol-service ai-idol-worker ai-idol-broadcast-worker ai-idol-beat php-backend nginx
```

On this RTX 3050 Windows workstation the avatar engine intentionally runs on the
host, while the queue remains in Docker. This avoids the current Docker/WSL GPU
adapter issue. Check it with `http://localhost:7861/health`; `ok`, `cuda`, and
`model_ready` must all be `true` before accepting generation jobs.

Open the existing admin menu and choose **AI Idol Studio**. Select products, a future time, duration, and destinations. The dashboard refreshes campaign state every five seconds.

Each product stops at `AWAITING_APPROVAL` after its script passes automatic
validation. An employee can edit the text in the campaign detail and must press
**Chấp nhận & tạo video** before TTS, talking-head rendering, or composition starts.

The **Agent tự động** flow has two policies. `assisted` preserves that human
approval checkpoint. `auto_preview` lets the validated script continue to an
internal-only preview video, but never publishes it to YouTube or Facebook.
`auto_publish` is rejected unless `AI_IDOL_AGENT_ALLOW_AUTO_PUBLISH=true` is
explicitly enabled after acceptance testing.

For preview-only testing, keep only `internal` selected. For external broadcasting, set the matching RTMP URL and stream key. Never commit stream keys.

## Cost profile

- SadTalker, Piper Vietnamese TTS, FFmpeg, Redis, MongoDB, and Chroma run locally
  with no per-video API fee. Local electricity, hardware, and maintenance still apply.
- Groq is the only remote script model used by this module; there is no paid-model fallback. The Script Council uses two short sequential calls and caps both prompt data and completion size. Any third-party free quota can change, so it cannot be guaranteed as "free forever".
- `JINA_API_KEY` is optional and is not required by this Phase 1 runtime.
- `AVATAR_PROVIDER=sadtalker` is isolated to AI Idol Studio and does not alter
  chatbot, recommendation, or voice-chat services.

## Operational notes

- Schedule lead time is validated using `AI_IDOL_ESTIMATED_MINUTES_PER_PRODUCT` so large batches are not knowingly scheduled before rendering can finish.
- Each generation stage is checkpointed; retry resumes from saved artifacts.
- Long talking-head audio is rendered in eight-second GPU chunks and concatenated,
  preventing a four-GB RTX 3050 from accumulating the whole program in VRAM.
- RTMP targets are preflighted before airtime and each platform has independent status/retry records.
- The master program loops until `duration_minutes` is reached.
- If the CrewAI service is unavailable or Groq throttles the two-agent request,
  the main worker falls back to the existing single-agent writer. The compliance
  validator still runs and fails closed before any voice or video is generated.
