# TÍCH HỢP VÍ VOUCHER KHÁCH HÀNG (CUSTOMER WALLET) — PHASE V2C

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Quản lý và Hiển thị Voucher Cá Nhân (Customer Wallet UI & Data Isolation)  
**Tác giả:** Senior PHP/MongoDB E-commerce Engineer & Application Security Engineer  
**Ngày:** 13/10/2026  

---

## 1. Mục tiêu và Nguyên tắc Thiết kế

1. **Giao diện thân thiện và thẩm mỹ cao:**
   - Tích hợp một tab chuyên biệt "Ví Voucher" (`#tab-vouchers`) vào trang thông tin cá nhân khách hàng (`hoso.php`).
   - Thiết kế theo phong cách hiện đại (cards layout, badge trạng thái bo tròn, icon trực quan, gradient nhẹ nhàng).
2. **Cách ly dữ liệu tuyệt đối (Tenant/Data Isolation):**
   - Khách hàng đăng nhập chỉ xem được voucher của chính tài khoản mình.
   - Tuyệt đối không để lộ mã hoặc thông tin ưu đãi của khách hàng khác.
   - Endpoint backend không nhận `customer_id` từ client query/body; `customer_id` luôn được lấy từ `$_SESSION['user']['id']`.
3. **Tiện ích người dùng (UX Enhancements):**
   - Hiển thị đầy đủ thông tin: Mã voucher, mức giảm, giá trị đơn tối thiểu, mức giảm tối đa, thời hạn sử dụng, danh sách mã sản phẩm áp dụng.
   - Hỗ trợ nút "Sao chép" (Copy to clipboard) một chạm kèm hiệu ứng thông báo phản hồi tức thì.
   - Liên kết trực tiếp "Dùng ngay" trỏ về trang chi tiết sản phẩm tương ứng.

---

## 2. Kiến trúc Tích hợp Giao diện (Frontend Implementation)

### 2.1. Điều hướng Tab trong Hồ sơ Tài khoản (`frontend/views/hoso.php`)

Trong menu bên trái của trang tài khoản, bổ sung mục Ví Voucher với icon vé giảm giá:

```html
<a href="#vouchers" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between p-3" data-tab="vouchers">
    <span><i class="bi bi-ticket-perforated-fill text-danger me-2"></i> Ví Voucher Của Tôi</span>
    <span class="badge bg-danger rounded-pill"><?= count($customerVouchers ?? []) ?></span>
</a>
```

### 2.2. Card Hiển thị Voucher Cá nhân

Mỗi voucher được biểu diễn dưới dạng thẻ voucher sang trọng:

- **Header:**
  - Badge trạng thái:
    - `AVAILABLE`: Màu xanh lá cây rực rỡ (`bg-success`), hiển thị text **Khả dụng**.
    - `USED`: Màu xám (`bg-secondary`), text **Đã sử dụng**.
    - `EXPIRED`: Màu cảnh báo (`bg-danger`), text **Hết hạn**.
  - Badge ưu đãi: Thẻ tag màu hồng phấn `bg-danger bg-opacity-10 text-danger` ghi rõ tỷ lệ hoặc số tiền giảm (ví dụ: *Giảm 15%* hoặc *Giảm 50.000đ*).
- **Body:**
  - Mã voucher in đậm khổ lớn kèm khung viền nét đứt: `<code class="fs-5 text-danger fw-bold">SYNA-776059FB</code>`.
  - Nút sao chép mã với icon clipboard và tooltip.
  - Tên chiến dịch ưu đãi xem video (ví dụ: *Ưu đãi Syna 3D - Giảm 15% khi xem video*).
  - Điều kiện áp dụng: Đơn tối thiểu, giảm tối đa, sản phẩm áp dụng.
  - Hạn sử dụng: Ngày bắt đầu $\rightarrow$ Ngày hết hạn định dạng chuẩn `d/m/Y H:i`.
- **Footer:**
  - Nút CTA trỏ tới sản phẩm áp dụng: `<a href="index.php?r=chitiet&id=6376" class="btn btn-outline-danger btn-sm">Xem sản phẩm áp dụng</a>`.

---

## 3. Kiến trúc Backend Controller (`TaiKhoanController.php`)

Khi người dùng truy cập trang `index.php?r=hoso`:
1. `TaiKhoanController::hoso()` kiểm tra session khách hàng:
   ```php
   if (!isset($_SESSION['user'])) {
       header("Location: index.php?r=dangnhap");
       exit;
   }
   $customerId = (string)($_SESSION['user']['id'] ?? $_SESSION['user']['ma_kh'] ?? '');
   ```
2. Gọi `CustomerVoucher::getCustomerVouchers($customerId)` để lấy danh sách voucher.
3. Tự động kiểm tra và cập nhật trạng thái hết hạn (`EXPIRED`) nếu thời gian hiện tại đã vượt qua `end_date`.
4. Truyền biến `$customerVouchers` sang view `frontend/views/hoso.php`.

### 3.1. API Lấy Danh sách Voucher Cá nhân (`/?r=customer_vouchers`)

Cung cấp thêm endpoint dạng JSON cho các tương tác dynamic:
- **Method:** `GET`
- **Controller:** `VideoWatchController::getCustomerVouchers()`
- **Response:**
  ```json
  {
    "ok": true,
    "customer_id": "20",
    "total": 1,
    "vouchers": [
      {
        "voucher_code": "SYNA-776059FB",
        "campaign_name": "Ưu đãi Syna 3D - Giảm 15% khi xem video",
        "discount_type": "percentage",
        "discount_value": 15,
        "max_discount_amount": 50000,
        "min_order_amount": 100000,
        "applicable_products": ["6376"],
        "status": "AVAILABLE",
        "start_date": "2026-10-13 11:45:00",
        "end_date": "2026-11-12 11:45:00"
      }
    ]
  }
  ```

---

## 4. Xác minh An toàn Dữ liệu (Isolation Evidence)

Trong bộ test tự động `backend/tests/test_v2c_reward_issuance.php`, kịch bản 14 đã chứng minh:
- Tài khoản `cust_v2c_user1` chỉ nhìn thấy 1 voucher thuộc về chính mình.
- Tài khoản `cust_v2c_migrated_3` chỉ nhìn thấy voucher của chính mình.
- Tài khoản lạ không có voucher (`cust_stranger_unknown`) nhận kết quả danh sách rỗng (`count = 0`).
- Không có bất kỳ voucher nào bị rò rỉ chéo giữa các tài khoản người dùng (`[PASS] 14.1`).
