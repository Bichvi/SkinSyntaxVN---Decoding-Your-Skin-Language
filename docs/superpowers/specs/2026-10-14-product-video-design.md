# Video AI Idol trên trang chi tiết sản phẩm — Thiết kế

- Ngày: 2026-10-14
- Nhánh: `syna`
- Mockup đã duyệt: https://claude.ai/artifact/RuifVNvhPkdg33Jh6gqxge (bản 2)
- Trạng thái: chờ duyệt spec

## 1. Mục tiêu

Video do AI Idol Syna tạo **tự động** xuất hiện trên trang chi tiết sản phẩm, giống Shopee Video:

- Video nhỏ ở góc phải màn hình, bấm vào để phóng to.
- Bên dưới video phóng to có thẻ sản phẩm "Xem chi tiết".
- Có mục "Video về sản phẩm" để khách lướt nhiều video.

Admin không phải duyệt từng video. Admin chỉ chọn **chế độ phát sóng** một lần lúc tạo chiến dịch, các bước sau chạy tự động. Nút "Ẩn khỏi trang sản phẩm" dùng khi cần gỡ gấp.

**Tiêu chí thành công:**

1. Tạo chiến dịch mà không chọn chế độ thì ra **Video sản phẩm**. Mỗi video tự lên trang sản phẩm khi đạt READY và upload xong.
2. Chế độ **Livestream + video sản phẩm**: livestream vẫn nói giá ngay sau từng sản phẩm. Trang sản phẩm chỉ có phần không nói giá và không hiện giá trên hình.
3. Chế độ **Chỉ livestream**: không có video nào lên trang sản phẩm. Trong lúc phát sóng chỉ hiện nhãn LIVE.
4. Sản phẩm không có video thì trang chi tiết giữ nguyên như hiện tại và không tải thêm CSS/JS.
5. AI Idol service hoặc R2 lỗi thì trang chi tiết vẫn tải bình thường, chỉ không có video.

## 2. Các quyết định đã chốt

| # | Quyết định | Ghi chú |
|---|---|---|
| D1 | Tự động hoàn toàn, admin không duyệt từng video | Có nút "Ẩn khỏi trang sản phẩm" để gỡ |
| D2 | Thêm 3 chế độ phát sóng, lưu trong trường `purpose` | Mặc định `product_video` |
| D3 | Mục 6 trong khung "Ghép lệnh" đổi thành "Chế độ phát sóng" | Ba chế độ luôn hiện sẵn |
| D4 | Chế độ 3 dùng **một kịch bản, render một lần, cắt bớt phần cuối** cho trang sản phẩm | Xem mục 4.3 |
| D5 | Lưu video và ảnh bìa trên **Cloudflare R2**. Tạm dùng `r2.dev`, địa chỉ gốc đặt trong biến môi trường | Trình duyệt tải thẳng từ R2 |
| D6 | Ảnh bìa là 1 khung hình cắt từ video bằng ffmpeg | |
| D7 | Mục "Video về sản phẩm" là một hàng riêng ngay dưới phần ảnh/giá, **chỉ hiện khi sản phẩm có ít nhất 1 video** | |
| D8 | Không có hạn hiển thị. Video mới nhất nằm ở góc phải | |
| D9 | Prompt gõ tay không chọn chế độ: có ý tạo livestream thì dùng `live_and_product`, không thì dùng `product_video` | |
| D10 | Kịch bản Video sản phẩm hấp dẫn, bắt trend, 90–150 giây, đi sâu vào thông số và thành phần | Xem 4.2 |
| D11 | Tăng âm lượng nhạc nền khi Syna nói: mức nhạc `-19 LUFS`, độ nén `3` | Xem 4.3b |
| D12 | Chế độ `product_video` **tự duyệt kịch bản** sau khi qua bộ kiểm tra, kể cả khi chính sách là "Duyệt trước khi render" hoặc chiến dịch tạo bằng wizard thủ công. Video tự lên trang, không có bước duyệt nào | Quyết định của người dùng ngày 2026-10-14 |
| D13 | **Một lần duy nhất (backfill):** mọi video đã render trước tính năng này, kể cả video livestream có nói giá, đều được đưa lên trang sản phẩm, không cần duyệt. Bản ghi được đánh dấu `product_page_backfill: true`, và API danh sách nhận thêm cờ này bên cạnh `purpose`. Sau lần chạy này, video cũ phát sinh thêm vẫn theo quy tắc `purpose` | Người dùng chấp nhận rủi ro giá trong video cũ lệch với giá hiện tại |

