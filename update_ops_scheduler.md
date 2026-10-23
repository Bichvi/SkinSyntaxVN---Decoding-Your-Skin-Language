# Vận hành tìm kiếm: đồng bộ tự động + lịch + giám sát (U11)

Tài liệu đầy đủ: `docs/ops/search-ops.md` (luồng sự kiện, bảng job, chạy tay, đọc health, xử lý sự cố, RAM Neo4j).

- PHP: `CatalogEvents` (XADD `catalog:changes:v1`) gọi từ `SanPham::adminInsert/adminUpdate/updateProductVisibility/updateInventory` và `HoaDon` (hết/còn hàng). Trang admin chỉ đọc `index.php?r=admin_search_ops`.
- Python: `graph_sync/incremental.py` (`--ids`, SIMILAR_TO cục bộ), `reindex_chroma.reindex_ids`, `workers/{catalog_sync,scheduler,jobs,catalog_worker}.py`, `search_service/ops_health.py` (`GET /api/ops/health`), `shared/redis_kit/ops.py`.
- Compose: dịch vụ `catalog-worker`. Chatbot: kiểm tra LLM chạy nền (`chatbot_flask.get_llms`). Báo cáo `search_metrics` bỏ dữ liệu test.
- Test: `ai-service-flask/tests/ops`, `tests/graph_sync/test_incremental.py`, `tests/test_chatbot_llm_warmup.py`, `backend/tests/unit/CatalogEventsTest.php`, `SearchOpsClientTest.php`.
