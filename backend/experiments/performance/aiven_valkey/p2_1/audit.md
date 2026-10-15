# BÁO CÁO AUDIT HỆ THỐNG VALKEY CACHE (PHASE P2 & P2.1)

## 1. Bối cảnh & Hiện trạng sau Phase P2

Trong giai đoạn P2, nhóm kỹ sư đã triển khai hệ thống bộ nhớ đệm Cache-aside kết nối dịch vụ Aiven Valkey (TLS rediss://) cho 3 route chính của SkinSyntaxVN:
1. **Homepage (`?r=home`)**: Lưu cache các truy vấn danh mục, sản phẩm mới nhất, danh mục nổi bật, flash deals.
2. **Category Listing (`?r=tatca`)**: Lưu cache kết quả phân trang theo bộ lọc và từ khóa.
3. **Product Detail (`?r=chitiet&id=...`)**: Lưu cache sản phẩm thường mua kèm (FBT) và video AI liên quan.

Tuy nhiên, kết quả benchmark so sánh P2 với baseline P0 ghi nhận hiện tượng bất thường:
- **Homepage p50**: $2503.51\text{ ms} \longrightarrow 2578.71\text{ ms}$ (chậm hơn $75.2\text{ ms}$).
- **Category p50**: $1964.97\text{ ms} \longrightarrow 1232.29\text{ ms}$ (nhanh hơn $732.68\text{ ms}$, cải thiện 37.3%).
- **Product Detail p50**: $446.63\text{ ms} \longrightarrow 717.04\text{ ms}$ (chậm hơn $270.41\text{ ms}$, tăng 60.5% độ trễ!).

Mục tiêu của audit là tìm ra căn nguyên sâu xa của việc "thêm cache nhưng trang lại chậm đi", từ đó đưa ra phương án tối ưu triệt để.

---

## 2. Kết quả Audit Chi Tiết Theo Từng Thành Phần

### 2.1. Audit `CacheService.php` & Kết Nối Predis TLS
1. **Lỗi cấu hình tham số SSL trong Predis:**
   - Trong `CacheService.php` ban đầu, cấu hình SSL được truyền ở cấp ngoài cùng:
     ```php
     $options = [
         'parameters' => [
             'timeout' => 2.0,
             'read_write_timeout' => 2.0,
             'persistent' => true,
         ],
     ];
     if ($isTls) {
         $options['ssl'] = [
             'verify_peer' => true,
             'verify_peer_name' => true,
             'allow_self_signed' => false,
         ];
     }
     ```
   - **Phát hiện:** Khi sử dụng `Predis\Client(url, $options)`, class `StreamFactory::tlsStreamInitializer` chỉ đọc tham số SSL từ `$parameters->ssl`. Do đặt `$options['ssl']` ngoài `parameters`, Predis hoàn toàn không nhận được các cờ kiểm tra chứng chỉ nghiêm ngặt.
   - **Khắc phục:** Đã chuyển cấu hình SSL vào trong `$options['parameters']['ssl']`.

2. **Chi phí Handshake TLS ban đầu (Cold Connection Overhead):**
   - Đo kiểm thực tế qua script `benchmark_valkey_latency.php`:
     - Kết nối lạnh (Cold TLS Handshake): Dao động từ $194.01\text{ ms}$ đến $1994.52\text{ ms}$ (trung bình $\approx 703.12\text{ ms}$).
     - Lệnh qua kết nối ấm (Warm Socket PING): Dao động từ $55.43\text{ ms}$ đến $83.08\text{ ms}$.
   - **Hệ quả:** Nếu worker PHP-FPM phải mở socket mới hoặc bị ngắt kết nối do mạng công cộng, chi phí bắt tay TLS sẽ triệt tiêu toàn bộ thời gian tiết kiệm được từ MongoDB.

### 2.2. Audit Đường Đọc Trang Chủ (Homepage Cache Paths)
1. **Hiện tượng Roundtrip Amplification (Nhiều roundtrip tuần tự trên 1 request):**
   - Trên mỗi lượt tải `index.php?r=home`, code P2 thực hiện lần lượt các lệnh đọc cache độc lập:
     - Lệnh 1: `GET menu:tree:3600` ($\approx 70\text{ ms}$)
     - Lệnh 2: `GET home:latest:12:1:1` ($\approx 70\text{ ms}$)
     - Lệnh 3: `GET home:highlighted_categories` ($\approx 70\text{ ms}$)
     - Lệnh 4: `GET home:flash_deals:8` ($\approx 70\text{ ms}$)
   - **Tổng thời gian chờ roundtrip mạng tuần tự:** $4 \times 70\text{ ms} = 280\text{ ms}$ chỉ riêng cho việc giao tiếp mạng với Valkey!
2. **Chiến dịch Flash Sale chưa được cache:**
   - Phương thức `FlashSaleCampaign::getPrimaryActiveCampaign()` vẫn truy vấn trực tiếp collection `flash_sale_campaigns` và hydrate từng sản phẩm từ `san_pham` bằng MongoDB Atlas (mất $\approx 300 - 800\text{ ms}$).
3. **Kết luận Homepage:**
   - Thay vì giảm độ trễ, việc thêm 4 roundtrip mạng tuần tự cộng với việc vẫn phải chờ MongoDB thực hiện truy vấn Flash Sale khiến Homepage ở P2 bị chậm hơn so với khi chưa bật cache.

### 2.3. Audit Đường Đọc Chi Tiết Sản Phẩm (Product Detail Cache Paths)
1. **So sánh Head-to-Head MongoDB `findOne` vs Remote Valkey `GET`:**
   - Truy vấn bản ghi đơn lẻ `san_pham->findOne(['ma_san_pham' => $id])` trong MongoDB Atlas đã có Single Unique Index trên `ma_san_pham`. Thời gian thực thi tại DB chỉ mất $\approx 20 - 50\text{ ms}$.
   - Trong khi đó, việc gửi lệnh `GET` từ backend đến Aiven Valkey qua đường truyền internet xuyên quốc tế mất $\approx 65 - 80\text{ ms}$ (và lên tới $700\text{ ms}$ nếu xảy ra TLS handshake).
   - Do đó, việc lưu cache sản phẩm đơn lẻ vào Valkey **không mang lại lợi ích về tốc độ** so với truy vấn indexed findOne, mà lại tiềm ẩn nguy cơ hiển thị giá cũ/tồn kho cũ nếu invalidation gặp trục trặc.
2. **Lỗi Cache Penetration đối với Mảng rỗng (`frequentlyBought` & `productVideos`):**
   - Trong `SanPhamController::chitiet()`:
     ```php
     if (class_exists('CacheService') && !empty($frequentlyBought)) {
         CacheService::getInstance()->set($freqKey, $frequentlyBought, 3600);
     }
     ```
   - Đối với các sản phẩm chưa có dữ liệu mua kèm hoặc chưa liên kết video AI, `$frequentlyBought` và `$productVideos` trả về mảng rỗng `[]`.
   - Do điều kiện `!empty()`, hệ thống **không bao giờ lưu kết quả rỗng vào cache**.
   - Ở các request tiếp theo, cache luôn bị MISS, hệ thống vừa mất roundtrip hỏi Valkey, vừa phải chạy lại thuật toán Association Rule và query MongoDB cho cả hai mục này!
   - Đây chính là lý do khiến Product Detail p50 tăng từ $446\text{ ms}$ lên $717\text{ ms}$!

### 2.4. Audit Đường Đọc Danh Mục (Category Listing Cache)
1. **Hiệu quả rõ rệt:**
   - Phân trang `tatca` liên quan đến `count`, `sort`, `filter` trên toàn bộ tập dữ liệu (hơn 3.000 sản phẩm), tiêu tốn từ $1800\text{ ms}$ đến $2500\text{ ms}$ trên MongoDB Atlas.
   - Nhờ lưu cache nguyên khối kết quả phân trang 24 sản phẩm, Category Listing giảm được từ $1964\text{ ms}$ xuống $1232\text{ ms}$.
2. **Điểm cần hoàn thiện:**
   - Tương tự như PDP, khi bộ lọc không có sản phẩm nào khớp, kết quả rỗng cũng cần được lưu trữ ngắn hạn ($300\text{ s}$) để ngăn chặn cache penetration.

---

## 3. Tổng Hợp Các Hành Động Khắc Phục Ở Phase P2.1

| Hạng mục | Vấn đề ở P2 | Giải pháp tối ưu P2.1 | Trạng thái |
| :--- | :--- | :--- | :--- |
| **Homepage** | 4 roundtrip GET tuần tự + truy vấn Flash Sale Mongo không cache | Gom toàn bộ dữ liệu public (Menu, Latest, Highlighted Cats, Flash Deals, Primary Campaign) vào **1 Valkey key duy nhất (`home:composite_public`)**. Giảm từ 4 roundtrips xuống **1 roundtrip**. | **ĐÃ TRIỂN KHAI** |
| **Product Detail** | Bị phạt bởi TLS handshake + Cache penetration với kết quả rỗng | Giữ nguyên đọc trực tiếp MongoDB index cho thông tin sản phẩm chính để bảo đảm 100% giá và tồn kho. Gom FBT, Video, và Menu vào **1 lệnh `mget` duy nhất**, đồng thời lưu trữ cả kết quả rỗng để triệt tiêu cache penetration. | **ĐÃ TRIỂN KHAI** |
| **Category** | Bỏ sót cache cho kết quả lọc rỗng | Đổi điều kiện lưu cache từ `!empty($items)` sang `isset($items)` để bảo vệ hệ thống trước các truy vấn không có kết quả. | **ĐÃ TRIỂN KHAI** |
| **Predis TLS** | Tham số `ssl` đặt sai vị trí trong mảng `$options` | Đưa `ssl` vào trong `$options['parameters']['ssl']` để kích hoạt đầy đủ xác thực peer và host name. | **ĐÃ TRIỂN KHAI** |
| **Testability** | Khó đo lường Cache OFF và Valkey Down qua HTTP độc lập | Bổ sung cơ chế `cache=off` và `valkey_down=1` qua query param để đo benchmark khách quan mà không ảnh hưởng code nghiệp vụ. | **ĐÃ TRIỂN KHAI** |
