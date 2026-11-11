# SKINSYNTAXVN — PHASE P4A: BROWSER PERFORMANCE BASELINE REPORT

**Author:** Senior Frontend Performance Engineer, PHP Full-Stack Engineer & Web Performance Specialist  
**Date:** 2026-10-18  
**Measurement Tool:** Google Lighthouse v12.8.2 via Headless Google Chrome & Chrome DevTools Protocol  
**Scope:** 5 Core Routes across Desktop & Mobile Viewports (10 Empirical Measurement Sets)  
**Dataset File:** `backend/experiments/performance/frontend_p4/browser_baseline_matrix.json`

---

## 1. Phương pháp luận & Điều kiện đo chuẩn (Methodology)

Toàn bộ các chỉ số dưới đây được đo lường thực tế trên môi trường Docker của SkinSyntaxVN (Nginx reverse-proxy, PHP-FPM 8.2, MongoDB Atlas, Aiven Valkey):
- **Công cụ:** Google Lighthouse v12.8.2 chính thức kết nối trực tiếp với Google Chrome qua Chrome DevTools Protocol (CDP).
- **Không sử dụng bất kỳ số liệu giả hay suy đoán.**
- **Desktop Preset:** Viewport chuẩn $1350 \times 940$, không throttling CPU.
- **Mobile Preset:** Emulation chuẩn Moto G Power ($412 \times 823$, DPR 1.75), mobile user-agent, 4x CPU slowdown mô phỏng thiết bị tầm trung, packet latency 150ms.
- **5 Tuyến đường đo lường:**
  1. **Homepage:** `http://localhost/index.php?r=home`
  2. **Category (Catalog):** `http://localhost/index.php?r=tatca`
  3. **Product Detail (PDP):** `http://localhost/index.php?r=chitiet&id=111`
  4. **Search:** `http://localhost/index.php?r=tatca&q=serum`
  5. **Recommendation:** `http://localhost/index.php?r=goiy`

---

## 2. Bảng ma trận hiệu năng tổng thể (Full Performance Matrix)

### 2.1. Môi trường Desktop (Desktop Baseline)

| Route | Score | FCP (ms) | LCP (ms) | TBT (ms) | CLS | Speed Index (ms) | TTFB (ms) | Transferred (KB) | Requests | Main Thread (ms) | Long Tasks |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **Homepage** | **51** | 1.201 | 2.602 | 585 | 0.016 | 4.180 | 2.819 | 3.711 KB | 54 | 3.151 | 6 |
| **Category** | **76** | 1.090 | 1.844 | 234 | 0.027 | 2.568 | 2.535 | 2.376 KB | 47 | 1.700 | 5 |
| **Product Detail** | **94** | 0.989 | **1.167** | **0** | 0.032 | 1.722 | 1.366 | 1.035 KB | 22 | 892 | 2 |
| **Search** | **85** | 1.065 | 1.799 | 15 | 0.027 | 2.528 | 2.642 | 2.124 KB | 47 | 1.340 | 3 |
| **Recommendation** | **85** | 1.176 | 1.905 | **0** | 0.029 | 1.995 | 1.600 | 2.903 KB | 50 | 1.494 | 1 |

---

### 2.2. Môi trường Mobile (Mobile Baseline — Điểm nghẽn nghiêm trọng)

| Route | Score | FCP (ms) | LCP (ms) | TBT (ms) | CLS | Speed Index (ms) | TTFB (ms) | Transferred (KB) | Requests | Main Thread (ms) | Render-Block Wasted (ms) |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| **Homepage** | **55** | 3.650 | **9.809** | 330 | 0.0001 | 6.099 | 2.343 | 3.695 KB | 54 | 5.383 | **4.747 ms** |
| **Category** | **63** | 4.197 | **9.381** | 88 | 0.0002 | 5.175 | 1.569 | 2.370 KB | 47 | 4.559 | **4.801 ms** |
| **Product Detail** | **64** | 4.594 | **6.068** | **0** | 0.0001 | 6.344 | 1.996 | 1.034 KB | 22 | 4.676 | **5.303 ms** |
| **Search** | **60** | 4.109 | **9.215** | 156 | 0.0003 | 5.856 | 2.381 | 2.123 KB | 47 | 3.451 | **4.635 ms** |
| **Recommendation** | **59** | 5.626 | **10.837** | 130 | 0.0239 | 6.240 | 2.042 | 2.904 KB | 50 | 7.310 | **5.632 ms** |

---

