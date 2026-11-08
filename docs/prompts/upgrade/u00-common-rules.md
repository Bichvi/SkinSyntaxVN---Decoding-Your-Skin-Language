# U00 — QUY TẮC CHUNG (mọi bước giai đoạn 2 phải tuân thủ)

## Thứ tự đọc khi bắt đầu một bước
1. `AGENTS.md` (GitNexus, workflow, kiểm thử).
2. File này.
3. `docs/plans/upgrade-handoff.md` (trạng thái nền + nhật ký các bước trước) — **nguồn sự thật**; nếu prompt bước và handoff mâu thuẫn, tin handoff và ghi lại.
4. Prompt của bước hiện tại.
5. Chỉ đọc thêm file code mà bước cần. Không đọc lại toàn bộ lịch sử.

## Ngôn ngữ & báo cáo
- Trả lời người dùng bằng **tiếng Việt**, mở đầu bằng kết luận (xong / chưa xong + lý do).
- Báo cáo cuối: file tạo/sửa · lệnh đã chạy + kết quả THẬT · bỏ qua + lý do · rủi ro · việc bước sau cần biết.
- Không bao giờ nói PASS cho test/E2E/lint chưa chạy thật. Không chạy được thì ghi `NOT RUN` + lý do.

## An toàn dữ liệu
- MongoDB **Atlas** là nguồn sự thật — không chuyển về Mongo local. Mặc định **chỉ đọc**; chỉ ghi khi prompt bước cho phép rõ ràng (ghi đúng collection/trường đó).
- **Không chạy các script cũ trong `backend/tests/`** (ghi DB thật). Chỉ chạy test unit mới không chạm DB (`backend/tests/unit/*Test.php`).
- Neo4j, Chroma là bản sao dẫn xuất — được xóa/dựng lại, không bao giờ ghi ngược về Mongo.
- Không lộ bí mật: không in/log/commit giá trị trong `.env`, token, mật khẩu, chuỗi kết nối, email, SĐT, hồ sơ da thô. `.env` đã bị git ignore — được phép **thêm** key vào `.env` (không in giá trị) khi prompt cho phép; `.env.example` chỉ ghi tên key, giá trị rỗng.
- Không xóa/sửa thuật toán gợi ý có sẵn của thành viên khác (Simple/Hybrid recommender trong `recommendation_service`, `GoiYContentBased.php`) — chỉ thêm bên cạnh.
- AI Idol E2E tạo chiến dịch thật — không đụng `ai-idol-service` trừ khi bước yêu cầu.

## Code
- Trước khi sửa symbol có sẵn: `node .gitnexus/run.cjs impact "<symbol>" --direction upstream --repo .`. Báo callers + risk. HIGH/CRITICAL → **dừng, báo người dùng**. UNKNOWN hoặc index trễ → xác nhận bằng `rg` và ghi rõ.
- Thay đổi nhỏ nhất đạt yêu cầu; không refactor ngoài phạm vi; giữ chữ ký public và contract API (`ai-service-flask/search_service/contract/recommend_response.schema.json`) tương thích ngược.
- Python: type hints, `logging` (không print trong module), dependency injection để test không cần DB thật, Cypher **luôn tham số hóa**, cấu hình qua env có mặc định an toàn + feature flag.
- PHP: escape mọi output view (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`), giữ CSRF/rate limit, route mới thêm vào **cả** `backend/public/index.php` và `frontend/public/index.php`.
- Redis: theo quy ước key ở U03 (`<domain>:<purpose>:v<n>:...`), mọi key có TTL trừ khi là cấu trúc dài hạn được ghi rõ; Redis chết → tính năng xuống cấp mềm, không lỗi trang.
- TDD cho logic thuần. Test không kết nối DB thật (trừ test đánh dấu `@pytest.mark.integration`).

## Hạ tầng
- Python host: `.venv/Scripts/python.exe`. Trong container: `MSYS_NO_PATHCONV=1 docker exec -w /app skinsyntax-ai python ...`.
- Đổi requirements → rebuild riêng: `docker compose build ai-service && docker compose up -d --no-deps ai-service` (Docker Hub có thể chập chờn, thử lại).
- Được restart/recreate `skinsyntax-ai`, `skinsyntax-php`, `skinsyntax-neo4j` khi cần; **không dừng container khác** trừ khi bước yêu cầu; dừng để test thì phải bật lại.
- curl tiếng Việt trên Git Bash: gửi body từ file UTF-8 (`--data-binary @file`).

## Kết thúc bước
- Chạy test liên quan + `php -l` file PHP đổi + `git diff --check`.
- Thêm mục vào `docs/plans/upgrade-handoff.md`.
- **Không commit** (U13 commit theo nhóm sau khi người dùng đồng ý).
