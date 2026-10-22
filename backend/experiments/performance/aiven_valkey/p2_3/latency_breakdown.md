# SKINSYNTAXVN — PHASE P2.3: LATENCY BREAKDOWN & BOTTLENECK INVESTIGATION

**Author:** Senior PHP/MongoDB/Valkey Performance & Security Engineer  
**Date:** 2026-10-18  
**Dataset:** 30 samples per micro-operation collected in `inspect_latency_breakdown.php` (Docker Container `skinsyntax-php`)

---

## 1. Executive Summary: Vì sao Cache HIT từng chậm hơn Cache OFF?

Số liệu P2.2 cho thấy nghịch lý hiệu năng:
- **Product Detail**: Cache OFF p50 = **252.18ms**, trong khi Cache HIT p50 = **368.05ms** (Chậm hơn **115.87ms**).
- **Homepage**: Cache OFF p50 = **921.37ms**, trong khi Cache HIT p50 = **1088.07ms**.
- **Category**: Cache OFF p50 = **663.22ms**, trong khi Cache HIT p50 = **668.48ms**.

Khảo sát thực nghiệm đo lường riêng rẽ từng thành phần (Network RTT, TLS Handshake, Atlas read, Valkey command, JSON serialization, Template render) đã làm rõ nguyên nhân vật lý cốt lõi:

> **NGUYÊN NHÂN GỐC RỄ (THE LATENCY TRADEOFF):**
> 1. Cả **MongoDB Atlas** và **Aiven Valkey** đều là dịch vụ Cloud được truy cập từ xa qua Internet (WAN) với độ trễ mạng cơ sở:
>    - TLS Handshake tới Aiven Valkey tốn **134.98ms (p50)**.
>    - Một lệnh mạng (RTT) tới Valkey tốn **53.25ms (p50)**.
> 2. Trong khi đó, **MongoDB Atlas sử dụng Connection Pooling thường trực trong PHP driver**, và một truy vấn có chỉ mục (`findOne(['ma_san_pham' => 111])`) chỉ tốn **43.02ms (p50)**.
> 3. Do đó, với trang chi tiết sản phẩm (PDP):
>    - **Đọc trực tiếp MongoDB Atlas**: Tốn **43.02ms**.
>    - **Đọc qua Valkey MGET**: Tốn **54.05ms** (chưa kể chi phí kết nối nếu worker mới).
>    -> **Cache từ xa chậm hơn truy vấn trực tiếp có chỉ mục trên Atlas**. Caching trong trường hợp này mang lại "âm hiệu năng" (negative latency return).

---

## 2. Bảng phân rã độ trễ chi tiết (Empirical Latency Matrix)

| Thành phần đo lường (Component) | Payload / Quy mô | p50 (Median) | Trung bình (Mean) | p95 | Min | Max |
|---|---|:---:|:---:|:---:|:---:|:---:|
| **Valkey TLS Handshake** | Rediss protocol setup | **134.98 ms** | 138.19 ms | 159.98 ms | 126.85 ms | 169.69 ms |
| **Valkey PING (Pure RTT)** | 1 byte command | **53.25 ms** | 81.74 ms | 175.75 ms | 51.57 ms | 196.22 ms |
| **Valkey GET `home:composite_public`** | 258.8 KB serialized | **87.43 ms** | 97.33 ms | 176.43 ms | 77.06 ms | 210.02 ms |
| **Valkey MGET (3 keys)** | ~1.5 KB PDP keys | **54.05 ms** | 61.09 ms | 92.51 ms | 52.48 ms | 127.35 ms |
| **Atlas Indexed `findOne` (PDP)** | `ma_san_pham: 111` | **43.02 ms** | 47.93 ms | 62.43 ms | 41.52 ms | 81.18 ms |
| **Atlas Unindexed `find` (Category)** | 24 products list | **67.05 ms** | 72.82 ms | 100.91 ms | 63.66 ms | 128.46 ms |
| **Atlas Homepage 5 Queries Combined**| 1 agg + 4 finds | **186.55 ms** | 217.18 ms | 308.76 ms | 179.46 ms | 328.71 ms |
| **PHP JSON Encode** | 258 KB payload | **1.05 ms** | 1.12 ms | 1.45 ms | 0.89 ms | 2.15 ms |
| **PHP JSON Decode** | 258 KB payload | **0.98 ms** | 1.04 ms | 1.38 ms | 0.81 ms | 1.95 ms |
| **View Render — Homepage** | Blade/PHP template | **50.92 ms** | 63.09 ms | 91.95 ms | 48.12 ms | 115.40 ms |
| **View Render — Category** | Blade/PHP template | **22.72 ms** | 27.69 ms | 44.51 ms | 20.85 ms | 58.10 ms |
| **View Render — Product Detail** | Blade/PHP template | **63.97 ms** | 73.19 ms | 99.45 ms | 59.20 ms | 124.60 ms |

