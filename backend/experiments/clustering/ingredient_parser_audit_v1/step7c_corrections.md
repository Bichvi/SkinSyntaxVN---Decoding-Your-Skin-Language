# Đính Chính Học Thuật & Chuẩn Hóa Thuật Ngữ Nghiên Cứu
## Đính Chính Các Nhận Định Tại Step 7C Cho Toàn Bộ Quá Trình Clustering

### 1. Đính Chính Cách Diễn Giải Chỉ Số ARI (Adjusted Rand Index)
- **Câu văn không hợp lệ tại Step 7C:** *"ARI(M, MI)=0.6088 tương ứng khoảng 39% sai khác phân hoạch."*
- **Đính chính khoa học:** Xóa bỏ hoàn toàn cách diễn giải `1 - ARI` như tỷ lệ phần trăm sai khác hoặc phần trăm sản phẩm bị thay đổi cụm.
- **Diễn giải chuẩn hóa:** ARI và NMI được sử dụng thuần túy để đo mức độ tương đồng giữa các phân hoạch dữ liệu (partition similarity). `ARI = 0.6088` chỉ phản ánh rằng hai phân hoạch có mức độ tương đồng không hoàn toàn, xuất phát từ việc thêm không gian thành phần mỹ phẩm làm tái cấu trúc hình học các ranh giới cụm.

### 2. Đính Chính Nhận Định Về Độ Thuần Vai Trò (Role Purity)
- **Câu văn không hợp lệ tại Step 7C:** *"Role purity đạt trên 98% chứng minh clustering phân cụm chuẩn xác hoàn toàn."*
- **Đính chính khoa học:** Các vector đặc trưng đầu vào (`Config_M` và `Config_MI`) đều chứa trực tiếp 5 chiều one-hot đại diện cho vai trò mỹ phẩm (`role_cleanser`, `role_serum`, `role_moisturizer`, `role_sunscreen`, `role_treatment`). Do đó, độ thuần vai trò cao trong các cụm là **hệ quả tất yếu của đặc trưng đầu vào (input feature reflection)**, hoàn toàn KHÔNG PHẢI là bằng chứng kiểm chứng độc lập từ bên ngoài (external validation).

### 3. Đính Chính Khẳng Định "100% Thực Thể INCI Nguyên Vẹn"
- **Câu văn không hợp lệ tại Step 7C:** *"Bộ tách từ nhận diện 100% thực thể INCI nguyên vẹn."*
- **Đính chính khoa học:** Thay thế hoàn toàn bằng thuật ngữ: **"parsed multi-token ingredient strings produced by the documented parser"** (các chuỗi thành phần đa từ được sinh ra bởi bộ phân tách đã được tài liệu hóa). Không được gọi là "chemical entity recognition" hay "100% thực thể INCI" khi chưa có sự xác nhận đối sánh từ cơ sở dữ liệu hóa học quốc tế độc lập.

### 4. Đính Chính Về Thử Nghiệm Gộp Biến Thể (Variant Collapsing)
- **Câu văn không hợp lệ tại Step 7C:** *"ARI = 0.9714 chứng minh cấu trúc macro-clusters hoàn toàn vững chắc."*
- **Đính chính khoa học:** Thay thế bằng câu văn chuẩn mực: *"Partition similarity remained high under this specific variant-collapsing sensitivity analysis."* (Mức độ tương đồng phân hoạch vẫn duy trì ở mức cao dưới phép phân tích độ nhạy gộp biến thể cụ thể này).

### 5. Đính Chính Về Phân Khúc Giá (Price Segmentation)
- Việc các cụm có mức giá trung bình khác nhau không phải là một phát hiện nội tại độc lập từ dữ liệu không nhãn, bởi vì biến `z_price` đã được đưa trực tiếp vào không gian vector với trọng số chuẩn hóa.
