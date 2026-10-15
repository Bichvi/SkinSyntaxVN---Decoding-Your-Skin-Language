# QUYẾT ĐỊNH KIẾN TRÚC CACHE THEO TỪNG ROUTE (PHASE P2.1)

Hệ thống: **SkinSyntaxVN E-Commerce Platform**  
Giai đoạn: **Phase P2.1 — Valkey Cache Performance Correction & Acceptance**  
Ngày phê duyệt: **15/10/2026**  
Kỹ sư phụ trách: **Senior PHP/MongoDB Performance Engineer & Valkey Engineer**

---

## 1. TỔNG QUAN BẢNG QUYẾT ĐỊNH (EXECUTIVE DECISION MATRIX)

Dựa trên dữ liệu đo lường thực nghiệm 360 requests chuẩn hóa (30 lượt đo xen kẽ - interleaved per mode) thu được từ bộ benchmark tự động:

| Tuyến đường (Route) | Quyết định P2.1 | Lý do kỹ thuật cốt lõi | Trước tối ưu (P2) | Sau tối ưu (P2.1) | Cải thiện thực tế? |
| :--- | :---: | :--- | :---: | :---: | :---: |
| **Trang chủ (`?r=home`)** | **GIỮ CACHE (KEEP)**<br>*(Gom Composite Bundle)* | Thay thế 4 lệnh Valkey GET tuần tự + uncached Flash Sale bằng **1 roundtrip duy nhất** `home:composite_public` (TTL 300s). Khử sạch 100% query MongoDB trên Cache HIT. | **2,578.71 ms** (p50) | **757.52 ms** (p50) | **CÓ — GIẢM 70.6%**<br>*(Nhanh hơn 1,821 ms)* |
| **Danh mục (`?r=tatca`)** | **GIỮ CACHE (KEEP)**<br>*(Bổ sung Empty Cache)* | Giữ nguyên kiến trúc catalog cache theo hash tham số (filter/sort/page), sửa triệt để lỗi cache penetration bằng cách lưu cả kết quả rỗng `items: []`. | **1,232.29 ms** (p50) | **1,082.87 ms** (p50) | **CÓ — GIẢM 12.1%**<br>*(Nhanh hơn P0 44.9%)* |
| **Chi tiết SP (`?r=chitiet`)** | **BỎ REMOTE CACHE SP ĐƠN LẺ**<br>*(Chỉ cache FBT/Video qua MGET)* | Indexed `findOne` của MongoDB Atlas cực nhanh (20ms query, tổng HTTP p50 = **325.58 ms**). Remote Valkey HIT p50 = **484.25 ms**. Valkey chậm hơn $158.67\text{ ms}$ do TLS latency. | **717.04 ms** (p50) | **325.58 ms** (p50)<br>*(Direct Mongo)* | **CÓ — GIẢM 54.6%**<br>*(Nhanh hơn 391.4 ms)* |

---

## 2. PHÂN TÍCH CHI TIẾT TỪNG ROUTE

### 2.1. ROUTE 1: TRANG CHỦ (`?r=home`)

#### Bối cảnh P2 & Lỗ hổng đã tìm thấy:
- Trong Phase P2, Homepage bị chậm hơn cả lúc chưa có cache ($2578.71\text{ ms}$ vs $2503.51\text{ ms}$).
- **Nguyên nhân:**
  1. `HomeController::index()` gọi 4 lệnh đọc Valkey tuần tự: `home:menu_tree`, `home:latest`, `home:cats`, `home:flash_deals`. Mỗi roundtrip TLS qua cloud tốn ~70–100ms, khiến tổng chi phí mạng chờ cache lên tới ~350–400ms.
  2. Khối Flash Sale Campaign (`FlashSaleCampaign::getPrimaryActiveCampaign`) không được cache, vẫn chọc thẳng xuống MongoDB Atlas để scan collection `flash_sale_campaigns`.
  3. Phương thức `SanPham::getHomepageProductSections()` tự động gọi lại `getFlashSaleProducts()` bên dưới dù controller đã lấy dữ liệu.

#### Giải pháp tối ưu P2.1:
1. **Consolidated Composite Cache (`home:composite_public`):**
   - Tạo phương thức trung tâm `HomeController::getHomepagePublicData()` gom toàn bộ 5 khối dữ liệu tĩnh/bán tĩnh dùng chung của Trang chủ:
     - `menu_tree`: Cây 3 cấp danh mục.
     - `latest`: Top sản phẩm mới nhất.
     - `cats`: Danh mục nổi bật có đếm sản phẩm.
     - `flash_deals`: Top sản phẩm Flash Sale đang giảm giá sâu.
     - `primary_campaign`: Chiến dịch Flash Sale chính thức đang chạy.
   - Toàn bộ được đóng gói và lưu trong 1 key duy nhất `home:composite_public` với TTL 300 giây.
