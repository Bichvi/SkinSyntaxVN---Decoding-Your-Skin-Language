# BÁO CÁO KIỂM THỬ HỒI QUY (REGRESSION RESULTS)
## SkinSyntaxVN — Homepage Recommendation UX Phase 2

Kiểm thử hồi quy toàn diện hệ thống sau khi áp dụng các thay đổi Phase 2:

---

### 1. Kiểm tra trạng thái HTTP các Endpoint chính

| Endpoint | Đường dẫn kiểm tra | Mã trạng thái HTTP | Đánh giá |
|---|---|---|---|
| **Trang chủ (Homepage)** | `index.php?r=home` | **200 OK** | Hoạt động bình thường, layout nguyên vẹn, zero PHP notices/errors. |
| **Chi tiết sản phẩm** | `index.php?r=chitiet&id=1` | **200 OK** | Render đầy đủ thông tin sản phẩm và khối gợi ý liên quan. |
| **Tìm kiếm sản phẩm** | `index.php?r=tatca&q=serum` | **200 OK** | Xử lý truy vấn tìm kiếm danh mục chính xác. |

---

### 2. Bất biến Tham số Thuật toán (Algorithm Invariants)

| Thành phần thuật toán | Thông số kỹ thuật bất biến | Giá trị kiểm tra | Kết luận |
|---|---|---|---|
| **Simple Recommender (IMDb WR)** | $m = 21.0$ (P75 vote threshold)<br>$C = 4.8890$ (Global mean rating) | $m = 21.0$<br>$C = 4.8890$ | **UNCHANGED** |
| **TF-IDF Feature Representation** | Cấu hình B: Tên SP + Danh mục + Công dụng + Thành phần | Cấu hình B đông cứng | **UNCHANGED** |
| **Behavior Blending Weights** | Giỏ hàng (Cart): $0.35$<br>Vừa xem (View): $0.35$<br>Tìm kiếm (Search): $0.20$<br>Lịch sử mua (Purchase): $0.10$ | `cart: 0.35`<br>`view: 0.35`<br>`search: 0.20`<br>`purchase: 0.10` | **UNCHANGED** |
| **Hybrid Blending Weight ($\alpha$)** | $\alpha = 0.50$ ($50\%$ Behavior $+ 50\%$ Profile) | $\alpha = 0.50$ | **UNCHANGED** |
| **Scoring Rerank Weights** | Content Similarity: $70\%$ ($0.70$)<br>Skin Type Match: $20\%$ ($0.20$)<br>Budget Score: $10\%$ ($0.10$) | `content: 0.70`<br>`skin: 0.20`<br>`budget: 0.10` | **UNCHANGED** |

---

### 3. Cách ly Thuật toán Nghiên cứu (Research Isolation Audit)

Kiểm toán toàn bộ cây gọi hàm (call graph) của production code:
- `backend/app/controllers/HomeController.php`
- `backend/app/models/SanPham.php`
- `backend/app/services/ContentBasedRecommender.php`

**Kết quả kiểm tra:**
- Số lượng lệnh gọi `K-Means`: **0**
- Số lượng lệnh gọi `Apriori / FP-Growth`: **0**
- Số lượng lệnh gọi `Collaborative Filtering (BPR / Funk MF)`: **0**
- Cơ chế Fallback an toàn: Khi session không có tín hiệu hoặc dữ liệu rỗng, hệ thống luôn fallback về `SanPham::getSimpleRecommenderProducts(4)` (IMDb WR).
