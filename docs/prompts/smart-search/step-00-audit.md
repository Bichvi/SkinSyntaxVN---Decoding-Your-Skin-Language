# BƯỚC 00 — KHẢO SÁT HIỆN TRẠNG (CHỈ ĐỌC)

## Vai trò
Bạn là kỹ sư khảo sát hệ thống. Nhiệm vụ: thu thập SỰ THẬT về code và dữ liệu để các bước sau (tìm kiếm theo từ, Neo4j, API gợi ý) không phải đoán. **Tuyệt đối không sửa, tạo hay xóa file code nào**, ngoại trừ 2 file tài liệu ở mục "Đầu ra".

## Bối cảnh dự án (đủ để làm, không cần đọc thêm lịch sử)
- Dự án: SkinSyntaxVN — e-commerce mỹ phẩm. PHP MVC (`backend/`, `frontend/views/`), Flask AI (`ai-service-flask/`), MongoDB **Atlas** (nguồn dữ liệu gốc, KHÔNG chuyển về Mongo local), ChromaDB, Redis, Docker Compose. Windows + XAMPP, chạy tại `http://localhost:8080`.
- Lỗi cần giải quyết: tìm `index.php?r=tatca&q=torriden sữa rửa mặt` ra 0 kết quả dù shop có nhiều SP Torriden.
- Nguyên nhân đã biết: `backend/app/models/SanPham.php` hàm `paginateWithProjection` (~dòng 846-855) tạo 1 regex từ NGUYÊN cụm `q` rồi `$or` trên từng trường riêng → không trường nào chứa nguyên cụm.
- Mục tiêu cuối: (1) tìm theo từng từ; (2) khi ít/không có kết quả, hiện các nhóm gợi ý (cùng hãng, sản phẩm thay thế cùng công dụng, hợp loại da, bán chạy) dùng ChromaDB (đã có) + Neo4j (chưa có); (3) PHP chỉ gọi Flask, không gọi thẳng Neo4j/Chroma.

## Quy tắc bắt buộc
- Đọc `AGENTS.md` và các file trong `.agents/` (nếu có) trước.
- Trả lời người dùng bằng **tiếng Việt**.
- **KHÔNG chạy các script trong `backend/tests/`** — chúng ghi vào DB thật.
- Truy vấn Mongo chỉ dùng thao tác đọc (`find`, `count_documents`, `aggregate` không có `$out`/`$merge`). Không in email, SĐT, địa chỉ, mật khẩu, chuỗi kết nối, nội dung `.env`.
- Ưu tiên GitNexus: `node .gitnexus/run.cjs query "<khái niệm>" --repo .` và `context`. Nếu GitNexus lỗi/index cũ, ghi lại và dùng `rg`.

## Nhiệm vụ chi tiết

### 1. Luồng tìm kiếm PHP
- Xác nhận chuỗi gọi: `index.php` (route `r=tatca`) → `SanPhamController` (hàm nào, dòng nào) → `SanPham::paginate` → `paginateWithProjection` → `buildSearchRegex`. Ghi **file:dòng** từng mắt xích.
- Đọc `buildSearchRegex`: nó escape thế nào, có xử lý không dấu không, có tách từ không.
- Liệt kê **mọi nơi gọi** `SanPham::paginate` (GitNexus impact hoặc `rg "->paginate("`), đánh dấu nơi nào là admin, nơi nào là public.
- Đọc `availableProductFilter()` và `LISTING_PROJECTION`: điều kiện SP "đang bán" là gì.
- Đọc `frontend/views/tatca.php`: biến nào view nhận, khối "Không tìm thấy sản phẩm phù hợp" nằm ở dòng nào, partial card sản phẩm tên gì.

### 2. Schema & chất lượng dữ liệu collection sản phẩm
- Tìm tên collection thật (grep `san_pham` trong model). Lấy 3 document mẫu, chỉ hiện tên trường + kiểu + ví dụ ngắn (cắt ≤ 80 ký tự).
- Bằng aggregate CHỈ ĐỌC trên SP đang bán, tính **% có giá trị không rỗng** cho các trường (tên trường thật có thể khác — tìm đúng tên): thương hiệu, mã danh mục, danh mục đầy đủ, loại sản phẩm, **thành phần** (chuỗi hay mảng? độ dài trung bình?), **loại da phù hợp**, **công dụng/vấn đề da**, lượt bán, đánh giá, tồn kho, giá, giá gốc, ngày cập nhật (`updated_at` hay tương tự — cần cho đồng bộ tăng dần).
- Đếm số SP theo `thuong_hieu` (top 20) và phát hiện cách viết không thống nhất (vd `Torriden`, `TORRIDEN`, `torriden `).
- Đếm `Torriden` + chứa "rửa mặt" trong tên: bao nhiêu SP (để làm ca kiểm thử chuẩn).
- Liệt kê cây danh mục (collection danh mục, cấp 1 → cấp 2).

