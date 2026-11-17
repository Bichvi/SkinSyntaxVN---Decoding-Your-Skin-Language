# Bàn giao cho Codex: SkinSyntaxVN, trọng tâm Livestream

Ngày viết: 2026-11-17 · Nhánh: `syna_10_11` · HEAD: `1df09bb` · Nhánh chính: `main`
Mục đích: Codex đọc file này là nắm được dự án đã làm gì và livestream đang chạy ra sao, để nâng cấp livestream mà không phải đọc toàn bộ code. Mọi đường dẫn và tên hàm dưới đây đã đối chiếu với code ở HEAD (cộng phần chưa commit ghi ở mục 8).

Không có bí mật trong file này. Chỉ nêu tên biến môi trường, không nêu giá trị.

---

## 0. Đọc trước khi sửa (luật bắt buộc)

1. Đọc `AGENTS.md` ở gốc repo. Tóm tắt:
   - Chạy GitNexus impact trước khi sửa symbol: `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`. Kết quả HIGH/CRITICAL thì báo người dùng trước. `UNKNOWN` không có nghĩa là an toàn.
   - Chạy `detect-changes` trước khi commit. Không commit, push hay deploy khi chưa được yêu cầu.
2. **DB là MongoDB Atlas thật, giữ Atlas, không chuyển về Mongo local.**
3. **Không chạy hàng loạt script trong `backend/tests/`**, nhiều file ghi DB thật. Các file trong `backend/tests/unit/` là test thuần, an toàn.
4. Test `ai-idol-service`: đặt rỗng `MONGO_URI` và các khóa API trước khi chạy pytest, vì 53 test gọi `get_db()` thật.
5. E2E live (`e2e/live-*.e2e.cjs`) ghi vào DB thật và lên sóng LiveKit thật. Chỉ chạy trên phiên thử (xem mục 7).
6. Tài liệu tách riêng đặt tên `update_<tên_việc>.md`. `update.md` là file tổng, thêm một dòng trỏ tới file mới ở đầu file đó.
7. Người dùng muốn trả lời bằng **tiếng Việt**.

---

## 1. Dự án là gì

Sàn TMĐT mỹ phẩm chăm sóc da, có trợ lý AI tên **Syna**.

| Thư mục | Công nghệ | Vai trò |
|---|---|---|
| `backend/` | PHP 8.2 MVC (không framework), MongoDB driver 2.5.1 | Controller, model, service, API |
| `frontend/` | View PHP + JS/CSS thuần, không bundler | Giao diện khách và admin |
| `ai-service-flask/` | Flask, LangChain/LangGraph, ChromaDB, Neo4j | Chatbot, RAG, tìm kiếm, gợi ý, đặt hàng qua chat |
| `ai-idol-service/` | Flask + Celery + Redis | AI Idol Studio: viết kịch bản, TTS, dựng video Syna 3D, phát live, đăng video sản phẩm |
| `livekit-voice-agent/` | Python, LiveKit | Co-host giọng nói (tùy chọn, profile `voice`) |
| `voice-service/` | Python | TTS cho voice agent |
| Hạ tầng | Docker Compose, Nginx, Redis, ChromaDB, Neo4j, R2 (Cloudflare), ngrok | |

Chạy local: `docker compose up -d`, web ở `http://localhost:8080`. Public tạm qua ngrok (xem `update_deploy_cicd.md`).

**Có 3 file router `index.php`**, cùng một bảng `switch ($_GET['r'])`:
- `backend/public/index.php`: **đây là file Docker phục vụ** (nginx → `/var/www/html/public/index.php`, `./backend` mount vào `/var/www/html`).
- `frontend/public/index.php` và `index.php` ở gốc (dùng khi chạy XAMPP).
- Thêm route mới thì thêm vào cả ba. Hiện ba file đã lệch nhau (ví dụ `ai_idol_live_video` không có trong `index.php` gốc).

---

## 2. Các việc đã làm (toàn dự án)

Chi tiết từng việc nằm trong file `update_*.md` tương ứng ở gốc repo. Chỉ đọc khi cần.