**Điều chỉnh so với các vòng trao đổi trước:**

- **Bỏ quy tắc "dự phòng bằng video cả chiến dịch".**
  - Ở chế độ 2, mỗi sản phẩm luôn có clip riêng và hệ thống không ghép video tổng.
  - Ở chế độ 3, video tổng có nói giá.
  - Vì vậy trang sản phẩm chỉ dùng **clip của đúng sản phẩm đó**.
- **Thẻ sản phẩm trong khung phóng to:** thẻ đầu tiên là sản phẩm của clip. Sau đó là tối đa 3 sản phẩm khác **cùng chiến dịch**, gắn nhãn "Cùng chương trình".

## 3. Chế độ phát sóng (`purpose`)

| Giá trị | Tên hiển thị | Kịch bản | Phát sóng | Trang sản phẩm |
|---|---|---|---|---|
| `product_video` (mặc định) | Video sản phẩm | 7 phần, 90–150 giây, giọng bắt trend, sâu về thông số và thành phần (xem 4.2). Không có con số giá, không khuyến mãi có hạn, không "chốt đơn", không "livestream/hôm nay" | Không | Từng clip tự hiện khi job READY và upload xong |
| `live_and_product` | Livestream + video sản phẩm | 8 phần như hiện tại. Phần 1–5 phải trung tính như chế độ 2 | Có | Phần nội dung (trước Price) của từng clip tự hiện sau khi chiến dịch phát sóng xong |
| `live_only` | Chỉ livestream | 8 phần như hiện tại | Có | Không có video. Chỉ hiện nhãn LIVE khi chiến dịch đang `LIVE` |

**Lưu trữ:**

- `ai_idol_campaigns.purpose` và `ai_idol_jobs.purpose` lấy từ chiến dịch.
- Giá trị lạ hoặc thiếu thì chuẩn hóa về `product_video`.
- Chiến dịch và job có từ trước tính năng này: thiếu `purpose` thì coi là `live_only`. Lý do là các kịch bản cũ đều có nói giá, nên không đưa lên trang sản phẩm.
- Tên `purpose` tách biệt với `content_mode`. `content_mode` là **phong cách** kịch bản ("Scientific Review", "Problem-Solution", "Product Intro") và giữ nguyên.

**Agent (khung "Giao việc bằng một câu"):**

- `agents/schemas.py`: brief nhận thêm `purpose` (enum 3 giá trị, có thể để trống).
- Studio gửi `purpose` dưới dạng **trường riêng** trong body của `admin_ai_idol_agent_create`, không chỉ nằm trong câu chữ.
- Nếu không có `purpose`, `intent_parser.py` suy ra:
  - Có ý tạo livestream (đã có cách nhận diện lịch phát) thì dùng `live_and_product`.
  - Ngược lại dùng `product_video`.
- `agents/service.py` truyền `purpose` vào payload tạo chiến dịch.

## 4. Pipeline AI Idol

### 4.1 Tạo chiến dịch (`core/campaigns.py`)

- `create_campaign` nhận `purpose`.
- Với `product_video`:
  - `scheduled_at` **không bắt buộc**, lưu `None`.
  - Bỏ qua kiểm tra thời gian chuẩn bị tối thiểu.
  - `platforms` lưu `[]`.
- `idempotency_key` mặc định: khi không có `scheduled_at` thì dùng `purpose` và thời điểm tạo, tránh lỗi gọi `.isoformat()` trên `None`.
- Hai chế độ có livestream giữ nguyên quy tắc hiện tại.

### 4.2 Kịch bản (`engines/content_agent.py`, `engines/script_validator.py`, script council)

