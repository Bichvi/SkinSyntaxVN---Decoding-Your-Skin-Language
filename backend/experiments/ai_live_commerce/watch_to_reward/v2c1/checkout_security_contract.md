# HỢP ĐỒNG BẢO MẬT TÍCH HỢP CHECKOUT (CHECKOUT SECURITY CONTRACT) — CHUẨN BỊ CHO PHASE V2D

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Đặc tả Ranh giới & Hợp đồng Bảo mật Tích hợp Giỏ hàng & Thanh toán (V2D Security Contract)  
**Tác giả:** Senior Application Security Engineer & Lead E-commerce Architect  
**Ngày:** 13/10/2026  
**Trạng thái:** Bản đặc tả kỹ thuật bắt buộc (Mandatory Technical Contract)  

---

## 1. Tôn chỉ và Ranh giới Nghiêm ngặt (Strict Boundary)

Trong Phase V2C.1, **TUYỆT ĐỐI KHÔNG TRIỂN KHAI CHECKOUT HAY TỰ ĐỘNG GIẢM GIÁ ĐƠN HÀNG**.
Tài liệu này đóng vai trò là **Hợp đồng Kỹ thuật (Technical Contract)** làm căn cứ pháp lý và tiêu chuẩn kỹ thuật bắt buộc khi Phase V2D được phê duyệt triển khai. Bất kỳ lập trình viên hoặc phân hệ nào vi phạm hợp đồng này đều bị coi là lỗi bảo mật nghiêm trọng (Critical Vulnerability).

---

## 2. Các Điều Khoản Bắt Buộc trong Hợp Đồng Bảo Mật V2D

### Điều 1: Tính Định Danh Cá Nhân (Personal Voucher Ownership)
- Mọi mã voucher có tiền tố `SYNA-` sinh ra từ tính năng Watch-to-Reward là **Voucher Cá Nhân**, thuộc quyền sở hữu độc quyền của tài khoản khách hàng đã xem video.
- Khi người dùng nhập mã voucher tại trang Giỏ hàng (`giohang.php`) hoặc Đặt hàng (`dathang.php`):
  - Hệ thống **TUYỆT ĐỐI KHÔNG ĐƯỢC CHỈ XÁC MINH MÃ TRONG COLLECTION `voucher`**.
  - Hệ thống **BẮT BUỘC PHẢI TRUY VẤN COLLECTION `customer_vouchers`** với điều kiện kép:
    ```php
    $entitlement = $db->customer_vouchers->findOne([
        'voucher_code' => $code,
        'customer_id'  => (string)$_SESSION['user']['id'],
        'status'       => 'AVAILABLE'
    ]);
    ```
  - Nếu mã tồn tại trong `voucher` nhưng không tìm thấy bản ghi sở hữu hợp lệ của khách hàng hiện tại trong `customer_vouchers`: **TỪ CHỐI LẬP TỨC** với thông báo: *"Mã voucher này không thuộc về tài khoản của bạn."*

### Điều 2: Không Tin Cậy Dữ Liệu Từ Phía Client (Zero Trust on Client Pricing)
- Client tuyệt đối không được gửi: số tiền được giảm (`discount_amount`), giá bán sau chiết khấu, hay tỷ lệ chiết khấu.
- Toàn bộ phép tính chiết khấu **PHẢI ĐƯỢC TÍNH TOÁN LẠI 100% TRÊN SERVER-SIDE**:
  1. Kiểm tra giỏ hàng có chứa ít nhất một sản phẩm nằm trong danh sách `applicable_products` hay không.
  2. Tính tổng giá trị của các sản phẩm thỏa mãn điều kiện.
  3. Kiểm tra tổng giá trị này có đạt `min_order_amount` (giá trị đơn tối thiểu) hay không.
  4. Tính mức giảm:
     - Nếu `discount_type === 'percentage'`: $\text{Discount} = \min\left(\text{ApplicableTotal} \times \frac{\text{discount\_value}}{100}, \text{max\_discount\_amount}\right)$.
     - Nếu `discount_type === 'fixed'`: $\text{Discount} = \min(\text{discount\_value}, \text{ApplicableTotal})$.
  5. Đảm bảo số tiền giảm không vượt quá tổng giá trị đơn hàng.

