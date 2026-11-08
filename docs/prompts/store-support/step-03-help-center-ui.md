# BƯỚC 03 — TRUNG TÂM HỖ TRỢ DẠNG DROPDOWN NHIỀU CẤP (FRONTEND)

## Vai trò
Kỹ sư frontend PHP-rendered + UX. Thiết kế lại `frontend/views/info/support.php` thành accordion 4 cấp (Nhóm → Bài → Mục → Chi tiết), đẹp, nhanh, truy cập được, chạy được khi tắt JS.

## Luật bất di bất dịch
- Không thêm thư viện/framework. Dùng `<details>/<summary>` gốc + JS thuần nhỏ (tăng cường). CSS trong `frontend/public/assets/css/support-center.css` (có sẵn, class `ss-help*`). JS mới một file `frontend/public/assets/js/support-center.js`.
- Dữ liệu chỉ từ `support.json` qua `SupportController` (đã có `$support`, `$articles`, `$article`, `$group`, `$supportSearch`, `$helpUrl`). Không gọi API mới.
- Đọc item qua `SupportKnowledge::itemText()/itemDetails()` (Bước 01).
- Giữ: route, URL `?bai=` / `?nhom=` / `?q=`, khối cảnh báo pháp lý, "Văn bản tham khảo", form tìm kiếm submit GET (dùng được khi tắt JS), `live-filter.js` nếu vẫn dùng được (nếu thay bằng lọc client-side, form GET vẫn phải hoạt động).
- Mọi chuỗi ra HTML qua `h()`. Không `innerHTML` với dữ liệu chưa escape.
- GitNexus impact cho `SupportController::show` nếu sửa controller. Trả lời tiếng Việt. Không commit trừ khi được yêu cầu.
- Trước khi code: đọc `.claude/skills/frontend-testing/SKILL.md` và `.claude/skills/e2e/SKILL.md`.

## Đọc trước
1. `AGENTS.md`, `docs/plans/store-support-handoff.md`
2. Spec mục 5 (bố cục, hành vi) — **làm đúng sơ đồ trong spec**
3. `frontend/views/info/support.php`, `frontend/public/assets/css/support-center.css`, `frontend/public/assets/js/live-filter.js`, `backend/app/controllers/SupportController.php`, `frontend/views/layouts/header.php` (cách nạp CSS/JS, biến dùng chung).
4. Widget chat: `frontend/views/components/ai_chat_widget.php` — bộ bắt click `quicksend:` (~dòng 2241) và `sendMessage`.

## Yêu cầu giao diện
1. **Hero**: tiêu đề "Bạn cần mình giúp gì?", ô tìm (giữ `name="q"`), nút "✨ Hỏi Syna" (link `quicksend:` với nội dung ô tìm; ô trống → mở widget không gửi), hàng chip phổ biến (giữ 3 chip cũ + "Dùng voucher", "Khảo sát da" nếu bài tồn tại — chỉ render chip có `slug` thật).
2. **Cột chủ đề** (desktop) / **hàng chip cuộn ngang dính đầu** (mobile <768px): link nhóm → cuộn và mở nhóm tương ứng (vẫn có `href="?r=tro_giup&nhom=<key>"` khi tắt JS). Khối liên hệ dùng `contact` (không giờ hỗ trợ).
3. **Thanh công cụ**: "Mở tất cả" / "Thu gọn" (chỉ hiện khi có JS), đếm "N nhóm · M bài" theo bộ lọc hiện tại.
4. **Accordion**:
   - L1 `<details class="ss-acc ss-acc--group" id="nhom-<key>">`: icon, tiêu đề, mô tả, số bài.
   - L2 `<details id="bai-<slug>">`: tiêu đề + summary 1 dòng; mở ra: summary, "Cập nhật <updated|version>", các L3, nút "Mở trang riêng ↗" (`?bai=`), nút "💬 Hỏi Syna về mục này" (`quicksend:` + câu đầu trong `questions`, hoặc "Hướng dẫn: <title>"), `action` nếu có.
   - L3 `<details id="bai-<slug>--muc-<n>">`: tiêu đề section; nội dung là danh sách đánh số `<ol>` nếu tiêu đề/ngữ cảnh là bước, ngược lại `<ul>`.
   - L4: item có `details` → `<details>` nhỏ lồng trong `<li>`, summary = `text`, nội dung = danh sách `details`.
   - Mặc định: không lọc → đóng hết trừ nhóm đầu; có `?nhom=` → mở nhóm đó; có `?q=` → mở mọi nhóm/bài khớp.
