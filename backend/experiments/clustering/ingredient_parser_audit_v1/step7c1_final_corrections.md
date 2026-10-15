# Đính Chính Học Thuật Bổ Sung Sau Kiểm Định Step 7C.1
## Bổ Sung Ràng Buộc Phát Ngôn & Chuẩn Hóa Khái Niệm Thống Kê Trước Khi Mở Rộng Step 7D

Tài liệu này được lập nhằm chuẩn hóa dứt điểm các cách diễn đạt học thuật tại Step 7C.1, đảm bảo tính chuẩn xác và trung thực tuyệt đối trong toàn bộ chuỗi nghiên cứu phân cụm mỹ phẩm SkinSyntaxVN.

---

### 1. Đính Chính Về Khả Năng Sửa Lỗi Diol Của Parser V2
- **Cách diễn đạt không hợp lệ:** *"Parser V2 sửa 100% mọi lỗi diol."*
- **Cách diễn đạt chuẩn mực khoa học:**
  > *"Trong các pattern lỗi được kiểm toán tại Step 7C.1, Parser V2 không còn ghi nhận split error tương ứng."*
- **Cơ sở khoa học:** Parser V2 hoạt động dựa trên các mẫu regex và quy tắc tiền bảo vệ `__NUMCOMMA__`. Trên các mẫu danh pháp được rà soát và kiểm toán cấu trúc trực tiếp (như `1,2-hexanediol`, `1,3-propanediol`, `2,3-butanediol`), lỗi phân đoạn do dấu phẩy đã được triệt tiêu hoàn toàn. Tuy nhiên, không khẳng định bao quát mọi biến thể danh pháp diol chưa từng xuất hiện hoặc các lỗi crawling dị biệt ngoài phạm vi kiểm toán.

---

### 2. Đính Chính Cách Diễn Giải Chỉ Số Trùng Khớp Láng Giềng (Jaccard@10)
- **Cách diễn đạt không hợp lệ:** *"1 - Jaccard@10 = % láng giềng bị thay đổi."*
- **Cách diễn đạt chuẩn mực khoa học:**
  > *"Với Mean Jaccard@10 = 0.8140, chỉ được diễn giải rằng: Neighbor sets giữa V1 và V2 có mức overlap cao nhưng không đồng nhất."*
- **Cơ sở khoa học:** Jaccard similarity là độ đo tỷ lệ phần giao trên phần hợp giữa hai tập hợp $\{N_{10}^{V1}\}$ và $\{N_{10}^{V2}\}$. Giá trị $0.8140$ phản ánh rằng đa số láng giềng trong Top-10 tiếp tục xuất hiện chung ở cả hai không gian biểu diễn, nhưng có sự dịch chuyển về thứ hạng khoảng cách hoặc một phần nhỏ láng giềng biên bị thay thế.

---

### 3. Đính Chính Nhận Định Về Chất Lượng Láng Giềng V2 So Với V1
- **Cách diễn đạt không hợp lệ:** *"Láng giềng của V2 thực sự tốt hơn V1."*
- **Đính chính khoa học:**
  > *"Không có ground-truth recommendation relevance hoặc đánh giá từ người dùng/chuyên gia để kết luận rằng láng giềng của V2 'tốt hơn' V1. Việc sửa đổi parser chỉ đảm bảo tính nhất quán cấu trúc chuỗi danh pháp và phản ánh đúng vector đặc trưng thành phần, loại bỏ tương quan giả do lỗi chia tách từ."*

---

### 4. Định Danh Chuẩn Xác Cho Chiều Không Gian SVD 30D
- **Cách diễn đạt không hợp lệ:** *"10D thiếu chi tiết thành phần; 50D gây nhiễu thưa hơn; 30D là tối ưu."*
- **Cách diễn đạt chuẩn mực khoa học:**
  > *"SVD 30D thuần túy là REFERENCE CONFIGURATION FOR CONTINUITY (Cấu hình tham chiếu nhằm đảm bảo tính kế thừa liên tục với các phân tích trước). Không khẳng định 10D thiếu chi tiết hay 50D nhiễu hơn khi chưa có bằng chứng thực nghiệm độc lập ngoài Silhouette score."*

---

### 5. Đính Chính Nhận Định Về Cấu Trúc Vĩ Mô
- **Cách diễn đạt không hợp lệ:** *"Cấu trúc phân cụm vĩ mô hoàn toàn không suy chuyển."*
- **Cách diễn đạt chuẩn mực khoa học:**
  > *"V1 và V2 có partition similarity cao trên K=5..10 trong thí nghiệm Step 7C.1 (ARI đạt từ 0.9115 đến 0.9712; NMI đạt từ 0.9201 đến 0.9654)."*
