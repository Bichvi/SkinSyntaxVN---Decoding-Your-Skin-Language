# BÁO CÁO KIỂM THỬ ĐỘNG CƠ PHÂN GIẢI GIÁ (EFFECTIVE PRICING TESTS)

## 1. Phương pháp kiểm thử
Động cơ phân giải giá `FlashSaleCampaign::resolveEffectivePrice()` được kiểm tra thông qua kỹ thuật Time Traveling (mô phỏng tiến trình thời gian $T_0 \to T_{\text{start}} \to T_{\text{mid}} \to T_{\text{end}} \to T_{+\infty}$) và kiểm tra tính bất biến giữa nhiều người dùng khác nhau.

---

## 2. Kết quả kiểm thử vòng đời giá (Lifecycle Price Matrix: Tests A - E, L, M, O)

| Mã Test | Mô tả kịch bản | Thời điểm & Trạng thái chiến dịch | Đơn giá thực tế phân giải | Nguồn giá (`pricing_source`) | Kết quả quan sát được | Trạng thái |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **TEST_A** | Không có chiến dịch nào | Không có record trong `flash_sale_campaigns` | `245.000 đ` | `REGULAR` | Trả về đúng giá niêm yết của SKU #93 | **PASS** |
| **TEST_B** | Chiến dịch sắp diễn ra (`UPCOMING`) | `now < start_at` ($now = T_0$, bắt đầu lúc $T_0 + 1h$) | `245.000 đ` | `REGULAR` | Status: `UPCOMING`. Giá vẫn giữ nguyên `REGULAR`, chưa áp dụng Flash Sale | **PASS** |
| **TEST_C** | Chiến dịch đang diễn ra (`ACTIVE`) | `start_at <= now < end_at` ($T_{\text{start}} - 30m$) | `285.600 đ` (so với gốc 357.000 đ) | `FLASH_SALE` | Status: `ACTIVE`. Áp dụng đơn giá Flash Sale, chiết khấu tính toán đúng `20%` | **PASS** |
| **TEST_D** | Chiến dịch đã kết thúc (`ENDED`) | `now >= end_at` (mô phỏng thời gian $T + 1h$) | `357.000 đ` | `REGULAR` | Status: `ENDED`. Tự động quay về giá niêm yết mà không cần can thiệp DB | **PASS** |
| **TEST_E** | Chiến dịch bị tắt (`DISABLED`) | `is_active = false` (dù thời gian nằm trong khung giờ) | `245.000 đ` | `REGULAR` | Status: `DISABLED`. Giá lập tức quay về `REGULAR` | **PASS** |
| **TEST_L** | Chiến dịch áp dụng cho SKU khác | Chiến dịch ACTIVE cho SKU #1; kiểm tra SKU #93 | `245.000 đ` | `REGULAR` | SKU #93 giữ nguyên giá thường, không bị ảnh hưởng chéo | **PASS** |
| **TEST_M** | Tính bất biến đối với mọi người dùng | 2 người dùng (User A và User B) cùng xem SKU #1 trong chiến dịch ACTIVE | User 1: `285.600 đ`<br/>User 2: `285.600 đ` | `FLASH_SALE` | Khẳng định $User_1 \equiv User_2$. Tuyệt đối **không cá nhân hóa giá bán** | **PASS** |
| **TEST_O** | Chiến dịch hết hạn trước khi chốt đơn | Chiến dịch ACTIVE lúc xem cart ($285.600 đ$), hết hạn lúc submit ($357.000 đ$) | `357.000 đ` | `REGULAR` | Server tính toán lại đơn giá mới nhất, hủy bỏ giá Flash Sale đã hết hạn | **PASS** |

---

## 3. Khẳng định tính bất biến về giá
- Toàn bộ kết quả kiểm thử khẳng định: **Một sản phẩm trong một chiến dịch Flash Sale cụ thể chỉ có một mức giá hiệu lực duy nhất tại một thời điểm**.
- Không có bất kỳ sự phân biệt đối xử về giá dựa trên lịch sử mua hàng, loại tài khoản hay thiết bị người dùng.
- Công thức tính phần trăm giảm giá luôn được nội suy từ giá niêm yết và giá Flash Sale tại thời điểm thực thi.
