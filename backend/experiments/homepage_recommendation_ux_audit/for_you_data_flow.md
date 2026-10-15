# KIỂM TOÁN LUỒNG DỮ LIỆU KHỐI "DÀNH CHO BẠN" (FOR YOU DATA FLOW)
## SKINSYNTAXVN HOMEPAGE RECOMMENDATION UX AUDIT — PHASE 1

Tài liệu này truy vết và phân tích chuyên sâu luồng dữ liệu, trạng thái tính toán và việc hiển thị của khối gợi ý cá nhân hóa trọng tâm **"Dành Riêng Cho Bạn"** (`forYouProducts`).

---

### 1. TRUY VẾT CHI TIẾT LUỒNG DỮ LIỆU (CONTROLLER $\rightarrow$ MODEL $\rightarrow$ SERVICE $\rightarrow$ VIEW)

```
[1. Controller Layer: HomeController.php]
  │
  ├─ Thu thập Session Signals: $_SESSION['recent_viewed_products'], $_SESSION['gio_hang'], $_SESSION['search_history']
  ├─ Thu thập Database Signals: buildRecommendationProfile($email), getValidPurchasedProductsForCustomer($customerId)
  ├─ Đóng gói $behaviorSignals = ['search' => ..., 'view' => ..., 'cart' => ..., 'purchases' => ...]
  │
  ▼
[2. Model Layer: SanPham.php]
  │
  ├─ SanPham::getHomepageProductSections()
  │     │
  │     ▼
  │   SanPham::getHybridRecommendations()
  │     │
  ▼     ▼
[3. Service Layer: ContentBasedRecommender.php]
  │
  ├─ recommendationModeRouter(): Phân tích ma trận tín hiệu đầu vào
  ├─ L2 Normalize Vector Fusion: Dung hợp vector hành vi $U_{behavior}$ và vector hồ sơ $V_{profile}$
  ├─ TF-IDF Cosine Similarity Calculation trên $N=2,473$ sản phẩm
  ├─ Reranking: $0.70 \times \text{Cosine} + 0.20 \times \text{SkinBonus} + 0.10 \times \text{BudgetBonus}$
  ├─ Product Family Diversity Filter: Lọc khử trùng lặp biến thể dung tích
  ├─ Reason Tag Grounding: Gán lý do gợi ý tương ứng với tín hiệu đóng góp
  │
  ▼
[4. Model Hydration: SanPham.php]
  │
  ├─ MongoDB Query: $db->san_pham->find(['ma_san_pham' => ['$in' => $targetIds]])
  ├─ Gắn mảng siêu dữ liệu: $p['recommender_meta'] = [...]
  ├─ Sắp xếp theo thứ tự điểm số giảm dần
  │
  ▼
[5. View Rendering: frontend/views/home.php]
  │
  ├─ Trích xuất $forYouProducts = $homepageSections['forYou']
  ├─ Đọc siêu dữ liệu sản phẩm đầu tiên: $firstRecMeta = $forYouProducts[0]['recommender_meta']
  ├─ Dynamic Switch theo $dominantSignal: Sinh $sectionKicker, $sectionTitle, $sectionSub
  ├─ Render danh sách 4 thẻ sản phẩm qua hàm đóng $renderHomeProductCard($p, $pBadge)
```

---

### 2. AUDIT TIÊU ĐỀ: CÓ BỊ HARD-CODE HAY KHÔNG?

* **Thực trạng kiểm toán:** Tiêu đề của section **KHÔNG bị hard-code tuyệt đối**, mà sử dụng cấu trúc `switch ($dominantSignal)` tại dòng 485–522 file `frontend/views/home.php`:
  * `CART` $\rightarrow$ Title: *"Phù hợp với giỏ hàng của bạn"*, Kicker: *"DỰA TRÊN GIỎ HÀNG CỦA BẠN"*.
  * `VIEW` $\rightarrow$ Title: *"Dựa trên sản phẩm bạn vừa xem"*, Kicker: *"DỰA TRÊN SẢN PHẨM VỪA XEM"*.
  * `SEARCH` $\rightarrow$ Title: *"Dựa trên tìm kiếm gần đây"*, Kicker: *"DỰA TRÊN TÌM KIẾM GẦN ĐÂY"*.
  * `PURCHASE` $\rightarrow$ Title: *"Gợi ý từ lịch sử mua hàng"*, Kicker: *"LỊCH SỬ MUA SẮM"*.
  * `PROFILE` $\rightarrow$ Title: *"Dành riêng cho làn da của bạn"*, Kicker: *"HỒ SƠ DA CÁ NHÂN"*.
  * `HYBRID` $\rightarrow$ Title: *"Dành riêng cho bạn (Hồ sơ da & Hành vi)"*, Kicker: *"CÁ NHÂN HÓA ĐA TÍN HIỆU"*.
  * `SIMPLE` / Mặc định $\rightarrow$ Title: *"Gợi ý dành cho bạn"*, Kicker: *"GỢI Ý HÔM NAY"*.