| Mảng | Đã làm | Tài liệu |
|---|---|---|
| AI Idol Studio | Pipeline kịch bản → kiểm duyệt → tự sửa (self-healing) → TTS → dựng video Syna 3D, chế độ AUTO/MANUAL | `update.md` (mục 1–15), `update_ai_idol_script_quality.md`, `update_autopilot_no_stop.md` |
| AI Idol: live | Tách "Livestream" và "Video sản phẩm", thư viện clip cho show 30–60 phút, phát Syna trên `?r=live`, xem lại trên R2 | `update_ai_idol_tach_livestream_video.md`, `update_ai_idol_live_replay.md`, `docs/superpowers/specs/2026-11-10-ai-idol-live-*` |
| **Livestream người thật** | LiveKit, studio cho host, chat, thả tim, ghim sản phẩm, hàng đợi deal, mua trong live, ghi hình, đếm người xem, tạm dừng | **file này** |
| Chatbot | RAG, hỗ trợ chính sách, lọc theo ràng buộc (HARD/SOFT), chụp bảng thành phần, đặt hàng qua chat + OTP email, thẻ hóa đơn | `update_chatbot_dathang.md`, `update_chatbot_otp_dathang.md`, `update_chup_thanh_phan.md`, `update_store_support_chatbot.md`, `update_catalog_constraints.md` |
| Tìm kiếm / gợi ý | Smart search, Neo4j, sản phẩm tương tự, gợi ý trang chủ, why-fit | `update_smart_search_neo4j.md`, `update_neo4j_search_recommendation.md`, `update_similar_products.md`, `update_home_graph_recommend.md`, `update_why_fit_detail.md` |
| Dữ liệu | Nhãn công dụng duyệt tay, chất lượng dữ liệu tìm kiếm, phân loại da | `update_tag_curation.md`, `update_search_data_quality.md`, `update_nhan_loai_da.md` |
| Khác | Xung đột thành phần giỏ hàng, giới hạn đăng nhập/OTP, đếm lượt xem qua Redis, xem video nhận xu, deploy/CI | `update_cart_conflicts.md`, `update_auth_rate_limit.md`, `update_luot_xem.md`, `update_video_phan_thuong.md`, `update_deploy_cicd.md` |
| Kiểm kê công nghệ | Bản đồ toàn bộ công nghệ, kèm bằng chứng `file:dòng` | `update_technology_inventory.md` |

---

## 3. Livestream có hai loại, dùng chung trang `?r=live`

| | A. Live người thật (LiveKit) | B. Live AI Idol (Syna) |
|---|---|---|
| Nguồn dữ liệu | Collection `phien_live` | Collection `ai_idol_campaigns` (status `LIVE`, `platforms: internal`) |
| Video | WebRTC qua LiveKit Cloud | File video master đã dựng sẵn, phát lặp vòng, đồng bộ theo giờ bắt đầu |
| Tạo phiên | Admin `?r=admin_lives` | AI Idol Studio `?r=admin_ai_idol` |
| Xem lại | File ghi từ trình duyệt host, lưu volume `uploads` | Video master tải lên R2 (`live-replays/`) |
| Id trên trang | `ma_phong` (số) | `ai-<campaign_id>` |

Phần lớn việc nâng cấp sẽ rơi vào loại A. Loại B nằm ở mục 6.

---

## 4. Live người thật: kiến trúc

```
Admin ?r=admin_lives (QuanTriController + frontend/views/admin/lives.php)
   tạo/sửa/xóa phiên, đổi trạng thái, ghim sản phẩm, hàng đợi deal
        │
Host mở ?r=live&id=<ma_phong>&studio=1   (chỉ admin/nhanvien)
   live.php (JS inline) ── api_live_token ──► JWT LiveKit (canPublish)
        │ publish camera+mic ─────────────────► LiveKit Cloud (room skinsyntax_room_<id>)
        │ api_live_stream_state (live/heartbeat 10s/ended/interrupted)
        │ MediaRecorder → api_upload_live_recording (chunk 10s)
        │ publishData {type: pause/resume/...} ───► người xem
        │
Người xem mở ?r=live&id=<id>
   api_live_token (chỉ khi status=live) → subscribe LiveKit
   api_live_ping (đếm người xem), api_live_chat / api_live_chat_history (poll 6s)
   api_live_reaction, api_live_products (poll ghim), api_live_add_to_cart
        │
LiveController ──► PhienLive (Mongo: phien_live, phien_live_chats,
                                phien_live_viewers, phien_live_reactions)
               ──► AI_CHAT_ENDPOINT (Flask /api/chat/auto) để AI trả lời chat
```

