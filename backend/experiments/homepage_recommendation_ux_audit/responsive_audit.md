# KIỂM TOÁN TƯƠNG THÍCH ĐA THIẾT BỊ (RESPONSIVE UX AUDIT)
## SKINSYNTAXVN HOMEPAGE RECOMMENDATION UX AUDIT — PHASE 1

Tài liệu này kiểm toán mã nguồn HTML và CSS của các khối gợi ý trên trang chủ để phát hiện các rủi ro vỡ layout, tràn văn bản hoặc khó tương tác trên các độ phân giải màn hình khác nhau (Desktop, Tablet, Mobile).

---

### 1. BẢNG PHÂN TÍCH THEO CÁC MỐC ĐỘ PHÂN GIẢI (VIEWPORT BREAKPOINTS)

Hệ thống sử dụng hệ thống lưới Bootstrap 5 kết hợp mã CSS tùy chỉnh:

| Thành phần giao diện | Desktop ($\ge 992\text{px}$) | Tablet ($768\text{px} - 991\text{px}$) | Mobile ($< 768\text{px}$, tiêu biểu 360–390px) | Đánh giá rủi ro hiển thị |
| :--- | :--- | :--- | :--- | :--- |
| **Section Header (Khối gợi ý)** | Flex row: Tiêu đề bên trái, nút CTA bên phải | Flex row: Tiêu đề bên trái, CTA bên phải | `flex-column`: CTA rớt xuống dưới tiêu đề | **Tốt**: Header co giãn tự nhiên, không bị chồng chéo. |
| **Lưới sản phẩm (Product Grid)** | 4 cột (`col-md-3`), rộng ~260px/thẻ | 4 cột (`col-md-3`), rộng ~165px/thẻ | 2 cột (`col-6`), rộng ~155–165px/thẻ | ⚠️ **CẢNH BÁO TABLET & MOBILE**: Bị chật hẹp ở cả Tablet và Mobile. |
| **Ảnh sản phẩm (Thumbnail)** | Tỷ lệ 1:1, hiển thị sắc nét | Tỷ lệ 1:1, ảnh co đều | Tỷ lệ 1:1, ảnh co đều | **Tốt**: Thuộc tính `aspect-ratio: 1/1` giữ khung ảnh vuông vức. |
| **Huy hiệu trên ảnh (Badges)** | Đủ khoảng trống 2 góc | Bắt đầu thu hẹp khoảng cách | Dễ va chạm nhau nếu cả 2 nhãn đều dài | ⚠️ **RỦI RO VA CHẠM**: Nhãn sale góc trái và tag góc phải dễ đè nhau. |
| **Tiêu đề sản phẩm (Title)** | 2 dòng (`line-clamp: 2`), đẹp | 2 dòng, chiều cao ổn định | 2 dòng, chiều cao ổn định | **Tốt**: Thuộc tính `min-height: 2.4em` giúp các thẻ bằng chiều cao nhau. |
| **Cụm giá tiền (Price wrap)** | Đủ chỗ cho cả giá bán và giá gạch | Thiếu chỗ, dễ rớt dòng giá cũ | Rất chật hẹp, giá cũ bị đẩy xuống dòng | ⚠️ **RỦI RO RỚT DÒNG**: Thiếu `flex-wrap: wrap`, dễ tràn viền giá tiền. |
| **Nút bấm hành động (Actions)** | 2 nút ngang (~110px/nút), bấm tốt | 2 nút ngang (~65px/nút), chữ bị chật | 2 nút ngang (~60px/nút), chữ bị ngắt đôi | ❌ **LỖI NGHIÊM TRỌNG (P1)**: Chữ "Mua ngay" bị rớt dòng thành 2 hàng. |

---

### 2. CHI TIẾT CÁC LỖI GIAO DIỆN CÓ BẰNG CHỨNG MÃ NGUỒN

