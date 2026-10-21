# Plan: Catalog Attribute Constraint Engine cho Syna (2026-10-16)

Hướng được chọn (trong các đề xuất LLM parser / Hybrid search / OR-Tools / LangGraph...):
**Bộ trích xuất ràng buộc dựa trên từ điển lấy từ chính CSDL + phân loại HARD/SOFT/CONTENT.**
Lý do: lỗi thật là command_query.py chặn mọi thuộc tính chưa hard-code, trong khi catalog
ĐÃ có dữ liệu có cấu trúc. Dùng lookup table của Mongo làm từ điển thì không bịa thuộc tính,
không tốn LLM, chạy được trong test FakeDb, và là nền cho LLM parser / OR-Tools sau này.

## Dữ liệu thật (đã đọc Atlas, chỉ đọc, 2026-10-16)
- `san_pham` (2853): `loai_da` (chuỗi, 1 giá trị/SP) + `ma_loai_da`; `ma_xuat_xu`; `ma_noi_san_xuat`;
  `ma_thuong_hieu`; `thanh_phan` (mô tả), `thanh_phan_full` (INCI), `thanh_phan_sach`; `so_luong_da_ban`; `gia_ban`.
- `loai_da` (10): ma_loai_da/ten_loai_da: Da dầu/Hỗn hợp dầu(1), Da thường/Mọi loại da(2), Da nhạy cảm(3),
  Unknown(4), Da khô/Hỗn hợp khô(5), Da mụn(6), Da khô(7), Da hỗn hợp thiên dầu(8), Da dầu, Da khô / Hỗn hợp thiên khô.
- `xuat_xu` (27): ma_xuat_xu/ten_xuat_xu tiếng Việt (Pháp, Đức viết "Ðức" bằng chữ Ð U+00D0!, Hàn Quốc...) = xuất xứ THƯƠNG HIỆU.
- `noi_san_xuat` (28): ma_nsx (int)/ten_nsx tiếng Anh (France, Korea...) = NƠI SẢN XUẤT. Khác trường với xuất xứ.
- `thuong_hieu` (432): ma_thuong_hieu/ten_thuong_hieu ("L'Oreal", "L'Oreal Professionnel", "Eucerin").
- `product_skin_compatibility`: rỗng — không dùng.
- Không có trường `van_de_da` trên san_pham. "Da mụn" nằm trong loai_da.

## Thiết kế
File mới `ai-idol-service/agents/catalog_constraints.py` (pure, không I/O):

1. `CatalogVocabulary` (dataclass) dựng từ lookup rows: brands {key->name}, origins {ma->ten}, factories {ma->ten},
   skin_types {ma->ten}. `normalize_text` dùng của intent_parser; chuẩn hóa thêm Ð->D, ’ -> ', bỏ dấu nháy
   ("L'Oréal"/"Loreal"/"L’Oreal" -> "loreal").
2. Taxonomy chuẩn:
   - SKIN_TYPES canonical: oily, dry, combination, normal, sensitive, acne. Alias VN/EN (da dau, da nhon, oily,
     da kho, da hon hop, da thuong, moi loai da, da nhay cam, da mun...). Map từng `ten_loai_da` thật -> tập canonical
     (ví dụ "Da dầu/Hỗn hợp dầu" -> {oily, combination}; "Da thường/Mọi loại da" -> {normal, all}).
     Quy tắc khớp HARD skin_type=oily: SP có loai_da chứa oily HOẶC "all" (mọi loại da) -> match, reason ghi rõ
     "phù hợp mọi loại da". `Unknown`/rỗng -> KHÔNG match, đếm missing["skin_type"].
   - COUNTRY alias: VN + EN + viết tắt (han, han quoc, korea, k-beauty -> Hàn Quốc / Korea; phap/france; duc/germany;
     nhat/japan; my/usa/united states...). Câu "xuất xứ/thương hiệu (từ) X" -> origin; "sản xuất tại/made in X" -> factory;
     chỉ nói "hàng Hàn"/"của Hàn" -> origin (ghi assumption).
   - INGREDIENT: tái dùng ACTIVE_ALIASES của product_resolver + thêm: salicylic/bha, aha/glycolic, hyaluronic/ha,
     ceramide, panthenol/b5, zinc/kem, tea tree/tram tra, fragrance/parfum/huong lieu, alcohol denat/con.
     Include: khớp trên thanh_phan_full + thanh_phan_chinh/thanh_phan/thanh_phan_sach + tên SP.
     Exclude ("không hương liệu", "không cồn"): CHỈ đánh giá khi `thanh_phan_full` có (INCI). Không có INCI ->
     loại và đếm missing["full_ingredients"] (không được kết luận "không có").