- Truyền `purpose` vào `generate_content_and_script` và `validate_and_revise_script` (đi qua payload script council).
- `product_video` dùng prompt riêng, hấp dẫn, đầy đủ, dày hơn:
  - Mặc định `length_mode = "Long (90-150s)"`. Mục tiêu 90–150 giây, tối đa 180 giây theo giới hạn của Syna.
  - Bỏ hướng dẫn "Công bố giá" và "Kêu gọi chốt đơn". Không đưa dòng "Giá bán" và "Giá thị trường" vào prompt.
  - Cấu trúc 7 phần, tiêu đề tiếng Anh để code tách được:

    | Tiêu đề | Nội dung | Tỉ trọng |
    |---|---|---|
    | `Hook` | Câu mở 3 giây đầu: gây tò mò hoặc chạm đúng nỗi đau da đang được quan tâm. Giọng trẻ, bắt trend. Không bịa số liệu. | ngắn |
    | `Problem` | Ai đang gặp vấn đề này, vì sao bị | ngắn |
    | `Product Intro` | Thương hiệu, xuất xứ, loại sản phẩm | ngắn |
    | `Specs` | Dung tích, kết cấu, loại da phù hợp, nồng độ hoạt chất. **Chỉ lấy từ dữ liệu sản phẩm.** Thiếu thông tin nào thì bỏ qua, không đoán. | vừa |
    | `Ingredients` | Từng hoạt chất chính: là gì, tác dụng thế nào, vì sao kết hợp với nhau tốt. Lồng 1–2 "fact thú vị" lấy từ tài liệu RAG. | **dài nhất, khoảng 35–40%** |
    | `How to Use` | Bước nào trong routine, sáng/tối, lưu ý khi kết hợp với hoạt chất khác | vừa |
    | `Wrap-up` | Tóm 3 ý chính và mời xem chi tiết sản phẩm. Không nói giá, không "chốt đơn". | ngắn |

  - Giọng "trend, giật gân" chỉ áp dụng cho **cách nói**: câu ngắn, nhịp nhanh, có câu hỏi tu từ. Mọi con số, hoạt chất và công dụng phải khớp dữ liệu sản phẩm. Danh sách TUYỆT ĐỐI CẤM hiện có ("trị tận gốc", "hết mụn 100%"…) vẫn áp dụng.
  - Prompt tăng giới hạn nguồn: thành phần lên 2000 ký tự, tài liệu RAG lên 2200 ký tự, để đủ chất liệu cho phần `Ingredients`. Vẫn đi qua `fit_message_pair`.
  - `core/script_text.py`: thêm `problem|specs|ingredients|wrap-up` vào `SECTION_NAMES`.
  - Validator dùng biến thể 7 phần này. Thêm điều kiện FAIL khi kịch bản bỏ trống phần `Ingredients` mà dữ liệu sản phẩm có thành phần.
  - Bảng "Thành phần chính" trên màn hình Syna vẫn chỉ hiện tối đa 3 hoạt chất (giới hạn của renderer). Phần lời đọc vẫn nói được nhiều hơn.
- `live_and_product`: prompt 8 phần, kèm yêu cầu rõ "phần Opening đến How to Use không nhắc giá, khuyến mãi, livestream, hôm nay, chốt đơn".
- **Bước kiểm tra cứng không dùng AI** (hàm mới `find_price_mentions(text)` trong `core/script_text.py`):
  - Regex bắt con số giá: `\d[\d.,]*\s*(k|nghìn|ngàn|đ|đồng|vnd|₫)`.
  - Regex bắt từ khuyến mãi có hạn: `giảm giá|sale|flash|chốt đơn|hôm nay|livestream|chỉ còn|duy nhất`, có xét bỏ dấu.
  - Áp lên toàn bộ kịch bản ở chế độ 2, và lên phần trước Price ở chế độ 3.
  - Có kết quả thì validator trả về FAIL kèm lý do, rồi đi theo luồng sửa/từ chối hiện có.

### 4.3 Render một lần, cắt bỏ phần bán hàng (chế độ 3)

**Hiện trạng:**

- `voice_engine.generate_voice_audio` đã ghi `<audio>.timings.json`, chứa `start`/`end` của từng đoạn.
- Video Syna gồm: lead-in (`lead_in_seconds`), rồi giọng đọc, rồi tail.
- Bảng giá được vẽ **suốt cả video** ở `frontend/public/ai-idol-mascot-3d/campaign-stage.js:113-114`.

