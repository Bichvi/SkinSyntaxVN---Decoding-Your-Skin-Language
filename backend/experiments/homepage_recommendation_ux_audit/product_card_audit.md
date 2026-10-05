# KIỂM TOÁN THẺ SẢN PHẨM GỢI Ý (PRODUCT CARD UX AUDIT)
## SKINSYNTAXVN HOMEPAGE RECOMMENDATION UX AUDIT — PHASE 1

Tài liệu này kiểm toán chi tiết cấu trúc hiển thị, dữ liệu và tương tác của thành phần thẻ sản phẩm (`$renderHomeProductCard`) đang được dùng chung cho toàn bộ các section trên trang chủ.

---

### 1. CẤU TRÚC VÀ TRƯỜNG DỮ LIỆU CỦA THẺ SẢN PHẨM HIỆN TẠI

Thẻ sản phẩm được định nghĩa qua hàm đóng `$renderHomeProductCard` (dòng 142–238 file `frontend/views/home.php`), bao gồm 9 thành phần giao diện:

| Thành phần | Trường dữ liệu thực tế | Cách xử lý trong mã nguồn | Đánh giá tính chân thực / Rủi ro UX |
| :--- | :--- | :--- | :--- |
| **Ảnh đại diện** | `link_hinh_anh` / `hinh_anh` | `resolve_image_url()`, fallback về `default_placeholder_image()` | **Hợp lệ**: Tỷ lệ 1:1, có thuộc tính `referrerpolicy` và xử lý `onerror`. |
| **Tên sản phẩm** | `ten_san_pham` | Giới hạn 2 dòng qua `-webkit-line-clamp: 2; overflow: hidden;` | **Hợp lệ**: Tiêu chuẩn thương mại điện tử, không bị tràn khung. |
| **Thương hiệu** | `thuong_hieu` | In hoa, fallback về `'SkinSyntax'` nếu rỗng | **Hợp lệ**: Nhãn nhỏ gọn, phân biệt rõ thương hiệu. |
| **Giá bán** | `gia_ban` | Định dạng tiền tệ qua `vnd($giaBan)` | **Hợp lệ**: Hiển thị rõ ràng, font tabular-nums giúp căn chỉnh đẹp. |
| **Giá thị trường** | `gia_thi_truong` | Gạch ngang nếu `gia_thi_truong > gia_ban` | **Hợp lệ**: Tự động ẩn nếu giá thị trường bằng hoặc nhỏ hơn giá bán. |
| **Điểm đánh giá** | `diem_danh_gia` | `(float)$p['diem_danh_gia'] > 0 ? ... : 4.9` | ⚠️ **DỮ LIỆU GIẢ (FAKE FALLBACK)**: Tự động gán điểm `4.9` sao nếu thiếu dữ liệu! |
| **Số lượt đánh giá**| `so_luong_danh_gia` | `(int)($p['so_luong_danh_gia'] ?? 128)` | ⚠️ **DỮ LIỆU GIẢ (FAKE FALLBACK)**: Tự động gán `(128)` lượt đánh giá nếu thiếu dữ liệu! |
| **Nhãn độ hợp da** | `p['match_score']` | `$matchScore !== null && $matchScore > 0` | ⚠️ **LỖI PHẦN CỨNG VIEW (BROKEN)**: Biến luôn bị `null`, không bao giờ hiển thị `% MATCH`. |
| **Trạng thái khảo sát**| `$hasSurvey` | Dòng 182–188 `home.php` | ⚠️ **GÂY HIỂU LẦM (MISLEADING)**: Mọi sản phẩm đều hiện *"Đã khảo sát"* nếu user đã làm khảo sát. |
| **Nút hành động** | `them_gio_hang_ajax` | 2 nút cạnh nhau: *"Thêm"* (AJAX) và *"Mua ngay"* | ⚠️ **CHÈN ÉP TRÊN MOBILE**: 2 nút cạnh nhau trong cột `col-6` bị chật hẹp trên màn hình nhỏ. |

---

### 2. PHÂN TÍCH SÂU CÁC KHIẾM KHUYẾT DỮ LIỆU & GIAO DIỆN (DEFECT ANALYSIS)

#### KHIẾM KHUYẾT 1: TỰ ĐỘNG BỊA ĐẶT ĐIỂM ĐÁNH GIÁ VÀ LƯỢT REVIEW (P0)
* **Bằng chứng mã nguồn (`frontend/views/home.php` dòng 149 và 206):**
  ```php
  $rating = isset($p['diem_danh_gia']) && (float)$p['diem_danh_gia'] > 0 ? (float)$p['diem_danh_gia'] : 4.9;
  // ...
  <span class="text-muted small ms-1">(<?= (int)($p['so_luong_danh_gia'] ?? 128) ?>)</span>
  ```
* **Vấn đề:** Khi sản phẩm chưa có đánh giá nào trong database (hoặc trường giá trị bị null), giao diện tự động biến sản phẩm đó thành sản phẩm được đánh giá "4.9 sao" với "128 lượt đánh giá". Điều này vi phạm nghiêm trọng tính trung thực trong thương mại điện tử và quy chuẩn học thuật của đồ án.
* **Đề xuất khắc phục:** Nếu không có đánh giá, hiển thị `"Chưa có đánh giá"` hoặc ẩn số lượng đánh giá, tuyệt đối không gán số liệu giả.

