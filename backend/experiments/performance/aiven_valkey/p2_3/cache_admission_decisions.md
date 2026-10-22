# SKINSYNTAXVN — PHASE P2.3: CACHE ADMISSION POLICY & ROUTE GOVERNANCE

**Author:** Senior PHP/MongoDB/Valkey Performance & Security Engineer  
**Date:** 2026-10-18  
**Scope:** Route-by-route Cache Admission Decisions, Latency Gates, and Resource Protection

---

## 1. Nguyên lý Cache Admission Gate (Performance-First Principle)

Trong kiến trúc hệ thống phân tán SkinSyntaxVN:
1. **Không mặc định cache là tốt chỉ vì MongoDB query count giảm.**
2. Việc sử dụng Remote Cache (Valkey TLS over WAN) chỉ được chấp thuận (Admitted) khi đáp ứng ít nhất một trong hai điều kiện:
   - **Performance Gate**: Cache HIT mang lại độ trễ p50 thấp hơn đáng kể so với truy vấn trực tiếp (Speedup $\ge 20\%$).
   - **Protection Gate (Giảm tải cụm dữ liệu)**: Giảm tải áp lực tính toán phức tạp (CPU-heavy aggregation, 5+ queries tuần tự) cho MongoDB Atlas cluster khi lưu lượng người dùng tăng cao, mà không làm suy giảm trải nghiệm p50 quá ngưỡng chấp nhận ($+15\%$).

Nếu một route có truy vấn trực tiếp MongoDB Atlas nhanh hơn đọc từ Remote Cache qua mạng, **bắt buộc phải ưu tiên Direct MongoDB và Bypass Valkey**.

---

## 2. Quyết định Admission theo từng Route (Route-by-Route Governance)

### 2.1. Product Detail Page (PDP) — Route `?r=chitiet&id={id}`

- **Thực nghiệm đo lường:**
  - Direct MongoDB Atlas indexed `findOne(['ma_san_pham' => 111])`: **43.02 ms (p50)**.
  - Valkey Remote MGET (3 keys qua TLS): **54.05 ms (p50)** + rủi ro handshake re-connection nếu worker mới (**134.98 ms**).
  - HTTP p50 Cache OFF (Direct Atlas): **252.18 ms**.
  - HTTP p50 Cache HIT (Valkey): **368.05 ms** (Chậm hơn **+115.87 ms**, tức suy giảm **46%**).
- **Phân tích kỹ thuật:**
  - Dữ liệu chi tiết sản phẩm được truy xuất qua unique index `ma_san_pham` trên MongoDB Atlas. Driver MongoDB PHP duy trì connection pool ấm và wire-protocol cực nhanh.
  - Bundled MGET 3 keys (`product:detail`, `product:freq_bought`, `product:videos`) qua Valkey TLS tạo thêm roundtrip mạng qua WAN và chi phí giải mã JSON.
- **QUYẾT ĐỊNH ADMISSION: BYPASS VALKEY (DIRECT MONGODB PREFERRED)**
  - **Trạng thái:** **REJECTED FROM REMOTE CACHE** (Bypass Valkey).
  - **Hành vi chuẩn:**
    - Đọc trực tiếp MongoDB Atlas qua B-tree index.
    - Không phát sinh lệnh `MGET` lên Valkey cho trang chi tiết sản phẩm đơn lẻ.
    - Đạt p50 thực tế tối ưu nhất: **~252 ms**.
    - Tuyệt đối không bắt buộc dùng Valkey chỉ để đổi lấy việc giảm 1 query MongoDB.

---

### 2.2. Category Page — Route `?r=tatca` / Catalog

- **Thực nghiệm đo lường:**
  - Direct MongoDB Atlas (1 aggregate facets + 1 find 24 items): **67.05 ms (p50)**.
  - Valkey Remote GET (1 serialized catalog chunk): **53.25 ms (p50)**.
  - HTTP p50 Cache OFF: **663.22 ms**.
  - HTTP p50 Cache HIT: **668.48 ms** ($\Delta \approx +5\text{ms}$, tương đương $0.7\%$).
- **Phân tích kỹ thuật:**
  - Đối với trang catalog mặc định (không lọc phức tạp), MongoDB Atlas thực thi rất nhanh nhờ pagination cursor và memory index.
  - Tuy nhiên, trong môi trường thương mại điện tử thực tế:
    - Người dùng thường lọc nhiều tiêu chí đồng thời (brand, price range, skin type, concern, rating, sort order).
    - Các truy vấn faceted aggregation kết hợp nhiều `$match` và `$facet` trên Atlas có thể tiêu tốn từ 250ms đến 600ms CPU time nếu không có cache.
