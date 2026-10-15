# SKINSYNTAXVN — FINAL RECOMMENDATION ENGINE AUDIT & THESIS EVIDENCE PACK

> **Academic Document Context:**  
> Hệ thống gợi ý sản phẩm chăm sóc da thương mại điện tử SkinSyntaxVN.  
> Phiên bản kiến trúc: **Phase A (Adaptive Recommender V1) + Phase C/C.1/C.2 (Offline Collaborative Filtering Research)**.  
> Trạng thái kho lưu trữ: Branch `rcm_index`, commit gốc `25b792b`.

---

## 1. MỤC TIÊU VÀ PHẠM VI TÀI LIỆU
Tài liệu này đóng băng (freeze) và tổng hợp toàn bộ bằng chứng kỹ thuật, cơ sở toán học, kiến trúc vận hành và kết quả nghiên cứu ngoại tuyến của Hệ thống Gợi ý Sản phẩm SkinSyntaxVN phục vụ bảo vệ luận văn / đồ án tốt nghiệp:

1. **Phân định ranh giới tuyệt đối giữa Production Runtime và Offline Research:**
   - **Production Runtime (Hệ thống thực tế):** Vận hành 100% bằng **Simple Recommender (IMDb-style Bayesian Weighted Rating)** kết hợp **Adaptive Content-Based Recommender (Multi-Signal Behavior Fusion + Customer Skin Profile)**.
   - **Offline Research (Nghiên cứu ngoại tuyến):** Môi trường thực nghiệm cô lập đánh giá các mô hình Collaborative Filtering (Item-kNN, Pointwise Funk MF, Pairwise BPR) trên cơ sở dữ liệu giả lập có kiểm soát (`skinsyntax_cf_dev`). **Hoàn toàn ngắt kết nối khỏi luồng phục vụ người dùng thực tế.**

2. **Chuẩn hóa ngôn ngữ khoa học (Academic Conservatism):**
   - Loại bỏ toàn bộ các tuyên bố phóng đại (overclaims) về mặt y khoa ("chuẩn y khoa", "cam kết an toàn điều trị") hoặc về mặt thuật toán ("MF thắng áp đảo", "khắc phục triệt để", "phù hợp tuyệt đối").
   - Xác định rõ: Tương tác chưa quan sát (unobserved item) $\neq$ sự không thích hay phản hồi tiêu cực (negative preference).

---

## 2. BẢNG PHÂN LOẠI TRẠNG THÁI CÁC THÀNH PHẦN (CANONICAL STATUS)

