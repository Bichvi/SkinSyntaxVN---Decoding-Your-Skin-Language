# AI Idol Studio — Thiết kế lại hai chế độ Thủ công / Tự động

- Ngày: 2026-10-15
- Trang: `index.php?r=admin_ai_idol`
- Trạng thái: chờ người dùng duyệt spec
- Phân loại: kiến trúc (tái cấu trúc UI + thay đổi hành vi orchestration ở `ai-idol-service`)

## 1. Bối cảnh và vấn đề

Trang hiện tại có hai tab "Wizard thủ công" và "Agent tự động" nhưng ranh giới bị đảo lộn:

1. Tab **Agent tự động** chứa ô prompt, 15 lệnh mẫu, **Prompt Builder 6 nhóm**, bảng livesearch sản phẩm, 2 chính sách duyệt. Người dùng phải cấu hình tay nhiều hơn cả Wizard → phá vỡ mô hình tư duy "giao việc một câu".
2. Hai tab trùng chức năng (chọn sản phẩm, cách kể, chế độ phát) nhưng hai luồng nhập khác nhau.
3. Cột phải tách "Thư viện" và "Agent đang làm gì" thành hai nơi theo dõi.

Phát hiện kỹ thuật trong lúc khảo sát (đã xác minh trong code):

- **F1 — Phong cách dẫn chuyện bị mất.** 9 phong cách trong Prompt Builder chỉ được ghép vào chuỗi prompt; `engines/content_agent.py` không đọc. `agents/command_query.py:310` ghi rõ phong cách "không ảnh hưởng bộ lọc". Kết quả: chọn phong cách không đổi gì.
- **F2 — Hai bộ lọc cho kết quả khác nhau.** API PHP `admin_ai_idol_products` lọc "Bán chạy 30 ngày / 7 ngày" bằng `so_luong_da_ban > 0` (doanh số toàn kỳ, `AdminAiIdolController.php:615`). Agent dùng `execute_query` có số liệu theo kỳ (snapshot). Cùng điều kiện, hai chế độ ra hai danh sách khác nhau.
- **F3 — Danh mục hai hệ.** Dropdown 3 cấp dùng `ma_danh_muc` (số); bộ truy vấn Agent dùng khóa từ khóa (`CATEGORY_PATHS`, so khớp `danh_muc_day_du`).
- **F4 — Một kịch bản trượt kiểm duyệt làm dừng cả chương trình.** `update_campaign_from_job` đưa chương trình sang `NEEDS_ATTENTION` khi có bất kỳ segment `FAILED` (`core/campaigns.py:239`).
- **F5 — Agent dừng ở `NEEDS_INPUT` trong rất nhiều trường hợp** (thiếu số lượng, giờ mơ hồ/không kịp, thiếu dữ liệu doanh số, mã sai), nên không "tự động" đúng nghĩa.
- Engine phát đọc `campaign.platforms` **tại thời điểm bắt đầu phát** (`engines/broadcast_engine.py:134`) → có thể thêm kênh sau khi tạo chương trình, trước giờ phát.

## 2. Mục tiêu

- **Thủ công**: người dùng tự chọn từ **toàn bộ** lựa chọn có sẵn (giữ đủ 6 nhóm của Prompt Builder cũ + các thiết lập Wizard cũ), có thứ tự rõ ràng, thấy "ý định" của mình thành câu hoàn chỉnh trước khi tạo.
- **Tự động**: nhập một prompt → hệ thống tự chọn sản phẩm, viết kịch bản, kiểm duyệt, render, lên lịch, phát nội bộ, đăng video lên trang sản phẩm. Không có điểm dừng nào trừ: phát ra kênh ngoài (YouTube/Facebook) cần một cú bấm cho phép.
- Một bảng **Hoạt động** chung để theo dõi mọi chương trình của cả hai chế độ, nổi bật việc "Cần bạn".
- Mọi lựa chọn hiển thị trên UI phải **thực sự có tác dụng** (sửa F1, F2, F3).

### Không làm (YAGNI)

- Tự phát YouTube/Facebook không cần bấm (cờ `AI_IDOL_AGENT_ALLOW_AUTO_PUBLISH` giữ nguyên, autopilot không dùng).
- Sửa kịch bản trực tiếp cạnh avatar / canvas preview kiểu CapCut.
- Lịch phát lặp lại, nhiều buổi.
- Xóa API `admin_ai_idol_products` cũ (chỉ ngừng dùng ở trang Studio).
- Thay đổi pipeline render Syna, TTS, kiểm duyệt (từ cấm + LLM) hay cơ chế đăng trang sản phẩm.

## 3. Quyết định đã chốt với người dùng