3. `extract_constraints(brief, vocab) -> list[Constraint]`, Constraint = dict
   {id, field, operator(in/not_in/eq/lte/gte/between), value, strength HARD|SOFT, source_text}, cùng `content_notes`.
   Fields: brand, origin_country, manufacturing_country, skin_type, ingredient, price, product_id/name (đã có sẵn).
   - SOFT khi trước cụm có "ưu tiên|nếu có|thiên về|nên chọn|như|giống như"; HARD khi "chỉ|bắt buộc|phải|chỉ lấy" hoặc
     thuộc tính đứng trực tiếp sau danh mục ("sữa rửa mặt cho da dầu").
   - Nhiều brand cùng cụm ("ưu tiên dịu nhẹ như L'Oréal, Eucerin") -> 1 SOFT brand constraint value=[...].
     Brand khớp theo token dài nhất, ưu tiên khớp chính xác tên ("L'Oreal" không ăn "L'Oreal Professionnel"
     trừ khi gõ đủ).
   - Giá: "dưới 300k", "từ 200-500k", "tối đa 300.000đ" -> price HARD.
   - CONTENT (không lọc): giọng/phong cách, nhấn mạnh thành phần, giải thích, mùa đông/trời lạnh, "dịu nhẹ" (không có
     dữ liệu kiểm chứng -> content note + assumption, không lọc).
   - Mâu thuẫn trực tiếp (chỉ X và không lấy X; 2 skin type HARD khác nhau với "chỉ") -> trả conflict.
4. `apply_constraints(product_row, constraints, vocab) -> (ok, evidence, missing_key|None)`.
   SOFT -> `soft_score(row) ` (số constraint SOFT thỏa) dùng để sort ổn định sau thứ hạng chính.

## Tích hợp
- `query_sources._load_catalog_rows`: thêm projection `loai_da, ma_loai_da, ma_xuat_xu, ma_noi_san_xuat,
  thanh_phan, thanh_phan_full, thanh_phan_sach`. Thêm `load_vocabulary(db)` đọc thuong_hieu/xuat_xu/noi_san_xuat/loai_da
  (bounded_rows). `load_catalog` gắn thêm `ten_xuat_xu`, `ten_noi_san_xuat` vào row.
- `command_query.build_query_plan(brief, *, today=None, vocab=None)`:
  - BỎ khối dòng 298-300 (da dau/da nhay cam...) khi vocab có; thay bằng `plan["constraints"]`, `plan["content_notes"]`,
    conflict -> questions. "loai tru/khong lay/tru thuong hieu" nay được parse thành not_in; chỉ hỏi khi không parse được.
  - Khối phủ định dòng 296-297: không hỏi nếu mọi "không" đã được parse thành constraint (ví dụ "không hương liệu").
  - BRAND_PATTERN cũ vẫn giữ để tương thích; nếu extractor ra brand thì plan["brand"] giữ nguyên hành vi cũ cho 1 brand.
  - vocab=None (call cũ / test cũ) -> hành vi cũ y hệt (backward compatible).
  - summary thêm dòng "Loại da: ...", "Ưu tiên thương hiệu: L'Oreal, Eucerin", "Xuất xứ: ...", "Thành phần: ...".
- `execute_query`: sau `_matches_constraints`, gọi apply_constraints cho HARD; evidence thêm skin_type/origin/
  ingredient hits; sort phụ theo soft_score (sau sort chính, stable — giống brand prefer hiện có).
- `service.py` (dòng ~553): truyền `vocab=load_vocabulary(db)` vào build_query_plan. Kiểm tra structured_query.py,
  autopilot.py có gọi build_query_plan không và truyền vocab nếu có db.
- "bán chạy 30 ngày" giữ nguyên cơ chế snapshot hiện có (product_metrics_snapshots). Không đổi.

## Ngoài phạm vi đợt này (ghi vào update.md mục "việc tiếp theo")
LLM structured parser, ChromaDB semantic, OR-Tools, LangGraph, Langfuse, trường van_de_da/claim evidence profile.

## Test (ai-idol-service/tests/test_catalog_constraints.py, FakeDb/pure)
1. Câu mẫu của user -> không có question; constraints: category cleanser, skin_type HARD oily, brand SOFT [L'Oreal, Eucerin],
   rank units 30 ngày, content_notes có giọng/thành phần/mùa đông.
2. Da dầu khớp "Da dầu/Hỗn hợp dầu" & "Da thường/Mọi loại da"; không khớp "Da khô", "Unknown" (missing).
3. Brand alias: Loreal / L'Oréal / L’Oreal -> L'Oreal, không lẫn L'Oreal Professionnel.
4. "chỉ lấy Eucerin" HARD vs "ưu tiên Eucerin" SOFT (thứ tự sort).
5. Xuất xứ "Hàn Quốc" vs "sản xuất tại Pháp" là 2 trường; "Đức" khớp "Ðức".
6. "có niacinamide" include; "không hương liệu" exclude: SP có Fragrance trong INCI bị loại; SP thiếu INCI -> missing.
7. "dưới 300k" price HARD.
8. Mâu thuẫn "chỉ Eucerin, không lấy Eucerin" -> question.
9. vocab=None -> build_query_plan giữ hành vi cũ (test_command_query.py cũ vẫn pass).
Chạy: `cd ai-idol-service && ../.venv/Scripts/python.exe -m pytest tests -q` (lỗi celery có sẵn thì ghi nhận, không sửa).
