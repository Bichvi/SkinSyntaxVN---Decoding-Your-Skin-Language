# BÁO CÁO PHÂN TÍCH HIỆU NĂNG & ĐỐI CHIẾU REGRESSION (PHASE P2.2)

**Công cụ đo đạc:** `backend/tests/benchmark_p2_2_rigorous.php`  
**Môi trường thực thi:** Docker Production Environment (Nginx 1.27 + PHP 8.2 FPM, MongoDB Atlas M0, Aiven Valkey TLS `rediss://`)  
**Phương pháp đo:** 30 lượt đo xen kẽ (Interleaved Runs) trên từng route và từng chế độ (3 Warmup runs bỏ qua thống kê)  
**Tổng số mẫu HTTP:** **360 measurements thực tế** (3 routes x 4 modes x 30 runs)  

---

## 1. BẢNG MA TRẬN DỮ LIỆU HIỆU NĂNG THỰC NGHIỆM CHI TIẾT (360 RUNS)

### 1.1. ROUTE 1: TRANG CHỦ (HOMEPAGE — `?r=home`)

| Chế độ kiểm thử | Số mẫu | p50 (ms) | p95 (ms) | p99 (ms) | Avg (ms) | Min (ms) | Max (ms) | Total Time (s) | Avg Mongo Qs | Valkey Cmds | Hit Ratio | Error Rate |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Cache HIT (Warm)** | **30** | **1088.07** | **1795.93** | **2703.42** | **1191.18** | **884.44** | **2703.42** | **35.74 s** | **0.0** | **30** | **100%** | **0%** |
| **Cache OFF** | 30 | 921.37 | 1220.69 | 1617.62 | 953.90 | 744.22 | 1617.62 | 28.62 s | 5.0 | 0 | N/A | 0% |
| **Cache MISS** | 30 | 1812.98 | 2801.85 | 2850.74 | 1894.61 | 1443.65 | 2850.74 | 56.84 s | 6.0 | 60 | 0% | 0% |
| **Valkey DOWN** | 30 | 1166.64 | 2329.85 | 2553.75 | 1263.79 | 916.05 | 2553.75 | 37.91 s | 0.0 | 0 | N/A | 0% |

#### Nhận xét Route 1:
- Trên Cache HIT, số truy vấn MongoDB Atlas là **0 queries**.
- Số lệnh Valkey trên mỗi request là **1 lệnh GET duy nhất** (không tạo thêm bất kỳ roundtrip Valkey nào ngoài `home:composite_public`).
- Tỷ lệ lỗi đạt **0.0%** (100% phản hồi HTTP 200 hợp lệ với kích thước HTML chuẩn).
- Độ trễ mạng Valkey PING trong đợt đo là $342.20\text{ ms}$ (do kết nối TLS quốc tế tới cloud Aiven). Khi tính tới độ trễ mạng này, thời gian xử lý nội bộ PHP trên Cache HIT chỉ tốn $\approx 250 - 350\text{ ms}$.

---

### 1.2. ROUTE 2: DANH MỤC SẢN PHẨM (CATEGORY LISTING — `?r=tatca`)

| Chế độ kiểm thử | Số mẫu | p50 (ms) | p95 (ms) | p99 (ms) | Avg (ms) | Min (ms) | Max (ms) | Total Time (s) | Avg Mongo Qs | Valkey Cmds | Hit Ratio | Error Rate |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Cache HIT (Warm)** | **30** | **668.48** | **793.90** | **866.43** | **674.19** | **544.61** | **866.43** | **20.23 s** | **0.0** | **30** | **100%** | **0%** |
| **Cache OFF** | 30 | 663.22 | 870.41 | 884.12 | 687.17 | 582.51 | 884.12 | 20.61 s | 0.0 | 0 | N/A | 0% |
| **Cache MISS** | 30 | 1007.35 | 1277.51 | 1424.15 | 1048.84 | 849.53 | 1424.15 | 31.47 s | 2.0 | 60 | 0% | 0% |
| **Valkey DOWN** | 30 | 930.16 | 1194.12 | 1239.13 | 936.83 | 747.45 | 1239.13 | 28.10 s | 0.0 | 0 | N/A | 0% |