| # | Quyết định |
|---|---|
| D1 | Phương án A: hai chế độ tách bạch + bảng Hoạt động chung, cùng một trang. |
| D2 | Tự động hết, **trừ** phát ra kênh ngoài: YouTube/Facebook cần 1 cú bấm cho phép. |
| D3 | Khi thiếu/mơ hồ: Agent **tự quyết và ghi rõ lý do**; chỉ dừng khi không thể làm (0 sản phẩm, prompt không phải yêu cầu tạo video). |
| D4 | Tab Thủ công giữ **đầy đủ** mọi lựa chọn hiện có, chia 4 bước, có câu "Ý định của bạn". |
| D5 | Thủ công và Tự động dùng **chung một bộ truy vấn sản phẩm** (`execute_query`). |

## 4. Giao diện

### 4.1 Khung trang

- Đầu trang giữ: tiêu đề, link "Quản lý phiên live", 3 chỉ số (Chương trình gần đây / Chờ duyệt kịch bản / Đã lên lịch), trạng thái đồng bộ, banner tình trạng dịch vụ.
- Bộ chuyển chế độ (tablist, 2 tab):
  - **Tự động** — "Giao việc cho Syna bằng một câu"
  - **Thủ công** — "Tự chọn từng bước"
  - Tab đang chọn được nhớ bằng `localStorage` (bọc try/catch; mặc định Tự động).
- Bố cục: cột trái (≈65%) là nội dung tab; cột phải (≈35%) là **bảng Hoạt động** dùng chung. Dưới 1100px: bảng Hoạt động xuống dưới. Dưới 640px: một cột, không cuộn ngang.

### 4.2 Tab Tự động

Chỉ có 3 thành phần:

1. **Ô prompt** (textarea, 12–4000 ký tự, giữ giới hạn backend) + nút chính **"Giao cho Syna"**. Dòng gợi ý dưới ô: "Ghi sản phẩm (tên, mã, hoặc điều kiện như bán chạy 30 ngày, hạn 17–19 tháng), mục đích (video sản phẩm / livestream), giờ phát và giọng dẫn nếu muốn. Thiếu gì Syna sẽ tự quyết và ghi rõ."
2. **6 mẫu gợi ý** (chip). Bấm = điền vào ô prompt (ghi đè; có "Khôi phục lệnh trước" như hiện tại). Không tự gửi.

   | Chip | Prompt điền vào |
   |---|---|
   | Top bán chạy 30 ngày | Làm video sản phẩm cho 5 kem chống nắng bán chạy nhất trong 30 ngày qua, giọng tư vấn nhẹ nhàng, nhấn mạnh thành phần. |
   | Xả lô còn hạn | Livestream 30 phút lúc 20h tối mai bán 5 sản phẩm còn hạn sử dụng từ 17 đến 19 tháng, tồn kho trên 100 sản phẩm, giọng tập trung chốt sale. |
   | Xem nhiều, chưa mua | Làm video sản phẩm cho 5 sản phẩm được xem nhiều nhưng chưa chốt đơn trong 30 ngày qua, giải thích chi tiết. |
   | Giảm giá, bán chậm | Livestream 60 phút kèm video sản phẩm cho 5 sản phẩm đang giảm giá nhưng bán chậm trong 30 ngày qua, giọng tươi vui. |
   | Theo mã sản phẩm | Làm video giới thiệu sản phẩm mã 995 theo phong cách phân tích thành phần. |
   | Live có YouTube | Livestream 60 phút lúc 20h thứ Sáu giới thiệu 5 serum bán tốt trong 7 ngày qua, phát nội bộ và YouTube. |
3. **Thẻ "Syna đã quyết định"** cho lần chạy gần nhất (hoặc run đang chọn trong bảng Hoạt động): danh sách `decisions[]` theo thứ tự, mỗi dòng có biểu tượng mức độ:
   - ✓ `done` — đã làm theo đúng yêu cầu
   - ⚠ `adjusted` — đã tự điều chỉnh, kèm lý do (ví dụ "Xin 10, tìm được 7 → làm 7")
   - 🔒 `waiting` — chờ bạn (ví dụ "YouTube chờ bạn cho phép")
   - ✕ `stopped` — dừng, kèm gợi ý sửa prompt
   - Kèm danh sách sản phẩm đã chọn (ảnh nhỏ, tên, mã), mục đích, giờ phát, kênh, phong cách.
   - Khi `stopped`: nút "Sửa prompt" (đưa prompt cũ về ô nhập).

Bỏ hẳn khỏi tab này: Prompt Builder 6 nhóm, bảng livesearch sản phẩm, 2 chính sách "Tự tạo bản xem trước / Duyệt trước khi render", nút "Dùng prompt này".

Payload gửi: `{brief, mode: "autopilot", idempotency_key: "studio:<timestamp>"}` qua route có sẵn `admin_ai_idol_agent_create`.

### 4.3 Tab Thủ công — Wizard 4 bước

