# BƯỚC 06 — WIDGET CHAT: NGUỒN, CHIP GỢI Ý, MENU "TRỢ GIÚP" 2 CẤP

## Vai trò
Kỹ sư frontend. Làm cho câu trả lời STORE_SUPPORT trong widget Syna rõ nguồn, dễ hỏi tiếp, và thêm menu dropdown "Trợ giúp" nhiều cấp để khách chọn câu hỏi không cần gõ.

## Luật bất di bất dịch
- Sửa tối thiểu trong `frontend/views/components/ai_chat_widget.php`; tái dùng `formatMarkdown`, `quicksend:`, `sendMessage`, `escapeHtml`, class `ai-chat-widget__*`. Không thư viện mới, không API mới.
- Dữ liệu menu render server-side từ `support.json` (chỉ `groups` + `title` + `questions` + `slug`) qua `SupportKnowledge`; không gửi `answer`/`sections` xuống client.
- Mọi dữ liệu vào DOM phải escape; không `innerHTML` với chuỗi chưa escape.
- Không đổi hành vi tin nhắn sản phẩm, thẻ sản phẩm, cảnh báo xung đột, lịch sử.
- GitNexus impact nếu sửa hàm dùng chung trong widget/controller. Trả lời tiếng Việt. Không commit trừ khi được yêu cầu.
- Đọc `.claude/skills/frontend-testing/SKILL.md`, `.claude/skills/e2e/SKILL.md` trước.

## Đọc trước
1. `AGENTS.md`, `docs/plans/store-support-handoff.md` (Bước 03: việc cần widget làm; Bước 05: khóa phản hồi mới)
2. Spec mục 5.3
3. `ai_chat_widget.php`: hàng chip (~dòng 97–100), `renderMessages` (~1786), `sendMessage` (~1994), bộ bắt click (~2241), `formatMarkdown` (~1587).

## Yêu cầu
1. **Tin nhắn STORE_SUPPORT** (`intent_mode === 'STORE_SUPPORT'`, lưu khóa này trong message như các khóa khác):
   - Nhãn nhỏ phía trên nội dung: "📘 Thông tin chính thức của SkinSyntax".
   - Dòng `Nguồn: [..](..)` hiển thị như thẻ nguồn nhỏ (link mở trang bài, cùng tab).
   - Chip `quicksend:` hiển thị thành hàng chip cuộn ngang dưới câu trả lời (CSS class, không inline style mới).
   - `pipeline_mode === 'verified_store_content'` hiển thị giống hệt (không lộ cho khách là fallback).
   - Lưu/khôi phục lịch sử: các khóa mới tồn tại sau tải lại trang.
2. **Menu "Trợ giúp"** trong hàng chip nhanh:
   - Nút chip "❓ Trợ giúp" `aria-haspopup="menu"`, `aria-expanded`.
   - Mở ra panel dropdown trong widget: **cấp 1** danh sách nhóm (icon + tiêu đề); chọn nhóm → **cấp 2** câu hỏi mẫu của các bài trong nhóm (tối đa 2 câu/bài) + "Xem tất cả trong Trung tâm hỗ trợ ↗" (`?r=ho_tro_khach_hang#nhom-<key>`); nút "← Quay lại" về cấp 1.
   - Chọn câu hỏi → đóng menu, `sendMessage(câu)`.
   - Bàn phím: mũi tên lên/xuống di chuyển, Enter chọn, Esc đóng/quay lại, focus trả về nút chip; click ra ngoài đóng.
   - Mobile: panel chiếm toàn chiều rộng widget, cao tối đa 60% vùng chat, cuộn trong.
3. **Hook mở widget từ trang** (nếu Bước 03 ghi cần): click link `quicksend:` khi panel đang `hidden` → mở widget rồi gửi. Không đổi hành vi khi widget đang mở.
4. Ô nhập placeholder thêm gợi ý: "…hoặc hỏi về đổi trả, vận chuyển, voucher".

## Quy trình bắt buộc
1. `git status`; impact nếu cần.
2. Viết kịch bản kiểm trước (Playwright) theo bảng dưới.
3. Implement.
4. Kiểm chứng.

## Kiểm chứng (chạy thật)
- `php -l` file sửa.
- Playwright tại `http://localhost:8080` (dịch vụ AI chạy; không chạy được → mock phản hồi `ai_chat_api` bằng route interception của Playwright và ghi rõ):
  | Kịch bản | Kỳ vọng |
  |---|---|
  | Hỏi "đổi trả trong bao lâu" | Nhãn chính thức, có thẻ nguồn, ≤3 chip, không số liệu lạ |
  | Bấm một chip | Gửi câu đó, nhận trả lời |
  | Mở menu Trợ giúp → nhóm → câu hỏi | Gửi đúng câu, menu đóng |
  | Bàn phím trong menu (↑↓ Enter Esc) | Hoạt động, focus đúng |
  | Hỏi "serum cho da dầu" | Như cũ: thẻ sản phẩm, không nhãn chính thức |
  | Tải lại trang | Lịch sử giữ nhãn/nguồn/chip |
  | Trang hỗ trợ → "Hỏi Syna về mục này" khi widget đóng | Widget mở và gửi |
  | Viewport 375px | Menu vừa khung, không tràn |
  | Console | Không lỗi |
- Ảnh vào `report/store-support-e2e/step-06/`.
- `git diff --check`.

## Đầu ra
Code + ảnh; mục "Bước 06" trong handoff: khóa message mới lưu, cấu trúc menu, kết quả bảng kiểm, Lệch spec.

## Tiêu chí hoàn thành
Bảng kiểm đạt (hoặc NOT RUN có lý do); tin nhắn sản phẩm không đổi; không lỗi console.
