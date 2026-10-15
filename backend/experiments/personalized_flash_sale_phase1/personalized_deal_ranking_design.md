# PERSONALIZED DEAL RANKING ARCHITECTURE DESIGN

**Dự án:** SkinSyntaxVN — Decoding Your Skin Language  
**Mục tiêu:** Thiết kế kiến trúc xếp hạng ưu đãi cá nhân hóa (Personalized Deal Ranking Pipeline), chiến lược phục vụ 5 trạng thái người dùng (User States A–E), cơ chế chuẩn hóa điểm số, và hệ thống tem lý do giải thích minh bạch (Grounded Reason Tags).  
**Môi trường:** Production Recommender Integration  
**Thời gian thực hiện:** Tháng 10/2026  
**Nguyên tắc:** DESIGN ONLY — Không tự ý gán trọng số tối ưu giả định, không bịa đặt tín hiệu.

---

## 1. Sơ Đồ Kiến Trúc Luồng Xếp Hạng (Ranking Pipeline)

```
┌────────────────────────────────────────────────────────┐
│ 1. ACTIVE CAMPAIGN (Do Admin cấu hình)                 │
│    - Xác định tập 12–20 sản phẩm được phép giảm giá   │
│    - start_at <= ServerNow() < end_at                  │
└────────────────────────────────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│ 2. CANDIDATE SET FILTERING (Lọc tập ứng viên)          │
│    - Kiểm tra trang_thai == 'active'                   │
│    - Kiểm tra so_luong_ton_kho > 0                     │
│    - Tập ứng viên: C = { SKU_1, SKU_2, ..., SKU_k }    │
│      (k = 12 đến 20)                                   │
└────────────────────────────────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│ 3. CONTEXT & USER STATE DETECTOR                       │
│    - Nhận diện 1 trong 5 trạng thái: STATE A, B, C, D, E│
│    - Nạp các bằng chứng thực nghiệm hợp lệ của user   │
└────────────────────────────────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│ 4. MULTI-COMPONENT DEAL SCORING                        │
│    - Personal Relevance Score: S_rel in [0.0, 1.0]     │
│    - Discount Strength Score:  S_disc in [0.0, 1.0]    │
│    - Social Proof Score:       S_pop in [0.0, 1.0]     │
│    - Compute Final Deal Score: Score(SKU, User)        │
└────────────────────────────────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│ 5. PRESENTATION TIE-BREAK & PRODUCT DIVERSITY          │
│    - Phạt trùng lặp dòng sản phẩm (Family Diversity)   │
│    - Ưu tiên ảnh hợp lệ cho mặt tiền Homepage         │
│    - Sinh Grounded Reason Tags tương ứng với tín hiệu  │
└────────────────────────────────────────────────────────┘
                           │
              ┌────────────┴────────────┐
              ▼                         ▼
   [ HOMEPAGE: TOP 4 ]       [ ALL DEALS PAGE: TOÀN BỘ ]
   (Xếp deal tốt nhất trước) (Sort: Cho bạn, Giảm nhiều,...)
```

> **Nguyên tắc phân định ranh giới:**  
> **Campaign quyết định:** SẢN PHẨM NÀO ĐƯỢC GIẢM GIÁ (Candidate Selection). Bộ gợi ý tuyệt đối không được tự ý đưa một sản phẩm ngoài chiến dịch vào Flash Sale.  
> **Ranking quyết định:** MỖI NGƯỜI DÙNG THẤY DEAL NÀO TRƯỚC TIÊN (Presentation Order).

---

## 2. Thiết Kế Chiến Lược 5 Trạng Thái Người Dùng (User States Design)

