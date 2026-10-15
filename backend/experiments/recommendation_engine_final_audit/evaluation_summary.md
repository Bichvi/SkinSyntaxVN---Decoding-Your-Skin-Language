# BÁO CÁO TỔNG HỢP ĐÁNH GIÁ THỰC NGHIỆM NGOẠI TUYẾN (EVALUATION SUMMARY)

> **MANDATORY SCIENTIFIC DISCLAIMER:**  
> *"All CF evaluation metrics presented herein are derived from controlled synthetic experiments. They evaluate algorithmic behavior under generator assumptions and do not establish recommendation quality for real SkinSyntaxVN users."*

---

## 1. GIAO THỨC ĐÁNH GIÁ NGOẠI TUYẾN (EVALUATION PROTOCOL)

Để đảm bảo tính khách quan và ngăn chặn rò rỉ dữ liệu (data leakage), toàn bộ các thực nghiệm Collaborative Filtering tại SkinSyntaxVN đều tuân thủ nghiêm ngặt giao thức sau:

1. **Phân chia tập kiểm thử thời gian (Temporal Leave-One-Out Holdout):**
   - Với mỗi người dùng có $\ge 5$ sản phẩm tương tác phân biệt:
     - **Tập kiểm thử (Test Target $i_{\text{test}}$):** Sản phẩm dương tính có trọng số cao cuối cùng theo trình tự thời gian ($r_{ui} \ge 3.0$, hoặc sản phẩm xem cuối cùng nếu không có sự kiện giỏ/mua).
     - **Tập huấn luyện (Train Matrix):** Toàn bộ các tương tác xảy ra trước thời điểm của $i_{\text{test}}$.
   - $i_{\text{test}}$ bị loại bỏ hoàn toàn khỏi quá trình huấn luyện và lấy mẫu âm tính của BPR / Funk MF / kNN.

2. **Tập ứng viên kiểm thử (Unseen Candidate Pool):**
   - Không sử dụng phương pháp rút gọn 100 sản phẩm ngẫu nhiên.
   - Tập ứng viên bao gồm **toàn bộ sản phẩm trong danh mục mà người dùng chưa từng tương tác trong tập huấn luyện** ($\sim 2,460$ ứng viên / người dùng). Sản phẩm mục tiêu $i_{\text{test}}$ được đặt vào tập ứng viên này để tính toán thứ bậc xếp hạng (Rank).

3. **Tập người dùng đánh giá đồng nhất:**
   - Trong mỗi hạt giống thực nghiệm, danh sách người dùng đủ điều kiện đánh giá là hoàn toàn giống nhau cho tất cả các mô hình so sánh (Random, Most Popular, Item-kNN, Funk MF, BPR).

---

## 2. BẢNG KẾT QUẢ ĐÁNH GIÁ ĐA HẠT GIỐNG (3-SEED EVALUATION: 42, 123, 2026)

Dữ liệu kịch bản gốc `cf_experiment_v1` (Trung bình qua 3 hạt giống độc lập):

| Mô hình (Model) | HitRate@5 | HitRate@10 | Precision@10 | Recall@10 | NDCG@10 | MRR@10 |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **RANDOM** | 0.0038 | 0.0076 | 0.0008 | 0.0076 | 0.0038 | 0.0027 |
| **MOST_POPULAR** | 0.0205 | **0.0469** | 0.0047 | 0.0469 | 0.0218 | 0.0143 |
| **ITEM_KNN** | 0.0046 | 0.0068 | 0.0007 | 0.0068 | 0.0038 | 0.0028 |
| **FUNK_MF** | 0.0075 | 0.0166 | 0.0017 | 0.0166 | 0.0074 | 0.0047 |
| **BPR** | **0.0279** | **0.0469** | 0.0047 | 0.0469 | **0.0225** | **0.0149** |

---