### 3. Dữ liệu phụ trợ sẵn có
- Hồ sơ khảo sát da: collection/trường nào, giá trị loại da và vấn đề da dạng gì.
- Lịch sử tìm kiếm: `saveSearchHistory`, `luuLichSuTimKiem`, `InteractionLogger::logSearch` lưu ở đâu, schema.
- Dữ liệu đơn hàng: collection nào, có danh sách item + ngày → dùng tính "bán chạy 14 ngày" và "thường mua cùng".
- Xung đột hoạt chất: `ai-service-flask/recommendation_service/conflict_checker.py` lấy dữ liệu từ đâu (file tri thức trong `knowledge/`? Mongo?). Có bao nhiêu cặp.

### 4. ai-service-flask
- Hai thư mục song song `recommendation/` và `recommendation_service/`: **cái nào được đăng ký vào app đang chạy thật?** Kiểm tra `chatbot_flask.py`, `rcm_flask.py`, `recommendation_service/rcm_flask.py`, `gunicorn_config.py`, `docker-compose.yml` (command/entrypoint), Dockerfile. Ghi kết luận có bằng chứng.
- Liệt kê toàn bộ endpoint (method + path + file:dòng) và cơ chế auth nội bộ (header/token nào — ghi TÊN biến, không ghi giá trị).
- `recommendation_service/hybrid_search.py`, `retrieval.py`, `reranker.py`, `response_cache.py`, `pipeline.py`: hàm public chính, input/output, có dùng lại được cho tìm kiếm từ khóa tự do không.
- ChromaDB: collection nào được index, document text gồm trường nào, metadata gồm gì, script index là gì, lần cuối index khi nào (nếu xác định được).
- Phiên bản Python, file requirements đang dùng thật; đã có `neo4j`, `rapidfuzz`, `unidecode`, `pyyaml`, `pydantic`, `pytest` chưa.
- Cách chạy test Python hiện có (lệnh pytest, thư mục tests, venv nào).

### 5. Cách PHP gọi Flask
- `rg -n "curl_init|AI_SERVICE|5000|rcm|recommend" backend frontend`: URL cấu hình ở đâu, timeout bao nhiêu, xử lý lỗi thế nào, có lớp service dùng chung không.

### 6. Hạ tầng
- `docker-compose.yml`: danh sách service, port, network, volume, healthcheck, biến môi trường cần có (chỉ tên). `.env.example` có những key nào. `.gitignore` đã chặn thư mục dữ liệu chưa.

### 7. Hạ tầng kiểm thử
- Có Playwright không (`package.json`, `playwright.config.*`, `.claude/skills/e2e/SKILL.md`)? Lệnh chạy? Composer có phpunit không?

## Đầu ra (bắt buộc)
1. Tạo `docs/plans/2026-10-21-neo4j-search-audit.md` với các mục 1→7 ở trên, mỗi khẳng định có **file:dòng** hoặc **lệnh + kết quả**. Thêm cuối file:
   - **Bảng độ phủ dữ liệu** (trường | tên thật | % có | kiểu | ghi chú).
   - **Kết luận khả thi Neo4j**: nếu % thành phần < 60% hoặc % loại da < 40% → ghi rõ "cần bổ sung dữ liệu trước Bước 03".
   - **RỦI RO** (bảng: rủi ro | mức | đề xuất).
   - **CÂU HỎI CẦN NGƯỜI DÙNG QUYẾT ĐỊNH** (vd: có được thêm trường `search_text` vào Mongo không; dùng module recommendation nào; ngân sách RAM cho Neo4j).
2. Thêm một mục vào `docs/plans/smart-search-handoff.md` theo mẫu trong file, gồm các số liệu then chốt (tên collection, tên trường thật, % độ phủ, module Flask đang chạy, endpoint + header auth, lệnh pytest, lệnh E2E).

## Tiêu chí hoàn thành
- Không có thay đổi nào trong `git status` ngoài 2 file tài liệu trên.
- Mọi mục 1→7 có câu trả lời hoặc ghi rõ "không xác định được vì …".
- Trả lời cuối cùng cho người dùng: tóm tắt 10 dòng + danh sách câu hỏi cần quyết định.
