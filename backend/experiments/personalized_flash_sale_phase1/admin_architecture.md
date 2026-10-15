# ADMIN ARCHITECTURE AUDIT & FLASH SALE MODULE SPECIFICATION

**Dự án:** SkinSyntaxVN — Decoding Your Skin Language  
**Mục tiêu:** Kiểm toán kiến trúc quản trị Admin hiện tại, xác định khả năng tái sử dụng module khuyến mãi/chiến dịch và thiết kế module Quản lý Flash Sale Campaign đồng bộ với quy ước hệ thống.  
**Môi trường:** Production Codebase & MVC Conventions  
**Thời gian thực hiện:** Tháng 10/2026  
**Nguyên tắc:** DESIGN ONLY — Tuyệt đối không tạo collection hay sửa code trong Phase 1.

---

## 1. Kiểm Toán Kiến Trúc Admin Hiện Tại

### 1.1 Bộ Điều Hướng (Routing) & Bảo Vệ Quyền (Role Guards)
* **Entry point:** `index.php` phân phối tất cả các route admin:
  * `admin_dashboard`, `admin_sp`, `admin_sp_them`, `admin_sp_sua`, `admin_sp_xoa`, `admin_sp_visibility`, `admin_sp_stock`.
  * `admin_orders`, `admin_order_status`, `admin_order_print`.
  * `admin_vouchers`, `admin_voucher_save`, `admin_voucher_delete`.
  * `admin_lives`, `admin_live_create`, `admin_live_edit`, `admin_live_status`, `admin_live_delete`.
  * `admin_categories`, `admin_users`, `admin_reviews`, `admin_reports`, `admin_questions`.
* **Controller duy nhất:** `backend/app/controllers/QuanTriController.php` (1.713 dòng).
* **Cơ chế xác thực & phân quyền (Role Guard):**
  ```php
  $this->requireRole(['admin']); // Hoặc ['admin', 'nhanvien']
  ```
  Nếu chưa đăng nhập: redirect về `index.php?r=dangnhap`. Nếu không đủ role: trả về HTTP 403 Forbidden và render template lỗi `admin/403.php`.

### 1.2 Cấu Trúc Layout & Giao Diện Admin
* Phương thức render chuẩn:
  ```php
  private function renderAdmin(string $view, array $data = []): void {
      $data['notificationCenter'] = $data['notificationCenter'] ?? $this->buildNotificationCenter();
      extract($data);
      $viewDir = defined('VIEW_DIR') ? VIEW_DIR : __DIR__ . '/../views';
      require $viewDir . '/admin/layouts/header.php';
      require $viewDir . '/admin/' . $view . '.php';
      require $viewDir . '/admin/layouts/footer.php';
  }
  ```
* Header chung (`admin/layouts/header.php`) chứa Sidebar điều hướng, Topbar thông báo đơn hàng/chat, thông tin Admin đăng nhập.

### 1.3 Quy Ước Thiết Kế Model (Model CRUD Conventions)
Các model quản trị (`Voucher.php`, `SanPham.php`, `HoaDon.php`, `PhienLive.php`) tuân thủ nghiêm ngặt mô hình sau:
1. `getNextNumericId(string $collection, string $column): int` để sinh khóa chính tự tăng (như `ma_voucher`, `ma_hoa_don`, `ma_live`).
2. `normalizeXRow($doc): array` để ép kiểu dữ liệu chuẩn, xử lý BSON `UTCDateTime` sang chuỗi ngày giờ Việt Nam (`Y-m-d H:i:s` tại múi giờ `Asia/Ho_Chi_Minh`), định dạng số tiền, số lượng tồn.
3. Flash notification thông qua helper `set_flash('success' | 'error', $message)` và redirect PRG (Post-Redirect-Get).

