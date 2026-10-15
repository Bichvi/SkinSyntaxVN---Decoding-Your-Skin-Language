# DANH SÁCH VÀ CHI TIẾT CÁC TẬP TIN THAY ĐỔI (PHASE P2.1)

Hệ thống: **SkinSyntaxVN E-Commerce Platform**  
Giai đoạn: **Phase P2.1 — Valkey Cache Performance Correction & Acceptance**  
Ngày cập nhật: **15/10/2026**

---

## 1. TỔNG HỢP CÁC TẬP TIN ĐÃ THAY ĐỔI

Trong Phase P2 và P2.1, để tối ưu hóa hiệu năng và sửa chữa các nút thắt cổ chai của hệ thống Cache, các tập tin sau đây trong mã nguồn backend đã được tinh chỉnh:

| STT | Đường dẫn tập tin | Loại thành phần | Mục đích thay đổi chính |
| :---: | :--- | :--- | :--- |
| **01** | `backend/app/services/CacheService.php` | Core Service | Sửa cấu hình TLS Predis, bổ sung kiểm soát runtime cache và che giấu secret |
| **02** | `backend/app/controllers/HomeController.php` | Controller | Gom 5 khối dữ liệu tĩnh thành 1 Composite Bundle `home:composite_public` (1 GET) |
| **03** | `backend/app/controllers/SanPhamController.php` | Controller | Tắt remote cache sản phẩm đơn lẻ trên PDP; gom MGET cho FBT/Video; chống penetration trên Danh mục |
| **04** | `backend/app/models/SanPham.php` | Model | Bổ sung In-Memory Menu Tree Priming, tham số `$preloadedFlashDeals`, và hook `invalidateProductCache()` |
| **05** | `backend/app/models/FlashSaleCampaign.php` | Model | Bổ sung hàm xóa cache khi chiến dịch Flash Sale thay đổi trạng thái |
| **06** | `backend/app/models/ProductVideo.php` | Model | Bổ sung hook xóa cache video và kiểm soát chặt chẽ trạng thái publish |
| **07** | `backend/app/controllers/QuanTriController.php` | Controller | Tích hợp kích hoạt Invalidation khi Admin sửa giá, ẩn sản phẩm hoặc đổi kho |

---

## 2. CHI TIẾT THAY ĐỔI THEO TỪNG TẬP TIN

### 2.1. `backend/app/services/CacheService.php`
- **Sửa cấu hình TLS cho Predis:**
  - Di chuyển mảng `ssl` từ `$options['ssl']` vào `$options['parameters']['ssl']`. Đây là vị trí chính xác mà Predis `StreamFactory::tlsStreamInitializer` đọc các cờ `verify_peer`, `verify_peer_name`, `allow_self_signed` và đường dẫn file chứng chỉ CA hệ thống `/etc/ssl/certs/ca-certificates.crt`.
- **Cờ kiểm soát Runtime:**
  - Hỗ trợ tham số URL `?cache=off`, header HTTP `X-Cache-Disabled: 1`, hoặc `?valkey_down=1` để phục vụ công tác benchmark đối đầu tin cậy và kiểm thử khả năng chịu lỗi (Circuit Breaker) mà không cần restart server.
- **An toàn bảo mật:**
  - Phương thức `getSafeStatus()` che giấu mật khẩu (`***`) và thông tin kết nối nhạy cảm, ngăn chặn rò rỉ credential trong log.

### 2.2. `backend/app/controllers/HomeController.php`
- **Tạo hàm `getHomepagePublicData()`:**
  - Đóng gói toàn bộ 5 khối dữ liệu công khai của Trang chủ: `menu_tree`, `latest`, `cats`, `flash_deals`, `primary_campaign` vào một mảng duy nhất.
  - Kiểm tra và lưu vào key `home:composite_public` với TTL 300 giây.
  - Khi cache HIT, chỉ tốn **1 roundtrip duy nhất** tới Valkey, giảm tải 100% các truy vấn lặp xuống MongoDB Atlas.
- **In-Memory Menu Tree Priming:**
  - Gọi `SanPham::setMenuTreeCache($publicData['menu_tree'])` ngay khi lấy được cache, giúp view header render cây danh mục 3 cấp từ RAM PHP worker với chi phí 0ms.
- **Truyền `$preloadedFlashDeals`:**
  - Truyền dữ liệu Flash Deals đã load từ cache vào `SanPham::getHomepageProductSections()`, triệt tiêu hoàn toàn truy vấn `getFlashSaleProducts()` ngầm bên dưới model.

