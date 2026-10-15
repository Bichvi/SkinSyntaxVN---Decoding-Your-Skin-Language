# ORDER SIGNAL AUDIT & PURCHASE POPULARITY FEASIBILITY

**Dự án:** SkinSyntaxVN — Decoding Your Skin Language  
**Mục tiêu:** Kiểm toán toàn diện dữ liệu giao dịch thực tế (`hoa_don`, `chi_tiet_hoa_don`), phân biệt số liệu bán hàng thực tế với số liệu giả lập (`so_luong_da_ban`), và đánh giá tính khả thi của tín hiệu "Mua nhiều / Bán chạy" (Purchase Popularity).  
**Môi trường:** Production Database `skinsyntax` (MongoDB Atlas)  
**Thời gian thực hiện:** Tháng 10/2026  

---

## 1. Số Liệu Kiểm Toán Thực Tế (READ-ONLY Metrics)

Dữ liệu được trích xuất trực tiếp từ các collection đơn hàng trên MongoDB Atlas:

| Chỉ số | Giá trị thực tế | Tỷ lệ trên Catalog (2.473 SKUs) |
| :--- | :--- | :--- |
| **Tổng số hóa đơn (`hoa_don`)** | **29** | - |
| **Tổng số dòng chi tiết (`chi_tiet_hoa_don`)** | **34** | - |
| **Tổng số lượng sản phẩm bán ra thực tế** | **62 đơn vị** | - |
| **Số sản phẩm phân biệt có đơn hàng thực tế** | **16 sản phẩm** | **0,65%** |
| **Số khách hàng phân biệt từng mua hàng** | **10 khách hàng** | 50% (trên 20 tài khoản khách) |

### 1.1 Phân Bố Trạng Thái Đơn Hàng (`hoa_don.trang_thai`)
* `Hoàn thành` / `completed`: 13 đơn (44,8%)
* `Đã hủy` / `cancelled`: 7 đơn (24,1%)
* `Đã xác nhận` / `confirmed`: 3 đơn (10,3%)
* `Chờ xử lý` / `pending`: 4 đơn (13,8%)
* `Đang giao` / `shipping`: 2 đơn (6,9%)

### 1.2 Cấu Trúc Trường Có Sẵn Trong `chi_tiet_hoa_don`
* `ma_hoa_don` (int): Liên kết với hóa đơn cha.
* `ma_san_pham` (string / int): ID sản phẩm.
* `so_luong` (int): Số lượng mua.
* `don_gia` (int): Đơn giá tại thời điểm thanh toán.
* `status_thanh_toan` (string): Trạng thái thanh toán của dòng đơn.

---

## 2. Đối Soát: Real Sales vs Synthetic Proxy (`so_luong_da_ban`)

Một phát hiện kiểm toán mang tính nền tảng đã được kiểm chứng bằng thực nghiệm: **Trường `san_pham.so_luong_da_ban` hoàn toàn không phản ánh sự thật bán hàng (Not Real Sales Truth).**

### Bảng Đối Soát Mẫu Sản Phẩm Có `so_luong_da_ban` Cao:

| Mã SP | Tên sản phẩm (rút gọn) | `san_pham.so_luong_da_ban` (Synthetic) | Số lần xuất hiện trong `chi_tiet_hoa_don` (Real) | Tổng số lượng thực tế đã bán (Real) |
| :--- | :--- | :--- | :--- | :--- |
| **6204** | Nước Tẩy Trang L'Oreal Tươi Mát | **298** | **0** | **0** |
| **6203** | Combo 4 Nước Tẩy Trang L'Oreal | **298** | **0** | **0** |
| **6202** | Nước Tẩy Trang L'Oreal Căng Mịn | **298** | **0** | **0** |
| **2383** | Kem Dưỡng La Roche-Posay B5 | **298** | **0** | **0** |
| **2108** | Sữa Chống Nắng Anessa | **298** | **0** | **0** |
| **6378** | Son Kem Lì Black Rouge | 12 | **12** | **14** |
| **6375** | Kem Chống Nắng Skin1004 | 4 | **4** | **15** |

