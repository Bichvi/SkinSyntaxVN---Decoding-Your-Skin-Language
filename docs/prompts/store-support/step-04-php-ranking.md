# BƯỚC 04 — XẾP HẠNG BÀI HỖ TRỢ + GỬI SANG FLASK + FALLBACK (PHP)

## Vai trò
Kỹ sư backend PHP. Cho chatbot biết bài hỗ trợ nào liên quan tới câu hỏi và gửi nội dung đó sang Flask qua **đúng một khóa mới** `store_support_articles`. Không đổi hành vi câu hỏi sản phẩm.

## Luật bất di bất dịch
- Không phát minh: mở rộng `SupportKnowledge` + `HomeController::aiChatAssistant()/buildAiAssistantPrompt()`. Không thêm endpoint, không gọi LLM từ PHP, không coupling vào nội bộ Flask.
- `match()`/`answer()` giữ nguyên hành vi (test cũ phải pass).
- GitNexus impact bắt buộc cho `aiChatAssistant`, `buildAiAssistantPrompt`, `SupportKnowledge`; đây là luồng chat trung tâm — HIGH/CRITICAL → **dừng, báo người dùng, chờ đồng ý**.
- Không log nội dung câu hỏi; log chỉ slug + điểm + thời gian.
- Không chạy script ghi DB. Trả lời tiếng Việt. Không commit trừ khi được yêu cầu.

## Đọc trước
1. `AGENTS.md`, `docs/plans/store-support-handoff.md` (Bước 00 câu 1–2, 10; Bước 01)
2. Spec mục 3, 6.1, 6.2, 8
3. `backend/app/services/SupportKnowledge.php`, `backend/app/content/support.json`, `backend/app/services/PurchaseIntent.php`, `HomeController.php`: `aiChatAssistant` (~dòng 3088–3342), `buildAiAssistantPrompt` (~2949), `postJsonRequest`, nhánh xử lý lỗi/timeout Flask.
4. Test có sẵn: `backend/tests/store_assistance_test.php`, `backend/tests/check_store_cases.php` (đọc để biết bộ câu mẫu).

## Yêu cầu
1. **`SupportKnowledge::rank(string $message, int $limit = 3): array`**
   - Trả `[['article'=>array,'score'=>float,'url'=>string], ...]` giảm dần, chỉ phần tử `score >= LOW_THRESHOLD`.
   - Điểm = (a) điểm cụm của `match()` hiện tại cho bài đó (tách logic chấm cụm ra private method dùng chung, **không** đổi kết quả `match()`) + (b) điểm token: `fold` câu hỏi, bỏ stopword (hằng số `STOPWORDS`: `cho, minh, toi, ban, thi, a, nhe, voi, la, cua, co, khong, sao, nao, the, lam, duoc, gi, o, dau, oi, shop, em, anh, chi, vay, ha, hả`→đã fold), token ≥2 ký tự; trùng với token của `title` ×3, `questions` ×2, `triggers` ×2, `summary` ×1, chuẩn hóa theo số token câu hỏi.
   - Khớp nguyên câu `questions` (sau `fold`) → điểm tối đa (`PHP_INT_MAX` hoặc hằng số `EXACT_SCORE`).
   - `LOW_THRESHOLD`, `HIGH_THRESHOLD` là hằng số public, hiệu chỉnh bằng bộ test (ghi giá trị cuối vào handoff).
   - Câu chỉ chứa lời chào/cảm ơn → rỗng.