### 4.1 File chính

| File | Nội dung |
|---|---|
| `backend/app/controllers/LiveController.php` (~1540 dòng) | Mọi API live + trang `index()` |
| `backend/app/models/PhienLive.php` (~400 dòng) | Lưu trữ, máy trạng thái, chat, reaction, deal |
| `frontend/views/live.php` (~740 dòng) | HTML + CSS + **toàn bộ JS host/viewer viết inline** (chưa tách file) |
| `backend/app/controllers/QuanTriController.php` dòng ~1749–1900 | `adminLives`, `adminLiveCreate/Edit/Status/Delete/UpdateRecording` |
| `frontend/views/admin/lives.php` | Trang admin: danh sách, modal tạo, modal "Sản phẩm" (hàng đợi deal), báo cáo |
| `scripts/ensure_live_indexes.php` | Tạo index Mongo cho các collection live (chạy tay, chạy được trong container php) |
| `livekit-voice-agent/agent.py` | Co-host giọng nói: đọc comment → hỏi AI → gọi TTS → đẩy audio vào room |
| Client LiveKit | `livekit-client@2.1.2` từ jsDelivr, nạp trong `live.php` |

### 4.2 Route (`?r=...`)

| Route | Hàm | Quyền | Ghi chú |
|---|---|---|---|
| `live` | `index()` | ai cũng xem | Thêm `&studio=1` cho host (admin/nhanvien) |
| `api_live_token` | `apiLivekitToken` | khách chỉ khi `status=live`; staff luôn được, có `canPublish` | JWT tự ký HS256, TTL 1 giờ, room `skinsyntax_room_<ma_phong>` |
| `api_live_stream_state` | `apiLiveStreamState` | staff, đăng nhập | `state` ∈ offline/live/interrupted/ended/heartbeat. live/heartbeat phải kèm `publisher_token` hợp lệ. Mất session → 401 `login_required` |
| `api_live_ping` | `apiLivePing` | ai cũng gọi | Upsert `phien_live_viewers`, tăng `luot_xem` khi người xem mới |
| `api_live_active_viewers` | `apiLiveActiveViewers` | | Số người xem trong 45 s gần nhất (cache 5 s) |
| `api_live_chat` | `apiLiveChat` | khi `live` | Giới hạn 5 tin / 20 s theo session. Lệnh "chốt đơn / mua ngay / đặt hàng" thêm sản phẩm ghim vào giỏ. Gọi AI trả lời (timeout 4 s), có câu trả lời dựng sẵn theo từ khóa khi AI lỗi |
| `api_live_chat_history` | `apiLiveChatHistory` | | `mode` all/featured/qa, `after` (ms) để lấy tăng dần |
| `api_live_reaction` | `apiLiveReaction` | khi `live` | |
| `api_live_products` | `apiLiveProducts` | | Sản phẩm ghim + giá live, viewer poll để đổi ghim |
| `api_search_catalog_products` | `apiSearchCatalogProducts` | staff | Tìm sản phẩm để ghim/thêm deal |
| `admin_live_pin_product` | `adminLivePinProduct` | staff | Ghim một sản phẩm (giá live, thời lượng, tồn kho deal ≥ 1) |
| `admin_live_add_deal` / `admin_live_delete_deal` | | staff | Hàng đợi deal `danh_sach_deal[]` |
| `api_live_add_to_cart` | `apiLiveAddToCart` | | Giá live lấy từ server, trình duyệt không tự đặt giá |
| `api_upload_live_recording` | `apiUploadLiveRecording` | staff | Có `upload_id` → upload chia khúc (`handleChunkedRecordingUpload`). Giới hạn 1 GB, chỉ webm/mp4/ogg. Lưu `backend/public/uploads/live_recordings/` |
| `admin_lives`, `admin_live_create/edit/status/delete/update_recording` | `QuanTriController` | staff | CRUD phiên |

