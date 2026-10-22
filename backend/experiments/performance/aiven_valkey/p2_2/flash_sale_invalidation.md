# CƠ CHẾ VÀ KIỂM ĐỊNH FLASH SALE INVALIDATION (PHASE P2.2)

## 1. PHÂN TÍCH RỦI RO BẤT ĐỒNG BỘ DỮ LIỆU FLASH SALE

Flash Sale là tính năng bán hàng chớp nhoáng có độ nhạy cảm cao nhất về mặt giá cả và thời gian:
1. **Rủi ro ranh giới bắt đầu (Start Boundary Lag):** Chiến dịch bắt đầu lúc 12:00:00, nhưng cache trang chủ được sinh lúc 11:58:00 với TTL 300s. Nếu không có cơ chế ranh giới động, khách hàng vẫn thấy giá gốc cho đến 12:03:00 (mất 3 phút doanh thu vàng của chiến dịch).
2. **Rủi ro ranh giới kết thúc (End Boundary Leak):** Chiến dịch kết thúc lúc 13:00:00, nhưng cache trang chủ được sinh lúc 12:59:00 với TTL 300s. Cho tới 13:04:00, trang chủ vẫn hiển thị giá giảm sâu. Khi khách hàng bấm Mua Ngay hoặc Checkout, hệ thống authoritative MongoDB từ chối mức giá khuyến mãi đã hết hạn, gây khiếu nại và trải nghiệm tiêu cực.
3. **Rủi ro Admin đột xuất (Admin Emergency Mutation):** Quản trị viên phát hiện lỗi thiết lập giá flash sale quá thấp hoặc sản phẩm hết hàng đột xuất và bấm Tắt (Toggle OFF) hoặc Xóa (Delete) chiến dịch. Bộ nhớ đệm phải bị xóa ngay lập tức trong mili-giây trên toàn bộ cluster.

---

## 2. GIẢI PHÁP KỸ THUẬT: DUAL-LAYER TIME BOUNDARY GUARD

Để giải quyết triệt để rủi ro mà không cần chạy cron job tốn tài nguyên hoặc xóa toàn bộ database Valkey, SkinSyntaxVN Phase P2.2 áp dụng kiến trúc bảo vệ ranh giới 2 lớp:

```
[Khách truy cập Trang chủ]
         │
         ▼
[1. Kiểm tra L2 Valkey Cache: home:composite_public]
         │
    (Cache HIT)
         │
         ├──► [Read Guard: Kiểm tra ranh giới thời gian]
         │        ├── Chiến dịch active có end_at <= now hoặc start_at > now?
         │        └── Có mốc transition __next_transition_ts <= now?
         │                │
         │             (Có - Đã hết hạn)
         │                │
         │                └──► [Xóa Cache ngay lập tức!] ──► [Fallback xuống MongoDB Atlas]
         │
         └──► (Không - Vẫn an toàn) ──► [Trả về dữ liệu Cache HIT] (p50 ~ 750ms)
         │
    (Cache MISS)
         │
         ▼
[2. Nạp dữ liệu Authoritative từ MongoDB Atlas]
         ├── menuTree()
         ├── latest()
         ├── cats()
         ├── getFlashSaleProducts()
         └── getPrimaryActiveCampaign()
         │
         ▼
[3. Tính toán Ranh giới chuyển giao kế tiếp: getNextCampaignTransitionTimestamp()]
         ├── T1 = Earliest end_at của chiến dịch đang ACTIVE (nếu có)
         └── T2 = Earliest start_at của chiến dịch UPCOMING (nếu có)
         └── Next Transition = min(T1, T2)
         │
         ▼
[4. Đóng gói và lưu vào Aiven Valkey]
         ├── TTL Động: effectiveTtl = min(300, max(5, Next Transition - now))
         └── Lưu kèm metadata: '__next_transition_ts' => Next Transition
```

---

## 3. THAY ĐỔI MÃ NGUỒN TỐI THIỂU TẠI ĐÚNG VỊ TRÍ

### 3.1. `FlashSaleCampaign.php`:
1. **Phòng hộ Invalidation (`clearRequestCache`):**
   Tự động nạp `SanPham.php` hoặc `CacheService.php` nếu chưa có sẵn trong ngữ cảnh gọi, đảm bảo Valkey keys `home:*`, `catalog:*`, `menu:*` luôn luôn bị xóa.
2. **Hàm tính toán ranh giới (`getNextCampaignTransitionTimestamp`):**
   Thực hiện 2 truy vấn cực nhanh (đều có index và limit 1, chỉ lấy projection 1 trường) để xác định mốc Unix timestamp kế tiếp mà trạng thái Flash Sale sẽ thay đổi.

### 3.2. `HomeController.php`:
1. **Dynamic TTL Bounding:** Thay thế TTL cứng 300s bằng TTL động co giãn theo thời gian còn lại của chiến dịch.
2. **Read-time Boundary Guard:** Kiểm tra tính hợp lệ của timestamp ngay khi đọc cache HIT, ngăn chặn 100% tình trạng phục vụ dữ liệu stale khi đã bước qua ranh giới bắt đầu/kết thúc.

---

## 4. MA TRẬN KIỂM CHỨNG TÍNH ĐÚNG ĐẮN

| Kịch bản kiểm thử | Hành vi hệ thống | Kết quả |
| :--- | :--- | :---: |
| **Admin thêm mới chiến dịch Flash Sale** | Gọi `saveCampaign()` -> kích hoạt `clearRequestCache()` -> xóa `home:composite_public`. Lần truy cập tiếp theo hiển thị ngay chiến dịch mới. | **PASS** |
| **Admin tắt chiến dịch (Toggle OFF)** | Gọi `toggleCampaign()` -> kích hoạt `clearRequestCache()` -> xóa `home:composite_public`. Lần truy cập tiếp theo ẩn ngay chiến dịch. | **PASS** |
| **Admin xóa chiến dịch (Delete)** | Gọi `deleteCampaign()` -> kích hoạt `clearRequestCache()` -> xóa `home:composite_public`. Trang chủ cập nhật tức thì. | **PASS** |
| **Chiến dịch tự kết thúc theo thời gian** | Read Guard phát hiện `now >= end_at_ts`, tự động evict cache key trên Valkey và gọi MongoDB nạp trạng thái không còn Flash Sale. | **PASS** |
| **Chiến dịch kế tiếp tự bắt đầu** | Cache tự hết hạn tại mốc `__next_transition_ts` (hoặc Read Guard phát hiện), trang chủ tự động hiển thị chiến dịch mới. | **PASS** |
| **Sản phẩm trong Flash Sale bị ẩn** | `SanPham::updateProductVisibility()` xóa `home:*` và `product:detail:*`. Trang chủ không còn hiển thị sản phẩm ẩn. | **PASS** |
| **Giá sản phẩm trong catalog thay đổi** | `SanPham::invalidateProductCache()` xóa sạch cache liên quan. | **PASS** |
| **Không xóa toàn bộ Valkey DB** | Chỉ dùng `deleteByPattern('home:*')`, `catalog:*`, `menu:*`. Không dùng `FLUSHDB` hay `FLUSHALL`. | **PASS** |
