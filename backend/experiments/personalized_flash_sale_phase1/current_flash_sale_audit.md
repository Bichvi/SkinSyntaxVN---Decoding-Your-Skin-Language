# FORENSIC AUDIT: CURRENT FLASH SALE IMPLEMENTATION

**Dự án:** SkinSyntaxVN — Decoding Your Skin Language  
**Mục tiêu:** Kiểm toán chi tiết (Forensic Code Audit) hiện trạng khối Flash Sale trên Homepage và Catalog  
**Môi trường:** Production Database `skinsyntax` (MongoDB Atlas)  
**Thời gian thực hiện:** Tháng 10/2026  
**Nguyên tắc:** READ-ONLY, không phỏng đoán, code và dữ liệu thực tế là nguồn chân lý duy nhất.

---

## 1. Call Graph & Trace Luồng Hiện Tại

```
URL: index.php?r=home
  │
  ▼
HomeController::index()  (backend/app/controllers/HomeController.php:134-212)
  │
  ├─► Xây dựng User Profile ($this->buildRecommendationProfile($email))
  ├─► Thu thập Session Signals (search, view, cart, purchases)
  │
  └─► SanPham::getHomepageProductSections(8, ...) (backend/app/models/SanPham.php:828)
        │
        ├─► SanPham::getFlashSaleProducts($limitEach = 8) (backend/app/models/SanPham.php:1107)
        │     │
        │     └─► SanPham::findHomepageProducts(
        │           $discountFilter = ['$or' => [['phan_tram_giam' => ['$gt' => 0]], ['tien_tiet_kiem' => ['$gt' => 0]]]],
        │           ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1],
        │           $limit = 8
        │         )
        │           │
        │           └─► MongoDB Query: db.san_pham.find(
        │                 { $and: [ { trang_thai: { $nin: ['inactive', 'hidden', 'tam_an', '0'] } }, { $or: [...] } ] },
        │                 { sort: { phan_tram_giam: -1, tien_tiet_kiem: -1, ma_san_pham: -1 }, limit: 8 }
        │               )
        │
        └─► Trả về $homepageSections['flashDeals']
  │
  ▼
Render View: frontend/views/home.php (dòng 53-56, dòng 610-652, dòng 876-908)
  │
  ├─► $flashSaleProducts = array_slice($homepageSections['flashDeals'], 0, 4)
  │   (Fallback nếu rỗng: array_slice($latest, 0, 4))
  │
  ├─► Header UI: "ƯU ĐÃI CHỚP NHOÁNG" - "Flash Sale Mỹ Phẩm"
  ├─► Countdown HTML: data-flash-sale-countdown (Ngày, Giờ, Phút, Giây)
  ├─► Link CTA: <a href="index.php?r=tatca">Xem tất cả deal &rarr;</a>
  ├─► Loop render 4 sản phẩm bằng $renderHomeProductCard($p, 'Flash Sale')
  │
  └─► Client-side JavaScript Countdown Timer:
        target = new Date(); target.setHours(23, 59, 59, 999);
```

---

## 2. Trả Lời 10 Câu Hỏi Trọng Tâm

### Q1: Homepage lấy 4 Flash Sale products từ đâu?
* **Source code:** `backend/app/controllers/HomeController.php:195-197` gọi `SanPham::getHomepageProductSections()`, bên trong gọi `SanPham::getFlashSaleProducts(8)` (`SanPham.php:1107-1120`).
* **View:** `frontend/views/home.php:53` lấy mảng `$homepageSections['flashDeals']` và dùng `array_slice(..., 0, 4)` để lấy đúng 4 sản phẩm đầu tiên hiển thị trên một hàng ngang.
* **Cơ chế truy vấn:** Query trực tiếp trên collection canonical `san_pham` của MongoDB với điều kiện lọc sản phẩm đang active và có discount field > 0.

### Q2: Có ngẫu nhiên (random) không?
* **Source of truth:** `SanPham.php:1117`
  ```php
  ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1]
  ```
* **Kết luận:** **HOÀN TOÀN KHÔNG RANDOM**. Việc sắp xếp hoàn toàn xác định (deterministic) theo thứ tự ưu tiên:
  1. `phan_tram_giam` giảm dần (sản phẩm giảm % cao nhất lên trước).
  2. `tien_tiet_kiem` giảm dần (nếu cùng %, số tiền tiết kiệm lớn hơn lên trước).
  3. `ma_san_pham` giảm dần (tie-breaker: sản phẩm nhập mới hơn lên trước).
