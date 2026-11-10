# AI Idol: xem lại phiên live Syna đã kết thúc (video trên R2)

Ngày: 2026-11-10 · Trạng thái: chờ duyệt
Phạm vi: `ai-idol-service` (engines, tasks, core/purpose), `backend/app/controllers/LiveController.php`, một service PHP nhỏ, một dòng chặn trong JS của `frontend/views/live.php`

## 1. Vấn đề

- Phiên AI Idol (Syna) lưu ở `ai_idol_campaigns`, còn danh sách "Các phiên phát khác" trên `?r=live` chỉ đọc `phien_live` (phiên người thật). Phiên Syna không bao giờ có thẻ trong danh sách.
- `LiveController::findAiIdolLive()` chỉ nhận chiến dịch `status = LIVE` và chưa quá `ends_at`. Hết giờ, khung Syna biến mất và `streamAiIdolLiveVideo()` trả 404.
- `media_publisher.publish_campaign_videos()` xóa `master_video_path` ngay khi các clip sản phẩm đã lên R2. Phiên `6af1f36e33597464ac61b9dd` ("Syna Live routine thử", 2026-11-10) đã mất file gốc, không khôi phục được.

## 2. Mục tiêu và tiêu chí hoàn thành

- Khi một phiên Syna phát trên kênh nội bộ kết thúc, video gốc và một ảnh bìa được tải lên R2. File trên máy chỉ bị xóa sau khi tải thành công.
- Trang `?r=live` hiện các phiên đó trong "Các phiên phát khác" với nhãn **Xem lại**. Nút lọc "Xem lại" lọc ra được các phiên này.
- Bấm "Xem lại bản ghi" thì mở `?r=live&id=ai-<campaign_id>`. Video R2 phát trong trình phát xem lại sẵn có (`#replayVideoPlayer`, có thanh tua).
- 3 phiên cũ còn file gốc (`6ace67e7…`, `6ace7dba…`, `6ace4166…`) được đưa lên R2 bằng lệnh chạy một lần.

## 3. Quyết định đã chốt

| # | Quyết định |
|---|---|
| D1 | Video xem lại lưu trên **Cloudflare R2**, dùng bộ cấu hình `R2_*` sẵn có. |
| D2 | **Giữ vĩnh viễn**, không tự dọn dẹp. |
| D3 | Chỉ áp dụng cho chiến dịch có phát (`live_only`, `live_and_product`, chiến dịch cũ chưa có purpose được tính là `live_only`) **và** có nền tảng `internal`. |
| D4 | Dùng lại trình phát xem lại và thẻ phiên sẵn có của `?r=live`. Không làm giao diện riêng. |
| D5 | File gốc chỉ bị xóa khi (a) đã có bản xem lại trên R2 (nếu chiến dịch cần bản xem lại) **và** (b) mọi clip trang sản phẩm đã lên R2 (nếu chiến dịch có đăng lên trang sản phẩm). R2 lỗi vĩnh viễn thì file gốc ở lại máy: tốn ổ đĩa vẫn hơn mất bản ghi. |

## 4. Thiết kế: ai-idol-service

### 4.1 `core/purpose.py`: thêm hàm thuần

```python
def campaign_wants_replay(campaign: dict | None) -> bool:
    """Shown on the storefront /live page: a broadcast purpose that aired on the internal channel."""
    platforms = (campaign or {}).get("platforms") or ["internal"]
    return has_broadcast(purpose_of(campaign)) and "internal" in platforms
```

### 4.2 `engines/replay_publisher.py` (file mới)

Dùng lại `_r2`, `r2_configured`, `_default_client`, `_scrub`, `make_poster`, `_remove`, `MAX_ATTEMPTS`, `CACHE_CONTROL` từ `engines/media_publisher.py` và `probe_duration` từ `engines/program_composer.py`.

- `KEY_PREFIX = "live-replays"`. Khóa R2: `live-replays/{campaign_id}-{secrets.token_hex(6)}.mp4` và `.jpg`.
- Trường mới trên `ai_idol_campaigns`:
  ```
  replay: {
    video_url, poster_url, keys: [mp4, jpg], duration,
    uploaded_at, started_at, attempts, last_error
  }
  ```