**Thay đổi:**

1. `core/script_text.py`: hàm mới `split_labeled_sections(script)` trả về `[(label, text)]`. Hàm cũ `split_speech_sections` giữ nguyên hành vi.
2. `voice_engine`: mỗi phần tử trong `timings` có thêm `label`.
3. `syna_engine.build_manifest`:
   - Thêm `product.price_from`: số giây (tính trên timeline của renderer, đã cộng lead-in) tại điểm bắt đầu phần đầu tiên thuộc `price|cta|closing`.
   - Chế độ 2: `price_from = null`.
   - Chế độ 1: `price_from = 0`, giữ hành vi hiện tại.
4. `campaign-stage.js`: chỉ vẽ giá khi `price_from` khác `null` và `time >= price_from`. Trước thời điểm đó, ô giá để trống (không hiện "Giá đang cập nhật").
5. `syna-render-server.cjs`: chấp nhận `product.price_from` là `null` hoặc số trong khoảng `[0, duration]`.
6. Sau `mix_program_audio`, với chế độ 3, cắt khúc nội dung:
   - `ffmpeg -ss 0 -to <cut>`, re-encode, fade-out âm thanh và hình khoảng 0,6 giây.
   - Lưu thành `syna_<job>_body.mp4`.
   - `cut = intro + timings[first_sale].start`.
   - Clip đầy đủ giữ nguyên để ghép livestream, nên `program_composer.py` **không đổi**.
7. Chế độ 2 không cần cắt: clip đầy đủ chính là khúc nội dung.
8. Renderer cũ (`video_composer` / `title_cards`) chỉ đặt thẻ giá ở outro, nên áp dụng cùng cách cắt. Chế độ 2 truyền `price=None` để không tạo thẻ giá.
9. Nếu không tìm thấy mốc Price (kịch bản thiếu tiêu đề) thì job vẫn READY cho livestream. Chỉ ghi `product_page_block_reason = "missing_price_marker"` và **không** đưa video lên trang sản phẩm.

### 4.3b Âm lượng nhạc nền (áp dụng cho mọi chế độ)

**Hiện trạng:**

- Nhạc nền chuẩn hóa ở mức `MUSIC_BED_LUFS = -22` ([config.py:76](../../../ai-idol-service/config.py)). Giọng đọc ở mức `-14 LUFS`.
- Khi Syna nói, nhạc còn bị nén thêm `sidechaincompress=threshold=0.04:ratio=6` (`engines/audio_mixer.py`, `build_filter_graph`). Kết quả là nhạc gần như mất hẳn lúc có lời.

**Thay đổi:**

- Mặc định `AI_IDOL_MUSIC_BED_LUFS` đổi từ `-22` thành **`-19`**.
- Biến mới `AI_IDOL_MUSIC_DUCK_RATIO`, mặc định **`3`** (trước đây cố định là `6`). Đọc trong `config.py` và truyền vào `build_filter_graph` dưới dạng tham số `duck_ratio`.
- Giữ nguyên `alimiter=limit=0.89` để tổng âm thanh không bị vỡ tiếng.
- Thêm 2 biến trên vào `.env.example`.
- Chỉ áp dụng cho video render mới. Không render lại video cũ.
- Tăng `AUDIO_PIPELINE_VERSION` từ `"2"` lên `"3"`. Job đang chờ render sẽ dùng mức mới; video đã READY không bị ảnh hưởng.

**Kiểm thử:**

- Unit test: `build_filter_graph` có chứa `ratio=3`, và đổi được qua tham số.
- Nghe thử thủ công 1 video mới trước khi kết luận. Không khẳng định "đã vừa tai" nếu chưa nghe.

### 4.4 Ảnh bìa và upload lên R2 (module mới `engines/media_publisher.py`)

- `make_poster(video_path)`:
  - Dùng ffmpeg lấy khung hình ở giây `min(1.5, duration/3)`.
  - Xuất JPEG, cạnh dài 640 px, chất lượng khoảng 80.