* Mọi khách truy cập (Guest lẫn User) khi vào Homepage tại cùng một thời điểm đều nhìn thấy 4 sản phẩm giống hệt nhau theo thứ tự cố định này.

### Q3: Có query discount không?
* **Source of truth:** `SanPham.php:1108-1113`
  ```php
  $discountFilter = [
      '$or' => [
          ['phan_tram_giam' => ['$gt' => 0]],
          ['tien_tiet_kiem' => ['$gt' => 0]],
      ],
  ];
  ```
* **Kết luận:** **CÓ query discount field trong database.**
* **Tuy nhiên, phát hiện kiểm toán:** Trong 2.473 sản phẩm của `san_pham`, có tới **2.361 sản phẩm (95,47%)** có `phan_tram_giam > 0` hoặc `tien_tiet_kiem > 0`. Do đó, điều kiện lọc này thực chất bao trùm hầu như toàn bộ danh mục sản phẩm, biến Flash Sale hiện tại thành một câu lệnh `ORDER BY discount_percent DESC LIMIT 8` trên toàn bộ kho hàng, chứ không phải một chiến dịch giới hạn.

### Q4: Có hard-coded product IDs không?
* **Source of truth:** `SanPham.php:1107-1120` và `frontend/views/home.php:53-56`.
* **Kết luận:** **KHÔNG CÓ hard-coded product IDs** trong code backend hoặc view. Danh sách lấy động từ MongoDB theo sort order. (Chỉ có fallback duy nhất ở dòng 55 của `home.php`: nếu query discount không trả về sản phẩm nào, hệ thống lấy tạm 4 sản phẩm mới nhất từ `$latest`).

### Q5: Countdown lấy start/end time từ đâu?
* **Source of truth:** `frontend/views/home.php:876-907`.
  ```javascript
  // Flash Sale Countdown Timer Script
  document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-flash-sale-countdown]');
    if (root) {
      ...
      var target = new Date();
      target.setHours(23, 59, 59, 999);
      ...
    }
  });
  ```
* **Kết luận:** **Start/end time KHÔNG HỀ ĐƯỢC LẤY TỪ DATABASE HOẶC BACKEND.**
* Không có biến PHP nào truyền `start_time` hay `end_time` xuống view. Thời gian đích hoàn toàn được hardcode trong JavaScript phía trình duyệt là: **23:59:59.999 của ngày hiện tại** (`target.setHours(23, 59, 59, 999)`).

### Q6: Vì sao về 0 lại reset?
* **Source of truth:**
  * Do `target` được khởi tạo bằng `new Date()` mỗi khi DOMContentLoaded chạy.
  * Trong hàm `tick()` (`home.php:891`):
    `var diff = Math.max(0, target.getTime() - Date.now());`
    Khi hết giờ (hoặc sang ngày mới), người dùng reload trang hoặc truy cập lại vào ngày hôm sau, script lại chạy lại dòng `target = new Date(); target.setHours(23, 59, 59, 999);`, đặt mốc đếm ngược mới tới cuối ngày đó.
  * Vì không có trạng thái chiến dịch (campaign lifecycle) lưu trữ trên server, đồng hồ đếm ngược chỉ mang tính chất **trình diễn (presentation ticker)** nhằm tạo cảm giác cấp bách giả lập (artificial urgency).

### Q7: -xx% tính từ đâu?
* **Source of truth:** `frontend/views/helpers.php:180-201` (`product_discount_percent`):
  ```php
  function product_discount_percent(array $product): ?int {
      $storedPercent = trim((string)($product['phan_tram_giam'] ?? ''));
      if ($storedPercent !== '' && is_numeric($storedPercent)) {
          $percent = (int)round((float)$storedPercent);
          return $percent > 0 ? $percent : null;
      }

      $giaBan = trim((string)($product['gia_ban'] ?? ''));
      $giaThiTruong = trim((string)($product['gia_thi_truong'] ?? ''));
      if ($giaBan === '' || $giaThiTruong === '' || !is_numeric($giaBan) || !is_numeric($giaThiTruong)) {
          return null;
      }

      $giaBanNum = (float)$giaBan;
      $giaThiTruongNum = (float)$giaThiTruong;
      if ($giaBanNum <= 0 || $giaThiTruongNum <= 0 || $giaThiTruongNum <= $giaBanNum) {
          return null;
      }

      $percent = (int)round((($giaThiTruongNum - $giaBanNum) / $giaThiTruongNum) * 100);
      return $percent > 0 ? $percent : null;
  }
  ```