2. **Loại bỏ Roundtrip tuần tự:** Số lệnh Valkey trên mỗi request Trang chủ giảm từ 4 lệnh xuống **chính xác 1 lệnh GET**.
3. **In-Memory Menu Tree Priming:**
   - Khi load `home:composite_public`, `HomeController` kích hoạt `SanPham::setMenuTreeCache($publicData['menu_tree'])`. Nhờ vậy, khi view gọi `render('layouts/header')`, component Menu Tree lấy thẳng từ RAM PHP worker, **không tốn thêm bất kỳ query DB hay lệnh Valkey nào**.
4. **Không cache dữ liệu người dùng:**
   - Các khối cá nhân hóa (Adaptive Recommender V1 dựa trên cookie/lịch sử xem `recent_viewed`, user profile) được tính toán riêng biệt trong request, không bao giờ nằm trong `home:composite_public`.

#### Kết quả thực nghiệm:
- **P0 Baseline:** $2503.51\text{ ms}$
- **P2 Cache:** $2578.71\text{ ms}$
- **P2.1 Cache HIT:** **$757.52\text{ ms}$**
- **Đánh giá:** GIỮ CACHE. Cải thiện vượt bậc ($1821.19\text{ ms}$, tương đương $70.6\%$).

---

### 2.2. ROUTE 2: DANH MỤC SẢN PHẨM (`?r=tatca`)

#### Bối cảnh P2 & Lỗ hổng đã tìm thấy:
- Trong Phase P2, Category Listing có cải thiện ($1964.97\text{ ms} \rightarrow 1232.29\text{ ms}$) nhờ lưu kết quả tìm kiếm và phân trang vào `catalog:tatca:<hash>`.
- **Nguyên nhân tiềm ẩn rủi ro:**
  - Logic cũ: `if (!empty($res['items'])) { $cache->set(...); }`.
  - Hậu quả: Khi người dùng tìm kiếm từ khóa không có kết quả, hoặc áp bộ lọc mà không có sản phẩm nào khớp (`$res['items'] == []`), hệ thống **không cache**. Dẫn tới kẻ tấn công hoặc bot crawl có thể gửi liên tục các query rác gây **Cache Penetration**, buộc MongoDB Atlas phải chạy aggregation liên tục và làm cạn kiệt tài nguyên DB.

#### Giải pháp tối ưu P2.1:
1. **Khử Cache Penetration:**
   - Sửa điều kiện ghi cache trong `SanPhamController::tatca()`: Đổi từ `!empty($res['items'])` thành `isset($res['items'])`.
   - Các trang rỗng kết quả vẫn được cache an toàn trong 300 giây, bảo vệ MongoDB Atlas 100% trước các đợt crawl/tấn công lặp lại.
2. **Khóa Cache Đầy Đủ Chiều (Multi-dimension Key Hashing):**
   - Key cache: `catalog:tatca:` kết hợp `md5(json_encode([page, limit, sort, category_id, brand_id, skin_types, min_price, max_price, q]))`.
   - Đảm bảo độc lập hoàn toàn giữa các trang, các bộ lọc và các tiêu chí sắp xếp.
3. **Bảo toàn Invalidation:**
   - Khi Admin thêm, sửa giá, ẩn sản phẩm hoặc đổi danh mục, gọi `CacheService::deleteByPattern('catalog:*')` để dọn sạch toàn bộ trang danh mục ngay lập tức.

#### Kết quả thực nghiệm:
- **P0 Baseline:** $1964.97\text{ ms}$
- **P2 Cache:** $1232.29\text{ ms}$
- **P2.1 Cache HIT:** **$1082.87\text{ ms}$**
- **Đánh giá:** GIỮ CACHE. Thời gian phản hồi giảm $882.10\text{ ms}$ ($44.9\%$) so với Baseline, bảo vệ tối đa MongoDB Atlas.

---

### 2.3. ROUTE 3: CHI TIẾT SẢN PHẨM (`?r=chitiet&id=111`)

