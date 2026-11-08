# BƯỚC 01 — MỘT NGUỒN SỰ THẬT + GỘP ROUTE HỖ TRỢ (PHP)

## Vai trò
Kỹ sư backend PHP. Đưa `support.json` thành nguồn duy nhất cho thông tin hỗ trợ, gộp hai trung tâm hỗ trợ thành một, mở rộng mô hình nội dung lên 4 cấp **tương thích ngược**. Thay đổi nhỏ nhất, an toàn nhất.

## Luật bất di bất dịch
- Không phát minh: chỉ mở rộng `support.json`, `SupportKnowledge`, `SupportController`, route có sẵn. Không thêm thư viện/route mới.
- Liên hệ đúng (người dùng chốt): hotline `0398606281`, email `cskh@skinsyntax.com`, giờ hỗ trợ **chưa công bố**. Sau bước này không còn `1900 0000` hay `08:00 - 22:00` trên giao diện khách.
- GitNexus impact trước khi sửa từng symbol; HIGH/CRITICAL → dừng hỏi người dùng; UNKNOWN → xác nhận bằng `rg`.
- Không chạy script trong `backend/tests/` trừ test bạn viết (thuần, không DB) và các test đã được Bước 00 xác nhận không chạm DB.
- Không commit trừ khi được yêu cầu. Trả lời tiếng Việt.

## ⚠ Cập nhật sau Bước 00
- Có **3 router** phải sửa đồng bộ: `backend/public/index.php` (Docker :8080 thực chạy), `frontend/public/index.php`, `index.php` gốc (file gốc không require `store_assistance.php` → gọi `SupportController` trực tiếp). Không đồng bộ các `case` khác đang lệch giữa 3 file (ngoài phạm vi).
- Chỗ viết cứng phải bỏ thêm: `header.php:403` (offcanvas), `HomeController::storeNetwork()` dòng 421-422 (giờ + hotline). Trang hệ thống cửa hàng vẫn giữ, chỉ đổi phần liên hệ sang `SupportKnowledge::contact()` và bỏ giờ.
- **Gộp luôn các trang pháp lý riêng** (người dùng quyết 2026-10-22): `dieu_kien_giao_dich`→`dieu-khoan`, `chinh_sach_bao_mat`→`bao-mat`, `xuly_du_lieu_ca_nhan`→`bao-mat`, `huong_dan_nhan_otp`→`otp` (cũng như `chinh_sach_xu_ly_du_lieu`, `chinh_sach_cookie` nếu router có `case` riêng). Ở cả 3 router, các route này mở `SupportController::show('<slug>')` (giữ URL cũ).
  - Nội dung của `termsReference()`, `privacyReference()`, `personalDataReference()` (mảng `highlights`, `summary`) và view `frontend/views/info/otp-guide.php`: so từng ý với bài đích. Ý **mới và không mâu thuẫn** → thêm thành item trong section phù hợp của bài đích (giữ giọng văn `support.json`, không thêm cam kết hay số liệu mới ngoài câu gốc). Ý **mâu thuẫn** (số phút OTP, thời hạn, kênh liên hệ, cam kết khác bài đích) → giữ `support.json`, bỏ ý kia, ghi vào handoff danh sách "ý đã bỏ + lý do".
  - Giữ khối cảnh báo "thông tin pháp lý đang được hoàn thiện" và "Văn bản tham khảo" của nhóm `legal`.
  - `termsReference/privacyReference/personalDataReference/otpGuide/renderPolicyReference` và view `info/policy-reference.php`, `info/otp-guide.php`: xóa nếu impact + `rg` cho thấy không còn nơi gọi; nếu còn → giữ và ghi lý do.
- `lichsuchat` đang 404: không sửa ở bước này. Link "Mở chat hỗ trợ" trong nội dung gộp từ `warrantyCenter` → bỏ.

## Đọc trước
1. `AGENTS.md`
2. `docs/plans/store-support-handoff.md` (đặc biệt Bước 00: chỗ viết cứng, nơi đọc `items`, impact)
3. Spec mục 2, 4, 5 (đầu mục: route), 6.1: `docs/superpowers/specs/2026-10-21-store-support-chatbot-design.md`
4. Code: `backend/app/content/support.json`, `backend/app/services/SupportKnowledge.php`, `backend/app/controllers/SupportController.php`, `backend/app/routes/store_assistance.php`, `frontend/public/index.php` (các `case` hỗ trợ), `backend/app/controllers/HomeController.php` (`customerSupport`, `warrantyCenter`, `storeNetwork`, `otpGuide` — chỉ đọc phần liên quan), `frontend/views/layouts/header.php`, `frontend/views/layouts/footer.php`, `frontend/views/info/support.php`.

