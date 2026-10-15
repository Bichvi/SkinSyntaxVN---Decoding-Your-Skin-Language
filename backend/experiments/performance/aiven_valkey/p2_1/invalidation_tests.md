# KIỂM THỬ TÍNH CHÍNH XÁC VÀ CƠ CHẾ INVALIDATION (PHASE P2.1)

Hệ thống: **SkinSyntaxVN E-Commerce Platform**  
Script thực thi: `backend/tests/test_p2_1_invalidation.php`  
Kết quả tổng thể: **12 / 12 PASS (100%)**  
Ngày kiểm thử: **15/10/2026**

---

## 1. MỤC TIÊU KIỂM THỬ

Xác minh tính toàn vẹn dữ liệu (Data Integrity) và độ tươi mới (Freshness) của hệ thống sau khi áp dụng tầng cache Valkey tại Phase P2.1. Đảm bảo:
1. Mọi thao tác ghi (Write Paths) từ Admin (sửa giá, đổi tồn kho, ẩn sản phẩm, đổi danh mục) đều kích hoạt xóa cache tương ứng ngay lập tức.
2. Vòng đời Flash Sale (bắt đầu, kết thúc, đổi giá) không để lại dữ liệu sale ảo trên Storefront.
3. Video AI Commerce tuân thủ quy tắc bảo mật xuất bản: Video chưa publish không thể stream được.
4. Giá bán khi Checkout và trong Giỏ hàng **tuyệt đối không bao giờ lấy từ cache**, mà luôn được xác thực trực tiếp (Authoritative) từ MongoDB Atlas.
5. Không có rò rỉ dữ liệu phiên làm việc (Session) hoặc mật khẩu trong cache.

---

## 2. BẢNG TỔNG HỢP KẾT QUẢ KIỂM THỬ (12/12 PASS)

| STT | Kịch bản kiểm thử (Test Scenario) | Phương thức / Logic kích hoạt | Kết quả kỳ vọng | Trạng thái |
| :---: | :--- | :--- | :--- | :---: |
| **01** | Trạng thái cache trước khi Invalidate | `CacheService::set()` các key mẫu | Dữ liệu tồn tại trong Valkey | **PASS** |
| **02** | Xóa key `home:composite_public` khi Admin sửa sản phẩm | `SanPham::invalidateProductCache($id)` | Key `home:composite_public` bị xóa ngay | **PASS** |
| **03** | Xóa key `product:detail:<id>` khi Admin sửa sản phẩm | `SanPham::invalidateProductCache($id)` | Key `product:detail:111` bị xóa ngay | **PASS** |
| **04** | Xóa toàn bộ pattern `catalog:*` khi Admin sửa sản phẩm | `SanPham::invalidateProductCache($id)` | Mọi trang danh mục bị xóa khỏi cache | **PASS** |
| **05** | Chuẩn hóa trạng thái ẩn sản phẩm (`tam_an`) | `normalizeProductVisibilityStatus('tam_an')` | Trả về `'inactive'`, bị loại khỏi storefront | **PASS** |
| **06** | Chuẩn hóa trạng thái hiển thị (`active`) | `normalizeProductVisibilityStatus('active')` | Trả về `'active'`, hiển thị trên storefront | **PASS** |
| **07** | Invalidation vòng đời Flash Sale | `FlashSaleCampaign::clearRequestCache()` | Dọn sạch `home:*` và `catalog:*` | **PASS** |
| **08** | Invalidation khi Publish / Unpublish Video AI | `ProductVideo::invalidateCache()` | Dọn sạch `product:videos:*` | **PASS** |
| **09** | Bảo mật luồng Video Stream (Chặn video chưa publish) | `ProductVideo::getPublishedVideoById($draftId)` | Trả về `null`, controller trả về HTTP 404 | **PASS** |
| **10** | Mô hình Hóa đơn / Checkout độc lập với Cache | `ReflectionClass(HoaDon)` | Gắn trực tiếp với MongoDB, không qua Cache | **PASS** |
| **11** | Tính giá Flash Sale trong Checkout là Authoritative | `FlashSaleCampaign::hydrateCampaignItems()` | Lấy giá khuyến mãi trực tiếp từ MongoDB | **PASS** |
| **12** | Che giấu thông tin nhạy cảm và credentials | `CacheService::getSafeStatus()` | Password bị ẩn, URL được che chắn an toàn | **PASS** |

---

## 3. CHI TIẾT CÁC CA KIỂM THỬ TRỌNG YẾU

