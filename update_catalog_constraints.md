# Handoff: Catalog Attribute Constraint Engine (Syna chọn sản phẩm theo loại da/brand/xuất xứ/thành phần/giá)

Nhánh `syna`. Ngày 2026-10-21. **Chưa commit.** Plan gốc: `docs/plans/2026-10-16-catalog-attribute-constraints.md`.
Đọc file này là đủ để sửa tiếp; không cần đọc lại toàn bộ hệ thống. Bối cảnh tổng của Syna/Studio: `update.md`.

## 1. Vấn đề đã sửa
Lệnh AUTO "Làm video cho 5 sữa rửa mặt cho da dầu bán chạy nhất 30 ngày... ưu tiên L'Oréal, Eucerin" bị dừng ở bước 1
với câu "Bộ lọc loại da/loại trừ chưa có ánh xạ xác minh". Nguyên nhân: `build_query_plan` trong
`ai-idol-service/agents/command_query.py` có danh sách cứng (`da dau`, `da nhay cam`, `khong lay`...) và mọi chữ "không"
đều thành câu hỏi chặn. AUTO chỉ bỏ qua bước duyệt kịch bản/render, không bỏ qua `questions` của truy vấn, nên pipeline dừng.

## 2. Dữ liệu thật trong MongoDB Atlas (đã đọc, chỉ đọc)
| Thuộc tính admin nói | Trường `san_pham` | Bảng tra cứu |
|---|---|---|
| Loại da | `loai_da` (chuỗi), `ma_loai_da` | `loai_da` (10 giá trị: "Da dầu/Hỗn hợp dầu", "Da thường/Mọi loại da", "Da nhạy cảm", "Da mụn", "Da khô/Hỗn hợp khô", "Unknown"...) |
| Thương hiệu | `ma_thuong_hieu` | `thuong_hieu` (432; có "L'Oreal", "L'Oreal Professionnel", "Eucerin") |
| Xuất xứ thương hiệu | `ma_xuat_xu` | `xuat_xu` (27, tên tiếng Việt; "Đức" lưu là "Ðức" chữ U+00D0) |
| Nơi sản xuất | `ma_noi_san_xuat` | `noi_san_xuat` (`ma_nsx` int / `ten_nsx` tiếng Anh). **Khác** xuất xứ |
| Thành phần | `thanh_phan_full` (INCI), `thanh_phan`, `thanh_phan_sach`, `thanh_phan_chinh` | — |
| Giá / bán | `gia_ban`, `so_luong_da_ban`; bán chạy 7/30 ngày lấy từ `product_metrics_snapshots` (cơ chế cũ, không đổi) | — |
Không có trường `van_de_da` trên sản phẩm ("Da mụn" nằm trong `loai_da`). `product_skin_compatibility` rỗng.

## 3. Thiết kế
File mới `ai-idol-service/agents/catalog_constraints.py` (thuần, không I/O):
- `CatalogVocabulary.from_rows(brands, origins, factories, skin_types)`: từ điển lấy từ 4 bảng tra cứu trên.
- `extract_constraints(brief, vocab)` trả về `{constraints, content_notes, assumptions, conflicts, unparsed_negations}`.
  Mỗi constraint: `{id, field, operator, value, strength: HARD|SOFT, source_text}`.
  Các field: `brand`, `origin_country`, `manufacturing_country`, `skin_type`, `ingredient` (in/not_in), `price`.
  - **SOFT**: chữ đứng trước là "ưu tiên / nếu có / thiên về / nên chọn / giống như / đẩy mạnh / như". SOFT chỉ đổi thứ tự, không loại sản phẩm.
  - **HARD**: "chỉ / chỉ lấy / bắt buộc / phải", hoặc thuộc tính viết thẳng ("sữa rửa mặt cho da dầu", "dưới 300k").
  - **CONTENT** (không bao giờ dùng để lọc): giọng, nhấn mạnh, giải thích, mùa đông, "dịu nhẹ". Hai cụm "dịu nhẹ" và "mùa đông" chỉ sinh assumption.
  - Mâu thuẫn trực tiếp (ví dụ "chỉ Eucerin, không lấy Eucerin") được đưa vào `conflicts` rồi thành `questions`, và chỉ trường hợp này mới dừng.