| Trạng thái người dùng | Bằng chứng thực tế có sẵn | Chiến lược xếp hạng Deal | Tín hiệu sử dụng | Grounded Reason Tag sinh ra |
| :--- | :--- | :--- | :--- | :--- |
| **STATE A — GUEST / COLD START** | Không profile, không lịch sử hành vi session | **Social Proof + Discount Ranking** | $\text{DiscountStrength}$ kết hợp $\text{IMDb Weighted Rating}$ của sản phẩm | `Đang giảm XX%` hoặc `Được đánh giá tốt` |
| **STATE B — BEHAVIOR ONLY** | Có `search_history`, `recent_viewed`, hoặc `cart` trong session | **Behavioral Content-Based** | Vector $U_{\text{behavior}}$ so khớp Cosine Similarity với TF-IDF của 12–20 deal, kết hợp $\text{DiscountStrength}$ | `Dựa trên sản phẩm bạn vừa xem` hoặc `Theo tìm kiếm gần đây` |
| **STATE C — PROFILE ONLY** | Khách đã làm khảo sát hồ sơ da (`loai_da`, `van_de_da`, `ngan_sach`) nhưng chưa có hành vi session hôm nay | **Profile Content-Based** | Vector $V_{\text{profile}}$ + Điểm tương thích loại da (`computeSkinTypeScore`) + Ngân sách (`computeBudgetScore`), kết hợp $\text{DiscountStrength}$ | `Phù hợp hồ sơ da của bạn` hoặc `Trong ngân sách của bạn` |
| **STATE D — PROFILE + BEHAVIOR** | Có đầy đủ cả hồ sơ da và hành vi thao tác realtime | **Adaptive Hybrid Ranking** | Tái sử dụng trọn vẹn truy vấn Hybrid ($0.50 U_{\text{behavior}} + 0.50 V_{\text{profile}}$) + SkinScore + BudgetScore, kết hợp $\text{DiscountStrength}$ | `Khớp loại da • Dựa trên sản phẩm vừa xem` |
| **STATE E — PURCHASE HISTORY** | Khách hàng thân thiết có lịch sử mua hàng hợp lệ | **Loyalty Purchase Affinity** | Các đơn hàng cũ được tính suy giảm thời gian (half-life 60 ngày) nạp vào vector hành vi, ưu tiên các deal cùng nhóm công dụng/thương hiệu đã tin dùng | `Tương thích lịch sử mua sắm • Giảm XX%` |

---

## 3. Không Tự Ý Đặt Trọng Số — Phân Tích Thang Đo & Cơ Chế Chuẩn Hóa

### 3.1 Cảnh Báo Phương Pháp Luận: "Do Not Invent Weights"
Trong nghiên cứu hệ thống gợi ý, việc tự tiện chốt các con số như $50\% \text{ Relevance} + 30\% \text{ Discount} + 20\% \text{ Popularity}$ khi chưa qua thực nghiệm là thiếu cơ sở khoa học (unvalidated heuristic).  
Vì vậy, Phase 1 chỉ thực hiện:
1. Xác định chính xác **thang đo (scale)** và **phân phối (distribution)** của từng thành phần.
2. Thiết kế giải pháp **chuẩn hóa (normalization)** đưa mọi thành phần về cùng khoảng $[0.0, 1.0]$.
3. Đề xuất các cấu hình ứng viên (candidate weight presets) để đo lường trong Phase 2.

### 3.2 Chuẩn Hóa 3 Điểm Thành Phần:
1. **Điểm Phù Hợp Cá Nhân ($\text{PersonalRelevance} \in [0.0, 1.0]$):**
   * Được sinh ra từ `ContentBasedRecommender::recommendHybrid` qua Cosine Similarity.
   * Thang đo đã nằm sẵn trong khoảng $[0.0, 1.0]$.
2. **Điểm Mức Độ Giảm Giá ($\text{DiscountStrength} \in [0.0, 1.0]$):**
   * Tính theo tỷ lệ giảm giá của deal:
     $$\text{raw\_disc} = \frac{\text{regular\_price} - \text{flash\_price}}{\text{regular\_price}}$$
   * Do các chiến dịch thông thường có mức giảm từ $10\%$ đến $60\%$, ta thực hiện Min-Max scaling cục bộ trên tập deal của chiến dịch:
     $$\text{DiscountStrength} = \frac{\text{raw\_disc} - \min_{C}(\text{raw\_disc})}{\max_{C}(\text{raw\_disc}) - \min_{C}(\text{raw\_disc}) + \epsilon}$$
     đảm bảo deal giảm mạnh nhất trong đợt luôn đạt điểm $1.0$.