- `replay_publishable(campaign) -> bool`: `campaign_wants_replay(campaign)` và `status` là `COMPLETED`, hoặc là `PARTIAL_FAILURE` kèm một dòng `ai_idol_broadcasts` của `internal` ở trạng thái `COMPLETED`.
- `publish_campaign_replay(campaign_id, *, client=None) -> bool`:
  1. Đọc chiến dịch. Có `replay.uploaded_at` thì trả `True` (chạy lại không sao). Không `replay_publishable` thì trả `False`.
  2. R2 chưa cấu hình: ghi `replay.last_error = "Chưa cấu hình Cloudflare R2 nên chưa có video xem lại."`, **không** tăng `attempts`, trả `False`. Cảnh báo một lần, giống `publish_video`.
  3. File gốc không còn: ghi `replay.last_error = "Không còn file video gốc để lưu bản xem lại."`, đặt `attempts = MAX_ATTEMPTS` (để khỏi thử lại), trả `False`.
  4. **Nhận việc nguyên tử**: `update_one` với điều kiện `replay.uploaded_at` không tồn tại **và** (`replay.started_at` không tồn tại hoặc cũ hơn 30 phút), rồi đặt `replay.started_at = now`. Không nhận được thì trả `False`, vì đang có worker khác tải.
  5. `probe_duration` → `make_poster(master, <master không đuôi>.replay-poster.jpg, duration)`. Tải `.mp4` (`video/mp4`) và `.jpg` (`image/jpeg`) lên với `CacheControl` bất biến. `boto3.upload_file` tự chia nhiều phần khi file lớn.
  6. Thành công: đặt `replay.video_url`, `poster_url`, `keys`, `duration`, `uploaded_at`, `last_error = None`, `updated_at`, xóa poster cục bộ, rồi gọi `release_master_video(campaign_id)`. Trả `True`.
  7. Lỗi: xóa poster cục bộ, `$inc replay.attempts`, `replay.last_error = _scrub(exc)`, `$unset replay.started_at`, ghi cảnh báo. Trả `False`. Không để lỗi thoát ra ngoài.
- `retry_pending_replays(limit=5, *, client=None) -> int`: R2 chưa cấu hình thì trả 0. Lấy các chiến dịch có `status ∈ {COMPLETED, PARTIAL_FAILURE}`, `master_video_path ≠ null`, chưa có `replay.uploaded_at` và `replay.attempts < MAX_ATTEMPTS` (hoặc chưa có trường này). Lọc bằng `replay_publishable`, rồi gọi `publish_campaign_replay`.
- `backfill(apply, *, client=None)` và lệnh `python -m engines.replay_publisher backfill [--apply]`: tìm như `retry_pending_replays` nhưng không giới hạn số lượng và chỉ lấy chiến dịch còn file gốc. Chạy thử (dry run) thì chỉ in ra danh sách. Thiếu R2 khi `--apply` thì trả mã 2, giống `media_publisher.main`.

### 4.3 Nhả file gốc: `media_publisher.release_master_video(campaign_id) -> bool`

Đọc lại chiến dịch rồi xóa `master_video_path` chỉ khi:
- `not campaign_wants_replay(c)` hoặc `c.replay.uploaded_at` đã có, **và**
- `not publishes_to_product_page(purpose_of(c))`, hoặc mọi `ai_idol_videos` của chiến dịch có `product_page_file_path ≠ null` đều đã có `product_page_ready_at`.

Sau khi xóa, giữ nguyên giá trị `master_video_path` trong DB, giống hiện nay. Trong `publish_campaign_videos()`, dòng `_remove(campaign.get("master_video_path"))` được thay bằng `release_master_video(campaign_id)`. Việc xóa `file_path` của từng clip giữ nguyên.

Tác vụ nào chạy xong sau (clip sản phẩm hay bản xem lại) thì tác vụ đó nhả file gốc.

