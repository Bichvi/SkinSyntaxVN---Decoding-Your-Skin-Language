# SKINSYNTAXVN — PHASE P2.2: AUDIT BẢO MẬT & TÍNH NHẤT QUÁN BỘ NHỚ ĐỆM (CACHE CONSISTENCY AUDIT)

**Kỹ sư thực hiện:** Senior PHP / MongoDB / Valkey Performance & Security Engineer  
**Thời gian thực hiện:** 18/10/2026  
**Môi trường:** Docker (Nginx 1.27, PHP 8.2 FPM), Aiven Valkey (TLS rediss://), MongoDB Atlas M0 Cluster  
**Phạm vi:** Kiểm toán toàn diện vòng đời Cache, Invalidation Paths và an toàn Flash Sale  

---

## 1. MỤC TIÊU & PHẠM VI KIỂM TOÁN

Trong Phase P2.1, hệ thống đã triển khai thành công mô hình L2 Cache-Aside gom cụm (`home:composite_public`), giúp giảm độ trễ p50 của Homepage từ 2503ms xuống 757ms. Tuy nhiên, tính nhất quán dữ liệu (Cache Consistency) và tính an toàn của chiến dịch Flash Sale (Flash Sale Safety) cần phải được chứng minh nghiêm ngặt:
1. Không được để lộ giá khuyến mãi hoặc trạng thái ACTIVE sau khi chiến dịch kết thúc.
2. Không được hiển thị thiếu chiến dịch khi thời điểm bắt đầu tự động diễn ra.
3. Không để rò rỉ dữ liệu cá nhân hóa (Personalization) vào bộ nhớ đệm dùng chung (Public Composite Cache).
4. Mọi thao tác chỉnh sửa danh mục, sản phẩm, kho hàng, video và Flash Sale từ Admin phải lập tức xóa cache liên quan.
5. Khi kết nối tới Aiven Valkey gặp sự cố, hệ thống phải fallback thông suốt về MongoDB Atlas mà không gây gián đoạn thanh toán.

---

## 2. KẾT QUẢ KIỂM TOÁN THỰC TẾ STEP 1 — FLASH SALE INVALIDATION

### Câu hỏi 1: `FlashSaleCampaign::clearRequestCache()` có xóa cache trong Valkey hay chỉ cache PHP?
- **Khảo sát mã nguồn gốc:**
  ```php
  public static function clearRequestCache(): void {
      self::$requestActiveCampaignsCache = null;
      if (class_exists('SanPham') && method_exists('SanPham', 'invalidateProductCache')) {
          SanPham::invalidateProductCache();
      } elseif (class_exists('CacheService')) {
          CacheService::getInstance()->deleteByPattern('home:*');
          CacheService::getInstance()->deleteByPattern('catalog:*');
      }
  }
  ```
- **Phát hiện khiếm khuyết:**
  - Hệ thống SkinSyntaxVN sử dụng cơ chế include thủ công (không có PSR-4 autoloader tự động nạp models/services khi gọi tĩnh).
  - Nếu `FlashSaleCampaign::clearRequestCache()` được kích hoạt trong một script biệt lập, cron job, worker hoặc API mà file `SanPham.php` và `CacheService.php` chưa được `require_once` từ trước, cả `class_exists('SanPham')` và `class_exists('CacheService')` đều trả về `false`.
  - Hậu quả: Hàm chỉ gán `self::$requestActiveCampaignsCache = null;` trong bộ nhớ RAM của PHP request hiện tại, hoàn toàn **KHÔNG xóa khóa nào trên Aiven Valkey**!
- **Giải pháp khắc phục:**
  - Bổ sung nạp tệp phòng hộ an toàn: Nếu `!class_exists('SanPham') && is_file(__DIR__ . '/SanPham.php')`, nạp ngay `SanPham.php`.
  - Nếu `SanPham` không có sẵn, nạp trực tiếp `CacheService.php` và gọi xóa các pattern `home:*`, `catalog:*`, `menu:*`. Đảm bảo Valkey LUÔN LUÔN được invalidate bất kể call-site nào kích hoạt.

### Câu hỏi 2: `home:composite_public` có chứa Flash Sale campaign, sản phẩm, giá hoặc trạng thái đang diễn ra không?
- **Kiểm tra payload:**
  Khóa `home:composite_public` trong `HomeController::getHomepagePublicData()` lưu trữ mảng tổng hợp:
  - `menu_tree`: Cây danh mục 2 cấp.
  - `latest`: 12 sản phẩm mới nhất.
  - `cats`: 6 danh mục nổi bật.
  - `flash_deals`: 8 sản phẩm giảm giá sâu.
  - `primary_campaign`: Kết quả từ `$flashSaleModel->getPrimaryActiveCampaign()`.
- **Kết luận:** **CÓ.** Khóa này chứa đầy đủ thông tin chiến dịch Flash Sale đang diễn ra (`ma_campaign`, `ten_campaign`, `start_at`, `end_at`, trạng thái `ACTIVE`), danh sách sản phẩm tham gia kèm giá gốc (`regular_price`), giá flash (`flash_price`), phần trăm giảm (`phan_tram_giam`) và thông tin chi tiết danh mục đã được hydrate từ MongoDB Atlas.

### Câu hỏi 3: Admin tạo, sửa, bật, tắt hoặc xóa Flash Sale có invalidate composite cache không?
- **Kiểm tra luồng Admin (`QuanTriController.php`):**
  - Tạo / Cập nhật: `adminFlashSaleSave` -> `$this->flashSaleModel->saveCampaign($payload, $id)` -> gọi `self::clearRequestCache()`.
  - Bật / Tắt: `adminFlashSaleToggle` -> `$this->flashSaleModel->toggleCampaign($id)` -> gọi `self::clearRequestCache()`.
  - Xóa: `adminFlashSaleDelete` -> `$this->flashSaleModel->deleteCampaign($id)` -> gọi `self::clearRequestCache()`.
- **Kết luận:** Khi `SanPham` đã được nạp (trong `QuanTriController.php` dòng 4 đã nạp `SanPham.php`), `SanPham::invalidateProductCache()` được kích hoạt, xóa triệt để `home:*` (bao gồm `home:composite_public`). Với bản vá bổ sung phòng hộ tại Câu 1, tính nhất quán được bảo đảm 100% ở mọi ngữ cảnh thực thi.

### Câu hỏi 4: Khi campaign tự bắt đầu hoặc kết thúc theo thời gian, cache có tự hết hiệu lực đúng thời điểm không?
- **Phát hiện khiếm khuyết trọng yếu (Time-based Boundary Stale):**
  - Trước P2.2, `home:composite_public` được lưu với TTL cố định là **300 giây (5 phút)**.
  - Nếu một chiến dịch Flash Sale kết thúc sau 30 giây kể từ khi cache được tạo, khóa cache này vẫn tồn tại thêm 270 giây (4.5 phút) trên Valkey.
  - Khách hàng truy cập trang chủ vẫn thấy banner Flash Sale hiển thị `ACTIVE` với giá flash sale rẻ, nhưng khi bấm vào trang chi tiết hoặc thanh toán, MongoDB kiểm tra theo thời gian thực và báo chiến dịch đã hết hạn, gây trải nghiệm mâu thuẫn nghiêm trọng.
  - Tương tự, nếu một chiến dịch UPCOMING bắt đầu sau 60 giây, trang chủ không hiển thị Flash Sale cho đến khi hết 300s TTL.
- **Giải pháp khắc phục:** Triển khai cơ chế **2 lớp bảo vệ (Dual-Layer Time Boundary Guard)**:
  1. *Lớp 1 (Dynamic TTL Bounding tại thời điểm ghi):* Khi lưu `home:composite_public`, tính toán thời điểm chuyển giao trạng thái gần nhất (`getNextCampaignTransitionTimestamp()` = min giữa thời điểm kết thúc của chiến dịch đang chạy và thời điểm bắt đầu của chiến dịch kế tiếp). Giới hạn TTL: `effectiveTtl = min(300, max(5, $transitionTs - $now))`. Valkey sẽ tự động hết hạn chính xác tại mốc ranh giới thời gian!
  2. *Lớp 2 (Read-time Validation Guard tại thời điểm đọc):* Khi đọc cache HIT, kiểm tra ngay nếu `now >= end_at_ts` hoặc `now < start_at_ts` của chiến dịch trong cache, hoặc `now >= __next_transition_ts`. Nếu phát hiện đã bước qua mốc thời gian, lập tức xóa cache và truy vấn lại dữ liệu tươi từ MongoDB Atlas.

### Câu hỏi 5: Khi giá hoặc tồn kho thay đổi, homepage có phản ánh đúng không?
- **Kiểm tra luồng cập nhật kho và giá:**
  - Cập nhật tồn kho (`adminProductStock`): Gọi `SanPham::updateInventory($id, $stock)` -> dòng 1872 gọi `self::invalidateProductCache($id)`.
  - Cập nhật giá bán / thông tin (`adminProductEdit`): Gọi `SanPham::adminUpdate($id, $data)` -> dòng 1836 gọi `self::invalidateProductCache($id)`.
  - Ẩn / Hiển thị sản phẩm (`adminProductVisibility`): Gọi `SanPham::updateProductVisibility($id, $status)` -> dòng 383 gọi `self::invalidateProductCache($id)`.
- **Kết luận:** Mọi thao tác thay đổi giá, kho, hoặc ẩn sản phẩm đều kích hoạt `$cache->deleteByPattern('home:*')`. Do đó, `home:composite_public` bị xóa ngay lập tức, lượt xem trang chủ tiếp theo nạp giá và trạng thái tồn kho mới nhất từ MongoDB Atlas.

---

## 3. KẾT QUẢ KIỂM TOÁN THỰC TẾ STEP 2 — CACHE LIFECYCLE

| Tiêu chí kiểm toán | Trạng thái thực tế | Đánh giá an toàn & Hiệu năng |
| :--- | :--- | :--- |
| **`SanPham::$menuTreeCache` giữa các HTTP requests trên PHP-FPM** | **KHÔNG BỊ GIỮ** | PHP-FPM vận hành theo kiến trúc Shared-Nothing (mỗi HTTP worker hủy bỏ toàn bộ bộ nhớ tĩnh, heap, global variables sau `RSHUTDOWN`). Trong cùng 1 request, `$menuTreeCache` đóng vai trò L1 cache siêu tốc (0ms). Khi có cập nhật sản phẩm, hàm `invalidateProductCache` cũng gán `self::$menuTreeCache = null` triệt để. |
| **Composite Cache có chứa dữ liệu cá nhân hóa không?** | **HOÀN TOÀN KHÔNG** | Khóa `home:composite_public` chỉ lưu dữ liệu danh mục và sản phẩm public dùng chung. Logic cá nhân hóa (Personalization Re-ranking qua `$flashSaleModel->rankCampaignCandidates`) được chạy riêng biệt trên PHP process sau khi lấy dữ liệu public, dựa trên session user. Không có `customer_id`, email, cart, hoặc search history nào bị lưu vào Valkey public key. |
| **Phiên bản và tiền tố Cache Key (Namespacing & Versioning)** | **CHUẨN XÁC** | Mọi khóa đều có tiền tố cấu hình `skinsyntax:` (ngăn ngừa va chạm dữ liệu giữa các môi trường). Các khóa phân trang như `catalog:tatca:<hash>` đều chứa cờ phiên bản `'v' => 1`. |
| **Các đường ghi (Write Paths) có thể gây Stale Cache** | **ĐÃ BẢO VỆ TOÀN DIỆN** | Gồm 4 write paths chính: (1) Sửa sản phẩm/giá/kho; (2) Ẩn/hiển thị sản phẩm; (3) Lưu/bật/tắt/xóa Flash Sale; (4) Xuất bản video AI. Cả 4 đường ghi đều kích hoạt invalidation tức thì. Đường ghi vô hình theo thời gian (Time passage) đã được bảo vệ bởi Dual-Layer Time Boundary Guard. |
| **Xử lý khi Valkey Invalidation gặp sự cố** | **GRACEFUL FAILURE** | `CacheService::deleteByPattern()` và `delete()` bọc trong `try...catch (Throwable $e)`. Hàm luôn xóa L1 memory cache trước, và nếu kết nối Valkey lỗi thì ghi warning log và trả về 0 mà không bao giờ ném Uncaught Exception làm crash ứng dụng. |

---

## 4. KẾT LUẬN KIỂM TOÁN

- Kiến trúc cache composite hiện tại đã đạt độ ổn định và an toàn cao sau khi áp dụng các bản vá phòng hộ nạp thư viện và cơ chế bảo vệ ranh giới thời gian Flash Sale.
- Không tồn tại rò rỉ dữ liệu cá nhân hay dữ liệu thanh toán nhạy cảm trên Aiven Valkey.
- Checkout và Giỏ hàng tiếp tục duy trì nguyên tắc Authoritative Database độc lập trên MongoDB Atlas.