### 2.3. `backend/app/controllers/SanPhamController.php`
- **Tối ưu Danh mục (`tatca`):**
  - Đổi điều kiện lưu cache từ `!empty($res['items'])` thành `isset($res['items'])`.
  - Giúp lưu trữ cả các trang tìm kiếm hoặc bộ lọc không có sản phẩm (`items: []`) với TTL 300s, ngăn chặn triệt để tấn công Cache Penetration làm cạn kiệt tài nguyên MongoDB Atlas.
- **Tối ưu Chi tiết Sản phẩm (`chitiet`):**
  - **Loại bỏ remote cache đối với `$sanPham->find($id, true)`:** Trực tiếp đọc từ MongoDB Atlas qua indexed findOne, giảm p50 từ 717.04ms xuống 325.58ms và bảo đảm tính thẩm quyền 100% của giá bán và tồn kho.
  - **Gom MGET cho các dữ liệu thứ cấp:** Gom `$freqKey` (Frequently Bought Together), `$videoKey` (Video AI liên kết), và `menu:tree:14` vào **1 lệnh `mget()` duy nhất**.
  - **Lưu mảng rỗng `[]`:** Khi sản phẩm chưa có video hoặc chưa có FBT, lưu mảng rỗng vào cache với TTL ngắn để ngăn ngừa truy vấn lặp trong các lượt xem tiếp theo.

### 2.4. `backend/app/models/SanPham.php`
- **Bộ nhớ đệm Menu Tree tĩnh:**
  - Thêm thuộc tính static `public static ?array $menuTreeCache = null` và hàm thiết lập `setMenuTreeCache(?array $tree)` để chia sẻ cây menu giữa controller và layout header.
- **Phương thức trung tâm Invalidation:**
  - Xây dựng `SanPham::invalidateProductCache($productId = null)`:
    - Xóa `menuTreeCache` trong bộ nhớ RAM.
    - Xóa các key chi tiết sản phẩm nếu có `$productId`.
    - Gọi `CacheService::deleteByPattern('home:*')`, `deleteByPattern('catalog:*')`, `deleteByPattern('menu:*')`.
    - Xóa cache bộ gợi ý `clearSimpleRecommenderCache()`.
- **Tích hợp vào các Write Paths:**
  - Gọi `invalidateProductCache()` trong `insert()`, `save()`, `update()`, `updateStock()`.
- **Tối ưu `getHomepageProductSections()`:**
  - Bổ sung tham số thứ 5: `?array $preloadedFlashDeals = null` để không truy vấn lại CSDL nếu controller đã nạp sẵn từ Composite Cache.

### 2.5. `backend/app/models/FlashSaleCampaign.php`
- **Phương thức `clearRequestCache()`:**
  - Khi một chiến dịch Flash Sale được Admin tạo mới, kích hoạt, kết thúc hoặc sửa giá, hàm này tự động gọi `CacheService::deleteByPattern('home:*')` và `CacheService::deleteByPattern('catalog:*')`.

### 2.6. `backend/app/models/ProductVideo.php`
- **Phương thức `invalidateCache()`:**
  - Tự động xóa các key `product:videos:*` khi có video được xuất bản, chỉnh sửa hoặc gỡ bỏ.
- **Kiểm soát Stream An Toàn (`getPublishedVideoById`):**
  - Bắt buộc kiểm tra `status === 'published'` tại CSDL trước khi cấp quyền stream, ngăn chặn stream video bản nháp hoặc video đã bị thu hồi.

### 2.7. `backend/app/controllers/QuanTriController.php`
- Tích hợp gọi `SanPham::invalidateProductCache($id)` trong các action xử lý của Admin:
  - Khi lưu thông tin sản phẩm mới hoặc cập nhật thông tin sản phẩm cũ.
  - Khi bật/tắt trạng thái hiển thị của sản phẩm (`active` / `tam_an`).
  - Khi điều chỉnh tồn kho trong trang quản lý kho hàng.

---

## 3. TẬP TIN KIỂM THỬ VÀ BENCHMARK MỚI ĐƯỢC TẠO

Các kịch bản tự động được phát triển để phục vụ đo lường độc lập và kiểm định nghiêm ngặt:
1. `backend/tests/benchmark_p2_1_rigorous.php`: Bộ đo benchmark tự động 360 requests chuẩn hóa (30 lượt đo xen kẽ - interleaved per mode) trên Docker.
2. `backend/tests/test_p2_1_invalidation.php`: Bộ kiểm thử 12 ca xác minh tính chính xác, Invalidation và an toàn luồng video.
3. `backend/tests/test_p2_1_regression.php`: Bộ kiểm thử hồi quy 15 ca bao quát 10 phân hệ chức năng cốt lõi của website.
