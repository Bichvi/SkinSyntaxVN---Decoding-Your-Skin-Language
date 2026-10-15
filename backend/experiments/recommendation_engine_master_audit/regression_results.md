# Kết Quả Kiểm Thử Hồi Quy Hệ Thống (Regression Results)
## Báo Cáo Thực Nghiệm Định Lượng Trên Các Kịch Bản & Điểm Cuối HTTP

Tài liệu này ghi nhận kết quả kiểm thử hồi quy toàn diện trên các route HTTP và 9 kịch bản người dùng thực tế mà không thực hiện bất kỳ thay đổi nào đối với mã nguồn sản xuất.

---

### 1. Kiểm Thử Trạng Thái Điểm Cuối HTTP (HTTP Route Status Checks)

Được thực hiện trực tiếp thông qua cURL tới máy chủ cục bộ:

| Tên Điểm Cuối (Endpoint) | Đường Dẫn URL Thực Nghiệm | Mã Trạng Thái HTTP | Thời Gian Phản Hồi | Đánh Giá Kết Quả |
| :--- | :--- | :---: | :---: | :--- |
| **Trang Chủ (Home)** | `index.php?r=home` | **HTTP 200 OK** | < 1.2s | **PASS** (Tải đầy đủ các section gợi ý và HTML) |
| **Chi Tiết Sản Phẩm (Product Detail)**| `index.php?r=chitiet&id=1` | **HTTP 200 OK** | < 0.4s | **PASS** (Tải đầy đủ thông tin sản phẩm và gợi ý tương tự) |
| **Tìm Kiếm Sản Phẩm (Search)** | `index.php?r=tatca&q=serum` | **HTTP 200 OK** | < 0.3s | **PASS** (Trả về danh sách sản phẩm khớp từ khóa) |

---

### 2. Kiểm Thử 9 Kịch Bản Định Tuyến Ngữ Cảnh (9 Production Context Scenarios)

Dữ liệu chi tiết được trích xuất từ tệp kiểm nghiệm thực tế [production_scenario_results.json](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/production_scenario_results.json):

| Kịch Bản | Trạng Thái Người Dùng & Tín Hiệu Đầu Vào | Chế Độ Thuật Toán Kích Hoạt (`algorithm_mode`) | Tín Hiệu Chủ Đạo (`dominant_signal`) | Top-1 Sản Phẩm Gợi Ý | Nhãn Giải Thích Minh Bạch (`reason`) |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **A. Cold Start** | Chưa đăng nhập, phiên mới, không có tín hiệu | `SIMPLE` (Fallback từ Adaptive) | `SIMPLE` | Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 75ml | *Sản phẩm nổi bật được yêu thích* |
| **B. Search Only** | Khách vãng lai vừa tìm kiếm từ khóa `"serum"` | `BEHAVIOR_CONTENT` | `SEARCH` | Serum Elixir Hỗ Trợ Săn Chắc Da 40ml | *Dựa trên tìm kiếm gần đây* |
| **C. View Only** | Khách vừa xem sản phẩm ID 1 (Sữa Rửa Mặt CeraVe) | `BEHAVIOR_CONTENT` | `VIEW` | Sữa Rửa Mặt CeraVe Dưỡng Ẩm 473ml | *Tương tự sản phẩm vừa xem* |
| **D. Cart Only** | Khách vừa thêm sản phẩm ID 1 vào giỏ hàng | `BEHAVIOR_CONTENT` | `CART` | Sữa Rửa Mặt CeraVe Sạch Sâu 88ml | *Phù hợp với giỏ hàng* |
| **E. Purchase Only** | Đã đăng nhập, có đơn hàng cũ đã hoàn thành | `PURCHASE_CONTENT` | `PURCHASE` | Sữa Rửa Mặt CeraVe Cho Da Thường Đến Khô | *Tương thích lịch sử mua sắm* |
| **F. Mixed Behavior**| Kết hợp Xem (ID 1) + Giỏ hàng (ID 90) + Tìm kiếm | `BEHAVIOR_CONTENT` | `CART` (Trọng số 0.35) | Kem Dưỡng Ẩm CeraVe Dành Cho Da Khô | *Dựa trên tìm kiếm gần đây • Tương tự sản phẩm vừa xem • Phù hợp với giỏ hàng* |
| **G. Profile Only** | Đã khảo sát da (Da dầu, Mụn, Ngân sách 300k) | `PROFILE_CONTENT` | `PROFILE` | Gel Rửa Mặt SVR Không Chứa Xà Phòng 400ml | *Khớp loại da • Trong ngân sách* |
| **H. Profile + Behavior** | Có khảo sát da kết hợp tìm kiếm và xem sản phẩm | `ADAPTIVE_HYBRID` | `HYBRID` | Gel Rửa Mặt Bioderma Dành Cho Da Dầu Mụn | *Dựa trên tìm kiếm gần đây • Tương tự sản phẩm vừa xem • Khớp loại da • Trong ngân sách* |
| **I. Partial Profile** | Chỉ có ngân sách 200k, không có thông tin loại da | `PARTIAL_PROFILE_FALLBACK` | `PROFILE` | Gel Rửa Mặt Eucerin Cho Da Nhờn Mụn 75ml | *Trong ngân sách • Sản phẩm nổi bật được yêu thích* |

---

### 3. Xác Minh Tính Toàn Vẹn Của Các Thuật Toán Nghiên Cứu
- **Kiểm tra đồ thị gọi hàm (Call Graph Inspection):**
  - Không có bất kỳ lệnh gọi nào tới `run_bpr_experiment.php`, `run_cf_experiment.php`, `evaluate_cf.php`.
  - Không có bất kỳ import hay tham chiếu nào tới các module K-Means trong `backend/experiments/clustering/`.
  - Không có bất kỳ thuật toán khai phá luật kết hợp nào (`Apriori`, `FP-Growth`) tham gia vào việc tính toán `FinalScore` của trang chủ.
- **Kết luận:** Hệ thống sản xuất duy trì sự phân lập tuyệt đối (Strict Isolation), bảo vệ an toàn cho trải nghiệm người dùng và tính nhất quán của kiến trúc.
