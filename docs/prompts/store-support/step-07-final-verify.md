# BƯỚC 07 — KIỂM THỬ TỔNG, ĐÁNH GIÁ CHẤT LƯỢNG TRẢ LỜI, TÀI LIỆU

## Vai trò
Kiểm thử viên + người viết tài liệu. Xác nhận toàn bộ tính năng chạy thật đầu–cuối, đo chất lượng câu trả lời, cập nhật tài liệu. Chỉ sửa lỗi nhỏ phát hiện được; lỗi lớn → ghi lại, báo người dùng.

## Luật bất di bất dịch
- Không tuyên bố PASS khi chưa chạy. Không sửa test cho dễ pass.
- Không chạy hàng loạt script `backend/tests/` (ghi DB thật) — chỉ test thuần đã được các bước trước tạo/xác nhận.
- Không commit/push trừ khi người dùng yêu cầu; nếu được yêu cầu: `node .gitnexus/run.cjs detect-changes --scope all --repo .` trước (kết quả `partial`/`truncated` → chạy lại).
- Trả lời tiếng Việt.

## Đọc trước
1. `AGENTS.md`, `docs/plans/store-support-handoff.md` (tất cả các bước)
2. Spec toàn bộ
3. `.claude/skills/e2e/SKILL.md`, `.claude/skills/lint/SKILL.md`

## Việc phải làm
1. **Test tự động**: chạy toàn bộ test các bước tạo ra (PHP unit, pytest `test_store_support.py`) + hồi quy (`test_chat_regressions.py`, `test_acceptance_fixes.py`, test cũ an toàn của `SupportKnowledge`). Lint file đã sửa.
2. **Bộ đánh giá chất lượng** `ai-service-flask/tests/store_support_eval_cases.json` (nếu chưa có thì tạo) — ≥40 câu:
   - 25 câu chính sách/HDSD (có không dấu, viết tắt, hai ý trong một câu), mỗi câu kèm `expected_slug` và `must_contain` (giá trị lấy từ `support.json`, vd "7 ngày", "30.000").
   - 10 câu sản phẩm/da → `expected_intent != STORE_SUPPORT`.
   - 5 câu tấn công: prompt injection, xin OTP, yêu cầu hứa hoàn tiền, hỏi chính sách không tồn tại ("đổi trả 60 ngày phải không"), hỏi giờ hỗ trợ (phải nói chưa công bố/không có thông tin).
   Viết script chạy bộ này qua endpoint thật (`ai_chat_api` hoặc Flask `/api/chat/auto` với payload do PHP dựng) và báo: tỉ lệ đúng slug, tỉ lệ chứa `must_contain`, số câu bị số liệu lạ (phải 0), tỉ lệ fallback, p50/p95 thời gian. Lưu kết quả `report/store-support-e2e/eval-<ngày>.json` + tóm tắt.
3. **E2E Playwright đầu–cuối**: gộp các bảng kiểm Bước 01, 03, 06 thành một lượt chạy; ảnh vào `report/store-support-e2e/final/`.
4. **Rà nguồn sự thật**: `rg -n "1900 0000|08:00 - 22:00" frontend backend --glob '!vendor' --glob '!backend/experiments/**'` → không còn trên giao diện khách.
5. **Tài liệu**:
   - Hoàn thiện `update_store_support_chatbot.md` (kiến trúc, cách thêm/sửa bài trong `support.json`, quy tắc viết bài HDSD, ngưỡng, khóa payload/phản hồi, cách chạy test/eval, giới hạn đã biết).
   - Thêm mục tóm tắt + link vào `update.md` (file tổng) — đúng định dạng các mục hiện có.
   - Đánh dấu trạng thái spec là "Đã triển khai" kèm ngày.
6. `git diff --check`; liệt kê file đổi theo tầng.

## Đầu ra
Báo cáo trong handoff mục "Bước 07": bảng kết quả test/E2E/eval, file đã đổi, việc bỏ qua + lý do, rủi ro còn lại, đề xuất bước tiếp (ví dụ trang admin soạn bài).

## Tiêu chí hoàn thành
Test + hồi quy pass; eval: đúng slug ≥ 90%, số liệu lạ = 0, câu sản phẩm không bị hút; E2E đạt hoặc NOT RUN có lý do; tài liệu cập nhật.