- `publish_video(video_id)`, idempotent:
  - Upload clip trang sản phẩm (body hoặc full) và ảnh bìa lên R2 bằng `boto3` S3 client với `endpoint_url` của R2.
  - Key có dạng `product-videos/<video_id>-<random12>.mp4` và `.jpg`.
  - Đặt `ContentType` đúng loại file, `CacheControl: public, max-age=31536000, immutable`.
- Thành công thì ghi vào `ai_idol_videos`:
  - `media.video_url`, `media.poster_url`, `media.keys`, `media.uploaded_at`.
  - `product_page_ready_at = now`.
- Sau khi upload thành công mới xóa file trên máy:
  - Xóa: clip body, ảnh bìa, file tạm, audio trung gian.
  - **Không xóa** clip đầy đủ và video tổng khi chiến dịch còn có thể phát lại.
  - Chế độ 2: xóa clip ngay sau upload.
  - Chế độ 3: xóa clip đầy đủ, `segments.video_path` và video tổng **sau khi** chiến dịch đã `COMPLETED`.
- Thời điểm kích hoạt (Celery task `publish_product_videos_task`):
  - Chế độ 2: khi job chuyển sang READY, gọi từ `generate_job_task` sau khi pipeline trả `True`.
  - Chế độ 3: khi chiến dịch chuyển sang `COMPLETED`, hoặc `PARTIAL_FAILURE` mà có ít nhất 1 nền tảng `COMPLETED` trong `ai_idol_broadcasts`. Có hai chỗ chuyển trạng thái là `broadcast_engine.py:140` và `:161`, cả hai cùng gọi một hàm `enqueue_product_publish(campaign_id)`.
- Upload lỗi thì giữ file trên máy và ghi `media.last_error`. Beat task mới `retry_product_video_publish_task` chạy mỗi 10 phút để thử lại, tối đa 5 lần (`media.attempts`).
- Thiếu cấu hình R2 (môi trường dev) thì **không upload và không xóa file**, chỉ log cảnh báo một lần. Video sẽ không lên trang sản phẩm. Tiêu chí 5 vẫn đạt.

**Biến môi trường mới** (thêm vào `.env.example` với giá trị trống):

- `R2_ACCOUNT_ID`
- `R2_ACCESS_KEY_ID`
- `R2_SECRET_ACCESS_KEY`
- `R2_BUCKET`
- `R2_PUBLIC_BASE_URL`, ví dụ `https://pub-xxxx.r2.dev`, sau này đổi sang tên miền riêng.

Không log khóa bí mật.

### 4.5 Trường mới trong `ai_idol_videos`

```
purpose, campaign_id, campaign_name,
aired_at            # chế độ 3: completed_at của chiến dịch; chế độ 2: null
media: { video_url, poster_url, keys[], uploaded_at, last_error, attempts }
product_page_ready_at
product_page_block_reason
hidden_from_product_page: bool, hidden_at, hidden_by
```

Index mới: `{product_id: 1, product_page_ready_at: -1}` (partial index: `product_page_ready_at` tồn tại).

### 4.6 API (Flask `app.py`)

**`GET /api/ai-idol/products/<product_id>/videos?limit=30`**

Lấy các video thỏa mãn:
- `product_id` khớp.
- Có `product_page_ready_at`.
- `hidden_from_product_page != true`.

Sắp theo `product_page_ready_at` giảm dần, tối đa 30 video. Trả về:

```json
{
  "live": {"campaign_id": "…", "name": "…"} ,
  "videos": [{
    "video_id": "…", "video_url": "https://…mp4", "poster_url": "https://…jpg",
    "duration": 30.4, "format": "16:9",
    "date": "2026-10-12T12:00:00Z", "date_kind": "aired" ,
    "campaign_name": "Routine da dầu tháng 10",
    "product_ids": ["1024", "1031"]
  }]
}
```

- `live`: chiến dịch có `status: "LIVE"`, chứa `product_id`, với `purpose` thuộc `live_only` hoặc `live_and_product`. Không có thì trả `null`.
- `date_kind` là `"aired"` cho chế độ 3, `"created"` cho chế độ 2.
- `product_ids`: sản phẩm của clip đứng đầu, sau đó tối đa 3 sản phẩm khác cùng chiến dịch.