### Nhận Xét & Phân Tích Kỹ Thuật:
1. Trường `san_pham.so_luong_da_ban` phần lớn chứa dữ liệu giả lập được sinh ra từ quá trình cào dữ liệu mẫu (scraped/synthetic seed data), điển hình là các con số rập khuôn như `298` trên hàng loạt sản phẩm lớn mà thực tế chưa từng có đơn hàng nào trong hệ thống.
2. Hệ thống hiện tại có log `InteractionLogger::logPurchase` khi tạo đơn thành công, nhưng không bao giờ tăng trường `san_pham.so_luong_da_ban`.
3. Do đó, tuân thủ nghiêm ngặt **Historical Rule của đồ án**: **Tuyệt đối không sử dụng `san_pham.so_luong_da_ban` làm thước đo doanh số thực tế hay căn cứ xếp hạng Flash Sale.**

---

## 3. Khả Năng Khai Thác "Real Purchase Popularity" Cho Flash Sale

### 3.1 Mức Độ Phủ Của Tín Hiệu (Coverage Analysis)
* Toàn bộ kho hàng có 2.473 sản phẩm, nhưng tổng cộng lịch sử chỉ ghi nhận **16 sản phẩm (0,65%)** có phát sinh đơn hàng thực tế.
* Trong một chiến dịch Flash Sale dự kiến gồm **12 đến 20 sản phẩm** do Admin lựa chọn:
  * Khả năng một sản phẩm được chọn nằm trong nhóm 16 sản phẩm đã từng có đơn hàng là cực kỳ thấp ($< 1\%$).
  * Ma trận sản phẩm - lượt mua gần như rỗng hoàn toàn (**Sparsity > 99.3%**).

### 3.2 Hệ Quả Thiết Kế (Architectural Implications)
1. **Không thể sử dụng Real Purchase Popularity làm tín hiệu xếp hạng chính (Primary Ranking Signal):**
   * Nếu dùng số lượng đơn hàng thực tế để xếp hạng 12–20 sản phẩm Flash Sale, hầu như 95%–100% sản phẩm trong chiến dịch sẽ đều có điểm `RealPurchaseCount = 0`.
   * Tín hiệu bị triệt tiêu (zero-variance signal), không tạo ra được sự phân hóa có ý nghĩa để sắp xếp deal.
2. **Không được tạo tab / nút lọc "Mua nhiều nhất" (Best-Selling) trên trang Flash Sale:**
   * Việc gắn mác "Mua nhiều" hoặc "Bán chạy" cho các sản phẩm Flash Sale khi không có bằng chứng đơn hàng thực nghiệm đầy đủ là hành vi gian lận thông tin (misleading social proof).
   * Trang All Deals chỉ nên hỗ trợ các bộ lọc có bằng chứng thực:
     * *Dành cho bạn* (Personalized Relevance).
     * *Giảm nhiều nhất* (Discount Percentage).
     * *Đánh giá cao* (Weighted Rating từ 1.788 sản phẩm có review).
     * *Giá thấp &rarr; cao* / *Giá cao &rarr; thấp*.
3. **Vai trò hợp lệ của Purchase Signal trong Flash Sale:**
   * Chỉ sử dụng lịch sử mua sắm cá nhân của chính user đó (`user_purchases`) làm tín hiệu thành phần trong thuật toán cá nhân hóa Content-Based (nếu user đã từng mua sản phẩm nhóm cấp ẩm, các deal cấp ẩm sẽ có `content_similarity` cao hơn).
   * Không dùng đơn hàng tổng thể làm bằng chứng đám đông (crowd social proof) cho đến khi dữ liệu đạt ngưỡng tối thiểu (tối thiểu $\ge 500$ đơn và độ phủ danh mục $\ge 15\%$).
