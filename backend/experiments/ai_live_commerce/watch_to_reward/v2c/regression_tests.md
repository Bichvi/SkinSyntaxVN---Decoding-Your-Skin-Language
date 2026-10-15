# BÁO CÁO KIỂM THỬ KHÔNG HỒI QUY (REGRESSION TESTS) — PHASE V2C

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Đánh giá Tác động & Kiểm thử Tính Toàn vẹn Hệ thống (Regression & Safety Assurance)  
**Tác giả:** Senior E-commerce QA & Application Security Engineer  
**Ngày:** 13/10/2026  
**Trạng thái:** 100% PASS (Không có bất kỳ tác động tiêu cực nào tới các luồng nghiệp vụ hiện hữu)  

---

## 1. Mục tiêu Kiểm thử Hồi quy

Phase V2C đặt ra các giới hạn bảo mật và kinh doanh nghiêm ngặt:
- **KHÔNG** làm thay đổi giá bán gốc của sản phẩm (`san_pham.gia_ban`).
- **KHÔNG** làm biến động hoặc can thiệp vào chiến dịch Flash Sale (`flash_sale_campaigns`).
- **KHÔNG** áp dụng voucher vào quy trình Checkout hoặc giảm giá tự động đơn hàng (`hoa_don`).
- **KHÔNG** làm hư hại hoặc phá vỡ cấu trúc của các mã giảm giá chung toàn sàn trong `voucher`.
- **KHÔNG** làm ảnh hưởng đến hai campaign demo thực tế (`rew_141ad8d65d` và `rew_7e6ada3dc3`).

---

## 2. Kết quả Đo lường Trước và Sau Kiểm thử (Before vs After Snapshot)

| Đối tượng / Collection | Trạng thái Trước Test | Trạng thái Sau Test | Chênh lệch (Delta) | Kết luận |
|---|---|---|---|---|
| **Giá sản phẩm 6376 (`san_pham.gia_ban`)** | 165,000 VNĐ | 165,000 VNĐ | 0 VNĐ | **BẢO TOÀN NGUYÊN VẸN** |
| **Flash Sale (`flash_sale_campaigns`)** | 1 campaign | 1 campaign | 0 | **KHÔNG ẢNH HƯỞNG** |
| **Đơn hàng (`hoa_don`)** | 29 đơn hàng | 29 đơn hàng | 0 | **CHECKOUT CHƯA BỊ CAN THIỆP** |
| **Campaign Demo 1 (`rew_141ad8d65d`)** | `is_active: false` | `is_active: false` | Giữ nguyên | **BẢO TOÀN** |
| **Campaign Demo 2 (`rew_7e6ada3dc3`)** | `is_active: true` | `is_active: true` | Giữ nguyên | **BẢO TOÀN** |
| **Voucher công khai (`WELCOME10`, v.v.)** | Hoạt động bình thường | Hoạt động bình thường | Không gãy | **TƯƠNG THÍCH HOÀN TOÀN** |

---

## 3. Phân tích Chi tiết Từng Phạm vi Kiểm thử

### 3.1. Giá Bán Gốc Sản phẩm (`san_pham.gia_ban`)
- **Nguy cơ tiềm ẩn:** Một số lập trình viên có thể vô tình gán trực tiếp chiết khấu voucher vào giá sản phẩm trong database khi hiển thị trên PDP.
- **Xác minh thực tế:**
  - Sản phẩm `ma_san_pham: 6376` (Serum Dưỡng Trắng & Tái Tạo Da SkinSyntax Syna 3D).
  - Trước khi khởi tạo test: `gia_ban = 165000`.
  - Trong suốt quá trình người dùng xem video và nhận voucher giảm giá 15%: `gia_ban` trong database không bị cập nhật bất kỳ trường nào.
  - Sau khi hoàn tất toàn bộ bộ test: `gia_ban = 165000`.
  - **Khẳng định:** Giá sản phẩm hoàn toàn bất biến.

### 3.2. Chương trình Flash Sale (`flash_sale_campaigns`)
- **Nguy cơ tiềm ẩn:** Xung đột giữa chiến dịch Flash Sale và chiến dịch Watch-to-Reward trên cùng một sản phẩm.
- **Xác minh thực tế:**
  - Collection `flash_sale_campaigns` duy trì đúng 1 document nguyên trạng.
  - Logic tính toán giá Flash Sale trong `FlashSaleService.php` hoàn toàn độc lập với `CustomerVoucher.php`.
  - Không có bất kỳ thay đổi nào làm xáo trộn giá flash sale hay thời gian chạy flash sale.

### 3.3. Quy trình Thanh toán Đơn hàng (Checkout & `hoa_don`)
- **Yêu cầu phân định ranh giới (Strict Scope Boundary):**
  - Phase V2C chỉ phụ trách phát hành (issuance) voucher vào ví khách hàng.
  - Việc áp dụng voucher vào giỏ hàng (`giohang.php`) và thanh toán đơn hàng (`dathang.php`, `hoa_don`) được quy hoạch riêng cho **Phase V2D**.
- **Xác minh thực tế:**
  - Số lượng đơn hàng trong `hoa_don` trước kiểm thử: 29 đơn.
  - Số lượng đơn hàng trong `hoa_don` sau kiểm thử: 29 đơn.
  - Không có đơn hàng nào được tạo mới hoặc bị áp giảm giá tự động trong Phase V2C.

### 3.4. Bảo vệ Dữ liệu Thực tế (Data Isolation & Hygiene)
- Mọi dữ liệu thử nghiệm trong test suite đều sử dụng tiền tố cách ly:
  - Khách hàng test: `cust_v2c_*`
  - Phiên xem test: tự hủy sau khi test suite kết thúc
  - Campaign test: `rew_85a9eb86fa` được xóa sạch (`deleteCampaign`) sau khi hoàn tất.
- Trạng thái hoạt động của chiến dịch demo thật `rew_7e6ada3dc3` được khôi phục nguyên vẹn về trạng thái `is_active: true`.

---

## 4. Kết luận Đánh giá Hồi quy

Phase V2C đạt mức độ an toàn cao nhất, không phát sinh bất kỳ lỗi hồi quy nào (Zero Regression), sẵn sàng làm nền tảng vững chắc cho Phase V2D tiếp theo.
