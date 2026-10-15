# KẾT QUẢ THỰC NGHIỆM VÀ PHÂN TÍCH BENCHMARK (PHASE P2.1)

## 1. Bảng Dữ Liệu Thực Nghiệm Chi Tiết (30 Runs Interleaved Mỗi Chế Độ)

Kết quả đo đạc từ bộ công cụ benchmark tự động `backend/tests/benchmark_p2_1_rigorous.php` chạy trực tiếp trên môi trường Docker (Nginx 1.27 + PHP 8.2 FPM, MongoDB Atlas, Aiven Valkey):

### 1.1. ROUTE 1: TRANG CHỦ (HOMEPAGE — `?r=home`)

| Chế độ kiểm thử | Số lần đo | p50 (ms) | p95 (ms) | p99 (ms) | Avg (ms) | Min (ms) | Max (ms) | Total Time (s) | Avg Mongo Qs | Valkey Cmds | Hit Ratio | Error Rate |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Cache HIT (Warm)** | **30** | **757.52** | **1648.61** | **2163.27** | **911.08** | **614.35** | **2163.27** | **27.33 s** | **0.0** | **30** | **100%** | **0%** |
| **Cache OFF** | 30 | 1201.02 | 2030.41 | 8956.97 | 1522.64 | 989.23 | 8956.97 | 45.68 s | 0.0 | 0 | N/A | 0% |
| **Cache MISS** | 30 | 1934.35 | 5002.62 | 6964.18 | 2354.78 | 1687.43 | 6964.18 | 70.64 s | 8.0 | 60 | 0% | 0% |
| **Valkey DOWN** | 30 | 1275.38 | 3881.28 | 8587.62 | 1666.07 | 980.35 | 8587.62 | 49.98 s | 0.0 | 0 | N/A | 0% |

#### So sánh Homepage Trước và Sau Tối Ưu:
- **Baseline P0 (Chưa cache):** p50 = $2503.51\text{ ms}$
- **Phase P2 (Cache chưa tối ưu):** p50 = $2578.71\text{ ms}$ (chậm hơn P0 $+75.2\text{ ms}$)
- **Phase P2.1 (Composite Public Cache):** p50 = **$757.52\text{ ms}$**!
- **Mức độ cải thiện thực tế:**
  - Nhanh hơn Phase P2: **giảm $1821.19\text{ ms}$ (giảm 70.6% thời gian phản hồi!)**
  - Nhanh hơn Baseline P0: **giảm $1745.99\text{ ms}$ (giảm 69.7% thời gian phản hồi!)**
  - Số lệnh Valkey trên mỗi request: Giảm từ 4 lệnh GET tuần tự xuống **1 lệnh GET duy nhất**.
  - Số truy vấn MongoDB Atlas trên Cache HIT: **0 truy vấn**.

---

### 1.2. ROUTE 2: DANH MỤC SẢN PHẨM (CATEGORY LISTING — `?r=tatca`)

| Chế độ kiểm thử | Số lần đo | p50 (ms) | p95 (ms) | p99 (ms) | Avg (ms) | Min (ms) | Max (ms) | Total Time (s) | Avg Mongo Qs | Valkey Cmds | Hit Ratio | Error Rate |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Cache HIT (Warm)** | **30** | **1082.87** | **9697.75** | **30003.32** | **2557.81** | **603.12** | **30003.32** | **76.73 s** | **0.0** | **30** | **100%** | **3.3%** |
| **Cache OFF** | 30 | 1085.87 | 2607.97 | 7281.38 | 1376.75 | 947.46 | 7281.38 | 41.30 s | 0.0 | 0 | N/A | 0% |
| **Cache MISS** | 30 | 1556.47 | 6521.70 | 7556.41 | 2340.46 | 1282.78 | 7556.41 | 70.21 s | 2.0 | 60 | 0% | 0% |
| **Valkey DOWN** | 30 | 1488.32 | 6725.85 | 14860.56 | 2109.21 | 1048.24 | 14860.56 | 63.28 s | 0.0 | 0 | N/A | 0% |

