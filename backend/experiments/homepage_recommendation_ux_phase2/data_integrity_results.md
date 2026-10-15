# BÁO CÁO KIỂM TRA TÍNH TOÀN VẸN DỮ LIỆU (DATA INTEGRITY)
## SkinSyntaxVN — Homepage Recommendation UX Phase 2

Kiểm tra đối chiếu các ràng buộc dữ liệu và bằng chứng hiển thị trên giao diện theo yêu cầu kỹ thuật:

---

### 1. Bảng xác thực các tiêu chí Data Integrity

| Tiêu chí kiểm tra | Kết quả mong đợi | Kết quả thực tế kiểm toán | Trạng thái |
|---|---|---|---|
| **Không còn fallback 4.9** | Không có giá trị `4.9` được tự động gán cho sản phẩm chưa có review trong file view và output HTML. | Đã xóa dòng `$rating = ... : 4.9`. Khi sản phẩm không có rating > 0, không có số 4.9 nào được tạo. | **PASSED** |
| **Không còn fallback 128** | Không có giá trị `128` lượt đánh giá được gán tự động. | Đã xóa dòng `so_luong_danh_gia ?? 128`. Khi sản phẩm không có review thật, không xuất hiện `(128)`. | **PASSED** |
| **Sản phẩm chưa có review** | Hiển thị thông báo minh bạch `"Chưa có đánh giá"` kèm biểu tượng sao rỗng. | Đã triển khai thẻ `<i class="far fa-star text-muted"></i> <span>Chưa có đánh giá</span>`. | **PASSED** |
| **Thẻ sản phẩm không cá nhân hóa không nhận tem hồ sơ** | Flash Sale, Bảng xếp hạng đánh giá, Mỹ phẩm vừa lên kệ không được nhận tem `"Khớp loại da"`. | Kiểm toán cấu trúc: biến `$isPersonalized` chỉ bật khi sản phẩm nằm trong recommendation context có `algorithm_mode !== 'SIMPLE'` và `dominant_signal !== 'SIMPLE'`. Các section thông thường không nhận tem. | **PASSED** |
| **Xóa bỏ hoàn toàn tem "Đã khảo sát"** | Không hiển thị trạng thái hoàn thành khảo sát của user trên từng thẻ sản phẩm. | Đã loại bỏ hoàn toàn khối `<?php elseif ($hasSurvey): ?>` tại vị trí hiển thị badge của thẻ sản phẩm. | **PASSED** |
| **Partial Profile Fallback không nhận vơ cá nhân hóa theo da** | Khi profile chưa đủ và hệ thống fallback SIMPLE, không nói ranking theo da. | Tiêu đề fallback: Kicker `"GỢI Ý NỔI BẬT"`, Title `"Gợi ý nổi bật cho bạn"`, Subtitle `"Khám phá những sản phẩm nổi bật tại SkinSyntax."`. Không có câu từ nào khẳng định đã xếp hạng theo da. | **PASSED** |
| **Tín hiệu Giỏ hàng (CART) không claim luật kết hợp** | Không dùng các từ "kết hợp hài hòa", "mua kèm", "bổ trợ" (do Association Rules chưa lên prod). | Wording chính xác: Subtitle `"Gợi ý dựa trên những sản phẩm bạn đang quan tâm trong giỏ hàng."`. | **PASSED** |
| **Tín hiệu Mua sắm (PURCHASE) không claim tương thích chu trình** | Không dùng câu "tương thích với chu trình chăm sóc da". | Wording chính xác: Subtitle `"Gợi ý dựa trên những sản phẩm bạn từng mua."`. | **PASSED** |
| **Không rò rỉ mã thuật toán tiếng Anh (Algorithm Enum Leaks)** | UI người dùng không chứa `SIMPLE`, `BEHAVIOR_CONTENT`, `PROFILE_CONTENT`, `ADAPTIVE_HYBRID`, `TF-IDF`, `Cosine`, `vector`, `weight`. | Tìm kiếm chuỗi trên toàn bộ HTML output của 9 trạng thái: 0 trường hợp rò rỉ mã thuật toán tiếng Anh. | **PASSED** |
| **Không rò rỉ thuật toán nghiên cứu** | UI và code production không chứa `K-Means`, `Collaborative Filtering`, `BPR`, `Apriori`, `FP-Growth`. | Không có bất kỳ thuật toán nghiên cứu nào được đưa vào production call graph hoặc UI templates. | **PASSED** |
| **Không tạo threshold mới ngoài production engine** | Không tự đặt điều kiện `skin_score >= 70%` để hiển thị "Khớp da" nếu threshold này không từ engine. | Badge `"Khớp loại da"` và `"Mọi loại da"` chỉ được lấy trực tiếp từ `recommender_meta['reason_tags']` do `ContentBasedRecommender` tính toán. | **PASSED** |

---

### 2. Bằng chứng kiểm toán HTML Render (Trích xuất từ 9 States)

```html
<!-- KHI SẢN PHẨM CÓ ĐÁNH GIÁ THẬT (Rating > 0 && Review Count > 0) -->
<div class="d-flex align-items-center gap-1 mb-2" style="font-size: 0.76rem;">
  <span class="d-inline-flex align-items-center gap-1 text-warning font-weight-bold">
    <i class="fas fa-star" style="color: #F59E0B; font-size: 0.72rem;"></i> 5.0
  </span>
  <span class="text-muted small ms-1">(12)</span>
</div>

<!-- KHI SẢN PHẨM CHƯA CÓ ĐÁNH GIÁ (Rating = 0 || Review Count = 0) -->
<div class="d-flex align-items-center gap-1 mb-2 text-muted" style="font-size: 0.76rem; color: #94A3B8;">
  <i class="far fa-star text-muted" style="font-size: 0.72rem;"></i>
  <span>Chưa có đánh giá</span>
</div>

<!-- TEM KHỚP LOẠI DA CHỈ XUẤT HIỆN TRÊN SẢN PHẨM CÓ REASON TAGS TƯƠNG ỨNG -->
<span class="skin-match-status" style="font-size: 0.72rem; color: var(--muted);">
  <span class="text-success fw-semibold"><i class="bi bi-check2-circle"></i> Khớp loại da</span>
</span>
```
