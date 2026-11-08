# Bộ prompt nâng cấp Smart Search / Neo4j / Redis (giai đoạn 2)

Mỗi file `uXX-*.md` là **một prompt tự đủ ngữ cảnh**: mở chat mới (hoặc giao subagent), dán nguyên nội dung file.
Prompt do phiên Opus viết; code do subagent Sonnet thực hiện; phiên Opus review sau mỗi bước.

| File | Bước | Phụ thuộc | Ghi chú |
|---|---|---|---|
| u00-common-rules.md | Quy tắc chung — **mọi bước phải đọc** | — | |
| u01-config-security.md | Token nội bộ, salt, tài khoản test seed | — | |
| u02-runtime-stability.md | livekit-agent, lock chatbot, log `\x00` | — | |
| u03-redis-platform.md | Lớp Redis dùng chung: key, rate limit, Streams, Pub/Sub, Sorted Set | 01 | công nghệ mới |
| u04-autocomplete.md | `searchSuggestions` theo từng từ + autocomplete Redis | 03 | |
| u05-ranking-didyoumean.md | Xếp hạng kết quả chính xác + "Có phải bạn muốn tìm" | 04 | |
| u06-data-quality.md | Alias INCI + vòng lặp từ khóa 0 kết quả → synonym | 03 | |
| u07-benefits-labeling.md | Công dụng: admin nhập tay + LLM gợi ý có duyệt | 06 | |
| u08-similar-products.md | Khối "Sản phẩm tương tự" ở trang chi tiết | 03 | |
| u09-cart-graph.md | Giỏ hàng: xung đột hoạt chất + gợi ý đủ routine | 08 | |
| u10-syna-graph-tool.md | Syna truy vấn Neo4j | 09 | |
| u11-ops-scheduler.md | Lịch đồng bộ tự động, reindex theo sự kiện, giám sát RAM | 03 | |
| u12-advanced-gated.md | Co-purchase, CTR boost, A/B test, cross-encoder | 11 | có cổng điều kiện dữ liệu |
| u13-final-verify.md | Kiểm thử tổng, tài liệu, commit theo nhóm | tất cả | |

**Sổ bàn giao:** `docs/plans/upgrade-handoff.md` — mỗi bước đọc trước, ghi thêm một mục khi xong.
**Tài liệu nền (đọc khi cần):** `update_neo4j_search_recommendation.md`, `docs/plans/smart-search-handoff.md`, `docs/neo4j/schema.md`.
