# BÁO CÁO TỔNG KIỂM TOÁN TRẢI NGHIỆM GỢI Ý TRANG CHỦ (HOMEPAGE RECOMMENDATION UX AUDIT)
## SKINSYNTAXVN — PHASE 1: AUDIT ONLY BEFORE UI IMPLEMENTATION

---

## 0. TỔNG QUAN VÀ MỤC TIÊU KIỂM TOÁN

Sau khi hoàn tất đợt Tổng Kiểm Toán Kiến Trúc Thuật Toán (Recommendation Engine Master Audit), nhóm kỹ thuật chuyển trọng tâm sang **kiểm toán toàn diện trải nghiệm người dùng (UX/UI Audit) đối với các khối gợi ý trên trang chủ SkinSyntaxVN**.

### Nguyên tắc bất khả xâm phạm của Phase 1:
1. **AUDIT-ONLY (Chỉ kiểm toán, chưa sửa UI):** Không tự ý sửa code giao diện hoặc mã nguồn PHP trong giai đoạn này.
2. **Không can thiệp thuật toán:** Tuyệt đối không thay đổi công thức toán học, điểm số, trọng số hành vi hay bộ lọc của Recommender Engine. Giao diện phải phản ánh trung thực bản chất của thuật toán, không được bóp méo thuật toán để làm đẹp giao diện.
3. **Không tích hợp thuật toán nghiên cứu:** K-Means, Lọc cộng tác (CF/BPR) và Luật kết hợp (Apriori/FP-Growth) tuyệt đối không được đưa vào giao diện trang chủ.
4. **Không bịa đặt dữ liệu:** Mọi điểm đánh giá, số lượt review và nhãn giải thích trên giao diện phải có nguồn gốc từ cơ sở dữ liệu thật.

---

## 1. TRUY VẾT RUNTIME THỰC TẾ TRÊN TRANG CHỦ

Kiểm toán mã nguồn xác nhận luồng thực thi từ Request đến View:

$$\text{Request: } \texttt{index.php?r=home} \longrightarrow \texttt{HomeController::index()} \longrightarrow \texttt{SanPham::getHomepageProductSections()} \longrightarrow \texttt{frontend/views/home.php}$$

* **Tầng Controller (`HomeController.php` dòng 134–212):**
  * Kiểm tra trạng thái người dùng (`isLoggedIn`, `hasSurvey`, `userProfile`).
  * Thu thập 4 tín hiệu hành vi: `search` (truy vấn tìm kiếm), `view` (lịch sử xem), `cart` (giỏ hàng hiện tại), `purchases` (đơn hàng hợp lệ).
  * Gọi hàm tĩnh `SanPham::getHomepageProductSections()`.
* **Tầng Model (`SanPham.php` dòng 756–810):**
  * Khởi tạo đồng thời 3 khối dữ liệu:
    1. `$flashDeals`: Lọc sản phẩm có giảm giá.
    2. `$adaptiveRecs`: Gọi `ContentBasedRecommender::recommendHybrid()`, tự động fallback về Top-4 Simple WR nếu rỗng.
    3. `$topRatedWeighted`: Gọi `getSimpleRecommenderProducts()` theo chuẩn IMDb Weighted Rating ($m=21, C=4.8890$).
* **Tầng View (`frontend/views/home.php`):**
  * Kết xuất tổng cộng **10 section** theo thứ tự từ trên xuống dưới:
    1. `Hero Banner` (Định vị thương hiệu & CTA khảo sát)
    2. `Skin Concern Shortcuts` (8 lối tắt nhu cầu da)
    3. `Universal Adaptive For-You` (Khối cá nhân hóa trung tâm — 4 sản phẩm)
    4. `Flash Sale` (Khuyến mãi sốc — 4 sản phẩm)
    5. `Syna AI Livestream` (Widget livestream tư vấn AI)
    6. `Simple Recommender` (Được yêu thích nhất — 4 sản phẩm theo IMDb WR)
    7. `New Products` (Mỹ phẩm vừa lên kệ — 4 sản phẩm)
    8. `Personal Routine` (Quy trình 4 bước, tùy biến theo khảo sát)
    9. `Trusted Brands` (8 thương hiệu đồng hành)
    10. `Final CTA` (Chân trang kêu gọi khảo sát)

---

## 2. PHÂN TÍCH CHUYÊN SÂU KHỐI "DÀNH CHO BẠN" (`forYouProducts`)