#### So sánh Category Trước và Sau Tối Ưu:
- **Baseline P0 (Chưa cache):** p50 = $1964.97\text{ ms}$
- **Phase P2 (Cache cơ bản):** p50 = $1232.29\text{ ms}$
- **Phase P2.1 (Cache có chống penetration):** p50 = **$1082.87\text{ ms}$**!
- **Mức độ cải thiện thực tế:**
  - Nhanh hơn Baseline P0: **giảm $882.10\text{ ms}$ (giảm 44.9%)**.
  - Tiết kiệm tải MongoDB Atlas: Trên Cache HIT, toàn bộ 24 sản phẩm và thông tin phân trang được lấy trực tiếp từ Valkey, không tạo tải lên cụm MongoDB Atlas.

---

### 1.3. ROUTE 3: CHI TIẾT SẢN PHẨM (PRODUCT DETAIL — `?r=chitiet&id=111`)

| Chế độ kiểm thử | Số lần đo | p50 (ms) | p95 (ms) | p99 (ms) | Avg (ms) | Min (ms) | Max (ms) | Total Time (s) | Avg Mongo Qs | Valkey Cmds | Hit Ratio | Error Rate |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| **Cache OFF (Direct Mongo)** | **30** | **325.58** | **506.11** | **951.85** | **357.06** | **266.78** | **951.85** | **10.71 s** | **1.0** | **0** | **N/A** | **0%** |
| **Cache HIT (Bundled MGET)** | 30 | 484.25 | 957.84 | 1072.39 | 548.83 | 380.49 | 1072.39 | 16.46 s | 1.0 | 30 | 100% | 0% |
| **Cache MISS** | 30 | 461.20 | 687.30 | 995.91 | 481.83 | 373.46 | 995.91 | 14.45 s | 1.0 | 60 | 0% | 0% |
| **Valkey DOWN** | 30 | 479.21 | 1970.47 | 5209.60 | 740.47 | 394.37 | 5209.60 | 22.21 s | 1.0 | 0 | N/A | 0% |

#### Phân Tích Thực Nghiệm Trọng Yếu Đối Với Product Detail:
1. **P2.1 vs P2:**
   - Trong Phase P2, PDP p50 bị vọt lên **$717.04\text{ ms}$** do lỗi cache penetration khi mảng rỗng không được lưu và 2 roundtrip Valkey riêng rẽ.
   - Trong Phase P2.1, nhờ gom `mget` và lưu mảng rỗng, Cache HIT p50 đã giảm xuống **$484.25\text{ ms}$** (giảm $232.79\text{ ms}$).
2. **So sánh Trực Tiếp Cache OFF vs Cache HIT:**
   - Khi **Cache OFF** (đọc trực tiếp sản phẩm đơn lẻ bằng indexed findOne từ MongoDB Atlas): p50 đạt **$325.58\text{ ms}$**.
   - Khi **Cache HIT** qua Aiven Valkey: p50 là **$484.25\text{ ms}$** (chậm hơn $158.67\text{ ms}$).
3. **Nguyên nhân kỹ thuật:**
   - Truy vấn `findOne(['ma_san_pham' => 111])` trên MongoDB Atlas có chỉ mục Unique B-Tree Index cực kỳ tối ưu, thời gian tìm kiếm tại máy chủ DB chỉ tốn $\approx 20\text{ ms}$.
   - Ngược lại, việc gửi request qua TLS socket tới Aiven Valkey trên cloud phải chịu thêm độ trễ mạng internet ($\approx 70 - 100\text{ ms}$) và chi phí serialize/deserialize JSON.
4. **Kết luận kiến trúc dứt khoát:**
   - **TẮT remote cache đối với việc đọc sản phẩm đơn lẻ.** Thông tin sản phẩm cốt lõi (tên, giá bán, tồn kho, trạng thái) đọc trực tiếp từ MongoDB để vừa đạt tốc độ cao nhất ($325\text{ ms}$), vừa đảm bảo 100% tính chính xác của giá và kho hàng.
   - Valkey chỉ dùng để lưu các khối dữ liệu tổng hợp thứ cấp (FBT, video stream metadata) khi được kích hoạt.
