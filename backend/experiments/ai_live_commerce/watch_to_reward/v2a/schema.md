# DATA MODEL & SCHEMA SPECIFICATION — PHASE V2A

**Collection:** `ai_video_reward_campaigns`  
**Database:** MongoDB Atlas / Local MongoDB (`skinsyntax`)  
**Domain Model:** `backend/app/models/VideoRewardCampaign.php`  

---

## 1. Mục tiêu thiết kế dữ liệu

Mô hình dữ liệu `ai_video_reward_campaigns` định nghĩa chính sách phần thưởng khuyến mãi (Watch-to-Reward) được liên kết chặt chẽ với một video sản phẩm AI Idol đã xuất bản hợp lệ (`ai_product_videos`). Mô hình này đảm bảo tính toàn vẹn dữ liệu, hỗ trợ kiểm tra ràng buộc kinh doanh đa lớp và ngăn chặn mọi xung đột xếp chồng ưu đãi trái phép.

---

## 2. Đặc tả lược đồ chi tiết (Schema Specification)

| Tên trường | Kiểu dữ liệu | Bắt buộc | Mặc định | Mô tả & Ràng buộc nghiệp vụ |
| :--- | :--- | :---: | :---: | :--- |
| `_id` | `ObjectId` | Có | Tự động | Khóa định danh BSON duy nhất của document MongoDB. |
| `campaign_id` | `string` | Có | `rew_{hash}` | Mã định danh chiến dịch duy nhất, lập chỉ mục `unique`. |
| `campaign_name` | `string` | Có | — | Tên hiển thị của chiến dịch voucher (ví dụ: *Voucher xem video Syna 3D - Giảm 15%*). |
| `video_id` | `string` | Có | — | Mã định danh video tham chiếu đến video kết xuất từ AI Idol. |
| `publication_id` | `string` | Có | — | Mã định danh bản ghi xuất bản trong `ai_product_videos`. |
| `discount_type` | `string` | Có | `'percentage'` | Loại hình chiết khấu: `'percentage'` (phần trăm) hoặc `'fixed'` (tiền cố định). |
| `discount_value` | `float` | Có | — | Giá trị chiết khấu: Ràng buộc `1 <= discount_value <= 100` nếu là phần trăm; `discount_value > 0` nếu là tiền cố định. |
| `max_discount_amount`| `int\|null`| Không | `null` | Mức giảm tối đa (VNĐ) áp dụng khi loại giảm giá là phần trăm. Ràng buộc `> 0`. |
| `min_order_amount` | `int` | Có | `0` | Giá trị đơn hàng tối thiểu (VNĐ) để được áp dụng voucher. Ràng buộc `>= 0`. |
| `applicable_product_ids` | `array<string>` | Có | `[]` | Danh sách mã sản phẩm được phép áp dụng voucher. Tự động lấy danh sách sản phẩm liên kết của video nếu Admin không chọn tùy biến. |
| `start_date` | `UTCDateTime` | Có | — | Thời điểm bắt đầu có hiệu lực (UTC). Ràng buộc `start_date < end_date`. |
| `end_date` | `UTCDateTime` | Có | — | Thời điểm kết thúc hiệu lực (UTC). Ràng buộc `end_date > start_date`. |
| `total_quota` | `int` | Có | `100` | Tổng số lượng voucher phát hành tối đa. Ràng buộc `> 0` và không được giảm xuống dưới `issued_count`. |
| `issued_count` | `int` | Có | `0` | Số lượng voucher đã được cấp phát thực tế cho người xem. Bắt đầu từ 0; chỉ hiển thị số liệu thực tế, cấm số liệu ảo. |
| `user_limit` | `int` | Có | `1` | Số lần tối đa mỗi khách hàng được nhận voucher từ video này. Ràng buộc `>= 1`. |
| `is_active` | `boolean` | Có | `true` | Trạng thái bật/tắt chiến dịch. Ràng buộc: Mỗi video chỉ được phép có tối đa 1 chiến dịch ở trạng thái `is_active = true`. |
| `stack_with_flash_sale` | `boolean` | Có | `false` | Chính sách cộng dồn với Flash Sale. Mặc định `false` (không cho phép cộng dồn). |
| `stack_with_other_vouchers` | `boolean` | Có | `false` | Chính sách cộng dồn với voucher khuyến mãi khác. Mặc định `false`. |
| `created_at` | `UTCDateTime` | Có | `now()` | Thời điểm tạo bản ghi. |
| `updated_at` | `UTCDateTime` | Có | `now()` | Thời điểm cập nhật cấu hình gần nhất. |

---

## 3. MongoDB Indexes

Các chỉ mục sau được khởi tạo tự động trong `ensureIndexes()`:

```javascript
// 1. Khóa duy nhất cho mã chiến dịch
db.ai_video_reward_campaigns.createIndex({ "campaign_id": 1 }, { unique: true });

// 2. Tra cứu nhanh theo video_id
db.ai_video_reward_campaigns.createIndex({ "video_id": 1 });

// 3. Tra cứu nhanh theo publication_id
db.ai_video_reward_campaigns.createIndex({ "publication_id": 1 });

// 4. Kiểm soát ràng buộc nghiệp vụ: Một chiến dịch active cho mỗi video
db.ai_video_reward_campaigns.createIndex({ "video_id": 1, "is_active": 1 });

// 5. Truy vấn chiến dịch theo thời gian hiệu lực và trạng thái
db.ai_video_reward_campaigns.createIndex({ "is_active": 1, "start_date": 1, "end_date": 1 });
```

---

## 4. Ví dụ Document MongoDB Chuẩn

```json
{
  "_id": ObjectId("670c3e80f4a123456789abcd"),
  "campaign_id": "rew_6acc27e526e9d8acf59c457e",
  "campaign_name": "Voucher xem video: Syna 3D - Giảm 15%",
  "video_id": "6acc27e526e9d8acf59c457e",
  "publication_id": "6acc6bd54e1a7ffa0e0672b2",
  "discount_type": "percentage",
  "discount_value": 15.0,
  "max_discount_amount": 50000,
  "min_order_amount": 100000,
  "applicable_product_ids": ["6376"],
  "start_date": ISODate("2026-10-13T00:00:00.000Z"),
  "end_date": ISODate("2026-10-27T23:59:59.000Z"),
  "total_quota": 200,
  "issued_count": 0,
  "user_limit": 1,
  "is_active": true,
  "stack_with_flash_sale": false,
  "stack_with_other_vouchers": false,
  "created_at": ISODate("2026-10-13T03:30:00.000Z"),
  "updated_at": ISODate("2026-10-13T03:30:00.000Z")
}
```

---

## 5. Ràng buộc Giá và Thanh toán

1. **Tuyệt đối không sao chép hoặc ghi đè giá sản phẩm:** Trường giá gốc `gia_ban` trong collection `san_pham` hoàn toàn không bị chỉnh sửa hay can thiệp.
2. **Không tự động phát hành voucher:** Việc cấu hình chiến dịch trong Phase V2A không tự ý thêm bản ghi vào collection `voucher` cho đến khi có tương tác xem video hợp lệ trong Phase V2B.