### 2.1. Đánh giá tính động của tiêu đề
* **Thực trạng:** Section 3 không bị hard-code tiêu đề mà sử dụng cấu trúc `switch ($dominantSignal)` (dòng 485–522 `home.php`) để thay đổi Tiêu đề (`$sectionTitle`), Phụ đề (`$sectionSub`) và Kicker (`$sectionKicker`).
* **Lỗi phát hiện:** 
  * Khi ở chế độ `PARTIAL_PROFILE_FALLBACK` (hồ sơ thiếu trường và phải fallback), hệ thống vẫn giữ `$dominantSignal = 'PROFILE'` và giật tít: *"Dành riêng cho làn da của bạn"*, tạo cảm giác giả mạo hồ sơ hoàn chỉnh.
  * Khi ở chế độ `ADAPTIVE_HYBRID`, tiêu đề bị chèn cụm từ kỹ thuật: *"Dành riêng cho bạn (Hồ sơ da & Hành vi)"* và kicker *"CÁ NHÂN HÓA ĐA TÍN HIỆU"*, gây cảm giác như đang đọc báo cáo lập trình.

### 2.2. Khả năng nhận biết dữ liệu của Frontend
* **Frontend NHẬN ĐƯỢC:** `algorithm_mode`, `dominant_signal`, `reason_tags`, `reason`, `final_score`, `skin_score`, `similarity_score`.
* **Frontend BỊ KHUYẾT (Lỗi kết nối dữ liệu):** Thẻ card kiểm tra biến `$p['match_score']` để hiển thị huy hiệu `XX% MATCH`, nhưng `SanPham.php` chỉ lưu trữ trong mảng lồng `recommender_meta`. Hậu quả: `$matchScore` **luôn luôn bằng null**.

---

## 3. KIỂM THỬ GIAO DIỆN QUA 9 TRẠNG THÁI NGƯỜI DÙNG (DETERMINISTIC RUNTIME TESTS)

Kiểm thử độc lập qua PHP runtime xác nhận hành vi hiển thị thực tế:

| Trạng thái người dùng | Chế độ Algorithm | Tín hiệu chủ đạo | Tiêu đề hiện tại | Phụ đề hiện tại | Top-4 SKU ID | Đánh giá UX |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **A. Cold Start** | `SIMPLE` | `SIMPLE` | Gợi ý dành cho bạn | Khám phá các sản phẩm nổi bật được cộng đồng tin dùng | 1000, 266, 728, 4365 | ⚠️ Trùng 100% với Section 5.5 |
| **B. Anonymous + Search** | `BEHAVIOR_CONTENT` | `SEARCH` | Dựa trên tìm kiếm gần đây | Các sản phẩm phù hợp với nhu cầu bạn vừa tìm kiếm | 6350, 2350, 5788, 2780 | ✅ Phản ánh đúng từ khóa |
| **C. Anonymous + View** | `BEHAVIOR_CONTENT` | `VIEW` | Dựa trên sản phẩm bạn vừa xem | Các sản phẩm có đặc điểm tương tự với những gì bạn vừa quan tâm | 11, 36, 152, 3331 | ✅ Gợi ý tương đồng tốt |
| **D. Anonymous + Cart** | `BEHAVIOR_CONTENT` | `CART` | Phù hợp với giỏ hàng của bạn | Gợi ý bổ trợ cho các sản phẩm trong giỏ hàng của bạn | 11, 36, 152, 3331 | ✅ Bổ trợ sản phẩm giỏ hàng |
| **E. Logged-in No Survey** | `SIMPLE` | `SIMPLE` | Gợi ý dành cho bạn | Khám phá các sản phẩm nổi bật được cộng đồng tin dùng | 1000, 266, 728, 4365 | ⚠️ Trùng với Section 5.5 |
| **F. Logged-in + Purchase**| `PURCHASE_CONTENT` | `PURCHASE` | Gợi ý từ lịch sử mua hàng | Sản phẩm tương thích với thói quen chăm sóc da của bạn | 1, 36, 152, 3331 | ✅ Bám sát lịch sử mua |
| **G. Logged-in + Profile** | `PROFILE_CONTENT` | `PROFILE` | Dành riêng cho làn da của bạn | Gợi ý tối ưu theo loại da và nhu cầu trong hồ sơ của bạn | 4819, 3711, 1725, 4382 | ✅ Khớp da dầu mụn |
| **H. Profile + Behavior** | `ADAPTIVE_HYBRID` | `HYBRID` | Dành riêng cho bạn (Hồ sơ da & Hành vi) | Kết hợp hồ sơ da của bạn cùng các sản phẩm bạn đang quan tâm | 2928, 3531, 1395, 5349 | ⚠️ Tiêu đề có từ ngữ kỹ thuật |
| **I. Partial Profile** | `PARTIAL_PROFILE_FALLBACK` | `SIMPLE` | Gợi ý dành cho bạn | Khám phá các sản phẩm nổi bật được cộng đồng tin dùng | 1000, 266, 728, 4365 | ⚠️ Cần làm rõ mức độ hồ sơ |