## Yêu cầu
1. **Mô hình 4 cấp, tương thích ngược**
   - Phần tử trong `sections[].items` được phép là chuỗi **hoặc** `{"text": string, "details": string[]}`.
   - Thêm static helper vào `SupportKnowledge`: `itemText(mixed $item): string`, `itemDetails(mixed $item): array` (chuỗi → `[]`), `contact(): array`.
   - Sửa **mọi** nơi đang coi item là chuỗi (theo danh sách Bước 00) để đi qua helper. View hiện tại phải hiển thị như cũ với dữ liệu cũ.
   - Thêm trường tùy chọn trên bài: `route_sources` (mảng tên route), `updated` (YYYY-MM-DD). Không hiển thị `route_sources`.
   - Thêm nhóm `guide`: `{"title": "Hướng dẫn sử dụng", "description": "Các bước dùng tính năng trên SkinSyntax: khảo sát da, Syna, voucher, live…", "icon": "fa-book-open"}`. Chưa thêm bài (Bước 02 làm).
   - Đổi `version` → `2026-10-21`.
2. **Gộp route**
   - `ho_tro_khach_hang`, `bao_hanh` → `SupportController::show()`; `bao_hanh` mở bài `doi-tra`. Cách làm ưu tiên: trong `index.php` đổi thân `case` sang gọi `SupportController` (giữ URL cũ), hoặc bỏ `case` để rơi xuống alias trong `store_assistance.php` — chọn cách có impact thấp hơn, ghi lý do.
   - Nội dung đúng và **không mâu thuẫn** của `warrantyCenter()` (phạm vi tiếp nhận, thông tin cần chuẩn bị, quy trình) → gộp vào section phù hợp của `doi-tra` hoặc `hang-loi` (không thêm số liệu mới; câu nào mâu thuẫn với chính sách hiện có thì bỏ và ghi vào handoff).
   - `customerSupport()`/`warrantyCenter()`: nếu impact cho thấy chỉ `index.php` gọi → xóa; nếu không chắc → giữ, thêm comment `// Không còn route; giữ cho tương thích, xem update_store_support_chatbot.md`.
3. **Header/footer đọc từ nguồn**
   - `header.php:85`, `footer.php:21` (và mọi chỗ Bước 00 tìm ra trên giao diện khách) hiển thị `contact.phone` từ `SupportKnowledge::contact()`; dùng `h()`; lỗi đọc file → ẩn số, không làm vỡ trang.
   - Link "Hỗ trợ khách hàng" trên thanh trên cùng trỏ `?r=ho_tro_khach_hang` (đã gộp).
   - Tránh đọc/parse JSON nhiều lần mỗi request: cache tĩnh trong `SupportKnowledge` (static property).
4. Không đổi `match()`/`answer()`.

## Quy trình bắt buộc
1. `git status`.
2. Impact: `SupportKnowledge`, `SupportController::show`, `customerSupport`, `warrantyCenter`, header/footer include (nếu index có). Báo callers + risk.
3. TDD: viết test trước.
   - `backend/tests/unit/SupportContentTest.php` (thuần PHP, `require` chỉ `SupportKnowledge.php`): `itemText/itemDetails` với chuỗi/object/rác; `contact()` đúng 3 khóa; schema `support.json` (slug duy nhất, group tồn tại, mỗi bài ≥1 section, mọi item hợp lệ, có nhóm `guide`); không chứa `1900 0000`.
   - Chạy test cũ an toàn theo Bước 00 (`store_assistance_test.php` nếu không chạm DB) → vẫn pass.
4. Implement.
5. Kiểm chứng.

## Kiểm chứng (chạy thật, dán kết quả)
- `php -l` mọi file sửa.
- Test mới + test cũ an toàn: pass.
- `rg -n "1900 0000|08:00 - 22:00" frontend backend --glob '!vendor' --glob '!backend/experiments/**'` → không còn trên giao diện khách (liệt kê chỗ còn lại nếu là tài liệu/admin và giải thích).
- Trình duyệt (`http://localhost:8080`, Playwright hoặc trình duyệt có sẵn): `?r=ho_tro_khach_hang`, `?r=tro_giup`, `?r=bao_hanh`, `?r=tro_giup&bai=bao-mat`, `?r=chinh_sach_bao_mat` → mở đúng, không lỗi PHP; header/footer hiện `0398606281`. Chụp vào `report/store-support-e2e/step-01/`.
- `git diff --check`.

## Đầu ra
Code + test; mục "Bước 01" trong handoff: cách gộp route đã chọn + lý do, helper mới, chỗ đã bỏ viết cứng, nội dung bảo hành đã gộp/bỏ, kết quả kiểm chứng, Lệch spec.

## Tiêu chí hoàn thành
Một trung tâm hỗ trợ; không còn liên hệ mâu thuẫn trên giao diện khách; dữ liệu cũ hiển thị y như trước; test pass.