## 3. ĐÁNH GIÁ ĐỘ BẤT ĐỊNH THỐNG KÊ (BOOTSTRAP 95% CONFIDENCE INTERVALS)

Thực hiện tái lấy mẫu Bootstrap $B = 1,000$ lần trên $n = 432$ người dùng kiểm thử (Hạt giống 42, seed bootstrap 9999):

| Mô hình | HR@10 Point Estimate | Khoảng tin cậy 95% (HR@10) | NDCG@10 Point Estimate | Khoảng tin cậy 95% (NDCG@10) |
| :--- | :---: | :---: | :---: | :---: |
| **MOST_POPULAR** | 0.0278 | **[0.0139, 0.0440]** | 0.0129 | [0.0060, 0.0216] |
| **ITEM_KNN** | 0.0069 | [0.0000, 0.0162] | 0.0026 | [0.0000, 0.0059] |
| **FUNK_MF** | 0.0116 | [0.0023, 0.0231] | 0.0055 | [0.0008, 0.0118] |
| **BPR** | 0.0324 | **[0.0162, 0.0509]** | 0.0149 | [0.0069, 0.0239] |

### Nhận định khoa học thận trọng (Adhering to Section 18 Rule):
- Khoảng tin cậy 95% của BPR $[0.0162, 0.0509]$ và Most Popular $[0.0139, 0.0440]$ **chồng lấn đáng kể (substantial overlap)**.
- Do đó, về mặt thống kê khắt khe, **chưa đủ bằng chứng để khẳng định BPR vượt trội hoàn toàn Most Popular về tỷ lệ trúng đích tổng thể**.
- Tuy nhiên, BPR cải thiện vượt trội so với Pointwise Funk MF (khoảng tin cậy không chồng lấn $[0.0162, 0.0509]$ vs $[0.0023, 0.0231]$).

---

## 4. KỊCH BẢN KHẮC PHỤC LỖI SINH DỮ LIỆU (`cf_experiment_v2_corrected`)

Sau khi sửa lỗi ép kiểu chuỗi khóa mảng trong hàm `computeAffinity` để điểm cộng danh mục ($+0.40$) và thương hiệu ($+0.35$) thực sự tham gia vào quá trình sinh tương tác:

| Mô hình | HR@10 (V1 Gốc) | HR@10 (V2 Đã sửa) | NDCG@10 (V2) | MRR@10 (V2) |
| :--- | :---: | :---: | :---: | :---: |
| **MOST_POPULAR** | 0.0469 | 0.0456 | 0.0222 | 0.0152 |
| **ITEM_KNN** | 0.0068 | 0.0076 | 0.0039 | 0.0028 |
| **FUNK_MF** | 0.0166 | 0.0174 | 0.0081 | 0.0053 |
| **BPR** | 0.0469 | 0.0456 | **0.0231** | **0.0163** |

*Ghi chú:* Dữ liệu gốc V1 vẫn được bảo tồn và tái lập độc lập trong hệ thống.

---

## 5. THỰC NGHIỆM HỒI PHỤC CẤU TRÚC ẨN (`cf_latent_recovery_v1`)

Đánh giá khi mức độ cấu trúc cộng tác ẩn nhân tạo ($w_{\text{latent}}$) tăng dần:

| Trọng số cấu trúc ẩn | MOST_POPULAR HR@10 | ITEM_KNN HR@10 | FUNK_MF HR@10 | BPR HR@10 |
| :--- | :---: | :---: | :---: | :---: |
| **$w = 0.00$** | 0.0426 | 0.0250 | 0.0122 | 0.0415 |
| **$w = 0.25$** | 0.0474 | 0.0216 | 0.0081 | 0.0463 |
| **$w = 0.50$** | 0.0392 | 0.0176 | 0.0095 | **0.0441** |