---

## 4. TỔNG HỢP CÁC KHIẾM KHUYẾT UX/UI PHÁT HIỆN QUA KIỂM TOÁN

### 1. Bịa đặt điểm số và số lượng đánh giá (Mức ưu tiên P0)
* **Bằng chứng:** Dòng 149 và 206 `frontend/views/home.php`:
  `$rating = ... > 0 ? ... : 4.9;` và `(int)($p['so_luong_danh_gia'] ?? 128)`.
* **Hệ quả:** Bất kỳ sản phẩm nào chưa có đánh giá đều bị hiển thị giả mạo thành "4.9 sao" và "(128 lượt đánh giá)".

### 2. Lỗi `match_score` biến mọi thẻ sản phẩm thành "Đã khảo sát" (Mức ưu tiên P0)
* **Bằng chứng:** Biến `$matchScore` luôn bằng null. Dòng 184–188 kích hoạt nhánh `elseif ($hasSurvey)`.
* **Hệ quả:** Mọi thẻ sản phẩm trên toàn bộ trang chủ đều bị gắn nhãn "Đã khảo sát", gây hiểu lầm rằng toàn bộ sản phẩm trên web đều đã được xác thực y khoa khớp với da của người dùng.

### 3. Trùng lặp hiển thị ở trạng thái Khách vãng lai (Cold-start Duplication) (Mức ưu tiên P1)
* **Bằng chứng:** Section 3 và Section 5.5 cùng gọi Simple Recommender và hiển thị cùng 4 sản phẩm `[1000, 266, 728, 4365]`.
* **Giải pháp đề xuất:** Áp dụng **Chính sách bù trừ (Offset Policy)**: Khi Section 3 ở chế độ SIMPLE lấy Top 1–4, Section 5.5 sẽ tự động lấy Top 5–8 để tránh lặp sản phẩm trên trang chủ.

### 4. Rò rỉ thuật ngữ tiếng Anh và thuật ngữ kỹ thuật (Mức ưu tiên P2)
* **Bằng chứng:**
  * Kicker kỹ thuật: `"CÁ NHÂN HÓA ĐA TÍN HIỆU"`, Tiêu đề `"Dành riêng cho bạn (Hồ sơ da & Hành vi)"`.
  * Khối Routine: `"DAILY SKINCARE REGIMEN"`, `"STEP 1: Cleanse (Làm sạch)"`, `"STEP 2: Treat (Đặc trị)"`.
* **Giải pháp đề xuất:** Việt hóa 100% bằng ngôn ngữ tự nhiên: `"Quy trình chăm sóc da hằng ngày"`, `"Bước 1: Làm sạch da"`, `"Bước 2: Tinh chất đặc trị"`, v.v.

### 5. Nút bấm hành động bị co cụm dưới 65px trên điện thoại (Mức ưu tiên P2)
* **Bằng chứng:** Thẻ sản phẩm chia 2 nút ngang ("Thêm" và "Mua ngay") trong cột 160px.
* **Hệ quả:** Chiều rộng mỗi nút chỉ còn 64px–71px, chữ "Mua ngay" bị gãy thành 2 dòng, dễ bấm trượt trên màn hình cảm ứng.
* **Giải pháp đề xuất:** Trên màn hình nhỏ (`< 576px`), hợp nhất thành 1 nút chính *"Thêm vào giỏ"*, nhấp thẻ để xem chi tiết.

### 6. Quá tải nút kêu gọi khảo sát da (Mức ưu tiên P2)
* **Bằng chứng:** Kêu gọi khảo sát xuất hiện ở 5 vị trí khác nhau, đặc biệt là nút `"Độ hợp →"` gắn trên từng thẻ card.
* **Giải pháp đề xuất:** Gỡ bỏ `"Độ hợp →"` khỏi từng card, chỉ giữ lại một thông điệp mời khảo sát nhẹ nhàng ở Header của Section 3.

---

## 5. ĐỀ XUẤT ÁNH XẠ TIÊU ĐỀ ĐỘNG THEO TÍN HIỆU THỰC TẾ (DYNAMIC TITLES)