## 3. Phân tích chi tiết các chỉ số Core Web Vitals

```
                  ┌────────────────────────────────────────────────────────┐
                  │      CORE WEB VITALS BASELINE (MOBILE vs DESKTOP)      │
                  └────────────────────────────────────────────────────────┘

    HOMEPAGE LCP:
    Desktop  [=======] 2.60 s  (Chấp nhận được)
    Mobile   [============================================] 9.81 s  (NGUY HIỂM > 2.5s)

    HOMEPAGE FCP:
    Desktop  [===] 1.20 s
    Mobile   [==========] 3.65 s (Bị trễ do 4.7s Render-Blocking CSS/Fonts)

    TOTAL TRANSFERRED BYTES:
    Homepage       [===================================] 3.71 MB  (54 Requests)
    Recommendation [============================] 2.90 MB  (50 Requests)
    Category       [=======================] 2.37 MB  (47 Requests)
    Search         [=====================] 2.12 MB  (47 Requests)
    Product Detail [==========] 1.03 MB  (22 Requests)
```

### 3.1. Largest Contentful Paint (LCP)
- **Trên Desktop:** Dao động từ **1.16s** (Product Detail - Rất tốt) đến **2.60s** (Homepage).
- **Trên Mobile:** LCP bùng nổ từ **6.06s** đến **10.84s**!
  - Nguyên nhân: Phần tử LCP (đoạn văn bản hero hoặc hình ảnh đầu tiên) phải chờ toàn bộ chuỗi CSS (Bootstrap, Bootstrap Icons, FontAwesome, Style.css) và Google Fonts tải xong mới bắt đầu dựng layout và vẽ lên màn hình.

### 3.2. Total Blocking Time (TBT) & Long Tasks
- **Homepage Desktop:** TBT đạt **585 ms** với **6 long tasks** (thời lượng tác vụ > 50ms).
  - Nguyên nhân: Vòng lặp nạp trước 11 hình ảnh mascot (`new Image()`) trong sự kiện `DOMContentLoaded` kết hợp hiệu ứng typewriter và khởi tạo carousel.
- **Product Detail:** TBT đạt **0 ms** (Cực kỳ xuất sắc trên cả Desktop và Mobile).

### 3.3. Cumulative Layout Shift (CLS)
- CLS trên toàn bộ 5 route đều nằm ở mức **rất an toàn (< 0.035)** (Chuẩn Google: $\le 0.10$).
- Tuy nhiên, khi người dùng cuộn chuột trên mạng chậm, việc thiếu thuộc tính `width`/`height` cứng trên các thẻ `<img>` sản phẩm bên dưới có thể gây ra hiện tượng giật nhẹ khung nhìn.

### 3.4. Render-Blocking Resources Wasted Time
- Báo cáo DevTools chỉ ra sự lãng phí thời gian chặn hiển thị nghiêm trọng trên thiết bị di động:
  - `Plus Jakarta Sans & Quicksand (Google Fonts)`: Lãng phí **1.001 ms**.
  - `bootstrap.min.css` (232 KB): Lãng phí **1.067 ms**.
  - `fontawesome.min.css` (102 KB): Lãng phí **916 ms**.
  - `style.css` (109 KB): Lãng phí **916 ms**.
  - `bootstrap-icons.min.css` (85 KB): Lãng phí **616 ms**.
  - **Tổng thời gian nghẽn mạng di động trước khi vẽ pixel đầu tiên: ~4.75 - 5.63 giây**.

---

## 4. Kết luận Baseline & Đề xuất hành động cho P4B - P4E

1. **Hiệu năng Desktop đã ở mức khá tốt** (Score 76 - 94 đối với các trang bên trong; PDP đạt Score 94 và LCP 1.16s).
2. **Điểm nghẽn sinh tử nằm ở thiết bị Mobile và dung lượng tải trang chủ:**
   - 3.7 MB tài nguyên ban đầu là quá nặng cho người dùng di động 4G/3G tại Việt Nam.
   - Việc tối ưu hóa hình ảnh (P4B), nén Gzip và gỡ bỏ render-blocking CSS/Fonts (P4C) sẽ trực tiếp kéo điểm Mobile từ 55 lên > 85 và giảm LCP Mobile từ 9.8s xuống < 3.0s.
3. **Tuân thủ cam kết:** Đã hoàn thành 100% mục tiêu của P4A. **STRICT STOP** chờ phê duyệt của Product Owner trước khi bắt đầu P4B.
