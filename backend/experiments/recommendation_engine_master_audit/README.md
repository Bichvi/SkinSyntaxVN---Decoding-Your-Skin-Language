# Tổng Kiểm Toán Toàn Bộ Kiến Trúc Gợi Ý SkinSyntaxVN
## Recommendation Engine Master Audit (Simple + Content-Based + Hybrid + Association + CF + K-Means)

Tài liệu này là chỉ mục tổng hợp toàn bộ hệ thống gợi ý của SkinSyntaxVN, phân định ranh giới kỹ thuật rõ ràng giữa các thuật toán đang vận hành trên production và các nhánh nghiên cứu thực nghiệm ngoại tuyến.

---

### Mục Tiêu Kiểm Toán
1. **Minh bạch hóa runtime production:** Xác định chính xác luồng thực thi từ `index.php?r=home` qua Controller, Model, Service và View.
2. **Phân tách ranh giới hệ thống:** Phân biệt rõ ràng giữa Production, Offline Research, Synthetic-Only Experiments, Data-Insufficient Modules và Future Work.
3. **Bảo toàn tính toàn vẹn học thuật:** Ngăn chặn việc ngộ nhận các thuật toán nghiên cứu (K-Means, BPR, Apriori/FP-Growth) là các thành phần đã sẵn sàng cho production hoặc gán cho chúng các nhãn hiệu năng chưa được kiểm chứng lâm sàng.

---

### Cấu Trúc Hồ Sơ Kiểm Toán
- **Kiến Trúc & Luồng Thực Thi:**
  - [production_call_graph.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/production_call_graph.md): Đồ thị gọi hàm thực tế từ code PHP.
  - [production_algorithm_stack.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/production_algorithm_stack.md): Chi tiết ngăn xếp thuật toán production.
  - [homepage_section_mapping.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/homepage_section_mapping.md): Ánh xạ các section trên trang chủ.
  - [data_sources.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/data_sources.md): Danh mục nguồn dữ liệu thực tế.
  - [current_data_inventory.json](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/current_data_inventory.json): Kiểm kê số lượng tài nguyên dữ liệu MongoDB hiện tại.
- **Sơ Đồ Hệ Thống & Ma Trận Phân Định:**
  - [production_vs_research.csv](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/production_vs_research.csv): Ma trận phân loại 14 thuật toán và thành phần.
  - [algorithm_purpose_matrix.csv](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/algorithm_purpose_matrix.csv): Bảng mục đích và bản chất toán học của từng thuật toán.
  - [user_flow.mmd](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/user_flow.mmd): Sơ đồ luồng định tuyến người dùng (Mermaid).
  - [system_architecture.mmd](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/system_architecture.mmd): Sơ đồ kiến trúc tổng thể phân tách ranh giới Production vs Research (Mermaid).
- **Công Thức & Kiểm Thử Thực Nghiệm:**
  - [formula_reference.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/formula_reference.md): Bảng công thức toán học chuẩn hóa cho luận văn.
  - [production_scenario_results.json](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/production_scenario_results.json): Kết quả thực tế của 9 kịch bản người dùng.
  - [regression_results.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/regression_results.md): Kết quả kiểm thử hồi quy các route HTTP.
- **Quy Chuẩn Học Thuật & Báo Cáo Tổng Hợp:**
  - [claims_allowed.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/claims_allowed.md): Tuyên bố được phép và bị nghiêm cấm trong luận văn.
  - [limitations.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/limitations.md): Tổng hợp toàn bộ các giới hạn thực tế.
  - [future_roadmap.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/future_roadmap.md): Lộ trình nâng cấp hệ thống trong tương lai.
  - [recommendation_engine_master_report.md](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/recommendation_engine_master_report.md): Báo cáo tổng kiểm toán toàn diện bằng tiếng Việt.
  - [validate_master_audit.py](file:///d:/xampp/htdocs/fe/SkinSyntaxVN---Decoding-Your-Skin-Language-1/backend/experiments/recommendation_engine_master_audit/validate_master_audit.py): Script tự động kiểm định 20 tiêu chuẩn liêm chính.