| Chế độ Algorithm | Tín hiệu chủ đạo | Kicker đề xuất | Tiêu đề đề xuất | Phụ đề đề xuất |
| :--- | :--- | :--- | :--- | :--- |
| `SIMPLE` | `SIMPLE` | **GỢI Ý NỔI BẬT** | Sản phẩm được yêu thích tại SkinSyntax | Những lựa chọn nổi bật được cộng đồng skincare đánh giá cao. |
| `BEHAVIOR_CONTENT` | `SEARCH` | **TÌM KIẾM GẦN ĐÂY** | Có thể bạn đang quan tâm | Gợi ý dựa trên những nhu cầu bạn vừa tìm kiếm. |
| `BEHAVIOR_CONTENT` | `VIEW` | **VỪA XEM GẦN ĐÂY** | Dựa trên sản phẩm bạn vừa xem | Những lựa chọn tương tự về công dụng và thành phần nổi bật. |
| `BEHAVIOR_CONTENT` | `CART` | **DÀNH CHO GIỎ HÀNG** | Gợi ý phù hợp với giỏ hàng của bạn | Các sản phẩm kết hợp hài hòa cùng những món bạn đã chọn. |
| `PURCHASE_CONTENT` | `PURCHASE` | **LỊCH SỬ MUA SẮM** | Gợi ý từ thói quen mua sắm | Sản phẩm tương thích với chu trình chăm sóc da bạn từng trải nghiệm. |
| `PROFILE_CONTENT` | `PROFILE` | **HỒ SƠ LÀN DA** | Phù hợp với làn da của bạn | Được chọn lọc dựa trên thông tin làn da bạn đã chia sẻ. |
| `ADAPTIVE_HYBRID` | `HYBRID` | **DÀNH RIÊNG CHO BẠN** | Gợi ý tối ưu cho bạn | Hòa trộn giữa đặc điểm làn da và các sản phẩm bạn đang quan tâm. |
| `PARTIAL_PROFILE_FALLBACK` | `PROFILE/BEHAVIOR` | **GỢI Ý BAN ĐẦU** | Lựa chọn theo đặc điểm da cơ bản | Gợi ý bước đầu dựa trên thông tin bạn đã cung cấp (làm khảo sát đầy đủ để tối ưu hơn). |

---

## 6. KẾ HOẠCH TRIỂN KHAI HOÀN THIỆN THEO CẤP ĐỘ ƯU TIÊN

```
┌────────────────────────────────────────────────────────────────────────┐
│                      LỘ TRÌNH TRIỂN KHAI HOMEPAGE UX                   │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
     ┌──────────────────────────────┼──────────────────────────────┐
     ▼                              ▼                              ▼
[P0: SỬA LỖI DỮ LIỆU]      [P1: MINH BẠCH THÔNG ĐIỆP]    [P2: RÕ RÀNG & DI ĐỘNG]
- Loại bỏ rating giả 4.9    - Tiêu đề động tự nhiên        - Việt hóa 100% Routine
- Loại bỏ review giả (128)  - Sửa lỗi Partial Fallback     - 1 nút hành động trên mobile
- Sửa kết nối match_score   - Offset chống trùng Coldstart - Tinh gọn lời mời khảo sát
```

* **P0 — Tính đúng đắn của dữ liệu (Functional Correctness):** Sửa lỗi dữ liệu giả và kết nối `match_score`.
* **P1 — Tính minh bạch của gợi ý (Recommendation Transparency):** Cập nhật tiêu đề động, xử lý trùng lặp Cold-start.
* **P2 — Tính rõ ràng trong trải nghiệm (UX Clarity):** Việt hóa thuật ngữ tiếng Anh, tối ưu nút bấm di động, giảm tải CTA.
* **P3 — Hoàn thiện thẩm mỹ (Visual Polish):** Căn chỉnh khoảng cách nhãn, nâng độ tương phản màu chữ phụ đạt chuẩn WCAG.

---

## 7. KẾT LUẬN

Giai đoạn **Phase 1 — Audit Only** đã hoàn thành xuất sắc mục tiêu:
1. Đã phát hiện chính xác các khiếm khuyết dữ liệu và rào cản trải nghiệm trên trang chủ đang chạy.
2. Đã xây dựng giải pháp xử lý toàn diện mà **hoàn toàn không cần thay đổi bất kỳ dòng code thuật toán nào của backend**.
3. Toàn bộ mã nguồn production, database và giao diện hiện tại được **bảo toàn nguyên vẹn 100%**.

---
**DỪNG LẠI TẠI ĐÂY THEO ĐÚNG CHỈ THỊ (STOP). CHỜ PHÊ DUYỆT BÁO CÁO TRƯỚC KHI BẮT ĐẦU PHASE 2.**