**`POST /api/ai-idol/videos/<video_id>/visibility`** với body `{hidden: bool, by: "<admin>"}`, phục vụ admin.

Cả hai endpoint đi qua lớp bảo vệ nội bộ giống các route hiện có. Service không lộ ra internet; nginx vẫn chặn `/api/`.

## 5. PHP

### 5.1 Trang chi tiết (`SanPhamController::chitiet`)

- Class mới `backend/app/services/ProductVideoClient.php` gọi Flask:
  - Connect timeout 0,5 giây, tổng timeout 1,5 giây.
  - Lỗi, timeout hoặc JSON sai thì trả `['live' => null, 'videos' => []]` và ghi log mức warning (không log URL có token).
- Chỉ nhận `video_url` và `poster_url` bắt đầu bằng `R2_PUBLIC_BASE_URL`. Video không đạt thì bỏ qua.
- Lấy tên, giá **hiện tại** và ảnh của `product_ids` từ model `SanPham` có sẵn, gom thành một truy vấn. Sản phẩm đã ẩn hoặc không tồn tại thì bỏ khỏi thẻ.
- Truyền `$productVideos` vào view. `chitiet.php` chỉ thêm 1 dòng:
  `<?php if (!empty($productVideos['videos']) || !empty($productVideos['live'])) require VIEW_DIR.'/partials/product_video.php'; ?>`
  Dòng này đặt giữa khối ảnh/giá và `#detailTabNav`.
- **Không** có route PHP phát video, vì video tải thẳng từ R2.

### 5.2 Admin

- Route `admin_ai_idol_video_visibility` (POST): thêm vào **cả** `backend/app/routes/ai_idol.php` và whitelist ở `backend/public/index.php:63`. Kiểm tra quyền admin giống các route `admin_ai_idol_*` khác.
- `AdminAiIdolController`:
  - Chuyển tiếp `purpose` khi tạo chiến dịch và khi tạo agent run.
  - Thêm action `videoVisibility()`.

## 6. Giao diện trang sản phẩm

**File mới:**
- `frontend/views/partials/product_video.php`
- `frontend/public/assets/css/product-video.css`
- `frontend/public/assets/js/product-video.js`

Hai file CSS và JS chỉ được nạp bên trong partial.

