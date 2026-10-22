# SKINSYNTAXVN — PHASE P4A: FRONTEND PERFORMANCE AUDIT REPORT

**Author:** Senior Frontend Performance Engineer, PHP Full-Stack Engineer & Web Performance Specialist  
**Date:** 2026-10-18  
**Scope:** Comprehensive Source-Level & Runtime Audit of Frontend Layouts, Assets, Styles, Scripts, Carousels, and Media  
**Target Milestone:** P4A ONLY (Audit + Baseline). STRICT STOP BEFORE P4B.

---

## 1. Executive Summary & Audit Overview

Khảo sát chuyên sâu mã nguồn giao diện SkinSyntaxVN và thực tế vận hành trên hạ tầng Nginx/PHP-FPM/Docker cho thấy:
1. **Trải nghiệm người dùng đang bị nghẽn nghiêm trọng ở tầng tải tài nguyên (Asset & Network Bloat)**, chứ không phải do logic PHP backend.
2. **Tổng dung lượng trang chủ (Homepage) ban đầu lên tới 3.7 MB với 54 network requests**, trong đó hơn 80% dung lượng là các tệp hình ảnh PNG không nén, tệp font trùng lặp và các stylesheet chặn hiển thị (Render-Blocking CSS).
3. **Chỉ số Core Web Vitals trên thiết bị Mobile đạt mức báo động:**
   - **LCP (Largest Contentful Paint) Mobile = 9.8 giây** (Ngưỡng chuẩn Google: $\le 2.5$ giây).
   - **FCP (First Contentful Paint) Mobile = 3.7 giây**.
   - **Render-blocking CSS & Webfonts làm trễ màn hình đầu tiên hơn 4.7 giây**.
4. **Trang chi tiết sản phẩm (PDP) chứa thẻ `<video>` tự nạp trước (preload="metadata") và tự bắn request AJAX khởi tạo phiên xem video ngay khi load trang**, tiêu tốn băng thông và slot kết nối HTTP ngay cả khi người dùng chưa hề cuộn tới video.

Tất cả các phát hiện dưới đây được phân loại theo mức độ nghiêm trọng (P0 - Critical, P1 - High, P2 - Medium), kèm theo danh sách tệp nguồn cụ thể, giải pháp tối ưu dự kiến và phân tích rủi ro nghiệp vụ.

---

## 2. Chi tiết các Điểm nghẽn Hiệu năng (Bottleneck Inventory)

### 2.1. [P0 — CRITICAL] Cơ chế Cache-Busting bằng `time()` trên ảnh linh vật (Mascot PNG)

