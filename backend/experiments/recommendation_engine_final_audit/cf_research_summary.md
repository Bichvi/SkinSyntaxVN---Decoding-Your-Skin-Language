# BÁO CÁO NGHIÊN CỨU COLLABORATIVE FILTERING (CF RESEARCH SUMMARY)

> **MANDATORY SCIENTIFIC DISCLAIMER:**  
> *"All BPR and CF results are based on controlled synthetic experiments. They evaluate algorithmic behavior under generator assumptions and do not establish recommendation quality for real SkinSyntaxVN users."*

---

## 1. BẢNG SO SÁNH TỔNG QUAN 3 MÔ HÌNH CF ĐÃ THỰC NGHIỆM

| Tiêu chí | Item-Based kNN (Cosine) | Pointwise Funk Matrix Factorization | Bayesian Personalized Ranking (BPR) |
| :--- | :--- | :--- | :--- |
| **Loại phản hồi (Feedback Type)** | Explicit / Implicit tổng hợp | Explicit / Implicit tổng hợp | Implicit Feedback thuần túy |
| **Hàm mục tiêu (Loss Objective)** | Tương đồng Cosine giữa vector tương tác sản phẩm | Cực tiểu hóa sai số bình phương trung bình: $\min \sum (r_{ui} - \hat{r}_{ui})^2$ | Cực đại hóa xác suất hậu nghiệm xếp hạng cặp: $\min - \sum \ln \sigma(\hat{x}_{uij})$ |
| **Xử lý mẫu âm tính (Negative Handling)** | Bỏ qua hoàn toàn các ô trống (Missing as Unknown) | Coi ô trống hoặc tương tác yếu là điểm thấp gián tiếp | Lấy mẫu âm tính chủ động (Uniform Unseen Sampling): $j \notin \text{Train}(u)$ |
| **Ưu điểm chính** | Đơn giản, giải thích được thông qua đồng xuất hiện | Nén số chiều ẩn, mô hình hóa độ chệch người dùng/sản phẩm | Tối ưu hóa trực tiếp thứ tự xếp hạng (AUC/NDCG), phân bổ đề xuất trên diện rộng |
| **Nhược điểm cốt lõi** | Thất bại khi ma trận quá thưa ($>99.5\%$ ô trống) | Suy giảm hiệu năng nghiêm trọng trên dữ liệu nhị phân ẩn | Nhạy cảm với nhiễu lấy mẫu âm tính, chi phí huấn luyện cặp lớn hơn |
| **Trạng thái Production** | `EXPERIMENTAL` (Không đưa vào runtime) | `EXPERIMENTAL` (Không đưa vào runtime) | `EXPERIMENTAL` (Không đưa vào runtime) |

---

## 2. NGUYÊN LÝ CỐT LÕI: TƯƠNG TÁC CHƯA QUAN SÁT $\neq$ SỰ KHÔNG THÍCH

Một trong những đóng góp học thuật quan trọng nhất của Giai đoạn C.2 là làm rõ nguyên lý xử lý dữ liệu phản hồi ẩn (Implicit Feedback):
- **Sản phẩm chưa quan sát (Unobserved Item $j$):** Trong thực tế thương mại điện tử, việc người dùng chưa click, chưa xem hay chưa mua sản phẩm $j$ phần lớn bắt nguồn từ việc họ **chưa từng nhìn thấy sản phẩm đó trong số 2,473 sản phẩm của catalog** (Lack of Exposure), hoàn toàn **không đồng nghĩa với việc người dùng ghét hoặc không có nhu cầu đối với sản phẩm đó (Not a Confirmed Negative Preference)**.
- **Hạn chế của Pointwise Funk MF:**
  Khi áp dụng hàm mất mát MSE điểm chuẩn trên dữ liệu implicit, mô hình vô tình ép các sản phẩm chưa quan sát về điểm 0 hoặc điểm cơ sở thấp, dẫn đến việc phạt sai các sản phẩm tiềm năng nhưng chưa được hiển thị.
