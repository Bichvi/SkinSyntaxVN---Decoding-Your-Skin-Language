# Các Giới Hạn Đã Biết Của Nghiên Cứu Phân Cụm (Known Limitations)
## Tổng Hợp Toàn Bộ Rào Cản Kỹ Thuật, Dữ Liệu & Phương Pháp Luận

Tài liệu này ghi nhận trung thực và đầy đủ các giới hạn cố hữu của thuật toán phân cụm K-Means khi áp dụng trên danh mục sản phẩm của SkinSyntaxVN.

---

### 1. Khoảng Trống Đánh Giá Chuyên Gia & Người Dùng (Human Evaluation Gap)
- **Tình trạng hiện tại:** Toàn bộ các kết quả từ Step 6A đến Step 7D đều dựa trên các chỉ số toán học nội tại (Silhouette, WCSS, ARI, NMI, Jaccard).
- **Khoảng trống nghiêm trọng:**
  - Chưa từng có một cuộc đánh giá độc lập theo quy trình định trước (human relevance evaluation with a predefined protocol) từ người dùng hoặc người đánh giá độc lập. Đánh giá từ bác sĩ da liễu / chuyên gia chuyên môn (dermatologist/domain-expert review) chỉ bắt buộc nếu hệ thống muốn đưa ra các khẳng định về mức độ phù hợp lâm sàng / da liễu (dermatological/clinical suitability claims).
  - Chưa có dữ liệu thực tế về việc liệu người dùng có xem các sản phẩm trong cùng một cụm là "có thể thay thế cho nhau" hay "phù hợp với quy trình chăm sóc da của họ" hay không.
  - **Cam kết học thuật:** Nghiên cứu không tự tạo ra bất kỳ điểm số chuyên gia giả định (fake human scores) nào.

---

### 2. Giới Hạn Về Dữ Liệu Thành Phần (Ingredient Data Limitations)
- **Thiếu thông tin nồng độ định lượng:** Danh sách thành phần theo chuẩn INCI chỉ liệt kê theo thứ tự giảm dần nồng độ (xuống đến 1%), không công bố tỷ lệ % thực tế (ví dụ: Niacinamide 2% so với 10% có vector gần như tương đồng).
- **Không có dữ liệu pH và công nghệ bào chế:** Hiệu quả của các acid (AHA/BHA/L-Ascorbic Acid) phụ thuộc hoàn toàn vào độ pH của nền sản phẩm và công nghệ dẫn xuất (liposome, encapsulate); những thông tin này hoàn toàn vắng mặt trong chuỗi văn bản thô.
- **27 sản phẩm khuyết thành phần (1.09%):** Bắt buộc phải gán vector 0; dù được xử lý cờ minh bạch nhưng vẫn tạo ra các điểm dữ liệu dị biệt tại gốc tọa độ của khối thành phần.

---

### 3. Tác Động Gây Méo Cụm Của Dòng Biến Thể (Variant Dominance)
- 364 dòng sản phẩm đa biến thể (898 sản phẩm) chiếm hơn 36% catalog.
- Việc các sản phẩm khác dung tích (ví dụ chai 50ml, 150ml, 400ml) cùng tồn tại trong không gian huấn luyện khiến K-Means bị kéo lệch tâm về phía các cụm vi mô biến thể.
- Kiểm định độ nhạy tại Step 7D khẳng định sự thay đổi thực chất (`material change`, ARI = 0.5655, NMI = 0.7458) khi gộp biến thể.

---

### 4. Mất Cân Bằng Danh Mục Tự Nhiên (Category Imbalance)
- Phân bố số lượng sản phẩm giữa các danh mục lá cực kỳ phân tán: từ 2 sản phẩm (*Sản Phẩm Đặc Trị Khác*) đến 549 sản phẩm (*Mặt Nạ Giấy*).
- Hệ số Gini đạt `0.5631`.
- K-Means có xu hướng tạo ra các cụm có kích thước tương đối đồng đều về mặt không gian hình cầu, dẫn đến việc các danh mục khổng lồ bị xé lẻ thành nhiều cụm, trong khi các danh mục siêu nhỏ bị hòa tan vào các cụm lớn hơn.

---

### 5. Cạm Bẫy Chỉ Số Hình Học (Geometric Metrics Pitfall)
- Silhouette score rất cao (0.75 – 0.79) của các cấu hình chỉ dùng taxonomy thuần túy là hệ quả của việc các điểm dữ liệu trùng nhau trên các đỉnh siêu lập phương nhị phân (discrete vertices), không phản ánh tính tối ưu về mặt ngữ nghĩa.
- Ngược lại, việc Silhouette score giảm khi thêm các biến liên tục (giá chuẩn hóa, thành phần SVD) không đồng nghĩa với suy giảm chất lượng, mà phản ánh sự chuyển dịch hình học sang không gian đa chiều hỗn hợp.