### 4.3 Dữ liệu (Mongo)

`phien_live` (một tài liệu một phiên):
- Định danh: `ma_phong` (số tăng dần, dùng làm id ngoài), `id`, `_id`. `idFilter()` khớp cả ba.
- Nội dung: `tieu_de`, `streamer`, `khung_gio_bat_dau/ket_thuc`, `bat_ai_cohost`, `server_livekit_url` (bỏ qua, xem 4.5).
- Bán hàng: `ma_san_pham_ghim`, `gia_uu_dai_live`, `so_luong_kho_deal`, `deal_bat_dau/ket_thuc`, `danh_sach_deal[]` (`deal_id`, `ma_san_pham`, `gia_uu_dai_live`, `so_luong_kho_deal`, `khung_gio_*`, `trang_thai`).
- Trạng thái: `trang_thai`, `stream_state`, `stream_last_seen` (epoch), `stream_started_at`.
- Ghi hình: `recording_status`, `url_ban_ghi`, `recording_mime/size/error`.
- Chỉ số: `luot_xem`, `reaction_count`, `mat_do_nguoi_xem_dinh`, `tong_doanh_thu`, `tong_don_hang`, `tong_san_pham_ban`, `ty_le_chot_don`, `top_san_pham`, `tom_tat_phien_live`. Các trường doanh thu/đơn chỉ được đọc để hiển thị. **Không có code nào ghi chúng** (đã grep toàn `backend/`, chỉ thấy khởi tạo bằng 0).

Collection phụ: `phien_live_chats` (`room_id`, `sender_*`, `message`, `is_ai/is_order/is_question/is_pinned`, `timestamp_ms`), `phien_live_viewers` (`room_id`, `session_id`, `last_seen`), `phien_live_reactions`.

Lưu ý driver: mảng lồng trả về dạng `BSONArray`, không phải array PHP. `normalizeDoc()` đã xử lý cho `danh_sach_deal`. Thêm trường mảng mới thì xử lý tương tự.

### 4.4 Máy trạng thái

Trạng thái hợp lệ: `scheduled, ready, live, interrupted, ended, processing, replay_ready, recording_failed`. Giá trị cũ tiếng Việt (`chuamoi`, `danglive`, `ketthuc`, `tamdung`) được ánh xạ trong `normalizeInputStatus()`.

- `resolveStatus()` quyết định trạng thái hiển thị: `live` chỉ khi `stream_state=live` **và** heartbeat trong 60 s gần nhất (`STREAM_FRESH_SECONDS`). Host mất kết nối thì phiên tự hiện `ready`.
- Host: chọn camera + mic → "Bắt đầu phát" → `state=live` → heartbeat 10 s (`touchStream` chỉ làm mới phiên còn live, nên heartbeat trễ không làm sống lại phiên đã kết thúc).
- Kết thúc: `state=ended` → `recording_status=processing` → upload xong thì `saveRecording()` → `replay_ready`. Không có bản ghi → `failRecording()` → `recording_failed`.
- Phiên `ended/replay_ready/recording_failed` không phát lại được (409).
- Đóng tab khi đang phát: `sendBeacon` gửi `interrupted`.
- Token publisher hết hạn sau 1 giờ: heartbeat nhận 403 thì tự xin token mới và gửi lại.

### 4.5 Các quyết định đã chốt (đừng phá)