- `apply_constraints(row, constraints, vocab)` trả về `(ok, evidence, missing_key)`. Quy tắc:
  - Da dầu khớp sản phẩm có `loai_da` chứa dầu, hoặc ghi "Mọi loại da". `Unknown`/rỗng thì không khớp và được đếm vào `missing["skin_type"]`.
  - Loại trừ thành phần ("không hương liệu", "không cồn khô") chỉ xét khi có `thanh_phan_full`. Thiếu INCI thì loại sản phẩm và đếm `missing["full_ingredients"]`; không bao giờ kết luận "không chứa".
  - Brand khớp đúng tên sau khi chuẩn hóa (L'Oréal / Loreal / L’Oreal đều thành "loreal"), không lẫn với "L'Oreal Professionnel".
- `summarize_constraints(...)` tạo các dòng tóm tắt cho admin ("Loại da: Da dầu", "Ưu tiên thương hiệu: L'Oreal, Eucerin").

## 4. Tích hợp (diff nhỏ, tương thích ngược)
- `agents/query_sources.py`: projection thêm `loai_da, ma_loai_da, ma_xuat_xu, ma_noi_san_xuat, thanh_phan, thanh_phan_full, thanh_phan_sach`. `load_catalog` gắn thêm `ten_xuat_xu`, `ten_noi_san_xuat`. Có hàm mới `load_vocabulary(db)`.
- `agents/command_query.py`: thêm tham số `build_query_plan(..., vocab=None)` và `execute_query(..., vocab=None)`.
  - Có vocab:
    - Bỏ qua khối chặn "da dầu".
    - Câu hỏi phủ định chỉ bật khi còn phủ định chưa phân tích được (`unparsed_negations`).
    - `plan` có thêm `constraints`, `content_notes`.
    - HARD được lọc sau `_matches_constraints`. SOFT được sắp xếp ổn định theo `evidence.soft_score`, xếp **sau** thứ hạng chính nhưng **ưu tiên hơn** nó, giống cơ chế `brand_mode=prefer` cũ.
  - `vocab=None`: hành vi y hệt trước đây.
- `agents/service.py::process_agent_run`: nạp `load_vocabulary(get_db())` rồi truyền vào cả hai hàm. Lời gọi `execute_query` ở nhánh fallback metrics (khoảng dòng 310) vẫn chưa truyền vocab.
- `structured_query.py` và `autopilot.py` không gọi `build_query_plan`, nên không sửa.

## 5. Kiểm thử
- Test mới: `ai-idol-service/tests/test_catalog_constraints.py` (10 test). Gồm câu lệnh thật của user, loại da, alias brand, HARD/SOFT, xuất xứ khác nơi sản xuất, include/exclude thành phần, giá, mâu thuẫn, tương thích ngược khi `vocab=None`, và phủ định đã phân tích được thì không chặn.
- Lệnh chạy: `cd ai-idol-service && ../.venv/Scripts/python.exe -m pytest tests -q --ignore=tests/test_campaign_recovery.py --ignore=tests/test_e2e_flow.py`. Hai file bị ignore vì thiếu `celery` trong `.venv`, lỗi có sẵn.
- Kết quả: trước khi sửa 36 failed / 771 passed; sau khi sửa **36 failed / 781 passed**. Danh sách 36 test fail giống hệt nhau, đều có từ trước. `git diff --check` sạch.
- Kiểm tra trên dữ liệu Atlas thật (chỉ đọc) với câu lệnh của user:
  - `questions=[]`; 184 sữa rửa mặt khớp da dầu hoặc mọi loại da.
  - Có snapshot 30 ngày: RESULTS_READY, 43 sản phẩm hợp lệ, Eucerin đứng đầu nhờ SOFT.
  - Thiếu snapshot: DATA_UNAVAILABLE. Trường hợp này đúng chủ ý: không tự bịa số "bán chạy".
- **E2E NOT RUN** (Playwright). Chưa chạy lại lệnh trên giao diện Studio.

## 6. Giới hạn đã biết
- Brand khớp đúng tên. "L'Oreal Paris" sẽ không khớp nếu catalog không có đúng tên đó. Brand một từ nằm trong stoplist (skin, beauty...) hoặc ngắn dưới 3 ký tự thì bị bỏ qua.
- Thành phần và quốc gia chỉ nhận theo bảng alias trong file (`INGREDIENTS`, `COUNTRIES`). Thêm alias tại đó.
- Giá phải có đơn vị (k, nghìn, triệu, đ).
- Phủ định chỉ hiểu dạng danh sách đơn giản (dấu phẩy, "và"). "Trừ sản phẩm A" (ngoại lệ của ngoại lệ) chưa hỗ trợ.
- Chưa có LLM parser, semantic search (Chroma), OR-Tools, LangGraph, Langfuse, profile "vấn đề da/claim evidence". Đây là các bước tiếp theo đã bàn với user, nên làm theo thứ tự: LLM parser (dùng extractor này làm validator) → hybrid search → OR-Tools → LangGraph.

## 7. Quy tắc khi sửa tiếp
GitNexus impact trước khi sửa (`build_query_plan`, `execute_query`, `load_catalog`, `_load_catalog_rows`: tất cả LOW, caller chính `service.py::process_agent_run`). Không chạy `backend/tests` vì chúng ghi vào DB thật. Chạy `detect_changes` trước khi commit.