### 1.4 Kiểm Tra Module Khuyến Mãi Hiện Có
* **Voucher Module (`Voucher.php`):** Quản lý mã giảm giá coupon (như `FREESHIP`, `GIAM20K`) áp dụng trên toàn giỏ hàng dựa trên tổng tiền đơn hàng (`gia_tri_don_toi_thieu`). **Không quản lý giá trực tiếp của từng sản phẩm hay Flash Sale theo khung giờ.**
* **Livestream Module (`PhienLive.php`):** Quản lý phiên livestream bán hàng có tính năng ghim sản phẩm deal ngắn (5–30 phút). Tuy nhiên đây là deal realtime trong phòng live, không phục vụ banner Homepage 24/7.
* **Kết luận:** **Hiện tại hệ thống CHƯA CÓ module Campaign/Promotion nào cho Flash Sale.** Cần thiết kế một module mới hoàn toàn độc lập nhưng chuẩn hóa 100% theo các quy ước trên.

---

## 2. Thiết Kế Module Quản Trị Flash Sale (Proposed Specification)

### 2.1 Ranh Giới Quyền Hạn (Admin vs System Ownership)

```
┌──────────────────────────────────────────────┐
│ ADMIN CONTROLS (Con người quyết định)        │
├──────────────────────────────────────────────┤
│ 1. Tên chiến dịch (Campaign Name)            │
│ 2. Thời gian bắt đầu (start_at)              │
│ 3. Thời gian kết thúc (end_at)               │
│ 4. Lựa chọn sản phẩm tham gia (12-20 SKUs)  │
│ 5. Mức giá Flash Sale (flash_price)          │
│ 6. Bật/Tắt thủ công nếu cần hủy khẩn cấp    │
│                                              │
│ [X] KHÔNG can thiệp thứ tự xếp hạng user!    │
└──────────────────────────────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────┐
│ SYSTEM CONTROLS (Hệ thống tự động hóa)       │
├──────────────────────────────────────────────┤
│ 1. Trạng thái vòng đời: UPCOMING/ACTIVE/ENDED │
│ 2. Đếm ngược (Countdown ticker)              │
│ 3. Tính toán % giảm: (regular - flash) / reg │
│ 4. Cá nhân hóa thứ tự hiển thị cho từng user │
│ 5. Lọc Top 4 hiển thị trên Homepage          │
│ 6. Bảo toàn giá Flash Sale sang Cart/Order   │
│                                              │
│ [X] KHÔNG tự ý giảm giá ngoài danh sách      │
└──────────────────────────────────────────────┘
```

---

## 3. Thiết Kế Các Màn Hình Quản Trị (Admin UI Screens)

### Màn Hình A: Danh Sách Chiến Dịch Flash Sale (`admin/flash_sales.php`)
* **URL:** `index.php?r=admin_flash_sales`
* **Các thành phần giao diện:**
  * Nút CTA: `+ Tạo chiến dịch Flash Sale mới`.
  * Bộ lọc tìm kiếm theo từ khóa tên chiến dịch và trạng thái (`Tất cả`, `Đang diễn ra`, `Sắp diễn ra`, `Đã kết thúc`).
  * **Bảng dữ liệu (Data Table):**
    1. *Tên chiến dịch* (ví dụ: "Flash Sale Cuối Tuần - Đón Nắng Rực Rỡ").
    2. *Bắt đầu* (`d/m/Y H:i`).
    3. *Kết thúc* (`d/m/Y H:i`).
    4. *Trạng thái hệ thống:*
       * Badge Xanh lá: `Đang diễn ra` (Server time: `start_at <= now < end_at`).
       * Badge Xanh dương: `Sắp diễn ra` (Server time: `now < start_at`).
       * Badge Xám: `Đã kết thúc` (Server time: `now >= end_at`).
    5. *Số lượng sản phẩm:* (ví dụ: `16 sản phẩm`).
    6. *Thao tác:*
       * Nút `Xem trước (Preview)`.
       * Nút `Chỉnh sửa (Edit)`.
       * Nút `Kết thúc sớm / Kích hoạt lại`.
       * Nút `Xóa (Delete)`.

### Màn Hình B: Tạo / Chỉnh Sửa Chiến Dịch (`admin/flash_sale_form.php`)
* **URL:** `index.php?r=admin_flash_sale_them` hoặc `index.php?r=admin_flash_sale_sua&id=...`
* **Các trường thông tin chung:**
  * `ten_campaign`: Text input (Bắt buộc, 5–100 ký tự).
  * `mo_ta`: Textarea (Tùy chọn).
  * `start_at`: Datetime-local input (Bắt buộc).
  * `end_at`: Datetime-local input (Bắt buộc, phải lớn hơn `start_at` ít nhất 30 phút).
  * `trang_thai_kich_hoat`: Toggle On/Off (Mặc định On).