- `LIVEKIT_URL` trong `.env` luôn thắng `server_livekit_url` lưu trong phiên. Phiên cũ lưu URL của project LiveKit khác, dùng nó sẽ bị 401.
- Kiểm tra collection Mongo bằng `is_object()` hoặc `instanceof MongoDB\Database`, **không dùng `isset($db->coll)`** (luôn false, từng làm đếm người xem không chạy).
- Tắt mic/cam chỉ **mute** track, không unpublish, nên phiên vẫn live. Camera publish dưới dạng `LocalVideoTrack` do người dùng cấp, để mute không dừng MediaStreamTrack (nếu dừng thì bản ghi bị đứng hình). Đổi lại, đèn camera vẫn sáng khi "tắt".
- "Tạm dừng" mute cả hai track, pause MediaRecorder (bản ghi bỏ đoạn tạm dừng) và gửi tín hiệu qua data channel. Người vào sau suy ra trạng thái từ track bị mute.
- MediaRecorder chỉ bắt đầu khi đã có cả video và audio track (thêm track sau làm recorder dừng).
- Lỗi upload chunk giữa chừng **không** gọi `failRecording()`. Chỉ lần chốt cuối mới được.
- PHP session lưu ở volume `skinsyntax-php-sessions` (`docker/php/zz-skinsyntax-session.ini`), nên restart container không đăng xuất host.
- Giá live và sản phẩm được mua luôn lấy từ server (`ma_san_pham_ghim` hoặc SKU trong `danh_sach_deal`), không tin trình duyệt.
- Trang `?r=live` chỉ hiện dữ liệu thật, không seed phòng demo. URL mẫu của Google bị coi là bản ghi lỗi (`normalizeDoc`).

### 4.6 Co-host giọng nói (`livekit-voice-agent`)

- Bật: `docker compose --profile voice up -d livekit-voice-agent` (kéo theo `voice-service`). Tắt: `docker compose stop livekit-voice-agent`.
- Luồng: hàng đợi comment (`voice_queue.py`) → gọi AI lấy câu trả lời → POST `TTS_SERVICE_URL` lấy PCM → publish audio vào room.
- Agent chờ `ai-service` và `voice-service` healthy (backoff) trước khi chạy.
- Cờ `bat_ai_cohost` của phiên chỉ được lưu, **không điều khiển agent** (agent và controller không đọc cờ này). Chưa có E2E nào chạy agent, nên chưa rõ nó còn chạy đúng với luồng studio hiện tại.

---

## 5. Kiểm thử live

| Loại | Lệnh | Ghi chú |
|---|---|---|
| PHP thuần | `php backend/tests/unit/live_logic_test.php` | Không chạm DB |
| PHP thuần | `php backend/tests/unit/live_pin_queue_test.php` | Mới, chưa commit. Fake collection, không chạm DB |
| PHP thuần | `php backend/tests/ai_idol_replay_sessions_test.php` | |
| JS | `node --test frontend/public/assets/js/tests/*.test.js` | Phải dùng glob. Có `ai-idol-live.test.js` |
| Cú pháp | `php -l <file>` | |
| E2E vòng đời | `node e2e/live-stream.e2e.cjs [out_dir]` | Lên sóng phiên `E2E_LIVE_ID` (mặc định 8) rồi **kết thúc nó**. Chỉ dùng phiên thử. `E2E_LIVE_ROLE=staff` để host bằng tài khoản nhân viên |
| E2E đầy đủ | `node e2e/live-full.e2e.cjs [out_dir]` | Mới, chưa commit. Tự tạo phiên "E2E LIVE <timestamp>" rồi tự xóa. Các mục A–H: admin, hàng đợi deal, người xem, lên sóng, đổi ghim, chat/rate-limit/reaction, mua, kết thúc. Ảnh ở `report/live-full-e2e/` |

E2E cần Playwright qua `NODE_PATH` (đường dẫn ghi ở đầu mỗi file e2e), Chromium dùng camera/mic giả, và tài khoản `E2E_ADMIN_*`, `E2E_STAFF_*`, `E2E_CUSTOMER_*` trong `.env`. Thiếu tài khoản thì exit 2 (NOT RUN).
Sau E2E vẫn còn sót lại trong DB: chat, reaction, viewer của phiên đã xóa, sự kiện `add_to_cart`, file bản ghi.