Thanh bước: `1 Sản phẩm · 2 Mục đích & lịch · 3 Nội dung & nhân vật · 4 Xem lại & tạo`. Cho phép bấm quay lại bước đã qua; đi tới bước sau chỉ khi bước hiện tại hợp lệ. Cuối form luôn có **thanh tóm tắt** cập nhật tức thì, ví dụ "5 SP · Video sản phẩm · 2 phong cách · Syna 3D 16:9", và nút Quay lại / Tiếp tục.

**Bước 1 — Sản phẩm.** Bố cục hai cột: bộ lọc (trái) + kết quả (phải).

Bộ lọc giữ đủ nhóm 1–4, nhãn và số đếm như hiện tại (`$filterCounts`, `$catalogTree`):

- *Danh mục* (chọn tối đa 1 nhánh): Nhóm → Danh mục → Phân loại (3 select phụ thuộc nhau, kèm số sản phẩm).
- *Kho & hạn dùng* (mỗi nhóm chọn 1): Hạn dùng (không lọc / còn 17–19 tháng / tối đa 18 tháng); Tồn kho (không lọc / >100 / ≤10 còn hàng / hết hàng); Phạm vi kho (tất cả / một kho / một lô + ô nhập mã).
- *Phân tích bán hàng* (chọn 1): không chọn, bán chạy 30 ngày, bán tốt 7 ngày, bán chạy toàn kỳ, doanh thu cao chuyển đổi thấp, xem nhiều chưa mua, thêm giỏ nhiều, giảm giá bán chậm, tăng trưởng 7 ngày.
- *Số lượng & thương hiệu*: Số lượng (tự chọn / Top 1 / 3 / 5 / 10); Thương hiệu ưu tiên (text); "Mỗi thương hiệu tối đa 2 sản phẩm"; "Ưu tiên sản phẩm còn đủ hàng để live".

Kết quả:

- Gọi API truy vấn có cấu trúc (§5.3), debounce 300ms khi đổi bộ lọc; hiển thị tối đa 100 dòng: checkbox, ảnh, tên, mã, tồn kho, chỉ số xếp hạng (ví dụ "bán 42 / 30 ngày").
- Ô tìm nhanh trong kết quả (lọc phía client), "Chọn tất cả" / "Bỏ chọn", bộ đếm "N đã chọn", chip sản phẩm đã chọn (chọn từ nhiều lần lọc khác nhau vẫn giữ).
- Chọn Top N → N dòng đầu được tick sẵn khi kết quả về (chỉ khi người dùng chưa tick tay ở lần lọc đó).
- `warnings` / `missing_data` từ bộ truy vấn hiển thị ngay trên danh sách (ví dụ "Chưa có số liệu 30 ngày cho 12 sản phẩm").
- Lỗi mạng/timeout: thông báo + nút "Thử lại"; không xóa lựa chọn đã tick.
- Hợp lệ khi: ≥1 sản phẩm được chọn.

**Bước 2 — Mục đích & lịch phát.** (Nhóm 6 đặt trước nội dung vì nó quyết định phong cách được phép.)

- 3 chế độ, giữ nguyên mô tả đầy đủ: Video sản phẩm (mặc định) / Livestream + video sản phẩm / Chỉ livestream.
- Nếu có livestream: ngày giờ (`datetime-local`), thời lượng (phút, 1–720), kênh phát (Nội bộ mặc định tick; YouTube; Facebook) + cảnh báo nếu kênh chưa cấu hình RTMP (lấy từ health).
- Ghi chú duyệt: video sản phẩm tự duyệt sau kiểm duyệt; có livestream thì bạn duyệt kịch bản trước khi render (giữ hành vi hiện tại của chương trình thủ công).
- Hợp lệ khi: video sản phẩm; hoặc có livestream + giờ hợp lệ trong tương lai + ≥1 kênh.

**Bước 3 — Nội dung & nhân vật.**

- Tên chương trình (không bắt buộc, ≤160).
- Cách kể chuyện: Giới thiệu sản phẩm / Vấn đề & giải pháp / Phân tích thành phần.
- **Cách dẫn chuyện của Syna** (nhóm 5, chọn nhiều, 9 phong cách §5.2). Khi mục đích = Video sản phẩm, "Tập trung chốt sale" và "Nhấn mạnh giá & ưu đãi" bị khóa kèm câu giải thích. "Ngắn gọn, nhanh" và "Giải thích chi tiết" loại trừ nhau (chọn cái này bỏ tick cái kia).
- Nhân vật: Syna 3D (mặc định) / ảnh hoặc video riêng (upload như hiện tại) + iframe xem thử chuyển động Syna.
- Khung hình (16:9 / 9:16; Syna 3D chỉ 16:9 như ràng buộc backend hiện có), phông nền (upload, tùy chọn).
- Hợp lệ khi: nhân vật riêng thì phải có file; còn lại luôn hợp lệ.