3. **Điểm Bằng Chứng Đánh Giá ($\text{SocialProofEvidence} \in [0.0, 1.0]$):**
   * Không dùng số đơn hàng (vì 99,35% là 0).
   * Sử dụng công thức IMDb Weighted Rating đã có trong `SanPham::getSimpleRecommenderProducts()`:
     $$WR = \frac{v}{v+m} \cdot R + \frac{m}{v+m} \cdot C$$
     (với $v$ là số đánh giá, $m=21$ là ngưỡng tin cậy, $C=4.889$ là điểm trung bình toàn sàn).
   * Chuẩn hóa từ khoảng thực tế $[4.0, 5.0]$ về $[0.0, 1.0]$:
     $$\text{SocialProofEvidence} = \max\left(0.0, \min\left(1.0, \frac{WR - 4.0}{1.0}\right)\right)$$

### 3.3 Ba Cấu Hình Trọng Số Ứng Viên Cho Phase 2 Đánh Giá:
* **Preset 1 (Balanced Merchandising):** $45\% \text{ Relevance} + 35\% \text{ Discount} + 20\% \text{ SocialProof}$
* **Preset 2 (Relevance-First):** $60\% \text{ Relevance} + 25\% \text{ Discount} + 15\% \text{ SocialProof}$
* **Preset 3 (Deal-Seeker Promotion):** $30\% \text{ Relevance} + 50\% \text{ Discount} + 20\% \text{ SocialProof}$

---

## 4. Thiết Kế Tem Lý Do Giải Thích Minh Bạch (Grounded Reason Tags)

* **Nguyên tắc:** Mọi tem hiển thị trên thẻ deal Flash Sale phải có **căn cứ dữ liệu thực chứng (data grounding)**.
* **Các tem HỢP LỆ được phê duyệt:**
  * *"Phù hợp hồ sơ da của bạn"* (Khi `SkinScore == 1.0` từ khảo sát).
  * *"Phù hợp mọi loại da"* (Khi sản phẩm thuộc dòng đa năng, `SkinScore == 0.70`).
  * *"Dựa trên sản phẩm bạn vừa xem"* (Khi điểm `content_similarity` đến từ tương tác view gần nhất).
  * *"Theo tìm kiếm gần đây"* (Khi từ khóa search khớp với thành phần/công dụng sản phẩm).
  * *"Trong ngân sách của bạn"* (Khi `flash_price <= user_budget`).
  * *"Đang giảm XX%"* (Dựa trên tỷ lệ flash sale thực tế).
  * *"Được đánh giá tốt"* (Khi $WR \ge 4.7$ và có $\ge 5$ đánh giá thật).
* **Các tem BỊ CẤM HOÀN TOÀN (Banned Tags):**
  * *"Chuẩn y khoa"* (Không có kiểm nghiệm lâm sàng của bác sĩ).
  * *"Được bác sĩ da liễu khuyên dùng"* (Vi phạm tính trung thực).
  * *"An toàn 100% cho da"* (Không thể cam kết y tế).
  * *"Phù hợp 98%"* (Không dùng % matching bịa đặt khi chưa qua mô hình xác suất được kiểm chứng).
  * *"Bán chạy nhất đợt này"* (Không có đủ số liệu đơn hàng thực tế).

---

## 5. Thiết Kế Trang Tất Cả Deal (Dedicated All Deals Page: `index.php?r=flash_sale`)

* **Route chuẩn:** `index.php?r=flash_sale` (thay vì dẫn sang `index.php?r=tatca` như hiện nay).
* **Nội dung:** Chỉ tải danh sách 12–20 sản phẩm nằm trong chiến dịch đang **ACTIVE**.
* **Các tùy chọn sắp xếp (Sort Options) được phép:**
  1. `Dành cho bạn` (Mặc định — Personalized Deal Ranking theo State của user).
  2. `Giảm nhiều nhất` (Sort theo `DiscountPercentage` giảm dần).
  3. `Đánh giá cao` (Sort theo IMDb Weighted Rating giảm dần).
  4. `Giá thấp → cao` (Sort theo `flash_price` tăng dần).
  5. `Giá cao → thấp` (Sort theo `flash_price` giảm dần).
* **Lưu ý:** Tạm thời **KHÔNG CUNG CẤP tùy chọn "Mua nhiều nhất"** do dữ liệu đơn hàng chưa đủ độ phủ đại diện.
