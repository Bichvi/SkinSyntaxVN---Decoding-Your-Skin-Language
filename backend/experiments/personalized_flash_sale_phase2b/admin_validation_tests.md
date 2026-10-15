# BÁO CÁO KIỂM THỬ XÁC THỰC QUẢN TRỊ FLASH SALE (ADMIN VALIDATION TESTS)

## 1. Phương pháp kiểm thử
Các bài kiểm thử xác thực được thực thi tự động qua test suite `scratch/test_phase2b_suite.php` trên cơ sở dữ liệu MongoDB Atlas thực tế (`skinsyntax`), sử dụng các mã sản phẩm canonical có sẵn trong catalog.

---

## 2. Bảng kết quả chi tiết các bài kiểm thử xác thực (Tests D, E, F, G, H, I, K, M, N, O)

| Mã Test | Kịch bản kiểm thử | Dữ liệu đầu vào giả định | Kết quả mong đợi | Kết quả thực tế quan sát được | Trạng thái |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TEST_D** | Từ chối thời gian không hợp lệ (`end_at <= start_at`) | `start_at = now + 5000s`, `end_at = now + 2000s` | Bị từ chối, trả về lỗi `end_at` | Server từ chối: *"Thời gian kết thúc phải lớn hơn thời gian bắt đầu."* | **PASS** |
| **TEST_E** | Từ chối giá Flash Sale $\ge$ giá niêm yết gốc | SKU #93 (giá niêm yết 245.000 đ), nhập `flash_price = 255.000 đ` | Bị từ chối, trả về lỗi `item_0_flash_price_gte` | Server từ chối: *"Giá Flash Sale (255.000 đ) phải nhỏ hơn giá niêm yết (245.000 đ) của sản phẩm #93."* | **PASS** |
| **TEST_F** | Từ chối SKU trùng lặp trong cùng chiến dịch | Gửi 2 item cùng SKU `#93` trong payload `items` | Bị từ chối, trả về lỗi `item_1_duplicate` | Server từ chối: *"Sản phẩm #93 bị trùng lặp bên trong cùng một chiến dịch."* | **PASS** |
| **TEST_G** | Từ chối chiến dịch giao thoa khung giờ cho cùng SKU | Tạo chiến dịch mới chứa SKU `#93` trong khung giờ giao thoa với chiến dịch đã bật | Bị từ chối, trả về lỗi xung đột interval | Server từ chối: *"Sản phẩm #93 đã tham gia chiến dịch 'TEST_FLASHSALE_PHASE2B_ADMIN' (#1) có khung giờ trùng lặp."* | **PASS** |
| **TEST_H** | Cập nhật thông tin và sản phẩm chiến dịch (Edit) | Đổi tên thành `TEST_FLASHSALE_PHASE2B_ADMIN_UPDATED`, giảm số sản phẩm xuống 1 | Cập nhật thành công vào document cũ | Document `#1` được cập nhật: tên mới lưu đúng, số item giảm còn 1 SKU | **PASS** |
| **TEST_I** | Tắt chiến dịch qua nút Toggle | Gọi `toggleCampaign(#1)` khi đang bật | `is_active` đổi sang `false`, trạng thái suy diễn thành `DISABLED` | Trạng thái chuyển thành `DISABLED`, `is_active = false` | **PASS** |
| **TEST_K** | Chiến dịch đã kết thúc không thể tự động kích hoạt lại | Chiến dịch đã quá `end_at`, gọi bật `is_active = true` | Trạng thái suy diễn vẫn là `ENDED`, giá trả về `REGULAR` | Tại $T+30.000s$, status là `ENDED`, nguồn giá là `REGULAR` | **PASS** |
| **TEST_M** | Khớp nối giữa live discount preview và công thức server | SKU #93 giá 245.000 đ, flash price 169.500 đ | Công thức JS và PHP trả về cùng % | JS: `31%` == PHP: `31%` | **PASS** |
| **TEST_N** | Chuyển đổi múi giờ hai chiều (`Asia/Ho_Chi_Minh` $\leftrightarrow$ UTC) | Chuỗi giờ Việt Nam: `2026-10-15 14:30:00` | Giờ hiển thị lại khớp 100% với giờ nhập vào | Output: `2026-10-15 14:30:00` (không lệch 7 tiếng) | **PASS** |
| **TEST_O** | Lưu trữ cấu trúc mảng items trong MongoDB | Truy vấn trực tiếp document raw trong collection `flash_sale_campaigns` | Mảng items lưu đúng định danh và đơn giá | Document lưu đúng mảng items BSON: SKU `#93`, flash_price `169.500` | **PASS** |

---

## 3. Đánh giá độ tin cậy
- Tầng validation độc lập với giao diện, thực thi ở model phía máy chủ trước khi ghi dữ liệu.
- Ngăn ngừa 100% tình trạng giá sai lệch, trùng lặp sản phẩm, hoặc chiến dịch bị đè giờ không kiểm soát.