- **Tệp nguồn:** [`frontend/views/components/ai_chat_widget.php`](file:///D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/components/ai_chat_widget.php#L47) (Dòng 47).
- **Hiện trạng:**
  ```html
  <img src="<?= BASE_URL ?>/assets/images/syna_mascot.png?v=<?= time() ?>" alt="SYNA Mascot">
  ```
- **Hậu quả:**
  - `syna_mascot.png` có kích thước **299.2 KB**.
  - Tham số query `?v=<?= time() ?>` thay đổi theo từng giây của mỗi HTTP request.
  - **Triệt tiêu hoàn toàn khả năng cache của trình duyệt và Nginx proxy**. Mỗi lần người dùng click chuyển trang hoặc F5, trình duyệt bắt buộc phải tải lại tệp ảnh 299 KB từ đầu!
- **Dự kiến tối ưu (P4B):** Thay bằng static version hash hoặc mtime của file: `?v=<?= filemtime(...) ?>`.
- **Lợi ích:** Tiết kiệm ngay 300 KB cho 100% các lượt chuyển trang tiếp theo.
- **Rủi ro:** 0% rủi ro nghiệp vụ.

---

### 2.2. [P0 — CRITICAL] Thiếu `loading="lazy"` và kích thước cứng (`width`/`height`) trên toàn bộ Catalog

- **Tệp nguồn:**
  - [`frontend/views/home.php`](file:///D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/home.php#L178) (Dòng 178, 283).
  - [`frontend/views/tatca.php`](file:///D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/tatca.php#L115) (Dòng 115).
  - [`frontend/views/partials/goiy_product_card.php`](file:///D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/partials/goiy_product_card.php#L66) (Dòng 66).
- **Hiện trạng:**
  - Tất cả các thẻ `<img>` sản phẩm trong danh sách (24 sản phẩm/trang ở Category, hơn 30 sản phẩm ở Homepage) đều được render ở chế độ mặc định (Eager loading).
  - Không có thuộc tính `loading="lazy"`.
  - Không có thuộc tính `width` và `height` rõ ràng trên thẻ `<img>` (chỉ dựa vào CSS `aspect-ratio: 1/1`).
- **Hậu quả:**
  - Trình duyệt dồn dập gửi hàng chục request tải ảnh ngoài khung nhìn (Offscreen Images) ngay lập tức khi vừa nạp HTML, chiếm dụng hàng đợi TCP connection (giới hạn 6 kết nối song song của HTTP/1.1).
  - Đẩy LCP và FCP xuống rất sâu.
  - Gây giật cục khung hình (Layout Shift) khi ảnh tải về làm nhảy vị trí các khối văn bản xung quanh.
- **Dự kiến tối ưu (P4B):**
  - Giữ lại 4 ảnh đầu tiên thuộc Above-the-fold nạp ưu tiên (`fetchpriority="high"`).
  - Gắn `loading="lazy"` và `decoding="async"` cho toàn bộ ảnh sản phẩm từ vị trí số 5 trở đi.
  - Khai báo rõ `width="280"` và `height="280"` trên thẻ `<img>` để trình duyệt giữ chỗ trước.
- **Lợi ích:** Giảm số network requests ban đầu từ 54 xuống dưới 15; LCP cải thiện từ 30% đến 50%.
- **Rủi ro:** Không ảnh hưởng dữ liệu, giữ nguyên hàm `resolve_image_url()`.

---

### 2.3. [P0 — CRITICAL] Trùng lặp Icon Fonts và Chuỗi Stylesheet chặn hiển thị (Render-Blocking CSS)

- **Tệp nguồn:** [`frontend/views/layouts/header.php`](file:///D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/layouts/header.php#L71-L78) (Dòng 71 - 78).
- **Hiện trạng:**
  ```html
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&family=Quicksand:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" crossorigin="anonymous">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/fontawesome.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=...">
  ```
- **Hậu quả:**
  - Nạp **cả hai bộ icon font khổng lồ cùng lúc**: `bootstrap-icons.min.css` (85.8 KB) và `fontawesome.min.css` (102.6 KB) trên mọi trang web.
  - Tổng dung lượng CSS chặn hiển thị: **528 KB**.
  - Google Fonts tải 2 họ font biến thiên đầy đủ cả Normal và Italic weights từ 300 đến 800, gây độ trễ kết nối DNS/TLS qua Google Fonts.
  - Báo cáo Lighthouse chỉ ra chuỗi này làm lãng phí **4.7 giây** thời gian render trên mạng di động.
- **Dự kiến tối ưu (P4C):**
  - Tối ưu tải Google Fonts bằng `display=swap&text=...` hoặc preconnect chính xác.
  - Tải không đồng bộ (asynchronous / media print swap) đối với các font icons phụ trợ.
  - Bật nén Gzip/Brotli trên Nginx cho toàn bộ tệp `.css` và `.js` (giảm dung lượng truyền tải từ 528 KB xuống ~90 KB).
- **Lợi ích:** FCP giảm từ 3.7s xuống ~1.5s trên Mobile.
- **Rủi ro:** Cần kiểm tra kỹ các icon hiển thị trong UI để không bị biến mất hoặc nhấp nháy FOUT.

---

### 2.4. [P1 — HIGH] Tải trước 11 hình ảnh Mascot PNG (750 KB) đồng loạt khi mở trang chủ

- **Tệp nguồn:** [`frontend/views/home.php`](file:///D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/home.php#L450-L472) (Dòng 450 - 472).
- **Hiện trạng:**
  ```javascript
  var synaStates = {
    idle: baseUrl + 'syna_listening.png',
    greeting: baseUrl + 'syna_greeting.png',
    talking1: baseUrl + 'syna_talking_1.png',
    talking2: baseUrl + 'syna_talking_2.png',
    ... // 11 states
  };
  // Preload all assets
  Object.values(synaStates).forEach(function (src) {
    var img = new Image();
    img.src = src;
  });
  ```
- **Hậu quả:**
  - 11 tệp PNG chất lượng cao (~70 KB/ảnh) được tải đồng loạt ngay trong sự kiện `DOMContentLoaded`.
  - Tiêu tốn thêm **~750 KB** băng thông mạng, tranh chấp đường truyền với các sản phẩm bên dưới.
- **Dự kiến tối ưu (P4C):**
  - Chỉ nạp trước ảnh `syna_listening.png` (trạng thái khởi đầu).
  - Các tư thế `talking1`, `talking2`, `greeting` chỉ nạp lazy khi hiệu ứng typewriter chuẩn bị chạy đến câu thoại tương ứng, hoặc chuyển đổi sang định dạng WebP (chỉ ~15 KB/ảnh thay vì 70 KB).
- **Lợi ích:** Tiết kiệm ~600 KB tải ngay lập tức; giải phóng CPU luồng chính giải mã ảnh.
- **Rủi ro:** Cần đảm bảo không bị giật trắng (white flash) khi đổi tư thế linh vật.

---

### 2.5. [P1 — HIGH] AI Video Commerce: Preload Metadata & Kích hoạt Session ngầm khi chưa xem

- **Tệp nguồn:** [`frontend/views/chitiet.php`](file:///D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/chitiet.php#L964-L969) (Dòng 964, 1506).
- **Hiện trạng:**
  1. `<video id="synaVideoPlayer" preload="metadata" src="<?= h($initialStreamUrl) ?>">`:
     - Trình duyệt tự động mở kết nối HTTP Range request tới máy chủ video để đọc moov atom / metadata ngay khi nạp trang.
  2. Dòng 1506:
     ```javascript
     if (rewardCard && currentCampId) {
       startWatchSession();
     }
     ```
     - Hàm `startWatchSession()` bắn ngay một POST request tới `index.php?r=video_watch_start` để tạo bản ghi session trong MongoDB, dù khách hàng chưa hề cuộn chuột xuống khối video hoặc chưa có ý định bấm xem!
- **Hậu quả:**
  - Làm tăng TTFB và số lượng network requests trên trang chi tiết sản phẩm.
  - Tạo rác phiên xem video (zero-progress watch sessions) trong cơ sở dữ liệu MongoDB Atlas.
- **Dự kiến tối ưu (P4D):**
  - Đổi sang `preload="none"`.
  - Tách `src` thành `data-video-src`, chỉ gán vào thẻ `<video>` khi người dùng bấm vào nút "Xem Syna 3D tư vấn sản phẩm" (hoặc khi card video tiến vào viewport 50%).
  - Chỉ gọi `startWatchSession()` khi sự kiện `play` hoặc thao tác bấm Play đầu tiên diễn ra.
  - **Giữ nguyên 100%:** Luồng heartbeat 4s, tiến trình Watch-to-Reward, tính toán coverage %, và logic cấp voucher authoritative.
- **Lợi ích:** Trang chi tiết sản phẩm tải nhanh hơn ~200ms; loại bỏ hoàn toàn các request thừa cho khách chỉ vào xem thông số giá.
- **Rủi ro:** 0% rủi ro nghiệp vụ vì luồng tính thưởng chỉ bắt đầu khi video thực sự phát.

---

### 2.6. [P2 — MEDIUM] DOM Nặng & Trùng lặp Component Chat trong Footer

- **Tệp nguồn:**
  - [`frontend/views/layouts/footer.php`](file:///D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/layouts/footer.php#L80-L86) (Dòng 80 - 86).
  - [`frontend/views/components/ai_chat_widget.php`](file:///D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/components/ai_chat_widget.php) (87 KB).
  - [`frontend/views/components/support_chat_widget.php`](file:///D:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/frontend/views/components/support_chat_widget.php) (22 KB).
- **Hiện trạng:**
  - Cả hai widget chat khổng lồ (tổng cộng hơn 3.100 dòng mã HTML/CSS/JS) được `require` trực tiếp vào DOM của tất cả các trang, ngay cả khi modal chat đang đóng (`hidden`).
  - Hơn nữa, script trong `footer.php` duy trì timer `setInterval` 14 giây cho popup thông báo mua hàng top sale, gây thức tỉnh luồng chính (Main thread wakeups) liên tục.
- **Dự kiến tối ưu (P4C):**
  - Chuyển logic mở widget sang Event Listener thụ động (Passive event).
  - Chỉ render giao diện cơ bản (nút trigger), nạp phần thân khung chat bằng template hoặc deferred rendering khi người dùng click mở lần đầu.
- **Lợi ích:** Giảm hơn 200 DOM nodes; giảm Total Blocking Time (TBT).
- **Rủi ro:** Cần kiểm tra kỹ các luồng chat AI Syna và hỗ trợ khách hàng không bị mất trạng thái.

---

## 3. Ma Trận Kế Hoạch Triển Khai (Phân kỳ P4B – P4E)

| Giai đoạn | Hạng mục công việc chính | Danh sách tệp dự kiến sửa đổi | Lợi ích đo lường kỳ vọng | Mức độ rủi ro & Biện pháp an toàn |
|---|---|---|---|---|
| **P4B** | **Image Loading Optimization** | `home.php`, `tatca.php`, `chitiet.php`, `goiy_product_card.php`, `ai_chat_widget.php` | - Giảm 50% requests ban đầu.<br>- Khắc phục lỗi `time()` cache bust.<br>- Giảm LCP từ 9.8s xuống ~4.5s trên Mobile. | **RẤT THẤP**.<br>Không đổi ảnh gốc, giữ nguyên `resolve_image_url()`. |
| **P4C** | **CSS/JS & Carousel Optimization** | `header.php`, `footer.php`, `home.php`, `style.css`, Nginx config | - Giảm Render-blocking CSS từ 528 KB xuống ~90 KB (Gzip).<br>- Giảm FCP từ 3.7s xuống ~1.5s.<br>- TBT giảm từ 590ms xuống < 200ms. | **TRUNG BÌNH**.<br>Cần kiểm tra kỹ hiển thị icon font và responsive navbar. |
| **P4D** | **AI Video Loading Optimization** | `chitiet.php`, Video Controller (nếu cần) | - Chuyển sang `preload="none"`.<br>- Defer watch session start.<br>- PDP tải nhẹ hơn, giảm 1-2 requests thừa. | **THẤP**.<br>Bảo toàn 100% logic Watch-to-Reward, heartbeat và voucher. |
| **P4E** | **Browser Acceptance Verification** | Toàn bộ 14 tiêu chí Acceptance Test | - Đo lại Lighthouse Desktop/Mobile.<br>- Xác nhận 0 lỗi console, ảnh mượt, cuộn nhẹ. | **KHÔNG CÓ** (Chỉ kiểm thử xác nhận). |

---

## 4. STRICT STOP & RECOMMENDATION

Hiện tại **Phase P4A đã hoàn thành việc audit toàn diện mã nguồn**. Toàn bộ số liệu thực tế DevTools & Lighthouse Baseline đang được tổng hợp tại `browser_baseline.md`.

Tôi **DỪNG LẠI TẠI ĐÂY (STRICT STOP sau P4A)** và không tự ý sửa bất kỳ dòng code nào thuộc P4B – P4E trước khi nhận được sự phê duyệt chính thức từ bạn.