#### LỖI 1: NÚT BẤM BỊ CO CỤM DƯỚI 65PX TRÊN MOBILE VÀ TABLET
* **Bằng chứng mã nguồn (`frontend/views/home.php` dòng 216–234):**
  ```html
  <div class="product-card-actions d-grid gap-2" style="grid-template-columns: 1fr 1fr;">
    <button class="btn btn-sm w-100" style="padding: 7px 0; font-size: 0.78rem;">
      <i class="fa-solid fa-cart-plus me-1"></i> Thêm
    </button>
    <button class="btn btn-sm w-100 text-white" style="padding: 7px 0; font-size: 0.78rem;">
      Mua ngay
    </button>
  </div>
  ```
* **Phân tích toán học vùng hiển thị (Viewport Math):**
  * Trên iPhone 12/13/14 (chiều rộng 390px):
    * Chiều rộng container khả dụng: $390\text{px} - 24\text{px (padding container)} = 366\text{px}$.
    * Chiều rộng 1 cột `col-6` sau khi trừ khoảng cách 16px giữa 2 cột: $(366 - 16)/2 = 175\text{px}$.
    * Padding bên trong card: $12\text{px} \times 2 = 24\text{px}$. Chiều rộng nội dung còn lại: $175 - 24 = 151\text{px}$.
    * Khoảng cách giữa 2 nút: `gap-2` = 8px.
    * Chiều rộng khả dụng cho mỗi nút: $(151 - 8)/2 = 71.5\text{px}$!
  * Trên màn hình Samsung Galaxy A-series (chiều rộng 360px):
    * Chiều rộng mỗi nút chỉ còn **$64\text{px}$**!
  * **Hậu quả thực tế:**
    * Nút 1: Icon giỏ hàng + chữ "Thêm" bị dính sát mép viền.
    * Nút 2: Chữ "Mua ngay" bị ngắt thành 2 dòng ("Mua" ở dòng trên, "ngay" ở dòng dưới) hoặc bị tràn ra ngoài khung viền nút.
* **Đề xuất khắc phục:** 
  * Sử dụng media query CSS: Trên màn hình `< 576px`, chuyển sang hiển thị **1 nút duy nhất** (`grid-template-columns: 1fr;`): Nút *"Thêm vào giỏ"*, nhấp vào card để xem chi tiết và mua ngay.

---

#### LỖI 2: XUNG ĐỘT VỊ TRÍ 2 HUY HIỆU GÓC TRÊN ẢNH SẢN PHẨM
* **Bằng chứng mã nguồn (`frontend/views/home.php` dòng 155–168):**
  * Huy hiệu Sale đặt ở: `top: 10px; left: 10px;` (Chiều rộng ~45px).
  * Huy hiệu Tag đặt ở: `top: 10px; right: 10px;` (Chiều rộng từ 70px – 110px đối với các nhãn như "Tương tự đã xem").
* **Phân tích:** 
  * Trên chiều rộng ảnh 150px, tổng độ rộng của 2 huy hiệu: $45\text{px} + 90\text{px} = 135\text{px}$, cộng thêm lề 2 bên $10\text{px} \times 2 = 20\text{px}$, tổng cộng là $155\text{px} > 150\text{px}$!
  * Hai nhãn này sẽ bị đè lên nhau hoặc che khuất toàn bộ phần đầu của ảnh sản phẩm.
* **Đề xuất khắc phục:** 
  * Giữ huy hiệu Sale ở góc trái ảnh.
  * Chuyển nhãn lý do gợi ý (`reason tag`) xuống phía dưới ảnh (bên trên thương hiệu), tách biệt hoàn toàn khỏi ảnh đại diện.

---

#### LỖI 3: PADDING CONTAINER SECTION QUÁ LỚN TRÊN MOBILE
* **Bằng chứng mã nguồn (`frontend/views/home.php` dòng 524):**
  ```html
  <section class="mb-5 p-4 bg-white border" style="border-radius: 16px;">
  ```
* **Phân tích:** Lớp `p-4` của Bootstrap tạo padding 24px (1.5rem) ở tất cả các cạnh. Trên màn hình 360px, việc mất 48px cho padding của section cộng với 24px của container ngoài cùng khiến không gian hiển thị sản phẩm bị bóp nghẹt không cần thiết.
* **Đề xuất khắc phục:** Đổi thành `p-3 p-md-4` (padding 16px trên mobile, 24px trên tablet/desktop).