Kết quả gần nhất có ghi nhận: `live-stream.e2e` 26/26 PASS (admin và staff, 2026-11-16) và các bước mute/tạm dừng (2026-11-17, commit `b316b97`). `live-full.e2e` sau các sửa ở mục 8: **chưa có kết quả được ghi lại**. Hãy chạy lại trước khi dựa vào.

---

## 6. Live AI Idol (Syna) trên trang `?r=live`

- `LiveController::findAiIdolLive()`: lấy chiến dịch `status=LIVE`, `platforms=internal`, có `master_video_path`, `ends_at` còn ở tương lai.
- Video: route `ai_idol_live_video` → `streamAiIdolLiveVideo()`, proxy file master bằng cURL. Chỉ phục vụ khi chiến dịch đang LIVE, ngoài ra trả 404.
- `frontend/public/assets/js/ai-idol-live.js`: `livePosition(now, started, duration)` tua video tới đúng vị trí "đang phát" và phát lặp vòng. Có test Node.
- Sản phẩm đánh số theo thứ tự show (`aiIdolShowNumbers`, `orderAiIdolProducts`), chỉ với chiến dịch kiểu `clip_library_v1`.
- Xem lại: `findAiIdolReplays()` + `backend/app/services/AiIdolReplaySessions.php` biến chiến dịch có `replay.video_url` thành thẻ `ai-<id>` trạng thái `replay_ready` (tối đa 30). Phiên `ai-…` không poll `api_live_products`.
- Phía Python: `ai-idol-service/engines/broadcast_engine.py` (phát), `engines/replay_publisher.py` (tải R2, task `ai_idol.publish_campaign_replay`), `engines/media_publisher.release_master_video` (chỉ xóa file gốc khi đã có bản xem lại và clip sản phẩm).
- Syna live **không** có chat AI thời gian thực, không có LiveKit. Đây là phát lại video dựng sẵn.

---

## 7. Biến môi trường liên quan live

| Biến | Dùng ở |
|---|---|
| `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` | PHP ký token, voice agent. Giá trị mẫu (`your_key`, `devkey`, `secret`…) bị coi là chưa cấu hình → 503 `livekit_not_configured` |
| `AI_CHAT_ENDPOINT` | AI trả lời chat live (compose: `http://ai-service:5001/api/chat/auto`) |
| `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_PUBLIC_BASE_URL` | Bản xem lại Syna |
| `E2E_BASE_URL`, `E2E_LIVE_ID`, `E2E_LIVE_HOLD_MS`, `E2E_LIVE_ROLE`, `E2E_ADMIN_*`, `E2E_STAFF_*`, `E2E_CUSTOMER_*` | E2E |

---

## 8. Thay đổi đang dở (CHƯA COMMIT trên `syna_10_11`)

Liên quan live, sửa các lỗi mà `e2e/live-full.e2e.cjs` bắt được:
- `LiveController::apiLiveAddToCart`: bấm "+ Giỏ" trên deal trong hàng đợi (không phải món đang ghim) trước đây luôn thêm món đang ghim. Nay cho phép SKU ghim **hoặc** SKU có trong `danh_sach_deal`. SKU lạ hoặc thiếu thì quay về món ghim (E2E G3/G5).
- `PhienLive::normalizeDoc`: đọc `danh_sach_deal` dạng `BSONArray` đúng cách (trước đây ra mảng rỗng).
- `live.php`:
  - Studio render sẵn ô chat (ẩn) trước khi lên sóng và mở ra khi `goLive()`. Trước đây host phải tải lại trang mới chat được, mà tải lại thì rớt luồng phát (E2E D9).
  - Bỏ handler submit riêng của nút giỏ trong live. Handler chung ở `layouts/footer.php` đã gửi form, nên trước đây mỗi lần bấm thêm 2 sản phẩm. Nay chỉ đổi nút sang "Đã thêm" khi nhận sự kiện `skinsyntax:cart-added`.
