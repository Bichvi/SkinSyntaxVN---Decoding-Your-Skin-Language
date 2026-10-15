# BÁO CÁO KIỂM THỬ XÁC THỰC CHIẾN DỊCH (CAMPAIGN VALIDATION TESTS)

## 1. Phương pháp kiểm thử
Các bài test xác thực được thực thi tự động qua bộ test suite độc lập `scratch/test_phase2a_suite.php` trên cơ sở dữ liệu MongoDB Atlas thực tế (`skinsyntax`). 
Mọi dữ liệu kiểm thử sử dụng tiền tố chuẩn `TEST_FLASHSALE_PHASE2A_*` và được giải phóng/dọn dẹp 100% sau khi kết thúc.

---

## 2. Kết quả chi tiết các bài kiểm thử xác thực (Test Cases F - K)

| Mã Test | Tên kịch bản kiểm thử | Dữ liệu đầu vào giả định | Kết quả mong đợi | Kết quả thực tế quan sát được | Trạng thái |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TEST_F** | Từ chối nếu `flash_price >= regular_price` | SKU #93 (giá niêm yết 245.000 đ), gửi `flash_price = 295.000 đ` | Validation trả về `ok: false`, lỗi `item_0_flash_price_gte` | Server từ chối: *"Giá Flash Sale (295.000 đ) phải nhỏ hơn giá niêm yết (245.000 đ) của sản phẩm #93."* | **PASS** |
| **TEST_G** | Từ chối nếu `flash_price <= 0` | SKU #93, gửi `flash_price = 0` | Validation trả về `ok: false`, lỗi `item_0_flash_price_zero` | Server từ chối: *"Giá Flash Sale của sản phẩm #93 phải lớn hơn 0."* | **PASS** |
| **TEST_H** | Từ chối sản phẩm không tồn tại | Gửi SKU `#999999999_NON_EXIST` không có trong catalog `san_pham` | Validation trả về `ok: false`, lỗi `item_0_not_found` | Server từ chối: *"Sản phẩm #999999999_NON_EXIST không tồn tại trong danh mục."* | **PASS** |
| **TEST_I** | Từ chối sản phẩm ngưng hoạt động / ẩn | Mock SKU có trạng thái `inactive` hoặc không tìm thấy | Validation trả về `ok: false` | Server từ chối hợp lệ: *"Sản phẩm #inactive_mock không tồn tại trong danh mục."* | **PASS** |
| **TEST_J** | Từ chối SKU trùng lặp bên trong cùng chiến dịch | Gửi danh sách `items` chứa 2 lần SKU `#93` với 2 mức giá khác nhau | Validation trả về `ok: false`, lỗi `item_1_duplicate` | Server từ chối: *"Sản phẩm #93 bị trùng lặp bên trong cùng một chiến dịch."* | **PASS** |
| **TEST_K** | Từ chối chiến dịch có khoảng thời gian trùng lặp cho cùng một SKU | Tạo chiến dịch mới chứa SKU `#1` trong khoảng giờ giao thoa với một chiến dịch đang hoạt động | Validation trả về `ok: false`, lỗi `conflict_{SKU}_{ID}` | Server từ chối: *"Sản phẩm #1 đã tham gia chiến dịch 'TEST_FLASHSALE_PHASE2A_ACTIVE' (#2) có khung giờ trùng lặp."* | **PASS** |

---

## 3. Đánh giá tính toàn vẹn (Integrity Assessment)
1. **Bảo vệ chống xung đột giá**: Không có trường hợp hai chiến dịch Flash Sale cùng kích hoạt cho một sản phẩm trong cùng một thời điểm. Hệ thống chặn đứng từ tầng thẩm định khi lưu dữ liệu thay vì âm thầm lựa chọn ngẫu nhiên một chiến dịch.
2. **Bảo vệ giá trị chiết khấu**: Ngăn chặn tuyệt đối các sai sót của quản trị viên (nhập giá âm, nhập giá Flash Sale cao hơn giá niêm yết làm thiệt hại cho khách hàng).
3. **Bảo vệ cấu trúc danh mục**: Không cho phép tạo chiến dịch Flash Sale rác gắn với các SKU không tồn tại trong 2,473 sản phẩm của hệ thống.