---

#### KHIẾM KHUYẾT 2: ĐỘ HỢP DA BỊ VÔ HIỆU HÓA DẪN ĐẾN HIỂN THỊ SAI LỆCH TOÀN BỘ (P0)
* **Bằng chứng mã nguồn (`frontend/views/home.php` dòng 148, 160, 182):**
  ```php
  $matchScore = isset($p['match_score']) && is_numeric($p['match_score']) ? (int)$p['match_score'] : null;
  // ...
  <?php if ($matchScore !== null && $matchScore > 0): ?>
    <span class="badge-match"> <?= $matchScore ?>% MATCH </span>
  <?php elseif ($tag !== ''): ?>
    <span class="market-card__tag"> <?= h($tag) ?> </span>
  <?php endif; ?>
  // ...
  <?php if ($matchScore !== null && $matchScore > 0): ?>
    <span class="text-success fw-semibold"><i class="bi bi-check2-circle"></i> Phù hợp da</span>
  <?php elseif ($hasSurvey): ?>
    <span class="text-success fw-semibold"><i class="bi bi-shield-check"></i> Đã khảo sát</span>
  <?php else: ?>
    <a href="...r=khaosat">Độ hợp &rarr;</a>
  <?php endif; ?>
  ```
* **Vấn đề:** 
  1. `SanPham.php` hydrate dữ liệu gợi ý và gán điểm số vào mảng con `$p['recommender_meta']['final_score']` và `$p['recommender_meta']['skin_score']`, hoàn toàn **không có trường `$p['match_score']` ở cấp gốc**.
  2. Do đó, `$matchScore` **luôn luôn bằng null**.
  3. Hệ quả: Huy hiệu `XX% MATCH` không bao giờ xuất hiện. Đồng thời, nhánh `<?php elseif ($hasSurvey): ?>` bị kích hoạt trên **TOÀN BỘ SẢN PHẨM CỦA TRANG CHỦ** (từ Flash Sale, New Products đến Top Rated), khiến mọi sản phẩm đều gắn nhãn `Đã khảo sát`. Khách hàng sẽ tưởng rằng sản phẩm nào hiển thị cũng đều đã được kiểm chứng khớp với da của họ!
* **Đề xuất khắc phục:** 
  1. Đọc đúng điểm tương thích từ `$p['recommender_meta']['skin_score']` hoặc `$p['recommender_meta']['final_score']`.
  2. Chỉ hiển thị nhãn "Phù hợp da" trên những sản phẩm thực sự đạt điểm tương thích cao (`skin_score >= 0.8`), không hiển thị bừa bãi chỉ vì người dùng đã hoàn thành khảo sát.

---

#### KHIẾM KHUYẾT 3: RÒ RỈ THUẬT NGỮ TIẾNG ANH (ENGLISH ENUM LEAKAGE)
* **Bằng chứng mã nguồn (`frontend/views/home.php` dòng 746, 762–794):**
  * Tiêu đề phụ: `"DAILY SKINCARE REGIMEN"` (Viết hoa tiếng Anh không cần thiết trên website tiếng Việt).
  * Các bước routine hiển thị nửa Anh nửa Việt:
    * `"STEP 1: Cleanse (Làm sạch)"`
    * `"STEP 2: Treat (Đặc trị)"`
    * `"STEP 3: Hydrate (Dưỡng ẩm)"`
    * `"STEP 4: Protect (Bảo vệ)"`
  * Trên các danh mục: Cần rà soát ngăn chặn các nhãn enum kỹ thuật chưa được dịch như `CLEANSER`, `SERUM`, `MOISTURIZER`, `SUNSCREEN`, `TREATMENT`.
* **Đề xuất khắc phục:** 
  * Chuyển đổi 100% sang tiếng Việt tự nhiên:
    * `"DAILY SKINCARE REGIMEN"` $\rightarrow$ `"QUY TRÌNH CHĂM SÓC DA HẰNG NGÀY"`
    * `"Bước 1: Làm sạch da"`, `"Bước 2: Tinh chất đặc trị"`, `"Bước 3: Dưỡng ẩm & phục hồi"`, `"Bước 4: Bảo vệ chống nắng"`.

---

#### KHIẾM KHUYẾT 4: GIAO DIỆN NÚT BẤM BỊ CHÈN ÉP TRÊN THIẾT BỊ DI ĐỘNG (MOBILE LAYOUT)
* **Bằng chứng mã nguồn (`frontend/views/home.php` dòng 216–234):**
  ```css
  grid-template-columns: 1fr 1fr;
  ```
  Trên màn hình điện thoại (độ rộng 360px – 390px), lưới chia làm 2 cột (`col-6`), mỗi cột có độ rộng khoảng 160px – 175px bao gồm cả padding. Việc đặt 2 nút bấm *"Thêm"* và *"Mua ngay"* trên cùng một hàng ngang khiến mỗi nút chỉ còn khoảng 70px, làm chữ bị co cụm hoặc tràn viền, trải nghiệm bấm chạm ngón tay (touch target) rất kém.
* **Đề xuất khắc phục:** Trên màn hình nhỏ (`< 576px`), chuyển nút thành 1 nút chính *"Thêm vào giỏ"* hoặc xếp chồng 2 nút theo chiều dọc.