- File mới: `e2e/live-full.e2e.cjs`, `backend/tests/unit/live_pin_queue_test.php`, `report/live-full-e2e/*.png`.

Không liên quan live (đừng gộp chung commit):
- `ai-idol-service`: trạng thái job mới `WAITING_LLM` khi Groq hết hạn mức token. `core/watchdog.resume_waiting_llm` tiếp tục từng lô nhỏ, biến `AI_IDOL_LLM_WAIT_MAX_HOURS` và `AI_IDOL_LLM_RESUME_BATCH`. Kèm `providers/llm_provider.py`, `tests/test_llm_rate_limit.py`, nhãn trong `studio-core.js` và `studio-logic.js`.
- `frontend/views/chitiet.php`: rút gọn đoạn tóm tắt mô tả sản phẩm.
- `ai-service-flask`: script và báo cáo RAGAS (`update_ragas_benchmark.md`). File rác `ai-service-flask/nul` có thể xóa.

---

## 9. Hạn chế hiện có và gợi ý nâng cấp livestream

Sắp theo thứ tự đáng làm. Mỗi mục ghi chỗ cần sửa.

1. **Chat dùng poll HTTP 6 s** (`loadChat` trong `live.php`, `apiLiveChatHistory`). Có thể đẩy tin qua LiveKit data channel (`publishData` đã dùng cho tín hiệu pause), Mongo vẫn là nơi lưu chính. Nhớ giữ rate limit phía server.
2. **Rate limit chat theo PHP session** (5 tin / 20 s): khách vãng lai đổi session là qua được. Nên chuyển sang Redis theo IP + identity (tham khảo `AuthThrottle` ở `update_auth_rate_limit.md`).
3. **Ghi hình chạy trên trình duyệt host** (MediaRecorder → upload từng khúc → volume `uploads`, tối đa 1 GB). Host rớt mạng là mất đoạn ghi, và file không lên R2. Hướng nâng cấp: LiveKit Egress ghi phía server rồi đẩy R2 (giống bản xem lại Syna).
4. **Chỉ số doanh thu/đơn của phiên** (`tong_doanh_thu`, `tong_don_hang`, `ty_le_chot_don`, `top_san_pham`) mới chỉ được đọc. Cần gắn nguồn đơn hàng từ live (ví dụ lưu `live_id` vào giỏ/đơn khi mua qua `api_live_add_to_cart`) và tính khi phiên kết thúc.
5. **AI trả lời chat**: phần dự phòng là chuỗi `strpos` theo từ khóa trong `apiLiveChat`. Có thể chuyển sang agent chat v2 (`AI_AGENT_ENDPOINT`, xem `update_chatbot_dathang.md`) để trả lời theo đúng sản phẩm đang ghim và đặt hàng thật thay vì chỉ thêm vào giỏ.
6. **Tồn kho deal** (`so_luong_kho_deal`) không bị trừ khi mua (đã grep, không có code trừ). Cần trừ nguyên tử bằng `$inc` có điều kiện `> 0`, và chặn mua khi deal hết hàng hoặc hết khung giờ.
7. **`live.php` quá lớn, JS viết inline**: nên tách ra `frontend/public/assets/js/live-studio.js` và `live-viewer.js` để viết test Node như `ai-idol-live.test.js`.
8. **Ba router lệch nhau**: route mới phải thêm vào cả ba file `index.php`.
9. Bản xem lại Syna lưu vĩnh viễn và hiện công khai, admin chưa có nút ẩn/xóa. Studio chưa hiện `replay.last_error`.
10. `livekit-client` đang ghim bản 2.1.2. Nâng phiên bản thì chạy lại toàn bộ E2E live, vì logic mute/pause phụ thuộc API track.

Quy trình đề xuất cho mỗi nâng cấp: GitNexus impact → sửa nhỏ nhất → `php -l` + test thuần + `node --test` → E2E trên phiên thử (`live-full.e2e.cjs` tự tạo và tự xóa phiên) → `git diff --check` → `detect-changes` → ghi `update_<tên_việc>.md` và thêm một dòng vào đầu `update.md`.