2. **`SupportKnowledge::toChatContext(array $ranked): array`** → mỗi bài `{slug,title,summary,answer,sections:[{title,items:[{text,details[]}]}],url,version,score,questions}`; cắt tổng ≤ 2.500 ký tự/bài (cắt item cuối trước, không cắt giữa từ).
3. **`aiChatAssistant()`**
   - Gọi `rank()` **một lần** sau khi có `$message`, trước khi dựng payload. Lỗi đọc kho → bỏ qua (log `[STORE_SUPPORT] knowledge_unavailable`), luồng cũ chạy tiếp.
   - Có kết quả → thêm `store_support_articles` vào mảng JSON trong `buildAiAssistantPrompt` (khóa cấp cao nhất, cạnh `customer_question`). Không đổi các khóa cũ.
   - Không có kết quả → payload **giống hệt** trước (test phải chứng minh).
   - Flask lỗi/timeout/non-2xx **và** bài đầu `score >= HIGH_THRESHOLD` → trả `answer` của bài + các bước của section đầu dạng "1. …", dòng `Nguồn: [title](url)`; `pipeline_mode='verified_store_content'`, `intent_mode='STORE_SUPPORT'`, `fallback=true`, `support_topic`, `sources`, `suggested_questions` (≤3 từ `questions` của các bài trong ranked, bỏ câu trùng câu khách). Ngược lại giữ câu lỗi hiện tại.
   - Khi Flask trả về `intent_mode == 'STORE_SUPPORT'`: chuyển tiếp `sources`, `suggested_questions`, `support_topic` vào JSON gửi widget (khóa mới, không đổi khóa cũ).
   - Không đổi nhánh lời chào, routine, sản phẩm hiện tại, `storeAiResponsePayload`.
4. Hàm dựng câu trả lời fallback đặt trong `SupportKnowledge` (`formatFallbackAnswer(array $ranked): string`) để test được thuần.

## Quy trình bắt buộc
1. `git status`; impact (3 symbol trên) → báo.
2. TDD: `backend/tests/unit/SupportRankTest.php` (thuần):
   - Mỗi bài cũ: câu trong `questions` → hạng 1.
   - Bộ diễn đạt khác (≥30 câu, có không dấu, viết tắt, lỗi chính tả nhẹ), ví dụ: "muốn trả lại hàng thì làm sao", "ship bao nhiêu tiền", "quen pass", "ko nhan dc ma otp", "xoa tai khoan kieu gi", "xuat hoa don do duoc khong", "giao sai hang thi sao", "so dien thoai shop", "huy don duoc khong", "cookie de lam gi" → đúng slug hạng 1.
   - Câu sản phẩm/da (≥10: "serum cho da dầu", "kem chống nắng nào tốt", "retinol dùng với aha được không", "da mụn nên dùng gì"…) → rỗng (dưới LOW_THRESHOLD).
   - Lời chào → rỗng. `match()`/`answer()` cho bộ câu của `check_store_cases.php` không đổi.
   - `toChatContext` cắt đúng giới hạn; `formatFallbackAnswer` có link đúng.
   - Payload: hàm dựng prompt (tách phần thuần nếu cần, không cần DB) không có khóa mới khi ranked rỗng.
3. Implement; hiệu chỉnh ngưỡng tới khi test pass — **không** sửa test cho dễ pass; câu không đạt thì thêm trigger vào `support.json` (ghi vào handoff) hoặc ghi là giới hạn đã biết.
4. Kiểm chứng.

## Kiểm chứng (chạy thật)
- `php -l` mọi file sửa; test mới + test cũ an toàn pass.
- Đo `rank()` trên 100 câu: p95 < 5ms (ghi số).
- Gọi thật (nếu dịch vụ chạy) `POST /index.php?r=ai_chat_api` với "đổi trả trong bao lâu" khi **tắt** Flask (hoặc trỏ endpoint sai qua env cục bộ, không sửa `.env` của người dùng mà dùng biến môi trường tạm) → nhận fallback có link. Ghi lệnh + kết quả. Không chạy được → `NOT RUN` + lý do.
- `git diff --check`.

## Đầu ra
Code + test; mục "Bước 04" trong handoff: ngưỡng cuối, công thức điểm, trigger đã thêm, câu chưa đạt, hình dạng `store_support_articles` thực tế (1 ví dụ rút gọn) — **Bước 05 dựa vào đây**, đồng thời lưu ví dụ đó vào `ai-service-flask/tests/fixtures/store_support_payload.json`.

## Tiêu chí hoàn thành
Test xếp hạng đạt; câu sản phẩm không bị hút; payload không đổi khi không có bài; fallback hoạt động.
