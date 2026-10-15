# AI Idol Studio

This service is isolated from chatbot, recommendation, and voice chat. It accepts a product list, generates one video segment per product, concatenates the segments into a master program, and starts the configured RTMP broadcasts at the scheduled time.

## Runtime layout

- `ai-idol-service`: Flask API and health endpoint.
- `ai-idol-agent-service`: isolated CrewAI Script Council with a writer and a critical editor. It has no access to MongoDB, ChromaDB, media files, chatbot, recommendations, or voice chat.
- `ai-idol-worker`: durable product/video generation queue (`media`, `celery`).
- `ai-idol-control-worker`: agent, campaign preparation, scheduler and watchdog tasks (`control`, `agent`).
- `ai-idol-avatar` (Windows host runtime): SadTalker turns the selected portrait and
  the generated speech into a talking presenter with lip, eye, and head motion.
- `ai-idol-broadcast-worker`: dedicated real-time FFmpeg broadcaster.
- `ai-idol-beat`: checks due schedules every few seconds and performs preflight checks.
- MongoDB stores campaign/job/checkpoint state; Redis stores queue state; the `ai_idol_storage` volume stores generated media.

## Start

Copy the AI Idol variables from `.env.example` into `.env`, set `GROQ_API_KEY`, then run:

```bash
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/start-ai-idol-avatar.ps1
docker compose up -d --build ai-idol-agent-service ai-idol-service ai-idol-worker ai-idol-control-worker ai-idol-broadcast-worker ai-idol-beat php-backend nginx
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
- After rendering, `engines/audio_mixer.py` normalizes the voice to `AI_IDOL_AUDIO_TARGET_LUFS`
  and lays a ducked background track from `assets/music/` under it. Each video gets a
  2–3 s music-only lead-in and tail and a varied entry point into the track (seeded by job id).
  During the lead-in/tail `engines/title_cards.py` shows an intro card (product photo + name) and an
  outro card (name, price, "Đặt hàng ngay"), cross-faded into the render.
  Syna (`avatar_mode=syna_3d`) gets no cards: `syna_engine` pads the voice with the same lead-in/tail
  and sends a `speech` window, so the mascot waves hello before speaking and goodbye (with a heart) after.
- Voice delivery breathes: Piper adds `AI_IDOL_PIPER_SENTENCE_SILENCE` after each sentence and
  `voice_engine` inserts a varied 0.35–0.8 s pause between script sections; subtitle timings include it. An empty music folder means voice mastering only; mix failures keep the raw render.
- RTMP targets are preflighted before airtime and each platform has independent status/retry records.
- The master program loops until `duration_minutes` is reached.
- If the CrewAI service is unavailable or Groq throttles the two-agent request,
  the main worker falls back to the existing single-agent writer. The compliance
  validator still runs and fails closed before any voice or video is generated.

## Product metrics snapshots

`core/product_metrics.py` dựng `product_metrics_snapshots` cho Agent (`agents/command_query.py` chỉ đọc). Mỗi sản phẩm catalog có một document cho mỗi cửa sổ 7 và 30 ngày, `as_of` là chuỗi `YYYY-MM-DD` (ngày hiện tại theo giờ local, loại trừ).

- `units_sold`, `net_revenue`, `previous_units_sold`: từ đơn hoàn thành (cùng định nghĩa `QuanTri::isRevenueOrder`), tính theo dòng sau khi phân bổ giảm giá, không gồm phí ship, đơn hủy bị loại. Có movement `return` trong kỳ thì để `null`.
- `viewed_sessions`, `converted_sessions`, `cart_sessions`: số phiên phân biệt từ `product_events` (PHP ghi), `converted` là tập con của `viewed`. Chỉ có số khi đã đặt `AI_IDOL_TRACKING_STARTED_ON`, cửa sổ nằm sau ngày đó, còn trong retention và mọi ngày trong cửa sổ đều có ít nhất một `product_view`. Thiếu một điều kiện thì cả ba trường là `null`.
- `null` nghĩa là chưa đo được, không phải 0. Lý do nằm ở `coverage.reasons`.

Lệnh (chỉ chạy khi được phép, DB là Atlas dùng chung; index chỉ được tạo bởi `ensure-indexes`):

```
docker compose exec ai-idol-control-worker python -m core.product_metrics ensure-indexes
docker compose exec ai-idol-control-worker python -m core.product_metrics build [--as-of YYYY-MM-DD]
docker compose exec ai-idol-control-worker python -m core.product_metrics backfill --days N   # 1..60, chỉ dựng doanh số
docker compose exec ai-idol-control-worker python -m core.product_metrics run-due
```

Celery beat chạy `ai_idol.build_product_metrics` ở phút 30 mỗi giờ (queue `control`) và tự bù tối đa 7 ngày.

Thứ tự triển khai: `ensure-indexes`, deploy PHP, sáng hôm sau đặt `AI_IDOL_TRACKING_STARTED_ON` bằng ngày kế tiếp ngày deploy, rồi `backfill --days 30`. Image đã chứa source, nên job chỉ có hiệu lực sau khi rebuild các service `ai-idol-*`.

Cảnh báo: không được tạo hay nhập view/cart từ `luot_xem`, `tuong_tac_nguoi_dung`, số đánh giá hay dữ liệu seed.