**Bước 4 — Xem lại & tạo.**

- Đoạn **"Ý định của bạn"**: một câu tiếng Việt hoàn chỉnh sinh từ lựa chọn (hàm thuần `describeIntent`, §6), ví dụ: *"Syna làm video sản phẩm (không nói giá) cho 5 sản phẩm: …, phong cách tư vấn nhẹ nhàng, nhấn mạnh thành phần, Syna 3D khung 16:9; video tự đăng lên trang sản phẩm khi sẵn sàng."*
- Các khối tóm tắt theo bước, mỗi khối có nút "Sửa" nhảy về đúng bước.
- Nút chính "Tạo chương trình" → `admin_ai_idol_create_campaign` (route có sẵn) với payload hiện tại **cộng** `configuration.narration_styles` (mảng khóa).

### 4.4 Bảng Hoạt động (chung)

- Nguồn dữ liệu: `admin_ai_idol_campaigns` + `admin_ai_idol_agent_runs` (có sẵn), gộp phía client:
  - Agent run **chưa có** `campaign_id` → thẻ run (đang hiểu prompt / đã dừng / lỗi).
  - Có chương trình → chỉ hiện thẻ chương trình, nhãn **Tự động** nếu `configuration.agent_managed`, ngược lại **Thủ công**. Thẻ chương trình tự động gắn với run để mở được log quyết định.
- Thanh tiến độ 7 bước suy ra từ trạng thái (hàm thuần `progressSteps`, §6): `Chọn SP → Kịch bản → Kiểm duyệt → Render → Lên lịch → Phát → Trang SP`. Bước không áp dụng thì ẩn (video sản phẩm không có Lên lịch/Phát; chỉ livestream không có Trang SP).
- Bộ lọc: **Tất cả** / **Cần bạn** / **Đã lên lịch**. "Cần bạn" = chương trình `AWAITING_APPROVAL`; chương trình có `pending_external_platforms` không rỗng; run `stopped`/`NEEDS_INPUT`; chương trình `NEEDS_ATTENTION` hoặc `FAILED` (cần thử lại). Hiển thị số đếm trên nút "Cần bạn".
- Chi tiết (mở trong cột khi bấm thẻ): video xem trước, danh sách segment và trạng thái (kể cả `SKIPPED` + lý do), kịch bản (sửa & duyệt khi chờ — tái dùng code chi tiết + `ai-idol-approval.js` hiện có), log quyết định, các nút: Duyệt kịch bản, **Cho phép phát YouTube/Facebook**, Thử lại, Đổi lịch, Hủy, Ẩn/hiện video trên trang SP.
- Run cũ (không có `mode`, trạng thái `NEEDS_INPUT`): hiện thông điệp + "Hủy" + "Dùng lại prompt" (đưa brief vào tab Tự động). UI xác nhận sản phẩm/lịch kiểu cũ bị bỏ; endpoint `confirm` ở backend giữ nguyên.
- Làm mới: 5 giây khi còn mục đang chạy, 30 giây khi không; dừng khi `document.hidden`.

## 5. Backend

### 5.1 Chế độ autopilot cho Agent run

- `create_agent_run` lưu thêm `mode` (`"autopilot"` | `"classic"`, mặc định `"classic"` → run cũ và client cũ không đổi hành vi). `mode` tham gia vào idempotency key.
- Run autopilot luôn dùng `autonomy_policy = "auto_preview"` cho việc duyệt kịch bản (kịch bản tự duyệt sau khi qua kiểm duyệt — đã có trong `should_auto_approve_script`).
- Payload tạo chương trình từ run autopilot thêm `configuration.agent_mode = "autopilot"`, `configuration.narration_styles` và `pending_external_platforms` (top-level). `create_campaign` nhận và chuẩn hóa `pending_external_platforms` (chỉ giữ giá trị thuộc `youtube`/`facebook`, chỉ khi mục đích có livestream).
- Logic tự quyết đặt ở module mới `ai-idol-service/agents/autopilot.py` gồm các **hàm thuần** (không đọc DB), `process_agent_run` gọi chúng khi `run.mode == "autopilot"`.
- Mỗi quyết định ghi vào `agent_run.decisions[]`: `{code, level: done|adjusted|waiting|stopped, message, at}` qua helper `append_decisions(run_id, items)` trong `core/agent_runs.py` (`$push`). `message` tiếng Việt, đã qua `redact_secrets`.

Bảng quy tắc (chỉ áp dụng khi `mode == "autopilot"`):