* **PHÁT HIỆN LỖI LOGIC HIỂN THỊ TIÊU ĐỀ (CRITICAL UX MISMATCH):**
  1. **Lỗi `PARTIAL_PROFILE_FALLBACK`:** Khi người dùng có profile nhưng profile bị thiếu trường (không sinh được vector), Service gán `$algorithmMode = 'PARTIAL_PROFILE_FALLBACK'` nhưng `$dominantSignal = 'PROFILE'`. Khi đó, View đọc `$dominantSignal === 'PROFILE'` và hiển thị tiêu đề: *"Dành riêng cho làn da của bạn"*, mặc dù thực tế hệ thống đã fallback sang chế độ cơ bản và không dùng được vector profile.
  2. **Tiêu đề nhánh `HYBRID` quá mang tính kỹ thuật:** Dòng chữ *"Dành riêng cho bạn (Hồ sơ da & Hành vi)"* và kicker *"CÁ NHÂN HÓA ĐA TÍN HIỆU"* mang tính chất giải thích hệ thống cho lập trình viên/nghiên cứu hơn là ngôn ngữ giao tiếp tự nhiên với khách hàng thương mại điện tử.

---

### 3. KIỂM TOÁN TÍNH NHẬN BIẾT DỮ LIỆU CỦA FRONTEND VIEW

Kiểm toán xác nhận mức độ thông tin mà View nhận được từ Controller và Service:

| Thuộc tính | Frontend View có nhận được không? | Vị trí biến thực tế | Đánh giá sử dụng trên UI |
| :--- | :---: | :--- | :--- |
| **`algorithm_mode`** | **CÓ** | `$firstRecMeta['algorithm_mode']` | Hiện tại View có đọc ra biến `$algoMode` nhưng **chưa sử dụng** trong logic switch (switch chỉ dựa vào `$dominantSignal`). |
| **`dominant_signal`** | **CÓ** | `$firstRecMeta['dominant_signal']` | Đang được dùng trực tiếp để switch tiêu đề và phụ đề. |
| **`reason_tags`** | **CÓ** | `$p['recommender_meta']['reason_tags']` | Có nhận được mảng các nhãn lý do (ví dụ: `['Phù hợp với làn da của bạn', 'Trong tầm giá bạn quan tâm']`). |
| **`reason`** | **CÓ** | `$p['recommender_meta']['reason']` | Chuỗi lý do chính được truyền vào `$pBadge` của card sản phẩm. |
| **`profile completeness`** | **MỘT PHẦN** | `$hasSurvey` (boolean), `$userProfile` | View biết người dùng đã khảo sát hay chưa (`$hasSurvey`), nhưng không biết tỷ lệ hoàn thiện chi tiết (ví dụ: có chọn loại da nhưng thiếu ngân sách). |
| **`match_score`** | **KHÔNG** | `$p['match_score']` | **LỖI PHẦN CỨNG VIEW:** View mong đợi `$p['match_score']` (dòng 148 `home.php`) để hiển thị badge `XX% MATCH`, nhưng `SanPham.php` chỉ gán `final_score`, `skin_score`, `similarity_score` trong mảng lồng `recommender_meta`, khiến `$matchScore` **luôn luôn là null**. |

---

### 4. KẾT LUẬN KIỂM TOÁN LUỒNG DỮ LIỆU

Hạ tầng dữ liệu từ Controller và Service đã cung cấp rất đầy đủ thông tin ngữ cảnh (`recommender_meta` chứa 16 trường siêu dữ liệu). Tuy nhiên, tầng View (`home.php`) chưa khai thác hết các trường này và đang tồn tại 2 lỗi hiển thị:
1. Switch tiêu đề chỉ nhìn vào `dominant_signal` thay vì kết hợp `algorithm_mode`, dẫn đến sai lệch thông điệp khi fallback.
2. Không trích xuất `final_score` / `skin_score` làm `match_score`, khiến tính năng hiển thị độ phù hợp da trên từng thẻ sản phẩm bị vô hiệu hóa ngầm.