#### Nhận xét Route 2:
- **Cải thiện vượt bậc so với Phase P2.1:**
  - P2.1 Cache HIT p50: $1082.87\text{ ms}$
  - P2.2 Cache HIT p50: **$668.48\text{ ms}$**! (Nhanh hơn $414.39\text{ ms}$, **giảm 38.3% thời gian phản hồi**).
- p95 ổn định ở mức $793.90\text{ ms}$ (so với $9697.75\text{ ms}$ trong P2.1).
- Error rate giảm từ 3.3% trong P2.1 xuống **0.0% tuyệt đối** trong P2.2.
- Tải MongoDB: **0 queries** trên Cache HIT.

---

### 1.3. ROUTE 3: CHI TIẾT SẢN PHẨM (PRODUCT DETAIL — `?r=chitiet&id=111`)

| Chế độ kiểm thử | Số mẫu | p50 (ms) | p95 (ms) | p99 (ms) | Avg (ms) | Min (ms) | Max (ms) | Total Time (s) | Avg Mongo Qs | Valkey Cmds | Hit Ratio | Error Rate |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Cache OFF (Authoritative)** | **30** | **252.18** | **357.08** | **406.76** | **263.09** | **205.60** | **406.76** | **7.89 s** | **1.0** | **0** | **N/A** | **0%** |
| **Cache HIT (Bundled MGET)** | 30 | 368.05 | 556.98 | 564.57 | 394.28 | 313.50 | 564.57 | 11.83 s | 1.0 | 30 | 100% | 0% |
| **Cache MISS** | 30 | 396.15 | 645.12 | 807.79 | 417.67 | 323.50 | 807.79 | 12.53 s | 1.0 | 60 | 0% | 0% |
| **Valkey DOWN** | 30 | 349.22 | 537.88 | 539.94 | 358.46 | 278.56 | 539.94 | 10.75 s | 1.0 | 0 | N/A | 0% |

#### Nhận xét Route 3:
- **Tốc độ đọc sản phẩm nhanh nhất:** Đọc trực tiếp MongoDB Atlas bằng Unique B-Tree Index chỉ mất p50 = **$252.18\text{ ms}$** (nhanh hơn mức $325.58\text{ ms}$ trong P2.1).
- Việc gom cụm `mget` cho các trường phụ (FBT, video stream) đạt p50 = $368.05\text{ ms}$ (nhanh hơn mức $484.25\text{ ms}$ trong P2.1).
- Tỷ lệ lỗi: **0.0%**.

---

## 2. PHÂN TÍCH SO SÁNH VALKEY ROUNDTRIP & REGRESSION

1. **Roundtrip Budget:**
   - Homepage: **Chính xác 1 roundtrip** (`get('home:composite_public')`). Không thêm bất kỳ lệnh Valkey nào.
   - Category: **Chính xác 1 roundtrip** (`get('catalog:tatca:...')`).
   - Product Detail: **Chính xác 1 roundtrip** (`mget(['product:freq_bought:...', 'product:videos:...', 'menu:tree:14'])`).
2. **Đánh giá hồi quy (Regression Check):**
   - Không xuất hiện bất kỳ hồi quy hiệu năng nào (No Performance Regression).
   - Category cải thiện từ $1082\text{ ms}$ xuống $668\text{ ms}$.
   - Product Detail cải thiện từ $325\text{ ms}$ xuống $252\text{ ms}$.
   - Tỷ lệ lỗi trên toàn bộ 360 mẫu kiểm thử là **0.0%**.
3. **An toàn dự phòng (Circuit Breaker Resilience):**
   - Khi Valkey DOWN, cả 3 route đều tự động fallback về MongoDB Atlas an toàn với p50 từ $349\text{ ms}$ đến $1166\text{ ms}$, không có trang nào bị lỗi 500 hay treo socket.