| Mã | Tình huống | Hành vi |
|---|---|---|
| `INTENT_CREATE` | Intent `PRODUCT_SEARCH` | Coi như `CREATE_CAMPAIGN`; mục đích mặc định `product_video` trừ khi prompt có giờ/từ "live". `done` |
| `INTENT_UNSUPPORTED` | Intent khác `CREATE_CAMPAIGN`/`PRODUCT_SEARCH` | Dừng: run `NEEDS_INPUT`, decision `stopped` "Prompt chưa phải yêu cầu tạo video/livestream…" |
| `COUNT_DEFAULT` | Prompt không nêu số lượng | `limit = AI_IDOL_AUTOPILOT_DEFAULT_PRODUCTS` (5). `adjusted` |
| `COUNT_CAP` | Số lượng > tối đa | `limit = AI_IDOL_AUTOPILOT_MAX_PRODUCTS` (10). `adjusted` |
| `COUNT_SHORT` | Tìm được k < n (k ≥ 1) | Làm k. `adjusted` "Xin n, tìm được k" |
| `NO_PRODUCTS` | k = 0 | Dừng, `stopped`, kèm `warnings` của bộ truy vấn và gợi ý nới điều kiện |
| `IDS_DROPPED` | Mã không tồn tại / ẩn | Bỏ mã đó, tiếp tục nếu còn ≥1. `adjusted` |
| `AMBIGUOUS_PICKED` | Tên sản phẩm mơ hồ | Lấy ứng viên đầu tiên của mỗi nhóm `ambiguous`. `adjusted` nêu tên đã chọn |
| `CONSTRAINT_DROPPED` | Mã nhập không đạt điều kiện hoạt chất | Bỏ mã đó. `adjusted` |
| `METRICS_FALLBACK` | Thiếu số liệu bán hàng theo kỳ / popularity chưa xác minh | Xếp hạng theo doanh số toàn kỳ trong catalog. `adjusted` "Chưa có số liệu 30 ngày, dùng doanh số toàn kỳ" |
| `LIVE_OOS_DROPPED` | Mục đích có livestream và SP hết hàng | Loại SP hết hàng khỏi chương trình (video SP vẫn giữ). `adjusted` |
| `SLOT_PICKED` | Livestream, không nêu giờ hoặc giờ không đọc được | Giờ vàng gần nhất trong `AI_IDOL_AUTOPILOT_SLOTS` ("12:00,20:00") còn đủ thời gian chuẩn bị (`_safe_schedule` lead time). `adjusted` |
| `SLOT_MOVED` | Giờ nêu đã qua / không đủ thời gian chuẩn bị | Dời sang thời điểm khả thi sớm nhất, làm tròn lên 15 phút. `adjusted` "19:00 → 19:30 vì cần chuẩn bị 4 SP" |
| `PLATFORM_INTERNAL` | Có livestream | `platforms = ["internal"]`. `done` |
| `PLATFORM_PENDING` | Prompt nhắc YouTube/Facebook và kênh đã cấu hình RTMP | Ghi `pending_external_platforms` vào chương trình. `waiting` "YouTube chờ bạn cho phép" |
| `PLATFORM_UNCONFIGURED` | Nhắc kênh chưa cấu hình RTMP | Không thêm pending. `adjusted` "Chưa cấu hình RTMP cho YouTube; chỉ phát nội bộ" |
| `STYLES_APPLIED` | Prompt chứa phong cách | Nhận diện theo bảng alias §5.2, lưu `configuration.narration_styles`. `done` |
| `STYLES_PRICE_DROPPED` | Video sản phẩm + phong cách nói giá | Bỏ phong cách nói giá. `adjusted` |
| `SCRIPT_SKIPPED` | Kịch bản một SP trượt kiểm duyệt sau 3 lần tự sửa | §5.4. `adjusted` |

Không đổi trong autopilot: lỗi kỹ thuật (TTS, render, renderer tắt → `WAITING_RENDERER`) vẫn theo cơ chế hiện có (thử lại / tự chạy tiếp), không bỏ sản phẩm.

Cấu hình mới (`config.py`, kèm `.env.example`): `AI_IDOL_AUTOPILOT_DEFAULT_PRODUCTS=5`, `AI_IDOL_AUTOPILOT_MAX_PRODUCTS=10`, `AI_IDOL_AUTOPILOT_SLOTS=12:00,20:00`. Giá trị sai → về mặc định + log cảnh báo.

### 5.2 Phong cách dẫn chuyện (sửa F1)

Module mới `ai-idol-service/core/narration.py`:

| Khóa | Nhãn | Nói giá | Alias nhận diện trong prompt (đã bỏ dấu) |
|---|---|---|---|
| `cheerful` | Tươi vui, dễ thương | | `vui tuoi`, `de thuong`, `tuoi tan` |
| `gentle` | Tư vấn nhẹ nhàng | | `nhe nhang`, `tu van nhe` |
| `expert` | Chuyên gia khoa học | | `chuyen gia`, `khoa hoc` |
| `closing` | Tập trung chốt sale | ✓ | `chot sale`, `chot don` |
| `concise` | Ngắn gọn, nhanh | | `ngan gon`, `nhanh gon` |
| `detailed` | Giải thích chi tiết | | `chi tiet` |
| `friendly` | Thân thiện như bạn bè | | `ban be`, `than thien` |
| `ingredients` | Nhấn mạnh thành phần | | `nhan manh thanh phan`, `noi ky thanh phan` |
| `pricing` | Nhấn mạnh giá & ưu đãi | ✓ | `nhan manh gia`, `uu dai`, `khuyen mai` |

Hàm: `normalize_styles(values, purpose) -> (styles, dropped)` (lọc khóa hợp lệ, bỏ trùng, bỏ khóa nói giá khi `product_video`, `concise`+`detailed` → giữ cái xuất hiện trước); `styles_from_text(text) -> list[str]`; `style_instruction(styles) -> str` (một dòng tiếng Việt cho prompt, rỗng nếu không có).

- `create_campaign` chuẩn hóa `configuration.narration_styles` (áp dụng cho cả chương trình thủ công).
- `content_agent.build_script_prompts` (cả 3 nhánh purpose) và payload script council thêm `style_instruction(...)` khi có.

### 5.3 API truy vấn sản phẩm có cấu trúc (sửa F2, F3)

- Flask: `POST /api/ai-idol/products/query`, body:
  ```json
  {"category_ids": [1001, 1002], "expiry": "expiry_17_19|expiry_max_18|", "stock": "stock_high|stock_low|out_of_stock|",
   "warehouse_id": "", "batch_id": "", "sales": "best_30|best_7|best_all|revenue_low_conversion|views_no_orders|cart|discount_slow|growth_7|",
   "brand": "", "per_brand_max": 2, "prefer_in_stock": true, "q": "", "limit": 100}
  ```
- Ánh xạ body → `plan` của `execute_query` bằng hàm thuần `plan_from_filters(filters, today)` trong `agents/command_query.py`, dùng đúng các trường plan hiện có (`stock_min/max`, `expiry_from/to/required`, `rank_by`, `window_days`, `brand`/`brand_mode="prefer"`, `per_brand_max`, `warehouse_id`, `batch_id`, `limit`). Thêm trường plan tùy chọn `category_ids` (lọc `ma_danh_muc`); `execute_query` chỉ áp dụng khi có. Ánh xạ sales phải khớp với ánh xạ prompt → plan hiện có (test so sánh, §7).
- Trả về: `{ok, products:[{product_id, name, image, stock, price, metric_label, metric_value, brand}], matched_count, warnings, missing_data, checked_at}`.
- Cache nguồn dữ liệu (catalog, batches, snapshots) trong bộ nhớ worker TTL 30 giây để giảm số lần đọc Atlas; không cache kết quả.
- PHP: route mới `admin_ai_idol_products_query` (POST, `checkAdmin`) → mở rộng L1/L2/L3 thành `category_ids` (tái dùng đoạn mở rộng có sẵn trong `getProducts`, tách thành private method), validate/giới hạn chuỗi, rồi proxy sang Flask. Thêm route vào `routes/ai_idol.php` và danh sách route trong `public/index.php`.
- Autopilot không đổi đường đi: vẫn `build_query_plan(brief)` → `execute_query`.

### 5.4 Bỏ qua sản phẩm trượt kiểm duyệt (sửa F4, chỉ autopilot)

- Trong `update_campaign_from_job`: nếu job `FAILED` với `failed_stage == "VALIDATING_SCRIPT"` **và** chương trình có `configuration.agent_mode == "autopilot"` → segment chuyển `SKIPPED` (giữ `error_message`), `progress.skipped` tăng, append decision `SCRIPT_SKIPPED` vào run (`configuration.agent_run_id`).
- Đếm trạng thái: `SKIPPED` không tính là `failed`. Khi không còn segment đang chạy/chờ duyệt/lỗi: còn ≥1 `READY` → chương trình đi tiếp như bình thường (assemble/lên lịch/đăng trang SP chỉ với segment `READY`); 0 `READY` → `NEEDS_ATTENTION`, `error_message = "Tất cả kịch bản đều trượt kiểm duyệt"`.
- `retry_campaign` không thử lại segment `SKIPPED`.
- Chương trình thủ công và run `classic`: giữ hành vi cũ.
- Plan phải xác minh: assemble/playlist/product page chỉ đọc segment `READY` (sửa nếu đang đọc tất cả `product_ids`).

### 5.5 Cho phép phát kênh ngoài (D2)

