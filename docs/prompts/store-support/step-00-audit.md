# BƯỚC 00 — KHẢO SÁT HIỆN TRẠNG + IMPACT (CHỈ ĐỌC)

## Vai trò
Kỹ sư khảo sát. **Không sửa code.** Xác nhận các giả định của spec bằng bằng chứng (file:dòng, output lệnh) để các bước sau không đoán.

## Luật bất di bất dịch
- Chỉ đọc. File duy nhất được ghi: `docs/plans/store-support-handoff.md` (thêm mục Bước 00).
- Không phát minh; không chạy script trong `backend/tests/`; không log dữ liệu khách.
- Trả lời tiếng Việt.

## Đọc trước
1. `AGENTS.md`
2. `docs/superpowers/specs/2026-10-21-store-support-chatbot-design.md` (toàn bộ)
3. `docs/plans/store-support-handoff.md`

## Câu hỏi phải trả lời (mỗi câu kèm bằng chứng)
1. **Luồng chat thật**: widget `frontend/views/components/ai_chat_widget.php` gọi URL nào, JSON hay stream? `HomeController::aiChatApi/aiChatStream/aiChatAssistant` đi tới endpoint Flask nào (`getAiChatEndpoint`)? Trong Flask, route đó (`chatbot_service/chatbot_flask.py` hoặc `ai-service-flask/chatbot_flask.py`) có gọi `pipeline._prepare_pipeline()` / `xu_ly_cau_hoi()` không, hay có nhánh riêng (agent `should_use_agent`, consultation)? Vẽ chuỗi gọi chính xác.
2. Flask đọc những khóa nào từ JSON `message` do PHP dựng (`customer_question`, `retrieved_products`, …)? Khóa lạ có bị bỏ qua không? Chỉ ra chỗ parse.
3. Thứ tự nhánh trong `_prepare_pipeline()` (chitchat, off-topic, …) và nội dung `_SKINCARE_SHOP_SIGNALS` / `_OFF_TOPIC_SIGNALS`. Thử bằng Python (không gọi LLM) các câu: "chính sách cookie là gì", "đổi mật khẩu thế nào", "đổi trả mấy ngày", "phí ship bao nhiêu" → `_is_skincare_or_shop_query` trả gì?
4. `get_cross_encoder()` trả đối tượng gì, gọi chấm điểm cặp (query, passage) bằng hàm nào? Có sẵn trong container/local không (đọc cấu hình, không tải model mới)?
5. `response_cache.should_cache_request` / khóa cache dựng từ gì?
6. `index.php`: các `case` `ho_tro_khach_hang`, `bao_hanh`, `tro_giup`, `faq`, các alias trong `backend/app/routes/store_assistance.php`. Xác nhận alias không bao giờ chạy với route đã có `case`.
7. Mọi chỗ viết cứng liên hệ: `rg -n "1900 0000|08:00 - 22:00|0398606281|cskh@" backend frontend --glob '!vendor'`.
8. Nơi đọc `support.json` / `sections.items` (để bước 01 biết chỗ nào phải xử lý item dạng object).
9. Kiểm tra từng route nguồn trong bảng HDSD (spec mục 4.1) tồn tại, dành cho khách hay nhân viên, controller + view tương ứng.
10. Lệnh test có sẵn: PHP (`backend/tests/store_assistance_test.php`, `check_store_cases.php` — đọc xem có kết nối DB không), Python (`ai-service-flask/tests`, cách chạy pytest trong môi trường repo), Playwright/E2E harness (`.claude/skills/e2e/SKILL.md`).

## GitNexus impact (chạy thật, dán tóm tắt)
```
node .gitnexus/run.cjs impact "aiChatAssistant" --direction upstream --repo .
node .gitnexus/run.cjs impact "buildAiAssistantPrompt" --direction upstream --repo .
node .gitnexus/run.cjs impact "_prepare_pipeline" --direction upstream --repo .
node .gitnexus/run.cjs impact "SupportKnowledge" --direction upstream --repo .
node .gitnexus/run.cjs impact "customerSupport" --direction upstream --repo .
node .gitnexus/run.cjs impact "warrantyCenter" --direction upstream --repo .
```
Index cũ/không chạy được → ghi rõ, thử `node .gitnexus/run.cjs analyze --index-only`; vẫn không được → ghi BLOCKED cho phần impact, tiếp phần còn lại. Ghi risk từng symbol; HIGH/CRITICAL → đánh dấu nổi bật trong handoff để người dùng thấy.

## Đầu ra
Mục "Bước 00" trong `docs/plans/store-support-handoff.md`: trả lời 10 câu (ngắn, có file:dòng), bảng impact, danh sách **Lệch spec** (nếu có), lệnh test xác nhận dùng được.

## Tiêu chí hoàn thành
10 câu có bằng chứng; impact đã chạy hoặc ghi rõ lý do; không file code nào thay đổi (`git status` chứng minh).
