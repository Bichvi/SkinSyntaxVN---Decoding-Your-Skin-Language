# LỘ TRÌNH PHÁT TRIỂN VÀ NÂNG CẤP TRONG TƯƠNG LAI (FUTURE ROADMAP)

Lộ trình nâng cấp Hệ thống Gợi ý SkinSyntaxVN khi đưa vào vận hành thương mại thực tế gồm 6 bước tuần tự, không áp đặt các ngưỡng cứng tùy tiện mà gắn liền với sự trưởng thành của dữ liệu:

---

### BƯỚC 1: TÍCH LŨY DỮ LIỆU TƯƠNG TÁC TỰ NHIÊN ĐẠI DIỆN
- Tiếp tục vận hành ổn định module `tuong_tac_nguoi_dung` trên môi trường thực tế để thu thập dữ liệu hành vi tự nhiên (Search, View, Cart, Purchase) có định danh phiên và người dùng với cơ chế chống trùng lặp request.

### BƯỚC 2: ĐÁNH GIÁ RECOMMENDER BẰNG DỮ LIỆU TỰ NHIÊN (REAL TEMPORAL HOLDOUT)
- Khi dữ liệu tự nhiên đạt quy mô đủ lớn, áp dụng giao thức Temporal Holdout trên tập tương tác thực tế của khách hàng SkinSyntaxVN để kiểm chứng độ chính xác ngoại tuyến của Adaptive Content-Based Recommender.

### BƯỚC 3: HUẤN LUYỆN VÀ ĐÁNH GIÁ BPR TRÊN DỮ LIỆU ẨN TỰ NHIÊN
- Đưa mô hình BPR (đã được xây dựng và kiểm chuẩn toán học ở Giai đoạn C.2) từ môi trường giả lập sang huấn luyện trên ma trận tương tác tự nhiên thực tế. Đánh giá tính khả thi và chất lượng phân bổ danh mục.

### BƯỚC 4: HIỆU CHUẨN ĐÓNG GÓP CỦA CF TRONG MÔ HÌNH HYBRID (CALIBRATION)
- Tích hợp điểm số BPR như một thành phần bổ trợ trong công thức Hybrid:
  $$\text{FinalScore} = w_{\text{Content}} \cdot S_{\text{Content}} + w_{\text{Skin}} \cdot S_{\text{Skin}} + w_{\text{BPR}} \cdot S_{\text{BPR}} + w_{\text{Budget}} \cdot S_{\text{Budget}}$$
- Sử dụng tập kiểm định (Validation Set) để tối ưu hóa bộ trọng số thay vì gán cứng tham số kinh nghiệm.

### BƯỚC 5: TRIỂN KHAI THỬ NGHIỆM TRỰC TUYẾN A/B TESTING
- Chia ngẫu nhiên người dùng thành nhóm Đối chứng (Control Group - Adaptive Content-Based thuần túy) và nhóm Thực nghiệm (Treatment Group - Adaptive Hybrid tích hợp BPR) để đo lường trực tiếp Click-Through Rate (CTR) và tỷ lệ chuyển đổi đơn hàng.

### BƯỚC 6: TRIỂN KHAI KHAI PHÁ LUẬT KẾT HỢP (REAL ASSOCIATION RULES)
- Sau khi khối lượng giỏ hàng và số lượng đơn hàng tích lũy đạt quy mô giao dịch đáng tin cậy, chuyển đổi tính năng "Sản phẩm mua cùng" từ quy tắc bổ trợ chuyên gia (Routine Heuristic) sang thuật toán khai phá tập mục thường xuyên tự động.