- Chương trình autopilot có thể mang `pending_external_platforms: ["youtube", ...]`.
- Flask: `POST /api/ai-idol/campaigns/<id>/platforms/approve`, body `{platform}`. Điều kiện: `platform` thuộc `pending_external_platforms`, có trong `RTMP_TARGETS`, chương trình đang ở `VALIDATING|GENERATING|AWAITING_APPROVAL|NEEDS_ATTENTION|SCHEDULED`. Cập nhật nguyên tử (`find_one_and_update` có điều kiện trạng thái): `$addToSet platforms`, `$pull pending_external_platforms`, ghi `platform_approvals[]` `{platform, by, at}`. Sai điều kiện → 409/400 kèm thông điệp tiếng Việt. Append decision `done` "Đã cho phép phát YouTube".
- PHP: route `admin_ai_idol_approve_platform` (POST, `checkAdmin`, `created_by` từ session) → proxy.
- Không bấm trước giờ phát → chỉ phát nội bộ; khi chương trình lên sóng, pending bị bỏ qua (UI hiện "Đã hết hạn cho phép").
- Plan phải xác minh `reschedule_campaign` (kiểm tra kênh không cục bộ ở `campaigns.py:330`) vẫn đúng sau khi thêm kênh.

### 5.6 Tương thích

- Mọi route/API hiện có giữ nguyên hình dạng response; chỉ **thêm** trường (`mode`, `decisions`, `pending_external_platforms`, `progress.skipped`, `narration_styles`).
- Run/chương trình cũ không có trường mới → hiển thị như trước.
- `confirm`/`retry`/`cancel` agent run, `approve_script`, `video_visibility` không đổi.

## 6. Cấu trúc frontend

Tách `frontend/public/assets/js/ai-idol-studio.js` (1343 dòng) thành:

| File | Trách nhiệm |
|---|---|
| `studio-logic.js` | Hàm thuần, không DOM, export cho cả browser (`window.StudioLogic`) và Node (`module.exports`): `describeIntent(selection)`, `progressSteps(item)`, `needsYou(item)`, `allowedStyles(purpose)`, `toggleStyle(current, key)`, `summaryBar(selection)`, `mergeActivity(campaigns, runs)`. |
| `studio-core.js` | `api()` (fetch + xử lý lỗi), escape, format ngày giờ theo `Asia/Ho_Chi_Minh`, polling, chuyển tab, health banner, thống kê đầu trang. |
| `studio-manual.js` | Wizard 4 bước, bộ lọc + gọi `admin_ai_idol_products_query`, chọn sản phẩm, upload, submit. |
| `studio-auto.js` | Ô prompt, mẫu gợi ý, gửi run autopilot, thẻ quyết định. |
| `studio-activity.js` | Bảng Hoạt động, bộ lọc, chi tiết, các hành động. |

- `ai-idol-approval.js`, `admin-ai-idol-alerts.js` giữ nguyên.
- `ai-idol-studio.css` cập nhật cho bố cục mới; bỏ CSS của Prompt Builder trong tab Agent và livesearch cũ khi không còn dùng.
- View `frontend/views/admin/ai_idol/index.php` viết lại theo §4; giữ `window.__IDOL_CATALOG__`, `window.__IDOL_FILTER_COUNTS__`; script load theo thứ tự logic → core → manual → auto → activity, cache-bust bằng `filemtime`.
- Accessibility: tablist/tab/tabpanel đúng ARIA, focus chuyển vào bước mới, thông báo `aria-live` cho kết quả lọc và thẻ quyết định, mọi control dùng được bằng bàn phím.

## 7. Xử lý lỗi

- Dịch vụ AI không phản hồi: banner health; nút tạo vẫn bấm được, lỗi trả về hiển thị trong form với hướng dẫn bật Docker/Syna.
- Agent run lỗi orchestration: thẻ run trạng thái lỗi + "Thử lại" (endpoint retry có sẵn).
- API truy vấn sản phẩm lỗi/timeout (≥10 giây): thông báo trong khung kết quả + "Thử lại"; giữ lựa chọn.
- Cho phép kênh ngoài thất bại (409 do đã lên sóng, 400 do chưa có RTMP): thông điệp cụ thể trong chi tiết.
- Mọi thông điệp tiếng Việt, nói việc cần làm; không hiện stack trace, khóa RTMP, chuỗi kết nối.

## 8. Kiểm thử và tiêu chí chấp nhận

**Python (pytest, `ai-idol-service/tests`)**
- Mỗi mã quy tắc §5.1 có ít nhất một test (dùng catalog/fake DB hiện có trong `tests/fakes.py`, `tests/pb_catalog.py`).
- Run `classic` (không có `mode`) giữ nguyên kết quả: toàn bộ test hiện có vẫn pass.
- `narration.py`: chuẩn hóa, alias, bỏ phong cách nói giá, loại trừ `concise`/`detailed`; `build_script_prompts` chứa dòng phong cách khi có, không chứa khi không có.
- `plan_from_filters` + `execute_query` với `category_ids`; với mỗi lựa chọn sales, plan từ bộ lọc khớp plan từ câu prompt tương ứng của Prompt Builder cũ (`fixtures/prompt_builder/cases.py`).
- `SKIPPED`: 1/3 segment trượt → chương trình tiếp tục với 2; 3/3 trượt → `NEEDS_ATTENTION`; chương trình thủ công trượt → hành vi cũ.
- Approve platform: thành công, sai platform, chưa RTMP, đã `LIVE`, gọi hai lần (idempotent).