| Thành phần thuật toán / Chức năng | Phân loại trạng thái | Nguồn dữ liệu sử dụng | Được website thực tế sử dụng? |
| :--- | :---: | :---: | :---: |
| **Simple Weighted Rating (IMDb-style)** | `PRODUCTION` | Điểm đánh giá & số lượt đánh giá thật | **CÓ** (Trang chủ Section 3 Cold-Start & Section 5.5) |
| **TF-IDF Content-Based Index** | `PRODUCTION` | Catalog 2,473 sản phẩm MongoDB | **CÓ** (Tính toán tương đồng nội dung văn bản) |
| **Behavior-Aware Vector Fusion** | `PRODUCTION` | Session realtime (Search, View, Cart, Purchase) | **CÓ** (Cá nhân hóa theo phiên duyệt web) |
| **Profile-Aware Vector Fusion** | `PRODUCTION` | Khảo sát hồ sơ da khách hàng | **CÓ** (Khi người dùng đã hoàn thành khảo sát) |
| **Adaptive Hybrid Recommender** | `PRODUCTION` | Kết hợp Behavior + Profile + Budget | **CÓ** (Chế độ chính khi có đủ 2 nguồn tín hiệu) |
| **Grounded Explainability Tags** | `PRODUCTION` | Tín hiệu hành vi & tương thích da thật | **CÓ** (Hiển thị nhãn lý do dưới từng sản phẩm) |
| **Organic Interaction Logger** | `PRODUCTION` | Sự kiện người dùng thực (Session-deduped) | **CÓ** (Ghi log vào `tuong_tac_nguoi_dung`) |
| **Association Rules / FBT** | `DATA-INSUFFICIENT` | 29 hóa đơn (34 dòng chi tiết) | **KHÔNG DÙNG THỐNG KÊ** (Fallback Routine Heuristic) |
| **Routine Complement Fallback** | `PRODUCTION` | Quy tắc bổ trợ quy trình chăm sóc da | **CÓ** (Trang chi tiết sản phẩm) |
| **Item-Based kNN (Cosine CF)** | `EXPERIMENTAL` | Ma trận tương tác giả lập | **KHÔNG** (Chỉ chạy trong script test ngoại tuyến) |
| **Regularized Biased Funk MF** | `EXPERIMENTAL` | Ma trận tương tác giả lập | **KHÔNG** (Chỉ chạy trong script test ngoại tuyến) |
| **Bayesian Personalized Ranking (BPR)** | `EXPERIMENTAL` | Ma trận tương tác giả lập | **KHÔNG** (Chỉ chạy trong script test ngoại tuyến) |
| **Synthetic Dataset Generator** | `SYNTHETIC-ONLY` | Cấu hình tham số Pareto/Affinity | **KHÔNG** (Chỉ phục vụ nghiên cứu thuật toán) |

---

## 3. DANH MỤC TÀI LIỆU TRONG EVIDENCE PACK

Gói hồ sơ kiểm toán này bao gồm các tài liệu chuyên sâu:

1. [`production_architecture.md`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_final_audit/production_architecture.md): Sơ đồ gọi hàm thực tế từ HomeController đến View, luồng dữ liệu, phân loại bộ nhớ đệm và xác nhận cô lập hoàn toàn CF.
2. [`algorithm_formulas.md`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_final_audit/algorithm_formulas.md): Định nghĩa toán học chuẩn xác của toàn bộ công thức: Weighted Rating, Smooth TF-IDF, Cosine Similarity, Behavior Vector, Hybrid Query Vector, Reranking Scoring, và BPR SGD Pairwise Loss.
3. [`routing_table.md`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_final_audit/routing_table.md): Bảng chuyển mạch ngữ cảnh (Context Routing Table) chuẩn xác ứng với 9 trạng thái tín hiệu người dùng.
4. [`evaluation_summary.md`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_final_audit/evaluation_summary.md): Bảng số liệu thực nghiệm đa hạt giống (3-seed), kiểm định khoảng tin cậy Bootstrap 95%, phân đoạn lịch sử và phân vị độ phổ biến.
5. [`cf_research_summary.md`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_final_audit/cf_research_summary.md): Báo cáo so sánh kỹ thuật giữa Pointwise Funk MF và Pairwise BPR trên dữ liệu implicit feedback thưa.
6. [`claims_allowed.md`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_final_audit/claims_allowed.md): Danh sách các tuyên bố được phép bảo vệ (Supported Claims) và các tuyên bố bị nghiêm cấm (Unsupported Claims).
7. [`limitations.md`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_final_audit/limitations.md): Các giới hạn khoa học trung thực của hệ thống hiện tại.
8. [`future_roadmap.md`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_final_audit/future_roadmap.md): Lộ trình chuyển đổi từ thực nghiệm giả lập sang triển khai thực tế khi dữ liệu tự nhiên đủ lớn.
9. [`production_scenarios.json`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_final_audit/production_scenarios.json): Kết quả đầu ra đo đạc thực tế của 9 kịch bản gợi ý A $\rightarrow$ I.
10. [`current_data_inventory.json`](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_final_audit/current_data_inventory.json): Báo cáo kiểm kê chi tiết từng collection trên cơ sở dữ liệu production và experiment.
