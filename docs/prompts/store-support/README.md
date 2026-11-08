# Bộ prompt Trung tâm hỗ trợ + Chatbot chính sách & HDSD

Mỗi file `step-XX-*.md` là **một prompt tự đủ ngữ cảnh**: mở chat mới, dán nguyên nội dung file, không cần giải thích thêm.

Thiết kế gốc (nguồn quyết định): `docs/superpowers/specs/2026-10-21-store-support-chatbot-design.md`
Sổ bàn giao (mọi bước đọc trước, ghi sau): `docs/plans/store-support-handoff.md`
Tài liệu tổng (tra cứu): `update_store_support_chatbot.md`

| File | Bước | Tầng | Phụ thuộc |
|---|---|---|---|
| step-00-audit.md | Khảo sát + impact (chỉ đọc) | tất cả | — |
| step-01-single-source.md | Một nguồn sự thật + gộp route hỗ trợ | PHP | 00 |
| step-02-guide-content.md | Viết bài HDSD theo code thật | nội dung | 01 |
| step-03-help-center-ui.md | Trung tâm hỗ trợ dropdown nhiều cấp | frontend | 01 (02 để có dữ liệu thật) |
| step-04-php-ranking.md | `SupportKnowledge::rank` + gửi bài sang Flask + fallback | PHP | 01 |
| step-05-flask-store-support.md | Nhánh STORE_SUPPORT + prompt LLM + hậu kiểm | Flask | 04 |
| step-06-chat-widget.md | Widget: nguồn, chip gợi ý, menu "Trợ giúp" 2 cấp | frontend | 05 |
| step-07-final-verify.md | Kiểm thử tổng, E2E, tài liệu | tất cả | tất cả |

**Thứ tự khuyến nghị:** 00 → 01 → 04 → 05 → 02 → 03 → 06 → 07.
(04/05 chạy được với 20 bài cũ; 02 bổ sung bài mới rồi 03/06 hiển thị.)

## Luật chung (mọi bước đều phải theo — đã chép vào từng file)

1. **Không phát minh.** Chỉ mở rộng thứ đã có trong repo (`support.json`, `SupportKnowledge`, `SupportController`, `aiChatAssistant`, `_prepare_pipeline`, `llm_pool`, `get_cross_encoder`, widget `formatMarkdown`/`quicksend:`). Muốn thêm thư viện, service, collection, route API mới → **dừng, hỏi người dùng**.
2. **Một nguồn sự thật**: `backend/app/content/support.json`. Hotline `0398606281`, email `cskh@skinsyntax.com`, giờ hỗ trợ **chưa công bố** (người dùng chốt 2026-10-21). Không viết cứng thông tin liên hệ ở nơi khác.
3. **Không bịa số liệu chính sách** (ngày, phí, %, giờ). Không có trong `support.json` hay code → không viết.
4. **GitNexus impact trước khi sửa** symbol có sẵn: `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`. HIGH/CRITICAL → dừng, báo người dùng. UNKNOWN → xác nhận bằng `rg`, không coi là an toàn.
5. **Không chạy hàng loạt script trong `backend/tests/`** (nhiều script ghi DB thật). Test mới phải thuần, không kết nối DB.
6. Giữ thay đổi sẵn có của người dùng (`git status` trước). Không commit/push trừ khi được yêu cầu; nếu commit, chạy `node .gitnexus/run.cjs detect-changes --scope all --repo .` trước.
7. Không log nội dung khách, OTP, mật khẩu, token, chuỗi kết nối.
8. Trả lời và viết tài liệu bằng **tiếng Việt**.
9. Không tuyên bố PASS cho lệnh chưa chạy thật. E2E không chạy được → ghi `E2E NOT RUN` + lý do.

## Cơ chế bàn giao

- Mọi bước **đọc trước** `docs/plans/store-support-handoff.md` thay vì đọc lại lịch sử chat.
- Mọi bước **kết thúc** bằng một mục mới trong file đó (≤ 25 dòng) theo mẫu có sẵn.
- Bước nào phát hiện thực tế khác spec → ghi rõ trong handoff mục "Lệch spec", **không tự đổi thiết kế lớn**.
