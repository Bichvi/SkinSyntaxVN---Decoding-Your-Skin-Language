# Danh Mục Nguồn Dữ Liệu Thực Tế (Exact Data Sources)
## Kiểm Toán Các Bảng & Collection Đang Vận Hành Trong SkinSyntaxVN

Tài liệu này ghi nhận chính xác toàn bộ các cơ sở dữ liệu, collection và trường dữ liệu thực tế đang tồn tại trong hệ thống và được các module gợi ý truy vấn.

---

### 1. Cơ Sở Dữ Liệu Chính: MongoDB `skinsyntax`

| Tên Collection | Khóa Chính / Định Danh | Các Trường Sử Dụng Trong Gợi Ý | Vai Trò Trong Kiến Trúc Gợi Ý |
| :--- | :--- | :--- | :--- |
| **`san_pham`** | `ma_san_pham` (int / string) | `ten_san_pham`, `gia_ban`, `loai_da`, `danh_muc_day_du`, `ma_danh_muc`, `ma_thuong_hieu`, `thanh_phan`, `thanh_phan_chinh`, `mo_ta_ngan`, `mo_ta`, `diem_danh_gia`, `so_luong_danh_gia`, `trang_thai`, `trang_thai_kho`, `so_luong_ton_kho` | **Nguồn sự thật sản phẩm:** Xây dựng vector đặc trưng TF-IDF, tính điểm IMDb Weighted Rating, lọc điều kiện hiển thị |
| **`danh_muc`** | `ma_danh_muc` (int) | `ten_danh_muc`, `parent_id`, `level` | **Phân cấp ngành hàng:** Tra cứu tên danh mục, cây phả hệ 28 nút cho nghiên cứu phân cấp |
| **`thuong_hieu`** | `ma_thuong_hieu` (int) | `ten_thuong_hieu` | **Thương hiệu:** Bổ sung trọng số thương hiệu vào vector văn bản và lọc đa dạng hóa dòng sản phẩm |
| **`khach_hang`** | `ma_kh` (int), `email` (string) | `skin_profile`, `van_de_da`, `muc_tieu_cham_soc`, `ngan_sach`, `thanh_phan_tranh`, `tieu_chi_uu_tien` | **Hồ sơ khảo sát da:** Cung cấp thông tin loại da, mối quan tâm da và mức giá trần cho Adaptive Hybrid |
| **`nguoidung`** | `id` (int), `email` (string) | `email`, `ho_ten`, `vai_tro` | **Tài khoản người dùng:** Xác thực đăng nhập và ánh xạ với `khach_hang` |
| **`hoa_don`** | `ma_hoa_don` (int) | `ma_kh`, `trang_thai`, `ngay_tao`, `tong_tien` | **Lịch sử đơn hàng:** Lọc đơn hàng hợp lệ (`VALID_PURCHASE_STATUSES`) để trích xuất tín hiệu mua sắm quá khứ |
| **`chi_tiet_hoa_don`** | `_id` | `ma_hoa_don`, `ma_san_pham`, `so_luong`, `don_gia` | **Chi tiết giỏ hàng đã mua:** Xác định các SKU đã mua cho người dùng đã đăng nhập |
| **`danh_gia_san_pham`** / **`danh_gia`** | `id_danh_gia` (int) | `ma_san_pham`, `diem_danh_gia`, `noi_dung` | **Phản hồi khách hàng:** Cung cấp điểm số và lượt đánh giá cho thuật toán Simple Recommender |
| **`lich_su_tim_kiem`** | `_id` | `email`, `tu_khoa`, `thoi_gian` | **Lịch sử tìm kiếm:** Nạp từ khóa gần đây của người dùng đã đăng nhập khi phiên hiện tại chưa có tìm kiếm |
| **`tuong_tac_nguoi_dung`** | `_id` | `loai_tuong_tac`, `ma_san_pham`, `ma_kh`, `session_id`, `metadata`, `created_at` | **Nhật ký tương tác thống nhất:** Ghi nhận sự kiện xem, tìm kiếm, giỏ hàng, mua hàng phục vụ huấn luyện ngoại tuyến |

---

### 2. Dữ Liệu Thời Gian Thực Cấp Phiên (In-Session Tracking)

Hệ thống sử dụng cơ chế lưu trữ phiên chuẩn của PHP (`$_SESSION`) để nắm bắt hành vi người dùng theo thời gian thực mà không làm tăng tải database:
- **`$_SESSION['recent_viewed_products']`:** Mảng danh sách tối đa 5 mã sản phẩm người dùng vừa xem trong phiên duyệt web hiện tại.
- **`$_SESSION['search_history']`:** Mảng danh sách 3 từ khóa tìm kiếm gần nhất của người dùng.
- **`$_SESSION['gio_hang']`:** Cấu trúc giỏ hàng hiện tại gồm danh sách `ma_san_pham` và số lượng `so_luong` tương ứng.

---

### 3. Tập Tin Bộ Đệm Ứng Dụng (Application Cache Files)
- **`backend/app/content/tfidf_cache.json`:** Bộ đệm vector TF-IDF, chuẩn vector và thông tin giá của toàn bộ catalog sản phẩm, giúp phản hồi API trong vòng vài mili-giây mà không cần tính toán lại ma trận văn bản.
- **`backend/app/content/simple_recommender_cache.json`:** Bộ đệm kết quả Top sản phẩm đánh giá cao nhất theo công thức Weighted Rating với thời gian sống TTL = 600 giây.