* **Kết luận:**
  1. Ưu tiên 1: Đọc trực tiếp từ field tĩnh `phan_tram_giam` lưu sẵn trong document MongoDB của sản phẩm.
  2. Ưu tiên 2 (fallback): Nếu không có `phan_tram_giam`, tính toán động theo công thức:
     $$\text{percent} = \text{round}\left(\frac{\text{gia\_thi\_truong} - \text{gia\_ban}}{\text{gia\_thi\_truong}} \times 100\right)$$
     với điều kiện $\text{gia\_thi\_truong} > \text{gia\_ban} > 0$.

### Q8: Giá gạch ngang lấy field nào?
* **Source of truth:** `frontend/views/home.php:222-224`
  ```php
  <?php if ($giaThiTruong !== '' && is_numeric($giaThiTruong) && (float)$giaThiTruong > (float)$giaBan): ?>
    <div class="price-market text-muted text-decoration-line-through"><?= vnd($giaThiTruong) ?></div>
  <?php endif; ?>
  ```
* **Kết luận:** Lấy từ field `gia_thi_truong` của `san_pham`. Giá gạch ngang chỉ render khi `gia_thi_truong` hợp lệ và nghiêm ngặt lớn hơn `gia_ban`.

### Q9: Giá hiện tại lấy field nào?
* **Source of truth:** `frontend/views/home.php:145, 221`
  ```php
  $giaBan = (string)($p['gia_ban'] ?? '');
  ...
  <div class="price fw-bold"><?= vnd($giaBan) ?></div>
  ```
* **Kết luận:** Lấy trực tiếp từ field `gia_ban` của `san_pham`.

### Q10: Có campaign lifecycle thật không?
* **Source of truth:** Toàn bộ codebase (Controllers, Models, Routes, Views, MongoDB Collections).
* **Kết luận:** **HOÀN TOÀN KHÔNG CÓ.**
  * Không có collection nào như `flash_sale_campaigns`, `flash_sales`, `khuyen_mai`, hay `chuong_trinh_giam_gia`.
  * Không có dữ liệu quản lý các trạng thái vòng đời: `UPCOMING`, `ACTIVE`, `ENDED`.
  * Khối "Flash Sale" hiện tại thực chất chỉ là một "Top Discounted Products Section" được bọc giao diện banner Flash Sale và đồng hồ đếm ngược client-side.
  * Nút "Xem tất cả deal &rarr;" trỏ về `index.php?r=tatca` (`home.php:641`), dẫn thẳng tới trang danh mục hiển thị tất cả 2.473 sản phẩm mà không có bộ lọc chiến dịch nào.

---

## 3. Bảng Tổng Hợp So Sánh Hiện Trạng vs Mục Tiêu

| Tiêu chí | Hiện trạng (Audited Truth) | Mục tiêu Kiến trúc (Phase 1 Target) |
| :--- | :--- | :--- |
| **Model dữ liệu** | Không có campaign model; query trực tiếp `san_pham.phan_tram_giam` | Collection `flash_sale_campaigns` riêng biệt |
| **Vòng đời chiến dịch** | Không có; phụ thuộc client time 23:59:59 | Server-side lifecycle: `UPCOMING`, `ACTIVE`, `ENDED` |
| **Sản phẩm tham gia** | Mọi sản phẩm có discount trong catalog (2.361 SKUs) | Admin chọn danh sách 12–20 sản phẩm cụ thể |
| **Giá Flash Sale** | Lấy giá thường `san_pham.gia_ban` | Giá `flash_price` riêng cho campaign |
| **Countdown Timer** | Tự reset mỗi ngày về 23:59:59 của browser | Dựa vào `start_at` / `end_at` từ server; về 0 là KẾT THÚC |
| **Xem tất cả deal** | Trỏ về `index.php?r=tatca` (toàn bộ 2.473 sản phẩm) | Dedicated route `index.php?r=flash_sale` chỉ show SKUs trong campaign |
| **Xếp hạng Top 4** | Cố định theo `% giảm` cao nhất cho mọi user | Cá nhân hóa thứ tự hiển thị (Personalized Deal Ranking) |
| **Tính nhất quán giá** | Không có giá flash sale riêng để bảo toàn | Giữ nguyên giá flash sale cho mọi user, bảo toàn tới Checkout |