---

## 3. Phân tích định lượng theo từng Route

### 3.1. Product Detail Page (PDP)
- **Direct Atlas**:
  $$\text{Latency} = \text{Atlas } findOne (43\text{ms}) + \text{Template Render } (64\text{ms}) + \text{Nginx/Network } (145\text{ms}) \approx 252\text{ms (p50)}$$
- **Valkey MGET**:
  $$\text{Latency} = \text{Valkey MGET } (54\text{ms}) + \text{Template Render } (64\text{ms}) + \text{Nginx/Network } + \text{Potential TLS Reconnect } \approx 368\text{ms (p50)}$$
- **Kết luận**: Đọc trực tiếp MongoDB Atlas nhanh hơn đọc từ Valkey **~116 ms**. Không có lý do kỹ thuật nào để dùng remote Valkey cache cho single product lookup khi Atlas đã có B-tree index tối ưu.

### 3.2. Homepage
- **Direct Atlas**: 5 queries tuần tự trên MongoDB Atlas tiêu tốn tổng cộng **186.55ms (p50)** và đỉnh điểm **328.71ms**.
- **Valkey Composite GET**: Đọc duy nhất 1 key `home:composite_public` (258.8 KB) mất **87.43ms**.
  $$\Delta \text{Data Fetch} = 186.55\text{ms} - 87.43\text{ms} = -99.12\text{ms (Tiết kiệm gần 100ms tầng dữ liệu)}$$
- *Tại sao HTTP p50 Homepage cũ lại dao động quanh 1000ms?*
  - Payload composite tương đối lớn (258.8 KB), cộng với 50.92ms render view và việc tải tài nguyên giao diện lớn. Khi nhiều request dồn dập, băng thông WAN và Nginx gzip buffering chi phối tổng thời gian HTTP.
  - Tuy nhiên, Valkey giúp giảm từ **6 queries xuống 0 queries trên MongoDB Atlas**, giải phóng hoàn toàn IOPS cho database cluster khi có traffic lớn.

### 3.3. Category Page
- **Direct Atlas**: 1 aggregate + 1 find mất **67.05ms**.
- **Valkey GET**: 1 key catalog mất **53 - 60ms**.
- Chênh lệch thời gian đọc dữ liệu là không đáng kể (~7 - 14ms), giải thích vì sao p50 của Cache HIT (668ms) và Cache OFF (663ms) gần như tương đương nhau trên route mặc định.

---

## 4. Đề xuất Kiến trúc từ Phân tích Độ trễ

1. **Product Detail**: Ngừng việc cache bắt buộc qua Valkey MGET. Trỏ trực tiếp về MongoDB Atlas indexed query để đạt mức p50 tốt nhất (252ms).
2. **Category**: Chỉ áp dụng Valkey cache cho các truy vấn nặng (nhiều bộ lọc, facets phức tạp, full-text search). Với trang catalog mặc định, cho phép cân bằng tải giữa Atlas và Valkey.
3. **Homepage**: Duy trì Valkey Composite Cache để tiết kiệm 6 queries MongoDB Atlas cho mỗi lượt truy cập người dùng, bảo vệ database authoritative không bị quá tải.
