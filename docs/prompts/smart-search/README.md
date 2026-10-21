# Bộ prompt Smart Search + Neo4j

Mỗi file `step-XX-*.md` là **một prompt tự đủ ngữ cảnh** — mở chat mới, dán nguyên nội dung file, không cần giải thích thêm.

| File | Bước | Phụ thuộc |
|---|---|---|
| step-00-audit.md | Khảo sát (chỉ đọc) | — |
| step-01-token-search.md | Sửa tìm kiếm theo từng từ (Mongo) | 00 |
| step-02-neo4j-setup.md | Dựng Neo4j + schema | 00 |
| step-03-graph-sync.md | ETL Mongo → Neo4j | 02 |
| step-04-intent-parser.md | Phân tích ý định truy vấn | 03 |
| step-05-recommend-api.md | API gợi ý lai Flask | 04 (chế độ không-graph chỉ cần 00) |
| step-06-php-integration.md | Tích hợp PHP | 01, 05 |
| step-07-fallback-ui.md | Giao diện Smart Fallback | 06 |
| step-08-personalization.md | Cá nhân hóa + đo lường | 07 |
| step-09-final-verify.md | Kiểm thử tổng + tài liệu | tất cả |

**Thứ tự khuyến nghị:** 00 → 01 → 05 (chỉ semantic + trending) → 06 → 07 → 02 → 03 → 04 → 05 (bật graph) → 08 → 09.

## Cơ chế bàn giao giữa các chat

- Mọi bước **đọc trước** `docs/plans/smart-search-handoff.md` (ngắn) thay vì đọc lại toàn bộ lịch sử.
- Mọi bước **kết thúc** bằng việc thêm một mục vào file đó: đã làm gì, file nào, quyết định nào, số liệu nào, bước sau cần biết gì.
- Tài liệu tổng (đọc khi cần tra cứu, không bắt buộc): `update_smart_search_neo4j.md`.