### 4.4 Xếp hàng và thử lại

- `broadcast_engine`: thêm `_queue_replay_publish(campaign)`. Hàm này gửi `ai_idol.publish_campaign_replay` khi `campaign_wants_replay`, chỉ ghi cảnh báo khi lỗi (giống `_queue_product_publish`). Nó được gọi ở **cả hai** chỗ hiện đang gọi `_queue_product_publish`: cuối `broadcast_campaign` và trong `finalize_live_campaigns`.
- `tasks.py`: thêm task `ai_idol.publish_campaign_replay`. `celery_app.py` chuyển task này vào hàng `media`.
- Task định kỳ `ai_idol.retry_product_video_publish` (10 phút một lần) gọi thêm `retry_pending_replays()`. Mỗi việc được bọc riêng, lỗi việc này không chặn việc kia.

### 4.5 Tương thích với spec kho clip (`2026-11-10-ai-idol-live-clip-library-design.md`)

Spec đó vẫn ghi ra một file `master_video_path`, chỉ dài hơn (30–60 phút). Thiết kế này không đổi. Đã kiểm tra: `celery_app.py` và `tasks.py` không đặt `time_limit` hay `soft_time_limit`, nên việc tải file vài trăm MB không bị cắt ngang. Khóa nhận việc 30 phút (bước 4) chặn worker thứ hai tải trùng trong lúc đó.

## 5. Thiết kế: PHP và trang `?r=live`

### 5.1 `backend/app/services/AiIdolReplaySessions.php` (file mới, hàm thuần)

`AiIdolReplaySessions::toSession(array $campaign, ?array $firstProduct, string $fallbackImage = ''): ?array` (controller tự tính `$fallbackImage` bằng `resolve_image_url`, nên class không phụ thuộc helper của view) chuyển một chiến dịch (đã đổi sang mảng) thành đúng cấu trúc phần tử `$liveSessions` mà `live.php` đang dùng:

| Khóa | Giá trị |
|---|---|
| `id` | `'ai-' . (string)_id` |
| `title` | `name` |
| `streamer` | `'Syna · AI Idol'` |
| `viewers` | `0` |
| `status` | `'replay_ready'` |
| `thumbnail` | `replay.poster_url`. Không có thì lấy `$fallbackImage` (ảnh sản phẩm đầu tiên), cuối cùng là `''` |
| `pinned_product` | `$firstProduct` |
| `gia_uu_dai_live` | `$firstProduct['gia_ban'] ?? 0` |
| `server_livekit_url` | `''` |
| `url_ban_ghi` | `replay.video_url` |
| `tom_tat_phien_live`, `description` | `''` |
| các số liệu (`luot_xem`, `mat_do_nguoi_xem_dinh`, `tong_doanh_thu`, `tong_don_hang`, `tong_san_pham_ban`, `ty_le_chot_don`) | `0` |
| `danh_sach_deal`, `top_san_pham` | `[]` |

Trả `null` (bỏ qua chiến dịch) khi `video_url` không bắt đầu bằng `https://` hoặc `http://`, hoặc khi thiếu `_id`. Nếu `poster_url` không phải http(s) thì coi như không có.

### 5.2 `LiveController`

- `findAiIdolReplays($mongoDb, int $limit = 30): array`:
  - Lọc `{platforms: 'internal', status: {$in: ['COMPLETED','PARTIAL_FAILURE']}, 'replay.video_url': {$exists: true, $nin: [null, '']}}`, sắp theo `completed_at` giảm dần, chỉ lấy các trường cần dùng.
  - Lấy sản phẩm đầu tiên của mọi chiến dịch bằng **một** lần gọi `findManyByIds`, rồi map qua `toSession`.
  - Bọc `try/catch Throwable` và trả `[]` khi lỗi, giống `findAiIdolLive`.
- Trong `index()`: `$liveSessions = array_merge($liveSessions, $this->findAiIdolReplays($mongoDb));` **trước** đoạn chọn `$currentLive`. Nhờ vậy `?id=ai-<id>` chọn được phiên này qua vòng lặp có sẵn.
- Không đổi `findAiIdolLive`, `streamAiIdolLiveVideo` hay khung Syna đang phát.

