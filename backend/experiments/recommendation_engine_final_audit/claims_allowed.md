# BẢNG PHÂN ĐỊNH TUYÊN BỐ KHOA HỌC TRONG LUẬN VĂN (CLAIMS ALLOWED)

Để bảo đảm tính trung thực khoa học và chuẩn mực học thuật trong báo cáo và bảo vệ đồ án tốt nghiệp, toàn bộ các khẳng định về hệ thống SkinSyntaxVN phải tuân thủ nghiêm ngặt hai danh mục dưới đây:

---

## 1. NHỮNG TUYÊN BỐ ĐƯỢC PHÉP TRÌNH BÀY (SUPPORTED CLAIMS)
*(Các tuyên bố này có mã nguồn thực tế, dữ liệu kiểm toán và bài kiểm thử tự động xác nhận / hỗ trợ bằng chứng)*

1. **Hỗ trợ giải quyết bài toán Cold-Start cho khách truy cập mới:**  
   Hệ thống có cơ chế Fallback tự động về mô hình Simple Recommender sử dụng công thức đánh giá Bayes mượt (IMDb Weighted Rating) với các tham số toàn cục cố định ($C = 4.889, m = 21$), đảm bảo khách chưa có dữ liệu luôn nhận được danh sách sản phẩm có chất lượng cộng đồng cao nhất.
2. **Khả năng thích ứng động theo hành vi người dùng trong phiên (Behavior Adaptation):**  
   Hệ thống có khả năng trích xuất và hợp nhất đa tín hiệu hành vi thực thời (Tìm kiếm gần nhất, Sản phẩm vừa xem, Giỏ hàng hiện tại, Lịch sử mua hàng) thành vector truy vấn ngữ cảnh để tái xếp hạng danh mục sản phẩm theo độ tương đồng TF-IDF.
3. **Hồ sơ da khách hàng có khả năng định hướng gợi ý (Profile-Aware Influence):**  
   Thông tin khảo sát da (loại da, vấn đề da, mục tiêu, ngân sách) tham gia trực tiếp vào việc định hình vector truy vấn và chấm điểm thành phần tương thích loại da ($S_{\text{Skin}}$) cùng điểm ngân sách mềm ($S_{\text{Budget}}$).
4. **BPR phân bổ gợi ý danh mục rộng hơn trên dữ liệu thực nghiệm giả lập:**  
   Trong thực nghiệm ngoại tuyến có kiểm soát, thuật toán BPR đạt tỷ lệ bao phủ danh mục $0.61\%$ (15 SKU phân biệt) so với $0.57\%$ (14 SKU) của Most Popular, đồng thời phân bổ đề xuất qua phần danh mục rộng hơn và giảm độ trùng lặp danh sách giữa các người dùng (Jaccard overlap = $0.7910$ so với $0.8448$ của Most Popular).
5. **Hạ tầng ghi nhận tương tác tự nhiên đã sẵn sàng cho nghiên cứu tương lai:**  
   Module `tuong_tac_nguoi_dung` với cơ chế chống trùng lặp theo phiên 30 phút (`VIEW_DEDUP_WINDOW_SECONDS = 1800`) đã hoạt động ổn định trên production, cho phép tích lũy dữ liệu tự nhiên sạch phục vụ kiểm định trong tương lai.
6. **Tính minh bạch và giải thích được của thuật toán (Grounded Explainability):**  
   Mọi nhãn lý do gợi ý hiển thị trên giao diện (như *"Dựa trên tìm kiếm gần đây"*, *"Phù hợp với giỏ hàng"*, *"Khớp loại da"*) đều bắt nguồn trực tiếp từ tín hiệu có thực trong phiên, không có nhãn giả lập hoặc nhãn ngẫu nhiên.
7. **Nguyên lý tương tác ẩn được thiết lập rõ ràng:**  
   Sản phẩm chưa quan sát (unobserved item) $\neq$ sự không thích hay phản hồi tiêu cực đã xác nhận (confirmed negative preference).

---

## 2. NHỮNG TUYÊN BỐ BỊ NGHIÊM CẤM / KHÔNG CÓ CƠ SỞ (UNSUPPORTED CLAIMS)
*(Nghiêm cấm tuyệt đối sử dụng các cụm từ này trong báo cáo, slide thuyết trình và phản biện)*

1. **NGHIÊM CẤM tuyên bố về "Chuẩn Y Khoa" hoặc "Cam Kết An Toàn Lâm Sàng":**  
   - *Không được dùng:* "Hệ thống gợi ý chuẩn y khoa", "Đảm bảo trị dứt điểm mụn", "Thuật toán chẩn đoán da liễu chính xác 100%".  
   - *Thực tế:* Hệ thống là nền tảng thương mại điện tử sử dụng luật đối sánh thuộc tính văn bản, không phải là thiết bị y tế hay phần mềm chẩn đoán điều trị lâm sàng.
2. **NGHIÊM CẤM tuyên bố BPR giúp tăng tỷ lệ chuyển đổi của người dùng thực:**  
   - *Không được dùng:* "BPR giúp tăng doanh thu", "BPR chứng minh hiệu quả bán hàng thực tế cho SkinSyntaxVN".  
   - *Thực tế:* BPR mới chỉ được kiểm định trên môi trường giả lập ngoại tuyến (offline synthetic experiments), chưa từng chạy thực tế trên người dùng thật.
3. **NGHIÊM CẤM tuyên bố dữ liệu giả lập đại diện cho khách hàng thực tế:**  
   - *Không được dùng:* "Kết quả CF khẳng định thói quen của người dùng SkinSyntaxVN".  
   - *Thực tế:* Dữ liệu thực nghiệm là dữ liệu nhân tạo được sinh theo phân phối xác suất Pareto có kiểm soát.
4. **NGHIÊM CẤM tuyên bố Collaborative Filtering đã sẵn sàng trên Production:**  
   - *Không được dùng:* "Hệ thống production đang ứng dụng công nghệ học máy BPR / Matrix Factorization".  
   - *Thực tế:* Toàn bộ các mô hình CF đang ở trạng thái `EXPERIMENTAL` ngoại tuyến.
5. **NGHIÊM CẤM tuyên bố Luật kết hợp / Mua kèm đại diện cho xu hướng tiêu dùng lớn:**  
   - *Không được dùng:* "Thuật toán khai phá luật kết hợp FP-Growth tìm ra xu hướng mua kèm chính xác của khách hàng".  
   - *Thực tế:* Production mới chỉ có 29 hóa đơn thực tế (chưa đủ ý nghĩa thống kê); mục mua kèm hiện đang sử dụng luật chuyên gia bổ trợ quy trình (Routine Complement Heuristic).
6. **NGHIÊM CẤM tuyên bố các trọng số thuật toán là tối ưu toán học:**  
   - *Không được dùng:* "Bộ trọng số 0.70 / 0.20 / 0.10 là bộ trọng số tối ưu toàn cục".  
   - *Thực tế:* Đây là các tham số luật kinh nghiệm kỹ thuật (Engineering Baselines), chưa qua tối ưu hóa trên dữ liệu gắn nhãn.
