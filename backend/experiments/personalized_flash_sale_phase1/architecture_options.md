# COMPARATIVE EVALUATION OF FLASH SALE ARCHITECTURE OPTIONS

**Dự án:** SkinSyntaxVN — Decoding Your Skin Language  
**Mục tiêu:** So sánh khách quan 3 phương án kiến trúc xếp hạng Flash Sale (Option A, Option B, Option C) dựa trên mã nguồn thực tế, độ khả thi dữ liệu, độ phức tạp triển khai và tính bảo vệ học thuật.  
**Môi trường:** Production Codebase & Academic Capstone Defense  
**Thời gian thực hiện:** Tháng 10/2026  

---

## 1. Tổng Quan 3 Phương Án Kiến Trúc

```
┌────────────────────────────────────────────────────────────────────────┐
│ OPTION A: SIMPLE DEAL RANKING                                          │
│ Nguyên lý: Xếp hạng tĩnh không cá nhân hóa                             │
│ Công thức: Score = w1 * DiscountStrength + w2 * SocialProofRating     │
└────────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────────┐
│ OPTION B: SINGLE-PASS PERSONALIZED DEAL RANKING                        │
│ Nguyên lý: Ghép trực tiếp Recommender Score với Discount trong 1 pass   │
│ Công thức: Score = w1 * Relevance + w2 * Discount + w3 * Rating        │
└────────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────────┐
│ OPTION C: TWO-STAGE HYBRID PIPELINE (ELIGIBILITY -> RANK -> MERCH)     │
│ Nguyên lý: Phân tầng tách bạch nghiệp vụ kinh doanh và AI              │
│ Stage 1: Campaign Eligibility Gate (Active, Stock > 0, Admin Selection)│
│ Stage 2: Personalized Relevance Scoring (Context Router States A-E)    │
│ Stage 3: Merchandising Tie-Break (Discount Strength, Image Quality)    │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Bảng So Sánh Chi Tiết Toàn Diện

| Tiêu chí đánh giá | OPTION A: Simple Deal Ranking | OPTION B: Single-Pass Personalized Ranking | OPTION C: Two-Stage Hybrid Pipeline |
| :--- | :--- | :--- | :--- |
| **Bản chất thuật toán** | Rule-based tĩnh kết hợp xếp hạng theo discount & rating | Ghép tuyến tính 1 tầng giữa điểm Content Recommender và % Giảm | Phân tầng 2 giai đoạn: Lọc điều kiện kinh doanh &rarr; Chấm điểm cá nhân hóa &rarr; Merchandising Tie-break |
| **Mức độ cá nhân hóa** | **0%** (Mọi user thấy chung 1 thứ tự giống hệt nhau) | **Trung bình** (Có cá nhân hóa nhưng dễ bị % giảm lấn át hoặc ngược lại) | **Cao & Kiểm soát được** (Cá nhân hóa theo đúng nhu cầu da, đồng thời tôn trọng mục tiêu bán lẻ) |
| **Khả năng giải thích (Explainability)** | Chỉ giải thích được: *"Đang giảm mạnh"* hoặc *"Được đánh giá tốt"* | Dễ bị mâu thuẫn tem (ví dụ: deal giảm sâu nhưng không hợp da lại đứng đầu) | **Tuyệt đối minh bạch**: Tem lý do phản ánh đúng tín hiệu kích hoạt (Khớp loại da, Vừa xem, v.v.) |
| **Yêu cầu dữ liệu thực tế** | Chỉ cần % giảm và số sao đánh giá (Đã có sẵn trên 1.788 SKUs) | Cần nạp vector TF-IDF và lịch sử session của user | Tận dụng toàn bộ hạ tầng `ContentBasedRecommender` đã được tối ưu cache |
| **Độ trễ xử lý (Latency)** | Cực nhanh: $< 2\text{ ms}$ (Query sort trực tiếp trên 12–20 SKUs) | Nhanh: $\approx 5 - 10\text{ ms}$ trên tập candidate 12–20 items | Tối ưu: $\approx 8 - 12\text{ ms}$ (Chỉ chấm điểm trên đúng 12–20 SKUs, không quét 2.473 SKUs) |
| **Rủi ro rò rỉ giá / xung đột** | Thấp | Trung bình (Dễ can thiệp nhầm vào recommender chính) | **Rất thấp** (Phân định rõ ràng: Campaign quản lý Price, Engine quản lý Ranking) |
| **Độ phức tạp triển khai (Complexity)**| Rất thấp (1–2 ngày) | Trung bình (3–4 ngày) | Vừa phải, rất chuẩn kiến trúc phần mềm chuyên nghiệp (4–5 ngày) |
| **Tính vững chắc khi bảo vệ đồ án (Academic Defensibility)** | **YẾU**: Giám khảo sẽ nhận xét không có yếu tố cá nhân hóa / AI, chỉ là lọc discount thông thường | **TRUNG BÌNH**: Bị chất vấn về việc trộn lẫn điểm Cosine Similarity với % Giảm tiền một cách tùy tiện | **RẤT MẠNH**: Kiến trúc phân tầng (Two-stage candidate filtering & ranking) là chuẩn mực công nghiệp của các hệ thống Recommender lớn (YouTube, Netflix, Shopee) |

---

## 3. Đánh Giá Chuyên Sâu Từng Phương Án

### 3.1 OPTION A: Simple Deal Ranking
* **Ưu điểm:**
  * Cực kỳ đơn giản, không tốn tài nguyên tính toán.
  * Không phụ thuộc vào trạng thái đăng nhập hay lịch sử người dùng.
* **Nhược điểm chí mạng:**
  * **Không giải quyết được bài toán cá nhân hóa của đề tài.** Người da dầu vẫn bị gợi ý kem dưỡng đậm đặc cho da khô chỉ vì sản phẩm đó đang giảm 50%.
  * Giảm tỷ lệ chuyển đổi (CTR/CR) vì deal hiển thị không trúng "nỗi đau" (pain point) của từng loại da.
* **Đánh giá học thuật:** Không đủ hàm lượng khoa học cho đồ án tốt nghiệp chuyên ngành Recommender Systems.

### 3.2 OPTION B: Single-Pass Personalized Ranking
* **Ưu điểm:**
  * Có yếu tố cá nhân hóa, dễ cài đặt bằng 1 hàm tổng hợp duy nhất: $\text{Score} = w_1 \cdot S_{\text{rec}} + w_2 \cdot S_{\text{disc}} + w_3 \cdot S_{\text{pop}}$.
* **Nhược điểm:**
  * Gặp vấn đề nghiêm trọng về **hiệu ứng át chế (Dominance effect)**: Điểm Cosine Similarity biến thiên từ $0.05$ đến $0.60$, trong khi % giảm biến thiên từ $0.10$ đến $0.70$. Nếu không chuẩn hóa chuẩn xác, sản phẩm giảm giá cực sâu sẽ luôn đè bẹp sản phẩm đúng loại da, hoặc ngược lại.
  * Khó kiểm soát các điều kiện biên của kinh doanh (Business constraints) như ưu tiên hình ảnh đẹp, chống lặp thương hiệu trên Homepage.
* **Đánh giá học thuật:** Có thể chấp nhận được nhưng tính ứng dụng thực tế chưa hoàn chỉnh.

### 3.3 OPTION C: Two-Stage Hybrid Pipeline (Khuyến Nghị Cao Nhất)
* **Cơ chế hoạt động:**
  1. **Stage 1 (Eligibility & Business Hard Filters):**
     * Admin xác định tập hợp chiến dịch $C$ gồm 12–20 sản phẩm.
     * Hệ thống lọc bỏ các sản phẩm hết hàng hoặc bị ẩn.
  2. **Stage 2 (Personalized Relevance Scoring):**
     * Nạp Context của người dùng (State A, B, C, D, hoặc E).
     * Áp dụng `ContentBasedRecommender` chỉ trên tập con $C$ (không quét toàn bộ 2.473 sản phẩm của catalog, giúp thời gian tính toán cực kỳ nhanh).
     * Tính điểm $\text{PersonalRelevance}$ và chuẩn hóa trong khoảng $[0.0, 1.0]$.
  3. **Stage 3 (Merchandising & Multi-Objective Tie-Break):**
     * Kết hợp có kiểm soát giữa $\text{PersonalRelevance}$, $\text{DiscountStrength}$, và $\text{SocialProofRating}$.
     * Áp dụng quy tắc Family Diversity (không để 4 sản phẩm cùng một dòng dưỡng xuất hiện đồng thời trên Homepage).
     * Áp dụng Presentation Heuristic: Ưu tiên deal có ảnh hiển thị sắc nét cho 4 vị trí mặt tiền Homepage.
* **Tính bảo vệ học thuật:**
  * Thể hiện rõ tư duy kiến trúc phân tầng chuẩn quốc tế: Tách biệt rõ giữa **Retrieval/Filtering** và **Ranking/Reranking**.
  * Chứng minh được tính dung hòa hoàn hảo giữa **Mục tiêu Trải nghiệm Người dùng (User Relevance)** và **Mục tiêu Kinh doanh (Commercial Promotional Objectives)**.
