# KIỂM TOÁN TÍNH KHẢ DỤNG & TIẾP CẬN (ACCESSIBILITY UX AUDIT)
## SKINSYNTAXVN HOMEPAGE RECOMMENDATION UX AUDIT — PHASE 1

Tài liệu này kiểm toán các tiêu chuẩn tiếp cận cơ bản (Web Content Accessibility Guidelines - WCAG 2.1 AA) trên mã nguồn HTML/CSS của các khối sản phẩm trang chủ SkinSyntaxVN.

---

### 1. BẢNG KIỂM TRA CÁC TIÊU CHÍ TIẾP CẬN CỐT LÕI (A11Y AUDIT CHECKLIST)

| Tiêu chuẩn WCAG 2.1 | Yếu tố kiểm tra | Bằng chứng mã nguồn (`frontend/views/home.php`) | Đánh giá tuân thủ | Ghi chú & Đề xuất |
| :--- | :--- | :--- | :---: | :--- |
| **1.1.1 Non-text Content** | Văn bản thay thế ảnh (`alt`) | `alt="<?= h($p['ten_san_pham'] ?? '') ?>"` | **ĐẠT (PASS)** | Mọi ảnh sản phẩm và logo thương hiệu đều có thuộc tính `alt` chứa tên sản phẩm/thương hiệu. |
| **1.3.1 Info & Relationships** | Phân cấp tiêu đề (`Heading Hierarchy`) | Duy nhất 1 thẻ `<h1>` (Hero), các section chính dùng `<h2>`, section phụ dùng `<h3>` | **ĐẠT (PASS)** | Cấu trúc heading tuần tự, không nhảy cóc (h1 $\rightarrow$ h2 $\rightarrow$ h3), đúng chuẩn SEO và Screen Reader. |
| **1.4.3 Contrast (Minimum)** | Độ tương phản màu chữ chính | Chữ đen `#0F172A` trên nền trắng `#FFFFFF` (Tỷ lệ: 15.5:1)<br/>Chữ xanh `#183B2B` trên nền trắng `#FFFFFF` (Tỷ lệ: 9.8:1) | **ĐẠT (PASS AAA)** | Vượt ngưỡng tiêu chuẩn WCAG AAA (ngưỡng tối thiểu là 7:1 cho văn bản thông thường). |
| **1.4.3 Contrast (Secondary)**| Độ tương phản màu chữ phụ | Chữ giá thị trường `#94A3B8` trên nền `#FFFFFF` (Tỷ lệ: 2.6:1)<br/>Màu phụ đề `#64748B` trên nền `#FFFFFF` (Tỷ lệ: 4.6:1) | ⚠️ **CẢNH BÁO (WARNING)**| Màu `#94A3B8` dưới ngưỡng 4.5:1. Dù là giá gạch ngang (không quan trọng), nên nâng lên `#64748B` để người mắt kém dễ đọc hơn. |
| **2.1.1 Keyboard Navigation** | Điều hướng bằng phím Tab | Thẻ `<a>` và `<button>` tiêu chuẩn | **ĐẠT (PASS)** | Các phần tử tương tác (link xem chi tiết, nút thêm giỏ hàng) đều nhận sự kiện focus tuần tự của bàn phím. |
| **2.4.7 Focus Visible** | Vòng hiển thị tiêu điểm khi bấm Tab | Phụ thuộc mặc định của Bootstrap | ⚠️ **CẢNH BÁO (WARNING)**| Thiếu quy tắc CSS `:focus-visible` tùy chỉnh cho các thẻ sản phẩm, khiến người dùng duyệt bằng phím khó nhận biết vị trí con trỏ. |
| **4.1.2 Name, Role, Value** | Ngữ nghĩa nút bấm & Form | `<form method="post"><button type="submit">` | **ĐẠT (PASS)** | Sử dụng đúng thẻ ngữ nghĩa HTML5, không dùng thẻ `<div>` giả lập nút bấm. |

---

### 2. CHI TIẾT CÁC ĐIỂM CẦN NÂNG CẤP TIẾP CẬN (ACCESSIBILITY ENHANCEMENTS)

#### ĐIỂM 1: CẢI THIỆN ĐỘ TƯƠNG PHẢN MÀU PHỤ ĐỀ VÀ GIÁ GẠCH
* **Thực trạng:** 
  * Giá cũ (giá thị trường gạch ngang) sử dụng màu: `color: #94A3B8 !important;`.
  * Tỷ lệ tương phản trên nền trắng chỉ đạt **2.6:1**, dưới ngưỡng tối thiểu 4.5:1 của WCAG AA.
* **Đề xuất:** Thay bằng `#64748B` (tỷ lệ tương phản 4.6:1) kết hợp với đường gạch ngang `text-decoration-line-through`. Người dùng mắt kém vẫn có thể nhận biết được mức giá giảm mà không bị mờ mắt.

#### ĐIỂM 2: BỔ SUNG TRẠNG THÁI `:focus-visible` RÕ RÀNG CHO BÀN PHÍM
* **Thực trạng:** Khi duyệt bằng phím `Tab`, các nút bấm có viền focus màu xanh nhạt của Bootstrap, nhưng đường viền thẻ card và các link tiêu đề sản phẩm chưa có hiệu ứng outline rõ rệt.
* **Đề xuất:** Bổ sung vào CSS:
  ```css
  .product-card a:focus-visible,
  .product-card button:focus-visible {
    outline: 2px solid #183B2B;
    outline-offset: 2px;
  }
  ```

#### ĐIỂM 3: BỔ SUNG `aria-label` CHO CÁC NÚT ICON
* **Thực trạng:** Nút bấm thêm vào giỏ hàng có dạng:
  ```html
  <button type="submit"><i class="fa-solid fa-cart-plus me-1"></i> Thêm</button>
  ```
* **Đánh giá:** Mặc dù đã có chữ "Thêm", nhưng khi hiển thị trên màn hình đọc (Screen Reader), người khiếm thị chỉ nghe thấy từ "Thêm".
* **Đề xuất:** Thêm thuộc tính `aria-label="Thêm [Tên sản phẩm] vào giỏ hàng"` để tăng tính rõ ràng ngữ cảnh.
