# BƯỚC 09 — KIỂM THỬ TỔNG, ĐO HIỆU NĂNG, TÀI LIỆU, CHUẨN BỊ COMMIT

## Vai trò
Kỹ sư QA/release. Xác minh toàn bộ tính năng Smart Search + Neo4j chạy đúng trên ứng dụng thật, kể cả khi từng thành phần bị tắt; hoàn thiện tài liệu; chuẩn bị (không tự thực hiện) commit.

## Đọc trước
1. `AGENTS.md`, `.claude/skills/e2e/SKILL.md`, `.claude/skills/lint/SKILL.md`, `.claude/skills/frontend-testing/SKILL.md`
2. `docs/plans/smart-search-handoff.md` — **toàn bộ**, đây là nguồn chính
3. `update_smart_search_neo4j.md` (tài liệu kế hoạch tổng) và `update.md` (tài liệu tổng dự án)

## Nhiệm vụ

### 1. Kiểm kê thay đổi
- `git status`, `git diff --stat`. Liệt kê file theo nhóm: PHP, view/CSS/JS, Flask, graph_sync, migration, docker, tài liệu, test. Phát hiện file lạ/không thuộc phạm vi (dữ liệu, ảnh tạm, `.env`, `chroma_data`, thư mục Neo4j) → báo, **không** tự xóa.

### 2. Kiểm thử tự động (chạy thật, dán output rút gọn)
- Python: pytest cho `tests/search_service`, `tests/graph_sync` (+ test integration nếu có env Neo4j).
- PHP: `php -l` mọi file PHP thay đổi; test thuần đã viết ở Bước 01, 06, 08. **Không chạy script cũ trong `backend/tests/`.**
- Lint theo `.claude/skills/lint/SKILL.md` cho phần đã đổi.

### 3. E2E trên ứng dụng thật (`http://localhost:8080`, Playwright)
Ma trận:
| # | Trạng thái hạ tầng | Truy vấn | Kỳ vọng |
|---|---|---|---|
| 1 | Đủ | `torriden sữa rửa mặt` | có SP chính xác |
| 2 | Đủ | `sua rua mat torriden` | như #1 |
| 3 | Đủ | `xyzabc` | banner trung tính + section gợi ý + CTA Syna |
| 4 | Đủ | `srm cho da dầu` | section hợp lý, badge lý do |
| 5 | Đủ, đăng nhập user có hồ sơ da | `kem dưỡng` (ít kết quả) | có section "Hợp với làn da của bạn" |
| 6 | `docker stop skinsyntax-neo4j` | `xyzabc` | vẫn có gợi ý (semantic/trending) |
| 7 | Tắt Flask | `xyzabc` | section "Sản phẩm bán chạy", trang ≤ ~1.5s |
| 8 | Tắt Flask, tải lại 4 lần | `xyzabc` | lần 4 nhanh (circuit breaker) |
| 9 | Đủ | trang admin: tìm theo tên và mã SP | như trước |
| 10 | Mobile 375×812 | `xyzabc` | không cuộn ngang trang, chips cuộn được |
| 11 | Đủ | Thêm vào giỏ từ card gợi ý | thành công |
- Bật lại mọi service sau khi test.
- Ảnh vào `report/search-final-e2e/` (tên file có số kịch bản).
- Không có Playwright → `E2E NOT RUN` + lý do.

### 4. Hiệu năng
- 100 request `/api/search/recommend` (50 truy vấn × 2 lần, lần 2 ấm cache) → p50/p95; mục tiêu p95 < 800ms ấm cache.
- Thời gian tải trang `tatca` cho #1 và #3 (Playwright `performance.timing` hoặc đo response) trước/sau nếu có số liệu cũ.
- `python -m graph_sync --full --dry-run` → thời gian chạy.

### 5. An toàn & bảo mật
- `rg -n "NEO4J_PASSWORD|password|secret|token" --glob '!*.lock'` trên diff: không có giá trị thật.
- Mọi Cypher dùng tham số: `rg -n "f\"MATCH|f'MATCH|\+ *\"MATCH" ai-service-flask` không có kết quả.
- Không log PII: rà các dòng `logger.` mới.
- Output view được escape: rà partial fallback.

### 6. Tài liệu
- Viết `update_neo4j_search_recommendation.md` (tài liệu bàn giao, tiếng Việt): kiến trúc thực tế đã làm (có thể khác kế hoạch — ghi rõ khác ở đâu), sơ đồ luồng, biến môi trường + flag, lệnh vận hành (`docker compose up -d neo4j`, `python -m graph_sync.migrate`, `python -m graph_sync --full|--since|--derive co_purchase`, `python scripts/search_metrics.py`), lịch chạy đồng bộ khuyến nghị, cách tắt từng tính năng, rủi ro còn lại, chỉ số đo được.
- Cập nhật trạng thái các bước trong `update_smart_search_neo4j.md`.
- Thêm mục ngắn vào `update.md` (file tổng) trỏ tới tài liệu trên.
- Thêm mục "Bước 09" vào `docs/plans/smart-search-handoff.md`.

### 7. Chuẩn bị commit (KHÔNG tự commit)
- `git diff --check`.
- `node .gitnexus/run.cjs detect-changes --scope all --repo .` — nếu `partial: true` hoặc `truncated: true` thì chạy lại; báo symbol/process bị ảnh hưởng.
- Đề xuất chia commit theo milestone logic (vd: `fix(search): token-based matching`, `feat(graph): neo4j service + sync`, `feat(search): recommend api`, `feat(search): php fallback + ui`, `feat(search): co-purchase + metrics`, `docs: smart search handoff`) kèm danh sách file mỗi commit.
- Chỉ commit khi người dùng đồng ý rõ ràng.

## Đầu ra cho người dùng
Báo cáo tiếng Việt, bắt đầu bằng kết luận (sẵn sàng / chưa sẵn sàng + lý do), sau đó: bảng E2E (PASS/FAIL/NOT RUN), kết quả test, p95, vấn đề bảo mật (nếu có), file đã đổi, việc bỏ qua, rủi ro, đề xuất commit.
