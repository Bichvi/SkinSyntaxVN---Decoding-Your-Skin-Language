# BÁO CÁO KIỂM TOÁN VÀ THIẾT KẾ DỮ LIỆU (AUDIT & DATA DESIGN) — PHASE V2C

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Cấp Voucher Thật cho Khách Hàng (Voucher Issuance & Customer Wallet)  
**Kỹ sư thực hiện:** Senior PHP/MongoDB E-commerce Engineer & Application Security Engineer  
**Ngày thực hiện:** 13/10/2026  
**Trạng thái:** Hoàn tất kiểm toán (Audit Complete) & Phê duyệt thiết kế dữ liệu  

---

## 1. Hiện trạng Source Code và Cơ sở Dữ liệu

### 1.1. Model `Voucher.php` và Collection `voucher`
- **Cấu trúc hiện tại:**
  Collection `voucher` lưu trữ các mã giảm giá công khai của toàn sàn với các trường chính:
  - `ma_voucher` (integer, auto-increment: 1, 2, ...)
  - `ma_code` (chuỗi ký tự in hoa độc nhất, ví dụ: `WELCOME10`, `SYNA15`)
  - `ten_voucher`, `mo_ta`
  - `loai_giam` (`percent` | `fixed`)
  - `gia_tri_giam` (số tiền hoặc phần trăm)
  - `gia_tri_don_toi_thieu`, `giam_toi_da`
  - `so_luong`, `so_luong_da_dung`
  - `ngay_bat_dau`, `ngay_ket_thuc`
  - `trang_thai` (`active` | `inactive`)
  - `created_at`, `updated_at`
- **Đặc điểm sở hữu:**
  Collection `voucher` **hoàn toàn chưa có cơ chế định danh khách hàng sở hữu** (`customer_id` hoặc per-user ownership). Bất kỳ ai biết mã code đều có thể nhập tại checkout nếu mã còn hạn và còn số lượng.
- **Rủi ro nếu sửa schema bừa bãi:**
  Nếu biến đổi bảng `voucher` thành bảng voucher cá nhân hoặc bắt buộc phải có `customer_id`, toàn bộ các mã giảm giá chung toàn sàn hiện có sẽ bị lỗi hoặc không thể áp dụng cho khách vãng lai/người mua chung.

### 1.2. Giải pháp Kiến trúc: Lớp Entitlement Riêng (`customer_vouchers`)
Để **tái sử dụng hệ thống `Voucher.php` mà không phá vỡ mã giảm giá chung** và **không tạo duplicate voucher system**, chúng tôi áp dụng kiến trúc 2 tầng (Two-Tier Entitlement & Voucher Mapping):
1. **Tầng 1 — Entitlement/Wallet (`customer_vouchers`):**
   Lưu trữ quyền lợi và lịch sử sở hữu voucher của khách hàng gắn liền với phiên xem video Syna (`watch_session_id`), chiến dịch thưởng (`campaign_id`), và tài khoản khách hàng (`customer_id`).
2. **Tầng 2 — Mã Voucher Thực thi (`voucher`):**
   Khi một quyền lợi được cấp, hệ thống tự động sinh một mã voucher cá nhân duy nhất (ví dụ: `SYNA-` + 8 ký tự hex ngẫu nhiên) với `so_luong = 1` và lưu vào collection `voucher`. Nhờ vậy:
   - Tương thích 100% với model `Voucher.php` hiện có.
   - Mã là duy nhất, chỉ sử dụng đúng 1 lần (`so_luong = 1`, `so_luong_da_dung = 0`).
   - Có thể truy vết ngược về campaign và khách hàng sở hữu.

### 1.3. Định danh Khách hàng và Session
- Khách hàng đăng nhập lưu trong `$_SESSION['user']` với các trường:
  `id` (hoặc `ma_kh`), `email`, `ho_ten`, `role` (`khach_hang`).
- Khách vãng lai xem video ở chế độ Guest:
  Được định danh qua `guest_session_id = session_id()`.
- **Nguyên tắc an toàn:**
  - Client tuyệt đối **không được phép tự gửi `customer_id`**.
  - Server lấy `customer_id` độc quyền từ `$_SESSION['user']`.
  - Khách vãng lai xem xong video, nếu sau đó đăng nhập cùng trình duyệt (`session_id()`), hệ thống sẽ liên kết phiên xem đó với tài khoản đăng nhập để cho phép nhận thưởng một cách an toàn.

---

## 2. Thiết kế Cấu trúc Dữ liệu Chi tiết

### 2.1. Collection mới: `customer_vouchers` (Customer Wallet & Entitlements)
Mỗi bản ghi đại diện cho 1 voucher trong ví của khách hàng:

```json
{
  "_id": ObjectId("..."),
  "entitlement_id": "ent_a1b2c3d4e5f6g7h8",
  "customer_id": "20",
  "customer_email": "ngoc56643@gmail.com",
  "campaign_id": "rew_7e6ada3dc3",
  "campaign_name": "Ưu đãi Syna 3D - Giảm 15% khi xem video",
  "watch_session_id": "d2bed56a0079ac80020a38b85fac1af8",
  "video_id": "6acc27e526e9d8acf59c457e",
  "publication_id": "6acc6bd54e1a7ffa0e0672b2",
  "voucher_id": 3,
  "voucher_code": "SYNA-8F2B1C9D",
  "discount_type": "percentage",
  "discount_value": 15,
  "max_discount_amount": 50000,
  "min_order_amount": 100000,
  "applicable_products": ["6376"],
  "status": "AVAILABLE",
  "claimed_at": ISODate("2026-10-13T04:45:00.000Z"),
  "start_date": ISODate("2026-10-13T04:45:00.000Z"),
  "end_date": ISODate("2026-11-12T04:45:00.000Z"),
  "used_at": null,
  "order_id": null,
  "created_at": ISODate("2026-10-13T04:45:00.000Z"),
  "updated_at": ISODate("2026-10-13T04:45:00.000Z")
}
```

### 2.2. Các Index Bắt buộc cho `customer_vouchers`:
1. `{'campaign_id': 1, 'customer_id': 1}` — **UNIQUE**: Ngăn chặn tuyệt đối cùng một khách hàng nhận quá 1 lần trên cùng 1 chiến dịch.
2. `{'watch_session_id': 1}` — **UNIQUE**: Đảm bảo mỗi phiên xem video chỉ được nhận thưởng đúng 1 lần.
3. `{'voucher_code': 1}` — **UNIQUE**: Tra cứu nhanh mã voucher.
4. `{'customer_id': 1, 'status': 1}`: Truy vấn ví voucher cá nhân hiệu năng cao.

---

## 3. Cơ chế Đảm bảo Tính Atomic & Concurrency (Step 3)

Để xử lý concurrency thật, tránh race conditions và double-claim:

1. **Atomic Quota Reservation:**
   Trước khi tạo voucher, server thực hiện trừ quota nguyên tử trên `ai_video_reward_campaigns`:
   ```php
   $res = $this->db->ai_video_reward_campaigns->updateOne(
       [
           'campaign_id' => $campaignId,
           'is_active' => true,
           '$expr' => ['$lt' => ['$issued_count', '$total_quota']]
       ],
       [
           '$inc' => ['issued_count' => 1],
           '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()]
       ]
   );
   ```
   Nếu `getModifiedCount() === 0`: Hết quota hoặc chiến dịch đã tắt $\rightarrow$ Ngắt ngay lập tức, trả lỗi `QUOTA_EXHAUSTED` hoặc `CAMPAIGN_INACTIVE`.
2. **Idempotency & Rollback Compensation:**
   Nếu quá trình ghi vào `customer_vouchers` gặp trùng lặp (lỗi E11000 duplicate key do double-click) hoặc lỗi ngoại lệ:
   - Server lập tức hoàn trả hạn ngạch:
     `$this->db->ai_video_reward_campaigns->updateOne(['campaign_id' => $campaignId], ['$inc' => ['issued_count' => -1]]);`
   - Đảm bảo `issued_count` không bị tăng sai lệch.

---

## 4. Danh sách các File Dự kiến Thay đổi

| STT | File | Hành động | Mục đích |
| :---: | :--- | :--- | :--- |
| 1 | `backend/app/models/CustomerVoucher.php` | **Tạo mới** | Model quản lý cấp thưởng, atomic reservation, ví voucher khách hàng, mapping `Voucher.php`. |
| 2 | `backend/app/controllers/VideoWatchController.php` | **Cập nhật** | Thêm endpoint `claimReward()` và `customerVouchers()`. |
| 3 | `backend/app/controllers/TaiKhoanController.php` | **Cập nhật** | Nạp danh sách voucher cá nhân vào dữ liệu view `hoso`. |
| 4 | `frontend/views/hoso.php` | **Cập nhật** | Thêm Tab Ví Voucher (`#tab-vouchers`) hiển thị danh sách voucher của khách hàng. |
| 5 | `frontend/views/chitiet.php` | **Cập nhật** | Thêm nút "Nhận voucher", xử lý anti-double click, chuyển hướng đăng nhập cho Guest, hiển thị thông báo đã nhận. |
| 6 | `index.php` (các entry points) | **Cập nhật** | Đăng ký route `video_watch_claim_reward` và `customer_vouchers`. |
| 7 | `backend/tests/test_v2c_reward_issuance.php` | **Tạo mới** | Suite kiểm thử tự động 17 kịch bản bảo mật và concurrency. |

---

## 5. Cam kết Tuân thủ Phạm vi (Boundaries)

- **Tuyệt đối không sửa checkout:** Luồng đặt hàng và thanh toán giữ nguyên 100% cho Phase V2D.
- **Không tự động giảm giá đơn hàng:** Giá gốc sản phẩm `gia_ban` không bị can thiệp.
- **Không sửa Flash Sale & Recommendation Engine.**
- **Không xóa 2 chiến dịch demo hiện có.**