5. **Trang bài riêng `?bai=<slug>`**: giữ breadcrumb, cảnh báo pháp lý, references, "Cùng chủ đề"; các section render dạng L3 accordion, mục đầu `open`.
6. **Thẩm mỹ**: theo token `ss-help` sẵn có; màu thương hiệu `#183B2B` (chữ/nhấn), `#EBF2EE` (nền nhạt); bo góc, bóng nhẹ như thẻ hiện tại; mũi tên xoay 90° khi `[open]`; khoảng cách cấp lồng thụt rõ (L2 16px, L3 12px, L4 12px) với đường kẻ dọc nhẹ; vùng bấm summary ≥ 44px; `:focus-visible` rõ; `prefers-reduced-motion` tắt chuyển động; dark mode chỉ nếu site đã có (không tự thêm).

## Yêu cầu JS (`support-center.js`, tăng cường, IIFE, không global)
- **Deep-link**: khi tải và khi `hashchange`: `#nhom-…`, `#bai-…`, `#bai-…--muc-n` → mở chuỗi `<details>` cha, `scrollIntoView({block:'start'})`, đặt focus vào summary. Khi người dùng mở L2/L3 → `history.replaceState` cập nhật hash (không thêm lịch sử).
- **Lọc tức thì** (debounce 150ms) trên `data-search` của mỗi L2 (server render sẵn chuỗi đã `fold`: title + summary + triggers + itemText): chuẩn hóa từ khóa giống `SupportKnowledge::fold` (bỏ dấu tiếng Việt, `đ→d`, chữ thường); tất cả token phải có. Khớp → hiện + mở cha; không khớp → `hidden`. Highlight bằng `<mark>` chỉ trên text node của tiêu đề (dùng `TextNode` API, không `innerHTML`). Rỗng → khối "Chưa tìm thấy" + nút "Hỏi Syna: <từ khóa>". Cập nhật `?q=` bằng `replaceState`.
- **Hỏi Syna**: nếu widget chưa mở thì mở (click `[data-ai-chat-toggle]` khi panel `hidden`), sau đó để bộ bắt `quicksend:` sẵn có xử lý. Nếu cần hook mới trong widget, **không** sửa ở bước này — ghi vào handoff cho Bước 06.
- Không lỗi khi widget không có trên trang.

## Quy trình bắt buộc
1. `git status`; impact nếu sửa controller.
2. Viết test/kiểm trước theo `frontend-testing` skill (nếu repo không có runner JS → kiểm bằng Playwright ở bước kiểm chứng; ghi rõ).
3. Implement view → CSS → JS.
4. Kiểm chứng.

## Kiểm chứng (chạy thật)
- `php -l frontend/views/info/support.php` (+ controller nếu sửa).
- Playwright tại `http://localhost:8080/index.php?r=ho_tro_khach_hang`:
  | Kịch bản | Kỳ vọng |
  |---|---|
  | Tải trang | Nhóm đầu mở, còn lại đóng, không lỗi console |
  | Mở L1 → L2 → L3 → L4 | Từng cấp mở/đóng đúng, hash cập nhật |
  | Vào `…#bai-doi-tra--muc-1` | Tự mở đúng chuỗi, cuộn tới |
  | Gõ "voucher", "doi tra", "otp" | Chỉ bài khớp hiện, cha tự mở, highlight |
  | Gõ "xyzabc" | Trạng thái rỗng + nút Hỏi Syna |
  | Mở tất cả / Thu gọn | Đúng phạm vi lọc |
  | Bàn phím Tab/Enter/Space | Điều khiển được mọi cấp |
  | Viewport 375px | Không cuộn ngang, chip chủ đề cuộn ngang |
  | Tắt JS (Playwright `javaScriptEnabled:false`) | Accordion vẫn mở được, tìm kiếm GET chạy |
  | `?r=tro_giup&bai=bao-mat` | Trang bài riêng còn cảnh báo pháp lý + references |
  | Nút "Hỏi Syna về mục này" | Widget mở, câu hỏi được gửi |
- Ảnh desktop/mobile trước–sau vào `report/store-support-e2e/step-03/`.
- `git diff --check`.

## Đầu ra
View/CSS/JS + ảnh; mục "Bước 03" trong handoff: cấu trúc DOM/id, quyết định giữ hay bỏ `live-filter.js`, việc cần Bước 06 làm cho widget (nếu có), kết quả bảng kiểm, Lệch spec.

## Tiêu chí hoàn thành
Bảng kiểm đạt hết (hoặc ghi rõ cái nào NOT RUN + lý do); dữ liệu cũ hiển thị đủ; không lỗi console.