#### Bối cảnh P2 & Bất thường đo lường:
- Trong Phase P2, thời gian xem Chi tiết sản phẩm tăng từ $446.63\text{ ms}$ lên $717.04\text{ ms}$ (chậm hơn $+270.41\text{ ms}$).
- **Hai nguyên nhân chính:**
  1. **TLS Network RTT Overhead:** Một truy vấn `findOne(['ma_san_pham' => 111])` trên MongoDB Atlas có chỉ mục Unique B-Tree Index chỉ tốn khoảng $15 - 25\text{ ms}$. Trong khi đó, việc gửi lệnh đọc qua TLS socket tới Aiven Valkey trên cloud mất từ $70 - 100\text{ ms}$. Như vậy, remote cache cho một đối tượng đơn lẻ bị chậm hơn so với truy vấn trực tiếp DB local/mạng lân cận.
  2. **Cache Penetration trên FBT & Video:** Do nhiều sản phẩm chưa có dữ liệu mua kèm (Frequently Bought Together) hoặc video liên kết, các mảng rỗng `[]` không được cache ở P2, khiến mỗi request đều phải chạy truy vấn tìm kiếm thứ cấp vào MongoDB.

#### Thử nghiệm đối đầu và Quyết định kỹ thuật P2.1:
1. **So sánh trực tiếp (30 runs interleaved):**
   - **Cache OFF (Đọc trực tiếp MongoDB Atlas qua index):**
     - **p50 = $325.58\text{ ms}$** | p95 = $506.11\text{ ms}$ | Min = $266.78\text{ ms}$
   - **Cache HIT (Gom MGET Valkey):**
     - **p50 = $484.25\text{ ms}$** | p95 = $957.84\text{ ms}$ | Min = $380.49\text{ ms}$
2. **Đánh giá kiến trúc:**
   - Cache OFF nhanh hơn Cache HIT **$158.67\text{ ms}$** ở p50.
   - Hơn nữa, việc đọc sản phẩm đơn lẻ trực tiếp từ MongoDB mang lại lợi ích vô giá: **Tính toàn vẹn và thẩm quyền 100% của Giá bán & Tồn kho (Authoritative Data)**. Khách hàng xem trang chi tiết không bao giờ bị hiện tượng hiển thị giá cũ trong cache trong khi Admin vừa tăng giá hoặc sản phẩm vừa hết hàng.
3. **Quyết định dứt khoát:**
   - **BỎ REMOTE CACHE đối với việc đọc thông tin sản phẩm đơn lẻ (`product:detail:<id>`).**
   - Luôn sử dụng `$sanPham->find($id, true)` đọc trực tiếp từ MongoDB Atlas có chỉ mục unique.
   - Đối với các khối phụ trợ tổng hợp (Frequently Bought Together, Video Commerce, Menu Tree), gom vào một lệnh `mget` duy nhất và luôn lưu mảng rỗng `[]` khi không có dữ liệu để chống penetration.

#### Kết quả thực nghiệm:
- **P0 Baseline:** $446.63\text{ ms}$
- **P2 Cache:** $717.04\text{ ms}$
- **P2.1 Direct Mongo (Cache OFF):** **$325.58\text{ ms}$**
- **Đánh giá:** TẮT REMOTE CACHE CHO SINGLE ENTITY. Trang chi tiết đạt tốc độ kỷ lục ($325.58\text{ ms}$), giảm $391.46\text{ ms}$ ($54.6\%$) so với P2.

---

## 3. TỔNG KẾT QUY TẮC CACHE CỦA HỆ THỐNG SAU PHASE P2.1

1. **Composite Cache cho Dữ Liệu Tĩnh Cấp Trang (Page-Level Public Composite):** Chỉ áp dụng khi trang có $\ge 3$ khối dữ liệu dùng chung và tần suất đọc cực cao (Homepage). Gom vào 1 key duy nhất để tối thiểu hóa RTT qua mạng.
2. **Catalog Aggregation Cache:** Áp dụng cho danh sách sản phẩm phân trang/lọc/sắp xếp (`catalog:tatca:*`), bắt buộc lưu cả kết quả rỗng `items: []` với TTL 300s.
3. **No Remote Cache cho Single Entity By Unique Index:** Tuyệt đối không cache từ xa các truy vấn tìm kiếm một bản ghi duy nhất theo khóa chính/chỉ mục unique (như Product Detail, User Profile, Order Detail).
4. **Authoritative Checkout & Cart:** Luôn đọc trực tiếp từ MongoDB khi đưa vào Giỏ hàng và Thanh toán. Valkey không bao giờ là nguồn dữ liệu thẩm quyền cho giá tiền và trừ kho.