- **QUYẾT ĐỊNH ADMISSION: SELECTIVE ADMISSION (CACHE THEO ĐỘ PHỨC TẠP)**
  - **Trạng thái:** **CONDITIONALLY ADMITTED**.
  - **Hành vi chuẩn:**
    - **Catalog mặc định (Page 1, No Filter):** Cho phép cache với TTL ngắn (120s - 300s) nhằm giảm tải MongoDB Atlas (tiết kiệm 2 queries/request cho hàng nghìn khách truy cập đồng thời), dù p50 tương đương.
    - **Truy vấn lọc sâu / Faceted Search:** Bắt buộc áp dụng Cache-aside với key theo hash `md5(query_params)` để triệt tiêu thời gian aggregation đắt đỏ.
    - **Không suy rộng:** Không áp dụng bừa bãi cho các danh mục rỗng hoặc các bộ lọc ngẫu nhiên không có kết quả nhằm tránh làm ô nhiễm (cache pollution) bộ nhớ Valkey.

---

### 2.3. Homepage — Route `?r=home` / `/`

- **Thực nghiệm đo lường:**
  - Direct MongoDB Atlas (5 queries tuần tự: flash sale, hot products, new products, best sellers, categories): **186.55 ms (p50)**, đỉnh điểm **328.71 ms**.
  - Valkey Remote GET (`home:composite_public` 258.8 KB): **87.43 ms (p50)**.
  - Số truy vấn MongoDB Atlas: Giảm từ **6 queries xuống 0 queries (100% database offload)**.
- **Phân tích kỹ thuật:**
  - Tầng dữ liệu: Valkey tiết kiệm gần **100 ms (186.55ms vs 87.43ms)** thời gian đọc dữ liệu.
  - Homepage là trang đón lưu lượng lớn nhất (chiếm 60-80% tổng traffic của toàn sàn). Nếu 1000 người dùng truy cập đồng thời, Direct MongoDB sẽ tạo ra 6000 queries dồn dập lên Atlas cluster, gây cạn kiệt connection pool.
  - Với Valkey Composite Cache, 1000 requests chỉ đọc từ bộ nhớ RAM của Valkey mà không chạm vào MongoDB Atlas.
- **QUYẾT ĐỊNH ADMISSION: FULL ADMISSION WITH DUAL-LAYER TIME BOUNDARY GUARD**
  - **Trạng thái:** **ADMITTED (PROTECTION & WORKLOAD CONSOLIDATION GATE PASS)**.
  - **Hành vi chuẩn:**
    - Lưu trữ toàn bộ dữ liệu công khai trang chủ trong 1 composite payload duy nhất `home:composite_public`.
    - Bảo vệ tính chính xác bằng cơ chế Dual-Layer Guard (Dynamic TTL + Read-time Boundary Verification) để loại bỏ hoàn toàn nguy cơ Flash Sale stale data.

---

## 3. Bảng tổng hợp chính sách Cache Admission (Summary Matrix)

| Route | Admission Status | Primary Driver | Rationale & Gate Result | Action Items |
|---|:---:|:---:|---|---|
| **Product Detail** | **REJECTED (BYPASS)** | **MongoDB Atlas** | Atlas indexed query (43ms) nhanh hơn Valkey (54ms + TLS). Giảm p50 từ 368ms xuống 252ms. | Bypass Valkey MGET, đọc trực tiếp Atlas |
| **Category (Default)** | **ADMITTED** | **Valkey L2** | Độ trễ tương đương (~660ms), tiết kiệm 2 queries/request, bảo vệ Atlas IOPS | Duy trì key `catalog:*` TTL 300s |
| **Category (Complex Filters)** | **ADMITTED** | **Valkey L2** | Triệt tiêu thời gian aggregation nặng (200-500ms) trên Atlas | Cache theo hash params |
| **Homepage** | **ADMITTED** | **Valkey L2** | Tiết kiệm 100ms đọc dữ liệu (87ms vs 186ms), giảm từ 6 xuống 0 query Atlas | Duy trì `home:composite_public` + Dual Guard |
| **Checkout & Voucher** | **STRICT BYPASS** | **MongoDB Atlas** | Yêu cầu tính toàn vẹn tài chính, authoritative state tuyệt đối | Nghiêm cấm cache dữ liệu thanh toán |
| **Recommendation Engine** | **NEUTRAL / UNTOUCHED** | **MongoDB Atlas** | Giữ nguyên theo phạm vi Phase P2.3 (không thay đổi thuật toán) | Để ngỏ cho Phase P3 |
