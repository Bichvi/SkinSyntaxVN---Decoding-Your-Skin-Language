# KIỂM TOÁN DỮ LIỆU CHIẾN DỊCH VOUCHER (CAMPAIGN DATA AUDIT) — PHASE V2B.1

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Collection kiểm toán:** `ai_video_reward_campaigns`  
**Database:** MongoDB Atlas / Local Replica  
**Kỹ sư thực hiện:** Senior PHP/MongoDB Security Engineer  
**Ngày thực hiện:** 13/10/2026  

---

## 1. Kết quả Rà soát Thực tế trong Collection `ai_video_reward_campaigns`

Tổng số bản ghi chiến dịch hiện có trong collection: **2 bản ghi**.

Cả 2 bản ghi đều mang tên:  
`Ưu đãi Syna 3D - Giảm 15% khi xem video`

### Chi tiết từng bản ghi:

#### Bản ghi #1:
- **MongoDB `_id`:** `6acda46b08261c84ba03c352`
- **Mã chiến dịch (`campaign_id`):** `rew_141ad8d65d`
- **Tên chiến dịch:** `Ưu đãi Syna 3D - Giảm 15% khi xem video`
- **Mã Video (`video_id`):** `6acc27e526e9d8acf59c457e`
- **Mã Xuất bản (`publication_id`):** `6acc6bd54e1a7ffa0e0672b2`
- **Sản phẩm liên kết:** `["6376"]` (Combo Nước Tẩy Trang L'Oreal)
- **Trạng thái kích hoạt (`is_active`):** **`FALSE`** (Đang tắt hoạt động)
- **Hình thức giảm giá:** Giảm `15%`, tối đa `50.000 VNĐ`, đơn tối thiểu `100.000 VNĐ`
- **Tổng hạn ngạch (`total_quota`):** `200`
- **Số lượng đã phát hành (`issued_count`):** **`0`** (Chưa phát hành bất kỳ voucher nào)
- **Giới hạn mỗi khách hàng (`user_limit`):** `1`
- **Thời gian áp dụng:**
  - Bắt đầu: `2026-10-13 02:24:27 UTC`
  - Kết thúc: `2026-11-12 03:24:27 UTC`
- **Thời gian tạo (`created_at`):** `2026-10-13 03:24:27 UTC`
- **Thời gian cập nhật (`updated_at`):** `2026-10-13 03:56:15 UTC`

#### Bản ghi #2:
- **MongoDB `_id`:** `6acdac17352c78c6c0066dd2`
- **Mã chiến dịch (`campaign_id`):** `rew_7e6ada3dc3`
- **Tên chiến dịch:** `Ưu đãi Syna 3D - Giảm 15% khi xem video`
- **Mã Video (`video_id`):** `6acc27e526e9d8acf59c457e`
- **Mã Xuất bản (`publication_id`):** `6acc6bd54e1a7ffa0e0672b2`
- **Sản phẩm liên kết:** `["6376"]` (Combo Nước Tẩy Trang L'Oreal)
- **Trạng thái kích hoạt (`is_active`):** **`TRUE`** (Đang hoạt động)
- **Hình thức giảm giá:** Giảm `15%`, tối đa `50.000 VNĐ`, đơn tối thiểu `100.000 VNĐ`
- **Tổng hạn ngạch (`total_quota`):** `200`
- **Số lượng đã phát hành (`issued_count`):** **`0`** (Chưa phát hành bất kỳ voucher nào)
- **Giới hạn mỗi khách hàng (`user_limit`):** `1`
- **Thời gian áp dụng:**
  - Bắt đầu: `2026-10-13 02:57:11 UTC`
  - Kết thúc: `2026-11-12 03:57:11 UTC`
- **Thời gian tạo (`created_at`):** `2026-10-13 03:57:11 UTC`
- **Thời gian cập nhật (`updated_at`):** `2026-10-13 04:06:56 UTC`

---

## 2. Đánh giá Bản chất Dữ liệu (Nature of Data)

1. **Có phải dữ liệu test/demo không?**
   - **CÓ.** Cả 2 bản ghi đều được sinh ra tự động trong quá trình chạy thử nghiệm và kiểm thử trình duyệt PDP của Phase V2B.
   - Cả hai chiến dịch đều có `issued_count = 0`, hoàn toàn chưa phát sinh dữ liệu giao dịch hay ràng buộc tài chính nào với khách hàng thật.
2. **Có trùng lặp chiến dịch không?**
   - **CÓ.** Cả 2 chiến dịch cùng cấu hình mức giảm 15%, cùng hạn ngạch 200, cùng trỏ vào video `6acc27e526e9d8acf59c457e` trên sản phẩm `6376`.
   - Nhờ ràng buộc Unique Active Campaign của model `VideoRewardCampaign`:
     - Bản ghi #1 (`rew_141ad8d65d`) đang ở trạng thái `is_active: false`.
     - Bản ghi #2 (`rew_7e6ada3dc3`) đang ở trạng thái `is_active: true`.
     - Do đó, tại một thời điểm chỉ có duy nhất 1 chiến dịch hoạt động trên trang chi tiết sản phẩm.

---

## 3. Đề xuất Xử lý (Recommendations for Keep / Deactivate / Delete)

Tuân thủ nguyên tắc nghiêm ngặt: **"Chỉ báo cáo và đề xuất giữ/tắt/xóa. KHÔNG tự xóa hoặc tắt chiến dịch nếu chưa được phê duyệt."**

Dưới đây là phương án đề xuất cho Quản trị viên/Chủ dự án:

| Bản ghi | Tình trạng hiện tại | Đề xuất hành động | Lý do |
| :--- | :--- | :--- | :--- |
| **Bản ghi #1 (`rew_141ad8d65d`)** | `is_active: false`, `issued_count: 0` | **ĐỀ XUẤT XÓA (DELETE)** | Là bản ghi thử nghiệm dư thừa sinh ra trước đó, hiện đã tắt hoạt động và chưa từng phát hành voucher. Xóa bỏ giúp dữ liệu sạch sẽ, tránh gây nhầm lẫn khi tra cứu báo cáo. |
| **Bản ghi #2 (`rew_7e6ada3dc3`)** | `is_active: true`, `issued_count: 0` | **ĐỀ XUẤT GIỮ LẠI (KEEP)** hoặc **ĐỔI TÊN CHÍNH THỨC** | Đang làm chiến dịch mẫu phục vụ demo hiển thị giao diện Watch-to-Reward trên trang chi tiết sản phẩm `6376`. Nếu ban dự án muốn chạy chiến dịch thật trong Phase V2C, có thể giữ lại bản ghi này để cấu hình chính thức, hoặc tắt đi (`is_active: false`) khi chưa muốn hiển thị cho người dùng phổ thông. |

---

## 4. Cam kết Thực hiện

Không có bất kỳ thao tác xóa hoặc tắt chiến dịch nào được tự ý thực thi trong bước này. Toàn bộ dữ liệu được giữ nguyên trạng thái chờ phê duyệt.