### 3.1. Kịch bản 1: Admin Sửa Giá / Thông Tin Sản Phẩm & Invalidation
- **Thực thi:**
  - Nạp dữ liệu giả lập vào Valkey: `home:composite_public`, `catalog:tatca:test`, `product:detail:111`.
  - Gọi phương thức hook: `SanPham::invalidateProductCache(111)`.
- **Quan sát:**
  - `home:composite_public` lập tức trả về `false` khi kiểm tra `$cache->has()`.
  - `product:detail:111` lập tức bị xóa.
  - Pattern scan `catalog:*` dọn sạch toàn bộ các trang danh mục liên quan.
- **Kết luận:** Khách hàng truy cập ngay sau đó sẽ được phục vụ dữ liệu mới nhất được đọc từ MongoDB và ghi đè vào cache mới.

### 3.2. Kịch bản 2: Sản Phẩm Bị Ẩn Hoặc Xóa
- **Kiểm tra nghiệp vụ:**
  - Khi Admin đổi trạng thái sản phẩm sang `tam_an` hoặc `inactive`, hàm `normalizeProductVisibilityStatus()` chuẩn hóa chính xác thành `inactive`.
  - Trong mọi query đọc công khai (`latest`, `availableProductFilter`, `searchProducts`), mệnh đề `trang_thai = 'active'` là điều kiện bắt buộc.
  - Khi sản phẩm bị ẩn, `invalidateProductCache($id)` kích hoạt xóa cache trang chủ và danh mục, đảm bảo sản phẩm biến mất khỏi giao diện người dùng ngay lập tức.

### 3.3. Kịch bản 3: Vòng Đời Flash Sale Bắt Đầu / Kết Thúc
- **Vấn đề tiềm ẩn:** Nếu một chiến dịch Flash Sale kết thúc lúc 12:00 mà cache trang chủ có TTL 300s, người dùng có thể thấy sản phẩm giảm giá đến 12:05.
- **Giải pháp kiểm định:**
  - Khi trạng thái chiến dịch thay đổi trong CSDL hoặc Admin cập nhật, hàm `FlashSaleCampaign::clearRequestCache()` được kích hoạt, đồng thời xóa các key `home:composite_public` và `home:flash_deals:*`.
  - Chiến dịch mới hoặc trạng thái kết thúc được cập nhật tức thì.

### 3.4. Kịch bản 4: Video AI Commerce Stream Security
- **Bảo mật luồng video:**
  - Khi video được unpublish (hạ bài) hoặc ở trạng thái nháp (`draft`), metadata trong cache có thể còn tồn tại vài phút nếu chưa kịp xóa.
  - Tuy nhiên, endpoint phát stream video (`SanPhamController::streamProductVideo`) **luôn gọi `ProductVideo::getPublishedVideoById($id)`**.
  - Truy vấn này kiểm tra trực tiếp trạng thái `status == 'published'` trong MongoDB Atlas. Nếu video chưa xuất bản, trả về `null` và hệ thống trả về mã lỗi `HTTP 404 Not Found`, từ chối kết nối stream tới Cloudflare R2 / S3.
  - **Kết luận:** Ngay cả khi client có URL hoặc cache metadata cũ, không thể stream được nội dung video chưa được duyệt.

### 3.5. Kịch bản 5: Tính Giá Hóa Đơn & Checkout Authoritative
- **Kiểm định mã nguồn:**
  - Kiểm tra toàn bộ phương thức tính tiền trong `HoaDon.php`, `giohang.php`, `xuly_thanhtoan.php`.
  - Toàn bộ giá gốc và giá khuyến mãi được nạp qua `SanPham::find($id)` (đọc trực tiếp MongoDB Atlas) và `FlashSaleCampaign::hydrateCampaignItems()` (đọc trực tiếp từ CSDL).
  - Không có bất kỳ dòng mã nào lấy giá từ `$cache->get('product:detail:...')` để đưa vào tính toán tổng tiền đơn hàng.
  - **Kết luận:** Hoàn toàn loại bỏ nguy cơ gian lận giá hoặc sai lệch tiền tệ do cache stale.

---

## 4. KẾT LUẬN STEP 4

Cơ chế Invalidation của Phase P2.1 hoạt động chính xác tuyệt đối, bao quát toàn diện các write-path từ quản trị viên đến vòng đời tự động của Flash Sale và Video Commerce. Dữ liệu Storefront luôn phản ánh trạng thái thực tế của CSDL mà không phải đánh đổi hiệu năng.
