# KIỂM THỬ TRÌNH DUYỆT VÀ TRẢI NGHIỆM NGƯỜI DÙNG (BROWSER & UX TESTS) — PHASE V2C

**Hệ thống:** SkinSyntaxVN — AI Live Commerce Watch-to-Reward  
**Phân hệ:** Trải nghiệm Người dùng trên Trang Chi tiết Sản phẩm & Ví Voucher  
**Tác giả:** Senior Frontend & Application Security Engineer  
**Ngày:** 13/10/2026  
**Trang kiểm thử:**  
- Product Detail Page (PDP): `http://localhost/index.php?r=chitiet&id=6376`  
- Customer Wallet Page: `http://localhost/index.php?r=hoso#vouchers`  

---

## 1. Mục tiêu Kiểm thử UX

Xác nhận toàn bộ quy trình tương tác thực tế của người dùng:
1. Giao diện nhận voucher xuất hiện đúng lúc khi video đạt điều kiện xem (coverage $\ge 95\%$ và đến gần cuối video).
2. Xử lý trạng thái đăng nhập: Khách chưa đăng nhập được nhắc nhở chuyển hướng an toàn tới trang đăng nhập.
3. Anti-double-click guard: Nút nhận voucher bị vô hiệu hóa tức thì khi người dùng click, ngăn chặn việc gửi request trùng lặp.
4. Hiển thị thông báo kết quả: Banner chúc mừng kèm mã voucher, nút sao chép (copy) và liên kết mở Ví Voucher.
5. Hiển thị Ví Voucher cá nhân trong trang Hồ sơ người dùng với trạng thái `AVAILABLE`, thông tin chiết khấu chi tiết và bộ lọc.

---

## 2. Kiểm thử Chi tiết trên Trang Chi tiết Sản phẩm (PDP)

### 2.1. Trạng thái Chưa Đủ Điều Kiện Xem
- **Hành vi:** Người dùng mới bắt đầu phát video Syna.
- **Giao diện:** Khu vực `#synaRewardActionArea` hiển thị thanh tiến độ xem:
  - Thanh progress bar cập nhật phần trăm trực tiếp theo heartbeat.
  - Thông báo: *"Xem đủ 95% video để mở khóa voucher ưu đãi 15%"*.
  - Nút nhận voucher chưa xuất hiện.

### 2.2. Trạng thái Đạt Điều Kiện Xem (Watch Eligible)
- **Hành vi:** Tiến độ xem đạt $\ge 95\%$ và người xem đã xem tới đoạn kết thúc.
- **Giao diện:**
  - Thanh tiến độ chuyển sang màu xanh lá mạ với icon tích xanh `bi-check-circle-fill`.
  - Nút **"Nhận Voucher Ngay"** (`#btnClaimReward`) xuất hiện nổi bật với hiệu ứng viền phát sáng (pulse animation).
  - Badge chúc mừng: *"Bạn đã hoàn thành xem video! Hãy nhận ưu đãi ngay."*

### 2.3. Khách Vãng Lai (Guest) Nhấn "Nhận Voucher"
- **Hành vi:** Khách chưa đăng nhập nhấn nút `#btnClaimReward`.
- **Phản hồi:**
  - Hệ thống hiển thị hộp thông báo trang nhã yêu cầu đăng nhập.
  - Nút CTA chuyển hướng: **"Đăng nhập để nhận thưởng"** trỏ tới `index.php?r=dangnhap&redirect=...`.
  - Session xem video (`guest_session_id`) được bảo lưu nguyên vẹn để sau khi đăng nhập xong, khách hàng có thể quay lại nhận voucher ngay lập tức mà không phải xem lại video từ đầu.

### 2.4. Khách Hàng Đã Đăng Nhập Nhấn "Nhận Voucher"
- **Hành vi:** Khách hàng đăng nhập nhấn nút `#btnClaimReward`.
- **Cơ chế chống Double-Click:**
  - Nút lập tức chuyển sang trạng thái loading: Text đổi thành *"Đang xử lý cấp voucher..."*, icon spinner quay và thuộc tính `disabled` được kích hoạt. Biến `isClaiming = true` chặn hoàn toàn các sự kiện click tiếp theo.
- **Kết quả Thành công:**
  - Hộp thông báo chúc mừng xuất hiện với nền gradient nhẹ:
    - Hiển thị mã voucher nổi bật: `SYNA-776059FB`.
    - Nút **"Sao chép mã"** (`#btnCopyVoucher`): Click sao chép mã vào clipboard của hệ điều hành, hiển thị toast *"Đã sao chép mã!"*.
    - Nút **"Xem trong Ví Voucher"**: Mở trang `index.php?r=hoso#vouchers`.
    - Trạng thái nút chuyển vĩnh viễn sang **"Đã nhận voucher"** (`disabled`).

### 2.5. Trạng thái Quota Hết hoặc Chiến dịch Hết hạn
- Nếu backend trả về `QUOTA_EXHAUSTED` hoặc `CAMPAIGN_EXPIRED`:
  - Giao diện hiển thị badge cảnh báo màu vàng cam (`alert-warning`).
  - Nút bị vô hiệu hóa kèm thông báo rõ ràng lý do: *"Rất tiếc, số lượng voucher ưu đãi cho video này đã được nhận hết."*

---

## 3. Kiểm thử Giao diện Ví Voucher Cá Nhân (`hoso.php`)

### 3.1. Truy cập Tab Ví Voucher
- Người dùng truy cập trang `index.php?r=hoso`.
- Menu bên trái hiển thị mục **Ví Voucher Của Tôi** với huy hiệu đếm số lượng voucher (ví dụ: `1`).
- Khi click hoặc truy cập qua hash URL `#vouchers`, tab nội dung ví voucher tự động kích hoạt.

### 3.2. Hiển thị Thẻ Voucher
- Thẻ voucher được hiển thị dạng lưới (grid):
  - Khung viền hồng nhạt phong cách mỹ phẩm SkinSyntax.
  - Huy hiệu xanh: `KHẢ DỤNG`.
  - Mức giảm: `Giảm 15%`.
  - Mã: `SYNA-776059FB` in đậm.
  - Điều kiện: Đơn tối thiểu 100.000đ, Giảm tối đa 50.000đ.
  - Thời hạn: Từ ngày cấp đến +30 ngày.
  - Nút tương tác:
    - **Sao chép mã**: Lưu mã vào bộ nhớ tạm.
    - **Xem sản phẩm**: Liên kết trực tiếp tới sản phẩm 6376 để mua hàng.

---

## 4. Bảng Kết quả Đánh giá Giao diện (UX Checklist)

| STT | Tiêu chí Kiểm thử | Kết quả | Ghi chú |
|---|---|---|---|
| 1 | Nút nhận voucher chỉ hiển thị khi đủ điều kiện xem | **PASS** | Ẩn khi chưa đủ, hiện khi $\ge 95\%$ |
| 2 | Chặn double-click trên trình duyệt | **PASS** | Disable button + cờ `isClaiming` |
| 3 | Chuyển hướng đăng nhập cho Guest an toàn | **PASS** | Lưu giữ phiên xem cho tài khoản sau login |
| 4 | Hiển thị mã và nút sao chép mã sau khi nhận | **PASS** | Clipboard API hoạt động mượt mà |
| 5 | Giao diện Ví Voucher hiển thị đúng thông tin | **PASS** | Đầy đủ mã, hạn dùng, điều kiện, trạng thái |
| 6 | Responsive trên Mobile và Desktop | **PASS** | Tương thích chuẩn Bootstrap 5 |
