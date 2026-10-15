# CÁC GIỚI HẠN KHOA HỌC CỦA HỆ THỐNG (SYSTEM LIMITATIONS)

Một báo cáo học thuật chất lượng cao đòi hỏi sự thẳng thắn về các hạn chế hiện tại. Dưới đây là các giới hạn kỹ thuật và thực nghiệm của Hệ thống Gợi ý SkinSyntaxVN:

---

## 1. DỮ LIỆU TƯƠNG TÁC TỰ NHIÊN CÒN KHIÊM TỐN (LIMITED ORGANIC DATA)
- Hệ thống mới triển khai hạ tầng ghi nhận sự kiện (Interaction Logger). Số lượng tương tác tự nhiên trong cơ sở dữ liệu `skinsyntax` hiện tại mới đạt quy mô nhỏ, chưa đủ để huấn luyện trực tiếp các mô hình học máy phức tạp có hàng nghìn tham số.

## 2. COLLABORATIVE FILTERING MỚI ĐƯỢC ĐÁNH GIÁ TRÊN DỮ LIỆU GIẢ LẬP (SYNTHETIC EVALUATION)
- Mặc dù thực nghiệm BPR, Funk MF và Item-kNN được thiết kế nghiêm ngặt với giao thức Leave-One-Out và kiểm định Bootstrap 95%, toàn bộ dữ liệu tương tác đều được sinh từ bộ mô phỏng xác suất Pareto (`cf_experiment_v1` & `cf_experiment_v2_corrected`).
- Dữ liệu giả lập không thể phản ánh trọn vẹn những yếu tố nhiễu, sở thích cảm tính bất định và hành vi bất thường của người dùng thực tế.

## 3. DỮ LIỆU GIAO DỊCH CHƯA ĐỦ ĐỂ KHAI PHÁ LUẬT KẾT HỢP (INSUFFICIENT BASKET TRANSACTIONS)
- Cơ sở dữ liệu production hiện có **29 hóa đơn (với 34 dòng chi tiết sản phẩm)**. Đây là cỡ mẫu quá nhỏ để áp dụng các thuật toán khai phá tập mục thường xuyên (như Apriori hoặc FP-Growth) với độ hỗ trợ (support) và độ tin cậy (confidence) có ý nghĩa thống kê.
- Tính năng "Sản phẩm thường được mua cùng" hiện phải vận hành dựa trên luật bổ trợ quy trình chăm sóc da (Routine Complement Heuristic) thay vì luật kết hợp học từ dữ liệu.

## 4. TRỌNG SỐ THUẬT TOÁN ĐƯỢC THIẾT LẬP THỦ CÔNG (MANUALLY CONFIGURED BASELINE WEIGHTS)
- Các trọng số kết hợp tín hiệu hành vi ($\text{cart} = 0.35, \text{view} = 0.35, \text{search} = 0.20, \text{purchase} = 0.10$) cũng như trọng số xếp hạng cuối cùng ($0.70 \text{ Content} + 0.20 \text{ Skin} + 0.10 \text{ Budget}$) là **các giá trị giả định kỹ thuật ban đầu (Engineering Heuristic Baselines)**, chưa được tinh chỉnh bằng các phương pháp tối ưu tham số (Grid Search / Bayesian Optimization) trên dữ liệu thực.

## 5. CHƯA CÓ THỰC NGHIỆM TRỰC TUYẾN A/B TESTING (NO ONLINE A/B TEST)
- Toàn bộ kết quả đánh giá mới chỉ dừng lại ở các chỉ số ngoại tuyến (Offline Ranking Metrics: HitRate, Precision, Recall, NDCG, MRR).
- Hệ thống chưa thực hiện thử nghiệm A/B Testing trên môi trường trực tuyến để đo lường các chỉ số kinh doanh thực tế như:
  - Tỷ lệ nhấp chuột (Click-Through Rate - CTR).
  - Tỷ lệ chuyển đổi mua hàng (Conversion Rate - CVR).
  - Giá trị đơn hàng trung bình (Average Order Value - AOV).

## 6. KHÔNG GIAN THÀNH PHẦN KHÔNG PHẢI LÀ CHẨN ĐOÁN Y KHOA (NON-MEDICAL INGREDIENT MATCHING)
- Bộ lọc loại da và kiểm tra thành phần hoạt chất dựa trên từ khóa văn bản và danh mục định sẵn của nhà sản xuất. Hệ thống không có khả năng phân tích nồng độ hoạt chất, dạng bào chế, độ pH hay tương tác thuốc phức tạp như bác sĩ chuyên khoa da liễu.

## 7. GIỚI HẠN LƯU TRỮ HÀNH VI THEO PHIÊN (SESSION PERSISTENCE LIMITATIONS)
- Đối với khách vãng lai chưa đăng nhập, các tín hiệu hành vi (search, view, cart) được lưu tạm thời trong bộ nhớ `$_SESSION` của PHP. Khi phiên hết hạn (Session Timeout) hoặc người dùng đổi trình duyệt/thiết bị, các tín hiệu này sẽ biến mất và hệ thống sẽ quay về trạng thái Cold-Start ban đầu.