* **Bảng Chọn Sản Phẩm & Định Giá Deal (Product Selector Table):**
  * Nút `+ Thêm sản phẩm vào Flash Sale` (mở Modal tìm kiếm sản phẩm theo tên/danh mục/thương hiệu, chỉ hiển thị sản phẩm `active` và có `so_luong_ton_kho > 0`).
  * Danh sách sản phẩm đã chọn (khuyến nghị 12–20 sản phẩm):
    * Cột 1: *Ảnh thumbnail* (20x20) + Tên sản phẩm + Mã SKU.
    * Cột 2: *Giá niêm yết hiện tại* (`gia_ban` đọc tự động từ DB, ví dụ: 250.000 đ).
    * Cột 3: *Giá Flash Sale (`flash_price`)*: Number input. Bắt buộc: $0 < \text{flash\_price} < \text{gia\_ban}$.
    * Cột 4: *% Giảm giá tự động*: Hiển thị realtime bằng JavaScript khi Admin nhập giá flash (ví dụ: `-35%`).
    * Cột 5: *Tồn kho hiện có* (ví dụ: `300`).
    * Cột 6: Nút `Xóa khỏi deal`.

### Màn Hình C: Xem Trước Chiến Dịch (Campaign Preview)
* **URL:** `index.php?r=admin_flash_sale_preview&id=...`
* Hiển thị mô phỏng trực quan:
  * Thẻ Header Flash Sale như trên Homepage thật.
  * Đồng hồ đếm ngược chạy thử nghiệm theo giờ server.
  * Preview Top 4 sản phẩm mặc định (Cold Start) và danh sách đầy đủ.

---

## 4. Các Route & Action Backend Đề Xuất Cho Phase 2

```php
// Dispatch trong index.php:
case 'admin_flash_sales':
    (new QuanTriController($pdo))->adminFlashSales();
    break;
case 'admin_flash_sale_them':
case 'admin_flash_sale_sua':
    (new QuanTriController($pdo))->adminFlashSaleEdit();
    break;
case 'admin_flash_sale_save':
    (new QuanTriController($pdo))->adminFlashSaleSave();
    break;
case 'admin_flash_sale_delete':
    (new QuanTriController($pdo))->adminFlashSaleDelete();
    break;
case 'admin_flash_sale_preview':
    (new QuanTriController($pdo))->adminFlashSalePreview();
    break;
```

---

## 5. Quy Tắc Chống Xung Đột Chiến Dịch (Conflict Prevention Rules)

1. **Quy tắc Sản phẩm Trùng Lặp (Overlapping Active Product):**
   * Một sản phẩm chỉ được phép tham gia **duy nhất một chiến dịch ACTIVE** tại một thời điểm.
   * Khi Admin lưu chiến dịch mới hoặc cập nhật thời gian:
     Backend chạy kiểm tra đối soát:
     $$\text{Conflict} \iff (P_{\text{new}} \cap P_{\text{existing}} \ne \emptyset) \land (\text{start}_{\text{new}} < \text{end}_{\text{existing}}) \land (\text{end}_{\text{new}} > \text{start}_{\text{existing}})$$
   * Nếu phát hiện sản phẩm đã nằm trong một chiến dịch trùng khung giờ: Backend lập tức chặn lưu (reject) và thông báo lỗi rõ ràng: *"Sản phẩm #ID đã nằm trong chiến dịch '[Tên Campaign]' diễn ra từ ... đến ..."*.
2. **Quy tắc Toàn Vẹn Giá (Price Invariant):**
   * Bắt buộc: $\text{flash\_price} \le 0.95 \times \text{gia\_ban}$ (giảm tối thiểu 5%) để tránh tạo deal ảo.
   * Bắt buộc: $\text{flash\_price} \ge 1.000\text{ đ}$ (chống lỗi nhập nhầm số 0).