### Điều 3: Chống Tái Sử Dụng (Anti-Replay / Single-Use Guarantee)
- Voucher đã có trạng thái `status === 'USED'` hoặc `so_luong_da_dung >= 1` **KHÔNG ĐƯỢC PHÉP SỬ DỤNG LẠI DƯỚI BẤT KỲ HÌNH THỨC NÀO**.
- Tại thời điểm tạo đơn hàng thành công, việc tiêu thụ voucher phải diễn ra trong cùng một transaction:
  - Cập nhật trong `customer_vouchers`: `status = 'USED'`, `used_at = now()`, `order_id = ma_hoa_don`.
  - Cập nhật trong `voucher`: `so_luong_da_dung = 1`, `trang_thai = 'used'`.

### Điều 4: Kiểm Tra Hạn Hiệu Lực Thời Gian Thực (Real-Time Expiration Check)
- Thời gian hiện tại phải nằm trong khoảng:
  $\text{start\_date} \le \text{currentTime} \le \text{end\_date}$.
- Nếu đã quá thời hạn, hệ thống tự động chuyển trạng thái sang `EXPIRED` và từ chối áp dụng.

### Điều 5: Cấm Chuyển Nhượng Giữa Các Tài Khoản (Anti-Transfer Policy)
- Mã voucher không thể chuyển nhượng, chia sẻ, hoặc áp dụng cho tài khoản người thân nếu không cùng sở hữu entitlement.
- Nếu Tài khoản A gửi mã `SYNA-XXXXXXXX` cho Tài khoản B nhập, hệ thống sẽ chặn đứng ngay tại bước kiểm tra sở hữu ở Điều 1.

### Điều 6: Chính Sách Kết Hợp Khuyến Mãi (Stacking Policy)
Khi tính toán áp dụng voucher, hệ thống phải tuân thủ nghiêm ngặt 2 cờ cấu hình trong `ai_video_reward_campaigns`:
1. **`stack_with_flash_sale` (Mặc định: `false`):**
   - Nếu `false`: Sản phẩm đang được giảm giá theo Flash Sale sẽ **KHÔNG ĐƯỢC CỘNG DỒN** voucher Syna. Giá trị sản phẩm flash sale không được tính vào `ApplicableTotal` để tính giảm giá.
   - Nếu `true`: Cho phép trừ tiếp voucher Syna trên giá đã giảm của Flash Sale.
2. **`stack_with_other_vouchers` (Mặc định: `false`):**
   - Nếu `false`: Trong một đơn hàng, khách chỉ được chọn áp dụng **HOẶC** là voucher Syna, **HOẶC** là voucher sàn thông thường (ví dụ: `WELCOME10`). Không cho nhập 2 mã cùng lúc.
   - Nếu `true`: Cho phép xếp chồng theo thứ tự ưu tiên được cấu hình.

### Điều 7: Tiêu Thụ Đồng Bộ và Hoàn Tác Khi Hủy Đơn (Atomic Consumption & Cancellation Rollback)
- Quá trình trừ voucher và tạo đơn hàng trong `hoa_don` phải nằm trong một MongoDB Multi-Document Transaction:
  - Nếu tạo hóa đơn thất bại $\rightarrow$ Voucher vẫn giữ nguyên trạng thái `AVAILABLE`.
- Nếu đơn hàng bị khách hàng hoặc admin HỦY trước khi xuất kho (`trang_thai === 'da_huy'`):
  - Hệ thống cho phép hoàn trả voucher về trạng thái `AVAILABLE` nếu voucher đó vẫn còn trong thời hạn hiệu lực.

---

## 3. Bản Đồ Kiểm Thử Chấp Thuận Trước Khi Code V2D (Definition of Done for V2D)

Trước khi ký nghiệm thu Phase V2D, bất kỳ pull request nào cũng phải vượt qua bộ test tương ứng với 7 điều khoản trên.