**PHP**
- `php -l` các file đổi; test hợp đồng cho `admin_ai_idol_products_query` và `admin_ai_idol_approve_platform` (yêu cầu admin, validate input, hình dạng proxy) theo mẫu `backend/tests/admin_usecase_contract_test.php`.

**JavaScript**
- `node --test` cho `studio-logic.js`: `describeIntent` cho 3 mục đích, `progressSteps` cho mọi trạng thái chương trình/run, `needsYou`, `allowedStyles`, `mergeActivity`.
- `node --check` cho các file JS mới.

**E2E (Playwright, trình duyệt thật, `localhost:8080`, tài khoản admin test của dự án)**
1. Thủ công: đi hết 4 bước (lọc "Bán chạy · toàn kỳ" + Top 3 → 3 SP tick sẵn → Video sản phẩm → phong cách "Tư vấn nhẹ nhàng" → câu ý định đúng) → tạo → chương trình xuất hiện trong Hoạt động với nhãn Thủ công.
2. Tự động: gửi một prompt mẫu → thẻ quyết định hiện các dòng ✓/⚠ → chương trình xuất hiện với nhãn Tự động.
3. Bộ lọc "Cần bạn" hiển thị đúng số đếm.
4. Màn hình 390px: không cuộn ngang, tab và wizard dùng được.
5. Console không có lỗi JS.

**Tiêu chí chấp nhận**
- AC1: Tab Tự động không còn bộ lọc/bảng sản phẩm; một prompt hợp lệ tạo được chương trình mà không cần thao tác thêm (trừ cho phép kênh ngoài).
- AC2: Tab Thủ công có đủ mọi lựa chọn liệt kê ở §4.3 và số đếm như trang cũ.
- AC3: Cùng điều kiện lọc, Thủ công và Tự động chọn ra cùng tập sản phẩm.
- AC4: Phong cách dẫn đã chọn xuất hiện trong prompt viết kịch bản.
- AC5: Autopilot không dừng trong các tình huống "tự quyết" ở §5.1; mỗi quyết định có dòng giải thích.
- AC6: Một SP trượt kiểm duyệt không chặn các SP còn lại (autopilot).
- AC7: Không có kênh ngoài nào được phát khi chưa có người bấm cho phép.
- AC8: Mọi test hiện có của `ai-idol-service` và `backend/tests` liên quan vẫn pass.

## 9. Rủi ro

| Rủi ro | Giảm thiểu |
|---|---|
| Autopilot tự quyết sai ý (ví dụ chọn ứng viên mơ hồ) | Mọi quyết định `adjusted` được ghi rõ; nút Hủy trước giờ phát; video SP có thể ẩn khỏi trang SP. |
| Chi phí render khi prompt rộng | Trần `AI_IDOL_AUTOPILOT_MAX_PRODUCTS`. |
| Truy vấn toàn catalog mỗi lần lọc chậm trên Atlas | Cache nguồn 30 giây, debounce 300ms, giới hạn 100 dòng. |
| Tách JS 1343 dòng gây hồi quy | Hàm thuần có unit test; E2E đi đủ luồng chính; giữ API/route cũ. |
| Worktree đang có nhiều thay đổi chưa commit liên quan AI Idol | Chỉ sửa file trong phạm vi; không đụng/hoàn tác thay đổi khác; GitNexus impact trước mỗi lần sửa symbol. |

## 10. File dự kiến thay đổi

- Mới: `ai-idol-service/agents/autopilot.py`, `ai-idol-service/core/narration.py`, test tương ứng; `frontend/public/assets/js/studio-{logic,core,manual,auto,activity}.js`, `frontend/public/assets/js/tests/studio-logic.test.js`.
- Sửa: `ai-idol-service/agents/service.py`, `agents/command_query.py`, `core/agent_runs.py`, `core/campaigns.py`, `engines/content_agent.py`, `app.py`, `config.py`, `.env.example`; `backend/app/controllers/AdminAiIdolController.php`, `backend/app/routes/ai_idol.php`, `backend/public/index.php`; `frontend/views/admin/ai_idol/index.php`, `frontend/public/assets/css/ai-idol-studio.css`.
- Ngừng dùng ở trang Studio: `frontend/public/assets/js/ai-idol-studio.js` (xóa sau khi E2E pass, trong cùng thay đổi).