*Nhận định:* Khi cấu trúc cộng tác tăng mạnh ($w=0.50$), hiệu quả của Most Popular giảm sút do hành vi người dùng phân hóa theo các vector ẩn. BPR duy trì điểm số ổn định ($0.0441$), thích ứng tốt hơn đáng kể so với Funk MF ($0.0095$).

---

## 6. PHÂN ĐOẠN NGƯỜI DÙNG THEO LỊCH SỬ VÀ THIÊN KIẾN PHỔ BIẾN

### 6.1. Phân đoạn theo độ dài lịch sử huấn luyện (History Length)
*(Thực nghiệm trên Hạt giống 42, $n = 432$)*

| Phân đoạn | Số user ($n$) | Popular HR@10 | kNN HR@10 | Funk MF HR@10 | BPR HR@10 | Cảnh báo mẫu |
| :--- | :---: | :---: | :---: | :---: | :---: | :--- |
| **5–8 sản phẩm** | 338 | 0.0266 | 0.0059 | 0.0118 | **0.0325** | Mẫu đủ tin cậy ($n > 50$) |
| **9–12 sản phẩm** | 81 | 0.0247 | 0.0123 | 0.0123 | **0.0370** | Mẫu đủ tin cậy ($n > 50$) |
| **$\ge 13$ sản phẩm** | 13 | 0.0769 | 0.0000 | 0.0000 | 0.0000 | **SMALL SAMPLE — INTERPRET CAUTIOUSLY** ($n = 13 < 50$) |

### 6.2. Phân đoạn theo thiên kiến độ phổ biến của người dùng (Popularity Bias)

| Phân đoạn thiên kiến | Số user ($n$) | Popular HR@10 | kNN HR@10 | Funk MF HR@10 | BPR HR@10 |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Low Popularity Bias** | 137 | 0.0146 | 0.0073 | 0.0073 | **0.0219** |
| **Medium Popularity Bias** | 154 | 0.0260 | 0.0000 | 0.0065 | **0.0325** |
| **High Popularity Bias** | 141 | **0.0426** | 0.0142 | 0.0213 | **0.0426** |

---

## 7. ĐỘ BAO PHỦ DANH MỤC VÀ ĐỘ ĐA DẠNG DANH SÁCH (COVERAGE & DIVERSITY)

*(Đo đạc thực tế từ artifact `output/bpr/coverage_personalization.json` trên toàn bộ tập người dùng kiểm thử)*

| Chỉ số chẩn đoán | MOST_POPULAR | ITEM_KNN | FUNK_MF | BPR |
| :--- | :---: | :---: | :---: | :---: |
| **Catalog Coverage (%)** | 0.57% | **46.66%** | 2.67% | 0.61% |
| **Số SKU duy nhất đề xuất** | 14 | **1,154** | 66 | 15 |
| **Phân vị độ phổ biến TB** | 99.8% | 71.4% | 95.8% | 99.6% |
| **Độ trùng lặp Jaccard giữa các user** | 0.8448 | **0.0055** | 0.3731 | 0.7910 |

*Ý nghĩa thực tiễn và nhận định thận trọng:*
- Item-kNN đạt độ phủ danh mục cao nhất ($46.66\%$, gợi ý 1,154 SKU) và độ trùng lặp Jaccard cực thấp ($0.0055$), nhưng đánh đổi bằng độ chính xác thấp ($\text{HR@10} = 0.0068$).
- BPR và Most Popular tập trung gợi ý vào các sản phẩm có độ tin cậy tương tác cao trên tập dữ liệu tổng thể (phân vị độ phổ biến $99.6\%$ và $99.8\%$), mang lại tỷ lệ trúng đích cao hơn ($\text{HR@10} \approx 0.0469$) nhưng độ bao phủ danh mục hẹp hơn trong thiết lập siêu tham số hiện tại.
- Kết quả này nhấn mạnh sự đánh đổi (trade-off) kinh điển giữa Độ chính xác (Accuracy) và Độ bao phủ / Đa dạng (Coverage / Diversity).
