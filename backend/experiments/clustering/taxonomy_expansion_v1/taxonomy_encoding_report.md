# Báo Cáo Kỹ Thuật Mã Hóa Phân Cấp Danh Mục (Taxonomy Encoding Report)
## Step 7D: Nghiên Cứu Chuyển Đổi Từ Metadata 5 Vai Trò Sang Phân Cấp Toàn Catalog

### 1. Bản Chất Nguồn Sự Thật Danh Mục (Category Source of Truth)
- Toàn bộ phân loại sản phẩm trong nghiên cứu Step 7D được xác định dựa trên khóa ngoại thực tế trong cơ sở dữ liệu MongoDB:
  `san_pham.ma_danh_muc` tham chiếu đến `danh_muc.ma_danh_muc`.
- Cấu trúc phân cấp được dựng bằng việc duyệt cây phả hệ (`parent_id`, `level`) từ nút lá (Leaf Category) lên nút gốc (Root Category).
- **Tuyệt đối không sử dụng trích xuất chuỗi con (substring matching)** trên trường `danh_muc_day_du` làm phương thức gán nhãn chính.

### 2. So Sánh Hai Phương Thức Mã Hóa Phân Cấp
1. **Mã hóa nút lá đơn lẻ (`LEAF_ONLY` - 21 chiều):**
   - Mỗi sản phẩm được biểu diễn bằng vector one-hot 21 chiều tương ứng với 21 danh mục lá.
   - *Ưu điểm:* Cực kỳ thưa, độc lập hoàn toàn giữa các danh mục.
   - *Hạn chế:* Mọi cặp danh mục khác nhau đều có khoảng cách Euclidean bằng $\sqrt{2}$. Mô hình hoàn toàn không biết rằng "Sữa Rửa Mặt" và "Tẩy Trang Mặt" cùng thuộc nhánh cha "Làm Sạch Da", trong khi "Kem Chống Nắng" thuộc nhánh khác.
2. **Mã hóa đa điểm phân cấp (`HIERARCHICAL_MULTI_HOT` - 29 chiều):**
   - Bao gồm 21 chiều danh mục lá (Leaf One-Hot) cộng thêm 8 chiều danh mục nhánh cha cấp 2 (Parent Multi-Hot: Mặt Nạ, Làm Sạch Da, Dưỡng Ẩm, Đặc Trị, Dưỡng Mắt, Dưỡng Môi, Chống Nắng Da Mặt, Bộ Chăm Sóc).
   - *Ý nghĩa hình học:* Hai sản phẩm cùng nhánh cha (ví dụ cùng là Làm Sạch Da) sẽ chia sẻ chung 1 bit đặc trưng nhánh cha, tạo khoảng cách Euclidean ngắn hơn so với hai sản phẩm khác nhánh cha, bảo toàn được cấu trúc ngữ nghĩa phân cấp thực tế của sàn thương mại điện tử.

### 3. Chuẩn Hóa Khối Đặc Trưng (Block Normalization)
- Khi mở rộng danh mục, khối taxonomy chiếm 29 chiều, khối thành phần chiếm 30 chiều, trong khi giá chỉ có 1 chiều và loại da có 3 chiều.
- Phép phân tích chuẩn hóa khối (`BLOCK_NORMALIZED`) giúp cân bằng đóng góp phương sai của từng khối ngữ nghĩa, ngăn chặn việc khối thành phần hoặc khối danh mục áp đảo hoàn toàn khoảng cách giá.