### 5.3 Giao diện

Phần HTML của `live.php` không cần sửa. Thẻ có `status = replay_ready` thì đã hiện nhãn "Xem lại", nút "Xem lại bản ghi" và vào được bộ lọc "Xem lại". Trình phát chính đã hiện `#replayVideoPlayer controls` khi có `url_ban_ghi`. Trò chuyện bị khóa và nút mua bị tắt vì phiên không ở trạng thái `live`, giống bản xem lại của phiên người thật.

Đã đọc phần JS của `live.php` với id `ai-…`:
- `heartbeat` (`api_live_ping`) chỉ chạy khi `streamState === 'live'`, nên không gọi.
- `connectLiveKit` dừng vì trạng thái không phải `live`.
- `refreshPinned` gọi `api_live_products` mỗi 5 giây. API trả `ok:false` ("Phiên Live không tồn tại") nên hàm thoát sớm: không lỗi và không tải lại trang, nhưng là lời gọi thừa.

**Sửa nhỏ duy nhất trong JS**: thêm `const isAiReplay = liveId.indexOf('ai-') === 0;` và cho `refreshPinned` thoát ngay khi `isAiReplay`.

## 6. Xử lý lỗi và bảo mật

- Lỗi R2 được che khóa bí mật bằng `_scrub` trước khi ghi DB hoặc log. Không log URL kết nối DB.
- PHP chỉ in URL http(s) và luôn qua `h()`.
- Mọi lỗi phía PHP đều trả về danh sách rỗng, trang `/live` không bao giờ hỏng vì phần xem lại.

## 7. Kiểm thử

- **pytest** (`ai-idol-service/tests/test_replay_publisher.py`): R2 giả, `MONGO_URI` và các khóa đặt rỗng theo quy ước. Các trường hợp:
  - tải thành công thì ghi đủ trường và nhả file gốc;
  - chạy lại thì không tải lần hai;
  - chưa cấu hình R2 thì không tăng `attempts`;
  - mất file gốc thì không thử lại;
  - đang có worker khác nhận việc thì bỏ qua;
  - lỗi tải thì tăng `attempts`, che bí mật và bỏ `started_at`;
  - `product_video` hoặc không có `internal` thì không làm gì.
- **pytest cho `release_master_video`**: giữ file khi thiếu bản xem lại hoặc còn clip chưa lên R2, xóa khi đủ cả hai. Cập nhật test cũ `test_completed_both_mode_campaign_publishes_bodies_then_drops_full_media` trong `test_media_publisher.py`. Chiến dịch trong test không có `platforms` nên được tính là `internal` và cần bản xem lại: test phải kỳ vọng file gốc **còn lại** khi chưa có `replay.uploaded_at`, và thêm một ca có `replay.uploaded_at` thì file gốc bị xóa.
- **pytest `broadcast_engine`**: `finalize_live_campaigns` xếp `publish_campaign_replay` vào hàng đợi cho chiến dịch `live_only` có kênh internal.
- **PHP**: `php -l` các file đã sửa. Thêm test thuần `backend/tests/ai_idol_replay_sessions_test.php` cho `toSession`, không chạm DB.
- **E2E (Playwright)**: chỉ sau khi người dùng đồng ý chạy `backfill --apply` (ghi thật lên R2 và Atlas). Mở `?r=live`, thấy thẻ Syna có nhãn "Xem lại", lọc "Xem lại" vẫn còn thẻ đó, bấm vào thì `#replayVideoPlayer` tải được và tua được.

## 8. Ngoài phạm vi

- Khôi phục phiên `6af1f36e…` đã mất file gốc.
- Tự dọn bản xem lại cũ, ẩn hoặc xóa bản xem lại từ trang quản trị.
- Phát lại tin nhắn trò chuyện, số liệu doanh thu của phiên AI.
- Đổi phần phát trực tiếp hoặc xem lại của phiên người thật (`phien_live`).