Dữ liệu được nhúng bằng `<script type="application/json" id="productVideoData">` và escape bằng `json_encode` với `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.

### 6.1 Video nhỏ ở góc phải

- Luôn là video mới nhất (`videos[0]`).
- Kích thước:
  - Video 9:16: rộng 128 px trên máy tính, 96 px trên điện thoại.
  - Video 16:9: rộng 220 px trên máy tính, 168 px trên điện thoại.
- Vị trí: góc dưới bên phải. Trên điện thoại đặt **cao hơn thanh mua hàng cố định**.
- `<video muted playsinline loop preload="none" poster=…>`. Chỉ gán `src` và gọi `play()` sau sự kiện `load` của trang. Nếu `prefers-reduced-motion` thì chỉ hiện ảnh bìa, không tự chạy.
- Nút ✕ ẩn video nhỏ. Lựa chọn này nhớ trong `sessionStorage` theo từng sản phẩm, có try/catch. Sau khi ẩn, mép phải hiện nút "▶ Xem video".
- Nút loa bật/tắt tiếng. Bấm vào video thì mở khung phóng to, có tiếng, phát tiếp từ `currentTime` đang xem.
- Khi `live` khác `null`:
  - Viền và nhãn **LIVE** màu đỏ, có chấm nhấp nháy, kèm chữ "Đang phát sóng".
  - Bấm vào thì chuyển tới `index.php?r=live`.
  - Nếu sản phẩm chưa có video nào thì khung nhỏ dùng ảnh nền tĩnh có nhãn LIVE.

### 6.2 Mục "Video về sản phẩm"

- Chỉ render khi `videos.length >= 1`. Tiêu đề kèm số lượng, ví dụ "5 video".
- Hàng thẻ 9:16 vuốt ngang được (`scroll-snap`). Thẻ rộng 132 px trên máy tính, 108 px trên điện thoại.
- Mỗi thẻ có:
  - Ảnh bìa với `loading="lazy"`. Video 16:9 thì dùng `object-fit: cover`.
  - Thời lượng.
  - Ngày: "Phát sóng 12/10" hoặc "Tạo 05/10".
  - Tên chiến dịch, tối đa 2 dòng.
  - Nhãn "Mới nhất" trên thẻ đầu.
- Hiện trước 10 thẻ. Nếu còn nữa thì có nút "Xem thêm" để hiện phần còn lại (dữ liệu đã có sẵn, tối đa 30 video).

### 6.3 Khung phóng to

- Dạng dialog: `role="dialog" aria-modal="true"`, giữ focus bên trong, Esc để đóng, đóng xong trả focus về chỗ cũ.
- Video 9:16 chiếm hết chiều cao khung. Video 16:9:
  - Máy tính: khung 16:9 ở giữa.
  - Điện thoại: video căn giữa theo chiều dọc, nền phía sau là ảnh bìa làm mờ.
- Phía trên có:
  - Tên chiến dịch, "Syna · AI Idol".
  - Bộ đếm "2/5", ẩn khi chỉ có 1 video.
  - Nút loa và nút ✕ (thu nhỏ).
- Phía dưới là khay "Sản phẩm trong video":
  - Dòng "Video tạo ngày dd/mm".
  - Các thẻ sản phẩm gồm ảnh, tên, giá **hiện tại**, nút "Xem chi tiết".
  - Thẻ sản phẩm đang xem có nhãn "Đang xem". Bấm vào thì đóng khung, cuộn về khu vực giá và nhấp nháy viền khu vực đó.
  - Thẻ sản phẩm khác dẫn tới `index.php?r=chitiet&id=<id>`.
- Chuyển video:
  - Nút ▲ ▼ và phím ↑ ↓ trên máy tính.
  - Vuốt dọc trên 50 px trên điện thoại (Pointer Events). Khay sản phẩm dùng `touch-action: pan-x` để không bị nhầm thành vuốt.
  - Hết một video thì tự sang video kế tiếp.
- Tải trước: chỉ tạo `<link rel="preload" as="video">` hoặc đặt `preload="metadata"` cho **video kế tiếp**.
- Bấm vào video để tạm dừng hoặc phát tiếp.

## 7. AI Idol Studio — mục 6 "Chế độ phát sóng"

File: `frontend/views/admin/ai_idol/index.php:141-149`, `frontend/public/assets/js/ai-idol-studio.js:150-205`, `ai-idol-studio.css`.

- Thay ô checkbox `agentLiveEnabled` bằng nhóm radio `name="agentPurpose"` với 3 thẻ chọn như mockup. `product_video` được chọn sẵn, kèm nhãn "Mặc định".
- `agentLiveFields` (giờ, thời lượng, phạm vi phát) chỉ hiện khi chọn chế độ có livestream.
- Câu ghép vào prompt theo chế độ:
  - `product_video`: "tạo video sản phẩm dùng lâu dài, không nhắc giá và khuyến mãi".
  - `live_and_product`: "tạo livestream …; sau khi phát xong đăng phần giới thiệu không có giá lên trang sản phẩm".
  - `live_only`: "tạo livestream …, chỉ livestream".
- Khi chọn `product_video`: tắt và bỏ chọn 2 phong cách "Tập trung chốt sale" và "Nhấn mạnh giá & ưu đãi", hiện ghi chú lý do.
- Khi submit agent run: gửi thêm `purpose` là chế độ đang chọn, kể cả khi admin không bấm "Dùng prompt này".
- Wizard thủ công (tab "Wizard thủ công", bước 01 "Nội dung"): thêm cùng nhóm radio, ô lịch phát ẩn/hiện theo cùng quy tắc.
- Danh sách chiến dịch và video:
  - Hiện "Đang hiện trên N trang sản phẩm".
  - Nút "Ẩn khỏi trang sản phẩm" / "Hiện lại trên trang sản phẩm".
  - Hiện lý do khi video không được lên trang (`product_page_block_reason`, `media.last_error`).

## 8. Xử lý lỗi

| Tình huống | Hành vi |
|---|---|
| Flask chậm hoặc lỗi khi mở trang chi tiết | Không render partial, trang vẫn bình thường, ghi log warning |
| R2 chưa cấu hình hoặc upload lỗi | Không xóa file trên máy, thử lại tối đa 5 lần, Studio hiện lỗi, video chưa lên trang |
| Kịch bản lọt con số giá hoặc từ khuyến mãi | Validator báo FAIL, đi theo luồng từ chối/sửa hiện có |
| Thiếu mốc Price ở chế độ 3 | Livestream vẫn chạy, video không lên trang, ghi lý do |
| File video trên R2 lỗi khi phát | Thẻ hiện ảnh bìa và chữ "Video tạm thời không phát được", chuyển sang video kế |
| Sản phẩm trong khay đã bị ẩn hoặc xóa | Bỏ thẻ đó; nếu khay trống thì ẩn khay |

## 9. Kiểm thử

**Python (pytest, `ai-idol-service/tests/`):**
- Chuẩn hóa `purpose`, mặc định và dữ liệu cũ.
- `create_campaign` ở chế độ `product_video` không cần lịch.
- `split_labeled_sections`, `find_price_mentions` (có dấu và không dấu).
- Prompt chế độ `product_video`: có đủ 7 tiêu đề, không có dòng giá, `length_mode` mặc định là Long. Validator FAIL khi thiếu phần `Ingredients`.
- `build_filter_graph` dùng `ratio` cấu hình được, mặc định `3`. `MUSIC_BED_LUFS` mặc định `-19`.
- Tính `price_from` và `cut`.
- Bỏ qua bước ghép video tổng và lên lịch cho chế độ 2.
- Kích hoạt publish ở 2 chỗ chuyển trạng thái phát sóng.
- `media_publisher` dùng `boto3` giả lập: thành công, lỗi, chạy lại không tạo trùng, không xóa file khi upload lỗi.
- API danh sách video: lọc theo chế độ, video bị ẩn, `live`, thứ tự.
- Endpoint visibility.

**Node (renderer):** mở rộng `scripts/ai-idol-mascot/check-3d-browser.cjs` để kiểm tra bảng giá ẩn trước `price_from` và hiện sau đó.

**PHP:**
- `php -l` cho mọi file PHP thay đổi.
- `ProductVideoClient` xử lý timeout và JSON sai.
- Lọc URL không thuộc `R2_PUBLIC_BASE_URL`.

**E2E (Playwright, theo `.claude/skills/e2e/SKILL.md`):** 4 tình huống trên trang chi tiết là 0 video, 1 video, nhiều video, đang LIVE. Kiểm tra:
- Mở khung phóng to, chuyển video bằng phím ↑ ↓.
- Bấm thẻ sản phẩm khác thì sang đúng trang.
- Mục 6 trong Studio: mặc định đúng, ẩn/hiện ô lịch, tắt phong cách nói giá.

Dữ liệu E2E nạp thẳng vào Mongo test với URL file mẫu cục bộ, không gọi R2 thật.

**Không** khẳng định E2E PASS nếu chưa chạy thật.

## 10. Ngoài phạm vi

- Thống kê lượt xem hoặc lượt bấm của video.
- Ảnh bìa thiết kế riêng.
- Tên miền riêng cho R2 (chỉ cần đổi biến môi trường).
- URL có ký tên hoặc chặn truy cập video đã ẩn trên R2. Video quảng cáo được chấp nhận để công khai.
- Đổi renderer Syna sang khung dọc 9:16.

## 11. Rủi ro

- **Syna chỉ render khung ngang 16:9** (`campaigns.py`, `configuration.avatar_mode == "syna_3d"`). Giao diện phải xử lý tốt cả 16:9 lẫn 9:16, xem mục 6.
- Cắt video bằng re-encode tốn thêm vài giây CPU cho mỗi clip, chấp nhận được.
- Phần Opening có thể vẫn nhắc "livestream". Đã có prompt dặn trước và regex chặn lại.
- `r2.dev` bị giới hạn tốc độ, không dùng cho lưu lượng lớn.
- `backend/public/index.php` có whitelist route trùng lặp. Phải thêm route mới ở cả hai nơi.
