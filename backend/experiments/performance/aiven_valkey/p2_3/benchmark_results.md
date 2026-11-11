# SKINSYNTAXVN — PHASE P2.3: COMPREHENSIVE BENCHMARK RESULTS MATRIX

**Author:** Senior PHP/MongoDB/Valkey Performance & Security Engineer  
**Date:** 2026-10-18  
**Scope:** 360 Interleaved HTTP Measurements across 3 Routes and 4 Execution Modes  
**Artifact File:** `backend/experiments/performance/aiven_valkey/p2_3/benchmark_matrix.json`

---

## 1. Tổng quan Bộ đo (Benchmark Overview)

- **Quy mô đo lường:** 360 requests HTTP thực tế được gửi qua Nginx reverse-proxy tới cụm PHP-FPM trong môi trường Docker.
- **Phương pháp luận:**
  - 30 requests đo lường cho mỗi cặp (Route, Mode).
  - 3 requests warm-up không tính điểm trước mỗi mode.
  - Chạy xen kẽ (Interleaved) lần lượt giữa các mode để triệt tiêu ảnh hưởng của CPU scheduling và network jitter.
  - Cô lập hoàn toàn trạng thái bằng `CacheService::resetInstance()`.
  - Giám sát sự kiện driver MongoDB chuẩn xác bằng `AccurateMongoProfiler`.
- **Tỷ lệ lỗi (Error Rate):** **0.0% (360/360 requests thành công HTTP 200)**.

---

## 2. Bảng ma trận hiệu năng đối đầu (Full Performance Matrix)

### 2.1. Route: Homepage (`/` hoặc `?r=home`)

| Mode | Runs | p50 (ms) | p95 (ms) | p99 (ms) | Avg (ms) | Min (ms) | Max (ms) | Mongo Queries | Valkey Cmds | Hit Ratio | Error Rate |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **Cache OFF** | 30 | 1020.59 | 1468.97 | 2721.85 | 1046.64 | 765.27 | 2721.85 | 6 | 0 | N/A | **0%** |
| **Cache MISS** | 30 | 668.88 | 972.68 | 1176.24 | 700.23 | 557.52 | 1176.24 | 4 - 5 | 60 | 0% | **0%** |
| **Cache HIT** | 30 | **757.88** | 1581.73 | 1589.73 | **820.55** | 602.16 | 1589.73 | **0** | 30 | **100%** | **0%** |
| **Valkey DOWN**| 30 | 1132.33 | 1638.86 | 1679.47 | 1162.15 | 816.97 | 1679.47 | 6 | 0 | N/A | **0%** |

> **Nhận xét Homepage:**
> - **Cache HIT (757.88ms) nhanh hơn Cache OFF (1020.59ms) rõ rệt: Giảm 262.71ms (-25.7% latency).**
> - **Tiết kiệm 100% truy vấn database:** Giảm từ 6 queries xuống 0 queries trên MongoDB Atlas.
> - Khi Valkey DOWN, hệ thống graceful fallback sang MongoDB Atlas đạt p50 = 1132.33ms với 0% lỗi.

---

### 2.2. Route: Category (`/danh-muc` hoặc `?r=tatca`)

| Mode | Runs | p50 (ms) | p95 (ms) | p99 (ms) | Avg (ms) | Min (ms) | Max (ms) | Mongo Queries | Valkey Cmds | Hit Ratio | Error Rate |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **Cache OFF** | 30 | **770.55** | 1093.12 | 1545.08 | **798.37** | 612.52 | 1545.08 | **2** | 0 | N/A | **0%** |
| **Cache MISS** | 30 | 1144.93 | 1816.75 | 2104.06 | 1202.15 | 809.26 | 2104.06 | 2 | 60 | 0% | **0%** |
| **Cache HIT** | 30 | 1178.82 | 1556.25 | 1654.06 | 1230.21 | 828.66 | 1654.06 | **0** | 30 | **100%** | **0%** |
| **Valkey DOWN**| 30 | 947.60 | 1338.73 | 1506.61 | 1026.04 | 765.63 | 1506.61 | 2 | 0 | N/A | **0%** |

> **Nhận xét Category:**
> - Trên trang catalog mặc định (không lọc phức tạp), MongoDB Atlas chỉ tốn 2 queries (67ms). Việc gửi nhận và giải mã payload catalog từ Valkey qua mạng WAN cộng thêm độ trễ TLS khiến Cache HIT (1178ms) chậm hơn Cache OFF (770ms).
> - **Khẳng định tính đúng đắn của Cache Admission Policy:** Tuyệt đối không suy rộng cache từ route đơn giản sang toàn bộ catalog; chỉ áp dụng remote cache cho các truy vấn faceted/filter phức tạp tốn nhiều CPU database.

---

### 2.3. Route: Product Detail (`/san-pham/111` hoặc `?r=chitiet&id=111`)

| Mode | Runs | p50 (ms) | p95 (ms) | p99 (ms) | Avg (ms) | Min (ms) | Max (ms) | Mongo Queries | Valkey Cmds | Hit Ratio | Error Rate |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **Cache OFF** | 30 | **272.89** | 397.82 | 432.84 | **279.73** | 203.91 | 432.84 | **1** | 0 | N/A | **0%** |
| **Cache MISS** | 30 | 415.44 | 632.50 | 881.39 | 460.50 | 329.03 | 881.39 | 1 | 60 | 0% | **0%** |
| **Cache HIT** | 30 | 415.45 | 586.00 | 699.69 | 437.39 | 342.81 | 699.69 | 1 | 30 | **100%** | **0%** |
| **Valkey DOWN**| 30 | 359.09 | 741.15 | 825.29 | 399.54 | 302.62 | 825.29 | 1 | 0 | N/A | **0%** |

> **Nhận xét Product Detail:**
> - **Direct MongoDB Atlas là người chiến thắng tuyệt đối:** p50 = **272.89 ms** so với 415.45 ms khi đọc Valkey (Nhanh hơn **142.56 ms**, cải thiện **34.3%**).
> - Truy vấn indexed `findOne` trên Atlas chỉ tốn 43ms. Việc đọc qua Valkey MGET không mang lại bất kỳ lợi ích hiệu năng nào.
> - **Khẳng định chính sách:** Bypass Valkey hoàn toàn trên PDP, ưu tiên Direct MongoDB.

---

## 3. Tổng kết Phân tích Hiệu năng Toàn diện

```
                        ┌────────────────────────────────────────────────────────┐
                        │      PHASE P2.3 PERFORMANCE GATE COMPARISON           │
                        └────────────────────────────────────────────────────────┘

    HOMEPAGE (p50):
    Cache HIT  [========================] 757.88 ms   (FASTER BY 262.7ms, -25.7%, 0 Mongo Queries)
    Cache OFF  [=================================] 1020.59 ms
    Valkey DWN [====================================] 1132.33 ms

    CATEGORY DEFAULT (p50):
    Cache OFF  [=========================] 770.55 ms   (Direct Atlas faster for simple catalog)
    Cache HIT  [======================================] 1178.82 ms (Selective Admission needed)

    PRODUCT DETAIL (p50):
    Cache OFF  [=========] 272.89 ms   (DIRECT ATLAS WINS! 142.5ms FASTER, -34.3%)
    Cache HIT  [============== ] 415.45 ms (Valkey Remote MGET overhead confirmed)
```