- **Giải pháp của Pairwise BPR:**
  BPR không cố gắng dự đoán giá trị tuyệt đối $r_{ui} \approx 0$ hay $1$. BPR chỉ đưa ra một giả định yếu nhưng thực tế: **Người dùng ưu tiên sản phẩm họ đã từng tương tác $i$ hơn là một sản phẩm ngẫu nhiên họ chưa từng tương tác $j$ ($x_{ui} > x_{uj}$)**. Giả định này giải phóng thuật toán khỏi sự thiên lệch điểm số và giúp hồi phục thứ bậc xếp hạng chính xác.

---

## 3. ĐIỂM SÁNG THỰC NGHIỆM CỦA BPR (SYNTHETIC HEADLINE FINDINGS)

1. **Hiệu năng xếp hạng tương đương Most Popular:**  
   Trên cả hai kịch bản V1 và V2, BPR đạt $\text{HR@10} \approx 0.0469$ (xấp xỉ Most Popular) và $\text{NDCG@10} = 0.0225 \rightarrow 0.0231$, cải thiện rõ rệt so với mô hình Pointwise Funk MF ($\text{HR@10} = 0.0166$).
2. **Khai phá danh mục và phân bổ gợi ý:**  
   Mô hình Item-kNN đạt độ phủ danh mục cao nhất ($46.66\%$), trong khi BPR và Most Popular tập trung hơn vào các sản phẩm có độ tin cậy tương tác cao trên toàn hệ thống (độ phủ $0.61\%$ và $0.57\%$).
3. **Độ trùng lặp danh sách (Inter-User Overlap):**  
   BPR đạt độ trùng lặp danh sách Jaccard là $0.7910$ (thấp hơn Most Popular $0.8448$), cho thấy danh sách gợi ý bắt đầu có sự khác biệt giữa các người dùng dưới hàm mục tiêu xếp hạng cặp.

---

## 4. BPR GIẢI QUYẾT ĐƯỢC GÌ VÀ CHƯA GIẢI QUYẾT ĐƯỢC GÌ

### Những vấn đề BPR đã cải thiện:
- Tối ưu hóa thứ tự xếp hạng trực tiếp trên tín hiệu ẩn nhị phân (click/view/cart/purchase).
- Cải thiện đáng kể hiệu năng xếp hạng so với việc xấp xỉ điểm số tuyệt đối bằng MSE của Funk MF pointwise trên ma trận tương tác thưa.
- Cung cấp cơ chế học cặp xác định phù hợp hơn với bản chất bài toán ranking trong thương mại điện tử.

### Những vấn đề BPR KHÔNG THỂ giải quyết:
- **Cold-Start người dùng mới:** Người dùng chưa có bất kỳ tương tác nào vẫn không thể khởi tạo vector ẩn $\mathbf{p}_u$.
- **Cold-Start sản phẩm mới:** Sản phẩm mới chưa có tương tác không thể tham gia làm positive item $i$.
- **An toàn thành phần da liễu:** BPR là thuật toán thống kê hành vi mù về mặt hóa học; nó không hiểu tính tương thích loại da hay xung đột hoạt chất (AHA/BHA/Retinol).

---

## 5. CỔNG QUYẾT ĐỊNH TRIỂN KHAI (PRODUCTION DECISION GATE)

Căn cứ trên các bằng chứng thực nghiệm:
- **Phân loại thuật toán BPR:**
  $$\mathbf{B.\ PROMISING\ FOR\ FUTURE\ ORGANIC\ VALIDATION}$$
  *(HỨA HẸN CHO QUÁ TRÌNH THỰC NGHIỆM VÀ XÁC THỰC BẰNG DỮ LIỆU TỰ NHIÊN TRONG TƯƠNG LAI)*
- **Quyết định:**
  - **KHÔNG** tích hợp BPR vào hệ thống production runtime ở giai đoạn này.
  - CF chỉ nên được cân nhắc đóng vai trò một tín hiệu bổ trợ (signal) trong Adaptive Hybrid sau khi hệ thống thu thập đủ lượng dữ liệu tương tác tự nhiên (organic interactions) đạt ngưỡng mật độ tin cậy.
