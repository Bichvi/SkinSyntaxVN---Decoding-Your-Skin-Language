# THIẾT KẾ SCHEMA DỮ LIỆU VOUCHER (VOUCHER SCHEMA) — PHASE V2C

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Cấu trúc Dữ liệu Ví Khách Hàng và Voucher Thực Thi  
**Tác giả:** Senior PHP/MongoDB E-commerce Engineer & Application Security Engineer  
**Ngày:** 13/10/2026  

---

## 1. Collection `customer_vouchers` (Customer Wallet & Entitlement)

Bảng dữ liệu lưu trữ quyền sở hữu voucher cá nhân của khách hàng, đóng vai trò làm sổ cái (ledger) theo dõi phần thưởng đã cấp.

### 1.1. Cấu trúc Document

```json
{
  "_id": ObjectId("670b8a21f1c2d3e4a5b6c7d8"),
  "entitlement_id": "ent_776059fb9a01",
  "customer_id": "20",
  "customer_email": "ngoc56643@gmail.com",
  "campaign_id": "rew_7e6ada3dc3",
  "campaign_name": "Ưu đãi Syna 3D - Giảm 15% khi xem video",
  "watch_session_id": "d2bed56a0079ac80020a38b85fac1af8",
  "video_id": "6acc27e526e9d8acf59c457e",
  "publication_id": "6acc6bd54e1a7ffa0e0672b2",
  "voucher_id": 4,
  "voucher_code": "SYNA-776059FB",
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

### 1.2. Từ điển Dữ liệu (Data Dictionary)

| Tên trường | Kiểu dữ liệu | Bắt buộc | Mô tả |
|---|---|---|---|
| `_id` | ObjectId | Có | Định danh nội bộ của MongoDB document |
| `entitlement_id` | String | Có | Mã quyền lợi duy nhất (`ent_` + 12 hex) |
| `customer_id` | String | Có | ID tài khoản khách hàng sở hữu |
| `customer_email` | String | Không | Email khách hàng tại thời điểm claim |
| `campaign_id` | String | Có | Mã chiến dịch thưởng nguồn |
| `campaign_name` | String | Có | Tên chiến dịch hiển thị trên ví khách hàng |
| `watch_session_id` | String | Có | Mã phiên xem video hợp lệ tạo ra phần thưởng |
| `video_id` | String | Có | ID video Syna AI tương ứng |
| `publication_id` | String | Có | ID bản xuất bản video trên sản phẩm |
| `voucher_id` | Integer | Có | Khóa ngoại trỏ tới `ma_voucher` trong collection `voucher` |
| `voucher_code` | String | Có | Mã code thực thi duy nhất (ví dụ: `SYNA-776059FB`) |
| `discount_type` | String | Có | Loại giảm giá: `percentage` hoặc `fixed` |
| `discount_value` | Number | Có | Giá trị chiết khấu (% hoặc VNĐ) |
| `max_discount_amount`| Number | Không | Giảm tối đa nếu là phần trăm (VNĐ) |
| `min_order_amount` | Number | Có | Giá trị đơn hàng tối thiểu để áp dụng (VNĐ) |
| `applicable_products`| Array<String> | Có | Danh sách mã sản phẩm được áp dụng |
| `status` | String | Có | `AVAILABLE` (Khả dụng), `USED` (Đã dùng), `EXPIRED` (Hết hạn) |
| `claimed_at` | UTCDateTime | Có | Thời điểm nhận voucher |
| `start_date` | UTCDateTime | Có | Thời điểm bắt đầu hiệu lực |
| `end_date` | UTCDateTime | Có | Thời điểm hết hạn voucher |
| `used_at` | UTCDateTime | Không | Thời điểm sử dụng tại checkout (V2D) |
| `order_id` | String | Không | Mã đơn hàng đã sử dụng voucher (V2D) |

### 1.3. MongoDB Indexes

```javascript
db.customer_vouchers.createIndex({ campaign_id: 1, customer_id: 1 }, { unique: true });
db.customer_vouchers.createIndex({ watch_session_id: 1 }, { unique: true });
db.customer_vouchers.createIndex({ voucher_code: 1 }, { unique: true });
db.customer_vouchers.createIndex({ customer_id: 1, status: 1 });
```

- **`campaign_id` + `customer_id` (UNIQUE):** Chặn đứng nguy cơ 1 khách hàng nhận nhiều voucher trên cùng một chiến dịch ở mức cơ sở dữ liệu.
- **`watch_session_id` (UNIQUE):** Chặn việc tái sử dụng 1 phiên xem video để claim nhiều lần.
- **`voucher_code` (UNIQUE):** Đảm bảo không trùng mã voucher trên toàn hệ thống.
- **`customer_id` + `status`:** Tối ưu hóa truy vấn hiển thị danh sách voucher trong trang Ví Khách Hàng.

---

## 2. Collection `voucher` (Execution Voucher — Tái sử dụng `Voucher.php`)

Document được sinh tự động trong collection `voucher` kế thừa toàn bộ thuộc tính chuẩn của SkinSyntaxVN:

```json
{
  "_id": ObjectId("670b8a21f1c2d3e4a5b6c7d9"),
  "ma_voucher": 4,
  "ma_code": "SYNA-776059FB",
  "ten_voucher": "Ưu đãi Syna 3D - Giảm 15% khi xem video",
  "mo_ta": "Voucher xem video Syna trên trang chi tiết sản phẩm 6376",
  "loai_giam": "percent",
  "gia_tri_giam": 15,
  "gia_tri_don_toi_thieu": 100000,
  "giam_toi_da": 50000,
  "so_luong": 1,
  "so_luong_da_dung": 0,
  "ngay_bat_dau": "2026-10-13 11:45:00",
  "ngay_ket_thuc": "2026-11-12 11:45:00",
  "trang_thai": "active",
  "source_campaign_id": "rew_7e6ada3dc3",
  "customer_id": "20",
  "created_at": "2026-10-13 11:45:00",
  "updated_at": "2026-10-13 11:45:00"
}
```

### Điểm đặc biệt:
1. `so_luong = 1` và `so_luong_da_dung = 0`: Đảm bảo mã voucher duy nhất này chỉ có thể sử dụng đúng 1 lần duy nhất trong toàn hệ thống.
2. `source_campaign_id` và `customer_id`: Được thêm vào metadata để hỗ trợ truy vết nguồn gốc (audit trail) mà không can thiệp vào các logic validate chung của `Voucher.php`.
3. Chuẩn hóa `loai_giam`: Mapping từ `percentage` sang `percent` để tương thích hoàn toàn với logic tính tiền của `Voucher.php` và `giohang.php`.

---

## 3. Khả năng Tương thích Ngược (Backward Compatibility)

1. **Mã giảm giá công khai toàn sàn:** Các bản ghi cũ trong `voucher` (ví dụ `WELCOME10`, `SYNA15`) tiếp tục hoạt động bình thường, không chứa `customer_id` bắt buộc và áp dụng cho toàn thể khách hàng.
2. **Không sửa schema bừa bãi:** Không thêm cột bắt buộc nào làm gãy cấu trúc của các module quản lý khuyến mãi admin (`QuanLyKhuyenMaiController.php`).
3. **Giá sản phẩm:** Collection `san_pham` và trường `gia_ban` hoàn toàn không bị tác động. Chiết khấu sẽ chỉ được áp dụng dưới dạng voucher giảm giá khi tới Phase V2D.
